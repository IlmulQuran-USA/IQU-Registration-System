<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Discount_Ledger
 *
 * Zakat and Special Discount, month by month ({prefix}iqu_discount_ledger). One row per student, month and
 * fund ('zakat' | 'special'), with why it counts ('coupon' | 'special_plan' | 'agreed_fee') and the dollars.
 * Rows are written once a month and never recalculated, so a later fee change never alters past months.
 *
 *   ZAKAT   = Full Scholarship + Percentage Coupon + Fixed Amount Coupon (coupon_code / coupon_id)
 *   SPECIAL = the Special Discount of some course / day plans (special_discount)
 *   An agreed reduced fee, and an admin discount (discount_amount with no coupon, no special plan and no agreed
 *   fee: a student an admin added with a reduction), count under the fund an admin chose for it
 *   (agreed_fee_fund, "Fund for the reduction"); "not set" counts nowhere.
 *   The free first month of new students counts as neither.
 *
 * Amounts come from IQU_Billing_Pricing (the monthly discount of the row, as billed). A student billed at an
 * agreed fee is counted for that reduction only, never also for a coupon or special plan.
 *
 * Who and when:
 *   - a month counts while the student is active (IQU_Database::is_active_student: Free / Weekend "enrolled");
 *   - first month: the month the free first month ends (new students), else the registration month;
 *   - last month: the current month while active; for a student who left (status cancelled) the month of
 *     updated_at, marked "estimated end" (no leaving date is stored).
 *   - Summer is a one-time program, never active: it would count once. No Summer row stores a Zakat or Special
 *     discount today (the Summer form has admission-fee options only), so Summer adds nothing.
 *
 * Writing:
 *   - daily WP-Cron (CRON): the current month's row of every active student with a reduction (source 'monthly';
 *     safe to run any number of times);
 *   - "Calculate past discounts" on the Coupons page: preview() writes nothing, save_backfill() inserts the past
 *     months (source 'backfill') and never overwrites or duplicates a row (UNIQUE month + student + fund).
 */
final class IQU_Discount_Ledger
{
    public const DB_VERSION = '1.0.0';
    private const OPT_DB    = 'iqu_discount_ledger_db';   // DB_VERSION once the table, its columns and its key are verified
    public const CRON       = 'iqu_discount_ledger_daily';
    public const KINDS      = ['zakat', 'special'];
    public const REASONS    = ['coupon', 'special_plan', 'agreed_fee', 'admin_discount'];
    /** Reductions counted under the fund an admin chose ("Fund for the reduction"); not set = counted nowhere. */
    public const FUND_REASONS = ['agreed_fee', 'admin_discount'];
    /** 3.6.2: what the admin-discount migration did (ids only), stored once it has run. */
    public const OPT_ADMIN_DISCOUNT = 'iqu_ledger_admin_discount_362';
    /** Students who left: counted up to the month of updated_at ("estimated end"). Other statuses never started. */
    public const ENDED_STATUSES = ['cancelled'];
    /** Who changed the fund of an agreed reduction, and when (newest last; the last LOG_MAX changes). */
    private const OPT_FUND_LOG = 'iqu_agreed_fee_fund_log';
    private const LOG_MAX      = 500;

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'iqu_discount_ledger';
    }

    /** The CREATE TABLE statement (dbDelta format), used by maybe_install() and IQU_Schema. */
    public static function schemas(): array
    {
        global $wpdb;
        $t = self::table();
        return [$t => "CREATE TABLE {$t} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            month DATE NOT NULL,
            registration_id BIGINT(20) UNSIGNED NOT NULL,
            kind VARCHAR(10) NOT NULL,
            reason VARCHAR(20) NOT NULL DEFAULT '',
            amount DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            source VARCHAR(10) NOT NULL DEFAULT 'monthly',
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY month_reg_kind (month,registration_id,kind),
            KEY registration_id (registration_id)
        ) " . $wpdb->get_charset_collate() . ';'];
    }

    public static function ready(): bool
    {
        return get_option(self::OPT_DB) === self::DB_VERSION;
    }

    /**
     * Self-checking install: the table (apply_schema), then every column — `reason` added by an explicit ALTER
     * when it is missing — and the unique key, verified before the version is stored. Retried on the next load
     * until then; nothing is written to the table before it is ready.
     */
    public static function maybe_install(): void
    {
        global $wpdb;
        if (self::ready()) return;
        $t = self::table();
        foreach (self::schemas() as $table => $sql) IQU_Database::apply_schema($table, $sql);
        $cols = array_column((array) $wpdb->get_results("SHOW COLUMNS FROM {$t}", ARRAY_A), 'Field');
        if ($cols && !in_array('reason', $cols, true)) {
            $wpdb->query("ALTER TABLE {$t} ADD COLUMN reason VARCHAR(20) NOT NULL DEFAULT ''");
            $cols = array_column((array) $wpdb->get_results("SHOW COLUMNS FROM {$t}", ARRAY_A), 'Field');
        }
        $missing = array_diff(['id', 'month', 'registration_id', 'kind', 'reason', 'amount', 'source', 'created_at'], $cols);
        if ($missing || IQU_Schema::missing_any(self::schemas())) {
            error_log('[IQU] Discount ledger table not ready (' . implode(', ', $missing ?: ['index']) . '); will retry.');
            return;
        }
        update_option(self::OPT_DB, self::DB_VERSION, false);
    }

    public static function init(): void
    {
        add_action(self::CRON, [__CLASS__, 'run_monthly']);
    }

    public static function schedule(): void
    {
        if (!wp_next_scheduled(self::CRON)) wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON);
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::CRON);
    }

    // ------------------------------------------------------------
    // What a student's month counts as
    // ------------------------------------------------------------

    /**
     * The monthly reduction of a registration and where it counts: ['kind', 'reason', 'amount'] or null.
     * Monthly tuition (Enroll for Free) only.
     */
    public static function item_for(array $reg): ?array
    {
        if (($reg['form_type'] ?? '') !== IQU_Database::FORM_FREE) return null;
        $p = IQU_Billing_Pricing::for_registration($reg);
        $discount = round((float) ($p['discount'] ?? 0), 2);
        if ($discount <= 0) return null;
        $fund = self::fund_of($reg);
        if (IQU_Billing_Pricing::agreed_fee($reg) !== null) {
            // billed at the agreed fee: this reduction alone, under the fund the admin chose (none = not counted)
            return $fund ? ['kind' => $fund, 'reason' => 'agreed_fee', 'amount' => $discount] : null;
        }
        if (self::has_coupon($reg)) return ['kind' => 'zakat', 'reason' => 'coupon', 'amount' => $discount];
        if (!empty($reg['special_discount'])) return ['kind' => 'special', 'reason' => 'special_plan', 'amount' => $discount];
        // an admin discount (no coupon, no special plan, no agreed fee): under the fund the admin chose, like an agreed fee
        return $fund ? ['kind' => $fund, 'reason' => 'admin_discount', 'amount' => $discount] : null;
    }

    private static function has_coupon(array $reg): bool
    {
        return (string) ($reg['coupon_code'] ?? '') !== '' || (int) ($reg['coupon_id'] ?? 0) > 0;
    }

    /** The fund an admin chose for the reduction ('zakat' | 'special'), or null (not set). */
    private static function fund_of(array $reg): ?string
    {
        $fund = (string) ($reg['agreed_fee_fund'] ?? '');
        return in_array($fund, self::KINDS, true) ? $fund : null;
    }

    /** True when the registration is billed at an agreed fee below its normal price (the fund select applies). */
    public static function has_agreed_reduction(array $reg): bool
    {
        if (($reg['form_type'] ?? '') !== IQU_Database::FORM_FREE || IQU_Billing_Pricing::agreed_fee($reg) === null) return false;
        $p = IQU_Billing_Pricing::for_registration($reg);
        return isset($p['standard']) && (float) $p['discount'] > 0;
    }

    /** True for a reduction with no coupon, no special plan and no agreed fee (the fund select applies). */
    public static function has_admin_discount(array $reg): bool
    {
        if (($reg['form_type'] ?? '') !== IQU_Database::FORM_FREE || IQU_Billing_Pricing::agreed_fee($reg) !== null) return false;
        if (self::has_coupon($reg) || !empty($reg['special_discount'])) return false;
        return (float) (IQU_Billing_Pricing::for_registration($reg)['discount'] ?? 0) > 0;
    }

    /** The reduction an admin chooses the fund for: 'agreed_fee', 'admin_discount', or null (none here). */
    public static function fund_reason(array $reg): ?string
    {
        if (self::has_agreed_reduction($reg)) return 'agreed_fee';
        return self::has_admin_discount($reg) ? 'admin_discount' : null;
    }

    /** First month that counts (Y-m-01): the month the free first month ends, else the registration month. */
    public static function first_month(array $reg): string
    {
        $end = class_exists('IQU_Billing_Service') ? IQU_Billing_Service::free_month_end($reg) : null;
        if ($end) return substr($end, 0, 7) . '-01';
        $created = strtotime((string) ($reg['created_at'] ?? '') . ' UTC');
        return wp_date('Y-m-01', $created ?: time());
    }

    /** Last month that counts: [Y-m-01, estimated] — null for a student who never started (or is not monthly). */
    public static function last_month(array $reg): ?array
    {
        if (IQU_Database::is_active_student($reg)) return [self::current_month(), false];
        if (in_array((string) ($reg['status'] ?? ''), self::ENDED_STATUSES, true)) {
            $updated = strtotime((string) ($reg['updated_at'] ?? $reg['created_at'] ?? '') . ' UTC');
            return [wp_date('Y-m-01', $updated ?: time()), true];
        }
        return null;
    }

    public static function current_month(): string
    {
        return wp_date('Y-m-01');
    }

    /** Every month from $first to $last (Y-m-01), both included. */
    public static function months(string $first, string $last): array
    {
        $out = [];
        $utc = new DateTimeZone('UTC'); // calendar months, whatever the server's own time zone
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $first, $utc);
        while ($d && strcmp($d->format('Y-m-01'), $last) <= 0 && count($out) < 600) {
            $out[] = $d->format('Y-m-01');
            $d = $d->modify('first day of next month');
        }
        return $out;
    }

    // ------------------------------------------------------------
    // Writing
    // ------------------------------------------------------------

    /** The current month's rows of every active student with a reduction (daily; idempotent). Returns rows written. */
    public static function run_monthly(): int
    {
        if (!self::ready() || !class_exists('IQU_Billing_Pricing')) return 0;
        $month = self::current_month();
        $n = 0;
        foreach (self::current_items() as $reg_id => $item) $n += self::upsert($month, $reg_id, $item, 'monthly');
        return $n;
    }

    /** What counts this month: [registration id => item] of every active student past the free first month. */
    private static function current_items(): array
    {
        global $wpdb;
        $month = self::current_month();
        $regs  = $wpdb->get_results('SELECT * FROM ' . $wpdb->prefix . IQU_TABLE_NAME . ' WHERE form_type = \'' . IQU_Database::FORM_FREE . '\' AND ' . IQU_Database::active_student_sql() . ' ORDER BY id', ARRAY_A) ?: [];
        $out = [];
        foreach ($regs as $reg) {
            $item = self::item_for($reg);
            if (!$item || strcmp(self::first_month($reg), $month) > 0) continue; // nothing to count, or still in the free month
            $out[(int) $reg['id']] = $item;
        }
        return $out;
    }

    /**
     * Active students currently on a special discount (Coupons page card): the special plan, or an agreed fee /
     * admin discount with the fund Special — the same students as this month's Special rows of the daily job.
     */
    public static function special_students(): array
    {
        if (!class_exists('IQU_Billing_Pricing')) return [];
        return array_keys(array_filter(self::current_items(), fn($item) => $item['kind'] === 'special'));
    }

    /** The row of one month: 'monthly' replaces the amount of that month's row; 'backfill' only adds a missing row. */
    private static function upsert(string $month, int $reg_id, array $item, string $source): int
    {
        global $wpdb;
        $t = self::table();
        if ($source === 'monthly') {
            $wpdb->query($wpdb->prepare("UPDATE {$t} SET amount = %f, reason = %s, source = 'monthly' WHERE month = %s AND registration_id = %d AND kind = %s",
                $item['amount'], $item['reason'], $month, $reg_id, $item['kind']));
            if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE month = %s AND registration_id = %d AND kind = %s", $month, $reg_id, $item['kind']))) return 1;
        }
        return (int) $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$t} (month, registration_id, kind, reason, amount, source, created_at) VALUES (%s, %d, %s, %s, %f, %s, %s)",
            $month, $reg_id, $item['kind'], $item['reason'], $item['amount'], $source, current_time('mysql', true)
        ));
    }

    /**
     * What "Calculate past discounts" would write, without writing anything: one line per student (name, kind,
     * reason, first and last month, months, monthly amount, total, estimated end) and the totals per kind.
     * Also the students whose agreed reduction has no fund (not counted).
     */
    public static function preview(): array
    {
        global $wpdb;
        $out = ['rows' => [], 'totals' => ['zakat' => 0.0, 'special' => 0.0], 'students' => ['zakat' => 0, 'special' => 0], 'months' => 0, 'no_fund' => []];
        $regs = $wpdb->get_results('SELECT * FROM ' . $wpdb->prefix . IQU_TABLE_NAME . ' WHERE form_type = \'' . IQU_Database::FORM_FREE . '\' ORDER BY id', ARRAY_A) ?: [];
        foreach ($regs as $reg) {
            $last = self::last_month($reg);
            if (!$last) continue;
            $item = self::item_for($reg);
            if (!$item) {
                if (self::fund_reason($reg) !== null && self::fund_of($reg) === null) $out['no_fund'][] = (int) $reg['id'];
                continue;
            }
            $months = self::months(self::first_month($reg), $last[0]);
            if (!$months) continue;
            $total = round($item['amount'] * count($months), 2);
            $out['rows'][] = [
                'id' => (int) $reg['id'], 'name' => trim($reg['first_name'] . ' ' . $reg['last_name']), 'kind' => $item['kind'], 'reason' => $item['reason'],
                'first' => $months[0], 'last' => end($months), 'months' => $months, 'count' => count($months), 'monthly' => $item['amount'], 'total' => $total, 'estimated' => $last[1],
            ];
            $out['totals'][$item['kind']] = round($out['totals'][$item['kind']] + $total, 2);
            $out['students'][$item['kind']]++;
            $out['months'] += count($months);
        }
        return $out;
    }

    /** Writes the preview's months (source 'backfill'): a month already there is kept as it is. Returns rows added. */
    public static function save_backfill(): int
    {
        if (!self::ready()) return 0;
        $n = 0;
        foreach (self::preview()['rows'] as $r) {
            foreach ($r['months'] as $m) $n += self::upsert($m, $r['id'], ['kind' => $r['kind'], 'reason' => $r['reason'], 'amount' => $r['monthly']], 'backfill');
        }
        return $n;
    }

    // ------------------------------------------------------------
    // The fund of an agreed reduction
    // ------------------------------------------------------------

    /**
     * Set the fund of a student's agreed reduction or admin discount and move that student's rows of it with it
     * (all months; "not set" removes them: such a reduction counts nowhere). Reports only: no price, amount or
     * Stripe call.
     */
    public static function set_fund(int $reg_id, ?string $fund, int $user_id): bool
    {
        global $wpdb;
        $reg = IQU_Database::get_registration($reg_id);
        if (!$reg || self::fund_reason($reg) === null || ($fund !== null && !in_array($fund, self::KINDS, true))) return false;
        $old = self::fund_of($reg);
        if (!IQU_Database::set_agreed_fee_fund($reg_id, $fund)) return false;
        $moved = 0;
        if (self::ready() && $old !== $fund) {
            $t = self::table();
            $moved = (int) ($fund === null
                ? $wpdb->query($wpdb->prepare("DELETE FROM {$t} WHERE registration_id = %d AND reason IN ('agreed_fee', 'admin_discount')", $reg_id))
                : $wpdb->query($wpdb->prepare("UPDATE {$t} SET kind = %s WHERE registration_id = %d AND reason IN ('agreed_fee', 'admin_discount')", $fund, $reg_id)));
        }
        self::log_fund($reg_id, $old, $fund, $user_id, $moved);
        return true;
    }

    private static function log_fund(int $reg_id, ?string $old, ?string $fund, int $user_id, int $moved): void
    {
        $log = (array) get_option(self::OPT_FUND_LOG, []);
        $log[] = ['reg' => $reg_id, 'from' => $old, 'to' => $fund, 'user' => $user_id, 'at' => time(), 'rows' => $moved];
        update_option(self::OPT_FUND_LOG, array_slice($log, -self::LOG_MAX), false);
        error_log('[IQU] Reduction fund of registration #' . $reg_id . ': ' . ($old ?? 'not set') . ' → ' . ($fund ?? 'not set') . " by user #{$user_id} ({$moved} ledger rows).");
    }

    /**
     * 3.6.2, once: admin discounts were counted as coupons (a "paid by the Zakat Fund" tick was read as a coupon).
     * Each such student: a tick (zakat_declaration) becomes the fund "Zakat fund" (the admin's own choice when
     * adding the student; logged as user #0); their rows get the reason 'admin_discount'; with no fund their rows
     * are removed (re-created once an admin chooses one). The registration ids touched are stored in
     * OPT_ADMIN_DISCOUNT and logged. Waits until the ledger and the fund column are ready.
     */
    public static function migrate_admin_discounts(): void
    {
        global $wpdb;
        if (get_option(self::OPT_ADMIN_DISCOUNT, null) !== null || !self::ready() || !IQU_Database::agreed_fee_fund_ready() || !class_exists('IQU_Billing_Pricing')) return;
        $t = self::table();
        $report = ['fund_zakat' => [], 'relabelled' => [], 'removed' => [], 'at' => time()];
        $regs = $wpdb->get_results('SELECT * FROM ' . $wpdb->prefix . IQU_TABLE_NAME . ' WHERE form_type = \'' . IQU_Database::FORM_FREE . '\' AND discount_amount > 0 AND agreed_fee IS NULL ORDER BY id', ARRAY_A) ?: [];
        foreach ($regs as $reg) {
            if (!self::has_admin_discount($reg)) continue;
            $id = (int) $reg['id'];
            $fund = self::fund_of($reg);
            if ($fund === null && !empty($reg['zakat_declaration']) && IQU_Database::set_agreed_fee_fund($id, 'zakat')) {
                $fund = 'zakat';
                $report['fund_zakat'][] = $id;
                self::log_fund($id, null, 'zakat', 0, 0);
            }
            $n = (int) ($fund === null
                ? $wpdb->query($wpdb->prepare("DELETE FROM {$t} WHERE registration_id = %d AND reason IN ('coupon', 'admin_discount')", $id))
                : $wpdb->query($wpdb->prepare("UPDATE {$t} SET kind = %s, reason = 'admin_discount' WHERE registration_id = %d AND reason = 'coupon'", $fund, $id)));
            if ($n > 0) $report[$fund === null ? 'removed' : 'relabelled'][] = $id;
        }
        update_option(self::OPT_ADMIN_DISCOUNT, $report, false);
        error_log('[IQU] 3.6.2 admin discounts: fund Zakat from the tick #' . (implode(', #', $report['fund_zakat']) ?: '—') . '; rows relabelled #'
            . (implode(', #', $report['relabelled']) ?: '—') . '; rows removed (no fund) #' . (implode(', #', $report['removed']) ?: '—') . '.');
    }

    /** The last fund change of a registration: ['from', 'to', 'user', 'at', 'rows'] or null. */
    public static function last_fund_change(int $reg_id): ?array
    {
        foreach (array_reverse((array) get_option(self::OPT_FUND_LOG, [])) as $e) if ((int) ($e['reg'] ?? 0) === $reg_id) return $e;
        return null;
    }

    /** Active students with a reduced agreed fee or an admin discount and no fund chosen (not counted anywhere). */
    public static function students_without_fund(): array
    {
        global $wpdb;
        $regs = $wpdb->get_results('SELECT * FROM ' . $wpdb->prefix . IQU_TABLE_NAME . ' WHERE form_type = \'' . IQU_Database::FORM_FREE . '\' AND (agreed_fee IS NOT NULL OR discount_amount > 0) AND '
            . IQU_Database::active_student_sql() . ' ORDER BY id', ARRAY_A) ?: [];
        return array_values(array_filter($regs, fn($r) => self::fund_reason($r) !== null && self::fund_of($r) === null));
    }

    // ------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------

    /** The first month in the ledger (Y-m-01), or null when it is empty. */
    public static function first_stored_month(): ?string
    {
        global $wpdb;
        if (!self::ready()) return null;
        $m = $wpdb->get_var('SELECT MIN(month) FROM ' . self::table());
        return $m ? substr((string) $m, 0, 7) . '-01' : null;
    }

    /** "Total Discount Given" items, in the card's order. */
    public const BREAKDOWN = ['full', 'percent', 'fixed', 'admin', 'special'];

    /**
     * "Total Discount Given" for the months $from..$to (the same rows as totals(), so the two always agree):
     * 'total' and per item the amount and the number of students. Zakat coupons: full scholarship (the family pays
     * nothing), else percentage or fixed by the coupon's type (a deleted coupon counts as percentage, as before);
     * Zakat agreed fee / admin discount: 'admin'; every Special row: 'special'.
     */
    public static function breakdown(string $from, string $to): array
    {
        global $wpdb;
        $out = ['total' => 0.0];
        foreach (self::BREAKDOWN as $k) $out[$k] = ['amount' => 0.0, 'students' => 0];
        if (!self::ready()) return $out;
        $t = self::table();
        $r = $wpdb->prefix . IQU_TABLE_NAME;
        $c = IQU_Coupon_DB::table();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT l.registration_id, l.kind, l.reason, SUM(l.amount) AS amount, r.payment_amount, cp.discount_type
             FROM {$t} l LEFT JOIN {$r} r ON r.id = l.registration_id LEFT JOIN {$c} cp ON cp.id = r.coupon_id
             WHERE l.month >= %s AND l.month <= %s GROUP BY l.registration_id, l.kind, l.reason, r.payment_amount, cp.discount_type",
            $from, $to), ARRAY_A) ?: [];
        $who = [];
        foreach ($rows as $row) {
            if (!in_array($row['kind'], self::KINDS, true)) continue; // as totals()
            if ($row['kind'] === 'special') $k = 'special';
            elseif ($row['reason'] !== 'coupon') $k = 'admin';
            elseif ($row['payment_amount'] !== null && (float) $row['payment_amount'] <= 0) $k = 'full';
            else $k = $row['discount_type'] === IQU_Coupon_DB::TYPE_FIXED ? 'fixed' : 'percent';
            $out[$k]['amount'] = round($out[$k]['amount'] + (float) $row['amount'], 2);
            $out['total'] = round($out['total'] + (float) $row['amount'], 2);
            $who[$k][(int) $row['registration_id']] = true;
        }
        foreach (self::BREAKDOWN as $k) $out[$k]['students'] = count($who[$k] ?? []);
        return $out;
    }

    /** Discount given through each coupon in the months $from..$to: [coupon id => amount] (reason 'coupon' rows). */
    public static function coupon_totals(string $from, string $to): array
    {
        global $wpdb;
        if (!self::ready()) return [];
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT r.coupon_id, SUM(l.amount) AS amount FROM ' . self::table() . ' l JOIN ' . $wpdb->prefix . IQU_TABLE_NAME . " r ON r.id = l.registration_id
             WHERE l.reason = 'coupon' AND r.coupon_id > 0 AND l.month >= %s AND l.month <= %s GROUP BY r.coupon_id", $from, $to), ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $r) $out[(int) $r['coupon_id']] = round((float) $r['amount'], 2);
        return $out;
    }

    /**
     * Totals for the months $from..$to (Y-m-01, both included): per kind the total, the number of students and
     * the amount of each month (newest first).
     */
    public static function totals(string $from, string $to): array
    {
        global $wpdb;
        $out = [];
        foreach (self::KINDS as $k) $out[$k] = ['total' => 0.0, 'students' => 0, 'months' => []];
        if (!self::ready()) return $out;
        $t = self::table();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT month, kind, SUM(amount) AS amount FROM {$t} WHERE month >= %s AND month <= %s GROUP BY month, kind ORDER BY month DESC", $from, $to), ARRAY_A) ?: [];
        foreach ($rows as $r) {
            $k = (string) $r['kind'];
            if (!isset($out[$k])) continue;
            $m = substr((string) $r['month'], 0, 7) . '-01';
            $out[$k]['months'][$m] = round((float) $r['amount'], 2);
            $out[$k]['total'] = round($out[$k]['total'] + (float) $r['amount'], 2);
        }
        foreach ($wpdb->get_results($wpdb->prepare("SELECT kind, COUNT(DISTINCT registration_id) AS n FROM {$t} WHERE month >= %s AND month <= %s GROUP BY kind", $from, $to), ARRAY_A) ?: [] as $r) {
            if (isset($out[$r['kind']])) $out[$r['kind']]['students'] = (int) $r['n'];
        }
        return $out;
    }
}
