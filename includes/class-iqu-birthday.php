<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Birthday
 *
 * Birthdays from the stored date of birth (IQU_Dob; "today" = the site time zone, born 29 February
 * → 28 February in other years):
 *   profile_chip()   the chip next to the name on the student page
 *   message()        the birthday wishes, version A (18 or older, to the student) or B (under 18, to the
 *                    parent / guardian); text in one place, filter `iqu_birthday_message`
 *   wishes_button()  "Send wishes": opens WhatsApp with the message (disabled without a number)
 *
 * Rows from before 3.6.0 have an age only: no current age is worked out for them.
 */
class IQU_Birthday
{
    /** Version A: the student is 18 or older (sent to the student). */
    public const MESSAGE_ADULT = "Assalamu Alaikum wa Rahmatullahi wa Barakatuh, {first_name}!\n\n"
        . "Today Allah ﷻ has completed another year of your life. Every year that passes is a gift, and a reminder that we are one step closer to meeting Him.\n\n"
        . "The Prophet ﷺ said: \"Take advantage of five before five: your youth before your old age, your health before your sickness, your wealth before your poverty, your free time before your busyness, and your life before your death.\" (al-Hakim)\n\n"
        . "May Allah fill the years ahead with barakah, keep your heart attached to the Qur'an, and grant you a good ending. Ameen.\n\n"
        . "With du'as,\nIlm-Ul-Quran USA";

    /** Version B: the student is under 18 (sent to the parent / guardian). */
    public const MESSAGE_CHILD = "Assalamu Alaikum wa Rahmatullahi wa Barakatuh!\n\n"
        . "Today {first_name} completes {age} years, alhamdulillah. Each year is a gift from Allah ﷻ, and a reminder that our time in this world is passing.\n\n"
        . "The Prophet ﷺ said: \"Take advantage of five before five: your youth before your old age, your health before your sickness, your wealth before your poverty, your free time before your busyness, and your life before your death.\" (al-Hakim)\n\n"
        . "May Allah make {first_name} among the people of the Qur'an, bless the years ahead, and make {first_name} a coolness of your eyes. Ameen.\n\n"
        . "With du'as,\nIlm-Ul-Quran USA";

    /** The stored date of birth (Y-m-d), or '' (rows from before 3.6.0). */
    public static function dob(array $row): string
    {
        return IQU_Dob::clean((string) ($row['date_of_birth'] ?? ''));
    }

    /**
     * The chip on the student page:
     *   birthday today  gold, cake icon: "Birthday today · turns N · Mar 4, 2016"
     *   date known      neutral:         "Mar 4, 2016 · Age N" (age today)
     *   no date         soft blue:       "Age N · at registration (Mar 2025)"
     */
    public static function profile_chip(array $row): string
    {
        $dob = self::dob($row);
        if ($dob !== '' && IQU_Dob::is_birthday($dob)) {
            return '<span class="iqu-chip iqu-dob-chip iqu-dob-chip--today">' . IQU_Icons::svg('cake', 12)
                . esc_html('Birthday today · turns ' . IQU_Dob::age_on($dob) . ' · ' . IQU_Dob::format($dob)) . '</span>';
        }
        if ($dob !== '') {
            return '<span class="iqu-chip iqu-dob-chip iqu-dob-chip--date">' . esc_html(IQU_Dob::format($dob) . ' · Age ' . IQU_Dob::age_on($dob)) . '</span>';
        }
        $created = strtotime((string) ($row['created_at'] ?? '') . ' UTC');
        return '<span class="iqu-chip iqu-dob-chip iqu-dob-chip--age">' . esc_html('Age ' . (int) ($row['age'] ?? 0)
            . ($created ? ' · at registration (' . wp_date('M Y', $created) . ')' : '')) . '</span>';
    }

    /** The wishes for a student with a date of birth: version A at 18 or older, B under 18 (age reached today). */
    public static function message(array $row): string
    {
        $dob   = self::dob($row);
        $age   = $dob !== '' ? IQU_Dob::age_on($dob) : (int) ($row['age'] ?? 0);
        $adult = $age >= IQU_Dob::ADULT_AGE;
        $text  = strtr($adult ? self::MESSAGE_ADULT : self::MESSAGE_CHILD, [
            '{first_name}' => trim((string) ($row['first_name'] ?? '')),
            '{age}'        => (string) $age,
        ]);
        return (string) apply_filters('iqu_birthday_message', $text, $row, $adult ? 'A' : 'B');
    }

    /** WhatsApp digits: the guardian's WhatsApp where the form stores one (Summer), else the stored WhatsApp number. */
    public static function whatsapp_digits(array $row): string
    {
        $number = trim((string) ($row['guardian_whatsapp'] ?? '')) ?: trim((string) ($row['whatsapp'] ?? ''));
        return (string) preg_replace('/\D+/', '', $number);
    }

    /** https://wa.me/{digits}?text={message}, or '' without a number. */
    public static function wishes_url(array $row): string
    {
        $digits = self::whatsapp_digits($row);
        return $digits === '' ? '' : 'https://wa.me/' . $digits . '?text=' . rawurlencode(self::message($row));
    }

    /** "Send wishes": a link opening WhatsApp in a new tab, or a disabled button "No WhatsApp number on file". */
    public static function wishes_button(array $row): string
    {
        $url = self::wishes_url($row);
        if ($url === '') {
            return '<span class="iqu-wishes-wrap" title="No WhatsApp number on file"><button type="button" class="iqu-btn iqu-btn-ghost iqu-wishes-btn" disabled aria-disabled="true">Send wishes</button></span>';
        }
        return '<a class="iqu-btn iqu-btn-ghost iqu-wishes-btn" href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">Send wishes</a>';
    }
}
