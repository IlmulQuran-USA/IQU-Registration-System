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
 * Every enrollment of every form (Enroll for Free, Summer, Weekend) is sent: Lead at submit,
 * CompleteRegistration / Purchase later from the Stripe webhook. The only thing that stops an
 * event is an explicit "Deny" for marketing cookies, recorded on the row at submit
 * (marketing_consent; see consent_from_request()). Names (fn / ln) are the parent's — see
 * match_name(); a minor's own name is never sent.
 *
 * Config wp-config.php-তে:
 *   define('IQU_FB_PIXEL_ID',   '1234567890');
 *   define('IQU_FB_CAPI_TOKEN', 'EAAG...');
 *   define('IQU_FB_TEST_EVENT_CODE', 'TEST12345'); // optional, testing শেষে মুছে দিন
 */
class IQU_Pixel
{
    private const API_VERSION = 'v21.0';

    /** A student this age or older, with no parent name on the form, is matched by their own name. */
    public const ADULT_AGE = 18;

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
        // Only website submissions are leads (see IQU_Database::created_via()).
        if (!IQU_Database::is_public_row($clean)) return;
        // The choice recorded on the row (record_consent), or this request's when it was not stored.
        $consent = isset($clean['marketing_consent']) ? IQU_Database::marketing_consent($clean) : self::consent_from_request();
        if ($consent === IQU_Database::CONSENT_DENY) {
            error_log('[IQU Pixel] Lead not sent: the visitor chose "Deny" for marketing cookies.');
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

    /**
     * fbp / fbc of the current visitor, saved with a registration at submit so a later
     * server event (sent from the Stripe webhook, where there is no browser) can be matched.
     * @return array{fbp:string, fbc:string}
     */
    public static function browser_ids(): array
    {
        return ['fbp' => substr(self::cookie('_fbp'), 0, 100), 'fbc' => substr(self::fbc(), 0, 255)];
    }

    /**
     * Server-only event (CompleteRegistration, Purchase) for a saved registration row.
     * Everything comes from the row: hashed email / phone / name / country, the fbp / fbc
     * saved at submit, and the IP + user agent already stored on the row at submit
     * (no new IP storage; nothing is read from the current request). Never throws.
     * The marketing choice is also the one stored at submit: "deny" → nothing is sent.
     *
     * @param string $event_name e.g. CompleteRegistration, Purchase
     * @param array  $row        registration row from IQU_Database::get_registration()
     * @param string $event_id   deterministic id, e.g. enroll_{id}, so retries dedupe in Meta
     * @param array  $custom     content_name, value, currency
     */
    public static function send_server_event(string $event_name, array $row, string $event_id, array $custom = []): void
    {
        if (!self::is_configured()) return;
        // Students added by an admin or a CSV import are not website conversions.
        if (!IQU_Database::is_public_row($row)) return;
        if (IQU_Database::marketing_consent($row) === IQU_Database::CONSENT_DENY) {
            error_log('[IQU Pixel] ' . $event_name . ' not sent for registration #' . (int) ($row['id'] ?? 0) . ': marketing cookies were denied at submit.');
            return;
        }
        try {
            $ud = [];
            self::maybe_set($ud, 'em', self::hash_email($row['email'] ?? ''));
            [$first, $last] = self::match_name($row);
            self::maybe_set($ud, 'fn', self::hash_text($first));
            self::maybe_set($ud, 'ln', self::hash_text($last));
            $phone = ($row['whatsapp'] ?? '') ?: (($row['guardian_whatsapp'] ?? '') ?: ($row['guardian_contact'] ?? ''));
            self::maybe_set($ud, 'ph', self::hash_phone($phone));
            $country = (string) ($row['country_ip'] ?? '');
            self::maybe_set($ud, 'country', $country !== '' ? hash('sha256', strtolower($country)) : self::hash_country($row['country_res'] ?? ''));
            $ip = (string) ($row['ip_address'] ?? '');
            if (filter_var($ip, FILTER_VALIDATE_IP) && $ip !== '0.0.0.0') $ud['client_ip_address'] = $ip;
            self::maybe_set($ud, 'client_user_agent', (string) ($row['user_agent'] ?? ''));
            self::maybe_set($ud, 'fbp', (string) ($row['fbp'] ?? ''));
            self::maybe_set($ud, 'fbc', (string) ($row['fbc'] ?? ''));

            $cd = ['currency' => $custom['currency'] ?? 'USD'];
            if (isset($custom['content_name'])) $cd['content_name'] = (string) $custom['content_name'];
            if (isset($custom['value']) && is_numeric($custom['value'])) $cd['value'] = (float) $custom['value'];

            $body = [
                'data' => wp_json_encode([[
                    'event_name'       => $event_name,
                    'event_time'       => time(),
                    'event_id'         => $event_id,
                    'action_source'    => 'website',
                    'event_source_url' => home_url('/'),
                    'user_data'        => $ud,
                    'custom_data'      => $cd,
                ]]),
                'access_token' => IQU_FB_CAPI_TOKEN,
            ];
            if (defined('IQU_FB_TEST_EVENT_CODE') && IQU_FB_TEST_EVENT_CODE) {
                $body['test_event_code'] = IQU_FB_TEST_EVENT_CODE;
            }
            $url = 'https://graph.facebook.com/' . self::API_VERSION . '/' . rawurlencode(IQU_FB_PIXEL_ID) . '/events';
            $response = wp_remote_post($url, ['timeout' => 5, 'blocking' => true, 'body' => $body]);
            if (is_wp_error($response)) {
                error_log('[IQU Pixel] ' . $event_name . ' failed: ' . $response->get_error_message());
            } elseif ((int) wp_remote_retrieve_response_code($response) !== 200) {
                error_log('[IQU Pixel] ' . $event_name . ' HTTP ' . (int) wp_remote_retrieve_response_code($response));
            }
        } catch (\Throwable $e) {
            error_log('[IQU Pixel] Unexpected error: ' . $e->getMessage());
        }
    }

    // ────────────────────────────────────────────────────
    // Consent and names
    // ────────────────────────────────────────────────────

    /**
     * The visitor's marketing-cookie choice, read at form submit from the consent banner's own
     * cookie: Complianz (cmplz_marketing) or the WP Consent API cookie Complianz also writes
     * (wp_consent_marketing). Only an explicit "allow" / "deny" counts; anything else is
     * "unknown" — no choice made yet, or no consent tool active (a leftover cookie is ignored then).
     * Complianz's cmplz_has_consent() / wp_has_consent() are not used: they answer yes / no and
     * cannot tell "denied" from "not chosen yet".
     */
    public static function consent_from_request(): string
    {
        if (!self::consent_tool_active()) return IQU_Database::CONSENT_UNKNOWN;
        $prefix  = (string) apply_filters('wp_consent_cookie_prefix', 'wp_consent');
        $cookies = (array) apply_filters('iqu_marketing_consent_cookies', ['cmplz_marketing', $prefix . '_marketing']);
        foreach ($cookies as $name) {
            $v = strtolower(self::cookie((string) $name));
            if ($v === IQU_Database::CONSENT_ALLOW || $v === IQU_Database::CONSENT_DENY) return $v;
        }
        return IQU_Database::CONSENT_UNKNOWN;
    }

    /** Complianz (any version) or the WP Consent API is active. */
    private static function consent_tool_active(): bool
    {
        return defined('cmplz_version') || defined('CMPLZ_VERSION') || function_exists('cmplz_has_consent') || function_exists('wp_has_consent');
    }

    /** Store the visitor's choice on the row about to be saved (once the column exists). */
    public static function record_consent(array &$clean): void
    {
        if (IQU_Database::marketing_consent_ready()) $clean['marketing_consent'] = self::consent_from_request();
    }

    /**
     * fn / ln for Meta: the parent's, because Meta matches the event to the parent's account.
     *   - guardian_name on the row (Summer, Weekend, any form that has it): its first and last word;
     *   - no parent name (Enroll for Free): the student's own name, only at age 18 or over;
     *   - otherwise none. The event is still sent; only fn / ln are left out.
     * @return array{0:string,1:string} [first, last], unhashed; '' = not sent.
     */
    public static function match_name(array $row): array
    {
        $guardian = trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) ($row['guardian_name'] ?? ''))));
        if ($guardian !== '') {
            $parts = explode(' ', $guardian);
            return [$parts[0], count($parts) > 1 ? (string) end($parts) : ''];
        }
        if ((int) ($row['age'] ?? 0) >= self::ADULT_AGE) {
            return [(string) ($row['first_name'] ?? ''), (string) ($row['last_name'] ?? '')];
        }
        return ['', ''];
    }

    // ────────────────────────────────────────────────────
    // Internals
    // ────────────────────────────────────────────────────

    private static function build_user_data(array $clean): array
    {
        $ud = [];

        self::maybe_set($ud, 'em', self::hash_email($clean['email'] ?? ''));
        [$first, $last] = self::match_name($clean);
        self::maybe_set($ud, 'fn', self::hash_text($first));
        self::maybe_set($ud, 'ln', self::hash_text($last));

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
            'free'             => 'Free Enrollment',
            'summer_level1'    => 'Summer Program Level 1',
            'summer_level2'    => 'Summer Program Level 2',
            'weekend_existing' => 'Weekend Ilm Program (Existing)',
            'weekend_new'      => 'Weekend Ilm Program (New)',
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