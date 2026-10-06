<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Stripe
 *
 * Minimal server-side client for the Stripe API (no SDK).
 *
 * Security:
 * - The secret key is read ONLY from the IQU_STRIPE_SECRET_KEY constant
 *   (wp-config.php). It is never stored in the database and never sent to
 *   the browser. masked_key() is the only display form.
 * - Mode lock: every request is refused unless the key's mode (test/live)
 *   matches the expected mode. A live key cannot run while the site is in
 *   test mode, and vice versa.
 * - POST requests accept an idempotency key so a double click or a retry
 *   never creates the same object twice.
 * - Errors are returned to the caller; the key and request bodies are never
 *   written to logs.
 */
class IQU_Stripe
{
    private const API_BASE    = 'https://api.stripe.com/v1';
    private const API_VERSION = '2025-09-30.clover'; // flexible billing mode
    private const TIMEOUT     = 30;

    public const OPT_MODE = 'iqu_billing_mode'; // 'test' | 'live'

    // ------------------------------------------------------------
    // Credentials
    // ------------------------------------------------------------

    public static function get_secret_key(): string
    {
        if (defined('IQU_STRIPE_SECRET_KEY') && is_string(IQU_STRIPE_SECRET_KEY)) {
            return trim(IQU_STRIPE_SECRET_KEY);
        }
        return '';
    }

    public static function has_key(): bool
    {
        return self::get_secret_key() !== '';
    }

    /** Mode of the configured key: 'test', 'live' or 'invalid'. */
    public static function key_mode(): string
    {
        $key = self::get_secret_key();
        if (preg_match('/^(sk|rk)_test_[A-Za-z0-9]+$/', $key)) return 'test';
        if (preg_match('/^(sk|rk)_live_[A-Za-z0-9]+$/', $key)) return 'live';
        return 'invalid';
    }

    /** Restricted keys (rk_) are preferred for live use. */
    public static function key_is_restricted(): bool
    {
        return strpos(self::get_secret_key(), 'rk_') === 0;
    }

    /** Mode the site is meant to run in. Defaults to test. */
    public static function expected_mode(): string
    {
        return get_option(self::OPT_MODE, 'test') === 'live' ? 'live' : 'test';
    }

    /** True only when a valid key exists and its mode matches the site mode. */
    public static function is_ready(): bool
    {
        return self::has_key() && self::key_mode() === self::expected_mode();
    }

    /** Safe-to-display fingerprint, e.g. "sk_test_••••••••a1B2". */
    public static function masked_key(): string
    {
        $key = self::get_secret_key();
        if ($key === '') return '';
        $prefix = preg_match('/^(sk|rk)_(test|live)_/', $key, $m) ? $m[0] : '';
        return $prefix . str_repeat('•', 8) . substr($key, -4);
    }

    /** Why the client refuses to run, or '' when ready. Plain English, no secrets. */
    public static function not_ready_reason(): string
    {
        if (!self::has_key()) {
            return 'No Stripe key found. Add IQU_STRIPE_SECRET_KEY to wp-config.php.';
        }
        $mode = self::key_mode();
        if ($mode === 'invalid') {
            return 'The Stripe key in wp-config.php is not a valid secret or restricted key.';
        }
        if ($mode !== self::expected_mode()) {
            return sprintf(
                'Mode mismatch: the site is in %s mode but the key is a %s key. Nothing will run until they match.',
                self::expected_mode(),
                $mode
            );
        }
        return '';
    }

    // ------------------------------------------------------------
    // Core request
    // ------------------------------------------------------------

    /**
     * @param string      $method          GET | POST | DELETE
     * @param string      $path            e.g. '/customers'
     * @param array       $params          Nested arrays are encoded the Stripe way.
     * @param string|null $idempotency_key Use for every POST that creates something.
     * @return array{ok:bool,status:int,data:array,error:string,code:string}
     */
    public static function request(string $method, string $path, array $params = [], ?string $idempotency_key = null): array
    {
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) {
            return self::fail(0, 'Unsupported HTTP method.', 'iqu_bad_method');
        }
        if (!self::is_ready()) {
            return self::fail(0, self::not_ready_reason(), 'iqu_not_ready');
        }
        if (!preg_match('#^/[A-Za-z0-9_/\-]+$#', $path)) {
            return self::fail(0, 'Invalid API path.', 'iqu_bad_path');
        }

        $params = self::stringify_booleans($params);
        $url    = self::API_BASE . $path;
        $body   = null;
        if ($method === 'GET' || $method === 'DELETE') {
            if ($params) $url .= '?' . http_build_query($params, '', '&');
        } else {
            $body = http_build_query($params, '', '&');
        }

        $headers = [
            'Authorization'  => 'Bearer ' . self::get_secret_key(),
            'Stripe-Version' => self::API_VERSION,
            'Content-Type'   => 'application/x-www-form-urlencoded',
        ];
        if ($method === 'POST' && $idempotency_key) {
            $headers['Idempotency-Key'] = substr(preg_replace('/[^A-Za-z0-9_\-:]/', '', $idempotency_key), 0, 255);
        }

        $args = [
            'method'      => $method,
            'headers'     => $headers,
            'timeout'     => self::TIMEOUT,
            'redirection' => 0,
            'sslverify'   => true,
        ];
        if ($body !== null) $args['body'] = $body;

        $response = wp_remote_request($url, $args);

        // One retry on network failure or 5xx, only when it is safe to repeat.
        $can_retry = ($method === 'GET') || ($method === 'POST' && $idempotency_key);
        if ($can_retry && (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) >= 500)) {
            $response = wp_remote_request($url, $args);
        }

        if (is_wp_error($response)) {
            return self::fail(0, 'Could not reach Stripe: ' . $response->get_error_message(), 'iqu_network');
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $data   = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            return self::fail($status, 'Stripe returned an unreadable response.', 'iqu_bad_json');
        }

        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'status' => $status, 'data' => $data, 'error' => '', 'code' => ''];
        }

        $err = $data['error'] ?? [];
        return self::fail(
            $status,
            (string) ($err['message'] ?? 'Stripe request failed.'),
            (string) ($err['code'] ?? $err['type'] ?? 'stripe_error')
        );
    }

    public static function get(string $path, array $params = []): array
    {
        return self::request('GET', $path, $params);
    }

    public static function post(string $path, array $params = [], ?string $idempotency_key = null): array
    {
        return self::request('POST', $path, $params, $idempotency_key);
    }

    /** Lightweight connection check that also works with a restricted key. */
    public static function ping(): array
    {
        return self::get('/customers', ['limit' => 1]);
    }

    /** Stripe expects "true"/"false"; http_build_query would send 1/0. */
    private static function stringify_booleans(array $params): array
    {
        foreach ($params as $k => $v) {
            if (is_bool($v)) {
                $params[$k] = $v ? 'true' : 'false';
            } elseif (is_array($v)) {
                $params[$k] = self::stringify_booleans($v);
            }
        }
        return $params;
    }

    private static function fail(int $status, string $message, string $code): array
    {
        return ['ok' => false, 'status' => $status, 'data' => [], 'error' => $message, 'code' => $code];
    }
}
