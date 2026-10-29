<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Discount_Ledger_Admin
 *
 * Coupons page, under "Total Discount Given": Zakat given and Special discounts for a range of months
 * (IQU_Discount_Ledger), the CSV, "Calculate past discounts" (preview, then confirm), and the list of students
 * whose reduced agreed fee has no fund. Student page: "Fund for the reduction" next to the agreed fee (AJAX).
 *
 * Every action: manage_options + its own nonce. Nothing here changes a price, an amount or a subscription.
 */
final class IQU_Discount_Ledger_Admin
{
    private const CAP       = 'manage_options';
    public const A_BACKFILL = 'iqu_ledger_backfill';
    public const A_CSV      = 'iqu_ledger_csv';
    public const A_FUND     = 'iqu_set_agreed_fee_fund';
    private const PAGE      = 'iqu-coupon-list';
    private const LABELS    = ['zakat' => 'Zakat fund', 'special' => 'Special discount'];
    private const REASONS   = ['coupon' => 'Coupon', 'special_plan' => 'Special plan', 'agreed_fee' => 'Agreed fee', 'admin_discount' => 'Admin discount'];

    public static function init(): void
    {
        add_action('admin_post_' . self::A_BACKFILL, [__CLASS__, 'handle_backfill']);
        add_action('wp_ajax_' . self::A_CSV, [__CLASS__, 'handle_csv']);
        add_action('wp_ajax_' . self::A_FUND, [__CLASS__, 'ajax_set_fund']);
    }

    // ------------------------------------------------------------
    // Range
    // ------------------------------------------------------------

    /** [from, to, preset] (Y-m-01): ?lp=year|last|all, or ?lfrom=YYYY-MM&lto=YYYY-MM; default this year. */
    public static function range(array $req): array
    {
        $cur  = IQU_Discount_Ledger::current_month();
        $year = (int) substr($cur, 0, 4);
        $lp   = sanitize_key((string) ($req['lp'] ?? ''));
        if ($lp === 'last') return [($year - 1) . '-01-01', ($year - 1) . '-12-01', 'last'];
        if ($lp === 'all') return [min(IQU_Discount_Ledger::first_stored_month() ?? $cur, $cur), $cur, 'all'];
        $from = self::month_param($req['lfrom'] ?? '');
        $to   = self::month_param($req['lto'] ?? '');
        if ($from === null && $to === null) return [$year . '-01-01', $cur, 'year'];
        $from = $from ?? $year . '-01-01';
        $to   = $to ?? $cur;
        if (strcmp($from, $to) > 0) [$from, $to] = [$to, $from];
        return [$from, $to, ($from === $year . '-01-01' && $to === $cur) ? 'year' : ''];
    }

    private static function month_param($v): ?string
    {
        $v = is_string($v) ? trim($v) : '';
        return preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $v) ? $v . '-01' : null;
    }

    private static function month_label(string $m, string $f = 'M Y'): string
    {
        return gmdate($f, strtotime($m . ' 12:00:00 UTC'));
    }

    private static function page_url(array $q = []): string
    {
        return add_query_arg(array_map('rawurlencode', ['page' => self::PAGE] + $q), admin_url('admin.php'));
    }

    // ------------------------------------------------------------
    // Coupons page section
    // ------------------------------------------------------------

    /** Below "Total Discount Given", above the state tabs. */
    public static function render_section(): void
    {
        if (!current_user_can(self::CAP)) return;
        [$from, $to, $preset] = self::range($_GET);
        $t      = IQU_Discount_Ledger::totals($from, $to);
        $cur    = IQU_Discount_Ledger::current_month();
        $first  = min(IQU_Discount_Ledger::first_stored_month() ?? $cur, ((int) substr($cur, 0, 4) - 1) . '-01-01');
        $months = array_reverse(IQU_Discount_Ledger::months($first, $cur));
        $q      = ['lfrom' => substr($from, 0, 7), 'lto' => substr($to, 0, 7)];
        $csv    = add_query_arg(['action' => self::A_CSV, '_wpnonce' => wp_create_nonce(self::A_CSV)] + $q, admin_url('admin-ajax.php'));
        $nofund = IQU_Database::agreed_fee_fund_ready() ? count(IQU_Discount_Ledger::students_without_fund()) : 0;
        $saved  = isset($_GET['ledger_saved']) ? absint($_GET['ledger_saved']) : null;
        ?>
    <section class="iqu-ledger" aria-label="Zakat and special discounts by month">
        <?php if ($saved !== null): ?><div class="notice notice-success inline"><p><?php echo esc_html($saved . ' past ' . ($saved === 1 ? 'month was' : 'months were') . ' saved.'); ?></p></div><?php endif; ?>
        <form method="GET" class="iqu-ledger-bar">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE); ?>">
            <span class="iqu-ledger-field"><label for="iqu-ledger-from">From</label>
            <select id="iqu-ledger-from" name="lfrom"><?php foreach ($months as $m): ?><option value="<?php echo esc_attr(substr($m, 0, 7)); ?>"<?php selected($m, $from); ?>><?php echo esc_html(self::month_label($m, 'F Y')); ?></option><?php endforeach; ?></select></span>
            <span class="iqu-ledger-field"><label for="iqu-ledger-to">To</label>
            <select id="iqu-ledger-to" name="lto"><?php foreach ($months as $m): ?><option value="<?php echo esc_attr(substr($m, 0, 7)); ?>"<?php selected($m, $to); ?>><?php echo esc_html(self::month_label($m, 'F Y')); ?></option><?php endforeach; ?></select></span>
            <button type="submit" class="iqu-btn iqu-btn--secondary">Show</button>
            <span class="iqu-ledger-presets" role="group" aria-label="Quick ranges">
                <?php foreach (['year' => 'This year', 'last' => 'Last year', 'all' => 'All time'] as $k => $label): ?>
                <a class="iqu-ledger-preset" href="<?php echo esc_url(self::page_url(['lp' => $k])); ?>"<?php echo $preset === $k ? ' aria-current="true"' : ''; ?>><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </span>
            <span class="iqu-ledger-actions">
                <a class="iqu-btn iqu-btn--secondary iqu-ledger-calc" href="<?php echo esc_url(self::page_url(['ledger' => 'preview'])); ?>">Calculate past discounts</a>
                <a class="iqu-export-btn" href="<?php echo esc_url($csv); ?>"><span class="dashicons dashicons-download" aria-hidden="true"></span>Export CSV</a>
            </span>
        </form>
        <?php if ($nofund > 0): ?>
        <p class="iqu-ledger-warn"><a href="<?php echo esc_url(self::page_url(['ledger' => 'nofund'])); ?>"><?php echo esc_html($nofund . ' ' . ($nofund === 1 ? 'student' : 'students') . ' with a reduced fee ' . ($nofund === 1 ? 'has' : 'have') . ' no fund selected.'); ?></a></p>
        <?php endif; ?>
        <div class="iqu-ledger-cards">
            <?php self::card('zakat', 'Zakat given', $t['zakat'], $from, $to); ?>
            <?php self::card('special', 'Special discounts', $t['special'], $from, $to); ?>
        </div>
    </section>
        <?php
    }

    private static function card(string $kind, string $title, array $d, string $from, string $to): void
    {
        $max = $d['months'] ? max($d['months']) : 0;
        $id  = 'iqu-ledger-' . $kind;
        $paged = count($d['months']) > 12;
        ?>
        <div class="iqu-card iqu-ledger-card iqu-ledger-card--<?php echo esc_attr($kind); ?>">
            <div class="iqu-ledger-accent" aria-hidden="true"></div>
            <div class="iqu-ledger-head">
                <span class="iqu-ledger-title"><?php echo esc_html($title); ?></span>
                <span class="iqu-ledger-range"><?php echo esc_html(self::month_label($from) . ($from !== $to ? ' – ' . self::month_label($to) : '')); ?></span>
            </div>
            <div class="iqu-ledger-total"><?php echo esc_html(IQU_Pricing::format($d['total'])); ?></div>
            <div class="iqu-ledger-sub"><?php echo esc_html($d['students'] . ' ' . ($d['students'] === 1 ? 'student' : 'students')); ?></div>
            <?php if (!$d['months']): ?>
            <p class="iqu-ledger-empty">Nothing in these months.</p>
            <?php else: ?>
            <?php if ($paged) echo IQU_Pager::client_head($id, 'Months per page'); // escaped inside ?>
            <table class="iqu-ledger-months" id="<?php echo esc_attr($id); ?>"<?php echo $paged ? IQU_Pager::client_attrs($kind === 'zakat' ? 'lz' : 'ls') : ''; ?>>
                <caption class="screen-reader-text"><?php echo esc_html($title . ' by month'); ?></caption>
                <tbody>
                <?php foreach ($d['months'] as $m => $amt): $w = $max > 0 ? max(1, (int) round($amt / $max * 100)) : 0; ?>
                    <tr>
                        <th scope="row"><?php echo esc_html(self::month_label($m)); ?></th>
                        <td class="is-num"><?php echo esc_html(IQU_Pricing::format($amt)); ?></td>
                        <td class="iqu-ledger-barcell"><span class="iqu-ledger-mbar" aria-hidden="true"><span style="width:<?php echo (int) $w; ?>%"></span></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($paged) echo IQU_Pager::client_foot($id); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    // ------------------------------------------------------------
    // "Calculate past discounts": preview (writes nothing), then confirm
    // ------------------------------------------------------------

    /** True when the Coupons page shows a ledger view (preview / no fund) instead of the coupon list. */
    public static function wants_view(): bool
    {
        return in_array(sanitize_key((string) ($_GET['ledger'] ?? '')), ['preview', 'nofund'], true);
    }

    public static function render_view(): void
    {
        if (!current_user_can(self::CAP)) wp_die('Permission denied.', 403);
        sanitize_key((string) $_GET['ledger']) === 'nofund' ? self::render_nofund() : self::render_preview();
    }

    private static function back_link(): string
    {
        return '<a href="' . esc_url(self::page_url()) . '" class="iqu-back-btn">← Back to Coupon List</a>';
    }

    private static function render_preview(): void
    {
        $p = IQU_Discount_Ledger::preview();
        ?>
    <div class="iqu-card iqu-list-card iqu-ledger-preview">
        <div class="iqu-card-head iqu-list-head"><div class="iqu-card-head-text">
            <span class="iqu-card-head-title">Calculate past discounts — preview</span>
            <p class="iqu-card-head-sub">Nothing is saved yet. Each student counts from the month their free first month ends (or the month they registered, without a free month) to this month; for a student who left, to the month their record last changed (estimated end). Months already saved are kept as they are.</p>
        </div></div>
        <div class="iqu-ledger-cards iqu-ledger-cards--preview">
            <?php foreach (['zakat' => 'Zakat given', 'special' => 'Special discounts'] as $k => $label): ?>
            <div class="iqu-card iqu-ledger-card iqu-ledger-card--<?php echo esc_attr($k); ?>"><div class="iqu-ledger-accent" aria-hidden="true"></div>
                <div class="iqu-ledger-head"><span class="iqu-ledger-title"><?php echo esc_html($label); ?></span><span class="iqu-ledger-range">all months</span></div>
                <div class="iqu-ledger-total"><?php echo esc_html(IQU_Pricing::format($p['totals'][$k])); ?></div>
                <div class="iqu-ledger-sub"><?php echo esc_html($p['students'][$k] . ' ' . ($p['students'][$k] === 1 ? 'student' : 'students')); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if ($p['no_fund']): ?><p class="iqu-ledger-warn"><a href="<?php echo esc_url(self::page_url(['ledger' => 'nofund'])); ?>"><?php echo esc_html(count($p['no_fund']) . ' ' . (count($p['no_fund']) === 1 ? 'student' : 'students') . ' with a reduced fee and no fund selected: not counted.'); ?></a></p><?php endif; ?>
        <div class="iqu-table-wrap"><table class="iqu-tbl iqu-ledger-preview-tbl">
            <thead><tr><th>Student</th><th>Fund</th><th>Why</th><th>First month</th><th>Last month</th><th class="is-num">Months</th><th class="is-num">Monthly</th><th class="is-num">Total</th></tr></thead>
            <tbody>
            <?php if (!$p['rows']): ?><tr><td colspan="8" class="iqu-ledger-empty">No student with a Zakat or special discount.</td></tr><?php endif; ?>
            <?php foreach ($p['rows'] as $r): ?>
                <tr>
                    <td><a href="<?php echo esc_url(admin_url('admin.php?page=iqu-view-registration&id=' . (int) $r['id'])); ?>"><?php echo esc_html($r['name']); ?></a> <span class="iqu-ledger-id">IQU-<?php echo (int) $r['id']; ?></span></td>
                    <td><?php echo esc_html(self::LABELS[$r['kind']]); ?></td>
                    <td><?php echo esc_html(self::REASONS[$r['reason']]); ?></td>
                    <td><?php echo esc_html(self::month_label($r['first'])); ?></td>
                    <td><?php echo esc_html(self::month_label($r['last'])); ?><?php if ($r['estimated']): ?> <span class="iqu-chip iqu-ledger-est">estimated end</span><?php endif; ?></td>
                    <td class="is-num"><?php echo (int) $r['count']; ?></td>
                    <td class="is-num"><?php echo esc_html(IQU_Pricing::format($r['monthly'])); ?></td>
                    <td class="is-num"><?php echo esc_html(IQU_Pricing::format($r['total'])); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td colspan="5">Total — Zakat <?php echo esc_html(IQU_Pricing::format($p['totals']['zakat'])); ?> · Special <?php echo esc_html(IQU_Pricing::format($p['totals']['special'])); ?></td><td class="is-num"><?php echo (int) $p['months']; ?></td><td></td><td class="is-num"><?php echo esc_html(IQU_Pricing::format($p['totals']['zakat'] + $p['totals']['special'])); ?></td></tr></tfoot>
        </table></div>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="iqu-cpn-actions">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::A_BACKFILL); ?>">
            <?php wp_nonce_field(self::A_BACKFILL); ?>
            <button type="submit" class="iqu-btn iqu-btn-primary"<?php disabled(!$p['rows'] || !IQU_Discount_Ledger::ready()); ?>>Confirm and save</button>
            <?php echo self::back_link(); // escaped inside ?>
        </form>
    </div>
        <?php
    }

    private static function render_nofund(): void
    {
        $rows = IQU_Database::agreed_fee_fund_ready() ? IQU_Discount_Ledger::students_without_fund() : [];
        ?>
    <div class="iqu-card iqu-list-card iqu-ledger-nofund">
        <div class="iqu-card-head iqu-list-head"><div class="iqu-card-head-text">
            <span class="iqu-card-head-title">Reduced fee, no fund selected (<?php echo count($rows); ?>)</span>
            <p class="iqu-card-head-sub">These reductions are not counted as Zakat or Special discount. Choose the fund on each student's page, next to the agreed fee.</p>
        </div></div>
        <div class="iqu-table-wrap"><table class="iqu-tbl">
            <thead><tr><th>Student</th><th>Why</th><th class="is-num">Normal price</th><th class="is-num">Reduced fee</th><th class="is-num">Reduction a month</th></tr></thead>
            <tbody>
            <?php if (!$rows): ?><tr><td colspan="5" class="iqu-ledger-empty">Every reduced fee has a fund.</td></tr><?php endif; ?>
            <?php foreach ($rows as $r): $pp = IQU_Billing_Pricing::for_registration($r); ?>
                <tr>
                    <td><a href="<?php echo esc_url(admin_url('admin.php?page=iqu-view-registration&id=' . (int) $r['id'])); ?>"><?php echo esc_html(trim($r['first_name'] . ' ' . $r['last_name'])); ?></a> <span class="iqu-ledger-id">IQU-<?php echo (int) $r['id']; ?></span></td>
                    <td><?php echo esc_html(self::REASONS[IQU_Discount_Ledger::fund_reason($r) ?? 'agreed_fee']); ?></td>
                    <td class="is-num"><?php echo esc_html(IQU_Pricing::format((float) ($pp['standard'] ?? $pp['gross']))); ?></td>
                    <td class="is-num"><?php echo esc_html(IQU_Pricing::format((float) ($pp['agreed'] ?? $pp['net']))); ?></td>
                    <td class="is-num"><?php echo esc_html(IQU_Pricing::format((float) $pp['discount'])); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <div class="iqu-cpn-actions"><?php echo self::back_link(); // escaped inside ?></div>
    </div>
        <?php
    }

    public static function handle_backfill(): void
    {
        if (!current_user_can(self::CAP)) wp_die('Permission denied.', 403);
        check_admin_referer(self::A_BACKFILL);
        $n = IQU_Discount_Ledger::save_backfill();
        error_log('[IQU] Past discounts saved by user #' . get_current_user_id() . ": {$n} months.");
        wp_safe_redirect(self::page_url(['ledger_saved' => (string) $n]));
        exit;
    }

    // ------------------------------------------------------------
    // CSV: one row per month (Month, Zakat, Special, Total), then the total
    // ------------------------------------------------------------

    public static function handle_csv(): void
    {
        if (!current_user_can(self::CAP)) wp_die('Permission denied.', 403);
        check_ajax_referer(self::A_CSV);
        [$from, $to] = self::range(wp_unslash($_GET));
        $out = fopen('php://output', 'w');
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="iqu-zakat-special-' . substr($from, 0, 7) . '-to-' . substr($to, 0, 7) . '.csv"');
        self::write_csv($out, $from, $to);
        fclose($out);
        exit;
    }

    /** UTF-8 with BOM; money as 2-decimal numbers; months oldest first. */
    public static function write_csv($out, string $from, string $to): void
    {
        $t = IQU_Discount_Ledger::totals($from, $to);
        fputs($out, "\xEF\xBB\xBF");
        IQU_CSV::put($out, ['Month', 'Zakat', 'Special', 'Total']);
        $sum = ['zakat' => 0.0, 'special' => 0.0];
        foreach (IQU_Discount_Ledger::months($from, $to) as $m) {
            $z = (float) ($t['zakat']['months'][$m] ?? 0);
            $s = (float) ($t['special']['months'][$m] ?? 0);
            $sum['zakat'] += $z;
            $sum['special'] += $s;
            IQU_CSV::put($out, [self::month_label($m, 'Y-m'), number_format($z, 2, '.', ''), number_format($s, 2, '.', ''), number_format($z + $s, 2, '.', '')]);
        }
        IQU_CSV::put($out, ['Total', number_format($sum['zakat'], 2, '.', ''), number_format($sum['special'], 2, '.', ''), number_format($sum['zakat'] + $sum['special'], 2, '.', '')]);
    }

    // ------------------------------------------------------------
    // Student page: "Fund for the reduction"
    // ------------------------------------------------------------

    /** The select next to the agreed fee, while the agreed fee is below the normal price or the student has an admin discount ('' otherwise). */
    public static function fund_select(array $row): string
    {
        if (!IQU_Database::agreed_fee_fund_ready() || IQU_Discount_Ledger::fund_reason($row) === null) return '';
        $id   = (int) $row['id'];
        $p    = IQU_Billing_Pricing::for_registration($row);
        $fund = in_array((string) ($row['agreed_fee_fund'] ?? ''), IQU_Discount_Ledger::KINDS, true) ? (string) $row['agreed_fee_fund'] : '';
        $html = '<div class="iqu-fund" data-iqu-fund data-id="' . $id . '" data-nonce="' . esc_attr(wp_create_nonce(self::A_FUND . '_' . $id)) . '" data-ajax="' . esc_url(admin_url('admin-ajax.php')) . '">'
            . '<label for="iqu-fund-' . $id . '">Fund for the reduction</label> '
            . '<select id="iqu-fund-' . $id . '" name="agreed_fee_fund">';
        foreach (['' => 'Not set', 'zakat' => 'Zakat fund', 'special' => 'Special discount'] as $v => $label) {
            $html .= '<option value="' . esc_attr($v) . '"' . selected($fund, $v, false) . '>' . esc_html($label) . '</option>';
        }
        $html .= '</select> <span class="iqu-fund-amt">' . esc_html(IQU_Pricing::format((float) $p['discount']) . ' a month') . '</span>'
            . ' <span class="iqu-fund-status" role="status" aria-live="polite"></span>'
            . '<small class="iqu-fund-changed">' . esc_html(self::changed_text($id)) . '</small></div>';
        return $html;
    }

    private static function changed_text(int $id): string
    {
        $e = IQU_Discount_Ledger::last_fund_change($id);
        if (!$e) return '';
        if ((int) $e['user'] === 0) return 'Set by the 3.6.2 update ("paid by the Zakat Fund" was ticked) on ' . wp_date('j M Y', (int) $e['at']);
        $u = get_userdata((int) $e['user']);
        return 'Changed by ' . ($u ? $u->display_name : 'user #' . (int) $e['user']) . ' on ' . wp_date('j M Y', (int) $e['at']);
    }

    /** POST id, fund ('' | zakat | special), _ajax_nonce: reports only — no price, amount or Stripe change. */
    public static function ajax_set_fund(): void
    {
        $id = absint($_POST['id'] ?? 0);
        if (!current_user_can(self::CAP)) wp_send_json_error(['message' => 'You do not have permission to do this.'], 403);
        if (!check_ajax_referer(self::A_FUND . '_' . $id, '_ajax_nonce', false)) wp_send_json_error(['message' => 'This page has expired. Please reload it.'], 403);
        $fund = sanitize_key(wp_unslash($_POST['fund'] ?? ''));
        if ($fund !== '' && !in_array($fund, IQU_Discount_Ledger::KINDS, true)) wp_send_json_error(['message' => 'Unknown fund.'], 400);
        if (!IQU_Discount_Ledger::set_fund($id, $fund === '' ? null : $fund, get_current_user_id())) {
            wp_send_json_error(['message' => 'The fund could not be saved.'], 400);
        }
        wp_send_json_success(['message' => 'Saved.', 'changed' => self::changed_text($id)]);
    }
}
