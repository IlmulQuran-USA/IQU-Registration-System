<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Coupon
 *
 * 🎟️ কুপন কোড জেনারেশন ও যাচাই — বিজনেস লজিক লেয়ার।
 * ডেটাবেজ অ্যাক্সেস সব IQU_Coupon_DB-এর মাধ্যমে।
 *
 * ── কোড ফরম্যাট ──────────────────────────────────────
 *   {SHORT}-{YYMMDD}-{RAND4}-{VALUE}
 *
 *   HFZ-260826-X7K2-80     → Hifz, ২৬ আগস্ট ২০২৬, ৮০% ছাড়
 *   QAD-260826-M4P9-100    → Qa'idah, ১০০% স্কলারশিপ
 *   ARB-260826-B2T7-F25    → Arabic, ফিক্সড $25 ছাড় (F = fixed)
 *   ALL-260826-K9RD-50     → সব কোর্সে প্রযোজ্য, ৫০% ছাড়
 *
 *   হাইফেন দিয়ে সেগমেন্ট করার কারণ — RANDOM অংশে সংখ্যা থাকলেও
 *   ডিসকাউন্ট ভ্যালু আলাদা করে পড়া যায়, আর WhatsApp/Messenger-এ
 *   টাইপ করতে ভুল কম হয়।
 *
 *   RAND4-এ 0/O এবং 1/I/L বাদ দেওয়া হয়েছে — দেখতে একরকম হওয়ায়
 *   মানুষ ভুল টাইপ করে।
 */
class IQU_Coupon
{
    /** সব কোর্সে প্রযোজ্য কুপনের প্রিফিক্স */
    public const ALL_COURSES_SHORT = 'ALL';

    /** বিভ্রান্তিকর ক্যারেক্টার বাদ দেওয়া অ্যালফাবেট */
    private const SAFE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    private const RANDOM_LENGTH = 4;

    /** ইউনিক কোড বানানোর সর্বোচ্চ চেষ্টা */
    private const MAX_GENERATE_ATTEMPTS = 25;

    // ════════════════════════════════════════════════════
    // GENERATION
    // ════════════════════════════════════════════════════

    /**
     * ইউনিক কুপন কোড তৈরি করে।
     *
     * @param string $course_type   কোর্স key, অথবা '' মানে সব কোর্স
     * @param string $discount_type percentage | fixed
     * @param float  $value         শতকরা হার অথবা ডলার অঙ্ক
     *
     * @return string  ইউনিক কোড, অথবা '' যদি বানানো না যায়
     */
    public static function generate_code(string $course_type, string $discount_type, float $value): string
    {
        $short = $course_type === ''
            ? self::ALL_COURSES_SHORT
            : IQU_Pricing::short_code($course_type);

        if ($short === '') {
            return '';
        }

        $date = current_time('ymd');

        // ভ্যালু সেগমেন্ট: percentage → "80",  fixed → "F25"
        $value_segment = $discount_type === IQU_Coupon_DB::TYPE_FIXED
            ? 'F' . (int) round($value)
            : (string) (int) round($value);

        for ($i = 0; $i < self::MAX_GENERATE_ATTEMPTS; $i++) {
            $code = sprintf(
                '%s-%s-%s-%s',
                $short,
                $date,
                self::random_segment(),
                $value_segment
            );

            if (!IQU_Coupon_DB::code_exists($code)) {
                return $code;
            }
        }

        return '';
    }

    /**
     * ক্রিপ্টোগ্রাফিকভাবে নিরাপদ র‍্যান্ডম সেগমেন্ট।
     * অনুমান করা কঠিন হওয়া দরকার — নইলে কেউ কোড আন্দাজ করে ফ্রি ক্লাস নেবে।
     */
    private static function random_segment(): string
    {
        $alphabet = self::SAFE_ALPHABET;
        $max      = strlen($alphabet) - 1;
        $out      = '';

        for ($i = 0; $i < self::RANDOM_LENGTH; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }

    // ════════════════════════════════════════════════════
    // VALIDATION
    // ════════════════════════════════════════════════════

    /**
     * কুপন যাচাই করে ডিসকাউন্ট হিসাব করে।
     *
     * ⚠️ এটি কেবল যাচাই করে — রিডিম করে না। রেজিস্ট্রেশন সফলভাবে
     *    সেভ হওয়ার পরেই IQU_Coupon_DB::redeem() কল করতে হবে।
     *
     * @param string $code          ইউজারের দেওয়া কোড
     * @param string $course_type   সে যে কোর্স সিলেক্ট করেছে
     * @param float  $base_amount   ডিসকাউন্টের আগের মাসিক ফি
     *
     * @return array{
     *   valid: bool,
     *   error: string,
     *   coupon_id: int,
     *   code: string,
     *   discount_type: string,
     *   discount_value: float,
     *   discount_amount: float,
     *   final_amount: float,
     *   discount_percent: float,
     *   is_full_scholarship: bool,
     *   occasion_name: string
     * }
     */
    public static function validate(string $code, string $course_type, float $base_amount): array
    {
        $result = [
            'valid'               => false,
            'error'               => '',
            'coupon_id'           => 0,
            'code'                => '',
            'discount_type'       => '',
            'discount_value'      => 0.00,
            'discount_amount'     => 0.00,
            'final_amount'        => $base_amount,
            'discount_percent'    => 0.00,
            'is_full_scholarship' => false,
            'occasion_name'       => '',
        ];

        $code = strtoupper(trim($code));

        if ($code === '') {
            $result['error'] = 'Please enter a coupon code.';
            return $result;
        }

        $coupon = IQU_Coupon_DB::get_by_code($code);

        // 🔒 ভুল কোড আর নিষ্ক্রিয় কোডের বার্তা একই রাখা হয়েছে, যাতে
        //    কেউ ব্রুট-ফোর্স করে বৈধ কোডের অস্তিত্ব বুঝতে না পারে।
        if (!$coupon) {
            $result['error'] = 'This coupon code is not valid. Please check the code and try again.';
            return $result;
        }

        if ($coupon['status'] !== IQU_Coupon_DB::STATUS_ACTIVE) {
            $result['error'] = 'This coupon code is not valid. Please check the code and try again.';
            return $result;
        }

        // মেয়াদ — তুলনা সাইটের টাইমজোনে
        if (!empty($coupon['expire_date']) && $coupon['expire_date'] !== '0000-00-00') {
            if ($coupon['expire_date'] < current_time('Y-m-d')) {
                $result['error'] = 'This coupon code has expired. Please contact us for a new one.';
                return $result;
            }
        }

        // ব্যবহারের সীমা (max_uses = 0 মানে সীমাহীন)
        $max_uses = (int) $coupon['max_uses'];
        if ($max_uses > 0 && (int) $coupon['used_count'] >= $max_uses) {
            $result['error'] = 'This coupon code has already been used. Please contact us for a new one.';
            return $result;
        }

        // কোর্স মিল — খালি course_type মানে সব কোর্সে চলবে
        if ($coupon['course_type'] !== '' && $coupon['course_type'] !== $course_type) {
            $result['error'] = sprintf(
                'This coupon is only valid for %s. Please select that course or use a different code.',
                IQU_Pricing::label($coupon['course_type'])
            );
            return $result;
        }

        if ($base_amount <= 0) {
            $result['error'] = 'Please select your course and class days before applying a coupon.';
            return $result;
        }

        // ── ডিসকাউন্ট হিসাব ──
        $type  = $coupon['discount_type'];
        $value = (float) $coupon['discount_value'];

        if ($type === IQU_Coupon_DB::TYPE_PERCENTAGE) {
            $value    = min(100.00, max(0.00, $value));
            $discount = round($base_amount * ($value / 100), 2);
        } else {
            $discount = round($value, 2);
        }

        // ডিসকাউন্ট কখনোই মূল অঙ্কের চেয়ে বেশি হবে না
        $discount = min($discount, $base_amount);
        $final    = round($base_amount - $discount, 2);

        $result['valid']               = true;
        $result['coupon_id']           = (int) $coupon['id'];
        $result['code']                = $coupon['code'];
        $result['discount_type']       = $type;
        $result['discount_value']      = $value;
        $result['discount_amount']     = $discount;
        $result['final_amount']        = $final;
        $result['discount_percent']    = $base_amount > 0
            ? round(($discount / $base_amount) * 100, 2)
            : 0.00;
        $result['is_full_scholarship'] = $final <= 0.00;
        $result['occasion_name']       = $coupon['occasion_name'];

        return $result;
    }

    // ════════════════════════════════════════════════════
    // DECLARATION TEXT
    // ════════════════════════════════════════════════════

    /**
     * যাকাত ঘোষণার টেক্সট — ছাড়ের পরিমাণ অনুযায়ী বদলায়।
     *
     * কুপন প্রয়োগ হলেই (আংশিক হোক বা পূর্ণ) এই স্বীকৃতি নেওয়া হবে,
     * কারণ দুই ক্ষেত্রেই যাকাত ফান্ড থেকে অর্থ ব্যয় হচ্ছে।
     */
    public static function declaration_text(float $discount_percent, float $discount_amount): string
    {
        $percent = (int) round($discount_percent);

        if ($percent >= 100) {
            return sprintf(
                'I sincerely confirm that, due to genuine financial hardship, '
                    . 'I believe I am eligible to receive Zakat assistance. I gratefully accept a full (100%%) '
                    . 'scholarship covering %s of monthly tuition from the Zakat fund of '
                    . 'Ilm-ul-Quran USA. I affirm that, to the best of my knowledge, the information I have provided is truthful '
                    . 'and complete.',
                IQU_Pricing::format($discount_amount)
            );
        }

        return sprintf(
            'I sincerely confirm that, due to genuine financial hardship, '
                . 'I believe I am eligible to receive Zakat assistance. I gratefully accept a %d%% scholarship '
                . '(%s per month) from the Zakat fund of Ilm-ul-Quran USA. '
                . 'I affirm that, to the best of my knowledge, the information I have provided is truthful and complete.',
            $percent,
            IQU_Pricing::format($discount_amount)
        );
    }
}