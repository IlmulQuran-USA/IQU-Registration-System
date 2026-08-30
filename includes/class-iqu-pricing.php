<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Pricing
 *
 * 💰 কোর্স প্রাইসিং-এর একমাত্র সোর্স অব ট্রুথ।
 *
 * PHP (validator / AJAX handler) এবং JS (লাইভ প্রাইস ডিসপ্লে) — দুই জায়গাতেই
 * এই ক্লাসের ডেটা ব্যবহার হবে। JS-কে wp_localize_script() দিয়ে
 * self::js_config() পাঠানো হয়, তাই রেট বদলাতে হলে শুধু এই ফাইলের
 * COURSES অ্যারে বদলালেই সব জায়গায় বদলে যাবে।
 *
 * ⚠️ ক্লায়েন্ট থেকে আসা কোনো টাকার অঙ্ক কখনোই বিশ্বাস করা হয় না।
 *    সার্ভারে সবসময় calculate() দিয়ে নতুন করে হিসাব করা হয়।
 *
 * 📐 মডেল: মাসে ৪ সপ্তাহ ধরা হয় →  ক্লাস সংখ্যা = দিন/সপ্তাহ × ৪
 *
 * রেট (লক করা — ২০২৬-০৮):
 *   Qa'idah / Nazirah : ১–২ দিন → $5/ক্লাস,  ৩–৭ দিন → $4/ক্লাস
 *   Quran Hifz        : $5/ক্লাস — সর্বনিম্ন ৩ দিন বাধ্যতামূলক
 *   Arabic Language   : $5/ক্লাস — ১–৭ দিন সব একই রেট
 */
class IQU_Pricing
{
    /** মাসে কত সপ্তাহ ধরে হিসাব হবে */
    public const WEEKS_PER_MONTH = 4;

    /** সপ্তাহে সর্বোচ্চ কত দিন */
    public const MAX_DAYS_PER_WEEK = 7;

    /**
     * কোর্স ডেফিনিশন।
     *
     * tiers → দিন-ভিত্তিক রেট স্ল্যাব। প্রতিটি টায়ারের `up_to` হলো ওই
     * টায়ারের সর্বোচ্চ দিন সংখ্যা (inclusive)। ছোট থেকে বড় ক্রমে থাকতে হবে।
     */
    private const COURSES = [
        'qaidah' => [
            'label'    => "Qa'idah / Nazirah",
            'short'    => 'QAD',   // কুপন কোডের প্রিফিক্স
            'min_days' => 1,
            'tiers'    => [
                ['up_to' => 2, 'rate' => 5.00],
                ['up_to' => 7, 'rate' => 4.00],
            ],
        ],
        'hifz' => [
            'label'    => 'Quran Hifz',
            'short'    => 'HFZ',
            'min_days' => 3,       // 🔒 হেফজে ৩ দিনের কম প্রগ্রেস হয় না
            'tiers'    => [
                ['up_to' => 7, 'rate' => 5.00],
            ],
        ],
        'arabic' => [
            'label'    => 'Arabic Language',
            'short'    => 'ARB',
            'min_days' => 1,
            'tiers'    => [
                ['up_to' => 7, 'rate' => 5.00],
            ],
        ],
    ];


    private const SPECIAL_DISCOUNT = [
        'qaidah' => [
            3 => 40.00,
            4 => 45.00,
            5 => 50.00,
            6 => 70.00,
            7 => 85.00,
        ],
    ];

    // ════════════════════════════════════════════════════
    // Lookups
    // ════════════════════════════════════════════════════

    /** সব কোর্স key → ['qaidah', 'hifz', 'arabic'] */
    public static function course_keys(): array
    {
        return array_keys(self::COURSES);
    }

    public static function is_valid_course(string $course): bool
    {
        return isset(self::COURSES[$course]);
    }

    /** ড্রপডাউনের জন্য key => label */
    public static function course_options(): array
    {
        $out = [];
        foreach (self::COURSES as $key => $c) {
            $out[$key] = $c['label'];
        }
        return $out;
    }

    public static function label(string $course): string
    {
        return self::COURSES[$course]['label'] ?? '';
    }

    /** কুপন কোডের প্রিফিক্স — QAD / HFZ / ARB */
    public static function short_code(string $course): string
    {
        return self::COURSES[$course]['short'] ?? '';
    }

    /** কোর্স → সর্বনিম্ন দিন/সপ্তাহ */
    public static function min_days(string $course): int
    {
        return (int) (self::COURSES[$course]['min_days'] ?? 1);
    }

    /** short code (QAD) থেকে course key (qaidah) — কুপন পার্স করতে লাগে */
    public static function course_from_short(string $short): string
    {
        $short = strtoupper($short);
        foreach (self::COURSES as $key => $c) {
            if ($c['short'] === $short) {
                return $key;
            }
        }
        return '';
    }

    // ════════════════════════════════════════════════════
    // Calculation
    // ════════════════════════════════════════════════════

    /**
     * নির্দিষ্ট কোর্স ও দিন সংখ্যার জন্য per-class রেট।
     *
     * @return float  অচেনা কোর্স হলে 0.00
     */
    public static function rate_for(string $course, int $days): float
    {
        if (!self::is_valid_course($course)) {
            return 0.00;
        }

        foreach (self::COURSES[$course]['tiers'] as $tier) {
            if ($days <= (int) $tier['up_to']) {
                return (float) $tier['rate'];
            }
        }

        // দিন সংখ্যা সব টায়ারের বাইরে — শেষ টায়ারের রেট
        $tiers = self::COURSES[$course]['tiers'];
        return (float) end($tiers)['rate'];
    }

    /**
     * মাসিক ফি হিসাব করে।
     *
     * @return array{
     *   valid: bool,
     *   course: string,
     *   course_label: string,
     *   days_per_week: int,
     *   classes_per_month: int,
     *   per_class_rate: float,
     *   monthly_amount: float,
     *   error: string
     * }
     */
    public static function calculate(string $course, int $days): array
    {
        $result = [
            'valid'             => false,
            'course'            => $course,
            'course_label'      => self::label($course),
            'days_per_week'     => $days,
            'classes_per_month' => 0,
            'per_class_rate'    => 0.00,
            'monthly_amount'    => 0.00,
            'error'             => '',
        ];

        if (!self::is_valid_course($course)) {
            $result['error'] = 'Please select a valid course.';
            return $result;
        }

        $min = self::min_days($course);

        if ($days < $min) {
            $result['error'] = sprintf(
                '%s requires a minimum of %d %s per week.',
                self::label($course),
                $min,
                $min === 1 ? 'class' : 'classes'
            );
            return $result;
        }

        if ($days > self::MAX_DAYS_PER_WEEK) {
            $result['error'] = 'Please select no more than ' . self::MAX_DAYS_PER_WEEK . ' days per week.';
            return $result;
        }

        $rate    = self::rate_for($course, $days);
        $classes = $days * self::WEEKS_PER_MONTH;

        $result['valid']             = true;
        $result['classes_per_month'] = $classes;
        $result['per_class_rate']    = $rate;
        $result['monthly_amount']    = round($classes * $rate, 2);

        return $result;
    }

    // ════════════════════════════════════════════════════
    // 🎁 Special discount (Qa'idah only, opt-in)
    // ════════════════════════════════════════════════════

    /** এই কোর্সে আদৌ কোনো স্পেশাল ডিসকাউন্ট আছে কি না */
    public static function course_has_special(string $course): bool
    {
        return !empty(self::SPECIAL_DISCOUNT[$course]);
    }

    /** এই কোর্স + দিনের জন্য স্পেশাল ডিসকাউন্ট প্রযোজ্য কি না */
    public static function has_special_discount(string $course, int $days): bool
    {
        return isset(self::SPECIAL_DISCOUNT[$course][$days]);
    }

    /** ছাড়ের পরের মাসিক ফি। প্রযোজ্য না হলে 0.00 */
    public static function special_price(string $course, int $days): float
    {
        return (float) (self::SPECIAL_DISCOUNT[$course][$days] ?? 0.00);
    }

    /**
     * স্পেশাল ডিসকাউন্টসহ চূড়ান্ত হিসাব — সার্ভারে এটাই একমাত্র বিশ্বাসযোগ্য উৎস।
     *
     * @return array{
     *   valid: bool,
     *   original_amount: float,
     *   discount_amount: float,
     *   final_amount: float,
     *   error: string
     * }
     */
    public static function calculate_special(string $course, int $days): array
    {
        $out = [
            'valid'           => false,
            'original_amount' => 0.00,
            'discount_amount' => 0.00,
            'final_amount'    => 0.00,
            'error'           => '',
        ];

        $calc = self::calculate($course, $days);

        if (!$calc['valid']) {
            $out['error'] = $calc['error'] ?: 'Please review your course and class days.';
            return $out;
        }

        if (!self::has_special_discount($course, $days)) {
            $out['error'] = 'The special discount is not available for this course or class schedule.';
            return $out;
        }

        $original = (float) $calc['monthly_amount'];
        $final    = self::special_price($course, $days);

        // 🔒 ছাড় কখনোই দাম বাড়াতে পারবে না
        if ($final <= 0 || $final >= $original) {
            $out['error'] = 'The special discount is not available for this course or class schedule.';
            return $out;
        }

        $out['valid']           = true;
        $out['original_amount'] = $original;
        $out['final_amount']    = round($final, 2);
        $out['discount_amount'] = round($original - $final, 2);

        return $out;
    }

    // ════════════════════════════════════════════════════
    // JS bridge
    // ════════════════════════════════════════════════════

    /**
     * wp_localize_script() দিয়ে ফ্রন্টএন্ডে পাঠানোর কনফিগ।
     * JS শুধু *দেখানোর* জন্য এটা ব্যবহার করবে — চূড়ান্ত হিসাব সবসময় সার্ভারে।
     */
    public static function js_config(): array
    {
        $courses = [];

        foreach (self::COURSES as $key => $c) {
            // প্রতিটি সম্ভাব্য দিনের জন্য আগে থেকেই হিসাব করে পাঠাই,
            // যাতে JS-এ টায়ার লজিক ডুপ্লিকেট করতে না হয়।
            $by_days = [];
            for ($d = $c['min_days']; $d <= self::MAX_DAYS_PER_WEEK; $d++) {
                $calc = self::calculate($key, $d);
                $special = self::calculate_special($key, $d);

                $by_days[$d] = [
                    'rate'            => $calc['per_class_rate'],
                    'classes'         => $calc['classes_per_month'],
                    'amount'          => $calc['monthly_amount'],
                    // 🎁 স্পেশাল ডিসকাউন্ট — প্রযোজ্য না হলে 0
                    'special'         => $special['valid'] ? $special['final_amount'] : 0,
                    'specialDiscount' => $special['valid'] ? $special['discount_amount'] : 0,
                ];
            }

            $courses[$key] = [
                'label'      => $c['label'],
                'minDays'    => (int) $c['min_days'],
                'maxDays'    => self::MAX_DAYS_PER_WEEK,
                'hasSpecial' => self::course_has_special($key),
                'byDays'     => $by_days,
            ];
        }

        return [
            'weeksPerMonth' => self::WEEKS_PER_MONTH,
            'currency'      => 'USD',
            'symbol'        => '$',
            'courses'       => $courses,
        ];
    }

    /** $1,234.00 ফরম্যাটে */
    public static function format(float $amount): string
    {
        return '$' . number_format($amount, 2);
    }
}