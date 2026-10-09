<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Enrollment_Settings
 *
 * Safety switches for the 3.3.0 enrollment flow (Billing Settings → Enrollment):
 *   iqu_enroll_country_check   off | monitor | enforce   (default monitor: log only, never block)
 *   iqu_enroll_free_payment    off | on                   (default off: Enroll for Free as before)
 *   iqu_enroll_summer_payment  zeffy | stripe             (default zeffy: Summer as before)
 *
 * While the Stripe site mode is test, the payment steps run only for logged-in
 * administrators; every other visitor gets the form exactly as before.
 */
class IQU_Enrollment_Settings
{
    public const OPT_COUNTRY = 'iqu_enroll_country_check';
    public const OPT_FREE    = 'iqu_enroll_free_payment';
    public const OPT_SUMMER  = 'iqu_enroll_summer_payment';

    public const OPT_SUMMER_FEE           = 'iqu_enroll_summer_fee';           // Standard, paid once for the whole program
    public const OPT_SUMMER_FEE_SUPPORTED = 'iqu_enroll_summer_fee_supported'; // Supported Rate
    public const SUMMER_FEE_DEFAULTS = [self::OPT_SUMMER_FEE => '40.00', self::OPT_SUMMER_FEE_SUPPORTED => '30.00'];

    public const COUNTRY_MODES = ['off', 'monitor', 'enforce'];
    public const FREE_MODES    = ['off', 'on'];
    public const SUMMER_MODES  = ['zeffy', 'stripe'];

    public static function country_mode(): string
    {
        $v = (string) get_option(self::OPT_COUNTRY, 'monitor');
        return in_array($v, self::COUNTRY_MODES, true) ? $v : 'monitor';
    }

    public static function free_mode(): string
    {
        return get_option(self::OPT_FREE, 'off') === 'on' ? 'on' : 'off';
    }

    public static function summer_mode(): string
    {
        return get_option(self::OPT_SUMMER, 'zeffy') === 'stripe' ? 'stripe' : 'zeffy';
    }

    /**
     * Stripe is configured for the site mode, and this visitor may use it:
     * live mode → everyone; test mode → logged-in administrators only.
     */
    public static function payments_allowed(): bool
    {
        if (!class_exists('IQU_Stripe') || !IQU_Stripe::is_ready()) return false;
        if (!IQU_Database::schema_ready()) return false; // 3.3.0 columns not verified yet: forms as before
        return IQU_Stripe::expected_mode() === 'live' || current_user_can('manage_options');
    }

    /** Enroll for Free sends this visitor to Stripe to add a card or bank account. */
    public static function free_payment_active(): bool
    {
        return self::free_mode() === 'on' && self::payments_allowed();
    }

    /** Summer is paid through Stripe Checkout for this visitor (instead of Zeffy). */
    public static function summer_stripe_active(): bool
    {
        return self::summer_mode() === 'stripe' && self::payments_allowed();
    }

    /** Settings form.js needs (printed as IQU_ENROLL). Nothing visitor-specific, so cached pages stay correct. */
    public static function js_config(): array
    {
        $contact = self::travel_contact();
        return [
            'check'          => self::country_mode(),
            'geo_url'        => esc_url_raw(rest_url('iqu/v1/geo')),
            'waitlist_url'   => esc_url_raw(rest_url('iqu/v1/waitlist')),
            'waitlist_nonce' => wp_create_nonce('iqu_waitlist'),
            'rest_nonce'     => wp_create_nonce('wp_rest'),
            'nanp'           => IQU_Eligibility::js_nanp(),
            'block_title'    => 'We are not in your country yet',
            'block_message'  => IQU_Eligibility::block_message(),
            'contact_url'    => $contact['url'],
            'contact_label'  => $contact['label'],
        ];
    }

    /**
     * "Live in the US or Canada and travelling?" link under the modal.
     * IQU_CONTACT_WHATSAPP (optional, wp-config.php): a phone number or a wa.me link.
     * Without it, Facebook Messenger (IQU_MESSENGER_URL) is used.
     * @return array{url:string, label:string}
     */
    public static function travel_contact(): array
    {
        $text = "Assalamu alaikum. I live in the US or Canada and I am travelling right now. I would like to enroll at Ilm-ul-Quran USA.";
        $wa = defined('IQU_CONTACT_WHATSAPP') ? trim((string) IQU_CONTACT_WHATSAPP) : '';
        if ($wa !== '') {
            if (preg_match('#^https://(wa\.me|api\.whatsapp\.com)/#i', $wa)) {
                $url = strpos($wa, 'text=') === false ? add_query_arg('text', rawurlencode($text), $wa) : $wa;
            } else {
                $digits = preg_replace('/\D/', '', $wa);
                $url = $digits !== '' ? 'https://wa.me/' . $digits . '?text=' . rawurlencode($text) : '';
            }
            if ($url !== '') return ['url' => $url, 'label' => 'Live in the US or Canada and travelling? Message us on WhatsApp'];
        }
        return ['url' => IQU_MESSENGER_URL, 'label' => 'Live in the US or Canada and travelling? Message us on Facebook Messenger'];
    }

    /** Summer fee in USD (Stripe mode): 'standard' or 'supported'. Always from the server, never the browser. */
    public static function summer_fee(string $which = 'standard'): float
    {
        $opt = $which === 'supported' ? self::OPT_SUMMER_FEE_SUPPORTED : self::OPT_SUMMER_FEE;
        $v = self::parse_amount((string) get_option($opt, self::SUMMER_FEE_DEFAULTS[$opt]));
        return (float) ($v ?? self::SUMMER_FEE_DEFAULTS[$opt]);
    }

    /** "40", "40.5", "40.00", "$40" → "40.00"; null unless a positive amount with at most 2 decimals, at least 1.00. */
    public static function parse_amount(string $raw): ?string
    {
        $raw = trim(str_replace(['$', ',', ' '], '', $raw));
        if (!preg_match('/^\d{1,5}(\.\d{1,2})?$/', $raw)) return null;
        $n = round((float) $raw, 2);
        return $n >= 1.00 ? number_format($n, 2, '.', '') : null;
    }

    /**
     * Save the switches and Summer fees from a posted form. Unknown values keep the current setting.
     * @return string[] Problems to show (a fee that was not saved).
     */
    public static function save(array $post): array
    {
        $country = sanitize_key((string) ($post['country_check'] ?? ''));
        $free    = sanitize_key((string) ($post['free_payment'] ?? ''));
        $summer  = sanitize_key((string) ($post['summer_payment'] ?? ''));
        if (in_array($country, self::COUNTRY_MODES, true)) update_option(self::OPT_COUNTRY, $country, false);
        if (in_array($free, self::FREE_MODES, true)) update_option(self::OPT_FREE, $free, false);
        if (in_array($summer, self::SUMMER_MODES, true)) update_option(self::OPT_SUMMER, $summer, false);

        $problems = [];
        foreach (['summer_fee' => [self::OPT_SUMMER_FEE, 'Summer program fee'], 'summer_fee_supported' => [self::OPT_SUMMER_FEE_SUPPORTED, 'Summer supported rate']] as $field => [$opt, $label]) {
            if (!array_key_exists($field, $post)) continue;
            $v = self::parse_amount(sanitize_text_field((string) $post[$field]));
            if ($v === null) {
                $problems[] = $label . ' was not changed: enter an amount of at least 1.00 with up to 2 decimals.';
            } else {
                update_option($opt, $v, false);
            }
        }
        return $problems;
    }
}
