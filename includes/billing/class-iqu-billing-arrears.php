<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Billing_Arrears (3.7.14)
 *
 * When a family owes an earlier month, the next billing day charges that month together with the new one, in one
 * payment — never on any other day. At most ONE earlier month is collected that way; older months are left to the team
 * (Telegram), who can waive them or settle them by hand. One row per student and month (IQU_Billing_DB::arrears_table).
 *
 *   A. Card added after (or within 48 h of) a current family's first billing day: the payment page moves the first
 *      charge to the following billing day (IQU_Billing_Service, owed_from kept); when the page completes, the missed
 *      month goes on that first invoice as one invoice item per student (subscription + period = that month).
 *   B. A payment that failed and was never recovered: Stripe leaves the subscription "unpaid" and the next billing day's
 *      invoice in draft (nothing charged). The hourly job then voids the unpaid invoice (it can never be paid twice),
 *      adds its month to the draft as one line per student (still billed), and finalizes and charges the draft.
 *   C. Waive: an unpaid earlier month (A or B) is never charged — its pending line deleted, or its unpaid invoice voided.
 * Each month is counted once, in the month it belongs to: the history stores a combined invoice as one row per month
 * (IQU_Billing_History). A student who stops (Remove from billing, Left, Stop billing) is never charged an earlier month.
 * Statuses: due (to go on the next invoice), scheduled (on it, not finalized), charged, team (left to the team),
 * waived, paid (the family paid the old invoice itself), stopped (the student stopped: not charged).
 */
class IQU_Billing_Arrears
{
    public const MAX_EARLIER = 1;           // at most one earlier month collected with the billing day's month
    private const MAX_FAMILIES_PER_RUN = 10;
    private const OPEN = ['due', 'scheduled', 'team'];

    private static function table(): string
    {
        return IQU_Billing_DB::arrears_table();
    }

    private static function ready(): bool
    {
        return class_exists('IQU_Billing_DB') && IQU_Billing_DB::is_installed();
    }

    /** All rows of an account (current mode), oldest month first. */
    public static function rows(int $account_id, array $statuses = []): array
    {
        global $wpdb;
        if (!self::ready()) return [];
        $sql = 'SELECT * FROM ' . self::table() . ' WHERE account_id = %d AND mode = %s';
        $args = [$account_id, IQU_Stripe::expected_mode()];
        if ($statuses) { $sql .= ' AND status IN (' . implode(',', array_fill(0, count($statuses), '%s')) . ')'; $args = array_merge($args, $statuses); }
        return $wpdb->get_results($wpdb->prepare($sql . ' ORDER BY month, registration_id, id', ...$args), ARRAY_A) ?: [];
    }

    /** Insert a row once (student + month + account); returns the row (existing or new). */
    private static function upsert(array $r): ?array
    {
        global $wpdb;
        $now = current_time('mysql', true);
        $new = (bool) $wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . self::table() . ' (mode, account_id, registration_id, month, amount, source, old_invoice_id, status, created_at, updated_at) VALUES (%s, %d, %d, %s, %f, %s, %s, %s, %s, %s)',
            IQU_Stripe::expected_mode(), (int) $r['account_id'], (int) $r['registration_id'], $r['month'], round((float) $r['amount'], 2), $r['source'], (string) ($r['old_invoice_id'] ?? ''), $r['status'], $now, $now));
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE account_id = %d AND registration_id = %d AND month = %s AND mode = %s',
            (int) $r['account_id'], (int) $r['registration_id'], $r['month'], IQU_Stripe::expected_mode()), ARRAY_A) ?: null;
        if ($row) $row['_new'] = $new;
        return $row;
    }

    /** Change a row only while it is in one of $from (one UPDATE): true when this request changed it. */
    private static function move(int $id, array $from, array $set): bool
    {
        global $wpdb;
        $set['updated_at'] = current_time('mysql', true);
        $cols = implode(', ', array_map(fn($k) => "`{$k}` = %s", array_keys($set)));
        $in = implode(',', array_fill(0, count($from), '%s'));
        return (bool) $wpdb->query($wpdb->prepare('UPDATE ' . self::table() . " SET {$cols} WHERE id = %d AND status IN ({$in})", ...array_merge(array_values($set), [$id], $from)));
    }

    // ------------------------------------------------------------
    // Months and texts
    // ------------------------------------------------------------

    /** "2026-12-01" → "December 2026". */
    public static function month_name(string $ymd): string
    {
        $ts = strtotime(substr($ymd, 0, 7) . '-15 12:00:00 UTC');
        return $ts ? gmdate('F Y', $ts) : $ymd;
    }

    /** ["2026-12-01", "2027-01-01"] → "December 2026 and January 2027 tuition". */
    public static function months_label(array $months): string
    {
        $n = array_map([__CLASS__, 'month_name'], array_values(array_unique(array_map(fn($m) => substr($m, 0, 7), $months))));
        if (!$n) return '';
        $last = array_pop($n);
        return ($n ? implode(', ', $n) . ' and ' : '') . $last . ' tuition';
    }

    /** The family's billing days from $from (included) up to $to (excluded), Y-m-d. */
    public static function billing_days_between(string $from, string $to): array
    {
        $out = [];
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $from, new DateTimeZone('UTC'));
        $day = $d ? (int) $d->format('j') : 1;
        for ($i = 0; $d && $i < 36 && $d->format('Y-m-d') < $to; $i++) {
            $out[] = $d->format('Y-m-d');
            $n = $d->modify('first day of next month');
            $d = $n->setDate((int) $n->format('Y'), (int) $n->format('n'), min($day, (int) $n->format('t')));
        }
        return $out;
    }

    /**
     * A: the months a current family missed before their card (billing days from the first one they were given up to
     * the first charge the payment page set up). @return array{charged:?string, older:string[]} charged: the one month
     * collected with the first charge (the latest); older: left to the team.
     */
    public static function missed(array $acc): array
    {
        $from = (string) ($acc['owed_from'] ?? '');
        $to   = (string) ($acc['first_charge_date'] ?? '');
        if ($from === '' || $to === '' || $from >= $to || IQU_Billing_Service::has_free_first_month($acc)) return ['charged' => null, 'older' => []];
        $days = self::billing_days_between($from, $to);
        $charged = $days ? array_pop($days) : null;
        return ['charged' => $charged, 'older' => $days];
    }

    /**
     * The first payment as the family will see it: date (Y-m-d), amount (the month plus the earlier month collected with
     * it) and the months it covers. $month_cents: what one month charges (the payment page's line items).
     */
    public static function first_payment(array $acc, ?int $month_cents = null): array
    {
        $date = (string) ($acc['first_charge_date'] ?? '');
        $month = ($month_cents ?? IQU_Billing_Service::stored_cents($acc)) / 100;
        $months = $date !== '' ? [$date] : [];
        $extra = 0.0;
        $rows = self::rows((int) $acc['id'], ['due', 'scheduled']);
        if ($rows) {
            foreach ($rows as $r) { $extra += (float) $r['amount']; $months[] = (string) $r['month']; }
        } else {
            $m = self::missed($acc);
            if ($m['charged'] !== null) { $extra = $month; $months[] = $m['charged']; }
        }
        sort($months);
        return ['date' => $date, 'amount' => round($month + $extra, 2), 'months' => $months, 'earlier' => $extra > 0];
    }

    /**
     * The next payment of a family with a running subscription: the month plus an earlier month waiting on that invoice.
     * @return array{amount:float, months:string[], earlier:bool}
     */
    public static function next_payment(array $acc): array
    {
        $amount = (float) $acc['net_amount'];
        $next = !empty($acc['next_charge_at']) ? wp_date('Y-m-d', (int) strtotime($acc['next_charge_at'] . ' UTC')) : '';
        $months = $next !== '' ? [$next] : [];
        $extra = 0.0;
        foreach (self::rows((int) $acc['id'], ['due', 'scheduled']) as $r) { $extra += (float) $r['amount']; $months[] = (string) $r['month']; }
        sort($months);
        return ['amount' => round($amount + $extra, 2), 'months' => $months, 'earlier' => $extra > 0];
    }

    /** B: the sentence for a failed payment — it is charged with next month's tuition on the next billing day ('' when unknown). */
    public static function failed_sentence(array $acc): string
    {
        if (!in_array((string) $acc['status'], ['past_due', 'unpaid'], true) || empty($acc['next_charge_at'])) return '';
        return 'If it is still unpaid, it will be charged together with next month\'s tuition on ' . wp_date('j F Y', (int) strtotime($acc['next_charge_at'] . ' UTC')) . '.';
    }

    // ------------------------------------------------------------
    // A: the missed month on the first invoice
    // ------------------------------------------------------------

    /**
     * When a current family's payment page has completed (IQU_Billing_Webhook::finish_checkout, or the hourly retry):
     * record the missed months; the latest goes on the subscription's first invoice (one item per student), older ones
     * go to the team. Once only (one row per student and month; items with idempotency keys).
     */
    public static function schedule_missed(array $acc): void
    {
        try {
            if (!self::ready() || empty($acc['stripe_subscription_id'])) return;
            $m = self::missed($acc);
            if ($m['charged'] === null) return;
            $new_team = [];
            foreach (IQU_Billing_DB::get_members((int) $acc['id']) as $mem) {
                if (IQU_Billing_Service::is_deferred($mem) || (float) $mem['amount'] <= 0) continue;
                $reg = IQU_Database::get_registration((int) $mem['registration_id']);
                if (!$reg || !self::still_billed($acc, $reg)) continue;
                foreach ($m['older'] as $old) {
                    $r = self::upsert(['account_id' => $acc['id'], 'registration_id' => $reg['id'], 'month' => substr($old, 0, 7) . '-01', 'amount' => $mem['amount'], 'source' => 'missed', 'status' => 'team']);
                    if ($r && !empty($r['_new'])) $new_team[substr($old, 0, 7)] = true;
                }
                self::upsert(['account_id' => $acc['id'], 'registration_id' => $reg['id'], 'month' => substr($m['charged'], 0, 7) . '-01', 'amount' => $mem['amount'], 'source' => 'missed', 'status' => 'due']);
            }
            self::put_due_on_next_invoice($acc);
            if ($new_team && class_exists('IQU_Billing_Notify')) IQU_Billing_Notify::arrears_to_team(IQU_Billing_DB::get_account((int) $acc['id']) ?: $acc, array_keys($new_team), 'missed before the card was added');
        } catch (\Throwable $e) {
            error_log('[IQU] Missed month not scheduled for account #' . (int) ($acc['id'] ?? 0) . ': ' . get_class($e));
        }
    }

    /** "due" rows of source "missed" → one invoice item each on the subscription's next invoice. */
    private static function put_due_on_next_invoice(array $acc): void
    {
        foreach (self::rows((int) $acc['id'], ['due']) as $r) {
            if ($r['source'] !== 'missed') continue;
            $reg = IQU_Database::get_registration((int) $r['registration_id']);
            $item = self::create_item($acc, $r, $reg, ['subscription' => (string) $acc['stripe_subscription_id']]);
            if ($item !== '') self::move((int) $r['id'], ['due'], ['status' => 'scheduled', 'item_id' => $item]);
        }
    }

    /** One invoice item for one row; '' when Stripe refused (retried next hour). $where: subscription or invoice. */
    private static function create_item(array $acc, array $r, ?array $reg, array $where): string
    {
        $start = IQU_Billing_Service::charge_time(self::month_billing_day($acc, (string) $r['month']));
        $end   = IQU_Billing_Service::charge_time(self::next_month_day(self::month_billing_day($acc, (string) $r['month'])));
        $name  = $reg ? IQU_Billing_Service::short_name($reg) : 'student';
        $res = IQU_Stripe::post('/invoiceitems', $where + [
            'customer'    => (string) $acc['stripe_customer_id'],
            'amount'      => IQU_Billing_Pricing::to_cents((float) $r['amount']),
            'currency'    => 'usd',
            'description' => substr('Tuition - ' . $name . ', ' . self::month_name((string) $r['month']) . ' (not paid yet)', 0, 250),
            'period'      => ['start' => $start, 'end' => max($start + 1, $end)],
            'metadata'    => ['iqu_arrears_id' => (string) $r['id'], 'iqu_arrears_month' => substr((string) $r['month'], 0, 7), 'iqu_registration_id' => (string) $r['registration_id'], 'iqu_account_id' => (string) $acc['id'], 'iqu_mode' => IQU_Stripe::expected_mode()],
        ], 'iqu-arr-' . IQU_Stripe::expected_mode() . '-' . (int) $r['id'] . '-' . (isset($where['invoice']) ? $where['invoice'] : 'sub'));
        if (!$res['ok'] || empty($res['data']['id'])) { error_log('[IQU] Earlier month not added to the invoice (arrears #' . (int) $r['id'] . ', ' . ($res['code'] ?: 'error') . ')'); return ''; }
        return (string) $res['data']['id'];
    }

    /** The family's billing day in a month (Y-m-d), from its first charge date's day. */
    private static function month_billing_day(array $acc, string $month): string
    {
        $day = (int) substr((string) ($acc['first_charge_date'] ?: $acc['owed_from'] ?: '2000-01-01'), 8, 2) ?: 1;
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', substr($month, 0, 7) . '-01', new DateTimeZone('UTC'));
        return $d ? $d->setDate((int) $d->format('Y'), (int) $d->format('n'), min(max(1, $day), (int) $d->format('t')))->format('Y-m-d') : $month;
    }

    private static function next_month_day(string $ymd): string
    {
        return self::billing_days_between($ymd, gmdate('Y-m-d', (int) strtotime($ymd . ' 12:00:00 UTC +40 days')))[1] ?? $ymd;
    }

    /** The student is still billed on this account (not removed, not Left, billing not ended). */
    private static function still_billed(array $acc, array $reg): bool
    {
        if ((string) ($reg['left_at'] ?? '') !== '' || (string) ($reg['billing_ended_at'] ?? '') !== '') return false;
        if (class_exists('IQU_Billing_Stop') && isset(IQU_Billing_Stop::removed($acc)[(int) $reg['id']])) return false;
        return true;
    }

    // ------------------------------------------------------------
    // B: an unpaid invoice collected with the next billing day
    // ------------------------------------------------------------

    /** Hourly: the missed months whose item could not be made yet, and every unpaid family whose new invoice waits. */
    public static function run(): int
    {
        if (!self::ready() || !IQU_Stripe::is_ready()) return 0;
        global $wpdb;
        $done = 0;
        // A retry: a missed month still "due" on a family whose subscription exists
        $ids = $wpdb->get_col($wpdb->prepare('SELECT DISTINCT account_id FROM ' . self::table() . " WHERE mode = %s AND status = 'due' AND source = 'missed' LIMIT 20", IQU_Stripe::expected_mode())) ?: [];
        foreach ($ids as $id) { $acc = IQU_Billing_DB::get_account((int) $id); if ($acc && !empty($acc['stripe_subscription_id'])) self::put_due_on_next_invoice($acc); }
        // B
        $accs = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . IQU_Billing_DB::accounts_table() . " WHERE mode = %s AND status IN ('unpaid', 'past_due') AND stripe_subscription_id <> '' ORDER BY id LIMIT 200", IQU_Stripe::expected_mode()), ARRAY_A) ?: [];
        foreach ($accs as $acc) {
            if ($done >= self::MAX_FAMILIES_PER_RUN) break;
            if (self::combine($acc)) $done++;
        }
        return $done;
    }

    /** An invoice's lines (all pages), each with its month and student. */
    private static function lines(array $inv): array
    {
        $lines = (array) ($inv['lines']['data'] ?? []);
        if (!empty($inv['lines']['has_more'])) {
            $g = IQU_Stripe::get('/invoices/' . $inv['id'] . '/lines', ['limit' => 100]);
            if ($g['ok']) $lines = (array) ($g['data']['data'] ?? $lines);
        }
        return $lines;
    }

    /** [subscription item id => registration id] from the subscription's items (each line's product carries iqu_registration_id). */
    private static function item_students(string $sub): array
    {
        static $cache = [];
        if (isset($cache[$sub])) return $cache[$sub];
        $map = [];
        $g = IQU_Stripe::get('/subscriptions/' . rawurlencode($sub), ['expand' => ['items.data.price.product']]);
        if ($g['ok']) foreach ((array) ($g['data']['items']['data'] ?? []) as $it) $map[(string) ($it['id'] ?? '')] = (int) ($it['price']['product']['metadata']['iqu_registration_id'] ?? 0);
        foreach (IQU_Billing_DB::all_member_items() as $si => $rid) if (!isset($map[$si])) $map[$si] = $rid; // a removed student's deleted line
        return $cache[$sub] = $map;
    }

    /** [[month Y-m-01, registration_id, cents]] for one unpaid invoice's lines (an earlier line keeps its own month). */
    private static function owed_parts(array $inv): array
    {
        $out = [];
        $sub = (string) (is_array($inv['subscription'] ?? null) ? ($inv['subscription']['id'] ?? '') : ($inv['subscription'] ?? ($inv['parent']['subscription_details']['subscription'] ?? '')));
        foreach (self::lines($inv) as $l) {
            $cents = (int) ($l['amount'] ?? 0);
            if ($cents <= 0) continue;
            $meta = (array) ($l['metadata'] ?? []);
            $rid  = (int) ($meta['iqu_registration_id'] ?? 0);
            if (!$rid) {
                $si = (string) ($l['parent']['subscription_item_details']['subscription_item'] ?? ($l['subscription_item'] ?? ''));
                if ($si !== '' && $sub !== '') $rid = (int) (self::item_students($sub)[$si] ?? 0);
            }
            $ym = (string) ($meta['iqu_arrears_month'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
                $start = (int) ($l['period']['start'] ?? $inv['period_start'] ?? 0);
                $ym = $start ? IQU_Billing_History::month_of($start) : '';
            }
            if ($ym === '') continue;
            $out[] = [$ym . '-01', $rid, $cents];
        }
        return $out;
    }

    /**
     * B for one family, on its billing day: the new invoice waits in draft (Stripe leaves it there for an unpaid
     * subscription). Unpaid earlier invoices are voided first (so they can never be paid twice); the latest earlier month
     * goes on the draft (one line per student still billed), older months go to the team; then the draft is finalized and
     * charged — one payment. An earlier invoice paid meanwhile is left alone. True when it acted.
     */
    public static function combine(array $acc): bool
    {
        $sub = (string) $acc['stripe_subscription_id'];
        if (!preg_match('/^sub_[A-Za-z0-9]+$/', $sub)) return false;
        $d = IQU_Stripe::get('/invoices', ['subscription' => $sub, 'status' => 'draft', 'limit' => 3]);
        if (!$d['ok']) return false;
        $draft = null;
        foreach ((array) ($d['data']['data'] ?? []) as $i) if (in_array((string) ($i['billing_reason'] ?? ''), ['subscription_cycle', ''], true) && (!$draft || (int) $i['created'] > (int) $draft['created'])) $draft = $i;
        if (!$draft) return false;
        $old = [];
        foreach (['open', 'uncollectible'] as $st) {
            $g = IQU_Stripe::get('/invoices', ['subscription' => $sub, 'status' => $st, 'limit' => 10]);
            if ($g['ok']) foreach ((array) ($g['data']['data'] ?? []) as $i) if ((string) $i['id'] !== (string) $draft['id'] && (int) $i['created'] < (int) $draft['created'] && (int) ($i['amount_remaining'] ?? 0) > 0) $old[] = $i;
        }
        $already = self::rows((int) $acc['id'], ['due', 'scheduled']);
        if (!$old && !$already) return self::finalize($draft, $acc, false);

        // the months owed, per student still billed
        $parts = [];
        $removed = [];
        foreach ($old as $inv) {
            foreach (self::owed_parts($inv) as [$month, $rid, $cents]) {
                $reg = $rid ? IQU_Database::get_registration($rid) : null;
                if ($reg && !self::still_billed($acc, $reg)) { $removed[] = [$month, $rid, $cents, (string) $inv['id']]; continue; }
                $parts[$month][$rid] = ($parts[$month][$rid] ?? 0) + $cents;
                $parts[$month]['_inv'][$rid] = (string) $inv['id'];
            }
        }
        // 1) void every unpaid earlier invoice (a paid one in the meantime is left: its months are then not owed)
        $voided = [];
        foreach ($old as $inv) {
            $v = IQU_Stripe::post('/invoices/' . $inv['id'] . '/void', []);
            if ($v['ok']) { $voided[(string) $inv['id']] = true; continue; }
            $g = IQU_Stripe::get('/invoices/' . $inv['id']);
            if ($g['ok'] && (string) ($g['data']['status'] ?? '') === 'void') $voided[(string) $inv['id']] = true;
        }
        ksort($parts);
        $months = array_keys($parts);
        $latest = $months ? array_pop($months) : null;
        $new_team = [];
        foreach ($parts as $month => $students) {
            foreach ($students as $rid => $cents) {
                if ($rid === '_inv') continue;
                $inv_id = (string) ($students['_inv'][$rid] ?? '');
                if (!isset($voided[$inv_id])) continue; // not voided (paid meanwhile, or Stripe refused): never charged here
                $r = self::upsert(['account_id' => $acc['id'], 'registration_id' => $rid, 'month' => $month, 'amount' => $cents / 100, 'source' => 'unpaid', 'old_invoice_id' => $inv_id, 'status' => $month === $latest ? 'due' : 'team']);
                if ($r && $month !== $latest && !empty($r['_new'])) $new_team[substr($month, 0, 7)] = true;
            }
        }
        foreach ($removed as [$month, $rid, $cents, $inv_id]) {
            if (!isset($voided[$inv_id])) continue;
            self::upsert(['account_id' => $acc['id'], 'registration_id' => $rid, 'month' => $month, 'amount' => $cents / 100, 'source' => 'unpaid', 'old_invoice_id' => $inv_id, 'status' => 'stopped']);
        }
        foreach (array_keys($voided) as $vid) IQU_Billing_History::refresh_invoice($vid, $acc);
        // 2) the latest earlier month on the draft
        foreach (self::rows((int) $acc['id'], ['due']) as $r) {
            if ($r['source'] !== 'unpaid') continue;
            $reg = IQU_Database::get_registration((int) $r['registration_id']);
            $item = self::create_item($acc, $r, $reg, ['invoice' => (string) $draft['id']]);
            if ($item !== '') self::move((int) $r['id'], ['due'], ['status' => 'scheduled', 'item_id' => $item, 'charged_invoice_id' => (string) $draft['id']]);
        }
        if ($new_team && class_exists('IQU_Billing_Notify')) IQU_Billing_Notify::arrears_to_team($acc, array_keys($new_team), 'unpaid after a failed payment');
        // 3) finalize and charge the draft (only when nothing is left "due": a line that could not be added waits an hour)
        if (self::rows((int) $acc['id'], ['due'])) return true;
        return self::finalize($draft, $acc, true);
    }

    /** Finalize a waiting draft and charge it (Stripe leaves an unpaid subscription's invoices in draft). */
    private static function finalize(array $draft, array $acc, bool $acted): bool
    {
        if ((string) $acc['status'] !== 'unpaid' && !$acted) return false; // a past-due family's draft: Stripe finalizes it itself
        $f = IQU_Stripe::post('/invoices/' . $draft['id'] . '/finalize', ['auto_advance' => true]);
        if (!$f['ok']) { $g = IQU_Stripe::get('/invoices/' . $draft['id']); if (!$g['ok'] || (string) ($g['data']['status'] ?? '') === 'draft') return $acted; }
        IQU_Stripe::post('/invoices/' . $draft['id'] . '/pay', [], 'iqu-pay-' . $draft['id']);
        IQU_Billing_History::refresh_invoice((string) $draft['id'], $acc);
        return true;
    }

    /** When an invoice is stored: its earlier-month lines are now charged on it (they left "scheduled"). */
    public static function on_invoice_stored(array $inv): void
    {
        if (!self::ready() || in_array((string) ($inv['status'] ?? ''), ['draft', ''], true)) return;
        foreach ((array) ($inv['lines']['data'] ?? []) as $l) {
            $aid = (int) ($l['metadata']['iqu_arrears_id'] ?? 0);
            if ($aid) self::move($aid, ['scheduled', 'due'], ['status' => 'charged', 'charged_invoice_id' => (string) $inv['id']]);
        }
    }

    // ------------------------------------------------------------
    // Stop billing / Left / Remove from billing
    // ------------------------------------------------------------

    /** A student (or, $registration_id 0, the whole family) stopped: their earlier months still pending are never charged. */
    public static function on_stop(array $acc, int $registration_id = 0): void
    {
        if (!self::ready()) return;
        foreach (self::rows((int) $acc['id'], ['due', 'scheduled']) as $r) {
            if ($registration_id && (int) $r['registration_id'] !== $registration_id) continue;
            if ($r['status'] === 'scheduled' && $r['item_id'] !== '') {
                $del = IQU_Stripe::delete('/invoiceitems/' . rawurlencode($r['item_id']));
                if (!$del['ok']) { error_log('[IQU] Earlier month line not removed for arrears #' . (int) $r['id'] . ' (' . ($del['code'] ?: 'error') . ')'); continue; }
            }
            self::move((int) $r['id'], ['due', 'scheduled'], ['status' => 'stopped']);
        }
    }

    // ------------------------------------------------------------
    // C: Waive
    // ------------------------------------------------------------

    /**
     * Every unpaid earlier month of a family, for the family page: [month Y-m-01 => [amount, students[], status label,
     * waivable, invoice id]]. From the rows (A, B, team) and from an unpaid invoice not collected yet (B before its day).
     */
    public static function unpaid_months(array $acc): array
    {
        $out = [];
        $name = function (int $rid) { $r = $rid ? IQU_Database::get_registration($rid) : null; return $r ? trim($r['first_name'] . ' ' . $r['last_name']) : 'Family'; };
        $label = ['due' => 'Charged with the next billing day', 'scheduled' => 'Charged with the next billing day', 'team' => 'Left to the team'];
        foreach (self::rows((int) $acc['id'], self::OPEN) as $r) {
            $m = (string) $r['month'];
            $out[$m] = $out[$m] ?? ['amount' => 0.0, 'students' => [], 'status' => $label[$r['status']], 'waivable' => true, 'invoice' => ''];
            $out[$m]['amount'] = round($out[$m]['amount'] + (float) $r['amount'], 2);
            $out[$m]['students'][] = $name((int) $r['registration_id']);
            if ($r['status'] === 'team') $out[$m]['status'] = $label['team'];
        }
        foreach (IQU_Billing_History::for_account((int) $acc['id']) as $h) {
            if (!in_array((string) $h['status'], ['failed', 'uncollectible'], true) || strpos((string) $h['stripe_invoice_id'], '#') !== false) continue;
            $m = substr((string) $h['period_month'], 0, 7) . '-01';
            if (isset($out[$m]) || (float) $h['amount_due'] - (float) $h['amount_paid'] <= 0) continue;
            $out[$m] = ['amount' => round((float) $h['amount_due'] - (float) $h['amount_paid'], 2), 'students' => self::invoice_students($acc), 'status' => 'Not paid — charged with the next billing day if still unpaid', 'waivable' => true, 'invoice' => (string) $h['stripe_invoice_id']];
        }
        ksort($out);
        return $out;
    }

    private static function invoice_students(array $acc): array
    {
        $n = [];
        foreach (IQU_Billing_DB::get_members((int) $acc['id']) as $m) { $r = IQU_Database::get_registration((int) $m['registration_id']); if ($r) $n[] = trim($r['first_name'] . ' ' . $r['last_name']); }
        return $n;
    }

    /**
     * Waive one earlier month: never charged. A line already on the next invoice is deleted; an unpaid invoice is voided
     * in Stripe (closed without payment). Refused once that month has been charged (that is a refund). Once only.
     * @return array{ok:bool, message:string}
     */
    public static function waive(array $acc, string $month, string $reason, string $op = ''): array
    {
        $fail = fn(string $m) => ['ok' => false, 'message' => $m];
        if (!self::ready()) return $fail('Not ready yet (the database update is still running). Nothing was changed.');
        if (!preg_match('/^\d{4}-\d{2}-01$/', $month)) return $fail('Choose a month.');
        $reason = trim(mb_substr(sanitize_text_field($reason), 0, 200));
        if ($reason === '') return $fail('A reason is required.');
        $lock = 'iqu_waive_' . (int) $acc['id'] . '_' . $month;
        if (!add_option($lock, time(), '', 'no')) {
            if ((int) get_option($lock) > time() - 60) return $fail('This month is being waived right now.');
            update_option($lock, time(), 'no');
        }
        try {
            $all = array_filter(self::rows((int) $acc['id']), fn($r) => (string) $r['month'] === $month);
            if ($all && !array_filter($all, fn($r) => in_array($r['status'], self::OPEN, true))) {
                if (array_filter($all, fn($r) => $r['status'] === 'waived')) return ['ok' => true, 'message' => self::month_name($month) . ' is already waived.'];
                return $fail(self::month_name($month) . ' has already been charged (or settled), so it cannot be waived. Use a refund instead.');
            }
            $list = self::unpaid_months($acc);
            if (!isset($list[$month])) {
                foreach (IQU_Billing_History::for_account((int) $acc['id']) as $h) {
                    if (substr((string) $h['period_month'], 0, 7) . '-01' === $month && in_array((string) $h['status'], ['paid', 'refunded', 'disputed', 'processing'], true)) return $fail(self::month_name($month) . ' has already been charged, so it cannot be waived. Use a refund instead.');
                }
                return $fail(self::month_name($month) . ' is not an unpaid month of this family.');
            }
            $amount = $list[$month]['amount'];
            $ref = '';
            // an unpaid invoice not collected yet (B before its billing day): void it, and record the month as waived
            if ($list[$month]['invoice'] !== '' && !array_filter($all, fn($r) => in_array($r['status'], self::OPEN, true))) {
                $inv_id = $list[$month]['invoice'];
                $v = IQU_Stripe::post('/invoices/' . $inv_id . '/void', []);
                if (!$v['ok']) {
                    $g = IQU_Stripe::get('/invoices/' . $inv_id);
                    $st = $g['ok'] ? (string) ($g['data']['status'] ?? '') : '';
                    if ($st === 'paid') { IQU_Billing_History::refresh_invoice($inv_id, $acc); return $fail(self::month_name($month) . ' has just been paid, so it cannot be waived.'); }
                    if ($st !== 'void') return $fail('Stripe did not accept the change, so nothing was changed' . ($v['error'] !== '' ? ': ' . $v['error'] : '.'));
                }
                $g = IQU_Stripe::get('/invoices/' . $inv_id);
                $parts = $g['ok'] ? self::owed_parts((array) $g['data']) : [];
                $by = [];
                foreach ($parts as [$pm, $rid, $cents]) if ($pm === $month) $by[$rid] = ($by[$rid] ?? 0) + $cents;
                if (!$by) $by[0] = (int) round($amount * 100);
                foreach ($by as $rid => $cents) self::upsert(['account_id' => $acc['id'], 'registration_id' => $rid, 'month' => $month, 'amount' => $cents / 100, 'source' => 'unpaid', 'old_invoice_id' => $inv_id, 'status' => 'team']);
                $all = array_filter(self::rows((int) $acc['id']), fn($r) => (string) $r['month'] === $month);
                $ref = $inv_id;
            }
            // a line already on the next invoice: delete it first (if Stripe refuses, nothing is waived)
            foreach ($all as $r) {
                if ($r['status'] === 'scheduled' && $r['item_id'] !== '') {
                    $del = IQU_Stripe::delete('/invoiceitems/' . rawurlencode($r['item_id']));
                    if (!$del['ok'] && empty($del['data']['deleted'])) return $fail('Stripe did not accept the change, so nothing was changed' . ($del['error'] !== '' ? ': ' . $del['error'] : '.'));
                    $ref = $ref ?: (string) $r['item_id'];
                }
            }
            $n = 0;
            foreach ($all as $r) if (self::move((int) $r['id'], self::OPEN, ['status' => 'waived', 'reason' => $reason, 'user_id' => (string) get_current_user_id()])) $n++;
            if (!$n) return ['ok' => true, 'message' => self::month_name($month) . ' is already waived.'];
            self::record_waived_history($acc, $month);
            IQU_Billing_DB::log_action(['action' => 'waive_month', 'account_id' => (int) $acc['id'], 'amount' => $amount, 'reason' => substr(self::month_name($month) . ': ' . $reason . ($op !== '' ? ' · op ' . substr($op, 0, 32) : ''), 0, 255), 'stripe_ref' => $ref]);
            $user = wp_get_current_user();
            if (class_exists('IQU_Billing_Notify')) IQU_Billing_Notify::month_waived($acc, self::month_name($month), $amount, $reason, (string) ($user->display_name ?? ''));
            self::waived_email($acc, $month, $amount, $list[$month]['students']);
            return ['ok' => true, 'message' => self::month_name($month) . ' (' . IQU_Pricing::format($amount) . ') is waived and will never be charged.'];
        } finally {
            delete_option($lock);
        }
    }

    /** History shows the month as "Waived" with the reason: the voided invoice's own row, or one row for a month with no invoice. */
    public static function record_waived_history(array $acc, string $month): void
    {
        $rows = array_filter(self::rows((int) $acc['id'], ['waived']), fn($r) => (string) $r['month'] === $month);
        if (!$rows) return;
        $inv = '';
        foreach ($rows as $r) if ($r['old_invoice_id'] !== '') $inv = (string) $r['old_invoice_id'];
        if ($inv !== '') { IQU_Billing_History::refresh_invoice($inv, $acc); return; }
        IQU_Billing_History::save_waived((int) $acc['id'], substr($month, 0, 7), round(array_sum(array_map(fn($r) => (float) $r['amount'], $rows)), 2), (string) reset($rows)['reason']);
    }

    /** For a stored invoice: 'waived' (with the reason) when its month was waived, 'combined' when it was charged with a later one. */
    public static function invoice_fate(string $invoice_id): array
    {
        global $wpdb;
        if (!self::ready() || $invoice_id === '') return ['', ''];
        $rows = $wpdb->get_results($wpdb->prepare('SELECT status, reason, charged_invoice_id, month FROM ' . self::table() . ' WHERE old_invoice_id = %s AND mode = %s', $invoice_id, IQU_Stripe::expected_mode()), ARRAY_A) ?: [];
        if (!$rows) return ['', ''];
        foreach ($rows as $r) if ($r['status'] === 'waived') return ['waived', (string) $r['reason']];
        return ['combined', ''];
    }

    /** One email to the family: that month will not be charged. Test mode: own addresses only. */
    private static function waived_email(array $acc, string $month, float $amount, array $students): void
    {
        $to = (string) $acc['contact_email'];
        if (!is_email($to)) return;
        if (IQU_Stripe::expected_mode() === 'test') {
            $own = array_map('strtolower', array_filter([get_option('admin_email'), defined('IQU_CONTACT_EMAIL') ? IQU_CONTACT_EMAIL : '']));
            if (!in_array(strtolower(trim($to)), $own, true)) return;
        }
        $g = trim((string) $acc['guardian_name']);
        $m = self::month_name($month);
        IQU_Billing_Email::send($to, "{$m} tuition will not be charged — Ilm-Ul-Quran USA", [
            'title'     => "{$m} tuition will not be charged",
            'preheader' => "The unpaid tuition for {$m} has been waived.",
            'greeting'  => 'Assalamu alaikum' . ($g !== '' ? ' ' . explode(' ', $g)[0] : '') . ',',
            'private'   => true,
            'help'      => 'If you have any question, please talk to us.',
            'blocks'    => [
                ['p', "We wanted to let you know that the unpaid tuition for {$m} has been waived. It will not be charged, now or later."],
                ['table', [['Student' . (count($students) > 1 ? 's' : ''), implode(', ', $students)], ['Month', $m], ['Amount not charged', IQU_Pricing::format($amount)]]],
                ['button', 'Open my billing page', IQU_Billing_Service::link_for($acc)],
                ['list', 'Good to know', ['Your next tuition payments are not affected.']],
            ],
        ]);
    }
}
