<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Billing_Reports
 *
 * IQU Registrations → Billing → Reports: month-based datasets on screen and as CSV.
 *   students        every billed student with fee, family status and dates
 *   payments        every invoice of the month
 *   reconciliation  per family: expected vs paid for the month
 *   not_set_up      families without a subscription yet
 *
 * Security:
 * - manage_options only; the download is an admin-post action with its own nonce.
 * - Dataset and month are whitelisted / validated.
 * - Local database only, every query prepared and limited to the current mode.
 * - CSV: UTF-8 with BOM, cells that start with = + - @ tab or CR get a leading
 *   apostrophe (formula injection), except plain numbers; money as 2-decimal numbers.
 * - All screen output is escaped.
 */
class IQU_Billing_Reports
{
    public const SLUG    = 'iqu-billing-reports';
    private const CAP    = 'manage_options';
    private const A_CSV  = 'iqu_billing_export';

    public const DATASETS = [
        'students'       => 'Students & billing',
        'payments'       => 'Payments',
        'reconciliation' => 'Reconciliation',
        'not_set_up'     => 'Not set up',
    ];

    public static function init(): void
    {
        add_action('admin_menu', [__CLASS__, 'register_menu'], 22);
        add_action('admin_post_' . self::A_CSV, [__CLASS__, 'handle_export']);
    }

    public static function register_menu(): void
    {
        add_submenu_page('iqu-registrations', 'Billing Reports — IQU', 'Billing Reports', self::CAP, self::SLUG, [__CLASS__, 'render']);
    }

    // ------------------------------------------------------------
    // Datasets
    // ------------------------------------------------------------

    /**
     * @return array{columns: array<string,array{0:string,1:string}>, rows: array[], note: string}
     *   columns: key => [label, type] with type text | num | money | date | url
     *   rows:    key => raw value, plus '_account' (account id) for the family link
     */
    public static function dataset(string $key, string $ym): array
    {
        switch ($key) {
            case 'payments':       return self::ds_payments($ym);
            case 'reconciliation': return self::ds_reconciliation($ym);
            case 'not_set_up':     return self::ds_not_set_up();
            default:               return self::ds_students($ym);
        }
    }

    private static function ds_students(string $ym): array
    {
        $end = self::month_end_utc($ym);
        $rows = [];
        foreach (IQU_Billing_History::accounts() as $a) {
            if ((string) $a['created_at'] > $end) continue; // added after this month
            foreach (IQU_Billing_DB::get_members((int) $a['id']) as $m) {
                $reg = IQU_Database::get_registration((int) $m['registration_id']);
                if (!$reg) continue;
                $p = IQU_Billing_Pricing::for_registration($reg);
                $disc = (float) $p['discount'] > 0 ? trim(($p['discount_label'] ?: 'Discount') . ' −' . IQU_Pricing::format((float) $p['discount'])) : ($p['net'] <= 0 ? 'Full scholarship' : '');
                $rows[] = [
                    '_account'    => (int) $a['id'],
                    'iqu'         => 'IQU-' . (int) $reg['id'],
                    'student'     => trim($reg['first_name'] . ' ' . $reg['last_name']),
                    'guardian'    => (string) $a['guardian_name'],
                    'email'       => (string) $a['contact_email'],
                    'whatsapp'    => (string) $a['contact_whatsapp'],
                    'course'      => (string) ($p['course_label'] ?: '—'),
                    'days'        => (int) $p['days_per_week'],
                    'monthly'     => (float) $m['amount'],
                    'discount'    => $disc,
                    'status'      => IQU_Billing_Send::status_label((string) $a['status']),
                    'first'       => (string) ($a['first_charge_date'] ?? ''),
                    'next'        => (string) ($a['next_charge_at'] ?? ''),
                    'method'      => (string) $a['payment_method_label'],
                    'email_sent'  => (string) ($a['email_sent_at'] ?? ''),
                    'wa_sent'     => (string) ($a['whatsapp_sent_at'] ?? ''),
                ];
            }
        }
        return [
            'columns' => [
                'iqu' => ['IQU no', 'text'], 'student' => ['Student', 'text'], 'guardian' => ['Guardian', 'text'],
                'email' => ['Email', 'text'], 'whatsapp' => ['WhatsApp', 'text'], 'course' => ['Course', 'text'],
                'days' => ['Days/week', 'num'], 'monthly' => ['Monthly', 'money'], 'discount' => ['Discount / Zakat', 'text'],
                'status' => ['Family status', 'text'], 'first' => ['First charge', 'date'], 'next' => ['Next charge', 'date'],
                'method' => ['Paying from', 'text'], 'email_sent' => ['Link emailed', 'date'], 'wa_sent' => ['Link on WhatsApp', 'date'],
            ],
            'rows' => $rows,
            'note' => 'Every student with a billing record created by the end of ' . IQU_Billing_History::month_label($ym) . '. Status and dates are as they are today.',
        ];
    }

    private static function ds_payments(string $ym): array
    {
        $accounts = self::accounts_by_id();
        $rows = [];
        foreach (IQU_Billing_History::rows_for_months($ym, $ym) as $r) {
            $a = $accounts[(int) $r['account_id']] ?? null;
            [$badge] = IQU_Billing_History::badge($r);
            $rows[] = [
                '_account' => (int) $r['account_id'],
                'family'   => $a ? self::family_name($a) : '—',
                'students' => $a ? self::students($a) : '',
                'due'      => (float) $r['amount_due'],
                'paid'     => (float) $r['amount_paid'],
                'refunded' => (float) $r['amount_refunded'],
                'status'   => $badge,
                'paid_on'  => (string) ($r['paid_at'] ?? ''),
                'method'   => (string) $r['method_label'],
                'receipt'  => (string) $r['receipt_number'],
                'invoice'  => IQU_Billing_History::safe_url($r['hosted_invoice_url']),
            ];
        }
        return [
            'columns' => [
                'family' => ['Family', 'text'], 'students' => ['Students', 'text'], 'due' => ['Amount due', 'money'],
                'paid' => ['Amount paid', 'money'], 'refunded' => ['Refunded', 'money'], 'status' => ['Status', 'text'],
                'paid_on' => ['Paid on', 'date'], 'method' => ['Method', 'text'], 'receipt' => ['Receipt no', 'text'], 'invoice' => ['Invoice', 'url'],
            ],
            'rows' => $rows,
            'note' => 'Every Stripe invoice for the tuition month ' . IQU_Billing_History::month_label($ym) . '.',
        ];
    }

    private static function ds_reconciliation(string $ym): array
    {
        $accounts = self::accounts_by_id();
        $fam = [];
        foreach (IQU_Billing_History::rows_for_months($ym, $ym) as $r) {
            $id = (int) $r['account_id'];
            $fam[$id] = $fam[$id] ?? ['expected' => 0.0, 'paid' => 0.0, 'statuses' => []];
            if ($r['status'] !== 'void') $fam[$id]['expected'] += (float) $r['amount_due'];
            if ($r['status'] === 'paid') $fam[$id]['paid'] += (float) $r['amount_paid'];
            $fam[$id]['statuses'][] = IQU_Billing_History::badge($r)[0];
        }
        foreach ($accounts as $id => $a) {
            if (isset($fam[$id]) || !in_array($a['status'], ['free_month', 'waiting_first_charge', 'active'], true) || empty($a['next_charge_at'])) continue;
            if (IQU_Billing_History::month_of((int) strtotime($a['next_charge_at'] . ' UTC')) !== $ym) continue;
            $fam[$id] = ['expected' => (float) $a['net_amount'], 'paid' => 0.0, 'statuses' => ['Due, no invoice yet']];
        }
        $rows = [];
        foreach ($fam as $id => $f) {
            $a = $accounts[$id] ?? null;
            $rows[] = [
                '_account'   => $id,
                'family'     => $a ? self::family_name($a) : '—',
                'students'   => $a ? self::students($a) : '',
                'expected'   => round($f['expected'], 2),
                'paid'       => round($f['paid'], 2),
                'difference' => round($f['paid'] - $f['expected'], 2),
                'status'     => implode(', ', array_unique($f['statuses'])),
                'billing'    => $a ? IQU_Billing_Send::status_label((string) $a['status']) : '',
            ];
        }
        usort($rows, fn($x, $y) => $x['difference'] <=> $y['difference']);
        return [
            'columns' => [
                'family' => ['Family', 'text'], 'students' => ['Students', 'text'], 'expected' => ['Expected', 'money'],
                'paid' => ['Paid', 'money'], 'difference' => ['Difference', 'money'], 'status' => ['Invoice status', 'text'], 'billing' => ['Family status', 'text'],
            ],
            'rows' => $rows,
            'note' => 'Expected = invoiced for ' . IQU_Billing_History::month_label($ym) . ' (without void), or the monthly amount when the charge is due that month but not invoiced yet. A negative difference is still owed.',
        ];
    }

    private static function ds_not_set_up(): array
    {
        $now = time();
        $rows = [];
        foreach (IQU_Billing_History::accounts() as $a) {
            if (!in_array($a['status'], ['not_sent', 'link_sent'], true) || !empty($a['stripe_subscription_id'])) continue;
            $sent = max((int) strtotime(($a['email_sent_at'] ?: '1970-01-01') . ' UTC'), (int) strtotime(($a['whatsapp_sent_at'] ?: '1970-01-01') . ' UTC'));
            $rows[] = [
                '_account'   => (int) $a['id'],
                'family'     => self::family_name($a),
                'students'   => self::students($a),
                'email'      => (string) $a['contact_email'],
                'whatsapp'   => (string) $a['contact_whatsapp'],
                'monthly'    => (float) $a['net_amount'],
                'created'    => (string) $a['created_at'],
                'email_sent' => (string) ($a['email_sent_at'] ?? ''),
                'wa_sent'    => (string) ($a['whatsapp_sent_at'] ?? ''),
                'days'       => $sent > 86400 ? (int) floor(($now - $sent) / DAY_IN_SECONDS) : '',
            ];
        }
        return [
            'columns' => [
                'family' => ['Family', 'text'], 'students' => ['Students', 'text'], 'email' => ['Email', 'text'], 'whatsapp' => ['WhatsApp', 'text'],
                'monthly' => ['Monthly', 'money'], 'created' => ['Billing created', 'date'], 'email_sent' => ['Link emailed', 'date'],
                'wa_sent' => ['Link on WhatsApp', 'date'], 'days' => ['Days since link sent', 'num'],
            ],
            'rows' => $rows,
            'note' => 'Families with a billing record but no bank or card yet (as of today; the month does not change this list).',
        ];
    }

    // ------------------------------------------------------------
    // Page
    // ------------------------------------------------------------

    public static function render(): void
    {
        if (!current_user_can(self::CAP)) wp_die('You do not have permission to view this page.', 403);

        [$key, $ym] = self::request($_GET);
        $ds   = self::dataset($key, $ym);
        $cols = $ds['columns'];
        $tid  = 'iqu-t-report';
        $money_cols = array_keys(array_filter($cols, fn($c) => $c[1] === 'money'));
        ?>
        <div class="wrap iqu-admin-wrap iqu-billing">
            <?php IQU_Billing_Page::tabs('reports'); ?>
            <?php if (IQU_Stripe::expected_mode() === 'test'): ?><div class="notice notice-warning inline"><p><strong>Test mode.</strong> These reports show test data, not real money.</p></div><?php endif; ?>

            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" class="iqu-period-bar">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
                <label class="iqu-period-label" for="iqu-rep-month">Month</label>
                <input id="iqu-rep-month" type="month" name="month" value="<?php echo esc_attr($ym); ?>">
                <label class="iqu-period-label" for="iqu-rep-ds">Report</label>
                <select id="iqu-rep-ds" name="dataset">
                    <?php foreach (self::DATASETS as $k => $label): ?>
                        <option value="<?php echo esc_attr($k); ?>" <?php selected($key, $k); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="iqu-btn-ghost">Show</button>
                <a class="iqu-export-btn" href="<?php echo esc_url(admin_url('admin.php?page=iqu-billing-import')); ?>">⬆ Import CSV</a>
            </form>

            <div class="iqu-card">
                <div class="iqu-card-head">
                    <span class="iqu-card-head-title"><?php echo esc_html(self::DATASETS[$key] . ' — ' . IQU_Billing_History::month_label($ym)); ?></span>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="<?php echo esc_attr(self::A_CSV); ?>">
                        <input type="hidden" name="dataset" value="<?php echo esc_attr($key); ?>">
                        <input type="hidden" name="month" value="<?php echo esc_attr($ym); ?>">
                        <?php wp_nonce_field(self::A_CSV); ?>
                        <button type="submit" class="iqu-btn-primary">⬇ Download CSV</button>
                    </form>
                </div>
                <div class="iqu-billing-body"><p class="iqu-fld-hint"><?php echo esc_html($ds['note']); ?></p></div>

                <?php if (!$ds['rows']): ?>
                    <div class="iqu-empty-state">Nothing for this report and month.<?php echo $key === 'payments' || $key === 'reconciliation' ? ' If earlier months are missing, run Settings → Backfill payment history once.' : ''; ?></div>
                <?php else: ?>
                    <div class="iqu-dt-toolbar">
                        <label class="screen-reader-text" for="<?php echo esc_attr($tid); ?>-q">Filter rows</label>
                        <input id="<?php echo esc_attr($tid); ?>-q" type="search" placeholder="Filter…" data-filter-for="<?php echo esc_attr($tid); ?>">
                        <span class="iqu-dt-count" data-count-for="<?php echo esc_attr($tid); ?>" aria-live="polite"></span>
                    </div>
                    <div class="iqu-table-wrap iqu-dt-wrap">
                    <table class="iqu-tbl iqu-dt iqu-billing-tbl" id="<?php echo esc_attr($tid); ?>">
                        <thead><tr>
                        <?php foreach ($cols as $c => [$label, $type]): $num = in_array($type, ['num', 'money'], true); ?>
                            <th<?php echo $num ? ' class="is-num"' : ''; ?> data-type="<?php echo esc_attr($num ? 'num' : 'text'); ?>" aria-sort="none"><button type="button" class="iqu-sort"><?php echo esc_html($label); ?></button></th>
                        <?php endforeach; ?>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($ds['rows'] as $r): ?>
                            <tr>
                            <?php foreach ($cols as $c => [$label, $type]): $v = $r[$c] ?? ''; ?>
                                <?php if ($type === 'money'): ?>
                                    <td class="is-num" data-sort="<?php echo esc_attr(number_format((float) $v, 2, '.', '')); ?>"><?php echo esc_html(IQU_Pricing::format((float) $v)); ?></td>
                                <?php elseif ($type === 'num'): ?>
                                    <td class="is-num"><?php echo esc_html((string) $v); ?></td>
                                <?php elseif ($type === 'date'): ?>
                                    <td class="iqu-td-date" data-sort="<?php echo esc_attr((string) $v); ?>"><?php echo esc_html(self::day((string) $v)); ?></td>
                                <?php elseif ($type === 'url'): ?>
                                    <td><?php if ($v !== ''): ?><a href="<?php echo esc_url((string) $v); ?>" target="_blank" rel="noopener noreferrer">Invoice</a><?php else: ?>—<?php endif; ?></td>
                                <?php elseif ($c === 'family' && !empty($r['_account'])): ?>
                                    <td><a class="iqu-family-link" href="<?php echo esc_url(IQU_Billing_Page::family_url((int) $r['_account'])); ?>"><?php echo esc_html((string) $v); ?></a></td>
                                <?php elseif ($c === 'student' && !empty($r['_account'])): ?>
                                    <td class="iqu-td-name"><a class="iqu-family-link" href="<?php echo esc_url(IQU_Billing_Page::family_url((int) $r['_account'])); ?>"><?php echo esc_html((string) $v); ?></a></td>
                                <?php else: ?>
                                    <td<?php echo in_array($c, ['discount', 'status', 'students'], true) ? ' class="iqu-billing-wrap"' : ''; ?>><?php echo esc_html((string) ($v === '' ? '—' : $v)); ?></td>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="iqu-dt-none"><td colspan="<?php echo (int) count($cols); ?>" class="iqu-empty-state">No rows match the filter.</td></tr>
                        </tbody>
                        <?php if ($money_cols): ?>
                        <tfoot><tr>
                            <?php $first = true; foreach ($cols as $c => [$label, $type]): ?>
                                <?php if ($type === 'money'): ?>
                                    <td class="is-num"><?php echo esc_html(IQU_Pricing::format(array_sum(array_map(fn($r) => (float) ($r[$c] ?? 0), $ds['rows'])))); ?></td>
                                <?php else: ?>
                                    <td><?php echo $first ? esc_html('Total (' . count($ds['rows']) . ')') : ''; ?></td>
                                <?php endif; $first = false; ?>
                            <?php endforeach; ?>
                        </tr></tfoot>
                        <?php endif; ?>
                    </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    // ------------------------------------------------------------
    // CSV download
    // ------------------------------------------------------------

    public static function handle_export(): void
    {
        if (!current_user_can(self::CAP)) wp_die('You do not have permission to do this.', 403);
        check_admin_referer(self::A_CSV);
        [$key, $ym] = self::request($_POST);
        $ds = self::dataset($key, $ym);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="iqu-billing-' . str_replace('_', '-', $key) . '-' . $ym . '.csv"');
        $out = fopen('php://output', 'w');
        self::write_csv($out, $ds);
        fclose($out);
        exit;
    }

    /** Header row + one line per row: money as 2-decimal numbers, dates as YYYY-MM-DD (site time zone). */
    public static function write_csv($out, array $ds): void
    {
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map(fn($c) => self::csv_safe($c[0]), $ds['columns']), ',', '"', '');
        foreach ($ds['rows'] as $r) {
            $line = [];
            foreach ($ds['columns'] as $c => [$label, $type]) {
                $v = $r[$c] ?? '';
                if ($type === 'money') $v = number_format((float) $v, 2, '.', '');
                elseif ($type === 'date') $v = self::iso_day((string) $v);
                $line[] = self::csv_safe((string) $v);
            }
            fputcsv($out, $line, ',', '"', '');
        }
    }

    /** Neutralise spreadsheet formulas: = + - @ tab CR at the start get a leading apostrophe (plain numbers stay numbers). */
    public static function csv_safe(string $v): string
    {
        if ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false && !preg_match('/^-?\d+(\.\d+)?$/', $v)) return "'" . $v;
        return $v;
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    /** Validated [dataset, 'YYYY-MM'] from a request array. */
    private static function request(array $src): array
    {
        $key = sanitize_key(wp_unslash($src['dataset'] ?? 'students'));
        if (!isset(self::DATASETS[$key])) $key = 'students';
        $ym = sanitize_text_field(wp_unslash($src['month'] ?? ''));
        if (!IQU_Billing_History::is_month($ym)) $ym = (new DateTimeImmutable('now', wp_timezone()))->format('Y-m');
        return [$key, $ym];
    }

    private static function accounts_by_id(): array
    {
        $out = [];
        foreach (IQU_Billing_History::accounts() as $a) $out[(int) $a['id']] = $a;
        return $out;
    }

    private static function family_name(array $a): string
    {
        $g = trim((string) $a['guardian_name']);
        return $g !== '' ? $g : self::students($a);
    }

    private static function students(array $a): string
    {
        $names = [];
        foreach (IQU_Billing_DB::get_members((int) $a['id']) as $m) {
            $reg = IQU_Database::get_registration((int) $m['registration_id']);
            if ($reg) $names[] = trim($reg['first_name'] . ' ' . $reg['last_name']);
        }
        return implode(', ', $names);
    }

    /** Last moment of the month in UTC (MySQL format), for "created by the end of the month". */
    private static function month_end_utc(string $ym): string
    {
        $end = (new DateTimeImmutable($ym . '-01 00:00:00', wp_timezone()))->modify('first day of next month');
        return $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function day(string $v): string
    {
        if ($v === '') return '—';
        $ts = strlen($v) === 10 ? strtotime($v . ' 12:00:00 UTC') : strtotime($v . ' UTC');
        return $ts ? wp_date('j M Y', $ts) : '—';
    }

    private static function iso_day(string $v): string
    {
        if ($v === '') return '';
        if (strlen($v) === 10) return $v; // already a calendar date (first_charge_date)
        $ts = strtotime($v . ' UTC');
        return $ts ? wp_date('Y-m-d', $ts) : '';
    }
}
