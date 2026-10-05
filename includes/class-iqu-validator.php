<?php
if (!defined('ABSPATH'))
    exit;

/**
 * Class IQU_Validator
 *
 * Validates + sanitizes submissions for all three form types:
 *   - free            (Ilm-ul-Quran Free Enrollment)
 *   - summer_level1   (Summer Program — ages 5–10)
 *   - summer_level2   (Summer Program — ages 11–15)
 */
class IQU_Validator
{

    private array $errors = [];
    private array $clean = [];

    // Whitelists
    private const ALLOWED_LEVELS     = ['beginner', 'intermediate', 'advanced'];
    private const ALLOWED_DAYS       = ['saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
    private const ALLOWED_DURATIONS  = ['30', '45', '60', 'other'];
    private const ALLOWED_LANGUAGES  = ['english', 'arabic', 'urdu', 'bengali', 'other'];
    private const ALLOWED_TEACHER    = ['male', 'female', 'no_preference'];
    private const ALLOWED_DEVICES    = ['smartphone', 'tablet', 'laptop/desktop'];
    private const ALLOWED_REFERRALS  = [
        'facebook',
        'whatsapp',
        'linkedin',
        'youtube',
        'website',
        'friend_family',
        'email',
    ];
    // ⚠️ পুরনো fee_pref অপশনগুলো v2.8.0-তে বাদ — এখন কোর্স + দিন থেকে
    //    দাম হিসাব হয়। Summer ফর্ম এই const ব্যবহার করে না।
    private const ALLOWED_ENROLL = ['level1', 'level2'];
    private const ALLOWED_TRIBOOL = ['yes', 'no', 'maybe'];
    private const ALLOWED_ADM_FEE = ['50', '30', 'flexible', 'complimentary'];
    private const ALLOWED_PAYMENT = ['zelle', 'zeffy', ''];

    // 🆕 Weekend Ilm Program — প্রোগ্রামটি সম্পূর্ণ ফ্রি, তাই কোনো ফি কনস্ট্যান্ট নেই
    private const ALLOWED_WEEKEND_STATUS = ['existing', 'new'];

    // ────────────────────────────────────────────────────
    // Free registration
    // ────────────────────────────────────────────────────
    public function validate_free(array $post): bool
    {
        $this->errors = [];
        $this->clean = ['form_type' => IQU_Database::FORM_FREE];

        $this->v_name($post, 'first_name', 'Participant\'s First Name');
        $this->v_name($post, 'last_name', 'Participant\'s Last Name');
        $this->v_email($post, 'email', 'Participant\'s Email');
        $this->v_age($post, 'age', 4, 100);
        $this->v_text($post, 'country_res', 'Country of Residence', 100);
        $this->v_text($post, 'country_origin', 'Country of Origin', 100);

        $level = strtolower(sanitize_text_field($post['quran_level'] ?? ''));
        if (!in_array($level, self::ALLOWED_LEVELS, true)) {
            $this->errors['quran_level'] = 'Please select your current Qur\'an reading level.';
        }
        $this->clean['quran_level'] = $level;

        $days_per_week = absint($post['days_per_week'] ?? 0);
        if ($days_per_week < 1 || $days_per_week > 7) {
            $this->errors['days_per_week'] = 'Please enter how many days a week you can join (1-7).';
        }
        $this->clean['days_per_week'] = (string) $days_per_week;

        // preferred_days (checkboxes)
        $p_days = $post['preferred_days'] ?? [];
        if (is_array($p_days)) {
            $p_days = array_map('strtolower', $p_days);
            $p_days = array_values(array_intersect($p_days, self::ALLOWED_DAYS));
        } else {
            $p_days = [];
        }
        if (empty($p_days)) {
            $this->errors['preferred_days'] = 'Please select at least one preferred day.';
        } elseif ($days_per_week >= 1 && count($p_days) > $days_per_week) {
            $this->errors['preferred_days'] = 'Please choose no more than the number of days entered above.';
        }
        $this->clean['preferred_days'] = implode(', ', $p_days);

        $this->v_text($post, 'time_slot', 'Preferred Time Slot', 100);

        $duration = strtolower(sanitize_text_field($post['session_dur'] ?? ''));
        if (!in_array($duration, self::ALLOWED_DURATIONS, true)) {
            $this->errors['session_dur'] = 'Please select a session duration.';
        }
        $this->clean['session_dur'] = $duration;

        $langs = $post['languages'] ?? [];
        if (is_array($langs)) {
            $langs = array_map('strtolower', $langs);
            $langs = array_values(array_intersect($langs, self::ALLOWED_LANGUAGES));
        } else {
            $langs = [];
        }
        if (empty($langs)) {
            $this->errors['languages'] = 'Please select at least one language.';
        }
        $this->clean['languages'] = implode(', ', $langs);

        $this->v_whatsapp($post, 'whatsapp');

        $this->clean['memorized'] = sanitize_textarea_field($post['memorized'] ?? '');

        $teacher = strtolower(sanitize_text_field($post['teacher_pref'] ?? ''));
        if (!in_array($teacher, self::ALLOWED_TEACHER, true)) {
            $this->errors['teacher_pref'] = 'Please select your teacher preference.';
        }
        $this->clean['teacher_pref'] = $teacher;

        $ref = strtolower(sanitize_text_field($post['referral'] ?? ''));
        if (!in_array($ref, self::ALLOWED_REFERRALS, true)) {
            $this->errors['referral'] = 'Please select how you heard about us.';
        }
        $this->clean['referral'] = $ref;

        // Referral "other" detail
        $this->clean['referral_other'] = '';
        if ($ref === 'other') {
            $ref_other = sanitize_text_field($post['referral_other'] ?? '');
            if (empty($ref_other)) {
                $this->errors['referral_other'] = 'Please specify how you heard about us.';
            }
            $this->clean['referral_other'] = $ref_other;
        }

        // ── 💰 কোর্স ও প্রাইসিং ───────────────────────────
        $course = strtolower(sanitize_text_field($post['course_type'] ?? ''));
        if (!IQU_Pricing::is_valid_course($course)) {
            $this->errors['course_type'] = 'Please select the course you would like to enroll in.';
            $course = '';
        }
        $this->clean['course_type'] = $course;

        // কোর্সভেদে সর্বনিম্ন দিন — হেফজে ৩ দিন বাধ্যতামূলক
        if ($course !== '' && $days_per_week > 0) {
            $min_days = IQU_Pricing::min_days($course);
            if ($days_per_week < $min_days) {
                $this->errors['days_per_week'] = sprintf(
                    '%s requires at least %d classes per week.',
                    IQU_Pricing::label($course),
                    $min_days
                );
            }
        }

        // ── 🎟️ কুপন ও যাকাত স্বীকৃতি ─────────────────────
        // এখানে শুধু ফরম্যাট যাচাই। কুপনের বৈধতা ও ছাড়ের অঙ্ক
        // IQU_Form::handle_ajax_submit()-এ IQU_Coupon::validate() দিয়ে
        // হিসাব হয়, কারণ সেখানে চূড়ান্ত দামও জানা থাকে।
        $coupon = strtoupper(trim(sanitize_text_field($post['coupon_code'] ?? '')));

        if ($coupon !== '' && !preg_match('/^[A-Z0-9\-]{4,40}$/', $coupon)) {
            $this->errors['coupon_code'] = 'That coupon code format is not valid.';
            $coupon = '';
        }

        // handle_ajax_submit() এটি পড়ে, তারপর unset করে —
        // এটি ডেটাবেজ কলাম নয়।
        $this->clean['coupon_code_input'] = $coupon;

        $this->clean['zakat_declaration'] = !empty($post['zakat_declaration']) ? 1 : 0;


        $this->clean['special_discount'] = !empty($post['special_discount']) ? 1 : 0;

        // দুটো ছাড় একসাথে নয়
        if ($this->clean['special_discount'] && $coupon !== '') {
            $this->errors['coupon_code'] =
                'The special discount cannot be combined with a scholarship coupon.';
        }

        if ($coupon !== '' && !$this->clean['zakat_declaration']) {
            $this->errors['zakat_declaration'] =
                'Please confirm the Zakat eligibility declaration to use a coupon code.';
        }

        // পুরনো ফিল্ডগুলো আর ফর্মে নেই — খালি রেখে দাও যাতে
        // ডেটাবেজ ইনসার্ট ও পুরনো রিপোর্ট অক্ষত থাকে।
        $this->clean['fee_pref']            = '';
        $this->clean['free_request_reason'] = '';
        $this->clean['device']              = '';
        $this->clean['whatsapp_group']      = '';

        $this->add_meta();

        return empty($this->errors);
    }

    // ────────────────────────────────────────────────────
    // Summer Program (both levels)
    // ────────────────────────────────────────────────────
    public function validate_summer(array $post): bool
    {
        $this->errors = [];
        $this->clean = [];

        // Enrollment level → determines form_type (level1 / level2)
        $enroll = strtolower(sanitize_text_field($post['enrollment_level'] ?? ''));
        if (!in_array($enroll, self::ALLOWED_ENROLL, true)) {
            $this->errors['enrollment_level'] = 'Please select the appropriate enrollment level.';
            $enroll = '';
        }
        $this->clean['enrollment_level'] = $enroll;
        $this->clean['form_type'] = ($enroll === 'level2')
            ? IQU_Database::FORM_SUMMER_LEVEL2
            : IQU_Database::FORM_SUMMER_LEVEL1;

        $this->v_email($post, 'email', 'Email');
        $this->v_name($post, 'first_name', 'Participant\'s First Name');
        $this->v_name($post, 'last_name', 'Participant\'s Last Name');

        // Age bounds depend on level
        if ($enroll === 'level1') {
            $this->v_age($post, 'age', 5, 10);
        } elseif ($enroll === 'level2') {
            $this->v_age($post, 'age', 11, 15);
        } else {
            $this->v_age($post, 'age', 5, 15);
        }

        $this->v_text($post, 'guardian_name', 'Guardian\'s Full Name', 150);
        $this->v_whatsapp($post, 'guardian_contact', 'Guardian\'s Contact Number', 'guardian_contact');
        $this->v_whatsapp($post, 'guardian_whatsapp', 'Guardian\'s WhatsApp Number', 'guardian_whatsapp');
        $this->v_text($post, 'country_res', 'Country of Residence', 100);
        $this->v_text($post, 'country_origin', 'Country of Origin', 100);

        $grp = strtolower(sanitize_text_field($post['whatsapp_group'] ?? ''));
        if (!in_array($grp, self::ALLOWED_TRIBOOL, true)) {
            $this->errors['whatsapp_group'] = 'Please answer the WhatsApp group question.';
        }
        $this->clean['whatsapp_group'] = $grp;

        $ref = strtolower(sanitize_text_field($post['referral'] ?? ''));
        if (!in_array($ref, self::ALLOWED_REFERRALS, true)) {
            $this->errors['referral'] = 'Please select how you heard about this program.';
        }
        $this->clean['referral'] = $ref;

        // Referral "other" detail
        $this->clean['referral_other'] = '';
        if ($ref === 'other') {
            $ref_other = sanitize_text_field($post['referral_other'] ?? '');
            if (empty($ref_other)) {
                $this->errors['referral_other'] = 'Please specify how you heard about this program.';
            }
            $this->clean['referral_other'] = $ref_other;
        }

        $fee = strtolower(sanitize_text_field($post['admission_fee'] ?? ''));
        if (!in_array($fee, self::ALLOWED_ADM_FEE, true)) {
            $this->errors['admission_fee'] = 'Please choose an admission fee option.';
        }
        $this->clean['admission_fee'] = $fee;

        // Payment method — required when a paid option is selected
        $pm = strtolower(sanitize_text_field($post['payment_method'] ?? ''));
        if (!in_array($pm, self::ALLOWED_PAYMENT, true)) {
            $pm = '';
        }
        if (in_array($fee, ['50', '30'], true) && $pm === '') {
            $this->errors['payment_method'] = 'Please choose a payment method (Zelle or Zeffy).';
        }
        $this->clean['payment_method'] = $pm;

        // Complimentary — no payment needed
        if ($fee === 'complimentary') {
            $this->clean['payment_method'] = '';
            $this->clean['payment_status'] = 'complimentary';
        }

        // Flexible / free note — only when "flexible" is chosen
        $this->clean['flexible_fee_note'] = '';
        if ($fee === 'flexible') {
            $flex_note = sanitize_text_field($post['flexible_fee_note'] ?? '');
            if (empty($flex_note)) {
                $this->errors['flexible_fee_note'] = 'Please enter an amount or write "Requesting Free Enrollment".';
            } elseif (mb_strlen($flex_note) > 300) {
                $this->errors['flexible_fee_note'] = 'Please keep your response under 300 characters.';
            } else {
                $is_dollar = preg_match('/^\$?\d+(\.\d{1,2})?$/', $flex_note);
                $is_free = strtolower($flex_note) === 'requesting free enrollment';
                if (!$is_dollar && !$is_free) {
                    $this->errors['flexible_fee_note'] = 'Please enter a valid dollar amount (e.g. $20) or write exactly "Requesting Free Enrollment".';
                }
            }
            $this->clean['flexible_fee_note'] = $flex_note;
        }

        // Payment amount from admission_fee
        $amount = 0.0;
        if ($fee === '50') {
            $amount = 50.0;
        } elseif ($fee === '30') {
            $amount = 30.0;
        } elseif ($fee === 'flexible') {
            // JS থেকে পাঠানো payment_amount নিন
            $posted_amount = floatval($post['payment_amount'] ?? 0);
            $amount = $posted_amount > 0 ? $posted_amount : 0.0;
        } elseif ($fee === 'complimentary') {
            $amount = 0.0;
        }
        $this->clean['payment_amount'] = $amount;

        // Zelle payment details
        if ($pm === 'zelle') {
            $tx = sanitize_text_field($post['transaction_id'] ?? '');
            if (empty($tx)) {
                $this->errors['transaction_id'] = 'Please enter the Zelle transaction ID.';
            } elseif (mb_strlen($tx) > 100) {
                $this->errors['transaction_id'] = 'Transaction ID is too long.';
            }
            $this->clean['transaction_id'] = $tx;
            $this->clean['payment_status'] = 'submitted';
        } elseif ($pm === 'zeffy') {
            $this->clean['transaction_id'] = '';
            $this->clean['payment_status'] = 'redirected';
        } else {
            $this->clean['transaction_id'] = '';
            if ($fee === 'complimentary') {
                $this->clean['payment_status'] = 'complimentary';
            } elseif ($fee === 'flexible') {
                $this->clean['payment_status'] = 'flexible';
            } else {
                $this->clean['payment_status'] = 'pending';
            }
        }

        $this->add_meta();

        return empty($this->errors);
    }

    // ────────────────────────────────────────────────────
    // Weekend Ilm Program
    // ────────────────────────────────────────────────────
    public function validate_weekend(array $post): bool
    {
        $this->errors = [];
        $this->clean  = [];

        // ── ছাত্রের অবস্থা → শুধু form_type নির্ধারণ করে (ফি নেই) ──
        $status = strtolower(sanitize_text_field($post['student_status'] ?? ''));
        if (!in_array($status, self::ALLOWED_WEEKEND_STATUS, true)) {
            $this->errors['student_status'] =
                'Please tell us whether your child already studies with Ilm-ul-Quran USA.';
            $status = '';
        }
        $is_new = ($status === 'new');

        $this->clean['form_type'] = $is_new
            ? IQU_Database::FORM_WEEKEND_NEW
            : IQU_Database::FORM_WEEKEND_EXISTING;

        // রিপোর্টে চেনার জন্য
        $this->clean['enrollment_level'] = 'weekend';

        // ── ব্যক্তিগত তথ্য ──
        $this->v_name($post, 'first_name', 'Participant\'s First Name');
        $this->v_name($post, 'last_name', 'Participant\'s Last Name');
        $this->v_email($post, 'email', 'Participant\'s Email');
        $this->v_age($post, 'age', 5, 15);
        $this->v_text($post, 'guardian_name', 'Guardian\'s Full Name', 150);
        $this->v_text($post, 'country_origin', 'Country of Origin', 100);
        $this->v_text($post, 'country_res', 'Country of Residence', 100);
        $this->v_whatsapp($post, 'whatsapp', 'WhatsApp Number', 'whatsapp');

        // ── রেফারেল ──
        $ref = strtolower(sanitize_text_field($post['referral'] ?? ''));
        if (!in_array($ref, self::ALLOWED_REFERRALS, true)) {
            $this->errors['referral'] = 'Please select how you heard about Ilm-ul-Quran USA.';
        }
        $this->clean['referral']       = $ref;
        $this->clean['referral_other'] = '';

        // ── 🎁 সম্পূর্ণ ফ্রি — নতুন হোক বা পুরনো, কোনো ফি নেই ──
        //    ক্লায়েন্ট থেকে payment_* কিছু এলেও তা উপেক্ষা করা হয়।
        $this->clean['admission_fee']     = 'free';
        $this->clean['payment_amount']    = 0.00;
        $this->clean['payment_method']    = '';
        $this->clean['transaction_id']    = '';
        $this->clean['payment_status']    = 'not_required';
        $this->clean['flexible_fee_note'] = '';

        $this->add_meta();

        return empty($this->errors);
    }

    // ────────────────────────────────────────────────────
    // Getters
    // ────────────────────────────────────────────────────
    public function get_clean(): array
    {
        return $this->clean;
    }
    public function get_errors(): array
    {
        return $this->errors;
    }

    // ────────────────────────────────────────────────────
    // Field helpers
    // ────────────────────────────────────────────────────
    private function v_name(array $post, string $key, string $label): void
    {
        $val = $this->sanitize_name($post[$key] ?? '');
        if (empty($val)) {
            $this->errors[$key] = $label . ' is required.';
        } elseif (mb_strlen($val) > 100) {
            $this->errors[$key] = $label . ' is too long.';
        }
        $this->clean[$key] = $val;
    }

    private function v_email(array $post, string $key, string $label): void
    {
        $raw = trim($post[$key] ?? '');
        $val = sanitize_email($raw);
        if (empty($val) || !is_email($val)) {
            $this->errors[$key] = 'A valid ' . strtolower($label) . ' is required.';
        } elseif (mb_strlen($val) > 191) {
            $this->errors[$key] = $label . ' is too long.';
        }
        $this->clean[$key] = $val;
    }

    private function v_age(array $post, string $key, int $min, int $max): void
    {
        $age = absint($post[$key] ?? 0);
        if ($age < $min || $age > $max) {
            $this->errors[$key] = "Please enter a valid age ({$min}–{$max}).";
        }
        $this->clean[$key] = $age;
    }

    private function v_text(array $post, string $key, string $label, int $max): void
    {
        $val = sanitize_text_field($post[$key] ?? '');
        if (empty($val)) {
            $this->errors[$key] = $label . ' is required.';
        } elseif (mb_strlen($val) > $max) {
            $this->errors[$key] = $label . ' is too long.';
        }
        $this->clean[$key] = $val;
    }

    private function v_whatsapp(array $post, string $key, string $label = 'WhatsApp number', string $error_key = null): void
    {
        $error_key = $error_key ?? $key;
        $wa = sanitize_text_field($post[$key] ?? '');
        $wa = preg_replace('/[^\d\+\-\s]/', '', $wa);
        $wa = trim($wa);

        if (empty($wa)) {
            $this->errors[$error_key] = $label . ' is required.';
            $this->clean[$key] = '';
            return;
        }


        if (!str_starts_with($wa, '+')) {
            // শুধু digits নিন
            $digits = preg_replace('/\D/', '', $wa);
            if (!empty($digits)) {
                $wa = '+' . $digits;
            }
        }

        if (str_starts_with($wa, '+0')) {
            $this->errors[$error_key] = 'Please enter a valid ' . strtolower($label) . ' with country code (e.g. +880XXXXXXXXX).';
            $this->clean[$key] = $wa;
            return;
        }

        // digits count check (+ বাদে ৭ থেকে ১৫ digit)
        $digits_only = preg_replace('/\D/', '', $wa);
        if (strlen($digits_only) < 7 || strlen($digits_only) > 15) {
            $this->errors[$error_key] = 'Please enter a valid ' . strtolower($label) . ' with country code (e.g. +1XXXXXXXXXX).';
        }

        $this->clean[$key] = $wa;
    }

    private function add_meta(): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $this->clean['ip_address'] = filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
        $this->clean['user_agent'] = substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    private function sanitize_name(string $name): string
    {
        $name = sanitize_text_field($name);
        $name = wp_strip_all_tags($name);
        $name = preg_replace('/[^\p{L}\s\-\'\.]/u', '', $name);
        return trim($name);
    }
}