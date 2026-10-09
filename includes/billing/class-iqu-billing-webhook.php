<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Billing_Webhook
 *
 * POST /wp-json/iqu/v1/stripe-webhook
 *
 * Security:
 * - Every request must carry a valid Stripe-Signature: HMAC-SHA256 of
 *   "timestamp.body" with the signing secret from wp-config.php
 *   (IQU_STRIPE_WEBHOOK_SECRET). Constant-time compare. Requests older than
 *   5 minutes are refused, which blocks replays.
 * - Events from the other mode (test vs live) are ignored.
 * - Each event id is processed once (dedupe table). If processing fails the
 *   record is removed and Stripe is asked to retry, so nothing is lost.
 * - The event body is only used to find WHICH family it concerns. Status,
 *   amounts and dates are then read straight from the Stripe API.
 * - The Stripe object must belong to the account's own Stripe customer.
 * - Responses never echo internal errors.
 */
class IQU_Billing_Webhook
{
    private const TOLERANCE = 300;
    private const MAX_BYTES = 512000;
    public const EVENTS = [
        'checkout.session.completed',
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
        'customer.subscription.paused',
        'customer.subscription.resumed',
        'invoice.paid',
        'invoice.payment_failed',
        'invoice.payment_action_required',
        'charge.refunded',
    ];

    public static function init(): void
    {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function routes(): void
    {
        register_rest_route('iqu/v1', '/stripe-webhook', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'handle'],
            'permission_callback' => '__return_true', // authenticated by the Stripe signature below
        ]);
    }

    public static function secret(): string
    {
        return (defined('IQU_STRIPE_WEBHOOK_SECRET') && is_string(IQU_STRIPE_WEBHOOK_SECRET)) ? trim(IQU_STRIPE_WEBHOOK_SECRET) : '';
    }

    /** Stripe signature check. Public so it can be tested. */
    public static function verify(string $payload, string $header, string $secret, int $now = 0): bool
    {
        if ($secret === '' || $header === '' || $payload === '') return false;
        $now  = $now ?: time();
        $t    = 0;
        $sigs = [];
        foreach (explode(',', $header) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) continue;
            if ($kv[0] === 't') $t = (int) $kv[1];
            elseif ($kv[0] === 'v1') $sigs[] = $kv[1];
        }
        if (!$t || !$sigs || abs($now - $t) > self::TOLERANCE) return false;
        $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
        foreach ($sigs as $s) {
            if (hash_equals($expected, $s)) return true;
        }
        return false;
    }

    public static function handle(WP_REST_Request $req): WP_REST_Response
    {
        $payload = (string) $req->get_body();
        if (strlen($payload) > self::MAX_BYTES) return self::reply(413, 'too_large');
        if (!self::verify($payload, (string) $req->get_header('stripe_signature'), self::secret())) {
            return self::reply(400, 'invalid_signature');
        }

        $event = json_decode($payload, true);
        if (!is_array($event) || empty($event['id']) || empty($event['type'])) return self::reply(400, 'bad_event');

        $is_live = !empty($event['livemode']);
        if ($is_live !== (IQU_Stripe::expected_mode() === 'live')) return self::reply(200, 'ignored_other_mode');
        if (!in_array($event['type'], self::EVENTS, true)) return self::reply(200, 'ignored_type');
        if (!IQU_Billing_DB::record_event((string) $event['id'], (string) $event['type'])) return self::reply(200, 'duplicate');

        try {
            [$result, $account_id, $note] = self::process($event);
            IQU_Billing_DB::finish_event((string) $event['id'], $result, $account_id, $note);
            return self::reply(200, $result);
        } catch (\Throwable $e) {
            self::forget((string) $event['id']); // let Stripe's retry process it again
            return self::reply(500, 'retry');
        }
    }

    /** @return array{0:string,1:?int,2:string} result, account id, note */
    private static function process(array $event): array
    {
        $obj = (array) ($event['data']['object'] ?? []);
        $acc = self::account_for($obj);
        if (!$acc) return ['no_account', null, ''];

        $before = $acc;
        $sync   = IQU_Billing_Sync::sync($acc);
        if (!$sync['ok']) throw new RuntimeException('sync failed');
        $acc = $sync['account'];
        $id  = (int) $acc['id'];

        switch ($event['type']) {
            case 'checkout.session.completed':
                $had_method = in_array($sync['old'], ['free_month', 'waiting_first_charge', 'active', 'past_due', 'unpaid', 'paused'], true);
                if (!$had_method && in_array($sync['new'], ['free_month', 'waiting_first_charge', 'active'], true)) {
                    IQU_Billing_Notify::method_added($acc);
                }
                break;

            case 'invoice.paid':
                $inv = self::invoice((string) ($obj['id'] ?? ''), $acc);
                $amount = $inv ? ((int) ($inv['amount_paid'] ?? 0)) / 100 : 0;
                if ($amount > 0) {
                    $first = ((string) $before['last_payment_status']) === '';
                    IQU_Billing_DB::update_account($id, [
                        'last_payment_status' => 'paid',
                        'last_payment_at'     => gmdate('Y-m-d H:i:s', (int) ($inv['status_transitions']['paid_at'] ?? time())),
                        'last_failure_reason' => '',
                    ]);
                    IQU_Billing_DB::record_payment((string) $inv['id'], $id, $amount, (int) ($inv['status_transitions']['paid_at'] ?? time()));
                    IQU_Billing_Notify::payment_received(IQU_Billing_DB::get_account($id) ?: $acc, $amount, $first, $inv);
                }
                break;

            case 'invoice.payment_failed':
                $inv = self::invoice((string) ($obj['id'] ?? ''), $acc);
                if ($inv) {
                    $amount  = ((int) ($inv['amount_due'] ?? 0)) / 100;
                    $attempt = (int) ($inv['attempt_count'] ?? 1);
                    $next    = !empty($inv['next_payment_attempt']) ? (int) $inv['next_payment_attempt'] : null;
                    IQU_Billing_DB::update_account($id, [
                        'last_payment_status' => 'failed',
                        'last_failure_reason' => substr('Attempt ' . $attempt . ($next ? ', next try ' . gmdate('j M', $next) : ', no more automatic attempts'), 0, 255),
                    ]);
                    IQU_Billing_Notify::payment_failed(IQU_Billing_DB::get_account($id) ?: $acc, $amount, $attempt, $next, $inv);
                }
                break;

            case 'invoice.payment_action_required':
                IQU_Billing_DB::update_account($id, [
                    'last_payment_status' => 'action_required',
                    'last_failure_reason' => 'The bank asked the family to confirm the payment. Stripe has emailed them a link.',
                ]);
                break;

            case 'charge.refunded':
                // Payment history only: re-read the refunded charge's invoice from Stripe.
                $inv_id = IQU_Billing_History::invoice_id_for_charge($obj);
                if ($inv_id === '' || !IQU_Billing_History::refresh_invoice($inv_id, $acc)) {
                    IQU_Billing_History::refresh_account($acc);
                }
                break;
        }

        // Payment history: store the invoice as Stripe has it now. Never throws.
        if (in_array($event['type'], ['invoice.paid', 'invoice.payment_failed', 'invoice.payment_action_required'], true)) {
            IQU_Billing_History::refresh_invoice((string) ($obj['id'] ?? ''), IQU_Billing_DB::get_account($id) ?: $acc);
        }

        if (in_array($sync['new'], ['unpaid', 'paused', 'canceled'], true) && $sync['new'] !== $sync['old']) {
            IQU_Billing_Notify::needs_contact($acc, $sync['new']);
        }

        return ['ok', $id, substr($sync['old'] . ' -> ' . $sync['new'], 0, 255)];
    }

    /** Find our account from a Stripe object, and check it belongs to that account's customer. */
    private static function account_for(array $obj): ?array
    {
        $type = (string) ($obj['object'] ?? '');
        $acc  = null;

        $id = (int) ($obj['metadata']['iqu_account_id'] ?? 0);
        if (!$id && $type === 'checkout.session') $id = (int) ($obj['client_reference_id'] ?? 0);
        if ($id) $acc = IQU_Billing_DB::get_account($id);

        if (!$acc) {
            $sub = $type === 'subscription' ? (string) ($obj['id'] ?? '')
                 : (string) ($obj['subscription'] ?? ($obj['parent']['subscription_details']['subscription'] ?? ''));
            if ($sub !== '') $acc = IQU_Billing_DB::get_account_by_subscription($sub);
        }

        $cus = (string) ($obj['customer'] ?? '');
        if (!$acc && $cus !== '') $acc = IQU_Billing_DB::get_account_by_customer($cus);

        if ($acc && $cus !== '' && $cus !== (string) $acc['stripe_customer_id']) return null;
        return $acc;
    }

    /** Read an invoice from Stripe and make sure it belongs to this account. */
    private static function invoice(string $invoice_id, array $acc): ?array
    {
        if (!preg_match('/^in_[A-Za-z0-9]+$/', $invoice_id)) return null;
        $r = IQU_Stripe::get('/invoices/' . $invoice_id);
        if (!$r['ok']) throw new RuntimeException('invoice read failed');
        if ((string) ($r['data']['customer'] ?? '') !== (string) $acc['stripe_customer_id']) return null;
        return $r['data'];
    }

    private static function forget(string $event_id): void
    {
        global $wpdb;
        $wpdb->delete(IQU_Billing_DB::events_table(), ['stripe_event_id' => $event_id], ['%s']);
    }

    private static function reply(int $code, string $result): WP_REST_Response
    {
        return new WP_REST_Response(['received' => $code < 400, 'result' => $result], $code);
    }
}
