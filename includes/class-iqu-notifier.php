<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Notifier
 *
 * Turns plugin events into Telegram messages.
 *
 * IQU_Telegram handles delivery; this class decides what is worth sending
 * and how it reads. Keeping the two apart means the message wording can
 * change without touching the transport, and a second channel (email,
 * Slack) could be added later by writing a sibling class.
 *
 * Events covered:
 *   1. New donation received via Zeffy       (real-time, webhook)
 *   2. Refund or dispute detected            (twice daily, reconcile sync)
 *   3. New student registration              (real-time, form submit)
 *   4. Daily summary                         (once a day, cron)
 */
class IQU_Notifier
{
    public const CRON_DAILY = 'iqu_telegram_daily_summary';

    /** Remembers which payments we have already flagged as refunded. */
    private const OPT_FLAGGED = 'iqu_telegram_flagged_refunds';

    public function __construct()
    {
        // Zeffy donations — fired from the webhook handler.
        add_action('iqu_zeffy_payment_received', [__CLASS__, 'on_donation'], 10, 2);

        // Student registrations — fired from both public forms.
        add_action('iqu_registration_created', [__CLASS__, 'on_registration'], 10, 2);

        // Daily summary.
        add_action(self::CRON_DAILY, [__CLASS__, 'send_daily_summary']);

        // Admin test button.
        add_action('wp_ajax_iqu_telegram_test', [$this, 'ajax_test']);
    }

    // ════════════════════════════════════════════════════
    // SCHEDULING
    // ════════════════════════════════════════════════════

    /**
     * Run the summary at 21:00 Asia/Dhaka, which is late morning in the US
     * — the team sees the previous day's activity before the US day starts.
     */
    public static function schedule(): void
    {
        if (wp_next_scheduled(self::CRON_DAILY)) {
            return;
        }

        try {
            $next = new DateTime('today 21:00', new DateTimeZone('Asia/Dhaka'));
            if ($next->getTimestamp() <= time()) {
                $next->modify('+1 day');
            }
            $timestamp = $next->getTimestamp();
        } catch (Exception $e) {
            $timestamp = time() + HOUR_IN_SECONDS;
        }

        wp_schedule_event($timestamp, 'daily', self::CRON_DAILY);
    }

    public static function unschedule(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_DAILY);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_DAILY);
        }
        wp_clear_scheduled_hook(self::CRON_DAILY);
    }

    // ════════════════════════════════════════════════════
    // 1. NEW DONATION
    // ════════════════════════════════════════════════════

    /**
     * @param array  $payment Raw Zeffy payment object.
     * @param string $outcome 'inserted' or 'updated'.
     */
    public static function on_donation(array $payment, string $outcome): void
    {
        // Only announce genuinely new donations. An 'updated' outcome means
        // Zeffy retried a delivery we already stored, and re-announcing it
        // would make the team think a second gift arrived.
        if ($outcome !== 'inserted' || !IQU_Telegram::is_configured()) {
            return;
        }

        $row      = IQU_Zeffy_DB::map_payment($payment);
        $currency = (string) $row['currency'];
        $amount   = IQU_Zeffy_DB::money((int) $row['amount'], $currency);

        $rows = [
            'Amount'   => IQU_Telegram::esc($amount) . ' ' . strtoupper(IQU_Telegram::esc($currency)),
            'Donor'    => IQU_Telegram::esc(IQU_Zeffy_DB::donor_name($row)),
            'Email'    => IQU_Telegram::esc($row['buyer_email']),
            'Campaign' => IQU_Telegram::esc($row['description']),
            'Fund'     => IQU_Telegram::esc($row['fund_name']),
        ];

        if (!empty($row['is_recurring'])) {
            $interval = $row['recurring_interval'] !== '' ? $row['recurring_interval'] : 'recurring';
            $rows['Type'] = '🔁 ' . IQU_Telegram::esc(ucfirst($interval));
        }

        if (!empty($row['is_corporate'])) {
            $rows['Organisation'] = IQU_Telegram::esc($row['company_name']);
        }

        // A tribute gift usually deserves a personal acknowledgement, so it
        // is worth surfacing rather than leaving buried in the dashboard.
        if (!empty($row['tribute_type'])) {
            $label = $row['tribute_type'] === 'in_memory_of' ? '🕊 In memory of' : '🎗 In honour of';
            $rows['Tribute'] = IQU_Telegram::esc($label . ' ' . $row['tribute_name']);
        }

        $rows['Method'] = trim(
            IQU_Telegram::esc(strtoupper($row['card_brand'])) . ' ' .
            ($row['card_last4'] !== '' ? '••••' . IQU_Telegram::esc($row['card_last4']) : '')
        );

        $dashboard = admin_url('admin.php?page=' . IQU_Zeffy_Admin::PAGE_SLUG);
        $rows['—'] = IQU_Telegram::link($dashboard, 'Open donations dashboard');

        IQU_Telegram::send(IQU_Telegram::compose(
            '🤲 New Donation Received',
            $rows,
            IQU_Telegram::now()
        ));
    }

    // ════════════════════════════════════════════════════
    // 2. REFUND / DISPUTE
    // ════════════════════════════════════════════════════

    /**
     * Called after each reconcile sync. Zeffy has no refund event, so the
     * only way to notice one is to compare the freshly synced rows against
     * what we have already reported.
     */
    public static function check_refunds_and_disputes(): void
    {
        if (!IQU_Telegram::is_configured()) {
            return;
        }

        global $wpdb;
        $table = IQU_Zeffy_DB::table();
        $since = time() - (90 * DAY_IN_SECONDS);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT payment_id, buyer_first_name, buyer_last_name, company_name,
                    is_corporate, amount, refunded_amount, refund_status,
                    dispute_status, dispute_reason, currency, description
             FROM `{$table}`
             WHERE created_ts >= %d
               AND (refund_status <> 'none' OR dispute_status <> '')",
            $since
        ), ARRAY_A) ?: [];

        if (empty($rows)) {
            return;
        }

        $flagged = get_option(self::OPT_FLAGGED, []);
        if (!is_array($flagged)) {
            $flagged = [];
        }

        $newly_flagged = [];

        foreach ($rows as $row) {
            $id = $row['payment_id'];

            // The state string is part of the key, so a payment that goes
            // partial → full refund, or needs_response → lost, is announced
            // again rather than staying silent on a meaningful change.
            $state = $id . '|' . $row['refund_status'] . '|' . $row['dispute_status'];

            if (isset($flagged[$state])) {
                $newly_flagged[$state] = $flagged[$state];
                continue;
            }

            $newly_flagged[$state] = time();
            self::send_refund_alert($row);
        }

        // Keep only what is still relevant, so the option cannot grow forever.
        update_option(self::OPT_FLAGGED, $newly_flagged, false);
    }

    private static function send_refund_alert(array $row): void
    {
        $currency = (string) $row['currency'];
        $donor    = IQU_Zeffy_DB::donor_name($row);

        $is_dispute = $row['dispute_status'] !== '';

        if ($is_dispute) {
            $title = '⚠️ Payment Disputed';
            $rows  = [
                'Donor'  => IQU_Telegram::esc($donor),
                'Amount' => IQU_Telegram::esc(IQU_Zeffy_DB::money((int) $row['amount'], $currency)),
                'Status' => IQU_Telegram::esc(ucfirst(str_replace('_', ' ', $row['dispute_status']))),
                'Reason' => IQU_Telegram::esc(ucfirst(str_replace('_', ' ', $row['dispute_reason']))),
            ];
            $footer = 'A dispute usually needs a response within a few days.';
        } else {
            $full  = $row['refund_status'] === 'full';
            $title = $full ? '↩️ Donation Refunded' : '↩️ Partial Refund';
            $rows  = [
                'Donor'    => IQU_Telegram::esc($donor),
                'Original' => IQU_Telegram::esc(IQU_Zeffy_DB::money((int) $row['amount'], $currency)),
                'Refunded' => IQU_Telegram::esc(IQU_Zeffy_DB::money((int) $row['refunded_amount'], $currency)),
                'Campaign' => IQU_Telegram::esc($row['description']),
            ];
            $footer = 'Recorded during the scheduled reconcile sync.';
        }

        IQU_Telegram::send(IQU_Telegram::compose($title, $rows, $footer));
    }

    // ════════════════════════════════════════════════════
    // 3. NEW REGISTRATION
    // ════════════════════════════════════════════════════

    /**
     * @param array $clean  Validated registration data.
     * @param int   $reg_id Row ID.
     */
    public static function on_registration(array $clean, int $reg_id): void
    {
        if (!IQU_Telegram::is_configured()) {
            return;
        }

        $form_type = (string) ($clean['form_type'] ?? '');
        $is_summer = in_array($form_type, ['summer_level1', 'summer_level2'], true);

        $name = trim(($clean['first_name'] ?? '') . ' ' . ($clean['last_name'] ?? ''));

        $rows = [
            'Student'   => IQU_Telegram::esc($name),
            'Programme' => IQU_Telegram::esc(IQU_Mailer::form_label($form_type)),
            'Age'       => IQU_Telegram::esc($clean['age'] ?? ''),
            'Email'     => IQU_Telegram::esc($clean['email'] ?? ''),
            'Country'   => IQU_Telegram::esc($clean['country_res'] ?? ''),
        ];

        if ($is_summer) {
            $rows['Guardian'] = IQU_Telegram::esc($clean['guardian_name'] ?? '');
            $rows['Contact']  = IQU_Telegram::esc($clean['guardian_contact'] ?? '');
            $rows['Fee']      = IQU_Telegram::esc(self::summer_fee_label($clean));
        } else {
            $rows['WhatsApp'] = IQU_Telegram::esc($clean['whatsapp'] ?? '');
            $rows['Level']    = IQU_Telegram::esc(ucfirst((string) ($clean['quran_level'] ?? '')));
            $rows['Fee']      = IQU_Telegram::esc(self::free_fee_label($clean));
        }

        if (!empty($clean['referral'])) {
            $rows['Heard via'] = IQU_Telegram::esc(
                ucwords(str_replace('_', ' ', (string) $clean['referral']))
            );
        }

        $view = admin_url('admin.php?page=iqu-view-registration&id=' . $reg_id);
        $rows['—'] = IQU_Telegram::link($view, 'View full registration →');

        IQU_Telegram::send(IQU_Telegram::compose(
            '📝 New Registration',
            $rows,
            'Registration #' . $reg_id . ' · ' . IQU_Telegram::now()
        ));
    }

    private static function free_fee_label(array $clean): string
    {
        $pref = (string) ($clean['fee_pref'] ?? '');

        if ($pref === 'free')  return 'Requesting free enrollment (Zakat)';
        if ($pref === 'other') return trim((string) ($clean['flexible_fee_note'] ?? 'Custom amount'));
        if ($pref === '')      return '';

        // Reuse the same labels the dashboard shows, so the two never drift.
        return str_replace('_', ' ', ucfirst($pref));
    }

    private static function summer_fee_label(array $clean): string
    {
        $fee    = (string) ($clean['admission_fee'] ?? '');
        $amount = (float) ($clean['payment_amount'] ?? 0);
        $method = (string) ($clean['payment_method'] ?? '');

        if ($fee === 'complimentary') return 'Complimentary';

        if ($amount > 0) {
            $label = '$' . number_format($amount, 0);
            return $method !== '' ? $label . ' via ' . ucfirst($method) : $label;
        }

        if ($fee === 'flexible') {
            $note = trim((string) ($clean['flexible_fee_note'] ?? ''));
            return $note !== '' ? $note : 'Flexible / requesting free';
        }

        return $fee;
    }

    // ════════════════════════════════════════════════════
    // 4. DAILY SUMMARY
    // ════════════════════════════════════════════════════

    public static function send_daily_summary(): void
    {
        if (!IQU_Telegram::is_configured()) {
            return;
        }

        global $wpdb;

        // ── Donations in the last 24 hours ──────────────
        $zeffy = IQU_Zeffy_DB::table();
        $since = time() - DAY_IN_SECONDS;

        $donations = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(amount - refunded_amount), 0) AS net_cents,
                    COUNT(DISTINCT NULLIF(buyer_email, '')) AS donors
             FROM `{$zeffy}`
             WHERE status = 'succeeded' AND created_ts >= %d",
            $since
        ), ARRAY_A) ?: [];

        // ── Registrations in the last 24 hours ──────────
        $reg_table = $wpdb->prefix . IQU_TABLE_NAME;
        $cutoff    = gmdate('Y-m-d H:i:s', $since);

        $registrations = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$reg_table}` WHERE created_at >= %s",
            $cutoff
        ));

        $pending = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM `{$reg_table}` WHERE status = 'pending'"
        );

        // ── Month to date, for context ──────────────────
        $month_start = (int) strtotime(gmdate('Y-m-01 00:00:00') . ' UTC');
        $month_cents = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount - refunded_amount), 0) FROM `{$zeffy}`
             WHERE status = 'succeeded' AND created_ts >= %d",
            $month_start
        ));

        $count = (int) ($donations['cnt'] ?? 0);

        $rows = [
            'Donations'     => $count . ' (' . IQU_Telegram::esc(
                IQU_Zeffy_DB::money((int) ($donations['net_cents'] ?? 0))
            ) . ')',
            'Donors'        => (string) (int) ($donations['donors'] ?? 0),
            'Registrations' => (string) $registrations,
            'Month to date' => IQU_Telegram::esc(IQU_Zeffy_DB::money($month_cents)),
        ];

        // Only nag about pending records when there are some.
        if ($pending > 0) {
            $rows['Awaiting review'] = $pending . ' pending registration' . ($pending === 1 ? '' : 's');
        }

        $footer = ($count === 0 && $registrations === 0)
            ? 'A quiet day — nothing came in.'
            : 'Covers the last 24 hours.';

        IQU_Telegram::send(IQU_Telegram::compose(
            '📊 Daily Summary — ' . IQU_Telegram::now(),
            $rows,
            $footer
        ));
    }

    // ════════════════════════════════════════════════════
    // TEST BUTTON
    // ════════════════════════════════════════════════════

    public function ajax_test(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.'], 403);
        }
        if (!check_ajax_referer('iqu_zeffy_sync', '_wpnonce', false)) {
            wp_send_json_error(['message' => 'Security check failed. Reload the page.'], 400);
        }

        $result = IQU_Telegram::send(IQU_Telegram::compose(
            '✅ Test Message',
            [
                'Site'  => IQU_Telegram::esc(get_bloginfo('name')),
                'Sent'  => IQU_Telegram::esc(IQU_Telegram::now()),
                'By'    => IQU_Telegram::esc(wp_get_current_user()->display_name),
            ],
            'If you can read this, Telegram notifications are working.'
        ), true);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()], 400);
        }

        wp_send_json_success(['message' => 'Test message sent — check your Telegram group.']);
    }
}
