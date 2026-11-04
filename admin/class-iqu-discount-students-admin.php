<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Discount_Students_Admin
 *
 * "Zakat & Discount students" (3.7.0 Part AL), a section of the Coupons page below the coupons table (no tab, page or
 * menu item of its own — the header keeps its ten tabs): one row per student who EVER had a row in the discount ledger
 * (IQU_Discount_Ledger) — archived and ended students too; never anyone else. Per student: name, guardian, age or date
 * of birth, WhatsApp, program / course, the discount kind and fund of their latest counted month, its monthly amount,
 * first and last counted month, months counted and total given (all from the ledger), status.
 *
 * The cards (Zakat total, Special total, students) follow the Coupons page's range toolbar (lp / lfrom / lto) — the same
 * totals as its Zakat / Special cards (IQU_Discount_Ledger::totals). Filters (zd_kind, zd_fund, zd_status, zd_q: apart
 * from the coupon list's own), search; the shared pager.
 * CSV of the filtered rows with a totals line (IQU_CSV: formula-injection protection). manage_options + nonces.
 */
final class IQU_Discount_Students_Admin
{
    private const PAGE  = 'iqu-coupon-list';
    private const ANCHOR = 'iqu-zakat-students';
    private const CAP   = 'manage_options';
    public const A_CSV  = 'iqu_zakat_discount_csv';
    public const KINDS  = ['full' => 'Full scholarship', 'percent' => 'Percentage coupon', 'fixed' => 'Fixed coupon', 'admin' => 'Admin discount', 'agreed' => 'Agreed reduction', 'special' => 'Special discount'];
    public const FUNDS  = ['zakat' => 'Zakat', 'special' => 'Special'];
    public const STATES = ['active' => 'Active', 'ended' => 'Billing ended', 'archived' => 'Archived'];

    public static function init(): void
    {
        add_action('wp_ajax_' . self::A_CSV, [__CLASS__, 'handle_csv']);
    }

    // ------------------------------------------------------------
    // Data
    // ------------------------------------------------------------

    /** Every student who ever had a ledger row, newest counted month first. */
    public static function rows(): array
    {
        global $wpdb;
        if (!IQU_Discount_Ledger::ready()) return [];
        $ledger = $wpdb->get_results('SELECT registration_id, month, kind, reason, amount FROM ' . IQU_Discount_Ledger::table() . ' ORDER BY registration_id, month, id', ARRAY_A) ?: [];
        $by = [];
        foreach ($ledger as $l) {
            $id = (int) $l['registration_id'];
            $m  = substr((string) $l['month'], 0, 7) . '-01';
            if (!isset($by[$id])) $by[$id] = ['first' => $m, 'last' => $m, 'months' => [], 'total' => 0.0, 'latest' => $l];
            $by[$id]['last'] = $m;
            $by[$id]['months'][$m] = true;
            $by[$id]['total'] = round($by[$id]['total'] + (float) $l['amount'], 2);
            $by[$id]['latest'] = $l; // rows are in month order: the last one is the latest
        }
        if (!$by) return [];
        $ids = array_keys($by);
        $regs = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', array_map('intval', $chunk));
            foreach ($wpdb->get_results('SELECT * FROM ' . $wpdb->prefix . IQU_TABLE_NAME . " WHERE id IN ({$in})", ARRAY_A) ?: [] as $r) $regs[(int) $r['id']] = $r;
        }
        $coupon_ids = array_filter(array_map(fn($r) => (int) $r['coupon_id'], $regs));
        $types = [];
        if ($coupon_ids) foreach ($wpdb->get_results('SELECT id, discount_type FROM ' . IQU_Coupon_DB::table() . ' WHERE id IN (' . implode(',', array_unique($coupon_ids)) . ')', ARRAY_A) ?: [] as $c) $types[(int) $c['id']] = (string) $c['discount_type'];
        $out = [];
        foreach ($by as $id => $d) {
            $reg = $regs[$id] ?? null;
            $l = $d['latest'];
            $out[] = [
                'id'      => $id,
                'reg'     => $reg,
                'name'    => $reg ? trim($reg['first_name'] . ' ' . $reg['last_name']) : 'Student IQU-' . $id,
                'kind'    => self::kind_of($l, $reg, $types),
                'fund'    => (string) $l['kind'] === 'special' ? 'special' : 'zakat',
                'monthly' => round((float) $l['amount'], 2),
                'first'   => $d['first'],
                'last'    => $d['last'],
                'count'   => count($d['months']),
                'total'   => $d['total'],
                'status'  => self::state_of($reg),
            ];
        }
        usort($out, fn($a, $b) => [$b['last'], $b['id']] <=> [$a['last'], $a['id']]);
        return $out;
    }

    /** The kind of a ledger row, as the Coupons page breaks it down (IQU_Discount_Ledger::breakdown). */
    private static function kind_of(array $l, ?array $reg, array $types): string
    {
        if ((string) $l['reason'] === 'special_plan') return 'special';
        if ((string) $l['reason'] === 'agreed_fee') return 'agreed';
        if ((string) $l['reason'] === 'admin_discount') return 'admin';
        if ($reg && (float) $reg['payment_amount'] <= 0) return 'full';
        return ($reg && ($types[(int) $reg['coupon_id']] ?? '') === IQU_Coupon_DB::TYPE_FIXED) ? 'fixed' : 'percent';
    }

    private static function state_of(?array $reg): string
    {
        if (!$reg || (string) ($reg['archived_at'] ?? '') !== '') return 'archived';
        return IQU_Database::is_active_student($reg) ? 'active' : 'ended';
    }

    /** The rows for the request's filters (kind, fund, status, search). */
    public static function filtered(array $req): array
    {
        $f = self::filters($req);
        return array_values(array_filter(self::rows(), function ($r) use ($f) {
            if ($f['kind'] !== '' && $r['kind'] !== $f['kind']) return false;
            if ($f['fund'] !== '' && $r['fund'] !== $f['fund']) return false;
            if ($f['status'] !== '' && $r['status'] !== $f['status']) return false;
            if ($f['q'] === '') return true;
            $reg = $r['reg'] ?? [];
            $hay = strtolower(implode(' ', [$r['name'], $reg['guardian_name'] ?? '', $reg['email'] ?? '', $reg['whatsapp'] ?? '', $reg['guardian_whatsapp'] ?? '', 'iqu-' . $r['id'], '#' . $r['id']]));
            return strpos($hay, strtolower($f['q'])) !== false || (preg_match('/^(?:iqu[\s\-#]*|#)?(\d+)$/i', $f['q'], $m) && (int) $m[1] === $r['id']);
        }));
    }

    public static function filters(array $req): array
    {
        $pick = fn(string $k, array $allowed) => isset($allowed[$v = sanitize_key((string) ($req[$k] ?? ''))]) ? $v : '';
        return ['kind' => $pick('zd_kind', self::KINDS), 'fund' => $pick('zd_fund', self::FUNDS), 'status' => $pick('zd_status', self::STATES),
            'q' => trim(sanitize_text_field(wp_unslash((string) ($req['zd_q'] ?? ''))))];
    }

    // ------------------------------------------------------------
    // Page
    // ------------------------------------------------------------

    private static function age_or_dob(?array $reg): string
    {
        if (!$reg) return '—';
        $dob = (string) ($reg['date_of_birth'] ?? '');
        if ($dob !== '' && class_exists('IQU_Dob')) return IQU_Dob::format($dob);
        return (int) ($reg['age'] ?? 0) > 0 ? (int) $reg['age'] . ' (at registration)' : '—';
    }

    private static function program(?array $reg): string
    {
        if (!$reg) return '—';
        $type = ['free' => 'Monthly classes', 'weekend_new' => 'Weekend', 'weekend_existing' => 'Weekend', 'summer_level1' => 'Summer L1', 'summer_level2' => 'Summer L2'][(string) $reg['form_type']] ?? (string) $reg['form_type'];
        $course = (string) $reg['course_type'] !== '' && IQU_Pricing::is_valid_course((string) $reg['course_type']) ? IQU_Pricing::label((string) $reg['course_type']) : '';
        return $type . ($course !== '' ? ' · ' . $course : '');
    }

    private static function month(string $m): string
    {
        return gmdate('M Y', strtotime($m . ' 12:00:00 UTC'));
    }

    /** The Coupons page section, below the coupons table: cards for the page's range, filters, the students, CSV. */
    public static function render_section(): void
    {
        if (!current_user_can(self::CAP)) return;
        [$from, $to] = IQU_Discount_Ledger_Admin::range($_GET);
        $t      = IQU_Discount_Ledger::totals($from, $to);
        $f      = self::filters($_GET);
        $rows   = self::filtered($_GET);
        $range  = IQU_Discount_Ledger_Admin::month_range_label($from, $to);
        $keep   = [];
        foreach (['lp', 'lfrom', 'lto', 'status', 'search', 'course_type', 'per_page'] as $k) { // the page's range and the coupon list's own filters
            if (isset($_GET[$k]) && is_string($_GET[$k]) && $_GET[$k] !== '') $keep[$k] = sanitize_text_field(wp_unslash($_GET[$k]));
        }
        $named  = ['zd_kind' => $f['kind'], 'zd_fund' => $f['fund'], 'zd_status' => $f['status'], 'zd_q' => $f['q']];
        $csv    = add_query_arg(array_map('rawurlencode', ['action' => self::A_CSV, '_wpnonce' => wp_create_nonce(self::A_CSV)] + array_filter($named, fn($v) => $v !== '')), admin_url('admin-ajax.php'));
        $sum    = ['monthly' => array_sum(array_column($rows, 'monthly')), 'count' => array_sum(array_column($rows, 'count')), 'total' => array_sum(array_column($rows, 'total'))];
        ?>
    <section class="iqu-zakat-students" id="<?php echo esc_attr(self::ANCHOR); ?>" aria-labelledby="iqu-zd-title">
        <h2 class="iqu-zd-title" id="iqu-zd-title">Zakat &amp; Discount students</h2>
        <div class="iqu-metrics iqu-zd-cards">
            <?php foreach ([['zakat', 'heart', 'green', IQU_Pricing::format($t['zakat']['total']), 'Zakat given', $range],
                            ['special', 'percent', 'gold', IQU_Pricing::format($t['special']['total']), 'Special discounts', $range],
                            ['students', 'users', 'navy', (string) self::students_in_range($from, $to), 'Students', 'with a Zakat or special discount · ' . $range]] as [$k, $icon, $tone, $num, $label, $line]): ?>
            <div class="iqu-metric iqu-stat iqu-zd-card iqu-zd-card--<?php echo esc_attr($k); ?>" data-iqu-zd="<?php echo esc_attr($k); ?>">
                <?php echo IQU_Icons::stat_head($icon, $tone, esc_html($num)); ?>
                <div class="iqu-metric-lbl"><?php echo esc_html($label); ?></div>
                <div class="iqu-stat-sub"><?php echo esc_html($line); ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <form method="GET" action="<?php echo esc_url(admin_url('admin.php') . '#' . self::ANCHOR); ?>" class="iqu-filter-bar" role="search">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE); ?>">
            <?php foreach ($keep as $k => $v): ?><input type="hidden" name="<?php echo esc_attr($k); ?>" value="<?php echo esc_attr($v); ?>"><?php endforeach; ?>
            <input type="search" name="zd_q" value="<?php echo esc_attr($f['q']); ?>" placeholder="Search name, guardian, email, WhatsApp or IQU number…" aria-label="Search Zakat and discount students">
            <?php foreach (['zd_kind' => ['kind', 'All kinds', self::KINDS], 'zd_fund' => ['fund', 'All funds', self::FUNDS], 'zd_status' => ['status', 'All statuses', self::STATES]] as $name => [$key, $all, $opts]): ?>
            <select name="<?php echo esc_attr($name); ?>" aria-label="<?php echo esc_attr(ucfirst($key)); ?>">
                <option value=""><?php echo esc_html($all); ?></option>
                <?php foreach ($opts as $v => $label): ?><option value="<?php echo esc_attr($v); ?>"<?php selected($f[$key], $v); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
            </select>
            <?php endforeach; ?>
            <button type="submit" class="iqu-btn iqu-btn--secondary">Filter</button>
            <?php echo IQU_Export::choice(['label' => 'Export', 'class' => 'iqu-export-btn', 'icon' => '<span class="dashicons dashicons-download" aria-hidden="true"></span>', 'href' => $csv]); // CSV or Excel; escaped inside ?>
        </form>

        <div class="iqu-card iqu-list-card">
            <div class="iqu-card-head iqu-list-head">
                <div class="iqu-list-head-title"><span class="iqu-card-head-title">Students</span><span class="iqu-card-head-badge"><?php echo (int) count($rows); ?></span></div>
                <?php if ($rows) echo IQU_Pager::client_head('iqu-zd-tbl', 'Students per page'); ?>
            </div>
            <div class="iqu-table-wrap">
                <table class="iqu-tbl iqu-zd-tbl" id="iqu-zd-tbl"<?php echo $rows ? IQU_Pager::client_attrs('zd') : ''; ?>>
                    <thead><tr><th>Student</th><th>Guardian</th><th>Age / date of birth</th><th>WhatsApp</th><th>Program / course</th><th>Discount</th><th>Fund</th>
                        <th class="is-num">Monthly</th><th>First month</th><th>Last month</th><th class="is-num">Months</th><th class="is-num">Total given</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="13" class="iqu-empty-state"><?php echo ($f['q'] !== '' || $f['kind'] !== '' || $f['fund'] !== '' || $f['status'] !== '') ? 'No students match these filters.' : 'No student has had a Zakat or special discount counted yet.'; ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $r): $reg = $r['reg']; $wa = $reg ? ((string) $reg['whatsapp'] ?: (string) ($reg['guardian_whatsapp'] ?? '')) : ''; ?>
                        <tr data-iqu-zd-row="<?php echo (int) $r['id']; ?>">
                            <td><?php if ($reg): ?><a href="<?php echo esc_url(admin_url('admin.php?page=iqu-view-registration&id=' . (int) $r['id'])); ?>"><?php echo esc_html($r['name']); ?></a><?php else: echo esc_html($r['name']); endif; ?> <span class="iqu-ledger-id">IQU-<?php echo (int) $r['id']; ?></span></td>
                            <td><?php echo esc_html($reg && trim((string) $reg['guardian_name']) !== '' ? $reg['guardian_name'] : '—'); ?></td>
                            <td><?php echo esc_html(self::age_or_dob($reg)); ?></td>
                            <td><?php echo esc_html($wa !== '' ? $wa : '—'); ?></td>
                            <td><?php echo esc_html(self::program($reg)); ?></td>
                            <td><?php echo esc_html(self::KINDS[$r['kind']]); ?></td>
                            <td><?php echo esc_html(self::FUNDS[$r['fund']]); ?></td>
                            <td class="is-num"><?php echo esc_html(IQU_Pricing::format($r['monthly'])); ?></td>
                            <td><?php echo esc_html(self::month($r['first'])); ?></td>
                            <td><?php echo esc_html(self::month($r['last'])); ?></td>
                            <td class="is-num"><?php echo (int) $r['count']; ?></td>
                            <td class="is-num"><?php echo esc_html(IQU_Pricing::format($r['total'])); ?></td>
                            <td><span class="iqu-chip iqu-chip--<?php echo esc_attr(['active' => 'green', 'ended' => 'neutral', 'archived' => 'neutral'][$r['status']]); ?>"><?php echo esc_html(self::STATES[$r['status']]); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <?php if ($rows): ?>
                    <tfoot><tr><td colspan="7">Total (<?php echo (int) count($rows); ?> <?php echo count($rows) === 1 ? 'student' : 'students'; ?>)</td><td class="is-num"><?php echo esc_html(IQU_Pricing::format($sum['monthly'])); ?></td><td colspan="2"></td>
                        <td class="is-num"><?php echo (int) $sum['count']; ?></td><td class="is-num"><?php echo esc_html(IQU_Pricing::format($sum['total'])); ?></td><td></td></tr></tfoot>
                    <?php endif; ?>
                </table>
            </div>
            <?php if ($rows) echo IQU_Pager::client_foot('iqu-zd-tbl'); ?>
        </div>
    </section>
        <?php
    }

    /** Students with a ledger row in the months $from..$to (the "Students" card; Zakat or Special). */
    public static function students_in_range(string $from, string $to): int
    {
        global $wpdb;
        if (!IQU_Discount_Ledger::ready()) return 0;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(DISTINCT registration_id) FROM ' . IQU_Discount_Ledger::table() . ' WHERE month >= %s AND month <= %s', $from, $to));
    }

    // ------------------------------------------------------------
    // CSV: the filtered rows, then the totals
    // ------------------------------------------------------------

    public static function handle_csv(): void
    {
        if (!current_user_can(self::CAP)) wp_die('Permission denied.', 403);
        check_ajax_referer(self::A_CSV);
        nocache_headers();
        $out = IQU_Export::open('iqu-zakat-discount-students-' . current_time('Y-m-d') . '.csv'); // CSV as before, or Excel (format=xlsx)
        self::write_csv($out, self::filtered($_GET));
        IQU_Export::close($out);
        exit;
    }

    /** UTF-8 with BOM; every text cell through IQU_CSV (formula-injection protection); money as 2-decimal numbers. */
    public static function write_csv($out, array $rows): void
    {
        fputs($out, "\xEF\xBB\xBF");
        IQU_CSV::put($out, ['IQU no', 'Student', 'Guardian', 'Age / date of birth', 'WhatsApp', 'Program / course', 'Discount', 'Fund', 'Monthly', 'First month', 'Last month', 'Months', 'Total given', 'Status']);
        $n = fn(float $v) => number_format($v, 2, '.', '');
        foreach ($rows as $r) {
            $reg = $r['reg'];
            IQU_CSV::put($out, ['IQU-' . $r['id'], $r['name'], $reg ? (string) $reg['guardian_name'] : '', self::age_or_dob($reg), $reg ? ((string) $reg['whatsapp'] ?: (string) ($reg['guardian_whatsapp'] ?? '')) : '',
                self::program($reg), self::KINDS[$r['kind']], self::FUNDS[$r['fund']], $n($r['monthly']), substr($r['first'], 0, 7), substr($r['last'], 0, 7), (string) $r['count'], $n($r['total']), self::STATES[$r['status']]]);
        }
        IQU_CSV::put($out, ['Total', count($rows) . ' students', '', '', '', '', '', '', $n(array_sum(array_column($rows, 'monthly'))), '', '', (string) array_sum(array_column($rows, 'count')), $n(array_sum(array_column($rows, 'total'))), '']);
    }
}
