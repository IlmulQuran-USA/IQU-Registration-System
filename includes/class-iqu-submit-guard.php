<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Submit_Guard
 *
 * Shared by the three public forms (Enroll for Free, Summer, Weekend).
 *
 * - The same submission twice (a retry after a network timeout, a second tap, a resubmit after
 *   closing the Stripe tab) is answered with the first answer instead of saving a second
 *   registration and sending every email, Telegram and Meta event again. The page sends one
 *   random iqu_submit_id per enrollment (form.js); the first successful answer is kept for
 *   30 minutes. While the first request is still running, a repeat gets "already being saved".
 * - Rate limits: at most IP_MAX accepted enrollments an hour from one address (the trusted
 *   client IP, IQU_Geo::client_ip) and EMAIL_MAX for one email — enough for a big family,
 *   too few for a flood that fills the database and sends confirmation emails to strangers.
 *
 * Keys are HMACs of the id / address / email (no personal data in option names).
 */
final class IQU_Submit_Guard
{
    public const IP_MAX     = 15;
    public const EMAIL_MAX  = 8;
    private const WINDOW    = HOUR_IN_SECONDS;
    private const REPLAY    = 30 * MINUTE_IN_SECONDS;
    private const LOCK_SECS = 120;

    /** The submission id of this request, once begin() has claimed it. */
    private static string $claimed = '';

    private static function hash(string $kind, string $value): string
    {
        return substr(hash_hmac('sha256', $kind . '|' . $value, wp_salt('nonce')), 0, 32);
    }

    /** The page's id for this enrollment (form.js), or '' (an older cached page without it). */
    public static function submit_id(): string
    {
        $id = isset($_POST['iqu_submit_id']) && is_string($_POST['iqu_submit_id']) ? $_POST['iqu_submit_id'] : '';
        return preg_match('/^[A-Za-z0-9-]{16,64}$/', $id) ? $id : '';
    }

    /**
     * Call right after the nonce check. A repeat of a submission that already succeeded gets its
     * first answer (and the request ends here); a repeat while the first is still running gets
     * "already being saved". Otherwise the id is claimed for this request.
     */
    public static function begin(): void
    {
        $id = self::submit_id();
        if ($id === '') return;
        $h = self::hash('submit', $id);

        $done = get_transient('iqu_sub_done_' . $h);
        if (is_array($done)) {
            wp_send_json_success($done + ['repeat' => true]);
        }
        $lock = 'iqu_sub_lock_' . $h;
        if (!add_option($lock, time(), '', 'no')) {                    // atomic: one INSERT wins
            if ((int) get_option($lock) > time() - self::LOCK_SECS) {
                wp_send_json_error(['message' => 'Your enrollment is already being saved. Please wait a moment.', 'code' => 'in_progress']);
            }
            update_option($lock, time(), 'no');                         // a request that died long ago
        }
        self::$claimed = $h;
        add_action('shutdown', [__CLASS__, 'release']);
    }

    /** Frees the claim at the end of the request (also after a validation error, so the family can fix and resend). */
    public static function release(): void
    {
        if (self::$claimed !== '') delete_option('iqu_sub_lock_' . self::$claimed);
        self::$claimed = '';
    }

    /**
     * Before the registration is saved: the hourly limits. Sends an error (and ends the request)
     * when this address or this email already sent the maximum this hour.
     */
    public static function check_rate(string $email): void
    {
        $ip = class_exists('IQU_Geo') ? IQU_Geo::client_ip() : '';
        if ((int) get_transient('iqu_sub_ip_' . self::hash('ip', $ip)) >= self::IP_MAX
            || ($email !== '' && (int) get_transient('iqu_sub_em_' . self::hash('email', strtolower($email))) >= self::EMAIL_MAX)) {
            wp_send_json_error([
                'message' => 'We have already received several enrollments from you in the last hour. Please wait a little, or contact us and we will help.',
                'code'    => 'rate_limited',
            ]);
        }
    }

    /** After a successful enrollment: count it and keep the answer for a repeat of the same submission. */
    public static function finish(string $email, array $response): void
    {
        $ip = class_exists('IQU_Geo') ? IQU_Geo::client_ip() : '';
        foreach (['iqu_sub_ip_' . self::hash('ip', $ip), $email !== '' ? 'iqu_sub_em_' . self::hash('email', strtolower($email)) : ''] as $key) {
            if ($key === '') continue;
            set_transient($key, (int) get_transient($key) + 1, self::WINDOW);
        }
        if (self::$claimed !== '') set_transient('iqu_sub_done_' . self::$claimed, $response, self::REPLAY);
    }

    /**
     * A fresh form nonce for a page that was served from a page cache longer ago than the nonce
     * lives (form.js asks once when a submit comes back "invalid_nonce"). Anyone could read the
     * same nonce from the public form page, so this gives nothing away.
     */
    public static function ajax_fresh_nonce(): void
    {
        nocache_headers();
        wp_send_json_success([
            'nonce'          => wp_create_nonce('iqu_registration_nonce'),
            'waitlist_nonce' => wp_create_nonce('iqu_waitlist'),
        ]);
    }
}
