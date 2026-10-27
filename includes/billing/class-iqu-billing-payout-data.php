<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Billing_Payout_Data
 *
 * What is inside each Stripe payout (one bank deposit): read from Stripe, totalled and cached.
 * One summary serves Billing → Payouts (screen and CSV) and the payout Telegram alert, so the
 * numbers always match.
 *
 *   payouts()  the payout list (GET /payouts), Stripe's order (newest created first)
 *   summary()  one payout and every balance transaction in it (GET /balance_transactions?payout=)
 *
 * Two caches per payout: its transactions (30 days once Stripe has matched them: they never change)
 * and the whole summary with the payout's status (30 days once paid, 10 minutes otherwise).
 *
 * Money stays in cents here; the screens convert once.
 *
 * Security:
 * - Read only: GET requests through IQU_Stripe, never a POST.
 * - Ids are checked (po_…, ch_… / py_…) before they reach a Stripe path.
 * - The cache holds Stripe's ids and numbers only (customer ids, metadata ids, amounts), never a
 *   family's name or contact. Families are matched in the local database when shown, current
 *   mode only. Every cache key carries the mode.
 * - A refused permission becomes a plain sentence; no key or secret is ever shown or logged.
 */
final class IQU_Billing_Payout_Data
{
    public const PAID_TTL  = 30 * DAY_IN_SECONDS;     // a paid payout does not change; nor do matched transactions
    public const SHORT_TTL = 10 * MINUTE_IN_SECONDS;
    private const CACHE    = 'iqu_payout_';            // + mode _ payout id (summary) / + '_txn' (transactions only)
    private const LIST     = 'iqu_payouts_';           // + mode _ list version _ hash of the query
    private const LIST_VER = 'iqu_payouts_list_ver';   // Refresh moves it on, so every cached list is skipped
    private const SUMMARY_VERSION = 1;

    public const PERMISSION_NOTE = 'The Stripe key cannot read payouts. In the Stripe Dashboard (Developers → API keys), give the restricted key READ access to "Payouts" and "Balance" (Balance transactions), and to "Disputes" if a payout includes a dispute.';
    public const MANUAL_NOTE     = 'This Stripe account sends payouts by hand (manual payouts). Stripe only links transactions to automatic payouts, so the families inside a manual payout cannot be listed.';

    /** How each balance transaction type counts. Every type lands in exactly one group, so nothing is counted twice. */
    private const GROSS_TYPES  = ['charge', 'payment'];
    private const REFUND_TYPES = ['refund', 'payment_refund', 'refund_failure', 'adjustment', 'payment_failure_refund', 'payment_reversal'];
    private const FEE_TYPES    = ['stripe_fee', 'stripe_fx_fee', 'tax_fee'];

    // ------------------------------------------------------------
    // The payout list
    // ------------------------------------------------------------

    /**
     * Payouts expected to arrive since $since (Unix time), up to 50, then the next 50 after $after.
     * @return array{ok:bool, error:string, code:string, payouts:array[], has_more:bool, cached:bool}
     */
    public static function payouts(int $since, string $after = ''): array
    {
        $params = ['limit' => 50, 'arrival_date' => ['gte' => $since], 'expand' => ['data.destination']];
        if ($after !== '') {
            if (!preg_match('/^po_[A-Za-z0-9]+$/', $after)) return self::fail('Not a payout id.', 'iqu_bad_id') + ['payouts' => [], 'has_more' => false, 'cached' => false];
            $params['starting_after'] = $after;
        }
        $key = self::LIST . IQU_Stripe::expected_mode() . '_' . (int) get_option(self::LIST_VER, 0) . '_' . md5((string) wp_json_encode($params));
        $hit = get_transient($key);
        if (is_array($hit) && isset($hit['payouts'])) return $hit + ['cached' => true];

        $r = IQU_Stripe::get('/payouts', $params);
        if (!$r['ok'] && $r['status'] === 403) {
            // The bank details may need more than Payouts: Read; the list itself still works without them.
            unset($params['expand']);
            $r = IQU_Stripe::get('/payouts', $params);
        }
        if (!$r['ok']) return self::stripe_fail($r) + ['payouts' => [], 'has_more' => false, 'cached' => false];

        $out = ['ok' => true, 'error' => '', 'code' => '', 'payouts' => array_map([__CLASS__, 'payout_fields'], (array) ($r['data']['data'] ?? [])), 'has_more' => !empty($r['data']['has_more'])];
        set_transient($key, $out, self::SHORT_TTL);
        return $out + ['cached' => false];
    }

    /** Refresh: every cached list is skipped from now on, and this payout is read again unless it is settled. */
    public static function forget(string $payout_id = ''): void
    {
        update_option(self::LIST_VER, (int) get_option(self::LIST_VER, 0) + 1, false);
        if ($payout_id !== '' && preg_match('/^po_[A-Za-z0-9]+$/', $payout_id)) delete_transient(self::cache_key($payout_id)); // its transactions stay: they never change
    }

    /** A payout that changed (failed after "paid") must be read again. */
    public static function drop(string $payout_id): void
    {
        if (preg_match('/^po_[A-Za-z0-9]+$/', $payout_id)) { delete_transient(self::cache_key($payout_id)); delete_transient(self::cache_key($payout_id) . '_txn'); }
        update_option(self::LIST_VER, (int) get_option(self::LIST_VER, 0) + 1, false);
    }

    /** The cached summary of a payout, or null (never calls Stripe). */
    public static function cached(string $payout_id): ?array
    {
        if (!preg_match('/^po_[A-Za-z0-9]+$/', $payout_id)) return null;
        $hit = get_transient(self::cache_key($payout_id));
        return (is_array($hit) && ($hit['v'] ?? 0) === self::SUMMARY_VERSION) ? $hit : null;
    }

    /** Number of transactions of a payout when they are cached, else null (never calls Stripe). */
    public static function cached_count(string $payout_id): ?int
    {
        if (!preg_match('/^po_[A-Za-z0-9]+$/', $payout_id)) return null;
        $txn = get_transient(self::cache_key($payout_id) . '_txn');
        return (is_array($txn) && ($txn['v'] ?? 0) === self::SUMMARY_VERSION) ? count((array) $txn['rows']) : null;
    }

    // ------------------------------------------------------------
    // One payout, in full
    // ------------------------------------------------------------

    /**
     * Every balance transaction of one automatic payout, totalled. The summary is cached 30 days once the
     * payout is paid, 10 minutes otherwise; its transactions 30 days once Stripe has matched them.
     *
     * @param float|null $budget  Seconds this may take in all (the webhook); null = no limit (the screens).
     * @param int|null   $timeout Seconds per Stripe request; null = the client's usual.
     * @return array{ok:bool, error:string, code:string, summary:?array, payout:?array, cached:bool}
     *   code iqu_not_reconciled / iqu_manual: the payout is known ('payout'), its transactions not (yet).
     */
    public static function summary(string $payout_id, ?float $budget = null, ?int $timeout = null): array
    {
        $none = ['summary' => null, 'payout' => null, 'cached' => false];
        if (!preg_match('/^po_[A-Za-z0-9]+$/', $payout_id)) return self::fail('Not a payout id.', 'iqu_bad_id') + $none;
        if ($hit = self::cached($payout_id)) return ['ok' => true, 'error' => '', 'code' => '', 'summary' => $hit, 'payout' => $hit['payout'], 'cached' => true];

        $start = microtime(true);
        $late  = fn() => $budget !== null && (microtime(true) - $start) > $budget;

        $txn = get_transient(self::cache_key($payout_id) . '_txn');
        $txn = (is_array($txn) && ($txn['v'] ?? 0) === self::SUMMARY_VERSION) ? $txn : null;

        // The payout itself is always read again (its status changes); its transactions come from the cache when known.
        $r = IQU_Stripe::get('/payouts/' . $payout_id, ['expand' => ['destination']], $timeout);
        if (!$r['ok'] && $r['status'] === 403 && !$late()) $r = IQU_Stripe::get('/payouts/' . $payout_id, [], $timeout); // bank details refused: go on without them
        if (!$r['ok']) return self::stripe_fail($r) + $none;
        $payout = self::payout_fields((array) $r['data']);
        if ($txn) {
            // the transactions are known: only the payout's own fields are new (bank details kept from then)
            if ($payout['bank_name'] === '' && $payout['last4'] === '') { $payout['bank_name'] = (string) $txn['bank_name']; $payout['last4'] = (string) $txn['last4']; }
            return self::store($payout, (array) $txn['rows']);
        }

        if (!$payout['automatic'] || $payout['reconciliation_status'] === 'not_applicable') {
            return self::fail(self::MANUAL_NOTE, 'iqu_manual') + ['summary' => null, 'payout' => $payout, 'cached' => false];
        }
        if ($payout['reconciliation_status'] !== 'completed') {
            return self::fail('Stripe has not finished matching the transactions of this payout yet. Please try again in a few minutes.', 'iqu_not_reconciled') + ['summary' => null, 'payout' => $payout, 'cached' => false];
        }

        // Every page of transactions (100 at a time), each with its source (the charge, refund or dispute).
        $txns  = [];
        $after = '';
        for ($page = 0; $page < 200; $page++) {
            if ($late()) return self::fail('Reading the payout took too long.', 'iqu_budget') + ['summary' => null, 'payout' => $payout, 'cached' => false];
            $params = ['payout' => $payout_id, 'limit' => 100, 'expand' => ['data.source']];
            if ($after !== '') $params['starting_after'] = $after;
            $t = IQU_Stripe::get('/balance_transactions', $params, $timeout);
            if (!$t['ok']) return self::stripe_fail($t) + ['summary' => null, 'payout' => $payout, 'cached' => false];
            $data = (array) ($t['data']['data'] ?? []);
            foreach ($data as $item) $txns[] = (array) $item;
            if (empty($t['data']['has_more']) || !$data) break;
            $after = (string) ($data[count($data) - 1]['id'] ?? '');
            if (!preg_match('/^txn_[A-Za-z0-9]+$/', $after)) break;
        }

        // Refunds, disputes and bank returns name only their charge: read each charge once for its customer.
        $rows    = array_values(array_filter(array_map(fn($t) => self::row($t, $payout_id), $txns)));
        $charges = [];
        foreach ($rows as $i => $row) {
            if ($row['customer'] !== '' || $row['summer_reg'] > 0 || $row['charge'] === '') continue;
            if (!array_key_exists($row['charge'], $charges)) {
                if ($late()) return self::fail('Reading the payout took too long.', 'iqu_budget') + ['summary' => null, 'payout' => $payout, 'cached' => false];
                $c = IQU_Stripe::get('/charges/' . $row['charge'], [], $timeout);
                if (!$c['ok'] && $c['status'] === 403) return self::stripe_fail($c) + ['summary' => null, 'payout' => $payout, 'cached' => false];
                $charges[$row['charge']] = $c['ok'] ? (array) $c['data'] : [];
            }
            $ch = $charges[$row['charge']];
            $rows[$i]['customer']   = self::id_of($ch['customer'] ?? '');
            $rows[$i]['summer_reg'] = self::summer_reg($ch);
        }

        // Every page read and Stripe has matched them: these transactions never change.
        set_transient(self::cache_key($payout_id) . '_txn', ['v' => self::SUMMARY_VERSION, 'rows' => $rows, 'bank_name' => $payout['bank_name'], 'last4' => $payout['last4']], self::PAID_TTL);
        return self::store($payout, $rows);
    }

    /** The summary of a payout and its rows, cached by the payout's status (30 days once paid, 10 minutes otherwise). */
    private static function store(array $payout, array $rows): array
    {
        $summary = [
            'v'        => self::SUMMARY_VERSION,
            'payout'   => $payout,
            'rows'     => $rows,
            'totals'   => self::totals($rows, (int) $payout['amount']),
            'built_at' => time(),
        ];
        set_transient(self::cache_key((string) $payout['id']), $summary, $payout['status'] === 'paid' ? self::PAID_TTL : self::SHORT_TTL);
        return ['ok' => true, 'error' => '', 'code' => '', 'summary' => $summary, 'payout' => $payout, 'cached' => false];
    }

    /**
     * Totals in cents. Each row counts in one group only:
     *   gross   = amount of charges and payments
     *   refunds = money that went back (refunds, disputes, bank returns), as a positive number
     *   fees    = every row's own fee + the amount of Stripe's separate fee rows (their own fee is in the first part)
     *   other   = amount of any other row type
     *   net     = Stripe's net of every row
     * Checks: gross − fees − refunds + other = net, and net = the payout amount.
     */
    public static function totals(array $rows, int $payout_amount): array
    {
        $t = ['gross' => 0, 'fees' => 0, 'refunds' => 0, 'other' => 0, 'net' => 0, 'payments' => 0, 'refund_rows' => 0, 'rows' => count($rows)];
        foreach ($rows as $r) {
            $t['fees'] += (int) $r['fee'];
            $t['net']  += (int) $r['net'];
            if (in_array($r['type'], self::GROSS_TYPES, true)) { $t['gross'] += (int) $r['amount']; $t['payments']++; }
            elseif (in_array($r['type'], self::REFUND_TYPES, true)) { $t['refunds'] -= (int) $r['amount']; $t['refund_rows']++; }
            elseif (in_array($r['type'], self::FEE_TYPES, true)) $t['fees'] -= (int) $r['amount'];
            else $t['other'] += (int) $r['amount'];
        }
        $t['formula_diff'] = $t['gross'] - $t['fees'] - $t['refunds'] + $t['other'] - $t['net'];
        $t['payout_diff']  = $t['net'] - $payout_amount;
        return $t;
    }

    /** The kind of a row, for the screens: tuition / summer / payment / refund / dispute / ach_return / stripe_fee / other. */
    public static function kind(array $row): string
    {
        $type = (string) $row['type'];
        if (in_array($type, self::GROSS_TYPES, true)) return $row['summer_reg'] > 0 ? 'summer' : 'payment';
        if (in_array($type, ['refund', 'payment_refund', 'refund_failure'], true)) return 'refund';
        if ($type === 'adjustment') return 'dispute';
        if ($type === 'payment_failure_refund' || $type === 'payment_reversal') return 'ach_return';
        if (in_array($type, self::FEE_TYPES, true)) return 'stripe_fee';
        return 'other';
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /** The fields of a payout the screens and alerts use. */
    public static function payout_fields(array $p): array
    {
        $dest = is_array($p['destination'] ?? null) ? $p['destination'] : [];
        return [
            'id'                    => (string) ($p['id'] ?? ''),
            'amount'                => (int) ($p['amount'] ?? 0),
            'currency'              => strtolower((string) ($p['currency'] ?? 'usd')),
            'arrival_date'          => (int) ($p['arrival_date'] ?? 0),
            'created'               => (int) ($p['created'] ?? 0),
            'status'                => (string) ($p['status'] ?? ''),
            'automatic'             => !empty($p['automatic']),
            'method'                => (string) ($p['method'] ?? ''),
            'reconciliation_status' => (string) ($p['reconciliation_status'] ?? ''),
            'failure_code'          => (string) ($p['failure_code'] ?? ''),
            'failure_message'       => (string) ($p['failure_message'] ?? ''),
            // a bank account (bank name + last 4) or a debit card (brand + last 4); never more than the last 4
            'bank_name'             => substr((string) ($dest['bank_name'] ?? ($dest['brand'] ?? '')), 0, 60),
            'last4'                 => substr(preg_replace('/\D/', '', (string) ($dest['last4'] ?? '')), -4),
        ];
    }

    /** One balance transaction as a cached row (the payout's own transaction is left out). */
    private static function row(array $t, string $payout_id): ?array
    {
        $type = (string) ($t['type'] ?? '');
        if ($type === 'payout' && self::id_of($t['source'] ?? '') === $payout_id) return null;
        $src = is_array($t['source'] ?? null) ? $t['source'] : [];
        // the charge behind the row: the source itself (charge / payment) or the charge a refund or dispute names
        $charge = '';
        foreach ([self::id_of($t['source'] ?? ''), self::id_of($src['charge'] ?? '')] as $c) {
            if (preg_match('/^(ch|py)_[A-Za-z0-9]+$/', $c)) { $charge = $c; break; }
        }
        $is_charge = in_array((string) ($src['object'] ?? ''), ['charge'], true);
        return [
            'id'         => substr((string) ($t['id'] ?? ''), 0, 64),
            'created'    => (int) ($t['created'] ?? 0),
            'type'       => substr($type, 0, 64),
            'amount'     => (int) ($t['amount'] ?? 0),
            'fee'        => (int) ($t['fee'] ?? 0),
            'net'        => (int) ($t['net'] ?? 0),
            'charge'     => $charge,
            'customer'   => $is_charge ? self::id_of($src['customer'] ?? '') : '',
            'summer_reg' => $is_charge ? self::summer_reg($src) : 0,
        ];
    }

    /** Summer one-time payment: the registration id its Checkout put on the payment (iqu_kind = summer). */
    private static function summer_reg(array $charge): int
    {
        $m = (array) ($charge['metadata'] ?? []);
        return (($m['iqu_kind'] ?? '') === 'summer') ? max(0, (int) ($m['iqu_registration_id'] ?? 0)) : 0;
    }

    private static function id_of($v): string
    {
        $id = is_array($v) ? (string) ($v['id'] ?? '') : (is_string($v) ? $v : '');
        return preg_match('/^[a-z]+_[A-Za-z0-9]+$/', $id) ? $id : '';
    }

    private static function cache_key(string $payout_id): string
    {
        return self::CACHE . IQU_Stripe::expected_mode() . '_' . $payout_id;
    }

    private static function fail(string $error, string $code): array
    {
        return ['ok' => false, 'error' => $error, 'code' => $code];
    }

    /** Stripe's answer as an error for the screens: a refused permission in plain words. */
    private static function stripe_fail(array $r): array
    {
        if ((int) $r['status'] === 403) return self::fail(self::PERMISSION_NOTE, 'iqu_permission');
        return self::fail((string) ($r['error'] ?: 'Stripe request failed.'), (string) ($r['code'] ?: 'stripe_error'));
    }
}
