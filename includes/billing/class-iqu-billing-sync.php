<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Billing_Sync
 *
 * Brings one family's billing record in line with Stripe.
 * Stripe is the source of truth: this reads the subscription straight from
 * the Stripe API and never trusts the contents of a webhook body.
 *
 * Used by the webhook after every event, and by the "Sync from Stripe"
 * button when an admin wants to refresh a record by hand.
 */
class IQU_Billing_Sync
{
    /**
     * @return array{ok:bool, error:string, old:string, new:string, account:array, subscription?:array}
     */
    public static function sync(array $acc): array
    {
        $res = ['ok' => false, 'error' => '', 'old' => (string) $acc['status'], 'new' => (string) $acc['status'], 'account' => $acc];
        if (empty($acc['stripe_customer_id'])) {
            $res['error'] = 'Not linked to Stripe.';
            return $res;
        }

        $r = IQU_Stripe::get('/subscriptions', [
            'customer' => $acc['stripe_customer_id'],
            'status'   => 'all',
            'limit'    => 10,
            'expand'   => ['data.default_payment_method'],
        ]);
        if (!$r['ok']) {
            $res['error'] = $r['error'];
            return $res;
        }

        // Prefer the subscription made for this account; ignore anything else on the customer. A live one
        // wins over a cancelled one (after two subscriptions, the team may cancel the newer), then the newest.
        $sub  = null;
        $live = fn($s) => !in_array((string) ($s['status'] ?? ''), ['canceled', 'incomplete_expired'], true);
        foreach ((array) ($r['data']['data'] ?? []) as $s) {
            if ((string) ($s['metadata']['iqu_account_id'] ?? '') === (string) $acc['id']) {
                if (!$sub || [$live($s), (int) $s['created']] > [$live($sub), (int) $sub['created']]) $sub = $s;
            }
        }

        if (!$sub) {
            // No subscription yet: the family has not finished the Stripe page.
            $res['ok'] = true;
            return $res;
        }

        // More than one live subscription for this family (two Checkout pages completed): the family would be
        // billed twice. Flag for review and alert once; nothing is cancelled automatically.
        $live = array_values(array_filter((array) ($r['data']['data'] ?? []), fn($s) => (string) ($s['metadata']['iqu_account_id'] ?? '') === (string) $acc['id']
            && !in_array((string) ($s['status'] ?? ''), ['canceled', 'incomplete_expired'], true)));
        if (count($live) > 1 && (string) ($acc['review_reason'] ?? '') === '') {
            $note = 'Two Stripe subscriptions for this family (' . implode(', ', array_map(fn($s) => (string) $s['id'], $live)) . ') — cancel one in Stripe, then press "Mark as reviewed".';
            IQU_Billing_DB::update_account((int) $acc['id'], ['review_reason' => 'duplicate_subscription', 'review_note' => substr($note, 0, 255)]);
            $acc['review_reason'] = 'duplicate_subscription';
            if (class_exists('IQU_Billing_Notify')) IQU_Billing_Notify::duplicate_subscription(IQU_Billing_DB::get_account((int) $acc['id']) ?: $acc, $note);
        }

        $status = self::status_from($sub);
        $label  = self::method_label($sub['default_payment_method'] ?? null);
        // A US bank account still waiting for its microdeposits (they take 1–2 business days, then the family
        // confirms them): not set up yet, so no "card added", no enrollment and no charge until it is verified.
        $bank = self::bank_waiting($acc, $sub);
        if ($bank !== null) {
            $status = 'bank_verification';
            if ($label === '') $label = $bank;
        }
        // An account under review (amount mismatch, two subscriptions) stays in review until an admin clears it.
        if ((string) ($acc['review_reason'] ?? '') !== '') $status = 'review';
        $upd = [
            'stripe_subscription_id' => (string) $sub['id'],
            'status'                 => $status,
            'next_charge_at'         => self::next_charge($sub),
            'payment_method_label'   => $label,
        ];
        IQU_Billing_DB::update_account((int) $acc['id'], $upd);

        $res['ok']           = true;
        $res['new']          = $status;
        $res['subscription'] = $sub; // its items: what Stripe charges (IQU_Billing_Service::amount_check)
        $res['account'] = IQU_Billing_DB::get_account((int) $acc['id']) ?: $acc;
        return $res;
    }

    /** Stripe subscription state -> our billing status. */
    public static function status_from(array $sub): string
    {
        if (!empty($sub['pause_collection'])) return 'paused';
        switch ((string) $sub['status']) {
            case 'trialing':
                return 'free_month';
            case 'active':
                // Uses Stripe's own dates only (works with test clocks and never depends on the server clock):
                // the current period starts at or after the first charge date once that charge has happened.
                $anchor = (int) ($sub['billing_cycle_anchor'] ?? 0);
                $start  = (int) ($sub['items']['data'][0]['current_period_start'] ?? $sub['current_period_start'] ?? 0);
                return ($anchor > 0 && $start > 0 && $start < $anchor) ? 'waiting_first_charge' : 'active';
            case 'past_due':
                return 'past_due';
            case 'unpaid':
                return 'unpaid';
            case 'paused':
                return 'paused';
            case 'canceled':
            case 'incomplete_expired':
                return 'canceled';
            default:
                return 'link_sent'; // incomplete: payment page not finished
        }
    }

    /**
     * The bank account's label ('' when unknown) while the family's US bank account waits for microdeposit
     * verification; null otherwise. No payment due yet (free month, a later first charge): the subscription's
     * pending SetupIntent, or the customer's latest ones (Checkout's own). A payment due now: the subscription is incomplete and its PaymentIntent waits.
     * A read that fails (e.g. a key without SetupIntents: Read) only skips this check; it never stops the sync.
     */
    private static function bank_waiting(array $acc, array $sub): ?string
    {
        if (!in_array((string) ($sub['status'] ?? ''), ['trialing', 'active', 'incomplete'], true)) return null;
        $intents = [];
        $si = $sub['pending_setup_intent'] ?? null;
        if (is_array($si)) $si = (string) ($si['id'] ?? '');
        if (is_string($si) && preg_match('/^seti_[A-Za-z0-9]+$/', $si)) {
            $r = IQU_Stripe::get('/setup_intents/' . $si, ['expand' => ['payment_method']]);
            if ($r['ok']) $intents[] = (array) $r['data'];
        } elseif ((string) $sub['status'] !== 'incomplete') {
            // Checkout's own SetupIntent (no payment due yet): only while the subscription has no card, or its
            // default is that same bank account — an old, unfinished bank attempt never hides a working card.
            $dpm = $sub['default_payment_method'] ?? null;
            $dpm_id = is_array($dpm) ? (string) ($dpm['id'] ?? '') : (string) $dpm;
            if ($dpm_id === '' || (is_array($dpm) && ($dpm['type'] ?? '') === 'us_bank_account')) {
                $r = IQU_Stripe::get('/setup_intents', ['customer' => $acc['stripe_customer_id'], 'limit' => 3, 'expand' => ['data.payment_method']]);
                if ($r['ok']) foreach ((array) ($r['data']['data'] ?? []) as $i) {
                    $pm_id = is_array($i['payment_method'] ?? null) ? (string) ($i['payment_method']['id'] ?? '') : (string) ($i['payment_method'] ?? '');
                    if ($dpm_id === '' || $pm_id === $dpm_id) $intents[] = (array) $i;
                }
            }
        }
        if ((string) $sub['status'] === 'incomplete') {
            $r = IQU_Stripe::get('/payment_intents', ['customer' => $acc['stripe_customer_id'], 'limit' => 3, 'expand' => ['data.payment_method']]);
            if ($r['ok']) foreach ((array) ($r['data']['data'] ?? []) as $pi) $intents[] = (array) $pi;
        }
        foreach ($intents as $i) {
            if ((string) ($i['status'] ?? '') === 'requires_action' && (string) ($i['next_action']['type'] ?? '') === 'verify_with_microdeposits') {
                return self::method_label(is_array($i['payment_method'] ?? null) ? $i['payment_method'] : null);
            }
        }
        return null;
    }

    /** Next charge time (UTC, MySQL format) or null. */
    private static function next_charge(array $sub): ?string
    {
        if (in_array($sub['status'], ['canceled', 'incomplete_expired'], true)) return null;
        if ($sub['status'] === 'trialing' && !empty($sub['trial_end'])) {
            return gmdate('Y-m-d H:i:s', (int) $sub['trial_end']);
        }
        $end = (int) ($sub['items']['data'][0]['current_period_end'] ?? $sub['current_period_end'] ?? 0);
        return $end ? gmdate('Y-m-d H:i:s', $end) : null;
    }

    /** "Visa •••• 4242" / "Chase •••• 6789". Never more than the last four digits. */
    private static function method_label($pm): string
    {
        if (!is_array($pm)) return '';
        $type = (string) ($pm['type'] ?? '');
        if ($type === 'card') {
            return substr(ucfirst((string) ($pm['card']['brand'] ?? 'Card')) . ' •••• ' . ($pm['card']['last4'] ?? ''), 0, 60);
        }
        if ($type === 'us_bank_account') {
            $bank = (string) ($pm['us_bank_account']['bank_name'] ?? 'Bank account');
            return substr($bank . ' •••• ' . ($pm['us_bank_account']['last4'] ?? ''), 0, 60);
        }
        if ($type === 'link') return 'Link';
        return $type !== '' ? ucfirst(str_replace('_', ' ', $type)) : '';
    }
}
