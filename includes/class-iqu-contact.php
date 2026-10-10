<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Contact
 *
 * How families reach us, from the constants only (never hard-coded):
 *   IQU_CONTACT_EMAIL     email address
 *   IQU_CONTACT_WHATSAPP  WhatsApp number, country code + number (digits), e.g. 14692756450
 *   IQU_MESSENGER_URL     Facebook page / Messenger link
 * A missing or empty constant simply leaves its line out.
 */
class IQU_Contact
{
    public static function email(): string
    {
        $e = defined('IQU_CONTACT_EMAIL') ? trim((string) IQU_CONTACT_EMAIL) : '';
        return is_email($e) ? $e : '';
    }

    public static function whatsapp_digits(): string
    {
        return defined('IQU_CONTACT_WHATSAPP') ? (string) preg_replace('/\D/', '', (string) IQU_CONTACT_WHATSAPP) : '';
    }

    /** "+1 (469) 275-6450" for a US/Canada number; other numbers as "+<digits>". */
    public static function whatsapp_display(): string
    {
        $d = self::whatsapp_digits();
        if ($d === '') return '';
        if (strlen($d) === 10) $d = '1' . $d;
        if (strlen($d) === 11 && $d[0] === '1') {
            return '+1 (' . substr($d, 1, 3) . ') ' . substr($d, 4, 3) . '-' . substr($d, 7);
        }
        return '+' . $d;
    }

    public static function whatsapp_url(): string
    {
        $d = self::whatsapp_digits();
        if (strlen($d) === 10) $d = '1' . $d;
        return $d !== '' ? 'https://wa.me/' . $d : '';
    }

    public static function facebook(): string
    {
        $u = defined('IQU_MESSENGER_URL') ? trim((string) IQU_MESSENGER_URL) : '';
        return preg_match('#^https://#i', $u) ? $u : '';
    }

    /**
     * The contact lines that exist, in order.
     * @return array<string, array{0:string,1:string}> label => [text to show, link]
     */
    public static function lines(): array
    {
        $out = [];
        if (($e = self::email()) !== '') $out['Email'] = [$e, 'mailto:' . $e];
        if (($w = self::whatsapp_display()) !== '') $out['WhatsApp'] = [$w, self::whatsapp_url()];
        if (($f = self::facebook()) !== '') $out['Facebook'] = [$f, $f];
        return $out;
    }

    /** "Need help? Contact us:" and one "Label: value" line each, or '' when no constant is set. */
    public static function text_block(): string
    {
        $lines = self::lines();
        if (!$lines) return '';
        $out = ['Need help? Contact us:'];
        foreach ($lines as $label => [$text]) $out[] = $label . ': ' . $text;
        return implode("\n", $out);
    }
}
