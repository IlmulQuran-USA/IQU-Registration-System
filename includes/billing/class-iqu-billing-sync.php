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
     * @return array{ok:bool, error:string, old:string, new:string, account:array}
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

        // Prefer the subscription made for this account; ignore anything else on the customer.
        $sub = null;
        foreach ((array) ($r['data']['data'] ?? []) as $s) {
            if ((string) ($s['metadata']['iqu_account_id'] ?? '') === (string) $acc['id']) {
                if (!$sub || (int) $s['created'] > (int) $sub['created']) $sub = $s;
            }
        }

        if (!$sub) {
            // No subscription yet: the family has not finished the Stripe page.
            $res['ok'] = true;
            return $res;
        }

        $status = self::status_from($sub);
        $upd = [
            'stripe_subscription_id' => (string) $sub['id'],
            'status'                 => $status,
            'next_charge_at'         => self::next_charge($sub),
            'payment_method_label'   => self::method_label($sub['default_payment_method'] ?? null),
        ];
        IQU_Billing_DB::update_account((int) $acc['id'], $upd);

        $res['ok']      = true;
        $res['new']     = $status;
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
