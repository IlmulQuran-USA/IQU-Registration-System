<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Eligibility
 *
 * US and Canada only. Decides, for one submission, whether to allow it, allow it with a
 * "Review: location" flag, or block it:
 *
 *   phone country       IP country    country of residence    result
 *   US/CA               US/CA         US/CA or empty          allow
 *   US/CA               other         —                       flag (location)
 *   US/CA               —             not recognised          flag (location)
 *   not US/CA (incl. +1 with a Caribbean area code)           block
 *   —                   —             clearly another country block
 *
 * Unknown IP country (no GeoLite2 database) → the phone and residence rules only.
 * Mode (Billing Settings → Enrollment): off = nothing; monitor = record what would be
 * blocked, never block; enforce = block.
 *
 * Privacy: only 2-letter country codes are kept (on the registration and in the blocked
 * counts). No IP address is stored.
 */
class IQU_Eligibility
{
    public const OPT_BLOCKS = 'iqu_enroll_blocks';
    private const KEEP_HOURS = 192; // 8 days of hourly counts

    /** Phone fields checked for each program. */
    public const PHONE_FIELDS = [
        'free'    => ['whatsapp'],
        'summer'  => ['guardian_contact', 'guardian_whatsapp'],
        'weekend' => ['whatsapp'],
    ];

    /** E.164 country calling codes → ISO country (used only to report where a number is from). */
    private const CALLING_CODES = [
        '7' => 'RU', '20' => 'EG', '27' => 'ZA', '30' => 'GR', '31' => 'NL', '32' => 'BE', '33' => 'FR', '34' => 'ES',
        '36' => 'HU', '39' => 'IT', '40' => 'RO', '41' => 'CH', '43' => 'AT', '44' => 'GB', '45' => 'DK', '46' => 'SE',
        '47' => 'NO', '48' => 'PL', '49' => 'DE', '51' => 'PE', '52' => 'MX', '53' => 'CU', '54' => 'AR', '55' => 'BR',
        '56' => 'CL', '57' => 'CO', '58' => 'VE', '60' => 'MY', '61' => 'AU', '62' => 'ID', '63' => 'PH', '64' => 'NZ',
        '65' => 'SG', '66' => 'TH', '81' => 'JP', '82' => 'KR', '84' => 'VN', '86' => 'CN', '90' => 'TR', '91' => 'IN',
        '92' => 'PK', '93' => 'AF', '94' => 'LK', '95' => 'MM', '98' => 'IR',
        '211' => 'SS', '212' => 'MA', '213' => 'DZ', '216' => 'TN', '218' => 'LY', '220' => 'GM', '221' => 'SN', '222' => 'MR',
        '223' => 'ML', '224' => 'GN', '225' => 'CI', '226' => 'BF', '227' => 'NE', '228' => 'TG', '229' => 'BJ', '230' => 'MU',
        '231' => 'LR', '232' => 'SL', '233' => 'GH', '234' => 'NG', '235' => 'TD', '236' => 'CF', '237' => 'CM', '238' => 'CV',
        '239' => 'ST', '240' => 'GQ', '241' => 'GA', '242' => 'CG', '243' => 'CD', '244' => 'AO', '245' => 'GW', '248' => 'SC',
        '249' => 'SD', '250' => 'RW', '251' => 'ET', '252' => 'SO', '253' => 'DJ', '254' => 'KE', '255' => 'TZ', '256' => 'UG',
        '257' => 'BI', '258' => 'MZ', '260' => 'ZM', '261' => 'MG', '262' => 'RE', '263' => 'ZW', '264' => 'NA', '265' => 'MW',
        '266' => 'LS', '267' => 'BW', '268' => 'SZ', '269' => 'KM', '291' => 'ER', '297' => 'AW', '298' => 'FO', '299' => 'GL',
        '350' => 'GI', '351' => 'PT', '352' => 'LU', '353' => 'IE', '354' => 'IS', '355' => 'AL', '356' => 'MT', '357' => 'CY',
        '358' => 'FI', '359' => 'BG', '370' => 'LT', '371' => 'LV', '372' => 'EE', '373' => 'MD', '374' => 'AM', '375' => 'BY',
        '376' => 'AD', '377' => 'MC', '378' => 'SM', '380' => 'UA', '381' => 'RS', '382' => 'ME', '383' => 'XK', '385' => 'HR',
        '386' => 'SI', '387' => 'BA', '389' => 'MK', '420' => 'CZ', '421' => 'SK', '423' => 'LI',
        '501' => 'BZ', '502' => 'GT', '503' => 'SV', '504' => 'HN', '505' => 'NI', '506' => 'CR', '507' => 'PA', '509' => 'HT',
        '590' => 'GP', '591' => 'BO', '592' => 'GY', '593' => 'EC', '594' => 'GF', '595' => 'PY', '596' => 'MQ', '597' => 'SR',
        '598' => 'UY', '599' => 'CW', '670' => 'TL', '673' => 'BN', '675' => 'PG', '676' => 'TO', '677' => 'SB', '678' => 'VU',
        '679' => 'FJ', '680' => 'PW', '685' => 'WS', '686' => 'KI', '687' => 'NC', '689' => 'PF', '691' => 'FM', '692' => 'MH',
        '850' => 'KP', '852' => 'HK', '853' => 'MO', '855' => 'KH', '856' => 'LA', '880' => 'BD', '886' => 'TW',
        '960' => 'MV', '961' => 'LB', '962' => 'JO', '963' => 'SY', '964' => 'IQ', '965' => 'KW', '966' => 'SA', '967' => 'YE',
        '968' => 'OM', '970' => 'PS', '971' => 'AE', '972' => 'IL', '973' => 'BH', '974' => 'QA', '975' => 'BT', '976' => 'MN',
        '977' => 'NP', '992' => 'TJ', '993' => 'TM', '994' => 'AZ', '995' => 'GE', '996' => 'KG', '998' => 'UZ',
    ];

    private static $nanp = null;
    private static $countries = null;

    public static function init(): void
    {
        add_filter('iqu_daily_summary_rows', [__CLASS__, 'summary_rows'], 10, 2);
    }

    /** NANPA area codes: ['US' => int[], 'CA' => int[], 'other' => [npa => ISO]]. */
    public static function nanp(): array
    {
        if (self::$nanp === null) self::$nanp = require IQU_PLUGIN_DIR . 'includes/data/nanp-us-ca.php';
        return self::$nanp;
    }

    private static function countries(): array
    {
        if (self::$countries === null) self::$countries = require IQU_PLUGIN_DIR . 'includes/data/countries.php';
        return self::$countries;
    }

    // ------------------------------------------------------------
    // Phone rule
    // ------------------------------------------------------------

    /**
     * Country of a phone number in international format: 'US', 'CA', another ISO code,
     * '1?' for an unassigned +1 area code, or '' when it cannot be read.
     */
    public static function phone_country(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if ($digits === '' || strpos(trim($phone), '+') !== 0) {
            // The forms always send +<country code>; a bare 10-digit number is read as +1.
            if (strlen($digits) === 10) $digits = '1' . $digits;
            elseif ($digits === '') return '';
        }
        if ($digits[0] === '1') {
            $npa  = (int) substr($digits, 1, 3);
            $nanp = self::nanp();
            if (in_array($npa, $nanp['US'], true)) return 'US';
            if (in_array($npa, $nanp['CA'], true)) return 'CA';
            return $nanp['other'][$npa] ?? '1?';
        }
        foreach ([3, 2, 1] as $len) {
            $cc = substr($digits, 0, $len);
            if (isset(self::CALLING_CODES[$cc])) return self::CALLING_CODES[$cc];
        }
        return '';
    }

    /** A +1 number with a US or Canadian area code. */
    public static function phone_ok(string $phone): bool
    {
        return in_array(self::phone_country($phone), ['US', 'CA'], true);
    }

    // ------------------------------------------------------------
    // Country of residence (free text, lenient)
    // ------------------------------------------------------------

    public static function normalise(string $s): string
    {
        $s = strtr($s, ['&' => ' and ', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'É' => 'E', 'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a',
            'ã' => 'a', 'å' => 'a', 'Å' => 'A', 'ç' => 'c', 'Ç' => 'C', 'í' => 'i', 'î' => 'i', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o',
            'Ö' => 'O', 'ú' => 'u', 'ü' => 'u', 'Ü' => 'U', 'ñ' => 'n']);
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    /**
     * 'US' or 'CA' when the text names the US / Canada (a state, a province, or a big city),
     * another ISO code when it clearly names another country, '' when not recognised.
     * A mention of the US or Canada wins, so "Georgia", "New Mexico" or "USA (from Pakistan)" pass.
     */
    public static function residence(string $text): string
    {
        $raw = trim($text);
        $n   = self::normalise($raw);
        if ($n === '') return '';
        $c   = self::countries();
        $pad = ' ' . $n . ' ';
        $has = fn(array $names) => (bool) array_filter($names, fn($name) => strpos($pad, ' ' . $name . ' ') !== false);

        if ($has($c['us_names'])) return 'US';
        if ($has($c['ca_names'])) return 'CA';

        // Postal abbreviations only where they are clearly an address part:
        // the whole text ("TX"), after the last comma ("Dallas, TX 75201"), or before a ZIP / postal code.
        $tail = strpos($raw, ',') !== false ? self::normalise(substr($raw, strrpos($raw, ',') + 1)) : $n;
        $first_of_tail = strtok($tail, ' ');
        foreach (['US' => $c['us_abbr'], 'CA' => $c['ca_abbr']] as $iso => $abbr) {
            if (in_array($n, $abbr, true)) return $iso;
            if ($tail !== $n && in_array((string) $first_of_tail, $abbr, true)) return $iso;
        }
        if (preg_match('/\b(' . implode('|', $c['us_abbr']) . ') \d{5}\b/', $n)) return 'US';
        if (preg_match('/\b[a-z]\d[a-z] ?\d[a-z]\d\b/', $n)) return 'CA'; // Canadian postal code

        // Another country: longest name first ("south sudan" before "sudan").
        $best = ''; $len = 0;
        foreach ($c['countries'] as $iso => $names) {
            foreach ($names as $name) {
                if (strlen($name) > $len && strpos($pad, ' ' . $name . ' ') !== false) { $best = $iso; $len = strlen($name); }
            }
        }
        if ($best !== '') return $best;
        if (preg_match('/^[a-z]{2}$/', $n) && isset($c['countries'][strtoupper($n)])) return strtoupper($n);
        return '';
    }

    // ------------------------------------------------------------
    // Decision
    // ------------------------------------------------------------

    /**
     * @param array  $clean   Validated form data.
     * @param string $program free | summer | weekend
     * @return array{decision:string, reasons:string[], ip_country:string, phone_country:string, residence:string, country:string}
     */
    public static function check(array $clean, string $program, ?string $ip_country = null): array
    {
        $ip  = $ip_country ?? (class_exists('IQU_Geo') ? IQU_Geo::country() : '');
        $ok  = fn(string $c) => in_array($c, ['US', 'CA'], true);
        $reasons = [];
        $phone_country = '';

        foreach (self::PHONE_FIELDS[$program] ?? [] as $field) {
            $phone = trim((string) ($clean[$field] ?? ''));
            if ($phone === '') continue;
            $pc = self::phone_country($phone);
            if (!$ok($pc)) { $phone_country = $pc; $reasons[] = 'phone'; break; }
            if ($phone_country === '') $phone_country = $pc;
        }

        $res = self::residence((string) ($clean['country_res'] ?? ''));
        if ($res !== '' && !$ok($res)) $reasons[] = 'residence';

        $block = (bool) $reasons;
        if (!$block) {
            if ($ip !== '' && !$ok($ip)) $reasons[] = 'ip';
            if ($res === '') $reasons[] = 'residence_unknown';
        }

        // Where the family seems to be, for the blocked counts.
        $country = '??';
        foreach ([$ip, $phone_country, $res] as $cand) {
            if (preg_match('/^[A-Z]{2}$/', $cand) && !$ok($cand)) { $country = $cand; break; }
        }

        return [
            'decision'      => $block ? 'block' : ($reasons ? 'flag' : 'allow'),
            'reasons'       => $reasons,
            'ip_country'    => preg_match('/^[A-Z]{2}$/', $ip) ? $ip : '',
            'phone_country' => $phone_country,
            'residence'     => $res,
            'country'       => $country,
        ];
    }

    /**
     * Apply the current mode to a submission, before it is saved.
     * Sets $clean['country_ip'] (2 letters) and, for flagged or would-be-blocked entries,
     * $clean['review_reason'] = 'location'.
     * @return array|null Error payload to send when the submission is blocked, otherwise null.
     */
    public static function apply(array &$clean, string $program): ?array
    {
        $mode = IQU_Enrollment_Settings::country_mode();
        if ($mode === 'off') return null;

        $r = self::check($clean, $program);
        if (IQU_Database::schema_ready()) {
            $clean['country_ip'] = $r['ip_country'];
            if ($r['decision'] !== 'allow') $clean['review_reason'] = 'location';
        }

        if ($r['decision'] === 'block') {
            self::record_block($r['country'], $mode);
            if ($mode === 'enforce') {
                return [
                    'message' => self::block_message(),
                    'code'    => 'location_blocked',
                    'program' => $program,
                    'country' => $r['country'] === '??' ? '' : $r['country'],
                ];
            }
        }
        return null;
    }

    public static function block_message(): string
    {
        return 'We currently serve Muslim families in the United States and Canada only. When our classes open in your country, we will let you know by email.';
    }

    // ------------------------------------------------------------
    // Blocked counts (per hour, per country; no IP)
    // ------------------------------------------------------------

    public static function record_block(string $country, string $mode): void
    {
        $country = preg_match('/^[A-Z]{2}$/', $country) ? $country : '??';
        $mode    = $mode === 'enforce' ? 'enforce' : 'monitor';
        $all     = get_option(self::OPT_BLOCKS, []);
        if (!is_array($all)) $all = [];
        $hour = gmdate('YmdH', time());
        $all[$hour][$mode][$country] = (int) ($all[$hour][$mode][$country] ?? 0) + 1;

        $oldest = gmdate('YmdH', time() - self::KEEP_HOURS * HOUR_IN_SECONDS);
        foreach (array_keys($all) as $h) {
            if ((string) $h < $oldest) unset($all[$h]);
        }
        update_option(self::OPT_BLOCKS, $all, false);
    }

    /** @return array{enforce:array<string,int>, monitor:array<string,int>} counts since $since (Unix time). */
    public static function blocks_since(int $since): array
    {
        $out  = ['enforce' => [], 'monitor' => []];
        $from = gmdate('YmdH', $since);
        foreach ((array) get_option(self::OPT_BLOCKS, []) as $hour => $modes) {
            if ((string) $hour < $from || !is_array($modes)) continue;
            foreach ($modes as $mode => $countries) {
                if (!isset($out[$mode]) || !is_array($countries)) continue;
                foreach ($countries as $c => $n) $out[$mode][$c] = ($out[$mode][$c] ?? 0) + (int) $n;
            }
        }
        foreach ($out as &$counts) arsort($counts);
        return $out;
    }

    /** "3 (BD 2, PK 1)" */
    public static function format_counts(array $counts): string
    {
        $parts = [];
        foreach ($counts as $c => $n) $parts[] = $c . ' ' . (int) $n;
        return array_sum($counts) . ' (' . implode(', ', $parts) . ')';
    }

    /** Daily Telegram summary rows (filter iqu_daily_summary_rows). */
    public static function summary_rows(array $rows, int $since = 0): array
    {
        $b = self::blocks_since($since ?: time() - DAY_IN_SECONDS);
        if ($b['enforce']) $rows['Blocked enrollments'] = IQU_Telegram::esc(self::format_counts($b['enforce']));
        if ($b['monitor']) $rows['Would be blocked (monitor)'] = IQU_Telegram::esc(self::format_counts($b['monitor']));
        return $rows;
    }

    // ------------------------------------------------------------
    // Data for form.js
    // ------------------------------------------------------------

    /** Area codes for the browser check (same lists as the server). */
    public static function js_nanp(): array
    {
        $n = self::nanp();
        return ['US' => $n['US'], 'CA' => $n['CA']];
    }
}
