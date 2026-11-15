<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Back (3.7.12)
 *
 * Every "← Back…" button returns to the exact screen the admin came from — the list, tab, filter, search and page
 * number, the family page or Student Billing — also after saving on that page.
 *   - Arriving on a page with a back button (student page, family page, ledger view) from another of this plugin's
 *     pages, that address is kept in the URL as ?iqu_back= (one redirect on arrival, read from the referer).
 *   - Every form on the page posts from that URL; after the save, the redirect back to the page keeps it (wp_redirect
 *     filter: from the posted iqu_back field, else from the referer's).
 *   - Opened directly (Telegram link, bookmark): the page's own fallback (the student's program list, Student Billing,
 *     the Coupon List).
 * Only this plugin's own admin pages (admin.php?page=iqu-…) are accepted as a return target.
 */
class IQU_Back
{
    public const PARAM = 'iqu_back';

    /** Pages with a back button: [page slug => the query key that identifies the record]. */
    private const PAGES = ['iqu-view-registration' => 'id', 'iqu-billing-message' => 'account', 'iqu-coupon-list' => 'ledger'];

    public static function init(): void
    {
        add_action('admin_init', [__CLASS__, 'on_arrival'], 1);
        add_filter('wp_redirect', [__CLASS__, 'keep_on_redirect'], 10, 1);
    }

    /** True for this site's admin.php?page=iqu-… (a plugin admin page), nothing else. */
    public static function allowed(string $url): bool
    {
        if ($url === '' || strlen($url) > 2000 || preg_match('/[\x00-\x1f]/', $url)) return false;
        $u = wp_parse_url($url);
        $a = wp_parse_url(admin_url('admin.php'));
        if (!$u || !$a || empty($u['path']) || (isset($u['host']) && strtolower($u['host']) !== strtolower((string) ($a['host'] ?? '')))) return false;
        if (isset($u['scheme']) && !in_array(strtolower($u['scheme']), ['http', 'https'], true)) return false;
        if (!isset($u['host']) && strpos($url, '/') !== 0) return false; // relative paths other than /wp-admin/… are not accepted
        if (rtrim($u['path'], '/') !== rtrim((string) $a['path'], '/')) return false;
        $q = [];
        parse_str((string) ($u['query'] ?? ''), $q);
        return is_string($q['page'] ?? null) && preg_match('/^iqu-[a-z0-9-]{1,60}$/', $q['page']);
    }

    /** A URL's query as an array. */
    private static function query(string $url): array
    {
        $q = [];
        parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $q);
        return $q;
    }

    /** The record page this request shows: [slug, record key value] or null. */
    private static function this_page(array $q): ?array
    {
        $page = is_string($q['page'] ?? null) ? $q['page'] : '';
        if (!isset(self::PAGES[$page])) return null;
        $key = self::PAGES[$page];
        $val = is_scalar($q[$key] ?? null) ? (string) $q[$key] : '';
        return $val === '' ? null : [$page, $val];
    }

    /** The return target carried by this request (?iqu_back= or the posted field), or ''. */
    public static function current(): string
    {
        $v = $_REQUEST[self::PARAM] ?? '';
        $v = is_string($v) ? trim(wp_unslash($v)) : '';
        return self::allowed($v) ? $v : '';
    }

    /**
     * Where the admin came from, read from the referer: the referer itself when it is another plugin page; its own
     * iqu_back when it is this same page (a save on it, a reload). '' when unknown.
     */
    public static function from_referer(array $here): string
    {
        $ref = (string) wp_get_referer();
        if ($ref === '' || !self::allowed($ref)) return '';
        $rq = self::query($ref);
        $same = self::this_page($rq);
        $mine = self::this_page($here);
        if ($same && $mine && $same === $mine) {
            $b = is_string($rq[self::PARAM] ?? null) ? $rq[self::PARAM] : '';
            return self::allowed($b) ? $b : '';
        }
        return $ref; // another plugin page, kept exactly as it was (its filter, search, page number, own return target)
    }

    /** The back button's address: the return target, else $fallback. */
    public static function url(string $fallback): string
    {
        return self::current() ?: (self::from_referer(self::query((string) ($_SERVER['REQUEST_URI'] ?? ''))) ?: $fallback);
    }

    /** $url with this request's return target added (links and redirects that stay on the page). */
    public static function keep(string $url): string
    {
        $b = self::current() ?: self::from_referer(self::query((string) ($_SERVER['REQUEST_URI'] ?? '')));
        return $b !== '' ? add_query_arg(self::PARAM, rawurlencode($b), $url) : $url;
    }

    /** Hidden field for a form on the page (GET forms keep the return target in the next URL). Escaped HTML. */
    public static function hidden_field(): string
    {
        $b = self::current();
        return $b !== '' ? '<input type="hidden" name="' . self::PARAM . '" value="' . esc_attr($b) . '">' : '';
    }

    /** Arriving on a page with a back button from another plugin page: keep where from in the URL (one redirect). */
    public static function on_arrival(): void
    {
        if (wp_doing_ajax() || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || !current_user_can('manage_options')) return;
        $here = $_GET;
        if (!self::this_page($here) || isset($_GET[self::PARAM])) return;
        $from = self::from_referer($here);
        if ($from === '') return;
        wp_safe_redirect(add_query_arg(self::PARAM, rawurlencode($from), wp_unslash((string) ($_SERVER['REQUEST_URI'] ?? ''))));
        exit;
    }

    /**
     * After a save on a page with a back button (admin-post.php, then back to that page): keep its return target. Only
     * when the redirect goes to such a page without one, and the target comes from the posted field or the referer.
     */
    public static function keep_on_redirect($location)
    {
        if (!is_string($location) || !is_admin()) return $location;
        $to = self::query($location);
        if (!self::this_page($to) || isset($to[self::PARAM]) || !self::allowed($location)) return $location;
        $b = self::current();
        if ($b === '') {
            $ref = (string) wp_get_referer();
            if ($ref !== '' && self::allowed($ref)) {
                $rq = self::query($ref);
                if (self::this_page($rq) === self::this_page($to)) $b = is_string($rq[self::PARAM] ?? null) && self::allowed($rq[self::PARAM]) ? $rq[self::PARAM] : '';
            }
        }
        return $b !== '' ? add_query_arg(self::PARAM, rawurlencode($b), $location) : $location;
    }
}
