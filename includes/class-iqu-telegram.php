<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Telegram
 *
 * Sends operational notifications to a Telegram group.
 *
 * Design notes:
 * - Credentials come from wp-config constants, never the database.
 * - Every send is non-blocking. A slow Telegram API must never delay a
 *   donor's form submission or hold up the Zeffy webhook response — a
 *   late 200 makes Zeffy retry a delivery that already succeeded.
 * - Messages use HTML parse mode, so all interpolated values pass through
 *   esc() first. A donor name containing "<b>" would otherwise break the
 *   message or, worse, get silently dropped by Telegram.
 */
class IQU_Telegram
{
    /** Telegram truncates at 4096 characters. */
    private const MAX_LENGTH = 4000;

    private const API_BASE = 'https://api.telegram.org/bot';

    // ════════════════════════════════════════════════════
    // CREDENTIALS
    // ════════════════════════════════════════════════════

    public static function token(): string
    {
        return defined('IQU_TELEGRAM_BOT_TOKEN') ? (string) IQU_TELEGRAM_BOT_TOKEN : '';
    }

    public static function chat_id(): string
    {
        return defined('IQU_TELEGRAM_CHAT_ID') ? (string) IQU_TELEGRAM_CHAT_ID : '';
    }

    public static function is_configured(): bool
    {
        return self::token() !== '' && self::chat_id() !== '';
    }

    // ════════════════════════════════════════════════════
    // SENDING
    // ════════════════════════════════════════════════════

    /**
     * Fire a message at the configured group.
     *
     * @param string $html     Message body, HTML parse mode.
     * @param bool   $blocking Wait for the response. Only used by the test
     *                         button, where the admin needs to see the error.
     *
     * @return true|WP_Error True on dispatch; WP_Error only when blocking.
     */
    public static function send(string $html, bool $blocking = false)
    {
        if (!self::is_configured()) {
            return new WP_Error(
                'iqu_telegram_unconfigured',
                'Telegram is not configured. Add IQU_TELEGRAM_BOT_TOKEN and IQU_TELEGRAM_CHAT_ID to wp-config.php.'
            );
        }

        $html = self::truncate($html);

        $response = wp_remote_post(self::API_BASE . self::token() . '/sendMessage', [
            'timeout'   => $blocking ? 15 : 5,
            'blocking'  => $blocking,
            'body'      => [
                'chat_id'                  => self::chat_id(),
                'text'                     => $html,
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => 'true',
            ],
        ]);

        if (!$blocking) {
            return true;
        }

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['ok'])) {
            // Telegram's own description is far more useful than an HTTP code.
            $description = $body['description'] ?? 'Unknown Telegram error.';
            return new WP_Error('iqu_telegram_api', $description);
        }

        return true;
    }

    // ════════════════════════════════════════════════════
    // FORMATTING HELPERS
    // ════════════════════════════════════════════════════

    /**
     * Escape a value for Telegram HTML parse mode.
     * Only & < > are special; over-escaping mangles apostrophes in names.
     */
    public static function esc($value): string
    {
        return htmlspecialchars((string) $value, ENT_NOQUOTES, 'UTF-8');
    }

    private static function truncate(string $text): string
    {
        if (mb_strlen($text) <= self::MAX_LENGTH) {
            return $text;
        }
        return mb_substr($text, 0, self::MAX_LENGTH - 20) . "\n\n… (truncated)";
    }

    /**
     * Build a message from an emoji title and label/value rows.
     * Empty values are dropped so a message never shows "Fund: —".
     *
     * @param array<string,string> $rows
     */
    public static function compose(string $title, array $rows, string $footer = ''): string
    {
        $lines = ['<b>' . self::esc($title) . '</b>', ''];

                foreach ($rows as $label => $value) {
            $value = trim((string) $value);
            if ($value === '' || $value === '—') {
                continue;
            }

            // A dash key means "no label" — used for standalone links,
            // which read better without a "—:" prefix in front of them.
            if ($label === '—') {
                $lines[] = '';
                $lines[] = $value;
                continue;
            }

            $lines[] = self::esc($label) . ': <b>' . $value . '</b>';
        }

        if ($footer !== '') {
            $lines[] = '';
            $lines[] = '<i>' . self::esc($footer) . '</i>';
        }

        return implode("\n", $lines);
    }

    /**
     * Site-local timestamp, matching the admin tables (Asia/Dhaka).
     */
    public static function now(): string
    {
        try {
            $dt = new DateTime('now', new DateTimeZone('Asia/Dhaka'));
            return $dt->format('M j, Y g:i A');
        } catch (Exception $e) {
            return gmdate('M j, Y g:i A') . ' UTC';
        }
    }

    /**
     * A clickable admin link. Telegram renders these inline, so the team
     * can jump straight to the record from the notification.
     */
    public static function link(string $url, string $label): string
    {
        return '<a href="' . esc_url_raw($url) . '">' . self::esc($label) . '</a>';
    }
}