<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Billing_Payments_Screen
 *
 * IQU Registrations → Billing → Payments: KPIs, charts and tables for one
 * tuition month and the months before it.
 *
 * - Reads the local database only (IQU_Billing_History); no Stripe call on load.
 * - Every chart has a table with the same numbers ("View as table") and an
 *   aria-label summary on the canvas.
 * - Capability check happens in IQU_Billing_Page::render_payments().
 * - All output is escaped.
 */
class IQU_Billing_Payments_Screen
{
    private const ON_BILLING = ['free_month', 'waiting_first_charge', 'active', 'past_due'];
    private const PROBLEM    = ['past_due', 'unpaid', 'paused'];

    /** Family status groups for the doughnut: label => [statuses, colour token]. */
    private const STATUS_GROUPS = [
        'Active'               => [['active'], 'green'],
        'Free month'           => [['free_month'], 'blue'],
        'Waiting first charge' => [['waiting_first_charge'], 'purple'],
        'Payment problem'      => [['past_due', 'unpaid', 'paused'], 'red'],
        'Not set up'           => [['not_sent'], 'text2'],
        'Link sent'            => [['link_sent'], 'gold'],
        'Stopped'              => [['canceled'], 'navy'],
    ];

    private static array $labels = [];

    public static function render(): void
    {
        $tz    = wp_timezone();
        $month = sanitize_text_field(wp_unslash($_GET['month'] ?? ''));
        if (!IQU_Billing_History::is_month($month)) $month = (new DateTimeImmutable('now', $tz))->format('Y-m');
        $range = absint($_GET['range'] ?? 6);
        if (!in_array($range, [6, 12], true)) $range = 6;

        $accounts = IQU_Billing_History::accounts();
        $by_id    = [];
        foreach ($accounts as $a) $by_id[(int) $a['id']] = $a;
        $list   = IQU_Billing_History::month_list($month, $range);
        $rows   = IQU_Billing_History::rows_for_months($list[0], $month);
        $months = IQU_Billing_History::months($range, $month, $rows, $accounts);
        $cur    = $months[count($months) - 1];
        $prev   = $months[count($months) - 2] ?? null;

        $on_billing = array_values(array_filter($accounts, fn($a) => in_array($a['status'], self::ON_BILLING, true)));
        $recurring  = round(array_sum(array_map(fn($a) => (float) $a['net_amount'], $on_billing)), 2);
        $families   = count($on_billing);
        $upcoming   = IQU_Billing_History::upcoming(30, $accounts);
        $problems   = array_values(array_filter($accounts, fn($a) => in_array($a['status'], self::PROBLEM, true)));
        $in_month   = array_values(array_filter($rows, fn($r) => $r['period_month'] === $month));
        usort($in_month, fn($x, $y) => strcmp((string) ($y['paid_at'] ?: $y['period_start']), (string) ($x['paid_at'] ?: $x['period_start'])));

        $mlabel = IQU_Billing_History::month_label($month);
        $plabel = $prev ? (new DateTimeImmutable($prev['month'] . '-01'))->format('F') : '';
        $mode   = IQU_Stripe::expected_mode();

        self::charts($months, $accounts, $rows, $upcoming, $tz);
        ?>
        <div class="wrap iqu-admin-wrap iqu-billing">
            <?php IQU_Billing_Page::tabs('payments'); ?>
            <?php IQU_Billing_Page::page_header('Payments', 'Tuition collected and billed, payment problems and the charges coming up.'); ?>
            <?php if ($mode === 'test'): ?><div class="notice notice-warning inline"><p><strong>Test mode.</strong> These are test payments, not real money.</p></div><?php endif; ?>

            <!-- ── Period ────────────────────────────────────── -->
            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="iqu-period-bar">
                <input type="hidden" name="page" value="<?php echo esc_attr(IQU_Billing_Page::PAYMENTS_SLUG); ?>">
                <label class="iqu-period-label" for="iqu-pay-month">Tuition month</label>
                <input id="iqu-pay-month" type="month" name="month" value="<?php echo esc_attr($month); ?>">
                <div class="iqu-segmented" role="radiogroup" aria-label="Months shown in the charts">
                    <?php foreach ([6, 12] as $n): ?>
                        <label><input type="radio" name="range" value="<?php echo (int) $n; ?>" <?php checked($range, $n); ?>><span><?php echo (int) $n; ?> months</span></label>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="iqu-btn iqu-btn--secondary">Show</button>
            </form>

            <details class="iqu-howto">
                <summary>How these numbers work</summary>
                <dl>
                    <dt>Tuition month</dt><dd>The month an invoice is for (its billing period start, in the site's time zone), not the day it was paid.</dd>
                    <dt>Billed</dt><dd>Amount due of the month's invoices, without void ones.</dd>
                    <dt>Collected</dt><dd>Amount paid of the month's paid invoices. Fully refunded invoices are not counted; partial refunds are shown separately.</dd>
                    <dt>Outstanding</dt><dd>Billed minus collected, for failed, open and uncollectible invoices.</dd>
                    <dt>Collection rate</dt><dd>Collected ÷ billed.</dd>
                    <dt>Expected</dt><dd>Billed, plus the monthly amount of families whose next charge falls in the month and who have no invoice for it yet.</dd>
                    <dt>Monthly recurring</dt><dd>Monthly amount of every family on billing (free month, waiting for first charge, active, payment failed).</dd>
                </dl>
            </details>

            <!-- ── KPIs ──────────────────────────────────────── -->
            <div class="iqu-kpis">
                <?php
                self::kpi('Collected', IQU_Pricing::format($cur['collected']), 'revenue', self::delta_money($cur['collected'], $prev['collected'] ?? null, $plabel), $mlabel);
                self::kpi('Expected', IQU_Pricing::format($cur['expected']), 'total', '', 'Billed ' . IQU_Pricing::format($cur['billed']));
                self::kpi('Collection rate', $cur['rate'] === null ? '—' : $cur['rate'] . '%', 'l1', self::delta_points($cur['rate'], $prev['rate'] ?? null, $plabel), $cur['rate'] === null ? 'Nothing billed yet' : '');
                self::kpi('Outstanding', IQU_Pricing::format($cur['outstanding']), 'problem', '', $cur['refunded'] > 0 ? 'Refunded ' . IQU_Pricing::format($cur['refunded']) : '', $cur['outstanding'] > 0);
                self::kpi('Families on billing', (string) $families, 'free', '', count($problems) ? count($problems) . ' with a payment problem' : '');
                self::kpi('Monthly recurring', IQU_Pricing::format($recurring), 'l2', '', 'a month');
                self::kpi('Average per family', $families ? IQU_Pricing::format($recurring / $families) : '—', 'weekend', '', '');
                ?>
            </div>

            <!-- ── Charts ────────────────────────────────────── -->
            <div class="iqu-chart-grid">
                <?php
                $mrows = array_map(fn($m) => [IQU_Billing_History::month_label($m['month']), IQU_Pricing::format($m['collected']), IQU_Pricing::format($m['billed'])], $months);
                self::chart_card('iqu-ch-monthly', 'Monthly tuition, last ' . $range . ' months', 'Collected and billed per tuition month, last ' . $range . ' months. ' . $mlabel . ': collected ' . IQU_Pricing::format($cur['collected']) . ' of ' . IQU_Pricing::format($cur['billed']) . ' billed.', ['Month', 'Collected', 'Billed'], $mrows, true, array_sum(array_column($months, 'billed')) + array_sum(array_column($months, 'collected')) > 0);
                ?>
            </div>

            <!-- Four small charts: one row of four on wide screens, 2 x 2 on medium, one column on narrow -->
            <div class="iqu-chart-quad">
                <?php

                $crows = array_map(fn($m) => [IQU_Billing_History::month_label($m['month']), (string) $m['paid_count'], (string) $m['failed_count']], $months);
                self::chart_card('iqu-ch-paidfail', 'Paid and failed payments', 'Number of paid and failed tuition invoices per month. ' . $mlabel . ': ' . $cur['paid_count'] . ' paid, ' . $cur['failed_count'] . ' failed.', ['Month', 'Paid', 'Failed'], $crows, false, array_sum(array_column($months, 'paid_count')) + array_sum(array_column($months, 'failed_count')) > 0, true);

                $counts = IQU_Billing_History::status_counts($accounts);
                $srows = [];
                foreach (self::STATUS_GROUPS as $label => [$statuses, $tone]) {
                    $srows[] = [$label, (string) array_sum(array_map(fn($s) => $counts[$s] ?? 0, $statuses))];
                }
                self::chart_card('iqu-ch-status', 'Billing status of families', 'Families by billing status: ' . implode(', ', array_map(fn($r) => $r[1] . ' ' . strtolower($r[0]), array_filter($srows, fn($r) => $r[1] !== '0'))) . '.', ['Status', 'Families'], $srows, false, count($accounts) > 0, true);

                $split = IQU_Billing_History::method_split($rows);
                $prows = [];
                foreach ($split as $g => $amt) $prows[] = [$g, IQU_Pricing::format($amt)];
                self::chart_card('iqu-ch-methods', 'Payment methods', 'Amount collected in the last ' . $range . ' months by payment method' . ($prows ? ': ' . implode(', ', array_map(fn($r) => $r[0] . ' ' . $r[1], $prows)) : '') . '.', ['Method', 'Collected'], $prows, false, (bool) $split, true);

                $weeks = self::weeks($upcoming, $tz);
                $wrows = array_map(fn($w) => [$w['label'], IQU_Pricing::format($w['amount']), (string) $w['count']], $weeks);
                self::chart_card('iqu-ch-upcoming', 'Upcoming charges, next 30 days', 'Charges expected in the next 30 days by week, total ' . IQU_Pricing::format(array_sum(array_column($weeks, 'amount'))) . '.', ['Week', 'Amount', 'Charges'], $wrows, false, (bool) $upcoming, true);
                ?>
            </div>

            <!-- ── Needs attention ──────────────────────────── -->
            <div class="iqu-card">
                <div class="iqu-card-head">
                    <span class="iqu-card-head-title">Needs attention</span>
                    <span class="iqu-card-head-badge"><?php echo (int) count($problems); ?> <?php echo count($problems) === 1 ? 'family' : 'families'; ?></span>
                </div>
                <?php if (!$problems): ?>
                    <div class="iqu-empty-state">No payment problems. Families whose payment fails appear here.</div>
                <?php else: ?>
                    <?php self::toolbar('iqu-t-attention', 'iqu-billing-needs-attention-' . $month . '.csv'); ?>
                    <div class="iqu-table-wrap iqu-dt-wrap">
                    <table class="iqu-tbl iqu-dt iqu-billing-tbl" id="iqu-t-attention">
                        <thead><tr>
                            <?php self::th('Family'); self::th('Amount', 'num'); self::th('Month'); self::th('Attempts', 'num'); self::th('Reason'); self::th('Last try', 'date'); ?>
                            <th data-csv-skip><span class="screen-reader-text">Actions</span></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($problems as $a):
                            $fail = self::latest_problem((int) $a['id']);
                            $amt  = $fail ? (float) $fail['amount_due'] : (float) $a['net_amount'];
                            $last = $fail ? (string) ($fail['updated_at'] ?: $fail['created_at']) : (string) $a['updated_at']; ?>
                            <tr>
                                <td><?php self::family_cell($a); ?></td>
                                <td class="is-num" data-sort="<?php echo esc_attr(number_format($amt, 2, '.', '')); ?>"><?php echo esc_html(IQU_Pricing::format($amt)); ?></td>
                                <td data-sort="<?php echo esc_attr($fail['period_month'] ?? ''); ?>"><?php echo esc_html($fail ? IQU_Billing_History::month_label((string) $fail['period_month']) : '—'); ?></td>
                                <td class="is-num"><?php echo (int) ($fail['attempt_count'] ?? 0); ?></td>
                                <td class="iqu-billing-wrap"><span class="iqu-chip iqu-chip--red"><?php echo esc_html(IQU_Billing_Send::status_label($a['status'])); ?></span><br><span class="iqu-billing-sub"><?php echo esc_html(($fail['failure_reason'] ?? '') ?: ($a['last_failure_reason'] ?: '—')); ?></span></td>
                                <td class="iqu-td-date" data-sort="<?php echo esc_attr($last); ?>"><?php echo esc_html(self::day($last)); ?></td>
                                <td class="iqu-td-actions"><a class="iqu-action-btn iqu-action-btn--view" href="<?php echo esc_url(IQU_Billing_Page::family_url((int) $a['id'])); ?>">Open family</a></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php self::no_match(7); ?>
                        </tbody>
                    </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ── Upcoming charges ─────────────────────────── -->
            <div class="iqu-card">
                <div class="iqu-card-head">
                    <span class="iqu-card-head-title">Upcoming charges, next 30 days</span>
                    <span class="iqu-card-head-badge"><?php echo esc_html(IQU_Pricing::format(array_sum(array_map(fn($a) => (float) $a['net_amount'], $upcoming)))); ?></span>
                </div>
                <?php if (!$upcoming): ?>
                    <div class="iqu-empty-state">No charges in the next 30 days.</div>
                <?php else: ?>
                    <?php self::toolbar('iqu-t-upcoming', 'iqu-billing-upcoming-' . $month . '.csv'); ?>
                    <div class="iqu-table-wrap iqu-dt-wrap">
                    <table class="iqu-tbl iqu-dt iqu-billing-tbl" id="iqu-t-upcoming">
                        <thead><tr><?php self::th('Date', 'date'); self::th('Family'); self::th('Amount', 'num'); self::th('Paying from'); ?></tr></thead>
                        <tbody>
                        <?php foreach ($upcoming as $a): ?>
                            <tr>
                                <td class="iqu-td-date" data-sort="<?php echo esc_attr((string) $a['next_charge_at']); ?>"><?php echo esc_html(self::day((string) $a['next_charge_at'])); ?><?php echo $a['status'] === 'free_month' ? '<br><span class="iqu-billing-sub">end of free month</span>' : ''; ?></td>
                                <td><?php self::family_cell($a); ?></td>
                                <td class="is-num" data-sort="<?php echo esc_attr(number_format((float) $a['net_amount'], 2, '.', '')); ?>"><?php echo esc_html(IQU_Pricing::format((float) $a['net_amount'])); ?></td>
                                <td><?php echo esc_html($a['payment_method_label'] ?: '—'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php self::no_match(4); ?>
                        </tbody>
                        <tfoot><tr><td colspan="2">Total</td><td class="is-num"><?php echo esc_html(IQU_Pricing::format(array_sum(array_map(fn($a) => (float) $a['net_amount'], $upcoming)))); ?></td><td></td></tr></tfoot>
                    </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ── Payments in the month ────────────────────── -->
            <div class="iqu-card">
                <div class="iqu-card-head">
                    <span class="iqu-card-head-title">Payments for <?php echo esc_html($mlabel); ?></span>
                    <span class="iqu-card-head-badge"><?php echo (int) count($in_month); ?> <?php echo count($in_month) === 1 ? 'invoice' : 'invoices'; ?></span>
                </div>
                <?php if (!$in_month): ?>
                    <div class="iqu-empty-state">No invoices for <?php echo esc_html($mlabel); ?>. After updating, run <strong>Settings → Backfill payment history</strong> once to load earlier months.</div>
                <?php else: ?>
                    <?php self::toolbar('iqu-t-month', 'iqu-billing-payments-' . $month . '.csv'); ?>
                    <div class="iqu-table-wrap iqu-dt-wrap">
                    <table class="iqu-tbl iqu-dt iqu-billing-tbl" id="iqu-t-month">
                        <thead><tr>
                            <?php self::th('Date', 'date'); self::th('Family'); self::th('Month'); self::th('Amount', 'num'); self::th('Method'); self::th('Status'); ?>
                            <th>Receipt</th><th>Invoice</th><th data-csv-skip><span class="screen-reader-text">Stripe</span></th>
                        </tr></thead>
                        <tbody>
                        <?php
                        $total = 0.0;
                        foreach ($in_month as $r):
                            $a    = $by_id[(int) $r['account_id']] ?? null;
                            $paid = in_array($r['status'], ['paid', 'refunded'], true);
                            $amt  = $paid ? (float) $r['amount_paid'] : (float) $r['amount_due'];
                            if ($r['status'] === 'paid') $total += (float) $r['amount_paid'];
                            $when = (string) ($r['paid_at'] ?: ($r['period_start'] ?: $r['created_at']));
                            [$blabel, $btone] = IQU_Billing_History::badge($r);
                            $receipt = IQU_Billing_History::safe_url($r['hosted_invoice_url']);
                            $pdf     = IQU_Billing_History::safe_url($r['invoice_pdf']); ?>
                            <tr>
                                <td class="iqu-td-date" data-sort="<?php echo esc_attr($when); ?>"><?php echo esc_html(self::day($when)); ?></td>
                                <td><?php if ($a) self::family_cell($a); else echo '—'; ?></td>
                                <td data-sort="<?php echo esc_attr((string) $r['period_month']); ?>"><?php echo esc_html(IQU_Billing_History::month_label((string) $r['period_month'])); ?></td>
                                <td class="is-num" data-sort="<?php echo esc_attr(number_format($amt, 2, '.', '')); ?>" data-csv="<?php echo esc_attr(number_format($amt, 2, '.', '')); ?>"><?php echo esc_html(IQU_Pricing::format($amt)); ?><?php if ((float) $r['amount_refunded'] > 0 && $r['status'] === 'paid'): ?><br><span class="iqu-billing-sub">Refunded <?php echo esc_html(IQU_Pricing::format((float) $r['amount_refunded'])); ?></span><?php endif; ?></td>
                                <td><?php echo esc_html($r['method_label'] ?: '—'); ?></td>
                                <td><span class="iqu-chip iqu-chip--<?php echo esc_attr($btone); ?>"><?php echo esc_html($blabel); ?></span></td>
                                <td data-csv="<?php echo esc_attr($receipt); ?>"><?php if ($receipt): ?><a href="<?php echo esc_url($receipt); ?>" target="_blank" rel="noopener noreferrer">Receipt<?php echo $r['receipt_number'] ? ' ' . esc_html($r['receipt_number']) : ''; ?></a><?php else: ?>—<?php endif; ?></td>
                                <td data-csv="<?php echo esc_attr($pdf); ?>"><?php if ($pdf): ?><a href="<?php echo esc_url($pdf); ?>" target="_blank" rel="noopener noreferrer">PDF</a><?php else: ?>—<?php endif; ?></td>
                                <td class="iqu-td-actions"><a class="iqu-action-btn" href="<?php echo esc_url(self::stripe_invoice_url((string) $r['stripe_invoice_id'])); ?>" target="_blank" rel="noopener noreferrer">View in Stripe</a></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php self::no_match(9); ?>
                        </tbody>
                        <tfoot><tr><td colspan="3">Collected</td><td class="is-num"><?php echo esc_html(IQU_Pricing::format($total)); ?></td><td colspan="5"></td></tr></tfoot>
                    </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    // ------------------------------------------------------------
    // Pieces
    // ------------------------------------------------------------

    private static function kpi(string $label, string $value, string $accent, string $delta, string $sub, bool $alert = false): void
    {
        echo '<div class="iqu-metric' . ($alert ? ' iqu-metric--alert' : '') . '"><div class="iqu-metric-accent iqu-metric-accent--' . esc_attr($accent) . '"></div>'
            . '<div class="iqu-metric-num">' . esc_html($value) . '</div><div class="iqu-metric-lbl">' . esc_html($label) . '</div>'
            . ($sub !== '' ? '<div class="iqu-metric-sub">' . esc_html($sub) . '</div>' : '')
            . $delta . '</div>';
    }

    /** "▲ 12.5% vs September" (escaped HTML), or '' when there is nothing to compare with. */
    private static function delta_money(float $now, ?float $before, string $month): string
    {
        if ($before === null || $month === '') return '';
        if ($before <= 0) return $now > 0 ? '<div class="iqu-kpi-delta iqu-kpi-delta--up">▲ ' . esc_html('up from $0 in ' . $month) . '</div>' : '';
        $pct = round(($now - $before) / $before * 100, 1);
        if ($pct == 0) return '<div class="iqu-kpi-delta">' . esc_html('Same as ' . $month) . '</div>';
        $up = $pct > 0;
        return '<div class="iqu-kpi-delta iqu-kpi-delta--' . ($up ? 'up' : 'down') . '">' . ($up ? '▲ ' : '▼ ') . esc_html(abs($pct) . '% vs ' . $month) . '</div>';
    }

    private static function delta_points(?float $now, ?float $before, string $month): string
    {
        if ($now === null || $before === null || $month === '') return '';
        $d = round($now - $before, 1);
        if ($d == 0) return '<div class="iqu-kpi-delta">' . esc_html('Same as ' . $month) . '</div>';
        $up = $d > 0;
        return '<div class="iqu-kpi-delta iqu-kpi-delta--' . ($up ? 'up' : 'down') . '">' . ($up ? '▲ ' : '▼ ') . esc_html(abs($d) . ' points vs ' . $month) . '</div>';
    }

    /**
     * A chart card: canvas (drawn by billing.js) + a table with the same numbers, behind a toggle.
     * @param string[][] $rows table rows (first cell = label)
     */
    private static function chart_card(string $id, string $title, string $summary, array $head, array $rows, bool $wide, bool $has_data, bool $compact = false): void
    {
        $card = $id . '-card';
        $box  = $compact ? 'iqu-chart-box iqu-chart-box--compact' : 'iqu-chart-box';
        echo '<div class="iqu-card' . ($wide ? ' iqu-chart-wide' : '') . ($compact ? ' iqu-chart-card--compact' : '') . '" id="' . esc_attr($card) . '">';
        echo '<div class="iqu-card-head"><span class="iqu-card-head-title">' . esc_html($title) . '</span>';
        $toggle = $has_data ? '<button type="button" class="iqu-view-toggle" data-toggle-view="' . esc_attr($card) . '" aria-expanded="false">View as table</button>' : '';
        if (!$compact) echo $toggle; // compact cards: in the footer, the same place on every card
        echo '</div>';
        if (!$has_data) {
            echo '<div class="' . ($compact ? $box : 'iqu-chart-box iqu-chart-box--short') . '"><div class="iqu-chart-empty"><span class="dashicons dashicons-chart-bar" aria-hidden="true"></span>No data yet for this chart.</div></div></div>';
            return;
        }
        echo '<div class="' . $box . '"><canvas id="' . esc_attr($id) . '" role="img" aria-label="' . esc_attr($summary) . '"></canvas></div>';
        echo '<div class="iqu-chart-table iqu-table-wrap" hidden><table class="iqu-tbl iqu-dt"><caption class="screen-reader-text">' . esc_html($title) . '</caption><thead><tr>';
        foreach ($head as $i => $h) echo '<th' . ($i ? ' class="is-num"' : '') . '>' . esc_html($h) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr>';
            foreach ($r as $i => $c) echo '<td' . ($i ? ' class="is-num"' : '') . '>' . esc_html($c) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        if ($compact) echo '<div class="iqu-chart-foot">' . $toggle . '</div>';
        echo '</div>';
    }

    /** Chart definitions for admin/js/billing.js (numbers only; colours are --iqu-* token names). */
    private static function charts(array $months, array $accounts, array $rows, array $upcoming, DateTimeZone $tz): void
    {
        $labels = array_map(fn($m) => (new DateTimeImmutable($m['month'] . '-01'))->format('M Y'), $months);
        $counts = IQU_Billing_History::status_counts($accounts);
        $status_labels = []; $status_data = []; $status_tones = [];
        foreach (self::STATUS_GROUPS as $label => [$statuses, $tone]) {
            $status_labels[] = $label;
            $status_data[]   = array_sum(array_map(fn($s) => $counts[$s] ?? 0, $statuses));
            $status_tones[]  = $tone;
        }
        $split  = IQU_Billing_History::method_split($rows);
        $mtones = ['navy', 'blue', 'green', 'gold', 'purple', 'orange', 'text2'];
        $weeks  = self::weeks($upcoming, $tz);

        IQU_Billing_Page::chart_data([
            ['id' => 'iqu-ch-monthly', 'type' => 'bar', 'money' => true, 'labels' => $labels, 'datasets' => [
                ['label' => 'Collected', 'data' => array_column($months, 'collected'), 'tone' => 'green'],
                ['label' => 'Billed', 'data' => array_column($months, 'billed'), 'tone' => 'navy', 'kind' => 'line'],
            ]],
            ['id' => 'iqu-ch-paidfail', 'type' => 'bar', 'stacked' => true, 'labels' => $labels, 'datasets' => [
                ['label' => 'Paid', 'data' => array_column($months, 'paid_count'), 'tone' => 'green', 'stack' => 'n'],
                ['label' => 'Failed', 'data' => array_column($months, 'failed_count'), 'tone' => 'red', 'stack' => 'n'],
            ]],
            ['id' => 'iqu-ch-status', 'type' => 'doughnut', 'legend' => 'bottom', 'labels' => $status_labels, 'datasets' => [
                ['label' => 'Families', 'data' => $status_data, 'tones' => $status_tones],
            ]],
            ['id' => 'iqu-ch-methods', 'type' => 'doughnut', 'legend' => 'bottom', 'money' => true, 'labels' => array_keys($split), 'datasets' => [
                ['label' => 'Collected', 'data' => array_values($split), 'tones' => array_slice(array_merge($mtones, $mtones), 0, max(1, count($split)))],
            ]],
            ['id' => 'iqu-ch-upcoming', 'type' => 'bar', 'money' => true, 'labels' => array_column($weeks, 'label'), 'datasets' => [
                ['label' => 'Expected', 'data' => array_column($weeks, 'amount'), 'tone' => 'blue'],
            ]],
        ]);
    }

    /** Upcoming charges grouped by week (Monday start, site time zone). */
    private static function weeks(array $upcoming, DateTimeZone $tz): array
    {
        $out = [];
        foreach ($upcoming as $a) {
            $d   = (new DateTimeImmutable('@' . (int) $a['_ts']))->setTimezone($tz);
            $mon = $d->modify('monday this week')->format('Y-m-d');
            if (!isset($out[$mon])) $out[$mon] = ['label' => 'Week of ' . (new DateTimeImmutable($mon))->format('j M'), 'amount' => 0.0, 'count' => 0];
            $out[$mon]['amount'] = round($out[$mon]['amount'] + (float) $a['net_amount'], 2);
            $out[$mon]['count']++;
        }
        ksort($out);
        return array_values($out);
    }

    /** Newest failed / open / uncollectible invoice of one family. */
    private static function latest_problem(int $account_id): ?array
    {
        foreach (IQU_Billing_History::for_account($account_id) as $r) {
            if (in_array($r['status'], ['failed', 'uncollectible', 'open'], true)) return $r;
        }
        return null;
    }

    private static function family_cell(array $acc): void
    {
        $id = (int) $acc['id'];
        if (!isset(self::$labels[$id])) self::$labels[$id] = IQU_Billing_Page::family_label($acc);
        $name = trim((string) $acc['guardian_name']) ?: self::$labels[$id];
        echo '<span class="iqu-family-cell">'
            . '<span class="iqu-avatar iqu-avatar--sm" aria-hidden="true">' . esc_html(IQU_Billing_Page::initials($name)) . '</span>'
            . '<a class="iqu-family-link" href="' . esc_url(IQU_Billing_Page::family_url($id)) . '">' . esc_html(self::$labels[$id]) . '</a></span>';
    }

    private static function th(string $label, string $type = 'text'): void
    {
        echo '<th' . ($type === 'num' ? ' class="is-num"' : '') . ' data-type="' . esc_attr($type) . '" aria-sort="none"><button type="button" class="iqu-sort">' . esc_html($label) . '</button></th>';
    }

    private static function toolbar(string $table_id, string $csv_name): void
    {
        echo '<div class="iqu-dt-toolbar"><label class="screen-reader-text" for="' . esc_attr($table_id) . '-q">Filter rows</label>'
            . '<input id="' . esc_attr($table_id) . '-q" type="search" placeholder="Filter…" data-filter-for="' . esc_attr($table_id) . '">'
            . '<span class="iqu-dt-count" data-count-for="' . esc_attr($table_id) . '" aria-live="polite"></span>'
            . '<button type="button" class="iqu-export-btn" data-csv-for="' . esc_attr($table_id) . '" data-csv-name="' . esc_attr($csv_name) . '"><span class="dashicons dashicons-download" aria-hidden="true"></span>Download CSV</button></div>';
    }

    private static function no_match(int $cols): void
    {
        echo '<tr class="iqu-dt-none"><td colspan="' . (int) $cols . '" class="iqu-empty-state">No rows match the filter.</td></tr>';
    }

    private static function day(string $utc): string
    {
        $ts = $utc !== '' ? strtotime($utc . ' UTC') : 0;
        return $ts ? wp_date('j M Y', $ts) : '—';
    }

    private static function stripe_invoice_url(string $invoice_id): string
    {
        return 'https://dashboard.stripe.com/' . (IQU_Stripe::expected_mode() === 'test' ? 'test/' : '') . 'invoices/' . rawurlencode($invoice_id);
    }
}
