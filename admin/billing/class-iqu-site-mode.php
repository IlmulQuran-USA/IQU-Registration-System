<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Site_Mode (3.7.15)
 *
 * The Site mode switch on Billing Settings (option iqu_billing_mode, IQU_Stripe::OPT_MODE). 3.7.17: a toggle switch
 * (Test on the left, Live on the right, showing the current mode) instead of the "Switch to Live" / "Switch to Test"
 * button; moving it opens the same confirmation. Only the admins who may refund (IQU_REFUND_USERS) see and use it;
 * everyone else sees the current mode as text.
 *   - To Live: only with a live restricted key (rk_live_) in wp-config, a webhook signing secret, and a Test connection
 *     with that key that succeeds at the moment of switching; the confirmation lists what changes; the admin types LIVE.
 *   - To Test: the confirmation says how many live families are on billing and that Stripe's live events are ignored
 *     while the site is in test mode; the admin types TEST.
 *   - After a switch: the page caches the site uses are cleared (W3 Total Cache, AirLift) — or the screen says which
 *     one could not be; the switch is logged (who, from, to, when) and one Telegram goes to the team.
 * A double click or a retry never switches twice (a lock, and a switch to the mode the site is already in does nothing).
 */
class IQU_Site_Mode
{
    public const A_SWITCH = 'iqu_billing_site_mode';
    private const NOTICE_TX = 'iqu_billing_notice_'; // shown by the Billing Settings page

    public static function init(): void
    {
        add_action('admin_post_' . self::A_SWITCH, [__CLASS__, 'handle_switch']);
    }

    public static function can_switch(): bool
    {
        return class_exists('IQU_Billing_Refund') && IQU_Billing_Refund::can_refund();
    }

    /** What stops a switch to Live ([] when nothing). $ping: also run the Test connection with the key (at the moment of switching). */
    public static function live_blockers(bool $ping): array
    {
        $out = [];
        if (!IQU_Stripe::has_key() || IQU_Stripe::key_mode() !== 'live' || !IQU_Stripe::key_is_restricted()) {
            $out[] = 'The key in wp-config.php is not a live restricted key (rk_live_…).';
        }
        if (!(defined('IQU_STRIPE_WEBHOOK_SECRET') && is_string(IQU_STRIPE_WEBHOOK_SECRET) && trim(IQU_STRIPE_WEBHOOK_SECRET) !== '')) {
            $out[] = 'The webhook signing secret (IQU_STRIPE_WEBHOOK_SECRET) is not set in wp-config.php.';
        }
        if ($ping && !$out) {
            $r = method_exists('IQU_Stripe', 'test_connection_for') ? IQU_Stripe::test_connection_for('live') : IQU_Stripe::ping();
            if (empty($r['ok'])) $out[] = 'The Test connection with the live key failed' . (!empty($r['error']) ? ': ' . $r['error'] : '.');
        }
        return $out;
    }

    /** Live families on billing (a live subscription that has not ended). */
    public static function live_families(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM " . IQU_Billing_DB::accounts_table() . " WHERE mode = 'live' AND stripe_subscription_id <> '' AND status <> 'canceled'");
    }

    /** The switch and its dialog, in place of the mode text next to "Site mode" ('' for everyone else). Escaped HTML. */
    public static function control(): string
    {
        if (!self::can_switch()) return '';
        $mode = IQU_Stripe::expected_mode();
        $to = $mode === 'test' ? 'live' : 'test';
        $d = 'iqu-site-mode-dialog';
        if ($to === 'live') {
            $blockers = self::live_blockers(false);
            $free = class_exists('IQU_Enrollment_Settings') ? IQU_Enrollment_Settings::free_mode() : 'off';
            $summer = class_exists('IQU_Enrollment_Settings') ? IQU_Enrollment_Settings::summer_mode() : 'zeffy';
            $items = [
                'Real money moves: every charge, refund and payout is real.',
                'Emails go to families (in test mode they only go to our own addresses).',
                'Enroll for Free payment is ' . ($free === 'on' ? 'On: every new family on the website is sent to the card step.' : 'Off: families enroll without adding a card.'),
                'Summer payment is ' . ($summer === 'stripe' ? 'Stripe (card on our website).' : 'Zeffy (as before).'),
            ];
            $word = 'LIVE';
            $title = 'Switch the site to Live?';
        } else {
            $blockers = [];
            $n = self::live_families();
            $items = [
                $n . ' live ' . ($n === 1 ? 'family is' : 'families are') . ' on billing. Stripe keeps charging them.',
                'While the site is in test mode, Stripe\'s live events are ignored: payments are not recorded on the site until it is switched back to Live.',
            ];
            $word = 'TEST';
            $title = 'Switch the site to Test?';
        }
        // 3.7.17: role="switch", checked = Live. A <button>, so Space / Enter move it; admin.js opens the dialog and
        // billing.js moves it back to the current mode when the dialog is closed without switching.
        $h = '<button type="button" class="iqu-mode-switch" role="switch" aria-checked="' . ($mode === 'live' ? 'true' : 'false') . '" aria-label="Live mode"'
            . ' data-iqu-site-mode-switch data-iqu-dialog="' . $d . '" data-iqu-site-mode="' . $to . '">'
            . '<span class="iqu-mode-switch-lbl" data-side="test">Test</span>'
            . '<span class="iqu-mode-switch-track" aria-hidden="true"><span class="iqu-mode-switch-knob"></span></span>'
            . '<span class="iqu-mode-switch-lbl" data-side="live">Live</span></button>'
            . '<dialog class="iqu-modal" id="' . $d . '" aria-labelledby="' . $d . '-t">'
            . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="iqu-modal-form" data-iqu-site-mode-form>'
            . '<h2 class="iqu-modal-title" id="' . $d . '-t">' . esc_html($title) . '</h2>';
        if ($blockers) {
            $h .= '<div class="notice notice-error inline" data-iqu-site-mode-blocked><p><strong>Not possible yet:</strong></p><ul>';
            foreach ($blockers as $b) $h .= '<li>' . esc_html($b) . '</li>';
            $h .= '</ul></div><div class="iqu-modal-actions"><button type="submit" formmethod="dialog" formnovalidate value="cancel" class="iqu-btn iqu-btn--secondary">Close</button></div></form></dialog>';
            return $h;
        }
        $h .= '<p class="iqu-modal-text">What changes:</p><ul class="iqu-modal-list">';
        foreach ($items as $i) $h .= '<li>' . esc_html($i) . '</li>';
        $h .= '</ul>'
            . '<input type="hidden" name="action" value="' . esc_attr(self::A_SWITCH) . '"><input type="hidden" name="to" value="' . esc_attr($to) . '">'
            . '<input type="hidden" name="op" value="' . esc_attr(str_replace('-', '', wp_generate_uuid4())) . '">'
            . wp_nonce_field(self::A_SWITCH, '_wpnonce', true, false)
            . '<div class="iqu-fld"><label for="' . $d . '-w">Type <strong>' . $word . '</strong> to confirm <em>*</em></label><input type="text" id="' . $d . '-w" name="confirm" required autocomplete="off" pattern="' . $word . '"></div>'
            . '<div class="iqu-modal-actions"><button type="submit" class="iqu-btn iqu-btn--danger">' . ($to === 'live' ? 'Switch to Live' : 'Switch to Test') . '</button>'
            . '<button type="submit" formmethod="dialog" formnovalidate value="cancel" class="iqu-btn iqu-btn--secondary">Keep ' . esc_html(ucfirst($mode)) . '</button></div>'
            . '</form></dialog>';
        return $h;
    }

    /**
     * Switch the site mode. @return array{ok:bool, message:string, caches?:string[]}
     */
    public static function switch_to(string $to, string $typed, string $op = ''): array
    {
        $to = $to === 'live' ? 'live' : ($to === 'test' ? 'test' : '');
        if ($to === '') return ['ok' => false, 'message' => 'Choose Live or Test.'];
        $from = IQU_Stripe::expected_mode();
        if ($from === $to) return ['ok' => true, 'message' => 'The site is already in ' . $to . ' mode. Nothing was changed.'];
        $word = strtoupper($to);
        if (trim($typed) !== $word) return ['ok' => false, 'message' => 'Type ' . $word . ' to confirm. Nothing was changed.'];
        $lock = 'iqu_site_mode_lock';
        if (!add_option($lock, time(), '', 'no')) {
            if ((int) get_option($lock) > time() - 60) return ['ok' => false, 'message' => 'The site mode is being switched right now. Please wait a moment.'];
            update_option($lock, time(), 'no');
        }
        try {
            if (IQU_Stripe::expected_mode() === $to) return ['ok' => true, 'message' => 'The site is already in ' . $to . ' mode. Nothing was changed.'];
            if ($to === 'live') {
                $b = self::live_blockers(true);
                if ($b) return ['ok' => false, 'message' => 'Not switched to Live: ' . implode(' ', $b)];
            }
            update_option(IQU_Stripe::OPT_MODE, $to, false);
            $user = wp_get_current_user();
            $who = (string) ($user->user_login ?? '');
            if (class_exists('IQU_Billing_DB')) IQU_Billing_DB::log_action(['action' => 'site_mode', 'reason' => substr($from . ' → ' . $to . ($op !== '' ? ' · op ' . substr($op, 0, 32) : ''), 0, 255)]);
            update_option('iqu_site_mode_changed', ['from' => $from, 'to' => $to, 'user' => get_current_user_id(), 'login' => $who, 'at' => time()], false);
            if (class_exists('IQU_Billing_Notify')) IQU_Billing_Notify::site_mode_switched($from, $to, (string) ($user->display_name ?? $who));
            $caches = self::clear_caches();
            return ['ok' => true, 'message' => 'The site is now in ' . $to . ' mode. ' . implode(' ', $caches), 'caches' => $caches];
        } finally {
            delete_option($lock);
        }
    }

    /** Clear the page caches the site uses, so the public forms follow the new mode. @return string[] one line per cache */
    public static function clear_caches(): array
    {
        $out = [];
        if (function_exists('w3tc_flush_all')) {
            try { w3tc_flush_all(); $out[] = 'W3 Total Cache cleared.'; } catch (\Throwable $e) { $out[] = 'W3 Total Cache could not be cleared from here — clear it in W3 Total Cache.'; }
        }
        // AirLift: its own "Purge All Cache" (AirLift has no public PHP API; its admin-bar tool, with its global objects)
        global $bvapi, $bvinfo;
        $al = defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR . '/airlift/admin/purge_cache_tools.php' : '';
        if (class_exists('ALWPAPI') || (is_object($bvapi ?? null))) {
            try {
                if (!class_exists('ALPurgeCacheTools') && $al !== '' && is_file($al)) require_once $al;
                if (class_exists('ALPurgeCacheTools') && is_object($bvapi) && is_object($bvinfo)) {
                    $tools = new ALPurgeCacheTools($bvinfo, $bvapi, (defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : '') . '/airlift/purge_cache_admin_bar.php', 65535);
                    $resp = $tools->submitPurgeCache(['purge_all' => true]);
                    $code = is_wp_error($resp) ? 0 : (int) wp_remote_retrieve_response_code($resp);
                    $out[] = ($code >= 200 && $code < 300) ? 'AirLift cache purge started.' : 'AirLift cache could not be cleared from here — use AirLift → Purge All Cache in the admin bar.';
                } else {
                    $out[] = 'AirLift cache could not be cleared from here — use AirLift → Purge All Cache in the admin bar.';
                }
            } catch (\Throwable $e) {
                $out[] = 'AirLift cache could not be cleared from here — use AirLift → Purge All Cache in the admin bar.';
            }
        }
        return $out;
    }

    public static function handle_switch(): void
    {
        if (!current_user_can('manage_options')) wp_die('You do not have permission to do this.', 403);
        check_admin_referer(self::A_SWITCH);
        if (!self::can_switch()) wp_die('Only the administrators who may refund can switch the site mode.', 403);
        $to = sanitize_key((string) ($_POST['to'] ?? ''));
        $typed = sanitize_text_field(wp_unslash((string) ($_POST['confirm'] ?? '')));
        $op = preg_replace('/[^a-f0-9]/', '', (string) ($_POST['op'] ?? ''));
        $r = self::switch_to($to, $typed, $op);
        set_transient(self::NOTICE_TX . get_current_user_id(), ['type' => $r['ok'] ? 'success' : 'error', 'text' => $r['message']], 60);
        wp_safe_redirect(admin_url('admin.php?page=iqu-billing-settings'));
        exit;
    }
}
