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

        $response = wp_remote_post(self::VERIFY_URL, [
            'timeout' => 10,
            'body'    => [
                'secret'   => IQU_RECAPTCHA_SECRET_KEY,
                'response' => $token,
                'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
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

        if ($score < IQU_RECAPTCHA_THRESHOLD) {
            return ['success' => false, 'score' => $score, 'action' => $got_act, 'error' => 'low_score'];
        }

        return ['success' => true, 'score' => $score, 'action' => $got_act];
    }

    /**
     * Enqueue the reCAPTCHA v3 JS (site-key loader).
     */
    public static function enqueue_script(): void
    {
        wp_enqueue_script(
            'iqu-recaptcha',
            'https://www.google.com/recaptcha/api.js?render=' . IQU_RECAPTCHA_SITE_KEY,
            [],
            null,
            true
        );
    }
}