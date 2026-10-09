<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Billing_Email
 *
 * One layout for every billing email: the same frame as the registration emails
 * (logo header with a deep blue rule, white card, deep blue footer), 600px wide,
 * table-based for email clients, with a hidden preheader and a plain-text part.
 *
 * An email is described as an ordered list of blocks, so each message keeps its
 * own order of sentences:
 *   ['p', 'text']                       paragraph
 *   ['facts', ['Label' => 'value', …]]  facts box (label / value rows)
 *   ['button', 'Label', 'https://…']    the primary button (one per email)
 *   ['note', 'text']                    smaller grey paragraph
 *
 * Security: every text is escaped here (esc_html / esc_url); callers pass plain text.
 */
class IQU_Billing_Email
{
    private const LOGO = 'uploads/2026/10/iqu-email-logo.png';

    private const POLICIES = [
        '/tuition-refund-policy/' => 'Tuition, Payment & Refund Policy',
        '/terms-and-conditions/'  => 'Terms and Conditions',
        '/privacy-policy/'        => 'Privacy Policy',
    ];

    private const FOUNDATION = 'Ilm-ul-Quran USA is operated by AL HASANAH FOUNDATION, a 501(c)(3) nonprofit.';

    /**
     * @param array $a {
     *   @type string $preheader   Hidden preview text (one sentence from the email).
     *   @type string $greeting    "Assalamu alaikum Sara,"
     *   @type array  $blocks      Ordered blocks, see the class comment.
     *   @type string $footer_note Sentence shown before the foundation line, or ''.
     * }
     * @return array{html:string, text:string}
     */
    public static function render(array $a): array
    {
        $ink   = '#15303F';
        $muted = '#414B58';
        $p     = 'margin:0 0 14px;font-size:15px;line-height:23px;color:' . $ink;

        $html = '<div style="display:none;max-height:0;max-width:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#F1F1F4;opacity:0">'
            . esc_html((string) ($a['preheader'] ?? '')) . '</div>';
        $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F1F1F4;font-family:\'Segoe UI\',Arial,Helvetica,sans-serif"><tr><td align="center" style="padding:24px 12px">';
        $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;background:#ffffff;border:1px solid #E5E7EB;border-radius:14px;overflow:hidden">';
        $html .= '<tr><td align="center" style="padding:24px 24px 16px;background:#EFF8FC;border-bottom:3px solid #1E4D6B">'
            . '<table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0"><tr><td>'
            . '<img src="' . esc_url(content_url(self::LOGO)) . '" width="240" alt="Ilm-ul-Quran USA" style="display:block;border:0;height:auto;max-width:240px">'
            . '</td></tr></table></td></tr>';
        $html .= '<tr><td style="padding:30px 30px 12px">';
        $html .= '<p style="' . $p . '">' . esc_html((string) ($a['greeting'] ?? '')) . '</p>';

        $text = [(string) ($a['greeting'] ?? ''), ''];

        foreach ((array) ($a['blocks'] ?? []) as $b) {
            $type = (string) ($b[0] ?? '');
            if ($type === 'p') {
                $html  .= '<p style="' . $p . '">' . esc_html((string) $b[1]) . '</p>';
                $text[] = (string) $b[1];
                $text[] = '';
            } elseif ($type === 'note') {
                $html  .= '<p style="margin:0 0 14px;font-size:13px;line-height:20px;color:' . $muted . '">' . esc_html((string) $b[1]) . '</p>';
                $text[] = (string) $b[1];
                $text[] = '';
            } elseif ($type === 'facts') {
                $rows = array_filter((array) $b[1], fn($v) => (string) $v !== '');
                if (!$rows) continue;
                $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#EFF8FC;border:1px solid #E5E7EB;border-left:4px solid #F7941D;border-radius:10px;margin:0 0 22px;font-size:14px;line-height:22px;color:' . $ink . '">';
                $last  = array_key_last($rows);
                foreach ($rows as $label => $value) {
                    $pad   = $label === $last ? '6px 18px 14px' : '6px 18px';
                    $first = $label === array_key_first($rows) ? '14px 18px 6px' : $pad;
                    $html .= '<tr><td style="padding:' . $first . ';color:' . $muted . ';white-space:nowrap;vertical-align:top;width:38%">' . esc_html((string) $label) . '</td>'
                        . '<td style="padding:' . $first . ';font-weight:bold;vertical-align:top">' . esc_html((string) $value) . '</td></tr>';
                    $text[] = $label . ': ' . $value;
                }
                $html  .= '</table>';
                $text[] = '';
            } elseif ($type === 'button') {
                $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px"><tr><td align="center">'
                    . '<a href="' . esc_url((string) $b[2]) . '" style="display:inline-block;background:#1E4D6B;color:#ffffff;text-decoration:none;font-weight:bold;font-size:16px;line-height:20px;padding:14px 28px;border-radius:8px">'
                    . esc_html((string) $b[1]) . '</a></td></tr></table>';
                $text[] = $b[1] . ': ' . $b[2];
                $text[] = '';
            }
        }

        $html  .= '<p style="' . $p . '">Jazakum Allahu khayran,<br>Ilm-ul-Quran USA</p>';
        $html  .= '</td></tr>';
        $text[] = 'Jazakum Allahu khayran,';
        $text[] = 'Ilm-ul-Quran USA';
        $text[] = '';
        $text[] = '--';

        $note   = trim((string) ($a['footer_note'] ?? ''));
        $footer = ($note !== '' ? $note . ' ' : '') . self::FOUNDATION;
        $links  = [];
        $text[] = $footer;
        foreach (self::POLICIES as $path => $label) {
            $links[] = '<a href="' . esc_url(home_url($path)) . '" style="color:#EFF8FC;text-decoration:underline">' . esc_html($label) . '</a>';
            $text[]  = $label . ': ' . home_url($path);
        }
        $html .= '<tr><td style="background:#1E4D6B;padding:16px 24px;text-align:center;font-size:12px;line-height:18px;color:#EFF8FC">'
            . esc_html($footer) . '<br>' . implode(' &middot; ', $links) . '</td></tr>';
        $html .= '</table></td></tr></table>';

        return ['html' => $html, 'text' => implode("\n", $text)];
    }

    /** Send an HTML email with its plain-text alternative. */
    public static function send(string $to, string $subject, array $a): bool
    {
        $out  = self::render($a);
        $alt  = $out['text'];
        $hook = function ($phpmailer) use ($alt) {
            $phpmailer->AltBody = $alt;
        };
        add_action('phpmailer_init', $hook);
        $ok = wp_mail($to, $subject, $out['html'], ['Content-Type: text/html; charset=UTF-8']);
        remove_action('phpmailer_init', $hook);
        return (bool) $ok;
    }
}
