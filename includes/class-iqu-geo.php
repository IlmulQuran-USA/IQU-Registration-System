<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Geo
 *
 * Country of the visitor from the MaxMind GeoLite2 Country database.
 *
 * - Only the 2-letter country code is ever used or stored; the IP address is not stored.
 * - Client IP = REMOTE_ADDR. X-Forwarded-For is read only when REMOTE_ADDR is a trusted proxy
 *   listed in the optional IQU_TRUSTED_PROXIES constant (IPs or CIDR ranges, comma separated);
 *   it is off by default.
 * - The database lives above the web root (dirname(ABSPATH)/iqu-geo/) or, when that folder
 *   cannot be used, in uploads/iqu-geo/ protected by .htaccess (Require all denied).
 * - It is refreshed weekly by WP-Cron (and by "Update now" on Billing Settings): download
 *   with the MaxMind account id + licence key from wp-config.php, sha256 check, PharData
 *   extract, open-and-check, then an atomic rename. Any failure keeps the old database.
 * - A missing or unreadable database never breaks a form: the country is unknown ('') and
 *   one line is logged per day.
 *
 * REST: GET /wp-json/iqu/v1/geo → {country, allowed}, never cached.
 */
class IQU_Geo
{
    public const CRON     = 'iqu_geo_update';
    public const OPT_META = 'iqu_geo_meta';
    public const ALLOWED  = ['US', 'CA'];

    private const FILE     = 'GeoLite2-Country.mmdb';
    private const DOWNLOAD = 'https://download.maxmind.com/geoip/databases/GeoLite2-Country/download';
    private const LOGGED   = 'iqu_geo_missing_logged';

    /** @var \MaxMind\Db\Reader|null|false false = not opened yet */
    private static $reader = false;
    /** @var string|null Country of this request, once looked up. */
    private static $country = null;

    public static function init(): void
    {
        add_action(self::CRON, [__CLASS__, 'cron_update']);
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function schedule(): void
    {
        if (!wp_next_scheduled(self::CRON)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'weekly', self::CRON);
        }
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::CRON);
    }

    public static function allowed(string $country): bool
    {
        return in_array(strtoupper($country), self::ALLOWED, true);
    }

    // ------------------------------------------------------------
    // Client IP
    // ------------------------------------------------------------

    public static function client_ip(): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if (!filter_var($remote, FILTER_VALIDATE_IP)) return '';

        $xff = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff === '' || !self::is_trusted_proxy($remote)) return $remote;

        // Walk the chain from the nearest hop; the first address that is not a trusted proxy is the client.
        foreach (array_reverse(array_map('trim', explode(',', $xff))) as $hop) {
            if (!filter_var($hop, FILTER_VALIDATE_IP)) break;
            if (!self::is_trusted_proxy($hop)) return $hop;
        }
        return $remote;
    }

    /** @return string[] Trusted proxy IPs / CIDR ranges from IQU_TRUSTED_PROXIES. */
    private static function trusted_proxies(): array
    {
        if (!defined('IQU_TRUSTED_PROXIES')) return [];
        $v = IQU_TRUSTED_PROXIES;
        $list = is_array($v) ? $v : explode(',', (string) $v);
        return array_values(array_filter(array_map('trim', $list)));
    }

    private static function is_trusted_proxy(string $ip): bool
    {
        foreach (self::trusted_proxies() as $range) {
            if (self::ip_in_range($ip, $range)) return true;
        }
        return false;
    }

    /** IPv4 / IPv6 address in a single IP or CIDR range. */
    public static function ip_in_range(string $ip, string $range): bool
    {
        $bits = null;
        if (strpos($range, '/') !== false) {
            [$range, $b] = explode('/', $range, 2);
            $bits = (int) $b;
        }
        $a = @inet_pton($ip);
        $r = @inet_pton($range);
        if ($a === false || $r === false || strlen($a) !== strlen($r)) return false;
        $max = strlen($a) * 8;
        $bits = ($bits === null) ? $max : max(0, min($max, $bits));
        $bytes = intdiv($bits, 8);
        if (substr($a, 0, $bytes) !== substr($r, 0, $bytes)) return false;
        $rest = $bits % 8;
        if ($rest === 0) return true;
        $mask = chr((0xFF << (8 - $rest)) & 0xFF);
        return (($a[$bytes] & $mask) === ($r[$bytes] & $mask));
    }

    // ------------------------------------------------------------
    // Lookup
    // ------------------------------------------------------------

    /** Country of the current visitor ('US', 'CA', … or '' when unknown). */
    public static function country(): string
    {
        if (self::$country === null) self::$country = self::lookup(self::client_ip());
        return self::$country;
    }

    /** Country for one IP address, or '' when unknown. */
    public static function lookup(string $ip): string
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return '';
        $reader = self::reader();
        if (!$reader) return '';
        try {
            $rec = $reader->get($ip);
        } catch (\Throwable $e) {
            return '';
        }
        $code = strtoupper((string) ($rec['country']['iso_code'] ?? ($rec['registered_country']['iso_code'] ?? '')));
        return preg_match('/^[A-Z]{2}$/', $code) ? $code : '';
    }

    /** Forget the opened database (after an update, and in tests). */
    public static function reset(): void
    {
        if (self::$reader) {
            try { self::$reader->close(); } catch (\Throwable $e) {}
        }
        self::$reader  = false;
        self::$country = null;
    }

    private static function reader()
    {
        if (self::$reader !== false) return self::$reader;
        self::$reader = null;
        $file = self::db_file();
        if ($file === '') {
            self::log_once('GeoLite2 database not found; country is unknown (phone rule only).');
            return null;
        }
        try {
            self::load_library();
            self::$reader = new \MaxMind\Db\Reader($file);
        } catch (\Throwable $e) {
            self::log_once('GeoLite2 database could not be opened: ' . $e->getMessage());
            self::$reader = null;
        }
        return self::$reader;
    }

    /** Load the bundled MaxMind DB reader unless another copy is already loaded. */
    public static function load_library(): void
    {
        if (class_exists('MaxMind\\Db\\Reader', false)) return;
        $src = IQU_PLUGIN_DIR . 'includes/vendor/maxmind-db/reader/src/MaxMind/Db/';
        foreach (['Reader/InvalidDatabaseException', 'Reader/Util', 'Reader/Decoder', 'Reader/Metadata', 'Reader'] as $f) {
            $class = 'MaxMind\\Db\\' . str_replace('/', '\\', $f);
            if (!class_exists($class, false)) require_once $src . $f . '.php';
        }
    }

    private static function log_once(string $message): void
    {
        if (get_transient(self::LOGGED)) return;
        set_transient(self::LOGGED, 1, DAY_IN_SECONDS);
        error_log('[IQU] ' . $message);
    }

    // ------------------------------------------------------------
    // Storage
    // ------------------------------------------------------------

    /** Folder above the web root. */
    private static function primary_dir(): string
    {
        return rtrim(str_replace('\\', '/', dirname(ABSPATH)), '/') . '/iqu-geo/';
    }

    /** Fallback folder inside uploads. */
    private static function fallback_dir(): string
    {
        $up = wp_upload_dir(null, false);
        return rtrim(str_replace('\\', '/', (string) ($up['basedir'] ?? (WP_CONTENT_DIR . '/uploads'))), '/') . '/iqu-geo/';
    }

    /** Path of the current database, or '' when there is none. */
    public static function db_file(): string
    {
        foreach ([self::primary_dir(), self::fallback_dir()] as $dir) {
            if (@is_file($dir . self::FILE) && @filesize($dir . self::FILE) > 0) return $dir . self::FILE;
        }
        return '';
    }

    /** A writable folder for the database ('' if none). The uploads fallback is protected first. */
    private static function writable_dir(): string
    {
        $dir = self::primary_dir();
        if ((@is_dir($dir) || @wp_mkdir_p($dir)) && @is_writable($dir)) return $dir;

        $dir = self::fallback_dir();
        if (!(@is_dir($dir) || @wp_mkdir_p($dir)) || !@is_writable($dir)) return '';
        if (!is_file($dir . '.htaccess')) @file_put_contents($dir . '.htaccess', "Require all denied\n");
        if (!is_file($dir . 'index.php')) @file_put_contents($dir . 'index.php', '');
        return is_file($dir . '.htaccess') ? $dir : '';
    }

    /** Where the database is kept, for the admin card. */
    public static function location_label(): string
    {
        $file = self::db_file();
        if ($file === '') return '';
        return strpos($file, self::primary_dir()) === 0 ? 'above the web root' : 'uploads/iqu-geo (protected)';
    }

    public static function meta(): array
    {
        $m = get_option(self::OPT_META, []);
        return is_array($m) ? $m : [];
    }

    public static function has_credentials(): bool
    {
        return self::credentials() !== null;
    }

    /** @return array{0:string,1:string}|null */
    private static function credentials(): ?array
    {
        $id  = defined('IQU_MAXMIND_ACCOUNT_ID') ? trim((string) IQU_MAXMIND_ACCOUNT_ID) : '';
        $key = defined('IQU_MAXMIND_LICENSE_KEY') ? trim((string) IQU_MAXMIND_LICENSE_KEY) : '';
        return ($id !== '' && $key !== '') ? [$id, $key] : null;
    }

    // ------------------------------------------------------------
    // Update
    // ------------------------------------------------------------

    public static function cron_update(): void
    {
        self::update();
    }

    /**
     * Download, verify and install the latest GeoLite2 Country database.
     * The current database stays in place on any failure.
     * @return array{ok:bool, message:string}
     */
    public static function update(): array
    {
        $meta = self::meta();
        $meta['last_attempt'] = time();
        $result = self::download_and_install($meta);
        $meta['last_error'] = $result['ok'] ? '' : $result['message'];
        if ($result['ok']) $meta['updated_at'] = time();
        update_option(self::OPT_META, $meta, false);
        if (!$result['ok']) error_log('[IQU] GeoLite2 update failed: ' . $result['message']);
        return $result;
    }

    private static function download_and_install(array &$meta): array
    {
        $fail  = fn(string $m) => ['ok' => false, 'message' => $m];
        $creds = self::credentials();
        if (!$creds) return $fail('MaxMind credentials missing. Add IQU_MAXMIND_ACCOUNT_ID and IQU_MAXMIND_LICENSE_KEY to wp-config.php.');
        if (!class_exists('PharData')) return $fail('The PHP Phar extension is not available on this server.');
        $dir = self::writable_dir();
        if ($dir === '') return $fail('No writable folder for the database (tried above the web root and uploads/iqu-geo).');

        // 1. The published checksum.
        $sum = self::fetch(self::DOWNLOAD . '?suffix=tar.gz.sha256', $creds, null);
        if (!$sum['ok']) return $fail('Checksum download failed: ' . $sum['error']);
        $expected = strtolower((string) strtok(trim($sum['body']), " \t\r\n"));
        if (!preg_match('/^[a-f0-9]{64}$/', $expected)) return $fail('The checksum file is not valid.');

        // 2. The archive, streamed to a temporary file next to the database.
        $tmp = $dir . 'download-' . wp_generate_password(12, false) . '.tar.gz';
        $new = $dir . self::FILE . '.new';
        try {
            $got = self::fetch(self::DOWNLOAD . '?suffix=tar.gz', $creds, $tmp);
            if (!$got['ok']) return $fail('Database download failed: ' . $got['error']);
            if (!is_file($tmp) || !hash_equals($expected, (string) hash_file('sha256', $tmp))) {
                return $fail('Checksum mismatch: the download was rejected and the current database kept.');
            }

            // 3. Extract the .mmdb with PharData.
            $found = '';
            $phar  = new PharData($tmp);
            foreach (new RecursiveIteratorIterator($phar) as $entry) {
                if (basename((string) $entry->getPathname()) === self::FILE) { $found = (string) $entry->getPathname(); break; }
            }
            if ($found === '') return $fail('The archive does not contain ' . self::FILE . '.');
            if (!@copy($found, $new) || !@filesize($new)) return $fail('Could not extract the database.');
            unset($phar);

            // 4. Open it and check it is a Country database.
            self::load_library();
            $check = new \MaxMind\Db\Reader($new);
            $md = $check->metadata();
            $check->close();
            if (stripos((string) $md->databaseType, 'Country') === false) return $fail('Unexpected database type: ' . $md->databaseType);

            // 5. Atomic replace.
            self::reset();
            if (!@rename($new, $dir . self::FILE)) return $fail('Could not replace the database file.');
            $meta['build'] = gmdate('Y-m-d', (int) $md->buildEpoch);
            $meta['location'] = strpos($dir, self::primary_dir()) === 0 ? 'primary' : 'uploads';
            delete_transient(self::LOGGED);
            return ['ok' => true, 'message' => 'GeoLite2 Country database updated (' . $meta['build'] . ').'];
        } catch (\Throwable $e) {
            return $fail('The downloaded database is not valid: ' . $e->getMessage());
        } finally {
            if (is_file($tmp)) @unlink($tmp);
            if (is_file($new)) @unlink($new);
        }
    }

    /**
     * GET from MaxMind with Basic auth. MaxMind answers with a redirect to a pre-signed
     * download URL; that URL is followed WITHOUT the credentials (like curl -L -u).
     * @return array{ok:bool, body:string, error:string}
     */
    private static function fetch(string $url, array $creds, ?string $save_to): array
    {
        $args = [
            'timeout'     => 120,
            'redirection' => 0,
            'headers'     => ['Authorization' => 'Basic ' . base64_encode($creds[0] . ':' . $creds[1])],
        ];
        for ($hop = 0; $hop < 3; $hop++) {
            if ($save_to !== null) { $args['stream'] = true; $args['filename'] = $save_to; }
            $res = wp_remote_get($url, $args);
            if (is_wp_error($res)) return ['ok' => false, 'body' => '', 'error' => $res->get_error_message()];
            $code = (int) wp_remote_retrieve_response_code($res);
            if ($code >= 300 && $code < 400) {
                $next = (string) wp_remote_retrieve_header($res, 'location');
                if (strpos($next, 'https://') !== 0) return ['ok' => false, 'body' => '', 'error' => 'redirect to a non-https address'];
                $url = $next;
                unset($args['headers']['Authorization']);
                continue;
            }
            if ($code !== 200) return ['ok' => false, 'body' => '', 'error' => 'HTTP ' . $code . ($code === 401 ? ' (check the account id and licence key)' : '')];
            return ['ok' => true, 'body' => $save_to === null ? (string) wp_remote_retrieve_body($res) : '', 'error' => ''];
        }
        return ['ok' => false, 'body' => '', 'error' => 'too many redirects'];
    }

    // ------------------------------------------------------------
    // REST: GET /wp-json/iqu/v1/geo
    // ------------------------------------------------------------

    public static function routes(): void
    {
        register_rest_route('iqu/v1', '/geo', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'rest_geo'],
            'permission_callback' => '__return_true', // public: returns only the visitor's own country
        ]);
    }

    public static function rest_geo(): WP_REST_Response
    {
        nocache_headers();
        $country = self::country();
        $res = new WP_REST_Response([
            'country' => $country,
            'allowed' => $country === '' || self::allowed($country), // unknown: decided by the phone rule
        ], 200);
        $res->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        return $res;
    }
}
