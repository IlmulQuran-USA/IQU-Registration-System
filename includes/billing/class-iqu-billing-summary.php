<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Billing_Summary
 *
 * Daily tuition summary to the IQU Notification Telegram group, sent as its
 * own message a few minutes after the registration plugin's Daily Summary.
 *
 * Privacy: counts and totals only, no names, emails or payment details.
 * In test mode it is sent only on days with tuition activity, so test
 * data does not fill the group every night.
 */
class IQU_Billing_Summary
{
    public const HOOK = 'iqu_billing_daily_summary';
    private const SEND_AT_UTC = '15:10'; // 9:10 PM Dhaka / 10:10 AM US Central

    public static function init(): void
    {
        add_filter('iqu_daily_summary_rows', [__CLASS__, 'add_rows'], 10, 2);
        add_filter('iqu_daily_summary_footer', [__CLASS__, 'footer'], 10, 2);
        add_action('init', [__CLASS__, 'remove_old_schedule']);
    }

    /** The separate tuition message was replaced by lines in the main Daily Summary. */
    public static function remove_old_schedule(): void
    {
        if (wp_next_scheduled(self::HOOK)) wp_clear_scheduled_hook(self::HOOK);
    }

    /** @return array<string,mixed> numbers for the last 24 hours and right now */
    public static function stats(): array
    {
        global $wpdb;
        $acc  = IQU_Billing_DB::accounts_table();
        $mode = IQU_Stripe::expected_mode();
        $now  = time();

        $by = [];
        foreach ((array) $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) AS n, COALESCE(SUM(net_amount),0) AS total FROM {$acc} WHERE mode = %s GROUP BY status", $mode
        ), ARRAY_A) as $r) {
            $by[$r['status']] = ['n' => (int) $r['n'], 'total' => (float) $r['total']];
        }
        $n   = fn(array $s) => array_sum(array_map(fn($k) => $by[$k]['n'] ?? 0, $s));
        $sum = fn(array $s) => array_sum(array_map(fn($k) => $by[$k]['total'] ?? 0, $s));

        $billing = ['free_month', 'waiting_first_charge', 'active', 'past_due'];

        $stale = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$acc} WHERE mode = %s AND status IN ('not_sent','link_sent') AND COALESCE(email_sent_at, whatsapp_sent_at, created_at) < %s",
            $mode, gmdate('Y-m-d H:i:s', $now - 7 * DAY_IN_SECONDS)
        ));
        $trial_ending = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$acc} WHERE mode = %s AND status = 'free_month' AND next_charge_at IS NOT NULL AND next_charge_at < %s",
            $mode, gmdate('Y-m-d H:i:s', $now + 7 * DAY_IN_SECONDS)
        ));
        $failed_today = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . IQU_Billing_DB::events_table() . " WHERE mode = %s AND type = 'invoice.payment_failed' AND received_at >= %s",
            $mode, gmdate('Y-m-d H:i:s', $now - DAY_IN_SECONDS)
        ));

        $month_start = strtotime(gmdate('Y-m-01 00:00:00') . ' UTC');

        return [
            'accounts'      => array_sum(array_column($by, 'n')),
            'paid_today'    => IQU_Billing_DB::paid_between($now - DAY_IN_SECONDS, $now + 60),
            'month_to_date' => IQU_Billing_DB::paid_between($month_start, $now + 60),
            'failed_today'  => $failed_today,
            'problems'      => $n(['past_due', 'unpaid', 'paused']),
            'no_card'       => $n(['not_sent', 'link_sent']),
            'no_card_stale' => $stale,
            'trial_ending'  => $trial_ending,
            'on_billing'    => $n($billing),
            'recurring'     => round($sum($billing), 2),
        ];
    }

    /**
     * Adds tuition lines to the registration plugin's Daily Summary
     * (filter iqu_daily_summary_rows). Counts and totals only.
     */
    public static function add_rows(array $rows, int $since = 0): array
    {
        $s = self::stats();
        if ($s['accounts'] === 0) return $rows;

        $esc  = fn($v) => class_exists('IQU_Telegram') ? IQU_Telegram::esc($v) : htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $test = IQU_Stripe::expected_mode() === 'test' ? ' [test]' : '';

        $rows['Tuition paid' . $test] = $s['paid_today']['count'] . ' (' . $esc(IQU_Pricing::format($s['paid_today']['total'])) . ')';
        if ($s['failed_today'] > 0) $rows['Tuition failed' . $test] = (string) $s['failed_today'];
        if ($s['problems'] > 0)     $rows['Payment problems' . $test] = $s['problems'] . ' — contact the families';
        if ($s['no_card'] > 0)      $rows['No card yet' . $test] = $s['no_card'] . ($s['no_card_stale'] ? ' (' . $s['no_card_stale'] . ' over 7 days)' : '');
        if ($s['trial_ending'] > 0) $rows['Free month ending' . $test] = $s['trial_ending'] . ' this week';
        $rows['On billing' . $test]         = $s['on_billing'] . ' families · ' . $esc(IQU_Pricing::format($s['recurring'])) . '/month';
        $rows['Tuition this month' . $test] = $esc(IQU_Pricing::format($s['month_to_date']['total']));
        return $rows;
    }

    /** A day with tuition payments is not "a quiet day". */
    public static function footer(string $footer, int $since = 0): string
    {
        $s = self::stats();
        if (($s['paid_today']['count'] > 0 || $s['failed_today'] > 0) && stripos($footer, 'quiet') !== false) {
            return 'Covers the last 24 hours.';
        }
        return $footer;
    }
}
