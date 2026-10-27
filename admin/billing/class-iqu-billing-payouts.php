<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Billing_Payouts
 *
 * IQU Registrations → Billing → Payouts: the bank deposits Stripe makes, and which family
 * payments are inside each one.
 *   list    (default)        payouts of the last 3 months, "Load more" for older ones
 *   detail  (?payout=po_…)   every transaction of one payout: family, students, type, gross, fee, net,
 *                            with the checks Gross − Fees − Refunds/Disputes = Net = payout amount
 * Data: IQU_Billing_Payout_Data (shared with the payout Telegram alert, so the numbers match).
 *
 * Security:
 * - manage_options only; the CSV download and Refresh are admin-post actions with their own nonces.
 * - The payout id is checked (po_…) before it reaches Stripe; "Load more" pages are bounded.
 * - Stripe is only read here (GET), never written.
 * - Families are matched in the local database, current mode only, in one query per page.
 * - CSV: UTF-8 with BOM, every cell through IQU_CSV (formula injection), money as 2-decimal numbers.
 * - All screen output is escaped.
 */
class IQU_Billing_Payouts
{
    public const SLUG       = 'iqu-billing-payouts';
    private const CAP       = 'manage_options';
    private const A_CSV     = 'iqu_billing_payout_csv';
    private const A_REFRESH = 'iqu_billing_payouts_refresh';
    private const MONTHS    = 3;  // the list starts with payouts arriving in the last 3 months
    private const MAX_PAGES = 10; // "Load more" up to 10 × 50 payouts

    private const STATUS = [
        'paid'       => ['Paid', 'green'],
        'in_transit' => ['In transit', 'blue'],
        'pending'    => ['Pending', 'gold'],
        'failed'     => ['Failed', 'red'],
        'canceled'   => ['Canceled', 'neutral'],
    ];

    public static function init(): void
    {
        add_action('admin_menu', [__CLASS__, 'register_menu'], 22);
        add_action('admin_post_' . self::A_CSV, [__CLASS__, 'handle_csv']);
        add_action('admin_post_' . self::A_REFRESH, [__CLASS__, 'handle_refresh']);
    }

    public static function register_menu(): void
    {
        add_submenu_page('iqu-registrations', 'Billing Payouts — IQU', 'Billing Payouts', self::CAP, self::SLUG, [__CLASS__, 'render']);
    }

    public static function url(string $payout_id = '', array $args = []): string
    {
        return add_query_arg(array_merge(['page' => self::SLUG], $payout_id !== '' ? ['payout' => $payout_id] : [], $args), admin_url('admin.php'));
    }

    // ------------------------------------------------------------
    // Screen
    // ------------------------------------------------------------

    public static function render(): void
    {
        if (!current_user_can(self::CAP)) wp_die('You do not have permission to view this page.', 403);
        $id = sanitize_text_field(wp_unslash($_GET['payout'] ?? ''));
        echo '<div class="wrap iqu-admin-wrap iqu-billing">';
        IQU_Billing_Page::tabs('payouts');
        if (!IQU_Stripe::is_ready()) {
            IQU_Billing_Page::page_header('Payouts', 'The bank deposits Stripe makes, and the family payments inside each one.');
            echo '<div class="notice notice-error inline"><p><strong>Not ready.</strong> ' . esc_html(IQU_Stripe::not_ready_reason()) . '</p></div></div>';
            return;
        }
        if ($id === '') {
            self::render_list();
        } elseif (!preg_match('/^po_[A-Za-z0-9]+$/', $id)) {
            IQU_Billing_Page::page_header('Payouts', 'The bank deposits Stripe makes, and the family payments inside each one.');
            echo '<div class="notice notice-error inline"><p>This is not a payout id.</p></div>'
                . '<p class="iqu-back-row"><a class="iqu-back-btn" href="' . esc_url(self::url()) . '">← All payouts</a></p>';
        } else {
            self::render_detail($id);
        }
        echo '</div>';
    }

    private static function render_list(): void
    {
        $tz    = wp_timezone();
        $since = (new DateTimeImmutable('today', $tz))->modify('-' . self::MONTHS . ' months')->getTimestamp();
        $pages = max(1, min(self::MAX_PAGES, absint($_GET['pages'] ?? 1)));

        $payouts = []; $more = false; $error = '';
        $after = '';
        for ($i = 0; $i < $pages; $i++) {
            $r = IQU_Billing_Payout_Data::payouts($since, $after);
            if (!$r['ok']) { $error = $r['error']; break; }
            $payouts = array_merge($payouts, $r['payouts']);
            $more = $r['has_more'];
            if (!$more || !$r['payouts']) break;
            $after = (string) end($r['payouts'])['id'];
        }
        // Stripe lists payouts by when they were created; people think in arrival dates.
        usort($payouts, fn($a, $b) => [$b['arrival_date'], $b['created']] <=> [$a['arrival_date'], $a['created']]);

        IQU_Billing_Page::page_header(
            'Payouts',
            'The bank deposits Stripe makes, and the family payments inside each one. Payouts arriving in the last ' . self::MONTHS . ' months.',
            self::refresh_button('') . (IQU_Stripe::expected_mode() === 'test' ? IQU_Billing_Page::mode_note('These are test payouts, not real money.') : '')
        );
        if ($error !== '') {
            echo '<div class="notice notice-error inline"><p>' . esc_html($error) . '</p></div>';
            return;
        }
        if (array_filter($payouts, fn($p) => !$p['automatic'])) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html(IQU_Billing_Payout_Data::MANUAL_NOTE) . '</p></div>';
        }
        self::list_cards($payouts, $tz);

        $tid = 'iqu-t-payouts';
        echo '<div class="iqu-card iqu-list-card"><div class="iqu-card-head iqu-list-head"><div class="iqu-card-head-text">'
            . '<span class="iqu-card-head-title">Payouts</span>'
            . '<p class="iqu-card-head-sub">Newest arrival first. Open a payout to see the family payments, refunds and fees inside it.</p>'
            . '</div></div>';
        if (!$payouts) {
            echo '<div class="iqu-empty-state">No payouts in the last ' . (int) self::MONTHS . ' months.</div></div>';
            return;
        }
        echo '<div class="iqu-table-wrap"><table class="iqu-tbl iqu-billing-tbl" id="' . esc_attr($tid) . '"><thead><tr>'
            . '<th>Arrival date</th><th class="is-num">Amount</th><th>Status</th><th>Bank</th><th class="is-num">Transactions</th><th aria-label="Open"></th>'
            . '</tr></thead><tbody>';
        foreach ($payouts as $p) {
            [$label, $tone] = self::STATUS[$p['status']] ?? [ucfirst(str_replace('_', ' ', $p['status'])), 'neutral'];
            $count = IQU_Billing_Payout_Data::cached_count($p['id']);
            echo '<tr>'
                . '<td class="iqu-td-date">' . esc_html($p['arrival_date'] ? wp_date('j M Y', $p['arrival_date']) : '—') . '</td>'
                . '<td class="is-num">' . esc_html(IQU_Pricing::format($p['amount'] / 100)) . '</td>'
                . '<td><span class="iqu-chip iqu-chip--' . esc_attr($tone) . '">' . esc_html($label) . '</span></td>'
                . '<td>' . esc_html(self::bank($p)) . '</td>'
                . '<td class="is-num">' . ($count === null ? '<span title="' . esc_attr('Shown once the payout has been opened') . '">—</span>' : (int) $count) . '</td>'
                . '<td><a class="iqu-action-btn" href="' . esc_url(self::url($p['id'])) . '">View</a></td>'
                . '</tr>';
        }
        echo '</tbody></table></div>';
        if ($more && $pages < self::MAX_PAGES) {
            echo '<p class="iqu-load-more"><a class="iqu-btn iqu-btn--secondary" href="' . esc_url(self::url('', ['pages' => $pages + 1])) . '">Load more</a></p>';
        }
        echo '</div>';
    }

    /** Paid out this month, last month (arrival date, site time zone), and the next payout. */
    private static function list_cards(array $payouts, DateTimeZone $tz): void
    {
        $this_m = (new DateTimeImmutable('now', $tz))->format('Y-m');
        $last_m = (new DateTimeImmutable('first day of last month', $tz))->format('Y-m');
        $sum = [$this_m => 0, $last_m => 0]; $n = [$this_m => 0, $last_m => 0];
        $next = null;
        foreach ($payouts as $p) {
            $ym = wp_date('Y-m', $p['arrival_date']);
            if ($p['status'] === 'paid' && isset($sum[$ym])) { $sum[$ym] += $p['amount']; $n[$ym]++; }
            if (in_array($p['status'], ['pending', 'in_transit'], true) && ($next === null || $p['arrival_date'] < $next['arrival_date'])) $next = $p;
        }
        $cards = [
            ['Paid out this month', IQU_Pricing::format($sum[$this_m] / 100), $sum[$this_m] ? 'green' : 'neutral', $n[$this_m] . ' payout' . ($n[$this_m] === 1 ? '' : 's') . ' · ' . wp_date('F Y'), 'banknote'],
            ['Paid out last month', IQU_Pricing::format($sum[$last_m] / 100), $sum[$last_m] ? 'blue' : 'neutral', $n[$last_m] . ' payout' . ($n[$last_m] === 1 ? '' : 's') . ' · ' . wp_date('F Y', (new DateTimeImmutable('first day of last month', $tz))->getTimestamp()), 'calendar'],
            ['Next payout', $next ? IQU_Pricing::format($next['amount'] / 100) : '—', $next ? 'gold' : 'neutral', $next ? 'Expected ' . wp_date('j M Y', $next['arrival_date']) : 'None pending', 'clock'],
        ];
        echo '<div class="iqu-rep-kpis" style="--iqu-kpi-n:' . count($cards) . '">';
        foreach ($cards as [$label, $value, $tone, $sub, $icon]) {
            echo '<div class="iqu-metric iqu-stat iqu-rep-kpi iqu-rep-kpi--' . esc_attr($tone) . '"><div class="iqu-metric-accent"></div>'
                . IQU_Icons::stat_head($icon, $tone, esc_html($value)) . '<div class="iqu-metric-lbl">' . esc_html($label) . '</div>'
                . '<div class="iqu-metric-sub">' . esc_html($sub) . '</div></div>';
        }
        echo '</div>';
    }

    private static function render_detail(string $id): void
    {
        $r = IQU_Billing_Payout_Data::summary($id);
        $p = $r['payout'];
        $title = $p ? 'Payout of ' . IQU_Pricing::format($p['amount'] / 100) . ($p['arrival_date'] ? ' — ' . wp_date('j M Y', $p['arrival_date']) : '') : 'Payout';
        $actions = self::refresh_button($id);
        if ($r['ok']) {
            $actions .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
                . '<input type="hidden" name="action" value="' . esc_attr(self::A_CSV) . '">'
                . '<input type="hidden" name="payout" value="' . esc_attr($id) . '">'
                . wp_nonce_field(self::A_CSV, '_wpnonce', true, false)
                . '<button type="submit" class="iqu-btn iqu-btn--primary">' . IQU_Billing_Page::icon('download') . 'Download CSV</button></form>';
        }
        echo '<p class="iqu-back-row"><a class="iqu-back-btn" href="' . esc_url(self::url()) . '">← All payouts</a></p>';
        IQU_Billing_Page::page_header($title, 'Every payment, refund, dispute and fee inside this bank deposit.', $actions);
        if ($p) self::facts($p);
        if (!$r['ok']) {
            $tone = in_array($r['code'], ['iqu_manual', 'iqu_not_reconciled'], true) ? 'warning' : 'error';
            echo '<div class="notice notice-' . $tone . ' inline"><p>' . esc_html($r['error']) . '</p></div>';
            return;
        }

        $s = $r['summary'];
        $t = $s['totals'];
        self::detail_cards($t);
        self::checks($t, (int) $p['amount']);

        $rows = self::display_rows($s);
        echo '<div class="iqu-card iqu-list-card"><div class="iqu-card-head iqu-list-head"><div class="iqu-card-head-text">'
            . '<span class="iqu-card-head-title">Transactions (' . count($rows) . ')</span>'
            . '<p class="iqu-card-head-sub">Gross is what the family paid (negative for money that went back), the Stripe fee is what Stripe kept, net is what reached the bank.</p>'
            . '</div></div>';
        if (!$rows) {
            echo '<div class="iqu-empty-state">No transactions in this payout.</div></div>';
            return;
        }
        echo '<div class="iqu-table-wrap"><table class="iqu-tbl iqu-billing-tbl"><thead><tr>'
            . '<th>Date</th><th>Family</th><th>Students</th><th>Type</th><th class="is-num">Gross</th><th class="is-num">Stripe fee</th><th class="is-num">Net</th>'
            . '</tr></thead><tbody>';
        foreach ($rows as $row) {
            if ($row['account']) {
                $fam = '<a class="iqu-family-link" href="' . esc_url(IQU_Billing_Page::family_url($row['account'])) . '">' . esc_html($row['family']) . '</a>';
            } elseif ($row['customer'] !== '' && $row['family'] === 'Not in IQU') {
                $fam = '<span class="iqu-chip iqu-chip--gold">Not in IQU</span> <code>' . esc_html($row['customer']) . '</code>';
            } else {
                $fam = esc_html($row['family'] !== '' ? $row['family'] : '—');
            }
            echo '<tr>'
                . '<td class="iqu-td-date">' . esc_html($row['created'] ? wp_date('j M Y', $row['created']) : '—') . '</td>'
                . '<td class="iqu-td-name">' . $fam . '</td>'
                . '<td class="iqu-billing-wrap">' . esc_html($row['students'] !== '' ? $row['students'] : '—') . '</td>'
                . '<td>' . esc_html($row['type_label']) . '</td>'
                . '<td class="is-num">' . esc_html(self::money($row['amount'])) . '</td>'
                . '<td class="is-num">' . esc_html(self::money($row['fee'])) . '</td>'
                . '<td class="is-num">' . esc_html(self::money($row['net'])) . '</td>'
                . '</tr>';
        }
        echo '</tbody><tfoot><tr><td colspan="4">Total (' . count($rows) . ')</td>'
            . '<td class="is-num">' . esc_html(self::money(array_sum(array_column($rows, 'amount')))) . '</td>'
            . '<td class="is-num">' . esc_html(self::money(array_sum(array_column($rows, 'fee')))) . '</td>'
            . '<td class="is-num">' . esc_html(self::money(array_sum(array_column($rows, 'net')))) . '</td>'
            . '</tr></tfoot></table></div></div>';
    }

    /** The payout's own facts: status, arrival, bank, Stripe link. */
    private static function facts(array $p): void
    {
        [$label, $tone] = self::STATUS[$p['status']] ?? [ucfirst(str_replace('_', ' ', $p['status'])), 'neutral'];
        $dash = 'https://dashboard.stripe.com/' . (IQU_Stripe::expected_mode() === 'test' ? 'test/' : '') . 'payouts/' . rawurlencode($p['id']);
        echo '<div class="iqu-card"><div class="iqu-billing-body"><div class="iqu-detail-data">'
            . '<div class="iqu-detail-item"><div class="iqu-detail-lbl">Status</div><div class="iqu-detail-val"><span class="iqu-chip iqu-chip--' . esc_attr($tone) . '">' . esc_html($label) . '</span></div></div>'
            . '<div class="iqu-detail-item"><div class="iqu-detail-lbl">Arrival date</div><div class="iqu-detail-val">' . esc_html($p['arrival_date'] ? wp_date('j M Y', $p['arrival_date']) : '—') . '</div></div>'
            . '<div class="iqu-detail-item"><div class="iqu-detail-lbl">Bank</div><div class="iqu-detail-val">' . esc_html(self::bank($p)) . '</div></div>'
            . '<div class="iqu-detail-item"><div class="iqu-detail-lbl">Stripe</div><div class="iqu-detail-val"><code>' . esc_html($p['id']) . '</code> · <a href="' . esc_url($dash) . '" target="_blank" rel="noopener noreferrer">Open in Stripe</a></div></div>'
            . ($p['status'] === 'failed' && $p['failure_message'] !== '' ? '<div class="iqu-detail-item"><div class="iqu-detail-lbl">Why it failed</div><div class="iqu-detail-val">' . esc_html($p['failure_message']) . '</div></div>' : '')
            . '</div></div></div>';
    }

    private static function detail_cards(array $t): void
    {
        $cards = [
            ['Gross', self::money($t['gross']), 'green', $t['payments'] . ' family payment' . ($t['payments'] === 1 ? '' : 's'), 'banknote'],
            ['Stripe fees', self::money($t['fees']), 'neutral', 'Kept by Stripe', 'receipt'],
            ['Refunds / disputes', self::money($t['refunds']), $t['refunds'] ? 'purple' : 'neutral', $t['refund_rows'] . ' transaction' . ($t['refund_rows'] === 1 ? '' : 's'), 'rotate-ccw'],
            ['Net', self::money($t['net']), 'blue', 'Sent to the bank', 'wallet'],
        ];
        if ($t['other'] !== 0) $cards[] = ['Other', self::money($t['other']), 'gold', 'Other Stripe adjustments', 'info'];
        echo '<div class="iqu-rep-kpis" style="--iqu-kpi-n:' . count($cards) . '">';
        foreach ($cards as [$label, $value, $tone, $sub, $icon]) {
            echo '<div class="iqu-metric iqu-stat iqu-rep-kpi iqu-rep-kpi--' . esc_attr($tone) . '"><div class="iqu-metric-accent"></div>'
                . IQU_Icons::stat_head($icon, $tone, esc_html($value)) . '<div class="iqu-metric-lbl">' . esc_html($label) . '</div>'
                . '<div class="iqu-metric-sub">' . esc_html($sub) . '</div></div>';
        }
        echo '</div>';
    }

    /** Gross − Fees − Refunds/Disputes (+ Other) = Net, and Net = the payout amount; a clear warning otherwise. */
    private static function checks(array $t, int $payout_amount): void
    {
        $other = $t['other'] !== 0 ? ' + Other' : '';
        if ($t['formula_diff'] !== 0) {
            echo '<div class="notice notice-error inline"><p><strong>The totals do not add up.</strong> Gross − Stripe fees − Refunds/disputes' . esc_html($other) . ' = '
                . esc_html(self::money($t['net'] + $t['formula_diff'])) . ', but Net is ' . esc_html(self::money($t['net'])) . ' (difference ' . esc_html(self::money($t['formula_diff'])) . '). Please check this payout in Stripe.</p></div>';
        }
        if ($t['payout_diff'] !== 0) {
            echo '<div class="notice notice-error inline"><p><strong>Net does not match the payout.</strong> The transactions add up to ' . esc_html(self::money($t['net']))
                . ', but Stripe paid out ' . esc_html(self::money($payout_amount)) . ' (difference ' . esc_html(self::money($t['payout_diff'])) . '). Please check this payout in Stripe.</p></div>';
        }
        if ($t['formula_diff'] === 0 && $t['payout_diff'] === 0) {
            echo '<p class="iqu-billing-intro iqu-payout-check" role="status">Checked: Gross − Stripe fees − Refunds/disputes' . esc_html($other) . ' = Net = the payout amount.</p>';
        }
    }

    /**
     * The summary's rows with family, students and a type label (local database, current mode, one query each).
     * @return array[] created, family, account, customer, students, type_label, amount, fee, net (cents), txn
     */
    private static function display_rows(array $summary): array
    {
        $rows     = (array) $summary['rows'];
        $accounts = IQU_Billing_DB::accounts_by_customers(array_column($rows, 'customer'));
        $students = IQU_Billing_DB::student_names_for_accounts(array_map(fn($a) => (int) $a['id'], $accounts));
        $summer   = IQU_Billing_DB::registration_names(array_filter(array_column($rows, 'summer_reg')));
        $out = [];
        foreach ($rows as $row) {
            $kind = IQU_Billing_Payout_Data::kind($row);
            $acc  = $row['customer'] !== '' ? ($accounts[$row['customer']] ?? null) : null;
            $family = ''; $names = '';
            if ($row['summer_reg'] > 0) {
                $family = 'Summer program';
                $names  = ($summer[$row['summer_reg']] ?? '') !== '' ? $summer[$row['summer_reg']] . ' (IQU-' . (int) $row['summer_reg'] . ')' : 'IQU-' . (int) $row['summer_reg'];
            } elseif ($acc) {
                $names  = $students[(int) $acc['id']] ?? '';
                $family = trim((string) $acc['guardian_name']) !== '' ? trim((string) $acc['guardian_name']) : ($names !== '' ? $names : 'Family #' . (int) $acc['id']);
            } elseif ($row['customer'] !== '') {
                $family = 'Not in IQU';
            }
            switch ($kind) {
                case 'payment':    $type = $acc ? 'Tuition' : 'Payment'; break;
                case 'summer':     $type = 'Summer program'; break;
                case 'refund':     $type = 'Refund'; break;
                case 'dispute':    $type = $row['amount'] > 0 ? 'Dispute reversal' : 'Dispute'; break;
                case 'ach_return': $type = 'Bank return (ACH)'; break;
                case 'stripe_fee': $type = 'Stripe fee'; break;
                default:           $type = 'Other (' . $row['type'] . ')';
            }
            $out[] = [
                'created' => (int) $row['created'], 'family' => $family, 'account' => $acc ? (int) $acc['id'] : 0, 'customer' => (string) $row['customer'],
                'students' => $names, 'type_label' => $type, 'amount' => (int) $row['amount'], 'fee' => (int) $row['fee'], 'net' => (int) $row['net'], 'txn' => (string) $row['id'],
            ];
        }
        return $out;
    }

    private static function refresh_button(string $payout_id): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::A_REFRESH) . '">'
            . ($payout_id !== '' ? '<input type="hidden" name="payout" value="' . esc_attr($payout_id) . '">' : '')
            . wp_nonce_field(self::A_REFRESH, '_wpnonce', true, false)
            . '<button type="submit" class="iqu-btn iqu-btn--secondary">' . IQU_Billing_Page::icon('update') . 'Refresh</button></form>';
    }

    // ------------------------------------------------------------
    // Actions
    // ------------------------------------------------------------

    /** Refresh: read the list (and this payout, unless settled) from Stripe again. */
    public static function handle_refresh(): void
    {
        if (!current_user_can(self::CAP)) wp_die('You do not have permission to do this.', 403);
        check_admin_referer(self::A_REFRESH);
        $id = sanitize_text_field(wp_unslash($_POST['payout'] ?? ''));
        if (!preg_match('/^po_[A-Za-z0-9]+$/', $id)) $id = '';
        IQU_Billing_Payout_Data::forget($id);
        wp_safe_redirect(self::url($id));
        exit;
    }

    /** One payout's transactions as CSV: the same rows as the screen, then the total. */
    public static function handle_csv(): void
    {
        if (!current_user_can(self::CAP)) wp_die('You do not have permission to do this.', 403);
        check_admin_referer(self::A_CSV);
        $id = sanitize_text_field(wp_unslash($_POST['payout'] ?? ''));
        if (!preg_match('/^po_[A-Za-z0-9]+$/', $id)) wp_die('This is not a payout id.', 400);
        $r = IQU_Billing_Payout_Data::summary($id);
        if (!$r['ok']) wp_die(esc_html($r['error']), 502);
        $rows = self::display_rows($r['summary']);
        $day  = $r['payout']['arrival_date'] ? wp_date('Y-m-d', $r['payout']['arrival_date']) : 'payout';

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="iqu-billing-payout-' . $day . '-' . $id . '.csv"');
        $out = fopen('php://output', 'w');
        self::write_csv($out, $rows);
        fclose($out);
        exit;
    }

    /** UTF-8 with BOM; money as 2-decimal numbers; dates YYYY-MM-DD in the site time zone. */
    public static function write_csv($out, array $rows): void
    {
        fputs($out, "\xEF\xBB\xBF");
        IQU_CSV::put($out, ['Date', 'Family', 'Stripe customer', 'Students', 'Type', 'Gross', 'Stripe fee', 'Net', 'Stripe transaction']);
        foreach ($rows as $row) {
            IQU_CSV::put($out, [
                $row['created'] ? wp_date('Y-m-d', $row['created']) : '', $row['family'], $row['customer'], $row['students'], $row['type_label'],
                self::csv_money($row['amount']), self::csv_money($row['fee']), self::csv_money($row['net']), $row['txn'],
            ]);
        }
        IQU_CSV::put($out, ['Total', '', '', '', '', self::csv_money(array_sum(array_column($rows, 'amount'))), self::csv_money(array_sum(array_column($rows, 'fee'))), self::csv_money(array_sum(array_column($rows, 'net'))), '']);
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /** Cents → "$1,234.56" / "−$12.00". */
    private static function money(int $cents): string
    {
        return ($cents < 0 ? '−' : '') . IQU_Pricing::format(abs($cents) / 100);
    }

    private static function csv_money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /** "STRIPE TEST BANK •••• 6789", "Bank account" when Stripe did not give the details. */
    private static function bank(array $p): string
    {
        if ($p['last4'] === '') return $p['bank_name'] !== '' ? $p['bank_name'] : 'Bank account';
        return ($p['bank_name'] !== '' ? $p['bank_name'] : 'Bank') . ' •••• ' . $p['last4'];
    }
}
