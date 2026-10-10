<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Waitlist
 *
 * Families outside the US and Canada can leave an email (and optionally a name) to hear
 * when classes open in their country.
 *
 * POST /wp-json/iqu/v1/waitlist  {nonce, recaptcha_token, email, name?, program, source?, country?}
 *
 * Security:
 * - nonce (action iqu_waitlist), reCAPTCHA v3 (action iqu_waitlist);
 * - rate limit per visitor: 5 per hour, keyed by an HMAC of the IP in a transient
 *   (the IP itself is never stored);
 * - one row per email and program (UNIQUE key; a repeat answers the same way);
 * - only a 2-letter country code is stored; the request's own country wins over the hint.
 * Responses are never cached (nocache_headers + Cache-Control: no-store).
 */
class IQU_Waitlist
{
    public const NONCE    = 'iqu_waitlist';
    public const PROGRAMS = ['free', 'summer', 'weekend'];
    private const LIMIT   = 5;

    public static function init(): void
    {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function routes(): void
    {
        register_rest_route('iqu/v1', '/waitlist', [
            'methods'             => 'POST',
            'callback'            => [__CLASS__, 'rest_join'],
            'permission_callback' => '__return_true', // public form: nonce + reCAPTCHA + rate limit below
        ]);
    }

    private static function reply(array $data, int $status): WP_REST_Response
    {
        nocache_headers();
        $res = new WP_REST_Response($data, $status);
        $res->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        return $res;
    }

    public static function rest_join(WP_REST_Request $req): WP_REST_Response
    {
        $nonce = (string) $req->get_param('nonce');
        if (!wp_verify_nonce($nonce, self::NONCE)) {
            return self::reply(['ok' => false, 'message' => 'Please refresh the page and try again.'], 403);
        }

        // Rate limit before anything else costs a reCAPTCHA call.
        $key = 'iqu_wl_' . substr(hash_hmac('sha256', class_exists('IQU_Geo') ? IQU_Geo::client_ip() : '', wp_salt('nonce')), 0, 32);
        $tries = (int) get_transient($key);
        if ($tries >= self::LIMIT) {
            return self::reply(['ok' => false, 'message' => 'Too many attempts. Please try again later.'], 429);
        }
        set_transient($key, $tries + 1, HOUR_IN_SECONDS);

        $verify = IQU_Recaptcha::verify(sanitize_text_field((string) $req->get_param('recaptcha_token')), 'iqu_waitlist');
        if (empty($verify['success'])) {
            return self::reply(['ok' => false, 'message' => 'Verification failed. Please try again.'], 400);
        }

        $email = sanitize_email((string) $req->get_param('email'));
        if (!is_email($email) || strlen($email) > 191) {
            return self::reply(['ok' => false, 'message' => 'Please enter a valid email address.', 'field' => 'email'], 400);
        }
        $name    = mb_substr(sanitize_text_field((string) $req->get_param('name')), 0, 150);
        $program = sanitize_key((string) $req->get_param('program'));
        if (!in_array($program, self::PROGRAMS, true)) $program = 'free';
        $source  = mb_substr(sanitize_key((string) $req->get_param('source')), 0, 30) ?: 'modal';

        $country = class_exists('IQU_Geo') ? IQU_Geo::country() : '';
        if ($country === '' || IQU_Geo::allowed($country)) {
            // Travelling or unknown IP: use the country the form detected from the phone / residence.
            $hint = strtoupper(sanitize_text_field((string) $req->get_param('country')));
            $country = preg_match('/^[A-Z]{2}$/', $hint) ? $hint : $country;
        }

        self::add($email, $name, $country, $program, $source);
        return self::reply(['ok' => true, 'message' => 'JazakAllahu Khairan! We will email you when our classes open in your country, in-sha\'-Allah.'], 200);
    }

    /** Insert once per email + program. @return bool true when a new row was added. */
    public static function add(string $email, string $name, string $country, string $program, string $source): bool
    {
        global $wpdb;
        $n = $wpdb->query($wpdb->prepare(
            'INSERT IGNORE INTO ' . IQU_Database::waitlist_table() . ' (email, name, country, program, source, created_at) VALUES (%s, %s, %s, %s, %s, %s)',
            strtolower($email), $name, preg_match('/^[A-Z]{2}$/', $country) ? $country : '', $program, $source, current_time('mysql', true)
        ));
        return (int) $n > 0;
    }

    /**
     * WHERE clause for the admin list / CSV. $more (all optional): 's' (name or email contains),
     * 'from' / 'to' (UTC 'Y-m-d H:i:s' bounds on created_at, inclusive). Values are bound with prepare().
     * @return array{0:string,1:array}
     */
    private static function where(string $country, string $program, array $more): array
    {
        global $wpdb;
        $where = '1=1';
        $args  = [];
        if ($country !== '') { $where .= ' AND country = %s'; $args[] = $country; }
        if ($program !== '') { $where .= ' AND program = %s'; $args[] = $program; }
        if (($more['s'] ?? '') !== '') {
            $like = '%' . $wpdb->esc_like((string) $more['s']) . '%';
            $where .= ' AND (email LIKE %s OR name LIKE %s)';
            $args[] = $like;
            $args[] = $like;
        }
        if (($more['from'] ?? '') !== '') { $where .= ' AND created_at >= %s'; $args[] = (string) $more['from']; }
        if (($more['to'] ?? '') !== '') { $where .= ' AND created_at <= %s'; $args[] = (string) $more['to']; }
        return [$where, $args];
    }

    /** Rows for the admin page / CSV, newest first. */
    public static function rows(string $country = '', string $program = '', int $limit = 0, int $offset = 0, array $more = []): array
    {
        global $wpdb;
        [$where, $args] = self::where($country, $program, $more);
        $sql = 'SELECT id, email, name, country, program, source, created_at FROM ' . IQU_Database::waitlist_table() . " WHERE {$where} ORDER BY id DESC";
        if ($limit > 0) { $sql .= ' LIMIT %d OFFSET %d'; $args[] = $limit; $args[] = $offset; }
        if ($args) $sql = $wpdb->prepare($sql, $args);
        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    public static function count(string $country = '', string $program = '', array $more = []): int
    {
        global $wpdb;
        [$where, $args] = self::where($country, $program, $more);
        $sql = 'SELECT COUNT(*) FROM ' . IQU_Database::waitlist_table() . " WHERE {$where}";
        return (int) $wpdb->get_var($args ? $wpdb->prepare($sql, $args) : $sql);
    }

    /**
     * Totals for the admin metric cards (whole waitlist, not the filtered view).
     * $month_start_utc: first moment of the current month in the site time zone, as UTC 'Y-m-d H:i:s'.
     * @return array{total:int,month:int,countries:array<string,int>,programs:array<string,int>}
     */
    public static function stats(string $month_start_utc): array
    {
        global $wpdb;
        $t = IQU_Database::waitlist_table();
        $countries = [];
        foreach ($wpdb->get_results("SELECT country, COUNT(*) AS n FROM {$t} WHERE country <> '' GROUP BY country ORDER BY n DESC, country ASC LIMIT 3", ARRAY_A) ?: [] as $r) {
            $countries[(string) $r['country']] = (int) $r['n'];
        }
        $programs = [];
        foreach ($wpdb->get_results("SELECT program, COUNT(*) AS n FROM {$t} GROUP BY program ORDER BY n DESC, program ASC", ARRAY_A) ?: [] as $r) {
            $programs[(string) $r['program']] = (int) $r['n'];
        }
        return [
            'total'     => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t}"),
            'month'     => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE created_at >= %s", $month_start_utc)),
            'countries' => $countries,
            'programs'  => $programs,
        ];
    }

    /** Countries present, for the filter. */
    public static function countries(): array
    {
        global $wpdb;
        return $wpdb->get_col('SELECT DISTINCT country FROM ' . IQU_Database::waitlist_table() . " WHERE country <> '' ORDER BY country") ?: [];
    }
}
