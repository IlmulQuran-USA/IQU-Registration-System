<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Billing_Notify
 *
 * Telegram alerts for billing, sent through the registration plugin's
 * IQU_Telegram (bot token and chat id live in wp-config.php).
 *
 * Each alert identifies the family: guardian, every student (full name,
 * IQU number, course, days), the email and WhatsApp number, and the card
 * or bank brand with its last four digits. Stripe never gives more than the last four digits of a card or
 * account, so nothing more sensitive can ever appear here.
 *
 * Payout alerts (3.5.0) are about the whole account: amounts, counts and totals only, never a
 * family's name, email, phone or WhatsApp number.
 */
class IQU_Billing_Notify
{
    private static function ready(): bool
    {
        return class_exists('IQU_Telegram') && IQU_Telegram::is_configured();
    }

    private static function e($v): string
    {
        // Telegram understands numeric entities and only &lt; &gt; &amp; &quot; by name,
        // so an apostrophe must become &#039; (HTML 4), not &apos; (HTML 5).
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML401, 'UTF-8');
    }

    /** Who this is: guardian, students, contact. HTML, escaped. */
    private static function family_block(array $acc): string
    {
        $lines = [];
        $g = trim((string) $acc['guardian_name']);
        $lines[] = 'Guardian: <b>' . self::e($g !== '' ? $g : '— (adult student)') . '</b>';

        $students = [];
        foreach (IQU_Billing_DB::get_members((int) $acc['id']) as $m) {
            $reg = IQU_Database::get_registration((int) $m['registration_id']);
            if (!$reg) continue;
            $p = IQU_Billing_Pricing::for_registration($reg);
            $course = trim(($p['course_label'] ?: 'Monthly tuition') . ($p['days_per_week'] ? ', ' . $p['days_per_week'] . ' days/week' : ''));
            $students[] = '• ' . self::e(trim($reg['first_name'] . ' ' . $reg['last_name'])) . ' (IQU-' . (int) $reg['id'] . ') — ' . self::e($course);
        }
        $lines[] = count($students) > 1 ? 'Students:' : 'Student:';
        foreach ($students as $s) $lines[] = $s;

        $email = trim((string) $acc['contact_email']);
        $phone = trim((string) $acc['contact_whatsapp']);
        if ($email !== '') $lines[] = 'Email: ' . self::e($email);
        if ($phone !== '') $lines[] = 'WhatsApp: ' . self::whatsapp_link($phone);
        return implode("\n", $lines);
    }

    /**
     * Tappable WhatsApp link (wa.me) when the number has a country code.
     * A number without one (10 digits or fewer, no + or 00) is shown as text,
     * because guessing the country could open a chat with a stranger.
     */
    private static function whatsapp_link(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        // A leading single 0 is a local prefix (01712... in Bangladesh, 07700... in the UK), never a country code.
        $has_code = (strpos(ltrim($phone), '+') === 0 || strpos($digits, '00') === 0 || (strlen($digits) > 10 && $digits[0] !== '0'));
        if (strpos($digits, '00') === 0) $digits = substr($digits, 2);
        if (!$has_code || strlen($digits) < 8 || strlen($digits) > 15) {
            return self::e($phone) . ' <i>(no country code)</i>';
        }
        return '<a href="https://wa.me/' . $digits . '">' . self::e($phone) . '</a>';
    }

    /** Latest charge for this customer: method used and the bank's reason if it failed. */
    private static function latest_charge(array $acc): array
    {
        if (empty($acc['stripe_customer_id'])) return [];
        $r = IQU_Stripe::get('/charges', ['customer' => $acc['stripe_customer_id'], 'limit' => 1]);
        $c = $r['ok'] ? ($r['data']['data'][0] ?? []) : [];
        if (!$c) return [];
        $pm = (array) ($c['payment_method_details'] ?? []);
        $method = '';
        if (($pm['type'] ?? '') === 'card') {
            $method = ucfirst((string) ($pm['card']['brand'] ?? 'Card')) . ' •••• ' . ($pm['card']['last4'] ?? '');
        } elseif (($pm['type'] ?? '') === 'us_bank_account') {
            $method = ((string) ($pm['us_bank_account']['bank_name'] ?? 'Bank')) . ' •••• ' . ($pm['us_bank_account']['last4'] ?? '');
        }
        return [
            'method' => $method,
            'reason' => (string) ($c['failure_message'] ?? ($c['outcome']['seller_message'] ?? '')),
        ];
    }

    private static function links(array $acc, array $inv = []): string
    {
        $out = '<a href="' . self::e(admin_url('admin.php?page=iqu-billing-message&account=' . (int) $acc['id'])) . '">Open billing record</a>';
        if (!empty($inv['id']) && preg_match('/^in_[A-Za-z0-9]+$/', (string) $inv['id'])) {
            $dash = 'https://dashboard.stripe.com/' . (IQU_Stripe::expected_mode() === 'test' ? 'test/' : '') . 'invoices/' . $inv['id'];
            $out .= ' · <a href="' . self::e($dash) . '">View in Stripe</a>';
        }
        return $out;
    }

    /** "October 2026 tuition" from the invoice line period. */
    private static function period(array $inv): string
    {
        // The tuition month as Reports file it (the non-proration line, the site's calendar).
        $start = (int) (IQU_Billing_History::tuition_line($inv)['period']['start'] ?? $inv['period_start'] ?? 0);
        return $start ? IQU_Billing_History::month_label(IQU_Billing_History::month_of($start)) . ' tuition' : '';
    }

    private static function day(?string $mysql_utc): string
    {
        $ts = $mysql_utc ? strtotime($mysql_utc . ' UTC') : 0;
        return $ts ? wp_date('j M Y', $ts) : '—';
    }

    /** Title, then the money lines, then who it is, then links. */
    private static function send(string $title, array $top, array $acc, array $inv = [], array $bottom = []): void
    {
        if (!self::ready()) return;
        $mode = IQU_Stripe::expected_mode() === 'test' ? ' <i>[TEST]</i>' : '';
        $html = '<b>' . self::e($title) . '</b>' . $mode . "\n\n";
        foreach ($top as $l) $html .= $l . "\n";
        $html .= "\n" . self::family_block($acc) . "\n";
        if ($bottom) $html .= "\n" . implode("\n", $bottom) . "\n";
        $html .= "\n" . self::links($acc, $inv);
        IQU_Telegram::send($html, false);
    }

    /** An alert about the whole account (payouts): title, lines, no family and no contact details. */
    private static function send_account(string $title, array $lines): void
    {
        if (!self::ready()) return;
        $mode = IQU_Stripe::expected_mode() === 'test' ? ' <i>[TEST]</i>' : '';
        $html = '<b>' . self::e($title) . '</b>' . $mode . "\n\n";
        foreach ($lines as $l) $html .= $l . "\n";
        IQU_Telegram::send(rtrim($html), false);
    }

    /** "STRIPE TEST BANK •••• 6789", "Bank •••• 6789" or "" (never more than the last four digits). */
    private static function payout_bank(array $p): string
    {
        $name = trim((string) ($p['bank_name'] ?? ''));
        $last = (string) ($p['last4'] ?? '');
        if ($last === '') return $name;
        return ($name !== '' ? $name : 'Bank') . ' •••• ' . $last;
    }

    // ------------------------------------------------------------
    // Alerts
    // ------------------------------------------------------------

    public static function method_added(array $acc): void
    {
        self::send("\u{1F4B3} Payment method added", [
            'Monthly: <b>' . self::e(IQU_Pricing::format((float) $acc['net_amount'])) . '</b>',
            'Method: ' . self::e($acc['payment_method_label'] ?: 'saved'),
            'First charge: ' . self::e(self::day($acc['next_charge_at'] ?: ($acc['first_charge_date'] ? $acc['first_charge_date'] . ' 15:00:00' : null))),
        ], $acc);
    }

    /** An old Checkout page set up a subscription at a different amount (IQU_Billing_Service::flag_amount_mismatch). */
    public static function amount_mismatch(array $acc, string $note, string $label = 'Review: amount mismatch'): void
    {
        self::send("\u{26A0}\u{FE0F} " . $label, [self::e($note)], $acc, [], [
            'The family is <b>not</b> marked active and nothing was cancelled. Please check the subscription in Stripe, then press "Mark as reviewed" on the student page.',
        ]);
    }

    public static function duplicate_subscription(array $acc, string $note): void
    {
        self::send("\u{26A0}\u{FE0F} Review: two subscriptions", [self::e($note)], $acc, [], [
            'The family would be charged twice. Nothing was cancelled automatically.',
        ]);
    }

    public static function payment_received(array $acc, float $amount, bool $first = false, array $inv = []): void
    {
        $ch  = self::latest_charge($acc);
        $top = ['Paid: <b>' . self::e(IQU_Pricing::format($amount)) . '</b>' . (self::period($inv) ? ' · ' . self::e(self::period($inv)) : '')];
        $top[] = 'Method: ' . self::e(($ch['method'] ?? '') ?: ($acc['payment_method_label'] ?: '—'));
        if (!empty($inv['number'])) $top[] = 'Receipt no: ' . self::e($inv['number']);
        self::send($first ? "\u{2705} First tuition payment received" : "\u{2705} Tuition payment received", $top, $acc, $inv, [
            'Next charge: ' . self::e(self::day($acc['next_charge_at'])),
        ]);
    }

    public static function payment_failed(array $acc, float $amount, int $attempt, ?int $next_attempt, array $inv = []): void
    {
        $ch  = self::latest_charge($acc);
        $top = ['Amount: <b>' . self::e(IQU_Pricing::format($amount)) . '</b>' . (self::period($inv) ? ' · ' . self::e(self::period($inv)) : '')];
        $top[] = 'Method: ' . self::e(($ch['method'] ?? '') ?: ($acc['payment_method_label'] ?: '—'));
        if (!empty($ch['reason'])) $top[] = 'Bank said: ' . self::e($ch['reason']);
        $top[] = 'Attempt: ' . max(1, $attempt);
        self::send("\u{26A0}\u{FE0F} Payment failed", $top, $acc, $inv, [
            $next_attempt ? 'Stripe tries again on ' . self::e(wp_date('j M', $next_attempt)) . '. No action needed yet.' : '<b>No more automatic attempts.</b>',
        ]);
    }

    /** Our own receipt email could not be sent (wp_mail returned false). $retry: the hourly retry failed too. */
    public static function receipt_failed(array $acc, array $row, bool $retry = false): void
    {
        $family = trim((string) $acc['guardian_name']) ?: ('Family #' . (int) $acc['id']);
        $month  = IQU_Billing_History::month_label((string) $row['period_month']);
        $top = ['Amount: <b>' . self::e(IQU_Pricing::format((float) $row['amount_paid'])) . '</b>'];
        if (!empty($row['receipt_number'])) $top[] = 'Receipt no: ' . self::e((string) $row['receipt_number']);
        $top[] = 'Email: ' . self::e((string) $acc['contact_email']);
        self::send("\u{2709}\u{FE0F} Receipt not sent — {$family}, {$month}" . ($retry ? ' (retry failed)' : ''), $top, $acc, ['id' => (string) $row['stripe_invoice_id']], [
            $retry
                ? 'The hourly retry failed too. Please check the site\'s email sending and send the receipt by hand.'
                : 'The payment itself is fine. We will try the email once more within the hour.',
        ]);
    }

    /** The family's US bank account could not be verified (microdeposits): nothing was charged. */
    public static function bank_not_verified(array $acc): void
    {
        self::send("\u{26A0}\u{FE0F} Bank account not verified", [
            'Monthly: <b>' . self::e(IQU_Pricing::format((float) $acc['net_amount'])) . '</b>',
            'Reason: ' . self::e($acc['last_failure_reason'] ?: 'The bank account could not be verified.'),
        ], $acc, [], [
            'Nothing was charged. The family page asks them to add a bank account or card again.',
        ]);
    }

    /** A payment was disputed — for a bank payment, returned by the bank after it was paid. */
    public static function payment_disputed(array $acc, float $amount, string $reason, ?array $row = null): void
    {
        $top = ['Amount: <b>' . self::e(IQU_Pricing::format($amount)) . '</b>'
            . ($row && !empty($row['period_month']) ? ' · ' . self::e(IQU_Billing_History::month_label((string) $row['period_month'])) : '')];
        if ($row && !empty($row['method_label'])) $top[] = 'Method: ' . self::e((string) $row['method_label']);
        if ($reason !== '') $top[] = 'Reason: ' . self::e(str_replace('_', ' ', $reason));
        self::send("\u{1F534} Payment disputed or returned by the bank", $top, $acc, $row ? ['id' => (string) $row['stripe_invoice_id']] : [], [
            'The money goes back to the family. Please contact them, and respond in Stripe → Disputes if needed.',
        ]);
    }

    /**
     * A payout reached the bank. $payout: IQU_Billing_Payout_Data::payout_fields(); $summary: that payout's summary,
     * or [] when its transactions are not known yet (then amount and date only). Counts and totals only: no family
     * is named and no contact detail is included.
     */
    public static function payout_paid(array $payout, array $summary): void
    {
        $lines = ['Amount: <b>' . self::e(IQU_Pricing::format(((int) $payout['amount']) / 100)) . '</b>'];
        if (!empty($payout['arrival_date'])) $lines[] = 'Arrival: ' . self::e(wp_date('j M Y', (int) $payout['arrival_date']));
        if (($bank = self::payout_bank($payout)) !== '') $lines[] = 'Bank: ' . self::e($bank);
        if (!empty($summary['totals'])) {
            $t = $summary['totals'];
            $lines[] = '';
            $lines[] = 'Family payments: ' . (int) $t['payments'];
            $lines[] = 'Gross: ' . self::e(IQU_Pricing::format($t['gross'] / 100));
            $lines[] = 'Stripe fees: ' . self::e(IQU_Pricing::format($t['fees'] / 100));
            $lines[] = 'Refunds / disputes: ' . self::e(IQU_Pricing::format($t['refunds'] / 100));
            if ((int) $t['payout_diff'] !== 0) $lines[] = '<b>Check:</b> the transactions add up to ' . self::e(IQU_Pricing::format($t['net'] / 100)) . ', not the payout amount.';
        } else {
            $lines[] = '';
            $lines[] = 'The list of payments in this payout is not ready yet; the payout page shows it once Stripe has matched them.';
        }
        $lines[] = '';
        $lines[] = '<a href="' . self::e(admin_url('admin.php?page=iqu-billing-payouts&payout=' . rawurlencode((string) $payout['id']))) . '">Open payout</a>';
        self::send_account("\u{1F4B0} Payout sent to bank", $lines);
    }

    /** A payout failed. $was_paid: a "sent to bank" alert went out for it earlier. */
    public static function payout_failed(array $payout, bool $was_paid = false): void
    {
        $lines = ['Amount: <b>' . self::e(IQU_Pricing::format(((int) $payout['amount']) / 100)) . '</b>'];
        if ($was_paid) $lines[] = '<b>This payout was reported as sent earlier and has now failed.</b>';
        $lines[] = 'Failure code: ' . self::e(($payout['failure_code'] ?? '') ?: '—');
        if (!empty($payout['failure_message'])) $lines[] = 'Stripe said: ' . self::e($payout['failure_message']);
        if (($payout['last4'] ?? '') !== '') $lines[] = 'Bank: •••• ' . self::e($payout['last4']);
        $lines[] = 'The money stays in the Stripe balance until the bank details are fixed.';
        $lines[] = '';
        $dash = 'https://dashboard.stripe.com/' . (IQU_Stripe::expected_mode() === 'test' ? 'test/' : '') . 'payouts/' . rawurlencode((string) $payout['id']);
        $lines[] = '<a href="' . self::e($dash) . '">Open in Stripe</a>';
        self::send_account("\u{26A0}\u{FE0F} Payout failed", $lines);
    }

    public static function needs_contact(array $acc, string $status): void
    {
        $label = $status === 'canceled' ? 'Subscription canceled' : 'Automatic attempts finished';
        self::send("\u{1F534} {$label} — please contact the family", [
            'Monthly: <b>' . self::e(IQU_Pricing::format((float) $acc['net_amount'])) . '</b>',
            'Method: ' . self::e($acc['payment_method_label'] ?: '—'),
            'Last problem: ' . self::e($acc['last_failure_reason'] ?: '—'),
        ], $acc, [], [
            'Classes continue. Offer help or the Zakat Fund if money is the reason.',
        ]);
    }
}
