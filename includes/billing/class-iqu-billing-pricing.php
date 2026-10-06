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
    public static function for_registration(array $reg, ?string $course = null, ?int $days = null): array
    {
        $out = [
            'billable'          => false,
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

        // Course: the record wins; an admin choice is used only when the record is empty.
        $use_course = $rec_course !== '' ? $rec_course : (string) $course;
        if (!IQU_Pricing::is_valid_course($use_course)) {
            $out['needs_input'] = true;
            $out['reason']      = 'Choose the course for this student.';
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
        } elseif (!empty($reg['coupon_code']) || !empty($reg['zakat_declaration']) || !empty($reg['special_discount'])) {
            $out['warnings'][] = 'This record has a coupon or discount, but the course or days were chosen by the admin. No discount is applied — check before sending.';
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
            $out['reason'] = 'Full scholarship — not billed.';
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

        if ($amt <= 0) {
            $out['reason'] = 'Full scholarship — not billed.';
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
