<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Waitlist_Admin
 *
 * IQU Registrations → Waitlist: families outside the US and Canada who asked to hear
 * when classes open in their country. Metric cards, filters (search, country, program,
 * date range) and a CSV export of the filtered list.
 *
 * Security: manage_options on the page and the export; nonce on the export;
 * CSV cells guarded against formulas; all output escaped.
 */
class IQU_Waitlist_Admin
{
    public const SLUG    = 'iqu-waitlist';
    private const CAP    = 'manage_options';
    private const EXPORT = 'iqu_waitlist_export';
    private const PER    = 50;
    private const PROGRAM_LABELS = ['free' => 'Enroll for Free', 'summer' => 'Summer', 'weekend' => 'Weekend'];

    public static function init(): void
    {
        // Priority 18: listed after Coupon List and before Billing (19) and Zeffy Payments (20).
        add_action('admin_menu', [__CLASS__, 'menu'], 18);
        add_action('admin_post_' . self::EXPORT, [__CLASS__, 'export']);
    }

    public static function menu(): void
    {
        add_submenu_page('iqu-registrations', 'Waitlist — IQU', 'Waitlist', self::CAP, self::SLUG, [__CLASS__, 'render']);
    }

    /**
     * Filters from a request array, validated.
     * @return array{country:string,program:string,s:string,from_date:string,to_date:string,more:array}
     *   more = search + UTC bounds for IQU_Waitlist::rows()/count().
     */
    private static function filters(array $src): array
    {
        $country = strtoupper(sanitize_text_field(wp_unslash($src['country'] ?? '')));
        $program = sanitize_key(wp_unslash($src['program'] ?? ''));
        $f = [
            'country'   => preg_match('/^[A-Z]{2}$/', $country) ? $country : '',
            'program'   => isset(self::PROGRAM_LABELS[$program]) ? $program : '',
            's'         => mb_substr(sanitize_text_field(wp_unslash($src['s'] ?? '')), 0, 100),
            'from_date' => self::day(sanitize_text_field(wp_unslash($src['from'] ?? ''))),
            'to_date'   => self::day(sanitize_text_field(wp_unslash($src['to'] ?? ''))),
        ];
        $f['more'] = [
            's'    => $f['s'],
            'from' => $f['from_date'] !== '' ? self::utc($f['from_date'] . ' 00:00:00') : '',
            'to'   => $f['to_date'] !== '' ? self::utc($f['to_date'] . ' 23:59:59') : '',
        ];
        return $f;
    }

    /** A real Y-m-d date, or ''. */
    private static function day(string $v): string
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return $d && $d->format('Y-m-d') === $v ? $v : '';
    }

    /** Site-time 'Y-m-d H:i:s' → UTC 'Y-m-d H:i:s' (created_at is stored in UTC). */
    private static function utc(string $local): string
    {
        return (new DateTimeImmutable($local, wp_timezone()))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** "Pakistan" for "PK" (PHP intl when available; otherwise the code). */
    public static function country_name(string $code): string
    {
        if ($code === '') return '';
        if (class_exists('Locale')) {
            $name = (string) Locale::getDisplayRegion('-' . $code, 'en');
            if ($name !== '' && $name !== $code) return $name;
        }
        return $code;
    }

    /** Where the family joined from, in words. */
    private static function source_label(string $source): string
    {
        return $source === 'modal' ? 'Enrollment form (pop-up)' : $source;
    }

    /** Leading = + - @ tab or CR would make a spreadsheet run the cell as a formula. */
    public static function csv_safe(string $v): string
    {
        if ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false && !preg_match('/^-?\d+(\.\d+)?$/', $v)) return "'" . $v;
        return $v;
    }

    public static function write_csv($out, array $rows): void
    {
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Email', 'Name', 'Country', 'Program', 'Source', 'Joined (UTC)'], ',', '"', '');
        foreach ($rows as $r) {
            fputcsv($out, array_map(fn($v) => self::csv_safe((string) $v), [
                $r['email'], $r['name'], $r['country'], self::PROGRAM_LABELS[$r['program']] ?? $r['program'], $r['source'], $r['created_at'],
            ]), ',', '"', '');
        }
    }

    public static function export(): void
    {
        if (!current_user_can(self::CAP)) wp_die('You do not have permission to do this.', 403);
        check_admin_referer(self::EXPORT);
        $f = self::filters($_GET);
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="iqu-waitlist-' . gmdate('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        self::write_csv($out, IQU_Waitlist::rows($f['country'], $f['program'], 0, 0, $f['more']));
        fclose($out);
        exit;
    }

    public static function render(): void
    {
        if (!current_user_can(self::CAP)) wp_die('You do not have permission to view this page.', 403);
        $f        = self::filters($_GET);
        $page     = max(1, absint($_GET['paged'] ?? 1));
        $total    = IQU_Waitlist::count($f['country'], $f['program'], $f['more']);
        $rows     = IQU_Waitlist::rows($f['country'], $f['program'], self::PER, ($page - 1) * self::PER, $f['more']);
        $query    = array_filter(['country' => $f['country'], 'program' => $f['program'], 's' => $f['s'], 'from' => $f['from_date'], 'to' => $f['to_date']]);
        $export   = wp_nonce_url(add_query_arg(['action' => self::EXPORT] + $query, admin_url('admin-post.php')), self::EXPORT);
        $stats    = IQU_Waitlist::stats(self::utc((new DateTimeImmutable('now', wp_timezone()))->format('Y-m-01 00:00:00')));
        $pages    = (int) ceil($total / self::PER);
        $filtered = $query !== [];
        $plain    = admin_url('admin.php?page=' . self::SLUG);
        // "Pakistan 12 · Bangladesh 9" — every part escaped here.
        $list = function (array $counts, callable $label): string {
            $parts = [];
            foreach ($counts as $k => $n) $parts[] = '<span>' . esc_html($label((string) $k)) . ' <strong>' . (int) $n . '</strong></span>';
            return $parts ? implode('<span class="iqu-wl-sep" aria-hidden="true">·</span>', $parts) : '<span class="iqu-wl-muted">None yet</span>';
        };
        ?>
        <div class="wrap iqu-admin-wrap iqu-waitlist">
            <h1 class="screen-reader-text">Waitlist</h1>
            <?php IQU_Admin::top_bar('Waitlist', 'Ilm-ul-Quran USA — Admin Panel', self::SLUG); ?>
            <p class="iqu-wl-intro">Families outside the United States and Canada who asked to be told when classes open in their country. Only the email, the optional name, a 2-letter country code, the program and where they joined from are kept.</p>

            <div class="iqu-metrics iqu-metrics--four">
                <div class="iqu-metric">
                    <div class="iqu-metric-accent iqu-metric-accent--total"></div>
                    <div class="iqu-metric-num"><?php echo (int) $stats['total']; ?></div>
                    <div class="iqu-metric-lbl">Total families</div>
                </div>
                <div class="iqu-metric">
                    <div class="iqu-metric-accent iqu-metric-accent--free"></div>
                    <div class="iqu-metric-num"><?php echo (int) $stats['month']; ?></div>
                    <div class="iqu-metric-lbl">Joined this month</div>
                </div>
                <div class="iqu-metric">
                    <div class="iqu-metric-accent iqu-metric-accent--l1"></div>
                    <div class="iqu-metric-lbl">Top countries</div>
                    <div class="iqu-wl-list"><?php echo $list($stats['countries'], [__CLASS__, 'country_name']); ?></div>
                </div>
                <div class="iqu-metric">
                    <div class="iqu-metric-accent iqu-metric-accent--l2"></div>
                    <div class="iqu-metric-lbl">By program</div>
                    <div class="iqu-wl-list"><?php echo $list($stats['programs'], fn($k) => self::PROGRAM_LABELS[$k] ?? $k); ?></div>
                </div>
            </div>

            <form method="GET" class="iqu-filter-bar">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
                <input type="search" name="s" value="<?php echo esc_attr($f['s']); ?>" placeholder="Search name or email…" aria-label="Search name or email">
                <select name="country" aria-label="Country">
                    <option value="">All countries</option>
                    <?php foreach (IQU_Waitlist::countries() as $c): ?>
                        <option value="<?php echo esc_attr($c); ?>" <?php selected($f['country'], $c); ?>><?php echo esc_html(self::country_name($c)); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="program" aria-label="Program">
                    <option value="">All programs</option>
                    <?php foreach (self::PROGRAM_LABELS as $k => $label): ?>
                        <option value="<?php echo esc_attr($k); ?>" <?php selected($f['program'], $k); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="iqu-wl-date"><span>From</span><input type="date" name="from" value="<?php echo esc_attr($f['from_date']); ?>"></label>
                <label class="iqu-wl-date"><span>To</span><input type="date" name="to" value="<?php echo esc_attr($f['to_date']); ?>"></label>
                <button type="submit" class="button">Filter</button>
                <?php if ($filtered): ?>
                    <a class="iqu-wl-clear" href="<?php echo esc_url($plain); ?>">Clear filters</a>
                <?php endif; ?>
                <a href="<?php echo esc_url($export); ?>" class="iqu-export-btn"><span class="dashicons dashicons-download" aria-hidden="true"></span>Export CSV</a>
            </form>

            <div class="iqu-table-wrap">
                <?php if (!$rows): ?>
                    <div class="iqu-empty-state iqu-wl-empty">
                        <span class="dashicons dashicons-groups iqu-wl-empty-icon" aria-hidden="true"></span>
                        <?php if ($filtered): ?>
                            <span class="iqu-wl-empty-line">No families match these filters.</span>
                            <a href="<?php echo esc_url($plain); ?>">Show everyone</a>
                        <?php else: ?>
                            <span class="iqu-wl-empty-line">No one on the waitlist yet.</span>
                            When a family outside the US and Canada leaves their email on an enrollment form, they appear here.
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                <table class="iqu-tbl iqu-wl-tbl">
                    <caption class="screen-reader-text">Waitlist families</caption>
                    <thead><tr><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Country</th><th scope="col">Program</th><th scope="col">Source</th><th scope="col">Joined</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r):
                        $ts = (int) strtotime($r['created_at'] . ' UTC'); ?>
                        <tr>
                            <td class="iqu-td-name"><?php echo $r['name'] !== '' ? '<strong>' . esc_html($r['name']) . '</strong>' : '<span class="iqu-wl-muted">—</span>'; ?></td>
                            <td><a href="<?php echo esc_url('mailto:' . $r['email']); ?>"><?php echo esc_html($r['email']); ?></a></td>
                            <td><?php echo $r['country'] !== '' ? esc_html(self::country_name($r['country'])) . ' <span class="iqu-wl-muted">(' . esc_html($r['country']) . ')</span>' : '<span class="iqu-wl-muted">—</span>'; ?></td>
                            <td><?php echo esc_html(self::PROGRAM_LABELS[$r['program']] ?? $r['program']); ?></td>
                            <td><?php echo esc_html(self::source_label((string) $r['source'])); ?></td>
                            <td class="iqu-wl-when"><?php echo esc_html(wp_date('j M Y', $ts)); ?> <span class="iqu-wl-muted"><?php echo esc_html(wp_date('g:i a', $ts)); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                <div class="iqu-pagination">
                    <span><?php echo (int) $total; ?> <?php echo $total === 1 ? 'family' : 'families'; ?><?php if ($pages > 1): ?> · Page <?php echo (int) $page; ?> of <?php echo (int) $pages; ?><?php endif; ?></span>
                    <?php if ($pages > 1): ?>
                    <div><?php echo wp_kses_post(paginate_links([
                        'base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $page, 'total' => $pages,
                    ])); ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }
}
