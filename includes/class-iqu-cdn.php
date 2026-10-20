<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_CDN
 *
 * Subresource Integrity for the version-pinned files loaded from a CDN (Chart.js on the admin screens,
 * intl-tel-input and flatpickr on the public forms, which collect children's and guardians' details).
 * Each tag gets integrity="sha384-…" crossorigin="anonymous": if the CDN ever serves a changed file,
 * the browser refuses it instead of running it. Both CDNs send Access-Control-Allow-Origin: *.
 *
 * The hashes are of the exact files (the ?ver= WordPress adds does not change them). Changing a
 * version means changing its hash here. intl-tel-input loads its utils.js itself at run time, which a
 * tag attribute cannot cover.
 */
final class IQU_CDN
{
    /** URL without query string => SRI hash. */
    public const SRI = [
        'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js'           => 'sha384-bs/nf9FbdNouRbMiFcrcZfLXYPKiPaGVGplVbv7dLGECccEXDW+S3zjqSKR5ZEaD',
        'https://cdn.jsdelivr.net/npm/intl-tel-input@23.0.10/build/css/intlTelInput.css'    => 'sha384-WF0RAt8MGisWNsxq/lbm1i7nz61PKrW8z1neNPIJLbwqtsftpz3/xHsl/of5T8RR',
        'https://cdn.jsdelivr.net/npm/intl-tel-input@23.0.10/build/js/intlTelInput.min.js' => 'sha384-7Noj/yrPQg2T1aPphOIxRNQvR9xmlgiOsyRVLXh16Orb/WkWZzg33zmCfkR12e38',
        'https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css'             => 'sha384-RkASv+6KfBMW9eknReJIJ6b3UnjKOKC5bOUaNgIY778NFbQ8MtWq9Lr/khUgqtTt',
        'https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js'              => 'sha384-5JqMv4L/Xa0hfvtF06qboNdhvuYXUku9ZrhZh3bSk8VXF0A/RuSLHpLsSV9Zqhl6',
    ];

    public static function init(): void
    {
        add_filter('script_loader_tag', [__CLASS__, 'tag'], 10, 1);
        add_filter('style_loader_tag', [__CLASS__, 'tag'], 10, 1);
    }

    /** Adds integrity + crossorigin to the <script src> / <link href> of a listed file; anything else unchanged. */
    public static function tag($html)
    {
        if (!is_string($html) || strpos($html, '://cdn') === false) return $html;
        return preg_replace_callback('#<(script|link)\b[^>]*\b(src|href)=([\'"])([^\'"]+)\3[^>]*>#i', function ($m) {
            $url = strtok(html_entity_decode($m[4], ENT_QUOTES), '?');
            if (!isset(self::SRI[$url]) || stripos($m[0], ' integrity=') !== false) return $m[0];
            $attrs = ' integrity="' . esc_attr(self::SRI[$url]) . '" crossorigin="anonymous"';
            return preg_replace('#^<(script|link)\b#i', '<$1' . $attrs, $m[0]);
        }, $html);
    }
}
