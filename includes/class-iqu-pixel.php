<?php
if (!defined('ABSPATH'))
    exit;

/**
 * Class IQU_Pixel
 *
 * Meta (Facebook) Conversions API — server-side Lead event.
 *
 * Browser-side fbq('track','Lead') এবং এই server-side event একই event_id
 * ব্যবহার করে, তাই Meta দুটোকে একটাই conversion হিসেবে গণনা করে (deduplication)।
 *
 * Config wp-config.php-তে:
 *   define('IQU_FB_PIXEL_ID',   '1234567890');
 *   define('IQU_FB_CAPI_TOKEN', 'EAAG...');
 *   define('IQU_FB_TEST_EVENT_CODE', 'TEST12345'); // optional, testing শেষে মুছে দিন
 */
class IQU_Pixel
{
    private const API_VERSION = 'v21.0';

    /** Country name → ISO 3166-1 alpha-2 (IQU-র common markets) */
    private const COUNTRY_MAP = [
        'united states'        => 'us',
        'usa'                  => 'us',
        'us'                   => 'us',
        'united states of america' => 'us',
        'canada'               => 'ca',
        'bangladesh'           => 'bd',
        'united kingdom'       => 'gb',
        'uk'                   => 'gb',
        'australia'            => 'au',
        'saudi arabia'         => 'sa',
        'united arab emirates' => 'ae',
        'uae'                  => 'ae',
        'qatar'                => 'qa',
        'kuwait'               => 'kw',
        'malaysia'             => 'my',
        'india'                => 'in',
        'pakistan'             => 'pk',
        'germany'              => 'de',
        'france'               => 'fr',
        'italy'                => 'it',
        'spain'                => 'es',
        'south africa'         => 'za',
    ];

    // ────────────────────────────────────────────────────
    // Public API
    // ────────────────────────────────────────────────────

    public static function is_configured(): bool
    {
        return defined('IQU_FB_PIXEL_ID')
            && IQU_FB_PIXEL_ID
            && defined('IQU_FB_CAPI_TOKEN')
            && IQU_FB_CAPI_TOKEN;
    }

    /** প্রতি submission-এ একটি unique id — browser ও server দুই দিকেই একই */
    public static function new_event_id(): string
    {
        return function_exists('wp_generate_uuid4')
            ? wp_generate_uuid4()
            : uniqid('iqu_', true);
    }

    /**
     * Lead event পাঠায়। কোনো অবস্থাতেই exception throw করে না —
     * CAPI ব্যর্থ হলেও registration flow অক্ষত থাকে।
     *
     * @param array  $clean     validator-এর get_clean() array
     * @param string $event_id  new_event_id() থেকে পাওয়া id
     * @param array  $extra     content_name, value, currency ইত্যাদি
     */
    public static function send_lead(array $clean, string $event_id, array $extra = []): void
    {
        if (!self::is_configured()) {
            error_log('[IQU Pixel] SKIPPED — constants missing. PIXEL_ID='
                . (defined('IQU_FB_PIXEL_ID') ? 'yes' : 'no')
                . ' TOKEN=' . (defined('IQU_FB_CAPI_TOKEN') ? 'yes' : 'no'));
            return;
        }
        try {
            $payload = [
                'event_name'       => 'Lead',
                'event_time'       => time(),
                'event_id'         => $event_id,
                'action_source'    => 'website',
                'event_source_url' => self::source_url(),
                'user_data'        => self::build_user_data($clean),
                'custom_data'      => self::build_custom_data($clean, $extra),
            ];

            $body = [
                'data'         => wp_json_encode([$payload]),
                'access_token' => IQU_FB_CAPI_TOKEN,
            ];

            if (defined('IQU_FB_TEST_EVENT_CODE') && IQU_FB_TEST_EVENT_CODE) {
                $body['test_event_code'] = IQU_FB_TEST_EVENT_CODE;
            }

            $url = 'https://graph.facebook.com/' . self::API_VERSION
                . '/' . rawurlencode(IQU_FB_PIXEL_ID) . '/events';

            $response = wp_remote_post($url, [
                'timeout'  => 5,
                'blocking' => true,
                'body'     => $body,
            ]);
            if (is_wp_error($response)) {
                error_log('[IQU Pixel] CAPI request failed: ' . $response->get_error_message());
                return;
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code !== 200) {
                error_log('[IQU Pixel] CAPI HTTP ' . $code . ' — ' . wp_remote_retrieve_body($response));
            }
        } catch (\Throwable $e) {
            error_log('[IQU Pixel] Unexpected error: ' . $e->getMessage());
        }
    }

    // ────────────────────────────────────────────────────
    // Internals
    // ────────────────────────────────────────────────────

    private static function build_user_data(array $clean): array
    {
        $ud = [];

        self::maybe_set($ud, 'em', self::hash_email($clean['email'] ?? ''));
        self::maybe_set($ud, 'fn', self::hash_text($clean['first_name'] ?? ''));
        self::maybe_set($ud, 'ln', self::hash_text($clean['last_name'] ?? ''));

        // Free form: whatsapp | Summer form: guardian_whatsapp → guardian_contact
        $phone = $clean['whatsapp']
            ?? $clean['guardian_whatsapp']
            ?? $clean['guardian_contact']
            ?? '';
        self::maybe_set($ud, 'ph', self::hash_phone($phone));

        self::maybe_set($ud, 'country', self::hash_country($clean['country_res'] ?? ''));

        // Non-hashed signals — match quality-র জন্য গুরুত্বপূর্ণ
        self::maybe_set($ud, 'client_ip_address', self::client_ip($clean));
        self::maybe_set($ud, 'client_user_agent', self::user_agent($clean));
        self::maybe_set($ud, 'fbp', self::cookie('_fbp'));
        self::maybe_set($ud, 'fbc', self::fbc());

        return $ud;
    }

    private static function build_custom_data(array $clean, array $extra): array
    {
        $form_type = $clean['form_type'] ?? 'unknown';

        $labels = [
            'free'          => 'Free Enrollment',
            'summer_level1' => 'Summer Program Level 1',
            'summer_level2' => 'Summer Program Level 2',
        ];

        $cd = [
            'content_name'     => $extra['content_name'] ?? ($labels[$form_type] ?? $form_type),
            'content_category' => $extra['content_category'] ?? 'registration',
            'currency'         => $extra['currency'] ?? 'USD',
        ];

        $value = $extra['value'] ?? ($clean['payment_amount'] ?? null);
        if ($value !== null && is_numeric($value)) {
            $cd['value'] = (float) $value;
        }

        return $cd;
    }

    private static function maybe_set(array &$arr, string $key, string $value): void
    {
        if ($value !== '') {
            $arr[$key] = $value;
        }
    }

    private static function hash_text($value): string
    {
        $value = trim(strtolower(wp_strip_all_tags((string) $value)));
        return $value === '' ? '' : hash('sha256', $value);
    }

    private static function hash_email($value): string
    {
        $value = trim(strtolower((string) $value));
        return is_email($value) ? hash('sha256', $value) : '';
    }

    /** E.164 — শুধু digits, leading + বাদ */
    private static function hash_phone($value): string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);
        return (strlen($digits) >= 7) ? hash('sha256', $digits) : '';
    }

    private static function hash_country($value): string
    {
        $value = trim(strtolower((string) $value));
        if ($value === '') {
            return '';
        }
        if (isset(self::COUNTRY_MAP[$value])) {
            return hash('sha256', self::COUNTRY_MAP[$value]);
        }
        // ইতিমধ্যেই 2-letter code হলে সরাসরি ব্যবহার
        return preg_match('/^[a-z]{2}$/', $value) ? hash('sha256', $value) : '';
    }

    private static function client_ip(array $clean): string
    {
        $candidates = [];

        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $candidates[] = $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $candidates[] = trim($parts[0]);
        }
        if (!empty($clean['ip_address'])) {
            $candidates[] = $clean['ip_address'];
        }
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $candidates[] = $_SERVER['REMOTE_ADDR'];
        }

        foreach ($candidates as $ip) {
            $ip = sanitize_text_field($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP) && $ip !== '0.0.0.0') {
                return $ip;
            }
        }

        return '';
    }

    private static function user_agent(array $clean): string
    {
        if (!empty($clean['user_agent'])) {
            return (string) $clean['user_agent'];
        }
        return sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? '');
    }

    private static function cookie(string $name): string
    {
        return isset($_COOKIE[$name])
            ? sanitize_text_field(wp_unslash($_COOKIE[$name]))
            : '';
    }

    /** _fbc cookie, নাহয় fbclid থেকে তৈরি */
    private static function fbc(): string
    {
        $fbc = self::cookie('_fbc');
        if ($fbc !== '') {
            return $fbc;
        }

        $fbclid = '';
        if (!empty($_POST['iqu_fbclid'])) {
            $fbclid = sanitize_text_field(wp_unslash($_POST['iqu_fbclid']));
        }

        return $fbclid === '' ? '' : 'fb.1.' . (time() * 1000) . '.' . $fbclid;
    }

    private static function source_url(): string
    {
        if (!empty($_POST['iqu_page_url'])) {
            return esc_url_raw(wp_unslash($_POST['iqu_page_url']));
        }
        if (!empty($_SERVER['HTTP_REFERER'])) {
            return esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER']));
        }
        return home_url('/');
    }
}