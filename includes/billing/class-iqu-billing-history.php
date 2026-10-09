<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Billing_History
 *
 * Payment history: one row per Stripe invoice in {prefix}iqu_billing_payments —
 * paid, failed, open, void, uncollectible or refunded.
 *
 * Write side (calls Stripe): only from the webhook, Sync, Sync all and Backfill.
 * Read side (local database only): the admin screens and the family page.
 *
 * Security:
 * - Every query goes through $wpdb->prepare() and is limited to the current mode.
 * - An invoice is stored only when it belongs to the account's own Stripe customer.
 * - Receipt and invoice links are kept only when they point at invoice.stripe.com
 *   or pay.stripe.com.
 * - Card and bank details: brand or "US bank" and the last four digits, nothing more.
 * - The write side never throws, so it can never break the webhook flow.
 */
class IQU_Billing_History
{
    public const STATUSES = ['paid', 'failed', 'open', 'void', 'uncollectible', 'refunded'];

    /** Card brands Stripe reports, as shown in method labels. */
    private const CARD_BRANDS = ['Visa', 'Mastercard', 'Amex', 'American express', 'Discover', 'Diners', 'Jcb', 'Unionpay', 'Cartes_bancaires', 'Eftpos', 'Interac'];

    private const URL_PREFIXES = ['https://invoice.stripe.com/', 'https://pay.stripe.com/'];

    // ------------------------------------------------------------
    // Write side (Stripe)
    // ------------------------------------------------------------

    /**
     * Store one Stripe invoice as a history row (insert or update).
     *
     * @param array      $invoice Stripe invoice (ideally with "payments" expanded).
     * @param array      $acc     Our account; the invoice must belong to its customer.
     * @param array[]    $charges Stripe charges for this invoice's payments, newest first.
     */
    public static function upsert_from_invoice(array $invoice, array $acc, array $charges = []): bool
    {
        try {
            $row = self::row_from_invoice($invoice, $acc, $charges);
            return $row ? self::save($row) : false;
        } catch (\Throwable $e) {
            error_log('IQU Billing history: could not store invoice ' . self::safe_id($invoice['id'] ?? '') . ' — ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Read every invoice of one family from Stripe and store them.
     * @return int|null Number of invoices stored, or null when Stripe could not be read.
     */
    public static function refresh_account(array $acc): ?int
    {
        try {
            if (empty($acc['stripe_customer_id'])) return 0;
            $inv = IQU_Stripe::get('/invoices', [
                'customer' => $acc['stripe_customer_id'],
                'limit'    => 100,
                'expand'   => ['data.payments'],
            ]);
            if (!$inv['ok']) return null;
            $ch = IQU_Stripe::get('/charges', ['customer' => $acc['stripe_customer_id'], 'limit' => 100]);
            $by_pi = self::charges_by_intent($ch['ok'] ? (array) ($ch['data']['data'] ?? []) : []);

            $n = 0;
            foreach ((array) ($inv['data']['data'] ?? []) as $invoice) {
                $charges = [];
                foreach (self::intent_ids($invoice) as $pi) $charges = array_merge($charges, $by_pi[$pi] ?? []);
                if (self::upsert_from_invoice((array) $invoice, $acc, self::newest_first($charges))) $n++;
            }
            return $n;
        } catch (\Throwable $e) {
            error_log('IQU Billing history: refresh of account ' . (int) ($acc['id'] ?? 0) . ' failed — ' . $e->getMessage());
            return null;
        }
    }

    /** Read one invoice (and its charges) from Stripe and store it. */
    public static function refresh_invoice(string $invoice_id, array $acc): bool
    {
        try {
            if (!preg_match('/^in_[A-Za-z0-9]+$/', $invoice_id)) return false;
            $r = IQU_Stripe::get('/invoices/' . $invoice_id, ['expand' => ['payments']]);
            if (!$r['ok']) return false;
            $charges = [];
            foreach (self::intent_ids($r['data']) as $pi) {
                $c = IQU_Stripe::get('/charges', ['payment_intent' => $pi, 'limit' => 10]);
                if ($c['ok']) $charges = array_merge($charges, (array) ($c['data']['data'] ?? []));
            }
            return self::upsert_from_invoice($r['data'], $acc, self::newest_first($charges));
        } catch (\Throwable $e) {
            error_log('IQU Billing history: refresh of invoice ' . self::safe_id($invoice_id) . ' failed — ' . $e->getMessage());
            return false;
        }
    }

    /** The invoice a charge paid, found through its payment intent ('' when none). */
    public static function invoice_id_for_charge(array $charge): string
    {
        try {
            $pi = self::id_of($charge['payment_intent'] ?? '');
            if (!preg_match('/^pi_[A-Za-z0-9]+$/', $pi)) return '';
            $r = IQU_Stripe::get('/invoice_payments', [
                'payment' => ['type' => 'payment_intent', 'payment_intent' => $pi],
                'limit'   => 1,
            ]);
            if (!$r['ok']) return '';
            $id = self::id_of($r['data']['data'][0]['invoice'] ?? '');
            return preg_match('/^in_[A-Za-z0-9]+$/', $id) ? $id : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    // ------------------------------------------------------------
    // Normalising a Stripe invoice
    // ------------------------------------------------------------

    /** @return array|null the row to store, or null when the invoice is not stored (draft, other customer). */
    public static function row_from_invoice(array $invoice, array $acc, array $charges = []): ?array
    {
        $id = (string) ($invoice['id'] ?? '');
        if (!preg_match('/^in_[A-Za-z0-9]+$/', $id)) return null;
        if (self::id_of($invoice['customer'] ?? '') !== (string) $acc['stripe_customer_id'] || empty($acc['stripe_customer_id'])) return null;

        $stripe_status = (string) ($invoice['status'] ?? '');
        if (!in_array($stripe_status, ['paid', 'open', 'void', 'uncollectible'], true)) return null; // drafts are not history

        $due      = round(((int) ($invoice['amount_due'] ?? 0)) / 100, 2);
        $paid     = round(((int) ($invoice['amount_paid'] ?? 0)) / 100, 2);
        $attempts = max(0, (int) ($invoice['attempt_count'] ?? 0));

        $latest   = $charges[0] ?? null;
        $refunded = 0;
        foreach ($charges as $c) {
            if (($c['status'] ?? '') === 'succeeded') $refunded += (int) ($c['amount_refunded'] ?? 0);
        }
        $refunded = round($refunded / 100, 2);

        switch ($stripe_status) {
            case 'paid':
                $status = ($paid > 0 && $refunded >= $paid) ? 'refunded' : 'paid';
                break;
            case 'open':
                // A bank payment still processing is open, not failed.
                $pending = $latest && ($latest['status'] ?? '') === 'pending';
                $status  = ($attempts > 0 && !$pending) ? 'failed' : 'open';
                break;
            default:
                $status = $stripe_status;
        }

        $line  = (array) ($invoice['lines']['data'][0]['period'] ?? []);
        $start = (int) ($line['start'] ?? $invoice['period_start'] ?? 0);
        $end   = (int) ($line['end'] ?? $invoice['period_end'] ?? 0);
        $paid_ts = in_array($status, ['paid', 'refunded'], true) ? (int) ($invoice['status_transitions']['paid_at'] ?? 0) : 0;

        $failure = '';
        if ($status === 'failed' && $latest && ($latest['status'] ?? '') === 'failed') {
            $failure = (string) ($latest['failure_message'] ?? ($latest['outcome']['seller_message'] ?? ''));
        }

        $method = '';
        foreach ($charges as $c) {
            if (in_array($c['status'] ?? '', ['succeeded', 'pending'], true) || $status === 'failed') {
                $method = self::method_label((array) ($c['payment_method_details'] ?? []));
                if ($method !== '') break;
            }
        }
        if ($method === '' && in_array($status, ['paid', 'refunded'], true) && $paid > 0) {
            $method = (string) ($acc['payment_method_label'] ?? '');
        }

        return [
            'stripe_invoice_id'  => $id,
            'account_id'         => (int) $acc['id'],
            'amount'             => in_array($status, ['paid', 'refunded'], true) ? $paid : 0.0,
            'paid_at'            => $paid_ts ? gmdate('Y-m-d H:i:s', $paid_ts) : null,
            'status'             => $status,
            'period_month'       => $start ? self::month_of($start) : '',
            'period_start'       => $start ? gmdate('Y-m-d H:i:s', $start) : null,
            'period_end'         => $end ? gmdate('Y-m-d H:i:s', $end) : null,
            'amount_due'         => $due,
            'amount_paid'        => $paid,
            'amount_refunded'    => $refunded,
            'method_label'       => substr($method, 0, 60),
            'hosted_invoice_url' => self::safe_url($invoice['hosted_invoice_url'] ?? ''),
            'invoice_pdf'        => self::safe_url($invoice['invoice_pdf'] ?? ''),
            'receipt_number'     => substr((string) ($invoice['number'] ?? ''), 0, 64),
            'attempt_count'      => min($attempts, 65535),
            'failure_reason'     => substr($failure, 0, 255),
        ];
    }

    /** Insert or update one row. amount and created_at are always supplied (NOT NULL columns). */
    private static function save(array $r): bool
    {
        global $wpdb;
        $now  = current_time('mysql', true);
        $null = fn($v) => $v === null ? 'NULL' : '%s';

        $vals = [$r['stripe_invoice_id'], $r['account_id'], IQU_Stripe::expected_mode(), $r['amount']];
        if ($r['paid_at'] !== null) $vals[] = $r['paid_at'];
        $vals = array_merge($vals, [$now, $r['status'], $r['period_month']]);
        if ($r['period_start'] !== null) $vals[] = $r['period_start'];
        if ($r['period_end'] !== null) $vals[] = $r['period_end'];
        $vals = array_merge($vals, [
            $r['amount_due'], $r['amount_paid'], $r['amount_refunded'], $r['method_label'],
            $r['hosted_invoice_url'], $r['invoice_pdf'], $r['receipt_number'], $r['attempt_count'],
            $r['failure_reason'], $now,
        ]);

        $sql = 'INSERT INTO ' . IQU_Billing_DB::payments_table()
            . ' (stripe_invoice_id, account_id, mode, amount, paid_at, created_at, status, period_month, period_start, period_end,'
            . ' amount_due, amount_paid, amount_refunded, method_label, hosted_invoice_url, invoice_pdf, receipt_number, attempt_count, failure_reason, updated_at)'
            . ' VALUES (%s, %d, %s, %f, ' . $null($r['paid_at']) . ', %s, %s, %s, ' . $null($r['period_start']) . ', ' . $null($r['period_end']) . ','
            . ' %f, %f, %f, %s, %s, %s, %s, %d, %s, %s)'
            . ' ON DUPLICATE KEY UPDATE account_id = VALUES(account_id), amount = VALUES(amount), paid_at = VALUES(paid_at),'
            . ' status = VALUES(status), period_month = VALUES(period_month), period_start = VALUES(period_start), period_end = VALUES(period_end),'
            . ' amount_due = VALUES(amount_due), amount_paid = VALUES(amount_paid), amount_refunded = VALUES(amount_refunded),'
            . ' method_label = VALUES(method_label), hosted_invoice_url = VALUES(hosted_invoice_url), invoice_pdf = VALUES(invoice_pdf),'
            . ' receipt_number = VALUES(receipt_number), attempt_count = VALUES(attempt_count), failure_reason = VALUES(failure_reason),'
            . ' updated_at = VALUES(updated_at)';

        $ok = $wpdb->query($wpdb->prepare($sql, ...$vals));
        if ($ok === false) {
            error_log('IQU Billing history: database write failed for invoice ' . $r['stripe_invoice_id'] . ', account ' . (int) $r['account_id'] . ' — ' . $wpdb->last_error);
            return false;
        }
        return true;
    }

    /** "Visa •••• 4242" / "US bank •••• 6789". Never more than the last four digits. */
    public static function method_label(array $pmd): string
    {
        $type = (string) ($pmd['type'] ?? '');
        if ($type === 'card') {
            return ucfirst((string) ($pmd['card']['brand'] ?? 'Card')) . ' •••• ' . substr((string) ($pmd['card']['last4'] ?? ''), -4);
        }
        if ($type === 'us_bank_account') {
            return 'US bank •••• ' . substr((string) ($pmd['us_bank_account']['last4'] ?? ''), -4);
        }
        if ($type === 'link') return 'Link';
        return $type !== '' ? ucfirst(str_replace('_', ' ', $type)) : '';
    }

    /** Status badge for a history row: [label, chip tone] (tones: green, gold, red, neutral). */
    public static function badge(array $r): array
    {
        switch ((string) $r['status']) {
            case 'paid':          return (float) $r['amount_due'] <= 0 ? ['Free month', 'neutral'] : ['Paid', 'green'];
            case 'refunded':      return ['Refunded', 'neutral'];
            case 'failed':        return ['Failed', 'red'];
            case 'uncollectible': return ['Unpaid', 'red'];
            case 'open':          return ['Open', 'gold'];
            case 'void':          return ['Void', 'neutral'];
        }
        return [ucfirst((string) $r['status']), 'neutral'];
    }

    /** Group a method label for the Payments chart: card brand, "US bank", "Link" or "Other". */
    public static function method_group(string $label): string
    {
        $label = trim($label);
        if ($label === '') return 'Other';
        foreach (self::CARD_BRANDS as $b) {
            if (stripos($label, $b) === 0) return ucfirst(strtolower(str_replace('_', ' ', $b)));
        }
        if (stripos($label, 'US bank') === 0) return 'US bank';
        if (strcasecmp($label, 'Link') === 0) return 'Link';
        return strpos($label, '••••') !== false ? 'US bank' : 'Other'; // older labels use the bank's name
    }

    // ------------------------------------------------------------
    // Read side (local database only)
    // ------------------------------------------------------------

    /** Every history row of one family, newest tuition month first. */
    public static function for_account(int $account_id): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . IQU_Billing_DB::payments_table() . ' WHERE account_id = %d AND mode = %s ORDER BY period_month DESC, id DESC',
            $account_id, IQU_Stripe::expected_mode()
        ), ARRAY_A) ?: [];
    }

    /** History rows whose tuition month is between two 'YYYY-MM' values (inclusive). */
    public static function rows_for_months(string $from_ym, string $to_ym): array
    {
        global $wpdb;
        if (!self::is_month($from_ym) || !self::is_month($to_ym)) return [];
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . IQU_Billing_DB::payments_table() . ' WHERE mode = %s AND period_month >= %s AND period_month <= %s ORDER BY period_month, id',
            IQU_Stripe::expected_mode(), $from_ym, $to_ym
        ), ARRAY_A) ?: [];
    }

    /** All accounts of the current mode (small table: one row per family). */
    public static function accounts(): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . IQU_Billing_DB::accounts_table() . ' WHERE mode = %s ORDER BY id',
            IQU_Stripe::expected_mode()
        ), ARRAY_A) ?: [];
    }

    /** Families by billing status, e.g. ['active' => 12, 'past_due' => 1]. */
    public static function status_counts(?array $accounts = null): array
    {
        $out = [];
        foreach ($accounts ?? self::accounts() as $a) {
            $s = (string) $a['status'];
            $out[$s] = ($out[$s] ?? 0) + 1;
        }
        return $out;
    }

    /**
     * Numbers for one tuition month.
     *   billed      = amount due of the month's invoices, without void ones
     *   collected   = amount paid of the month's paid invoices
     *   outstanding = billed − collected, for failed / open / uncollectible invoices
     *   expected    = billed + monthly amount of families due in that month without an invoice yet
     */
    public static function month_summary(string $ym, array $rows, array $accounts): array
    {
        $s = ['month' => $ym, 'billed' => 0.0, 'collected' => 0.0, 'outstanding' => 0.0, 'refunded' => 0.0,
              'expected' => 0.0, 'rate' => null, 'paid_count' => 0, 'failed_count' => 0, 'invoices' => 0];
        $has_invoice = [];
        foreach ($rows as $r) {
            if ($r['period_month'] !== $ym) continue;
            $has_invoice[(int) $r['account_id']] = true;
            if ($r['status'] === 'void') continue;
            $due = (float) $r['amount_due'];
            $s['invoices']++;
            $s['billed']   += $due;
            $s['refunded'] += (float) $r['amount_refunded'];
            if ($r['status'] === 'paid') {
                $s['collected'] += (float) $r['amount_paid'];
                if ($due > 0) $s['paid_count']++;
            } elseif (in_array($r['status'], ['failed', 'open', 'uncollectible'], true)) {
                $s['outstanding'] += max(0.0, $due - (float) $r['amount_paid']);
                if ($r['status'] !== 'open' && $due > 0) $s['failed_count']++;
            }
        }
        $s['expected'] = $s['billed'];
        foreach ($accounts as $a) {
            if (!in_array($a['status'], ['free_month', 'waiting_first_charge', 'active'], true) || empty($a['next_charge_at'])) continue;
            if (isset($has_invoice[(int) $a['id']])) continue;
            if (self::month_of((int) strtotime($a['next_charge_at'] . ' UTC')) === $ym) $s['expected'] += (float) $a['net_amount'];
        }
        foreach (['billed', 'collected', 'outstanding', 'refunded', 'expected'] as $k) $s[$k] = round($s[$k], 2);
        $s['rate'] = $s['billed'] > 0 ? round($s['collected'] / $s['billed'] * 100, 1) : null;
        return $s;
    }

    /** Summaries for the $n months ending with $end_ym, oldest first. */
    public static function months(int $n, string $end_ym, ?array $rows = null, ?array $accounts = null): array
    {
        $list = self::month_list($end_ym, $n);
        if (!$list) return [];
        $rows     = $rows ?? self::rows_for_months($list[0], $end_ym);
        $accounts = $accounts ?? self::accounts();
        return array_map(fn($ym) => self::month_summary($ym, $rows, $accounts), $list);
    }

    /** Amount collected per payment-method group (card brands, US bank, …) for paid rows. */
    public static function method_split(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            if ($r['status'] !== 'paid' || (float) $r['amount_paid'] <= 0) continue;
            $g = self::method_group((string) $r['method_label']);
            $out[$g] = round(($out[$g] ?? 0) + (float) $r['amount_paid'], 2);
        }
        arsort($out);
        return $out;
    }

    /** Families with a charge due in the next $days days (from the local next_charge_at). */
    public static function upcoming(int $days, ?array $accounts = null, ?int $now = null): array
    {
        $now = $now ?? time();
        $out = [];
        foreach ($accounts ?? self::accounts() as $a) {
            if (!in_array($a['status'], ['free_month', 'waiting_first_charge', 'active'], true) || empty($a['next_charge_at'])) continue;
            $ts = (int) strtotime($a['next_charge_at'] . ' UTC');
            if ($ts >= $now && $ts < $now + $days * DAY_IN_SECONDS) $out[] = $a + ['_ts' => $ts];
        }
        usort($out, fn($x, $y) => $x['_ts'] <=> $y['_ts']);
        return $out;
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /** 'YYYY-MM' of a Unix time in the site's time zone. */
    public static function month_of(int $ts): string
    {
        return (new DateTimeImmutable('@' . $ts))->setTimezone(wp_timezone())->format('Y-m');
    }

    public static function is_month(string $ym): bool
    {
        return (bool) preg_match('/^(19|20)\d\d-(0[1-9]|1[0-2])$/', $ym);
    }

    /** The $n months ending with $end_ym, oldest first. */
    public static function month_list(string $end_ym, int $n): array
    {
        if (!self::is_month($end_ym) || $n < 1) return [];
        $d = new DateTimeImmutable($end_ym . '-01');
        $out = [];
        for ($i = $n - 1; $i >= 0; $i--) $out[] = $d->modify("-{$i} months")->format('Y-m');
        return $out;
    }

    /** "October 2026" from 'YYYY-MM'. */
    public static function month_label(string $ym): string
    {
        return self::is_month($ym) ? (new DateTimeImmutable($ym . '-01'))->format('F Y') : '—';
    }

    /** A Stripe URL we are willing to link to, or ''. */
    public static function safe_url($url): string
    {
        $url = (string) $url;
        foreach (self::URL_PREFIXES as $p) {
            if (strpos($url, $p) === 0 && strlen($url) <= 500) return $url;
        }
        return '';
    }

    /** Payment intent ids an invoice was paid with (from the expanded "payments" list). */
    private static function intent_ids(array $invoice): array
    {
        $ids = [];
        foreach ((array) ($invoice['payments']['data'] ?? []) as $p) {
            $pi = self::id_of($p['payment']['payment_intent'] ?? '');
            if (preg_match('/^pi_[A-Za-z0-9]+$/', $pi)) $ids[] = $pi;
        }
        return array_values(array_unique($ids));
    }

    private static function charges_by_intent(array $charges): array
    {
        $out = [];
        foreach ($charges as $c) {
            $pi = self::id_of($c['payment_intent'] ?? '');
            if ($pi !== '') $out[$pi][] = $c;
        }
        return $out;
    }

    private static function newest_first(array $charges): array
    {
        usort($charges, fn($a, $b) => (int) ($b['created'] ?? 0) <=> (int) ($a['created'] ?? 0));
        return $charges;
    }

    /** Stripe sends either an id or an expanded object. */
    private static function id_of($v): string
    {
        return is_array($v) ? (string) ($v['id'] ?? '') : (string) $v;
    }

    private static function safe_id($v): string
    {
        return preg_replace('/[^A-Za-z0-9_]/', '', (string) $v);
    }
}
