<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Dob
 *
 * Date of birth: the one Month | Day | Year control used by all three public forms (Enroll for
 * Free, Weekend, Summer), and the date helpers the validator, the student page and the
 * birthday list share. Front end: public/js/dob.js (one module) and the .iqu-dob rules in
 * public/css/form.css.
 *
 * "Today" is always the site time zone (wp_date). Someone born on 29 February has their
 * birthday on 28 February in a year without one, and turns a year older that day.
 * A date of birth is never sent to Meta (see IQU_Pixel).
 */
class IQU_Dob
{
    /** Under this age a parent or guardian's name is required on Enroll for Free. */
    public const ADULT_AGE = 18;

    public const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    /**
     * The control: three native selects (Month | Day | Year, US order) and the hidden ISO
     * field `date_of_birth` that dob.js fills once all three are chosen. Native selects keep
     * full keyboard use; the form's select style applies to them as to every other select.
     *
     * @param array $a id (prefix for the element ids), label, help (optional, under the label),
     *                 help_id (optional), min_age, max_age, hint (true: the under-18 / 18+ line)
     */
    public static function render(array $a): string
    {
        $id   = preg_replace('/[^a-z0-9_-]/i', '', (string) ($a['id'] ?? 'dob'));
        $min  = max(0, (int) ($a['min_age'] ?? 0));
        $max  = max($min, (int) ($a['max_age'] ?? 100));
        $year = (int) wp_date('Y');
        $help = (string) ($a['help'] ?? '');

        $html  = '<div class="iqu-question iqu-dob" role="group" aria-labelledby="' . esc_attr($id) . '-label" data-iqu-dob'
            . ' data-min-age="' . $min . '" data-max-age="' . $max . '">';
        $html .= '<label id="' . esc_attr($id) . '-label" for="' . esc_attr($id) . '-month">' . esc_html((string) $a['label']) . ' <span class="req">*</span></label>';
        if ($help !== '') {
            $html .= '<p class="iqu-help"' . (!empty($a['help_id']) ? ' id="' . esc_attr((string) $a['help_id']) . '"' : '') . '>' . esc_html($help) . '</p>';
        }
        $html .= '<div class="iqu-dob-selects">';
        $html .= '<select id="' . esc_attr($id) . '-month" class="iqu-dob-month" aria-label="Month" autocomplete="bday-month" data-iqu-native="1"><option value="">Month</option>';
        foreach (self::MONTHS as $i => $m) $html .= '<option value="' . ($i + 1) . '">' . esc_html($m) . '</option>';
        $html .= '</select>';
        $html .= '<select id="' . esc_attr($id) . '-day" class="iqu-dob-day" aria-label="Day" autocomplete="bday-day" data-iqu-native="1"><option value="">Day</option>';
        for ($d = 1; $d <= 31; $d++) $html .= '<option value="' . $d . '">' . $d . '</option>';
        $html .= '</select>';
        $html .= '<select id="' . esc_attr($id) . '-year" class="iqu-dob-year" aria-label="Year" autocomplete="bday-year" data-iqu-native="1"><option value="">Year</option>';
        for ($y = $year - $min; $y >= $year - $max; $y--) $html .= '<option value="' . $y . '">' . $y . '</option>';
        $html .= '</select>';
        $html .= '</div>';
        // Enroll for Free: "Under 18 — …" / "18 or older — …", written by form.js once a full date is chosen.
        if (!empty($a['hint'])) $html .= '<p class="iqu-dob-hint" aria-live="polite"></p>';
        $html .= '<input type="hidden" name="date_of_birth" class="iqu-dob-value" value="">';
        $html .= '<span class="iqu-error" data-field="date_of_birth"></span>';
        $html .= '</div>';
        return $html;
    }

    /** Today in the site time zone (Y-m-d). */
    public static function today(): string
    {
        return wp_date('Y-m-d');
    }

    /** A real calendar date as YYYY-MM-DD, or ''. */
    public static function clean(string $iso): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($iso), $m)) return '';
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : '';
    }

    /** The birthday in a given year (Y-m-d): 29 February becomes 28 February when the year has none. */
    public static function birthday_in(string $dob, int $year): string
    {
        [, $m, $d] = array_map('intval', explode('-', $dob));
        if ($m === 2 && $d === 29 && !checkdate(2, 29, $year)) $d = 28;
        return sprintf('%04d-%02d-%02d', $year, $m, $d);
    }

    /** Age in whole years on $on (Y-m-d; default today in the site time zone). */
    public static function age_on(string $dob, ?string $on = null): int
    {
        $on = $on ?? self::today();
        $y  = (int) substr($on, 0, 4);
        $age = $y - (int) substr($dob, 0, 4);
        return strcmp($on, self::birthday_in($dob, $y)) < 0 ? $age - 1 : $age;
    }

    /** True when $on (default today, site time zone) is the birthday. */
    public static function is_birthday(string $dob, ?string $on = null): bool
    {
        $on = $on ?? self::today();
        return self::birthday_in($dob, (int) substr($on, 0, 4)) === $on;
    }

    /** "Mar 4, 2016" — the date as shown on the student page. */
    public static function format(string $dob): string
    {
        $t = DateTimeImmutable::createFromFormat('!Y-m-d', $dob, new DateTimeZone('UTC'));
        return $t ? $t->format('M j, Y') : '';
    }
}
