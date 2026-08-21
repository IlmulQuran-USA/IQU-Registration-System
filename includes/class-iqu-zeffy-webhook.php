<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Zeffy_Webhook
 *
 * Receives Zeffy's `payment.completed` deliveries in real time.
 *
 * Endpoint:  POST {site}/wp-json/iqu/v1/zeffy-webhook
 *
 * 🔒 Security model — every delivery must pass ALL of these:
 *   1. A `Zeffy-Signature: t=<unix>,v1=<hex>` header is present.
 *   2. v1 === HMAC-SHA256(secret, "{t}.{rawBody}"), hex encoded,
 *      compared with hash_equals() (constant time).
 *   3. `t` is within TOLERANCE seconds of now — blocks replay attacks.
 *
 * ⚠️  The signature MUST be computed over the RAW request bytes.
 *     WordPress hands the REST controller a parsed array; re-encoding it
 *     reorders keys and changes whitespace, which breaks the signature.
 *     That is why we read php://input directly.
 *
 * Idempotency: retries carry the same event `id`, and upsert() is keyed on
 * payment_id, so replaying a delivery never duplicates a row.
 */
class IQU_Zeffy_Webhook
{
    public const REST_NAMESPACE = 'iqu/v1';
    public const ROUTE      = '/zeffy-webhook';
    public const OPT_SECRET = 'iqu_zeffy_webhook_secret';
    public const OPT_LOG    = 'iqu_zeffy_webhook_log';

    /** Reject deliveries signed more than this many seconds ago. */
    private const TOLERANCE = 300;

    /** How many recent deliveries to keep for the admin screen. */
    private const LOG_LIMIT = 20;

    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_route']);
    }

    // ════════════════════════════════════════════════════
    // SECRET
    // ════════════════════════════════════════════════════

    /**
     * Resolution order: wp-config constant, then option.
     * The secret starts with `whsec_` and is found under
     * Zeffy → Settings → Integrations → Webhook.
     */
    public static function get_secret(): string
    {
        if (defined('IQU_ZEFFY_WEBHOOK_SECRET') && IQU_ZEFFY_WEBHOOK_SECRET) {
            return (string) IQU_ZEFFY_WEBHOOK_SECRET;
        }
        return (string) get_option(self::OPT_SECRET, '');
    }

    public static function secret_is_locked(): bool
    {
        return defined('IQU_ZEFFY_WEBHOOK_SECRET') && IQU_ZEFFY_WEBHOOK_SECRET;
    }

    public static function has_secret(): bool
    {
        return self::get_secret() !== '';
    }

    public static function endpoint_url(): string
    {
        return rest_url(self::REST_NAMESPACE . self::ROUTE);
    }

    // ════════════════════════════════════════════════════
    // ROUTE
    // ════════════════════════════════════════════════════

    public function register_route(): void
    {
        register_rest_route(self::REST_NAMESPACE, self::ROUTE, [
            'methods'  => 'POST',
            'callback' => [$this, 'handle'],
            // Authentication happens inside handle() via HMAC signature.
            // A capability check would be wrong here: Zeffy is not a WP user.
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle(WP_REST_Request $request)
    {
        // ── 0. Secret must be configured ────────────────────
        $secret = self::get_secret();
        if ($secret === '') {
            self::log('rejected', 'No signing secret configured on this site.');
            return new WP_REST_Response(['error' => 'Webhook not configured.'], 503);
        }

        // ── 1. Raw body, untouched ──────────────────────────
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            $raw = $request->get_body();
        }
        if (!is_string($raw) || $raw === '') {
            self::log('rejected', 'Empty request body.');
            return new WP_REST_Response(['error' => 'Empty body.'], 400);
        }

        // ── 2. Signature header ─────────────────────────────
        $header = $request->get_header('zeffy_signature');
        if (!$header) {
            $header = $request->get_header('Zeffy-Signature');
        }
        if (!$header) {
            self::log('rejected', 'Missing Zeffy-Signature header.');
            return new WP_REST_Response(['error' => 'Missing signature.'], 401);
        }

        $parsed = self::parse_signature((string) $header);
        if ($parsed === null) {
            self::log('rejected', 'Malformed Zeffy-Signature header.');
            return new WP_REST_Response(['error' => 'Malformed signature.'], 401);
        }
        [$timestamp, $received] = $parsed;

        // ── 3. Replay window ────────────────────────────────
        if (abs(time() - $timestamp) > self::TOLERANCE) {
            self::log('rejected', 'Signature timestamp outside the allowed window.');
            return new WP_REST_Response(['error' => 'Stale signature.'], 401);
        }

        // ── 4. Constant-time HMAC comparison ────────────────
        $expected = hash_hmac('sha256', $timestamp . '.' . $raw, $secret);
        if (!hash_equals($expected, $received)) {
            self::log('rejected', 'Signature mismatch.');
            return new WP_REST_Response(['error' => 'Invalid signature.'], 401);
        }

        // ── 5. Parse and route the event ────────────────────
        $event = json_decode($raw, true);
        if (!is_array($event)) {
            self::log('rejected', 'Body was not valid JSON.');
            return new WP_REST_Response(['error' => 'Invalid JSON.'], 400);
        }

        $type = (string) ($event['type'] ?? '');
        if ($type !== 'payment.completed') {
            // Acknowledge unknown types so Zeffy stops retrying them.
            self::log('ignored', 'Unhandled event type: ' . $type);
            return new WP_REST_Response(['received' => true], 200);
        }

        $payment = $event['data'] ?? null;
        if (!is_array($payment) || empty($payment['id'])) {
            self::log('rejected', 'Event carried no usable payment object.');
            return new WP_REST_Response(['error' => 'Malformed payload.'], 400);
        }

        // ── 6. Store (idempotent on payment_id) ─────────────
        $outcome = IQU_Zeffy_DB::upsert($payment, IQU_Zeffy_DB::SOURCE_WEBHOOK);

        if ($outcome === '') {
            // Return non-2xx so Zeffy retries — the data is worth another attempt.
            self::log('error', 'Database write failed for payment ' . $payment['id']);
            return new WP_REST_Response(['error' => 'Storage failure.'], 500);
        }

        $amount = IQU_Zeffy_DB::money(
            (int) ($payment['amount'] ?? 0),
            (string) ($payment['currency'] ?? 'usd')
        );
        self::log($outcome, sprintf('%s donation from %s', $amount, IQU_Zeffy_DB::donor_name(
            IQU_Zeffy_DB::map_payment($payment)
        )));

        /**
         * Fires after a Zeffy donation has been stored.
         * Useful for Telegram / email notifications.
         *
         * @param array  $payment Full Zeffy payment object.
         * @param string $outcome 'inserted' or 'updated'.
         */
        do_action('iqu_zeffy_payment_received', $payment, $outcome);

        return new WP_REST_Response(['received' => true], 200);
    }

    // ════════════════════════════════════════════════════
    // SIGNATURE PARSING
    // ════════════════════════════════════════════════════

    /**
     * "t=1700000000,v1=5f5e..." → [1700000000, '5f5e...']
     *
     * @return array{0:int,1:string}|null
     */
    private static function parse_signature(string $header): ?array
    {
        $timestamp = 0;
        $signature = '';

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) continue;

            if ($pair[0] === 't') {
                $timestamp = (int) $pair[1];
            } elseif ($pair[0] === 'v1') {
                $signature = trim($pair[1]);
            }
        }

        if ($timestamp <= 0 || $signature === '' || !ctype_xdigit($signature)) {
            return null;
        }

        return [$timestamp, $signature];
    }

    // ════════════════════════════════════════════════════
    // DELIVERY LOG
    // ════════════════════════════════════════════════════

    /**
     * Small rolling log shown on the admin screen so you can confirm
     * deliveries are arriving without digging through server logs.
     */
    public static function log(string $status, string $message): void
    {
        $log = get_option(self::OPT_LOG, []);
        if (!is_array($log)) $log = [];

        array_unshift($log, [
            'time'    => time(),
            'status'  => $status,
            'message' => mb_substr($message, 0, 200),
        ]);

        update_option(self::OPT_LOG, array_slice($log, 0, self::LOG_LIMIT), false);
    }

    public static function get_log(): array
    {
        $log = get_option(self::OPT_LOG, []);
        return is_array($log) ? $log : [];
    }

    public static function clear_log(): void
    {
        delete_option(self::OPT_LOG);
    }

    /**
     * True when at least one delivery has been accepted.
     */
    public static function is_receiving(): bool
    {
        foreach (self::get_log() as $entry) {
            if (in_array($entry['status'] ?? '', ['inserted', 'updated'], true)) {
                return true;
            }
        }
        return false;
    }
}