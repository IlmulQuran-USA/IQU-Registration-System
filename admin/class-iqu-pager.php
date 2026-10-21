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
 */
final class IQU_Pager
{
    public const SIZES   = [10, 20, 30];
    public const DEFAULT = 10;

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

    /** Chip + 10 / 20 / 30 (top right of the table card). A new size starts at page 1. */
    public static function head(array $st, callable $url, string $label = 'Rows per page'): string
    {
        $out = '<div class="iqu-pager-head" data-iqu-total="' . (int) $st['total'] . '">'
            . '<span class="iqu-card-head-badge iqu-pager-chip" aria-live="polite">' . esc_html(self::showing($st['from'], $st['to'], $st['total'])) . '</span>'
            . '<div class="iqu-perpage-btns" role="group" aria-label="' . esc_attr($label) . '">';
        foreach (self::SIZES as $n) {
            $on = $st['per'] === $n;
            $out .= '<a href="' . esc_url($url(['per_page' => $n, 'paged' => null])) . '" class="iqu-perpage-btn' . ($on ? ' iqu-perpage-btn--active' : '') . '"'
                . ($on ? ' aria-current="true"' : '') . '>' . (int) $n . '</a>';
        }
        return $out . '</div></div>';
    }

    /** Page numbers under the table (nothing when everything fits on one page). */
    public static function foot(array $st, callable $url): string
    {
        if ($st['pages'] <= 1) return '';
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
        return '<nav class="iqu-pagination" aria-label="Pages"><span>Page ' . (int) $st['page'] . ' of ' . (int) $st['pages'] . '</span><div>'
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
