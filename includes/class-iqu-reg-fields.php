<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Reg_Fields
 *
 * 📋 রেজিস্ট্রেশনের ফিল্ড তালিকার একমাত্র সোর্স অব ট্রুথ।
 *
 * ⚠️ কেন এই ক্লাস দরকার হলো:
 * আগে ইমেইলের ফিল্ড তালিকা ছিল IQU_Mailer-এ, আর টেলিগ্রামেরটা
 * IQU_Notifier-এ — দুটো আলাদা কোডে। ফলে টেলিগ্রামে মাত্র ৮টা ফিল্ড
 * যেত, ইমেইলে ১৭টা। নতুন ফিল্ড যোগ করলে একটায় দিয়ে অন্যটা ভুলে
 * যাওয়া অনিবার্য ছিল।
 *
 * এখন দুই চ্যানেলই admin_rows() থেকে পড়ে, তাই **ইমেইলে যা যায়,
 * টেলিগ্রামেও ঠিক তা-ই যায়** — চিরকালের জন্য।
 *
 * এখানে সব মান **প্লেইন টেক্সট**। এসকেপিং যে যার চ্যানেলের নিয়মে
 * করে — ইমেইলে esc_html(), টেলিগ্রামে IQU_Telegram::esc()।
 */
class IQU_Reg_Fields
{
    /**
     * অ্যাডমিনের জন্য সম্পূর্ণ ফিল্ড তালিকা।
     *
     * খালি মান বাদ দেওয়া হয় না — অ্যাডমিনের জানা দরকার কোন ঘরটা
     * ফাঁকা এসেছে। (টেলিগ্রামের compose() নিজে খালি সারি বাদ দেয়।)
     *
     * @return array<int, array{0:string, 1:string}>  [label, value] জোড়া
     */
    public static function admin_rows(array $data, int $reg_id = 0): array
    {
        $form_type = (string) ($data['form_type'] ?? '');
        $is_free   = $form_type === IQU_Database::FORM_FREE;

        $rows = [];
        $rows[] = ['Form', IQU_Mailer::form_label($form_type)];
        $rows[] = ['Participant', trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''))];
        $rows[] = ['Email', (string) ($data['email'] ?? '')];

        if ($is_free) {
            return array_merge($rows, self::free_rows($data));
        }

        return array_merge($rows, self::summer_rows($data));
    }

    // ════════════════════════════════════════════════════
    // FREE ENROLLMENT
    // ════════════════════════════════════════════════════

    private static function free_rows(array $data): array
    {
        $rows = [];

        // ── ব্যক্তিগত তথ্য ──
        $rows[] = ['Age', (string) (int) ($data['age'] ?? 0)];
        $rows[] = ['Country of Origin', (string) ($data['country_origin'] ?? '')];
        $rows[] = ['Country of Residence', (string) ($data['country_res'] ?? '')];
        $rows[] = ['WhatsApp', (string) ($data['whatsapp'] ?? '')];
        $rows[] = ['Languages', self::title_list($data['languages'] ?? '')];

        // ── কোর্স ও একাডেমিক ──
        $rows[] = ["Qur'an Level", ucfirst((string) ($data['quran_level'] ?? ''))];
        $rows[] = ['Memorized Before', trim((string) ($data['memorized'] ?? '')) ?: 'No'];

        // ── 💰 টিউশন ──
        $rows = array_merge($rows, self::tuition_rows($data));

        // ── সময়সূচি ──
        $rows[] = ['Preferred Days', self::title_list($data['preferred_days'] ?? '')];
        $rows[] = ['Time Slot', (string) ($data['time_slot'] ?? '')];
        $rows[] = ['Session Duration', self::duration_label($data['session_dur'] ?? '')];
        $rows[] = ['Teacher Preference', self::teacher_label($data['teacher_pref'] ?? '')];

        // ── অন্যান্য ──
        $referral = (string) ($data['referral'] ?? '');
        if ($referral === 'other' && !empty($data['referral_other'])) {
            $rows[] = ['Heard About Us Via', 'Other — ' . $data['referral_other']];
        } else {
            $rows[] = ['Heard About Us Via', self::title_case($referral)];
        }

        return $rows;
    }

    /**
     * 💰 টিউশনের সারিগুলো।
     *
     * নতুন (v2.8.0+) ও পুরনো — দুই ধরনের রেকর্ডই সামলায়, যাতে
     * পুরনো রেজিস্ট্রেশন পুনরায় পাঠালেও ঠিকঠাক দেখায়।
     */
    private static function tuition_rows(array $data): array
    {
        $course = (string) ($data['course_type'] ?? '');

        // ── পুরনো রেকর্ড ──
        if ($course === '') {
            $rows = [];
            $rows[] = ['Days / Week', (string) ($data['days_per_week'] ?? '')];
            $rows[] = ['Monthly Fee Pref.', IQU_Mailer::format_free_fee((string) ($data['fee_pref'] ?? ''))];

            if (!empty($data['free_request_reason'])) {
                $rows[] = ['Free Enrollment Note', (string) $data['free_request_reason']];
            }
            return $rows;
        }

        // ── নতুন সিস্টেম ──
        $days     = (int) ($data['days_per_week'] ?? 0);
        $rate     = (float) ($data['per_class_rate'] ?? 0);
        $original = (float) ($data['calculated_amount'] ?? 0);
        $discount = (float) ($data['discount_amount'] ?? 0);
        $final    = (float) ($data['payment_amount'] ?? 0);
        $coupon   = (string) ($data['coupon_code'] ?? '');
        $special  = !empty($data['special_discount']);
        $classes  = $days * IQU_Pricing::WEEKS_PER_MONTH;

        $rows = [];
        $rows[] = ['Course', IQU_Pricing::label($course) ?: $course];
        $rows[] = ['Classes / Week', (string) $days];

        if ($rate > 0) {
            $rows[] = [
                'Rate Breakdown',
                sprintf('%d classes/month × $%s per class', $classes, number_format($rate, 2)),
            ];
        }

        $rows[] = ['Regular Tuition', '$' . number_format($original, 2) . ' / month'];

        // 🎁 স্পেশাল ডিসকাউন্ট (Qa'idah) — কুপনের সাথে কখনো একসাথে আসে না
        if ($special && $discount > 0) {
            $percent = $original > 0 ? (int) round(($discount / $original) * 100) : 0;

            $rows[] = [
                'Special Discount',
                '−$' . number_format($discount, 2) . ' (' . $percent . '% off · student opted in)',
            ];
        }

        // ছাড় থাকলেই কেবল কুপন সংক্রান্ত সারি
        if (!$special && $discount > 0) {
            $percent = $original > 0 ? (int) round(($discount / $original) * 100) : 0;

            $rows[] = ['Coupon Code', $coupon];
            $rows[] = [
                'Zakat Scholarship',
                '−$' . number_format($discount, 2) . ' (' . $percent . '% covered)',
            ];
            $rows[] = [
                'Zakat Declaration',
                !empty($data['zakat_declaration'])
                    ? '✅ Confirmed by student'
                    : '⚠️ NOT confirmed',
            ];
        }

        $rows[] = [
            'Monthly Amount Due',
            $final > 0
                ? '$' . number_format($final, 2)
                : '$0.00 — full Zakat scholarship',
        ];

        return $rows;
    }

    // ════════════════════════════════════════════════════
    // SUMMER PROGRAM
    // ════════════════════════════════════════════════════

    private static function summer_rows(array $data): array
    {
        $rows = [];

        $rows[] = ['Age', (string) (int) ($data['age'] ?? 0)];
        $rows[] = ['Enrollment Level', strtoupper((string) ($data['enrollment_level'] ?? ''))];
        $rows[] = ['Country of Origin', (string) ($data['country_origin'] ?? '')];
        $rows[] = ['Country of Residence', (string) ($data['country_res'] ?? '')];
        $rows[] = ['Guardian', (string) ($data['guardian_name'] ?? '')];
        $rows[] = ['Guardian Contact', (string) ($data['guardian_contact'] ?? '')];
        $rows[] = ['Guardian WhatsApp', (string) ($data['guardian_whatsapp'] ?? '')];
        $rows[] = ['Join WhatsApp Group', ucfirst((string) ($data['whatsapp_group'] ?? ''))];
        $rows[] = ['Heard About Us Via', self::title_case((string) ($data['referral'] ?? ''))];
        $rows[] = ['Admission Fee', IQU_Mailer::format_admission_fee((string) ($data['admission_fee'] ?? ''))];

        if (!empty($data['flexible_fee_note'])) {
            $rows[] = ['Flexible / Free Note', (string) $data['flexible_fee_note']];
        }
        if (!empty($data['payment_method'])) {
            $rows[] = ['Payment Method', strtoupper((string) $data['payment_method'])];
        }
        if (isset($data['payment_amount']) && (float) $data['payment_amount'] > 0) {
            $rows[] = ['Payment Amount', '$' . number_format((float) $data['payment_amount'], 2)];
        }
        if (!empty($data['transaction_id'])) {
            $rows[] = ['Transaction ID', (string) $data['transaction_id']];
        }
        if (!empty($data['payment_status'])) {
            $rows[] = ['Payment Status', ucfirst((string) $data['payment_status'])];
        }

        return $rows;
    }

    // ════════════════════════════════════════════════════
    // STUDENT-FACING SUMMARY
    // ════════════════════════════════════════════════════

    /**
     * স্টুডেন্টের কনফার্মেশন ইমেইলের সংক্ষিপ্ত সারাংশ।
     *
     * ইচ্ছাকৃতভাবে ছোট — স্টুডেন্ট নিজের দেওয়া প্রতিটি তথ্য ফেরত
     * পড়তে চায় না, শুধু নিশ্চিত হতে চায় যে ঠিকঠাক জমা হয়েছে।
     * খালি মান এখানে বাদ যায়।
     */
    public static function student_rows(array $data): array
    {
        $rows = [];
        $rows[] = ['Name', trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''))];

        $course = (string) ($data['course_type'] ?? '');

        if ($course !== '') {
            $days     = (int) ($data['days_per_week'] ?? 0);
            $original = (float) ($data['calculated_amount'] ?? 0);
            $discount = (float) ($data['discount_amount'] ?? 0);
            $final    = (float) ($data['payment_amount'] ?? 0);

            $rows[] = ['Course', IQU_Pricing::label($course) ?: $course];
            $rows[] = ['Classes per Week', (string) $days];
            $rows[] = ["Qur'an Level", ucfirst((string) ($data['quran_level'] ?? ''))];
            $rows[] = ['Preferred Days', self::title_list($data['preferred_days'] ?? '')];
            $rows[] = ['Time Slot', (string) ($data['time_slot'] ?? '')];
            $rows[] = ['Session Duration', self::duration_label($data['session_dur'] ?? '')];
            $rows[] = ['Teacher Preference', self::teacher_label($data['teacher_pref'] ?? '')];

            if ($discount > 0) {
                $rows[] = ['Regular Tuition', '$' . number_format($original, 2) . ' / month'];
                $rows[] = [
                    !empty($data['special_discount']) ? 'Special Discount' : 'Scholarship Applied',
                    '−$' . number_format($discount, 2),
                ];
            }

            $rows[] = [
                'Your Monthly Tuition',
                $final > 0
                    ? '$' . number_format($final, 2) . ' / month'
                    : 'Fully covered by our Zakat fund',
            ];

            return $rows;
        }

        // পুরনো রেকর্ড
        $rows[] = ["Qur'an Level", ucfirst((string) ($data['quran_level'] ?? ''))];
        $rows[] = ['Preferred Days', self::title_list($data['preferred_days'] ?? '')];
        $rows[] = ['Time Slot', (string) ($data['time_slot'] ?? '')];
        $rows[] = ['Session Duration', self::duration_label($data['session_dur'] ?? '')];
        $rows[] = ['Teacher Preference', self::teacher_label($data['teacher_pref'] ?? '')];
        $rows[] = ['Monthly Fee', IQU_Mailer::format_free_fee((string) ($data['fee_pref'] ?? ''))];

        return $rows;
    }

    // ════════════════════════════════════════════════════
    // HELPERS
    // ════════════════════════════════════════════════════

    /** "monday, friday" → "Monday, Friday" */
    private static function title_list($csv): string
    {
        $csv = trim((string) $csv);
        if ($csv === '') return '';

        $parts = array_map('trim', explode(',', $csv));
        $parts = array_map(function ($p) {
            return self::title_case($p);
        }, $parts);

        return implode(', ', array_filter($parts));
    }

    /** "friend_family" → "Friend Family" */
    private static function title_case(string $value): string
    {
        return ucwords(str_replace('_', ' ', trim($value)));
    }

    private static function duration_label($value): string
    {
        $value = trim((string) $value);
        if ($value === '')      return '';
        if ($value === 'other') return 'Other';

        return is_numeric($value) ? $value . ' minutes' : $value;
    }

    private static function teacher_label($value): string
    {
        $value = trim((string) $value);

        return $value === 'no_preference'
            ? 'No preference'
            : ucfirst($value);
    }
}