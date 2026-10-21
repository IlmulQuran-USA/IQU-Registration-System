<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Pager
 *
 * The one pager every IQU table uses (the Student Billing pattern): a "Showing 1–10 of 40" chip
 * immediately left of a 10 / 20 / 30 control at the top right of the table card, and page numbers
 * (« Prev · 1 2 3 … · Next ») under the table. Default 10 rows.
 *
 * - Lists rendered on the server page on the server: from() reads per_page / paged, the caller
 *   runs its query with LIMIT/OFFSET and counts with the same WHERE, then prints head() and foot().
 *   Links keep the list's filters ($url); a new page size goes back to page 1.
 * - Tables filtered in the browser (Reports, Payments, the family page …) print client_head() and
 *   client_foot() around a table[data-iqu-pager]; admin/js/pager.js pages the rows that match the
 *   filter and keeps the state in the URL.
 * - The searchable lists (Enroll lists, Coupons, Waitlist, Student Billing, Zeffy, Recent Submissions)
 *   send every row with the page while the list has at most LOCAL_MAX rows ("local"): each row carries
 *   the values it is filtered on (row_attrs), the server marks the rows the request's filters and page
 *   show (local_view), and pager.js searches, filters and pages in the browser — no request per key or
 *   page. A longer list ("remote") keeps the server query; pager.js then asks ajax() for the list part
 *   alone (nonce + capability), at most once a second. Without JavaScript both work as plain links.
 */
final class IQU_Pager
{
    public const SIZES   = [10, 20, 30];
    public const DEFAULT = 10;

    /** A list with at most this many rows is searched and paged in the browser. */
    public const LOCAL_MAX = 1000;

    /** admin-ajax action (and nonce action) that returns one list's region for a longer list. */
    public const AJAX = 'iqu_list_region';

    /** @var array<string, array{0:callable, 1:string}> region id => [renderer, capability] */
    private static $remote = [];

    public static function init(): void
    {
        add_action('wp_ajax_' . self::AJAX, [__CLASS__, 'ajax']);
    }

    /** True when a list of $rows rows is sent whole and searched in the browser. */
    public static function local(int $rows): bool
    {
        return $rows <= self::LOCAL_MAX;
    }

    /**
     * A list that can answer ajax(): $render prints its region (data-iqu-live-region) for the filters
     * in $_GET, exactly as the page prints it. Only users with $cap get it.
     */
    public static function remote(string $id, callable $render, string $cap = 'manage_options'): void
    {
        self::$remote[$id] = [$render, $cap];
    }

    /**
     * GET admin-ajax.php?action=iqu_list_region&list=ID&_ajax_nonce=…&<the list's own query>: the list's
     * region only (rows, chip, page numbers) as JSON { html }, not the whole admin page. Read-only.
     */
    public static function ajax(): void
    {
        $id = sanitize_key(wp_unslash($_GET['list'] ?? ''));
        if (!isset(self::$remote[$id])) wp_send_json_error('Unknown list.', 400);
        if (!check_ajax_referer(self::AJAX, false, false)) wp_send_json_error('This page has expired. Please reload it.', 403);
        [$render, $cap] = self::$remote[$id];
        if (!current_user_can($cap)) wp_send_json_error('You do not have permission to view this list.', 403);
        nocache_headers();
        // paginate_links() copies the request's own query into every page link: make that the admin page's
        // (admin.php?page=…&filters), not this request's (action, list, nonce), so the links are the page's links.
        $query = wp_unslash($_GET);
        unset($query['action'], $query['list'], $query['_ajax_nonce']);
        $_SERVER['REQUEST_URI'] = (string) wp_parse_url(admin_url('admin.php'), PHP_URL_PATH) . ($query ? '?' . http_build_query($query) : '');
        ob_start();
        $render();
        wp_send_json_success(['html' => (string) ob_get_clean()]);
    }

    /**
     * The attributes of a list's region: its id, and how pager.js updates it ("local": every row is
     * here; "remote": the admin-ajax URL and nonce for ajax()).
     */
    public static function region_attrs(string $id, bool $local): string
    {
        $out = ' data-iqu-live-region="' . esc_attr($id) . '" data-iqu-mode="' . ($local ? 'local' : 'remote') . '"';
        if (!$local) {
            $out .= ' data-iqu-ajax="' . esc_url(admin_url('admin-ajax.php')) . '" data-iqu-nonce="' . esc_attr(wp_create_nonce(self::AJAX)) . '"';
        }
        return $out;
    }

    /**
     * A local list's first view, from the request: the pager over the matching rows, the ids the
     * filters leave out and the ids on the current page. $ids = every row in the list's order,
     * $match = the ids the list's own query returns for the request's filters.
     * @return array{pager:array, out:array<int|string,bool>, shown:array<int|string,bool>}
     */
    public static function local_view(array $ids, array $match, array $req): array
    {
        $in = array_fill_keys(array_map('strval', $match), true);
        $matching = array_values(array_filter($ids, fn($id) => isset($in[(string) $id])));
        $pager = self::from($req, count($matching));
        $out = [];
        foreach ($ids as $id) if (!isset($in[(string) $id])) $out[(string) $id] = true;
        return [
            'pager' => $pager,
            'out'   => $out,
            'shown' => array_fill_keys(array_map('strval', array_slice($matching, $pager['offset'], $pager['per'])), true),
        ];
    }

    /**
     * A local list's table: paged by pager.js with the server's own parameter names (per_page,
     * paged), so a link, a reload and the no-JavaScript page all show the same rows.
     */
    public static function local_attrs(): string
    {
        return ' data-iqu-pager="list" data-iqu-pp="per_page" data-iqu-pg="paged"';
    }

    /**
     * A row of a local list: data-iqu-NAME for each value (v-FILTER: the value a filter compares,
     * several values one per line; s: the text the search looks in, one field per line; wa / id: the
     * WhatsApp digits and number the registration search also matches), and its first state (left
     * out by the filters: data-iqu-out; not on the current page: hidden).
     */
    public static function row_attrs(array $attrs, bool $out = false, bool $shown = true): string
    {
        $html = '';
        foreach ($attrs as $k => $v) $html .= ' data-iqu-' . $k . '="' . esc_attr(is_array($v) ? implode("\n", array_map('strval', $v)) : (string) $v) . '"';
        return $html . ($out ? ' data-iqu-out' : '') . ($shown ? '' : ' hidden');
    }

    /**
     * A local list's two <tbody>: the rows on the current page (or the "nothing matches" row), then
     * every other row in a hidden <tbody data-iqu-stash>, each with its place in the list (data-iqu-i),
     * so stripes and the last row's border are as on a server page — with or without JavaScript.
     * $key = the row's id column, or a Closure that returns the id; $values($row) = the row's data
     * (row_attrs); $print($row, $attrs) prints the row (and a detail row after it); $none = the cells
     * of the "nothing matches" row ('' when the list has none). $view = local_view()'s result.
     */
    public static function local_body(array $rows, $key, callable $values, array $view, string $none, callable $print): void
    {
        $top = $rest = '';
        foreach (array_values($rows) as $i => $r) {
            $id = (string) ($key instanceof Closure ? $key($r) : $r[$key]);
            $on = isset($view['shown'][$id]);
            ob_start();
            $print($r, self::row_attrs(['i' => $i] + $values($r), isset($view['out'][$id]), $on));
            if ($on) $top .= ob_get_clean(); else $rest .= ob_get_clean();
        }
        $empty = (int) $view['pager']['total'] === 0;
        $none = $none !== '' ? '<tr class="iqu-pager-skip" data-iqu-none' . ($empty ? '' : ' hidden') . '>' . $none . '</tr>' : '';
        echo '<tbody>' . ($empty ? $none : '') . $top . '</tbody><tbody hidden data-iqu-stash>' . ($empty ? '' : $none) . $rest . '</tbody>';
    }

    /**
     * What a local list says when nothing matches, one variant per case (pager.js shows the right one):
     * a search: "No results for ‘xyz’." + Clear search; filters (or no $empty_html): $filter_html;
     * nothing set: $empty_html. Both HTML strings are escaped by the caller.
     */
    public static function none_variants(string $search, string $clear_url, string $filter_html, ?string $empty_html = null, bool $filtered = false): string
    {
        $pick = $search !== '' ? 'search' : ($empty_html === null || $filtered ? 'filter' : 'empty');
        $out = '<span data-iqu-none-search' . ($pick === 'search' ? '' : ' hidden') . '>' . self::no_results($search, $clear_url) . '</span>'
            . '<span data-iqu-none-filter' . ($pick === 'filter' ? '' : ' hidden') . '>' . $filter_html . '</span>';
        if ($empty_html !== null) $out .= '<span data-iqu-none-empty' . ($pick === 'empty' ? '' : ' hidden') . '>' . $empty_html . '</span>';
        return $out;
    }

    /**
     * Page size and page from a request, the page clamped to the last one.
     * @return array{per:int, page:int, pages:int, total:int, offset:int, from:int, to:int}
     */
    public static function from(array $req, int $total): array
    {
        $per = (int) ($req['per_page'] ?? self::DEFAULT);
        if (!in_array($per, self::SIZES, true)) $per = self::DEFAULT;
        $pages = max(1, (int) ceil($total / $per));
        $page  = min($pages, max(1, absint($req['paged'] ?? 1)));
        return [
            'per'    => $per,
            'page'   => $page,
            'pages'  => $pages,
            'total'  => $total,
            'offset' => ($page - 1) * $per,
            'from'   => $total ? ($page - 1) * $per + 1 : 0,
            'to'     => min($total, $page * $per),
        ];
    }

    /** The page size alone (for a query that must run before the total is known). */
    public static function per(array $req): int
    {
        $per = (int) ($req['per_page'] ?? self::DEFAULT);
        return in_array($per, self::SIZES, true) ? $per : self::DEFAULT;
    }

    public static function showing(int $from, int $to, int $total): string
    {
        return $total ? 'Showing ' . $from . '–' . $to . ' of ' . $total : 'Showing 0 of 0';
    }

    /**
     * A link builder for an admin page: the page slug plus the list's filters (empty ones left out,
     * values encoded — add_query_arg does not encode). Callers add per_page / paged.
     */
    public static function url(string $slug, array $query): callable
    {
        $query = array_filter($query, fn($v) => $v !== '' && $v !== null);
        return function (array $args = []) use ($slug, $query): string {
            $all = array_filter($args + $query, fn($v) => $v !== '' && $v !== null);
            if ((int) ($all['per_page'] ?? self::DEFAULT) === self::DEFAULT) unset($all['per_page']);
            if ((int) ($all['paged'] ?? 1) === 1 && ($all['paged'] ?? '') !== '%#%') unset($all['paged']);
            $q = ['page' => $slug];
            foreach ($all as $k => $v) $q[$k] = $v === '%#%' ? $v : rawurlencode((string) $v);
            return add_query_arg($q, admin_url('admin.php'));
        };
    }

    /**
     * Chip + 10 / 20 / 30 (top right of the table card). A new size starts at page 1. With $table_id
     * (a local list) pager.js takes it over once the page has loaded; the links stay for no JavaScript.
     */
    public static function head(array $st, callable $url, string $label = 'Rows per page', string $table_id = ''): string
    {
        $out = '<div class="iqu-pager-head" data-iqu-total="' . (int) $st['total'] . '"'
            . ($table_id !== '' ? ' data-iqu-pager-head="' . esc_attr($table_id) . '" data-iqu-pager-label="' . esc_attr($label) . '"' : '') . '>'
            . '<span class="iqu-card-head-badge iqu-pager-chip" aria-live="polite">' . esc_html(self::showing($st['from'], $st['to'], $st['total'])) . '</span>'
            . '<div class="iqu-perpage-btns" role="group" aria-label="' . esc_attr($label) . '">';
        foreach (self::SIZES as $n) {
            $on = $st['per'] === $n;
            $out .= '<a href="' . esc_url($url(['per_page' => $n, 'paged' => null])) . '" class="iqu-perpage-btn' . ($on ? ' iqu-perpage-btn--active' : '') . '"'
                . ($on ? ' aria-current="true"' : '') . '>' . (int) $n . '</a>';
        }
        return $out . '</div></div>';
    }

    /**
     * Page numbers under the table (nothing when everything fits on one page). With $table_id (a local
     * list) the element is always there, hidden on one page, for pager.js to fill when the rows change.
     */
    public static function foot(array $st, callable $url, string $table_id = ''): string
    {
        $mark = $table_id !== '' ? ' data-iqu-pager-foot="' . esc_attr($table_id) . '"' : '';
        if ($st['pages'] <= 1) return $mark !== '' ? '<nav class="iqu-pagination"' . $mark . ' aria-label="Pages" hidden></nav>' : '';
        $links = paginate_links([
            'base'      => $url(['per_page' => $st['per'], 'paged' => '%#%']),
            'format'    => '',
            'current'   => $st['page'],
            'total'     => $st['pages'],
            'end_size'  => 2,
            'mid_size'  => 3,
            'prev_text' => '« Prev',
            'next_text' => 'Next »',
        ]);
        return '<nav class="iqu-pagination"' . $mark . ' aria-label="Pages"><span>Page ' . (int) $st['page'] . ' of ' . (int) $st['pages'] . '</span><div>'
            . wp_kses_post((string) $links) . '</div></nav>';
    }

    /** Browser-side table: where pager.js puts the chip + 10 / 20 / 30 (filled in on load). */
    public static function client_head(string $table_id, string $label = 'Rows per page'): string
    {
        return '<div class="iqu-pager-head" data-iqu-pager-head="' . esc_attr($table_id) . '" data-iqu-pager-label="' . esc_attr($label) . '"></div>';
    }

    /** Browser-side table: where pager.js puts the page numbers. */
    public static function client_foot(string $table_id): string
    {
        return '<nav class="iqu-pagination" data-iqu-pager-foot="' . esc_attr($table_id) . '" aria-label="Pages" hidden></nav>';
    }

    // ── Live search (admin/js/pager.js): a list's form[data-iqu-live="ID"] updates [data-iqu-live-region="ID"]
    //    as you type (≈300 ms) or change a filter; without JavaScript the form submits as before. ──

    /** "Searching…" / "12 results" next to the search box, read out by screen readers. */
    public static function live_status(): string
    {
        return '<span class="iqu-live-status" data-iqu-live-status aria-live="polite"></span>';
    }

    /** The submit button only without JavaScript (with it, results update as you type). */
    public static function noscript_submit(string $label = 'Search', string $class = 'button'): string
    {
        return '<noscript><button type="submit" class="' . esc_attr($class) . '">' . esc_html($label) . '</button></noscript>';
    }

    /** Empty state of a search: "No results for ‘xyz’." and a link that clears the search (other filters kept). */
    public static function no_results(string $search, string $clear_url): string
    {
        return '<span class="iqu-no-results">No results for ‘' . esc_html($search) . '’.</span> '
            . '<a href="' . esc_url($clear_url) . '" data-iqu-clear>Clear search</a>';
    }

    /**
     * The attributes that make a table page in the browser: its id, a short key for the URL
     * ("{key}_pp" page size, "{key}_pg" page, "{key}_q" filter text).
     */
    public static function client_attrs(string $key): string
    {
        return ' data-iqu-pager="' . esc_attr($key) . '"';
    }
}
