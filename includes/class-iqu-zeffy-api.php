<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Zeffy_API
 *
 * Thin read-only client for the Zeffy Public API (Beta).
 * Base: https://api.zeffy.com/api/v1
 *
 * 🔒 Security:
 * - API key is NEVER hardcoded. Resolution order:
 *     1. IQU_ZEFFY_API_KEY constant (define it in wp-config.php — preferred)
 *     2. `iqu_zeffy_api_key` option (set from the admin screen)
 * - The key is never printed back to the browser in full; see masked_key().
 *
 * ⚠️  The API is read-only. All six endpoints are GET.
 *     Rate limit: 100 requests / minute / key.
 */
class IQU_Zeffy_API
{
    private const BASE          = 'https://api.zeffy.com/api/v1';
    private const TIMEOUT       = 20;
    private const MAX_PAGES     = 200;   // hard stop: 200 × 100 = 20,000 payments
    private const PAGE_SIZE     = 100;   // Zeffy max
    private const THROTTLE_USEC = 120000; // ~8 req/sec — well under 100/min

    public const OPT_API_KEY = 'iqu_zeffy_api_key';

    // ════════════════════════════════════════════════════
    // CREDENTIALS
    // ════════════════════════════════════════════════════

    public static function get_api_key(): string
    {
        if (defined('IQU_ZEFFY_API_KEY') && IQU_ZEFFY_API_KEY) {
            return (string) IQU_ZEFFY_API_KEY;
        }
        return (string) get_option(self::OPT_API_KEY, '');
    }

    public static function has_key(): bool
    {
        return self::get_api_key() !== '';
    }

    /**
     * True when the key comes from wp-config.php and must not be edited in the UI.
     */
    public static function key_is_locked(): bool
    {
        return defined('IQU_ZEFFY_API_KEY') && IQU_ZEFFY_API_KEY;
    }

    /**
     * Safe-to-display fingerprint, e.g. "zef_••••••••3f9c".
     */
    public static function masked_key(): string
    {
        $key = self::get_api_key();
        if ($key === '') return '';

        $len = strlen($key);
        if ($len <= 8) return str_repeat('•', $len);

        return substr($key, 0, 4) . str_repeat('•', 8) . substr($key, -4);
    }

    // ════════════════════════════════════════════════════
    // CORE REQUEST
    // ════════════════════════════════════════════════════

    /**
     * Perform a GET request against the Zeffy API.
     *
     * @return array|WP_Error Decoded JSON body on success.
     */
    public static function get(string $path, array $query = [])
    {
        $key = self::get_api_key();
        if ($key === '') {
            return new WP_Error(
                'iqu_zeffy_no_key',
                __('No Zeffy API key is configured.', 'iqu-registration')
            );
        }

        $url = self::BASE . '/' . ltrim($path, '/');
        if (!empty($query)) {
            $url = add_query_arg(array_map('rawurlencode', array_map('strval', $query)), $url);
        }

        $response = wp_remote_get($url, [
            'timeout' => self::TIMEOUT,
            'headers' => [
                'Authorization' => 'Bearer ' . $key,
                'Accept'        => 'application/json',
            ],
            'user-agent' => 'IQU-Registration/' . IQU_VERSION . '; ' . home_url('/'),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code === 401) {
            return new WP_Error('iqu_zeffy_auth', __('Zeffy rejected the API key (401). Generate a new key and save it again.', 'iqu-registration'));
        }
        if ($code === 429) {
            return new WP_Error('iqu_zeffy_rate_limit', __('Zeffy rate limit reached (429). Try again in a minute.', 'iqu-registration'));
        }
        if ($code === 404) {
            return new WP_Error('iqu_zeffy_not_found', __('Resource not found (404).', 'iqu-registration'));
        }

        $data = json_decode($body, true);

        if ($code < 200 || $code >= 300) {
            // Zeffy returns { error: { type, code, message, ... } }
            $message = $data['error']['message'] ?? sprintf(__('Zeffy API returned HTTP %d.', 'iqu-registration'), $code);
            return new WP_Error('iqu_zeffy_http_' . $code, $message);
        }

        if (!is_array($data)) {
            return new WP_Error('iqu_zeffy_bad_json', __('Zeffy returned a response that could not be parsed.', 'iqu-registration'));
        }

        return $data;
    }

    // ════════════════════════════════════════════════════
    // ENDPOINTS
    // ════════════════════════════════════════════════════

    /**
     * GET /payments — one page.
     *
     * Supported filters: currency, contact, campaign, type, status,
     * created[gte] / created[gt] / created[lte] / created[lt],
     * starting_after, ending_before, limit.
     */
    public static function list_payments(array $params = [])
    {
        $params = wp_parse_args($params, ['limit' => self::PAGE_SIZE]);
        return self::get('payments', $params);
    }

    public static function get_payment(string $id)
    {
        return self::get('payments/' . rawurlencode($id));
    }

    public static function list_campaigns(array $params = [])
    {
        $params = wp_parse_args($params, ['limit' => self::PAGE_SIZE]);
        return self::get('campaigns', $params);
    }

    public static function get_campaign(string $id)
    {
        return self::get('campaigns/' . rawurlencode($id));
    }

    public static function list_contacts(array $params = [])
    {
        $params = wp_parse_args($params, ['limit' => self::PAGE_SIZE]);
        return self::get('contacts', $params);
    }

    public static function get_contact(string $id)
    {
        return self::get('contacts/' . rawurlencode($id));
    }

    // ════════════════════════════════════════════════════
    // PAGINATION WALKER
    // ════════════════════════════════════════════════════

    /**
     * Walk every page of /payments, handing each batch to $callback.
     *
     * Zeffy uses cursor pagination: the response carries `has_more` and
     * `next_cursor`; the cursor is passed back as `starting_after`.
     *
     * @param array    $filters  e.g. ['created[gte]' => 1700000000]
     * @param callable $callback function(array $batch): void
     *
     * @return array|WP_Error ['pages' => n, 'payments' => n]
     */
    public static function walk_payments(array $filters, callable $callback)
    {
        $cursor = '';
        $pages  = 0;
        $seen   = 0;

        do {
            $params = $filters;
            $params['limit'] = self::PAGE_SIZE;
            if ($cursor !== '') {
                $params['starting_after'] = $cursor;
            }

            $response = self::list_payments($params);
            if (is_wp_error($response)) {
                // Return partial progress alongside the error so the caller can log it.
                $response->add_data(['pages' => $pages, 'payments' => $seen]);
                return $response;
            }

            $batch = isset($response['data']) && is_array($response['data']) ? $response['data'] : [];
            if (!empty($batch)) {
                $callback($batch);
                $seen += count($batch);
            }

            $pages++;
            $has_more = !empty($response['has_more']);
            $cursor   = (string) ($response['next_cursor'] ?? '');

            if ($has_more && $cursor !== '' && $pages < self::MAX_PAGES) {
                usleep(self::THROTTLE_USEC);
            }
        } while ($has_more && $cursor !== '' && $pages < self::MAX_PAGES);

        return ['pages' => $pages, 'payments' => $seen];
    }

    // ════════════════════════════════════════════════════
    // CONNECTION TEST
    // ════════════════════════════════════════════════════

    /**
     * Cheap round-trip used by the "Test connection" button.
     *
     * @return array|WP_Error ['ok' => true, 'sample' => n]
     */
    public static function test_connection()
    {
        $response = self::list_payments(['limit' => 1]);
        if (is_wp_error($response)) {
            return $response;
        }

        return [
            'ok'       => true,
            'sample'   => isset($response['data']) ? count($response['data']) : 0,
            'has_more' => !empty($response['has_more']),
        ];
    }
}