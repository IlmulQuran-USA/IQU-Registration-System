<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Billing_Email
 *
 * One formal layout for every billing and enrollment email: a white logo header with a thin
 * deep-blue rule, a short title, the greeting, a details table, "What you need to do" steps
 * when the family has to act, one primary button, a "Good to know" list, a "Need help?" box
 * (contact lines from IQU_Contact), the sign-off and a light footer. 600px wide, table-based
 * for email clients, with a hidden preheader and a plain-text part that mirrors the content.
 *
 * An email is an ordered list of blocks:
 *   ['p', 'text']                              paragraph
 *   ['note', 'text']                           smaller grey paragraph
 *   ['table', [['Label', 'value'], …]]         details table (two columns, zebra); an
 *   ['facts', ['Label' => 'value', …]]           associative array works too (facts = table)
 *   ['steps', 'What you need to do', [ … ]]    numbered steps
 *   ['list', 'Good to know', [ … ]]            bullet list
 *   ['button', 'Label', 'https://…']           the primary button (one per email)
 *   ['buttons', [['Label', url], ['Label', url]]]  primary + secondary button (receipt)
 *   ['badge', 'Payment received']              green status badge (receipt)
 *   ['hero', '$48.00 paid', '12 October 2026 · Visa •••• 4242']  large amount line (receipt)
 *
 * Options: preheader, title, greeting, blocks, help (extra sentence in the "Need help?" box),
 * private (true adds "This link is private to your family…"), footer_note (extra footer line).
 *
 * Security: every text is escaped here (esc_html / esc_url); callers pass plain text.
 */
class IQU_Billing_Email
{
    private const LOGO = 'uploads/2026/10/iqu-email-logo.png';

    private const POLICIES = [
        '/tuition-refund-policy/' => 'Tuition & Refund Policy',
        '/privacy-policy/'        => 'Privacy Policy',
    ];

    private const FOUNDATION = 'Ilm-ul-Quran USA is operated by AL HASANAH FOUNDATION, a 501(c)(3) nonprofit organization, Richardson, Texas.';
    private const PRIVATE_LINE = 'This link is private to your family. Please do not forward this email.';
    private const SIGN_OFF = ['Jazakum Allahu khayran,', 'The Ilm-ul-Quran USA team'];

    // Theme palette (the website's colours).
    private const BLUE  = '#1E4D6B';
    private const INK   = '#15303F';
    private const MUTED = '#414B58';
    private const LINE  = '#E5E7EB';
    private const ZEBRA = '#F7F9FB';
    private const ICE   = '#EFF8FC';
    private const PAGE  = '#F1F1F4';
    private const GREEN = '#0A553A';
    private const MINT  = '#EDFAEE';

    /**
     * @return array{html:string, text:string}
     */
    public static function render(array $a): array
    {
        $font = "'Segoe UI',Arial,Helvetica,sans-serif";
        $p    = 'margin:0 0 14px;font-size:15px;line-height:23px;color:' . self::INK;
        $h2   = 'margin:22px 0 10px;font-size:15px;line-height:20px;font-weight:bold;color:' . self::BLUE;
        $title = trim((string) ($a['title'] ?? ''));

        $html  = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . esc_html($title !== '' ? $title : 'Ilm-ul-Quran USA') . '</title>'
            // Phones: less side padding (clients without <style> support keep the 30px).
            . '<style>@media (max-width:480px){.iqu-px{padding-left:18px!important;padding-right:18px!important}}</style></head>'
            . '<body style="margin:0;padding:0;background:' . self::PAGE . '">';
        $html .= '<div style="display:none;max-height:0;max-width:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:' . self::PAGE . ';opacity:0">'
            . esc_html((string) ($a['preheader'] ?? '')) . '</div>';
        $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . self::PAGE . ';font-family:' . $font . '"><tr><td align="center" style="padding:24px 12px">';
        $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;background:#ffffff;border:1px solid ' . self::LINE . ';border-radius:12px;overflow:hidden">';
        // Header: white, logo, thin deep-blue rule.
        $html .= '<tr><td class="iqu-px" style="padding:22px 30px 18px;background:#ffffff;border-bottom:2px solid ' . self::BLUE . '">'
            . '<img src="' . esc_url(content_url(self::LOGO)) . '" width="200" alt="Ilm-ul-Quran USA" style="display:block;border:0;height:auto;max-width:200px">'
            . '</td></tr>';
        $html .= '<tr><td class="iqu-px" style="padding:28px 30px 8px;overflow-wrap:anywhere;word-break:break-word">';

        $text = [];
        if ($title !== '') {
            $html  .= '<h1 style="margin:0 0 18px;font-size:22px;line-height:28px;font-weight:bold;color:' . self::BLUE . '">' . esc_html($title) . '</h1>';
            $text[] = $title;
            $text[] = str_repeat('=', (int) mb_strlen($title));
            $text[] = '';
        }
        $greeting = (string) ($a['greeting'] ?? '');
        if ($greeting !== '') {
            $html  .= '<p style="' . $p . '">' . esc_html($greeting) . '</p>';
            $text[] = $greeting;
            $text[] = '';
        }

        foreach ((array) ($a['blocks'] ?? []) as $b) {
            $type = (string) ($b[0] ?? '');
            if ($type === 'p' || $type === 'note') {
                $style  = $type === 'p' ? $p : 'margin:0 0 14px;font-size:13px;line-height:20px;color:' . self::MUTED;
                $html  .= '<p style="' . $style . '">' . esc_html((string) $b[1]) . '</p>';
                $text[] = (string) $b[1];
                $text[] = '';
            } elseif ($type === 'table' || $type === 'facts') {
                $rows = self::pairs((array) $b[1]);
                if (!$rows) continue;
                $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:separate;border:1px solid ' . self::LINE . ';border-radius:8px;margin:4px 0 20px;font-size:14px;line-height:21px;color:' . self::INK . '">';
                foreach ($rows as $i => [$label, $value]) {
                    $bg  = $i % 2 ? self::ZEBRA : '#ffffff';
                    $top = $i ? 'border-top:1px solid ' . self::LINE . ';' : '';
                    $html .= '<tr><td style="' . $top . 'background:' . $bg . ';padding:10px 14px;color:' . self::MUTED . ';vertical-align:top;width:40%">' . esc_html($label) . '</td>'
                        . '<td style="' . $top . 'background:' . $bg . ';padding:10px 14px;font-weight:bold;vertical-align:top">' . esc_html($value) . '</td></tr>';
                    $text[] = $label . ': ' . $value;
                }
                $html  .= '</table>';
                $text[] = '';
            } elseif ($type === 'steps' || $type === 'list') {
                $items = array_values(array_filter(array_map('strval', (array) ($b[2] ?? [])), fn($s) => $s !== ''));
                if (!$items) continue;
                $html  .= '<h2 style="' . $h2 . '">' . esc_html((string) $b[1]) . '</h2>';
                $text[] = $b[1] . ':';
                $html  .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 18px;font-size:14px;line-height:21px;color:' . self::INK . '">';
                foreach ($items as $i => $item) {
                    $mark = $type === 'steps'
                        ? '<span style="display:inline-block;width:22px;height:22px;line-height:22px;border-radius:11px;background:' . self::BLUE . ';color:#ffffff;font-size:12px;font-weight:bold;text-align:center">' . ($i + 1) . '</span>'
                        : '<span style="color:' . self::BLUE . ';font-weight:bold">&bull;</span>';
                    $html .= '<tr><td style="width:30px;padding:3px 0;vertical-align:top">' . $mark . '</td><td style="padding:3px 0 3px 2px;vertical-align:top">' . esc_html($item) . '</td></tr>';
                    $text[] = ($type === 'steps' ? ($i + 1) . '. ' : '- ') . $item;
                }
                $html  .= '</table>';
                $text[] = '';
            } elseif ($type === 'button' || $type === 'buttons') {
                $btns = $type === 'button' ? [[(string) $b[1], (string) $b[2]]] : array_values((array) $b[1]);
                $cells = [];
                foreach ($btns as $i => [$label, $url]) {
                    if ((string) $url === '') continue;
                    $style = $i === 0
                        ? 'background:' . self::BLUE . ';color:#ffffff;border:2px solid ' . self::BLUE
                        : 'background:#ffffff;color:' . self::BLUE . ';border:2px solid ' . self::BLUE;
                    $cells[] = '<a href="' . esc_url($url) . '" style="display:inline-block;' . $style . ';text-decoration:none;font-weight:bold;font-size:15px;line-height:20px;padding:12px 26px;border-radius:999px;margin:4px 6px">' . esc_html($label) . '</a>';
                    $text[]  = $label . ': ' . $url;
                }
                if ($cells) $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 20px"><tr><td align="center">' . implode('', $cells) . '</td></tr></table>';
                $text[] = '';
            } elseif ($type === 'badge') {
                $html  .= '<p style="margin:0 0 10px;text-align:center"><span style="display:inline-block;background:' . self::MINT . ';color:' . self::GREEN . ';border:1px solid #BFE5C8;border-radius:999px;padding:5px 14px;font-size:13px;font-weight:bold">&#10003; ' . esc_html((string) $b[1]) . '</span></p>';
                $text[] = (string) $b[1];
            } elseif ($type === 'hero') {
                $html  .= '<p style="margin:0;text-align:center;font-size:32px;line-height:40px;font-weight:bold;color:' . self::INK . '">' . esc_html((string) $b[1]) . '</p>';
                $html  .= '<p style="margin:2px 0 22px;text-align:center;font-size:14px;line-height:20px;color:' . self::MUTED . '">' . esc_html((string) ($b[2] ?? '')) . '</p>';
                $text[] = (string) $b[1];
                if (($b[2] ?? '') !== '') $text[] = (string) $b[2];
                $text[] = '';
            }
        }

        // "Need help?" box: an optional sentence, then the contact lines that exist.
        $extra = trim((string) ($a['help'] ?? ''));
        $lines = class_exists('IQU_Contact') ? IQU_Contact::lines() : [];
        if ($extra !== '' || $lines) {
            $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . self::ICE . ';border:1px solid #D3E7F1;border-radius:8px;margin:8px 0 20px;font-size:14px;line-height:21px;color:' . self::INK . '"><tr><td style="padding:14px 16px">'
                . '<p style="margin:0 0 6px;font-weight:bold;color:' . self::BLUE . '">Need help?</p>';
            $text[] = 'Need help?';
            if ($extra !== '') {
                $html  .= '<p style="margin:0 0 8px">' . esc_html($extra) . '</p>';
                $text[] = $extra;
            }
            if ($lines) {
                $html  .= '<p style="margin:0">' . ($extra !== '' ? '' : 'Contact us:<br>');
                $parts  = [];
                foreach ($lines as $label => [$shown, $url]) {
                    $parts[] = esc_html($label) . ': <a href="' . esc_url($url) . '" style="color:' . self::BLUE . ';text-decoration:underline">' . esc_html($shown) . '</a>';
                    $text[]  = $label . ': ' . $shown;
                }
                $html .= implode('<br>', $parts) . '</p>';
            }
            $html  .= '</td></tr></table>';
            $text[] = '';
        }

        $html  .= '<p style="' . $p . ';margin-top:18px">' . esc_html(self::SIGN_OFF[0]) . '<br>' . esc_html(self::SIGN_OFF[1]) . '</p>';
        $html  .= '</td></tr>';
        $text[] = self::SIGN_OFF[0];
        $text[] = self::SIGN_OFF[1];
        $text[] = '';
        $text[] = '--';

        // Footer: who we are, the policies, and the private-link / extra lines.
        $links = [];
        $text[] = self::FOUNDATION;
        foreach (self::POLICIES as $path => $label) {
            $links[] = '<a href="' . esc_url(home_url($path)) . '" style="color:' . self::BLUE . ';text-decoration:underline">' . esc_html($label) . '</a>';
            $text[]  = $label . ': ' . home_url($path);
        }
        $after = [];
        if (!empty($a['private'])) $after[] = self::PRIVATE_LINE;
        $note = trim((string) ($a['footer_note'] ?? ''));
        if ($note !== '') $after[] = $note;
        foreach ($after as $line) $text[] = $line;
        $html .= '<tr><td class="iqu-px" style="background:' . self::ZEBRA . ';border-top:1px solid ' . self::LINE . ';padding:18px 30px 22px;overflow-wrap:anywhere;text-align:center;font-size:12px;line-height:18px;color:' . self::MUTED . '">'
            . esc_html(self::FOUNDATION) . '<br>' . implode(' &middot; ', $links)
            . implode('', array_map(fn($l) => '<br><span style="display:inline-block;margin-top:8px">' . esc_html($l) . '</span>', $after))
            . '</td></tr>';
        $html .= '</table></td></tr></table></body></html>';

        return ['html' => $html, 'text' => implode("\n", $text)];
    }

    /** [['Label', 'value'], …] from either a list of pairs or a Label => value map; empty values dropped. */
    private static function pairs(array $rows): array
    {
        $out = [];
        foreach ($rows as $k => $v) {
            [$label, $value] = is_array($v) ? [(string) ($v[0] ?? ''), (string) ($v[1] ?? '')] : [(string) $k, (string) $v];
            if ($label !== '' && $value !== '') $out[] = [$label, $value];
        }
        return $out;
    }

    // ------------------------------------------------------------
    // Shared details rows (local database only)
    // ------------------------------------------------------------

    /**
     * "Student(s)" (full names with IQU numbers) and one "Course" row per student
     * ("Qa'idah / Nazirah · 3 classes a week"), for one billing family.
     * @return array<int, array{0:string,1:string}>
     */
    public static function student_rows(array $acc): array
    {
        $names = [];
        $courses = [];
        foreach (IQU_Billing_DB::get_members((int) $acc['id']) as $m) {
            $reg = IQU_Database::get_registration((int) $m['registration_id']);
            if (!$reg) continue;
            $first = trim((string) $reg['first_name']);
            $names[] = trim($reg['first_name'] . ' ' . $reg['last_name']) . ' (IQU-' . (int) $reg['id'] . ')';
            $p = IQU_Billing_Pricing::for_registration($reg);
            $courses[$first] = ($p['course_label'] ?: 'Monthly classes') . ($p['days_per_week'] ? ' · ' . (int) $p['days_per_week'] . ' ' . ((int) $p['days_per_week'] === 1 ? 'class' : 'classes') . ' a week' : '');
        }
        $rows = [];
        if ($names) $rows[] = [count($names) > 1 ? 'Students' : 'Student', implode(', ', $names)];
        foreach ($courses as $first => $c) $rows[] = [count($courses) > 1 ? 'Course — ' . $first : 'Course', $c];
        return $rows;
    }

    /**
     * Monthly tuition rows: the tuition, and when the family has a discount the
     * "Zakat Fund support −$x" row and the monthly payment after it.
     * @return array<int, array{0:string,1:string}>
     */
    public static function tuition_rows(array $acc): array
    {
        $net  = (float) $acc['net_amount'];
        $disc = (float) ($acc['discount_amount'] ?? 0);
        $gross = (float) ($acc['monthly_amount'] ?? 0);
        if ($disc > 0 && $gross > $net) {
            return [
                ['Monthly tuition', IQU_Pricing::format($gross)],
                ['Zakat Fund support', '−' . IQU_Pricing::format($disc)],
                ['Your monthly payment', IQU_Pricing::format($net)],
            ];
        }
        return [['Monthly tuition', IQU_Pricing::format($net)]];
    }

    /** "1st", "2nd", "5th" … for "on the 5th of each month". */
    public static function ordinal(int $n): string
    {
        $s = ['th', 'st', 'nd', 'rd'];
        $v = $n % 100;
        return $n . ($s[($v - 20) % 10] ?? $s[$v] ?? $s[0]);
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
