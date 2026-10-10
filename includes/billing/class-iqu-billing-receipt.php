<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Billing_Receipt
 *
 * Our own monthly receipt email, sent once per paid invoice — only when
 * Billing → Settings → "Who sends payment receipts?" is "Ilm-ul-Quran USA" (option
 * iqu_billing_receipt_sender = 'iqu'; the default 'stripe' leaves receipts to Stripe).
 *
 * Once per invoice:
 * - The webhook calls maybe_send() after invoice.paid has been stored in the payments table.
 * - The row is claimed atomically (UPDATE … SET receipt_sent_at WHERE receipt_sent_at IS NULL),
 *   so a retried webhook or a second delivery never sends twice.
 * - When wp_mail() fails, receipt_sent_at is cleared again, the invoice is queued, a Telegram
 *   alert goes out, and the hourly iqu_enroll_reminders event retries it once.
 * Sync and Backfill never call this, so old invoices never get a receipt.
 *
 * Security: local database only (no Stripe call), every query prepared and limited to the
 * current mode, test mode only emails our own addresses, never throws into the webhook.
 */
class IQU_Billing_Receipt
{
    public const OPT_SENDER  = 'iqu_billing_receipt_sender';   // 'stripe' (default) | 'iqu'
    public const OPT_CHANGED = 'iqu_billing_receipt_changed';  // ['user' => id, 'at' => unix time, 'value' => sender]
    private const OPT_RETRY  = 'iqu_billing_receipt_retry';    // [invoice id => queued at]

    public static function init(): void
    {
        // The hourly enrollment event (IQU_Enrollment::schedule) — no schedule of our own.
        add_action('iqu_enroll_reminders', [__CLASS__, 'retry']);
    }

    /** 'iqu' when we send receipts, otherwise 'stripe'. */
    public static function sender(): string
    {
        return get_option(self::OPT_SENDER, 'stripe') === 'iqu' ? 'iqu' : 'stripe';
    }

    /** After invoice.paid (webhook). Never throws. */
    public static function maybe_send(string $invoice_id): void
    {
        try {
            if (self::attempt($invoice_id, false) === 'failed') {
                $queue = (array) get_option(self::OPT_RETRY, []);
                $queue[$invoice_id] = time();
                update_option(self::OPT_RETRY, $queue, false);
            }
        } catch (\Throwable $e) {
            error_log('IQU Billing: receipt for ' . $invoice_id . ' not handled — ' . $e->getMessage());
        }
    }

    /** Hourly: one more try for each receipt that could not be sent, then it leaves the queue. */
    public static function retry(): void
    {
        $queue = (array) get_option(self::OPT_RETRY, []);
        if (!$queue) return;
        delete_option(self::OPT_RETRY);
        foreach (array_keys($queue) as $invoice_id) {
            try {
                self::attempt((string) $invoice_id, true);
            } catch (\Throwable $e) {
                error_log('IQU Billing: receipt retry for ' . $invoice_id . ' — ' . $e->getMessage());
            }
        }
    }

    /** @return string 'sent', 'skipped' or 'failed' */
    public static function attempt(string $invoice_id, bool $is_retry): string
    {
        if (self::sender() !== 'iqu' || !preg_match('/^in_[A-Za-z0-9]+$/', $invoice_id)) return 'skipped';
        global $wpdb;
        $table = IQU_Billing_DB::payments_table();
        $mode  = IQU_Stripe::expected_mode();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE stripe_invoice_id = %s AND mode = %s", $invoice_id, $mode), ARRAY_A);
        if (!$row || $row['status'] !== 'paid' || (float) $row['amount_paid'] <= 0 || !empty($row['receipt_sent_at'])) return 'skipped';
        $acc = IQU_Billing_DB::get_account((int) $row['account_id']);
        if (!$acc || !is_email($acc['contact_email'])) return 'skipped';
        if ($mode === 'test' && !self::test_email_allowed((string) $acc['contact_email'])) return 'skipped';

        // Claim the row: only one request can move receipt_sent_at from NULL.
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET receipt_sent_at = %s WHERE id = %d AND mode = %s AND receipt_sent_at IS NULL",
            current_time('mysql', true), (int) $row['id'], $mode
        ));
        if ((int) $claimed !== 1) return 'skipped';

        [$subject, $content] = self::content($acc, $row);
        if (IQU_Billing_Email::send((string) $acc['contact_email'], $subject, $content)) return 'sent';

        // Not sent: free the row for the retry and tell the team.
        $wpdb->query($wpdb->prepare("UPDATE {$table} SET receipt_sent_at = NULL WHERE id = %d AND mode = %s", (int) $row['id'], $mode));
        IQU_Billing_Notify::receipt_failed($acc, $row, $is_retry);
        return 'failed';
    }

    /** In test mode, emails only go to our own addresses (same rule as the billing screens). */
    private static function test_email_allowed(string $email): bool
    {
        $allowed = array_map('strtolower', array_filter([get_option('admin_email'), defined('IQU_CONTACT_EMAIL') ? IQU_CONTACT_EMAIL : '']));
        return in_array(strtolower(trim($email)), $allowed, true);
    }

    /**
     * Subject and layout options of the receipt for one paid invoice row.
     * @return array{0:string, 1:array}
     */
    public static function content(array $acc, array $row): array
    {
        $paid   = (float) $row['amount_paid'];
        $amount = IQU_Pricing::format($paid);
        $ym     = (string) $row['period_month'];
        $month_ts = IQU_Billing_History::is_month($ym) ? (int) strtotime($ym . '-15 12:00:00 UTC') : 0;
        $month  = $month_ts ? gmdate('F', $month_ts) : '';
        $kids   = self::first_names($acc);
        $paid_ts = !empty($row['paid_at']) ? (int) strtotime($row['paid_at'] . ' UTC') : 0;
        $date   = $paid_ts ? wp_date('j F Y', $paid_ts) : '';
        $method = trim((string) $row['method_label']);
        $name   = trim((string) $acc['guardian_name']);

        // Tuition period from the invoice line period (site time zone); Stripe's end is the next period's start.
        $start = !empty($row['period_start']) ? (int) strtotime($row['period_start'] . ' UTC') : 0;
        $end   = !empty($row['period_end']) ? (int) strtotime($row['period_end'] . ' UTC') : 0;
        $period = $start ? wp_date('j M', $start) . ($end > $start ? ' – ' . wp_date('j M Y', $end - DAY_IN_SECONDS) : ' ' . wp_date('Y', $start)) : ($ym !== '' ? IQU_Billing_History::month_label($ym) : '');

        // Amount rows: the discount only when the account's fee adds up to this invoice.
        $due   = (float) $row['amount_due'];
        $gross = (float) ($acc['monthly_amount'] ?? 0);
        $disc  = (float) ($acc['discount_amount'] ?? 0);
        $money = ($disc > 0 && abs($gross - $disc - $due) < 0.01)
            ? [['Amount', IQU_Pricing::format($gross)], ['Zakat Fund support', '−' . IQU_Pricing::format($disc)]]
            : [['Amount', IQU_Pricing::format($due)]];
        $money[] = ['Total paid', $amount];

        $next = '';
        if (!empty($acc['next_charge_at']) && $acc['status'] !== 'canceled') {
            $nts  = (int) strtotime($acc['next_charge_at'] . ' UTC');
            $next = $nts > time() ? 'Next payment: ' . IQU_Pricing::format((float) $acc['net_amount']) . ' on ' . wp_date('j F Y', $nts) . '.' : '';
        }

        $blocks = [
            ['badge', 'Payment received'],
            ['hero', $amount . ' paid', implode(' · ', array_filter([$date, $method]))],
            ['p', 'Assalamu alaikum' . ($name !== '' ? ' ' . explode(' ', $name)[0] : '') . ','],
            ['p', "JazakAllahu khayran — we received the " . ($month !== '' ? $month . ' ' : '') . "tuition payment for {$kids}. Here is your receipt."],
            ['table', array_merge([
                ['Receipt no.', (string) $row['receipt_number']],
                ['Date paid', $date],
                ['Paid with', $method],
            ], IQU_Billing_Email::student_rows($acc), [
                ['Tuition period', $period],
            ], $money)],
        ];
        if ($next !== '') $blocks[] = ['p', $next];
        $blocks[] = ['buttons', [
            ['View invoice', IQU_Billing_History::safe_url((string) $row['hosted_invoice_url'])],
            ['Open your billing page', IQU_Billing_Service::link_for($acc)],
        ]];

        return ["Receipt — {$kids}'s " . ($month !== '' ? $month . ' ' : '') . "tuition, {$amount}", [
            'preheader'   => "We received {$amount}" . ($month !== '' ? " for {$month} tuition" : '') . ". Thank you.",
            'blocks'      => $blocks,
            'private'     => true,
            'footer_note' => 'This is a receipt for tuition. It is not a donation receipt.',
        ]];
    }

    /** "Ayesha and Bilal" (first names of the family's students). */
    private static function first_names(array $acc): string
    {
        $names = [];
        foreach (IQU_Billing_DB::get_members((int) $acc['id']) as $m) {
            $reg = IQU_Database::get_registration((int) $m['registration_id']);
            if ($reg) $names[] = trim((string) $reg['first_name']);
        }
        if (count($names) <= 1) return (string) ($names[0] ?? 'your child');
        $last = array_pop($names);
        return implode(', ', $names) . ' and ' . $last;
    }
}
