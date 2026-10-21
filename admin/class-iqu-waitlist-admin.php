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
    private const PROGRAM_LABELS = ['free' => 'Enroll for Free', 'summer' => 'Summer', 'weekend' => 'Weekend'];

    public static function init(): void
    {
        // Priority 18: listed after Coupon List and before Billing (19) and Zeffy Payments (20).
        add_action('admin_menu', [__CLASS__, 'menu'], 18);
        add_action('admin_post_' . self::EXPORT, [__CLASS__, 'export']);
        // more than IQU_Pager::LOCAL_MAX families: the table card alone, for live search and paging
        IQU_Pager::remote('iqu-waitlist', function () { self::list_region(self::list_context()); }, self::CAP);
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
        return IQU_CSV::safe($v);
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

    /**
     * The list for the request's filters and page. Up to IQU_Pager::LOCAL_MAX families: every one is
     * sent and pager.js filters and pages them (local); more: one page (LIMIT/OFFSET).
     */
    private static function list_context(): array
    {
        $f     = self::filters($_GET);
        $query = array_filter(['country' => $f['country'], 'program' => $f['program'], 's' => $f['s'], 'from' => $f['from_date'], 'to' => $f['to_date']]);
        $local = IQU_Pager::local(IQU_Waitlist::count());
        $out = $shown = [];
        if ($local) {
            $rows  = IQU_Waitlist::rows();
            $set   = $f['country'] !== '' || $f['program'] !== '' || $f['s'] !== '' || $f['from_date'] !== '' || $f['to_date'] !== '';
            $match = $set ? array_column(IQU_Waitlist::rows($f['country'], $f['program'], 0, 0, $f['more']), 'id') : array_column($rows, 'id');
            ['pager' => $pager, 'out' => $out, 'shown' => $shown] = IQU_Pager::local_view(array_column($rows, 'id'), $match, $_GET);
        } else {
            // The shared pager (IQU_Pager): counted with the same filters, then one page (LIMIT/OFFSET).
            $pager = IQU_Pager::from($_GET, IQU_Waitlist::count($f['country'], $f['program'], $f['more']));
            $rows  = IQU_Waitlist::rows($f['country'], $f['program'], $pager['per'], $pager['offset'], $f['more']);
        }
        return ['f' => $f, 'query' => $query, 'local' => $local, 'rows' => $rows, 'pager' => $pager, 'out' => $out, 'shown' => $shown];
    }

    public static function render(): void
    {
        if (!current_user_can(self::CAP)) wp_die('You do not have permission to view this page.', 403);
        $list_ctx = self::list_context();
        ['f' => $f, 'query' => $query, 'pager' => $pager] = $list_ctx;
        // add_query_arg does not encode: a search with & or # would cut the link (and its nonce) short.
        $export   = wp_nonce_url(add_query_arg(['action' => self::EXPORT] + rawurlencode_deep($query), admin_url('admin-post.php')), self::EXPORT);
        $stats    = IQU_Waitlist::stats(self::utc((new DateTimeImmutable('now', wp_timezone()))->format('Y-m-01 00:00:00')));
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
                <div class="iqu-metric iqu-stat">
                    <div class="iqu-metric-accent iqu-metric-accent--total"></div>
                    <?php echo IQU_Icons::stat_head('users', 'navy', (string) (int) $stats['total']); ?>
                    <div class="iqu-metric-lbl">Total families</div>
                </div>
                <div class="iqu-metric iqu-stat">
                    <div class="iqu-metric-accent iqu-metric-accent--free"></div>
                    <?php echo IQU_Icons::stat_head('calendar', 'blue', (string) (int) $stats['month']); ?>
                    <div class="iqu-metric-lbl">Joined this month</div>
                </div>
                <?php // the two list cards: the icon beside the card's title, the list under it ?>
                <div class="iqu-metric iqu-stat">
                    <div class="iqu-metric-accent iqu-metric-accent--l1"></div>
                    <div class="iqu-stat-head"><?php echo IQU_Icons::badge('globe', 'green'); ?><div class="iqu-metric-lbl">Top countries</div></div>
                    <div class="iqu-wl-list"><?php echo $list($stats['countries'], [__CLASS__, 'country_name']); ?></div>
                </div>
                <div class="iqu-metric iqu-stat">
                    <div class="iqu-metric-accent iqu-metric-accent--l2"></div>
                    <div class="iqu-stat-head"><?php echo IQU_Icons::badge('book-open', 'gold'); ?><div class="iqu-metric-lbl">By program</div></div>
                    <div class="iqu-wl-list"><?php echo $list($stats['programs'], fn($k) => self::PROGRAM_LABELS[$k] ?? $k); ?></div>
                </div>
            </div>

            <form method="GET" class="iqu-filter-bar" role="search" data-iqu-live="iqu-waitlist">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
                <input type="search" name="s" value="<?php echo esc_attr($f['s']); ?>" placeholder="Search name or email…" aria-label="Search name or email" data-iqu-search="like" data-iqu-max="100">
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
                <label class="iqu-wl-date"><span>From</span><input type="date" name="from" value="<?php echo esc_attr($f['from_date']); ?>" data-iqu-op="ge" data-iqu-on="day"></label>
                <label class="iqu-wl-date"><span>To</span><input type="date" name="to" value="<?php echo esc_attr($f['to_date']); ?>" data-iqu-op="le" data-iqu-on="day"></label>
                <?php if ($pager['per'] !== IQU_Pager::DEFAULT): ?><input type="hidden" name="per_page" value="<?php echo (int) $pager['per']; ?>"><?php endif; ?>
                <?php echo IQU_Pager::live_status() . IQU_Pager::noscript_submit('Filter'); // results update as you type ?>
                <a class="iqu-wl-clear" href="<?php echo esc_url($plain); ?>" data-iqu-clear-all data-iqu-reset="iqu-waitlist"<?php echo $filtered ? '' : ' hidden'; ?>>Clear filters</a>
                <a href="<?php echo esc_url($export); ?>" class="iqu-export-btn"><span class="dashicons dashicons-download" aria-hidden="true"></span>Export CSV</a>
            </form>

            <?php self::list_region($list_ctx); ?>
        </div>
        <?php
    }

    /** The families card (data-iqu-live-region="iqu-waitlist"): printed by the page and by IQU_Pager::ajax(). */
    private static function list_region(array $c): void
    {
        ['f' => $f, 'query' => $query, 'local' => $local, 'rows' => $rows, 'pager' => $pager] = $c;
        $list_url = IQU_Pager::url(self::SLUG, $query);
        $filtered = $query !== [];
        $plain    = admin_url('admin.php?page=' . self::SLUG);
        $tid      = $local && $rows ? 'iqu-waitlist-tbl' : '';
        $clear    = IQU_Pager::url(self::SLUG, array_diff_key($query, ['s' => 1]))(['per_page' => $pager['per']]);
        $pick     = $f['s'] !== '' ? 'search' : ($filtered ? 'filter' : 'empty');
        $empty    = (int) $pager['total'] === 0;
        // a local list: each "nothing matches" text is here, pager.js shows the one that fits
        $v = fn(string $k) => $local ? '<span data-iqu-none-' . $k . ($pick === $k ? '' : ' hidden') . '>' : '';
        $e = $local ? '</span>' : '';
        ?>
            <div class="iqu-card iqu-list-card"<?php echo IQU_Pager::region_attrs('iqu-waitlist', $local); ?>>
                <div class="iqu-card-head iqu-list-head">
                    <span class="iqu-card-head-title">Families</span>
                    <?php echo IQU_Pager::head($pager, $list_url, 'Families per page', $tid); // escaped inside ?>
                </div>
            <div class="iqu-table-wrap">
                <?php if (!$rows || $local): ?>
                    <div class="iqu-empty-state iqu-wl-empty"<?php echo $local ? ' data-iqu-none' . ($empty ? '' : ' hidden') : ''; ?>>
                        <span class="dashicons dashicons-groups iqu-wl-empty-icon" aria-hidden="true"></span>
                        <?php if ($local || $pick === 'search'): echo $v('search'); ?>
                            <span class="iqu-wl-empty-line"><?php echo IQU_Pager::no_results($f['s'], $clear); // escaped inside ?></span>
                        <?php echo $e; endif; if ($local || $pick === 'filter'): echo $v('filter'); ?>
                            <span class="iqu-wl-empty-line">No families match these filters.</span>
                            <a href="<?php echo esc_url($plain); ?>" data-iqu-reset="iqu-waitlist">Show everyone</a>
                        <?php echo $e; endif; if ($local || $pick === 'empty'): echo $v('empty'); ?>
                            <span class="iqu-wl-empty-line">No one on the waitlist yet.</span>
                            When a family outside the US and Canada leaves their email on an enrollment form, they appear here.
                        <?php echo $e; endif; ?>
                    </div>
                <?php endif; ?>
                <?php if ($rows): ?>
                <table class="iqu-tbl iqu-wl-tbl"<?php echo $local ? ' id="iqu-waitlist-tbl"' . IQU_Pager::local_attrs() . ' data-iqu-none-hide' . ($empty ? ' hidden' : '') : ''; ?>>
                    <caption class="screen-reader-text">Waitlist families</caption>
                    <thead><tr><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Country</th><th scope="col">Program</th><th scope="col">Source</th><th scope="col">Joined</th></tr></thead>
                    <?php if ($local):
                        // every family: what pager.js filters on (country, program, the day joined in the site's time zone; name and email)
                        IQU_Pager::local_body($rows, 'id', fn(array $r) => [
                            'v-country' => (string) $r['country'], 'v-program' => (string) $r['program'],
                            'v-day' => wp_date('Y-m-d', (int) strtotime($r['created_at'] . ' UTC')),
                            's' => [$r['email'], $r['name']],
                        ], $c, '', fn(array $r, string $attrs) => self::list_row($r, $attrs));
                    else: ?>
                    <tbody>
                    <?php foreach ($rows as $r) self::list_row($r, ''); ?>
                    </tbody>
                    <?php endif; ?>
                </table>
                <?php endif; ?>
            </div>
                <?php echo IQU_Pager::foot($pager, $list_url, $tid); // escaped inside ?>
            </div>
        <?php
    }

    /** One family ($attrs: IQU_Pager::row_attrs on a local list). */
    private static function list_row(array $r, string $attrs): void
    {
        $ts = (int) strtotime($r['created_at'] . ' UTC'); ?>
                        <tr<?php echo $attrs; ?>>
                            <td class="iqu-td-name"><?php echo $r['name'] !== '' ? '<strong>' . esc_html($r['name']) . '</strong>' : '<span class="iqu-wl-muted">—</span>'; ?></td>
                            <td><a href="<?php echo esc_url('mailto:' . $r['email']); ?>"><?php echo esc_html($r['email']); ?></a></td>
                            <td><?php echo $r['country'] !== '' ? esc_html(self::country_name($r['country'])) . ' <span class="iqu-wl-muted">(' . esc_html($r['country']) . ')</span>' : '<span class="iqu-wl-muted">—</span>'; ?></td>
                            <td><?php echo esc_html(self::PROGRAM_LABELS[$r['program']] ?? $r['program']); ?></td>
                            <td><?php echo esc_html(self::source_label((string) $r['source'])); ?></td>
                            <td class="iqu-wl-when"><?php echo esc_html(wp_date('j M Y', $ts)); ?> <span class="iqu-wl-muted"><?php echo esc_html(wp_date('g:i a', $ts)); ?></span></td>
                        </tr>
        <?php
    }
}
