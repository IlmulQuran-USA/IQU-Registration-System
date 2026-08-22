<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Zeffy_Sync
 *
 * Keeps the local `wp_iqu_zeffy_payments` mirror in step with Zeffy.
 *
 * Why both a backfill AND a recurring reconcile:
 *   • The webhook only fires `payment.completed`. There is NO event for
 *     refunds, disputes or contact edits, so webhook-only data goes stale.
 *   • The /payments endpoint exposes a `created` filter but no `updated`
 *     filter, so the only way to catch a refund on an older payment is to
 *     re-poll the whole created-window it sits in.
 *
 * Strategy:
 *   1. Backfill once — walk all history, cursor by cursor.
 *   2. Reconcile twice daily — re-poll the last N days (default 30) and
 *      upsert everything, which refreshes refund_status / dispute / amounts.
 */
class IQU_Zeffy_Sync
{
    public const CRON_HOOK        = 'iqu_zeffy_sync_event';
    public const OPT_LAST_SYNC    = 'iqu_zeffy_last_sync';
    public const OPT_LAST_RESULT  = 'iqu_zeffy_last_result';
    public const OPT_BACKFILL_DONE = 'iqu_zeffy_backfill_done';
    public const OPT_WINDOW_DAYS  = 'iqu_zeffy_window_days';

    /** Reconcile window in days — long enough to catch late refunds. */
    public const DEFAULT_WINDOW_DAYS = 30;

    public function __construct()
    {
        add_action(self::CRON_HOOK, [__CLASS__, 'run_scheduled_sync']);

        // Admin-triggered actions.
        add_action('wp_ajax_iqu_zeffy_sync_now', [$this, 'ajax_sync_now']);
        add_action('wp_ajax_iqu_zeffy_backfill', [$this, 'ajax_backfill']);
        add_action('wp_ajax_iqu_zeffy_test_connection', [$this, 'ajax_test_connection']);
    }

    // ════════════════════════════════════════════════════
    // SCHEDULING
    // ════════════════════════════════════════════════════

    public static function schedule(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 300, 'twicedaily', self::CRON_HOOK);
        }
    }

    public static function unschedule(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public static function window_days(): int
    {
        $days = (int) get_option(self::OPT_WINDOW_DAYS, self::DEFAULT_WINDOW_DAYS);
        return ($days >= 1 && $days <= 365) ? $days : self::DEFAULT_WINDOW_DAYS;
    }

    // ════════════════════════════════════════════════════
    // SYNC RUNNERS
    // ════════════════════════════════════════════════════

    /**
     * Cron entry point. Runs the backfill first if it has never completed,
     * otherwise the rolling reconcile.
     */
    public static function run_scheduled_sync(): void
    {
        if (!IQU_Zeffy_API::has_key()) {
            self::record_result([
                'ok'      => false,
                'mode'    => 'scheduled',
                'message' => 'No API key configured.',
            ]);
            return;
        }

        if (!get_option(self::OPT_BACKFILL_DONE)) {
            self::backfill();
            return;
        }

        self::reconcile();
    }

    /**
     * Re-poll the last N days and upsert everything found.
     *
     * @return array Result summary.
     */
    public static function reconcile(?int $days = null): array
    {
        $days  = $days ?? self::window_days();
        $since = time() - ($days * DAY_IN_SECONDS);

        $totals = ['inserted' => 0, 'updated' => 0, 'failed' => 0];

        $walk = IQU_Zeffy_API::walk_payments(
            ['created[gte]' => $since],
            static function (array $batch) use (&$totals) {
                $counts = IQU_Zeffy_DB::upsert_many($batch, IQU_Zeffy_DB::SOURCE_API);
                foreach ($counts as $key => $value) {
                    $totals[$key] += $value;
                }
            }
        );

        if (is_wp_error($walk)) {
            return self::record_result([
                'ok'      => false,
                'mode'    => 'reconcile',
                'message' => $walk->get_error_message(),
                'totals'  => $totals,
                'days'    => $days,
            ]);
        }
        IQU_Notifier::check_refunds_and_disputes();

        return self::record_result([
            'ok'      => true,
            'mode'    => 'reconcile',
            'message' => sprintf(
                '%d payments checked over the last %d days — %d new, %d updated.',
                $walk['payments'],
                $days,
                $totals['inserted'],
                $totals['updated']
            ),
            'totals'  => $totals,
            'pages'   => $walk['pages'],
            'days'    => $days,
        ]);
    }

    /**
     * Walk the entire payment history once.
     */
    public static function backfill(): array
    {
        $totals = ['inserted' => 0, 'updated' => 0, 'failed' => 0];

        $walk = IQU_Zeffy_API::walk_payments(
            [],
            static function (array $batch) use (&$totals) {
                $counts = IQU_Zeffy_DB::upsert_many($batch, IQU_Zeffy_DB::SOURCE_API);
                foreach ($counts as $key => $value) {
                    $totals[$key] += $value;
                }
            }
        );

        if (is_wp_error($walk)) {
            return self::record_result([
                'ok'      => false,
                'mode'    => 'backfill',
                'message' => $walk->get_error_message(),
                'totals'  => $totals,
            ]);
        }

        update_option(self::OPT_BACKFILL_DONE, time(), false);

        return self::record_result([
            'ok'      => true,
            'mode'    => 'backfill',
            'message' => sprintf(
                'Full history imported — %d payments across %d pages (%d new, %d updated).',
                $walk['payments'],
                $walk['pages'],
                $totals['inserted'],
                $totals['updated']
            ),
            'totals'  => $totals,
            'pages'   => $walk['pages'],
        ]);
    }

    // ════════════════════════════════════════════════════
    // RESULT LOG
    // ════════════════════════════════════════════════════

    private static function record_result(array $result): array
    {
        $result['time'] = time();

        update_option(self::OPT_LAST_RESULT, $result, false);

        if (!empty($result['ok'])) {
            update_option(self::OPT_LAST_SYNC, time(), false);
        }

        return $result;
    }

    public static function last_sync_ts(): int
    {
        return (int) get_option(self::OPT_LAST_SYNC, 0);
    }

    public static function last_result(): array
    {
        $result = get_option(self::OPT_LAST_RESULT, []);
        return is_array($result) ? $result : [];
    }

    public static function backfill_done(): bool
    {
        return (bool) get_option(self::OPT_BACKFILL_DONE, false);
    }

    // ════════════════════════════════════════════════════
    // AJAX HANDLERS
    // ════════════════════════════════════════════════════

    private function guard(string $nonce_action): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied.'], 403);
        }
        if (!check_ajax_referer($nonce_action, '_wpnonce', false)) {
            wp_send_json_error(['message' => 'Security check failed. Reload the page and try again.'], 400);
        }
        if (!IQU_Zeffy_API::has_key()) {
            wp_send_json_error(['message' => 'No Zeffy API key is configured yet.'], 400);
        }
    }

    public function ajax_sync_now(): void
    {
        $this->guard('iqu_zeffy_sync');

        @set_time_limit(300);
        $result = self::reconcile();

        if (empty($result['ok'])) {
            wp_send_json_error($result, 500);
        }
        wp_send_json_success($result);
    }

    public function ajax_backfill(): void
    {
        $this->guard('iqu_zeffy_sync');

        @set_time_limit(600);
        $result = self::backfill();

        if (empty($result['ok'])) {
            wp_send_json_error($result, 500);
        }
        wp_send_json_success($result);
    }

    public function ajax_test_connection(): void
    {
        $this->guard('iqu_zeffy_sync');

        $test = IQU_Zeffy_API::test_connection();

        if (is_wp_error($test)) {
            wp_send_json_error(['message' => $test->get_error_message()], 400);
        }

        wp_send_json_success([
            'message' => 'Connected to Zeffy successfully.',
        ]);
    }
}