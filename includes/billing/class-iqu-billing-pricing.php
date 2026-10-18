<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Billing_Pricing
 *
 * Works out the monthly amount for an enrollment, using the registration
 * plugin's own IQU_Pricing rules. This is the single place billing amounts
 * come from.
 *
 * Security:
 * - Amounts are always recalculated on the server from course + days.
 *   Nothing typed in a browser is ever used as an amount.
 * - Admin overrides are limited to choosing a course and days from the
 *   fixed list in IQU_Pricing; the price for that choice is still computed here.
 * - Discounts come only from the registration record (set when the coupon
 *   or special discount was applied) and are capped at the gross amount.
 */
class IQU_Billing_Pricing
{
    /** Enrollment types that pay monthly tuition. */
    private const BILLABLE_FORMS = ['free'];

    /**
     * @param array       $reg    Row from the registrations table.
     * @param string|null $course Admin's course choice, used only when the record has none.
     * @param int|null    $days   Admin's days choice, used only when the record has none.
     */
    /**
     * A monthly student with no course on the record: course_type is empty or not a course on
     * the website, and there is no older agreed fee (fee_pref / payment) to bill instead. Such a
     * student can be saved (e.g. by the CSV import) but cannot be billed until a course is set.
     */
    public static function course_missing(array $reg): bool
    {
        if (!in_array($reg['form_type'] ?? '', self::BILLABLE_FORMS, true)) return false;
        if (IQU_Pricing::is_valid_course((string) ($reg['course_type'] ?? ''))) return false;
        return (string) ($reg['course_type'] ?? '') !== '' || self::legacy_fee($reg) === null;
    }

    /** The monthly fee agreed with the family (agreed_fee), or null when none was agreed. */
    /** Highest agreed monthly fee an admin can enter (a typo such as 3000 for 30, or 1e9, is refused). */
    public const AGREED_MAX = 5000.0;

    /**
     * The name of the discount on a family's bill, for emails and receipts: the students' own label
     * (Zakat Fund support, Agreed reduced fee, Special discount …) when they agree, else "Discount".
     * Every discount used to be called "Zakat Fund support".
     */
    public static function account_discount_label(array $acc): string
    {
        $labels = [];
        foreach (IQU_Billing_DB::get_members((int) $acc['id']) as $m) {
            $reg = IQU_Database::get_registration((int) $m['registration_id']);
            if (!$reg) continue;
            $p = self::for_registration($reg);
            if ((float) ($p['discount'] ?? 0) > 0 && (string) ($p['discount_label'] ?? '') !== '') $labels[] = (string) $p['discount_label'];
        }
        $labels = array_values(array_unique($labels));
        return count($labels) === 1 ? $labels[0] : 'Discount';
    }

    public static function agreed_fee(array $reg): ?float
    {
        if (!array_key_exists('agreed_fee', $reg) || $reg['agreed_fee'] === null || $reg['agreed_fee'] === '') return null;
        return round((float) $reg['agreed_fee'], 2);
    }

    /**
     * "Agreed fee $X (standard $Y for <course>, N days a week)" when an agreed fee differs from
     * the standard price; '' when there is none or it is the same. Same wording either way.
     * $p: for_registration() of the row (with the course chosen in Check and send, if any).
     */
    public static function agreed_note(array $reg, ?array $p = null): string
    {
        $agreed = self::agreed_fee($reg);
        if ($agreed === null) return '';
        $p = $p ?? self::for_registration($reg);
        if (!isset($p['standard'])) {
            return 'Agreed fee ' . IQU_Pricing::format($agreed) . ' (standard price once the course is set)';
        }
        if (abs($agreed - (float) $p['standard']) < 0.005) return '';
        return sprintf('Agreed fee %s (standard %s for %s, %d %s a week)', IQU_Pricing::format($agreed), IQU_Pricing::format((float) $p['standard']),
            $p['course_label'], (int) $p['days_per_week'], (int) $p['days_per_week'] === 1 ? 'day' : 'days');
    }

    public static function for_registration(array $reg, ?string $course = null, ?int $days = null): array
    {
        $out = [
            'billable'          => false,
            'scholarship'       => false, // priced at $0: a full scholarship, never billed
            'needs_input'       => false,
            'reason'            => '',
            'registration_id'   => (int) ($reg['id'] ?? 0),
            'course'            => '',
            'course_label'      => '',
            'days_per_week'     => 0,
            'classes_per_month' => 0,
            'per_class_rate'    => 0.0,
            'gross'             => 0.0,
            'discount'          => 0.0,
            'discount_label'    => '',
            'net'               => 0.0,
            'warnings'          => [],
        ];

        if (!in_array($reg['form_type'] ?? '', self::BILLABLE_FORMS, true)) {
            $out['reason'] = 'This program is not billed monthly.';
            return $out;
        }

        // Older enrollments: no course recorded, but the family chose a fee
        // (fee_pref, e.g. "hifz_100" or "custom_25"). That agreed fee is used.
        $rec_course = (string) ($reg['course_type'] ?? '');
        if ($rec_course === '') {
            $legacy = self::legacy_fee($reg);
            if ($legacy !== null) {
                return self::from_legacy($out, $reg, $legacy);
            }
        }

        // Course: the record wins; an admin choice is used only when the record has no course
        // (empty, or a value that is not a course on the website).
        if (!IQU_Pricing::is_valid_course($rec_course)) $rec_course = '';
        $use_course = $rec_course !== '' ? $rec_course : (string) $course;
        if (!IQU_Pricing::is_valid_course($use_course)) {
            $out['needs_input'] = true;
            $out['reason']      = 'Course missing — choose the course before billing can start.';
            return $out;
        }

        $rec_days = (int) ($reg['days_per_week'] ?? 0);
        $use_days = $rec_days > 0 ? $rec_days : (int) $days;

        $calc = IQU_Pricing::calculate($use_course, $use_days);
        if (empty($calc['valid'])) {
            $out['needs_input'] = true;
            $out['reason']      = $calc['error'] ?: 'Choose the classes per week.';
            return $out;
        }

        $gross = round((float) $calc['monthly_amount'], 2);

        // Discounts only apply when we priced from the record itself.
        $from_record = ($rec_course !== '' && $rec_days > 0);
        $discount    = 0.0;
        $label       = '';

        if ($from_record) {
            $discount = min($gross, max(0.0, round((float) ($reg['discount_amount'] ?? 0), 2)));
            if ($discount > 0) {
                if (!empty($reg['zakat_declaration']) || !empty($reg['coupon_code'])) {
                    $label = 'Zakat Fund support';
                } elseif (!empty($reg['flexible_fee_note'])) {
                    $label = 'Agreed reduced fee';
                } elseif (!empty($reg['special_discount'])) {
                    $label = 'Special discount';
                } else {
                    $label = 'Discount';
                }
            }
            $stored = round((float) ($reg['calculated_amount'] ?? 0), 2);
            if ($stored > 0 && abs($stored - $gross) > 0.009) {
                $out['warnings'][] = sprintf(
                    'Fee at enrollment was %s; current pricing gives %s. The current price will be used.',
                    IQU_Pricing::format($stored),
                    IQU_Pricing::format($gross)
                );
            }
        } elseif (self::agreed_fee($reg) === null && (!empty($reg['coupon_code']) || !empty($reg['zakat_declaration']) || !empty($reg['special_discount']))) {
            $out['warnings'][] = 'This record has a coupon or discount, but the course or days were chosen by the admin. No discount is applied — check before sending.';
        }

        // An agreed fee is a price already agreed with the family: it is billed exactly, lower or
        // higher than the standard price. Lower: the standard price with the difference as the
        // reduction. Higher: the agreed fee is the monthly price. The standard price is kept in
        // 'standard' for the note (agreed_note()).
        $agreed = self::agreed_fee($reg);
        if ($agreed !== null) {
            $out['standard'] = $gross;
            $out['agreed']   = $agreed;
            if ($agreed <= $gross) {
                $discount = round($gross - $agreed, 2);
                $label    = $discount > 0 ? (!empty($reg['zakat_declaration']) ? 'Zakat Fund support' : 'Agreed reduced fee') : '';
            } else {
                $gross    = $agreed;
                $discount = 0.0;
                $label    = '';
            }
        }

        $net = round($gross - $discount, 2);

        $out['course']            = $use_course;
        $out['course_label']      = IQU_Pricing::label($use_course);
        $out['days_per_week']     = $use_days;
        $out['classes_per_month'] = (int) $calc['classes_per_month'];
        $out['per_class_rate']    = (float) $calc['per_class_rate'];
        $out['gross']             = $gross;
        $out['discount']          = $discount;
        $out['discount_label']    = $label;
        $out['net']               = $net;

        if ($net <= 0) {
            $out['reason']      = 'Full scholarship — not billed.';
            $out['scholarship'] = true;
            return $out;
        }

        $out['billable'] = true;
        return $out;
    }

    /**
     * Fee chosen on the older enrollment form.
     * @return array{course:string, amount:float, custom:bool}|null
     */
    private static function legacy_fee(array $reg): ?array
    {
        $pref = strtolower(trim((string) ($reg['fee_pref'] ?? '')));
        if (preg_match('/^([a-z]+)_(\d+(?:\.\d{1,2})?)$/', $pref, $m)) {
            $course = IQU_Pricing::is_valid_course($m[1]) ? $m[1] : '';
            if ($course !== '' || $m[1] === 'custom') {
                return ['course' => $course, 'amount' => round((float) $m[2], 2), 'custom' => $m[1] === 'custom'];
            }
        }
        $paid = round((float) ($reg['payment_amount'] ?? 0), 2);
        if ($paid > 0) {
            return ['course' => '', 'amount' => $paid, 'custom' => false];
        }
        return null;
    }

    /** Price an older enrollment at the fee the family chose then. */
    private static function from_legacy(array $out, array $reg, array $legacy): array
    {
        $days = (int) ($reg['days_per_week'] ?? 0);
        $amt  = (float) $legacy['amount'];

        $out['course']            = $legacy['course'];
        $out['course_label']      = $legacy['course'] !== '' ? IQU_Pricing::label($legacy['course']) : 'Monthly tuition';
        $out['days_per_week']     = $days;
        $out['classes_per_month'] = $days > 0 ? $days * 4 : 0;
        $out['per_class_rate']    = $days > 0 ? round($amt / ($days * 4), 2) : 0.0;
        $out['gross']             = $amt;
        $out['net']               = $amt;
        $out['legacy']            = true;
        $out['warnings'][]        = $legacy['custom']
            ? sprintf('Fee the family offered at enrollment: %s a month.', IQU_Pricing::format($amt))
            : sprintf('Fee chosen at enrollment: %s a month.', IQU_Pricing::format($amt));
        // An agreed fee set later by an admin wins over the old form's fee, as on every other record.
        $agreed = self::agreed_fee($reg);
        if ($agreed !== null) {
            $out['standard'] = $amt;
            $out['agreed']   = $agreed;
            if ($agreed <= $amt) {
                $out['discount']       = round($amt - $agreed, 2);
                $out['discount_label'] = $out['discount'] > 0 ? (!empty($reg['zakat_declaration']) ? 'Zakat Fund support' : 'Agreed reduced fee') : '';
            } else {
                $out['gross'] = $agreed;
            }
            $out['net'] = round($out['gross'] - (float) ($out['discount'] ?? 0), 2);
            $amt = $out['net'];
        }

        if ($amt <= 0) {
            $out['reason']      = 'Full scholarship — not billed.';
            $out['scholarship'] = true;
            return $out;
        }
        $out['billable'] = true;
        return $out;
    }

    /** Dollars to Stripe's smallest unit (cents). */
    public static function to_cents(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
