<?php
if (! defined('ABSPATH')) exit;

/**
 * Class IQU_Recaptcha
 *
 * Google reCAPTCHA v3 — server-side token verification.
 * Returns true when score >= IQU_RECAPTCHA_THRESHOLD AND action matches.
 */
class IQU_Recaptcha
{

    private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    /**
     * Verify a v3 token.
     *
     * @param string $token  The g-recaptcha-response token from the client.
     * @param string $action The action name the client submitted (e.g. 'iqu_free_form').
     * @return array{success:bool, score:float, action:string, error?:string}
     */
    public static function verify(string $token, string $action = ''): array
    {
        if (empty($token)) {
            return ['success' => false, 'score' => 0.0, 'action' => '', 'error' => 'missing_token'];
        }
        // A missing key in wp-config was a fatal error on every submit; now it is a normal "failed" answer.
        if (!defined('IQU_RECAPTCHA_SECRET_KEY') || (string) IQU_RECAPTCHA_SECRET_KEY === '') {
            error_log('[IQU] reCAPTCHA: IQU_RECAPTCHA_SECRET_KEY is not set in wp-config.php.');
            return ['success' => false, 'score' => 0.0, 'action' => '', 'error' => 'not_configured'];
        }

        $response = wp_remote_post(self::VERIFY_URL, [
            'timeout' => 10,
            'body'    => [
                'secret'   => IQU_RECAPTCHA_SECRET_KEY,
                'response' => $token,
                'remoteip' => class_exists('IQU_Geo') ? IQU_Geo::client_ip() : ($_SERVER['REMOTE_ADDR'] ?? ''),
            ],
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'score' => 0.0, 'action' => '', 'error' => 'http_error'];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (! is_array($body)) {
            return ['success' => false, 'score' => 0.0, 'action' => '', 'error' => 'bad_body'];
        }

        $score   = isset($body['score'])  ? (float)  $body['score']  : 0.0;
        $ok      = ! empty($body['success']);
        $got_act = isset($body['action']) ? (string) $body['action'] : '';

        if (! $ok) {
            return ['success' => false, 'score' => $score, 'action' => $got_act, 'error' => 'verify_failed'];
        }

        if ($action !== '' && $got_act !== $action) {
            return ['success' => false, 'score' => $score, 'action' => $got_act, 'error' => 'action_mismatch'];
        }

        // The token must come from a page on this site (matters when "Verify the origin of reCAPTCHA
        // solutions" is off in the Google console). www. and the bare domain count as the same site.
        if (isset($body['hostname']) && !self::same_host((string) $body['hostname'])) {
            return ['success' => false, 'score' => $score, 'action' => $got_act, 'error' => 'hostname_mismatch'];
        }

        $threshold = defined('IQU_RECAPTCHA_THRESHOLD') ? (float) IQU_RECAPTCHA_THRESHOLD : 0.5;
        if ($score < $threshold) {
            return ['success' => false, 'score' => $score, 'action' => $got_act, 'error' => 'low_score'];
        }

        return ['success' => true, 'score' => $score, 'action' => $got_act];
    }

    /**
     * Enqueue the reCAPTCHA v3 JS (site-key loader).
     */
    private static function same_host(string $host): bool
    {
        $bare = fn(string $h) => preg_replace('/^www\./', '', strtolower(trim($h)));
        return $bare($host) === $bare((string) wp_parse_url(home_url(), PHP_URL_HOST));
    }

    public static function enqueue_script(): void
    {
        if (!defined('IQU_RECAPTCHA_SITE_KEY') || (string) IQU_RECAPTCHA_SITE_KEY === '') return; // not configured: no broken script tag
        wp_enqueue_script(
            'iqu-recaptcha',
            'https://www.google.com/recaptcha/api.js?render=' . IQU_RECAPTCHA_SITE_KEY,
            [],
            null,
            true
        );
    }
}