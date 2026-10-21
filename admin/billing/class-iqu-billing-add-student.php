<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Billing_Add_Student
 *
 * Admin page: IQU Registrations → Add Existing Student.
 * For students who joined before the website enrollment form existed.
 * Creates a normal row in the registrations table so every student lives
 * in one place, then billing works the same way for everyone.
 *
 * Security:
 * - manage_options only; nonce on submit.
 * - Every field is sanitised and validated on the server.
 * - The fee is computed by IQU_Pricing. An agreed fee is billed as agreed
 *   (a lower one is stored as a discount; a higher one from a CSV row gets a
 *   warning), and ticking "agreed fee" without an amount is an error, never $0.
 * - Exact duplicates (same student name + email) are refused.
 * - Writes through IQU_Database::insert_registration() with a fixed set of
 *   columns. No notification emails or Telegram messages are triggered.
 */
class IQU_Billing_Add_Student
{
    public const PAGE_SLUG = 'iqu-billing-add-student';
    /** @deprecated Older versions stored this in `referral`; rows now record `created_via`. */
    public const MARKER    = IQU_Database::LEGACY_ADMIN_REFERRAL;
    private const CAP      = 'manage_options';
    private const ACTION   = 'iqu_billing_add_student';
    private const FORM_TX  = 'iqu_billing_add_form_';

    public static function init(): void
    {
        add_action('admin_menu', [__CLASS__, 'register_menu'], 21);
        add_action('admin_post_' . self::ACTION, [__CLASS__, 'handle_submit']);
    }

    public static function register_menu(): void
    {
        add_submenu_page(
            'iqu-registrations',
            'Add Existing Student — IQU',
            'Add Existing Student',
            self::CAP,
            self::PAGE_SLUG,
            [__CLASS__, 'render']
        );
    }

    // ------------------------------------------------------------
    // Submit
    // ------------------------------------------------------------

    public static function handle_submit(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die('You do not have permission to do this.', 403);
        }
        check_admin_referer(self::ACTION);

        $in = [
            'first_name'    => sanitize_text_field(wp_unslash($_POST['first_name'] ?? '')),
            'last_name'     => sanitize_text_field(wp_unslash($_POST['last_name'] ?? '')),
            'guardian_name' => sanitize_text_field(wp_unslash($_POST['guardian_name'] ?? '')),
            'email'         => sanitize_email(wp_unslash($_POST['email'] ?? '')),
            'whatsapp'      => preg_replace('/[^0-9+\-\s()]/', '', is_string($_POST['whatsapp'] ?? null) ? wp_unslash($_POST['whatsapp']) : ''),
            'course'        => sanitize_key(wp_unslash($_POST['course'] ?? '')),
            'days'          => absint($_POST['days'] ?? 0),
            'use_agreed'    => !empty($_POST['use_agreed']),
            'agreed_fee'    => isset($_POST['agreed_fee']) && is_string($_POST['agreed_fee']) ? (float) $_POST['agreed_fee'] : 0.0,
            'agreed_fee_invalid' => isset($_POST['agreed_fee']) && is_string($_POST['agreed_fee']) && trim($_POST['agreed_fee']) !== '' && !preg_match('/^-?\d+(\.\d{1,2})?$/', trim($_POST['agreed_fee'])),
            'agreed_fee_missing' => !isset($_POST['agreed_fee']) || !is_string($_POST['agreed_fee']) || trim($_POST['agreed_fee']) === '',
            'zakat'         => !empty($_POST['zakat']),
            'note'          => sanitize_textarea_field(wp_unslash($_POST['note'] ?? '')),
            'extra'         => self::extra_from_post(wp_unslash($_POST)),
        ];
        $in['whatsapp'] = trim(substr($in['whatsapp'], 0, 25));

        $prep = self::prepare($in);
        if ($prep['errors']) {
            self::flash(['errors' => $prep['errors'], 'warnings' => $prep['warnings'], 'old' => $in]);
            self::back();
        }
        // Warnings (age under 4, an "Other" answer) can be saved, after the admin confirms them.
        if ($prep['warnings'] && empty($_POST['confirm_warnings'])) {
            self::flash(['warnings' => $prep['warnings'], 'confirm' => true, 'old' => $in]);
            self::back();
        }

        // The duplicate check in prepare() is check-then-insert: a double click must not add the student twice.
        $lock = 'iqu_add_lock_' . md5(strtolower($in['first_name'] . '|' . $in['last_name'] . '|' . $in['email']));
        if (!add_option($lock, time(), '', 'no')) {
            if ((int) get_option($lock) > time() - 60) {
                self::flash(['errors' => ['This student is being saved already. Check Student Billing in a moment.'], 'old' => $in]);
                self::back();
            }
            update_option($lock, time(), 'no'); // left behind by a request that died
        }
        $id = self::is_duplicate($in['first_name'], $in['last_name'], $in['email']) ? 0 : IQU_Database::insert_registration($prep['row']);
        delete_option($lock);
        if (!$id) {
            self::flash(['errors' => [self::is_duplicate($in['first_name'], $in['last_name'], $in['email']) ? 'Already in the enrollment records (same name and email).' : 'Could not save the student. Nothing was added.'], 'old' => $in]);
            self::back();
        }

        self::flash(['saved' => [
            'id'   => (int) $id,
            'name' => $in['first_name'] . ' ' . $in['last_name'],
            'net'  => $prep['net'],
        ]]);
        self::back();
    }

    /**
     * Optional enrollment columns that may be filled from the form or a CSV, with their length
     * limits (the public Enroll for Free form's limits). The payment amount is not one of them:
     * pricing stays in the course / days / agreed fee / Zakat fields.
     */
    private const EXTRA_FIELDS = [
        'age'            => 3,
        'country_origin' => 100,
        'country_res'    => 100,
        'quran_level'    => 20,
        'memorized'      => 500,
        'languages'      => 200,
        'preferred_days' => 200,
        'time_slot'      => 100,
        'session_dur'    => 20,
        'teacher_pref'   => 20,
        'referral'       => 30,
    ];

    /** The optional fields as posted by the form (arrays for the checkbox groups). */
    private static function extra_from_post(array $p): array
    {
        $list = fn($k) => array_map('sanitize_text_field', array_map('strval', (array) ($p[$k] ?? [])));
        return [
            'age'            => sanitize_text_field((string) ($p['age'] ?? '')),
            'country_origin' => (string) ($p['country_origin'] ?? ''),
            'country_res'    => (string) ($p['country_res'] ?? ''),
            'quran_level'    => sanitize_text_field((string) ($p['quran_level'] ?? '')),
            'memorized'      => sanitize_textarea_field((string) ($p['memorized'] ?? '')),
            'languages'      => $list('languages'),
            'preferred_days' => $list('preferred_days'),
            'time_start'     => sanitize_text_field((string) ($p['time_start'] ?? '')),
            'time_end'       => sanitize_text_field((string) ($p['time_end'] ?? '')),
            'time_zone'      => sanitize_text_field((string) ($p['time_zone'] ?? '')),
            'session_dur'    => sanitize_text_field((string) ($p['session_dur'] ?? '')),
            'teacher_pref'   => sanitize_text_field((string) ($p['teacher_pref'] ?? '')),
            'referral'       => sanitize_text_field((string) ($p['referral'] ?? '')),
            'referral_other' => sanitize_text_field((string) ($p['referral_other'] ?? '')),
        ];
    }

    /**
     * Clean and check the optional fields against the public form's own option lists
     * (IQU_Reg_Fields) and limits, and return them in the same stored format as a website
     * enrollment ("saturday, monday", "english, arabic", "30", "05:00 PM – 06:00 PM (CST)").
     * CSV cells may use common spellings: "Mon", "30 min", "Bangla", "any", "Friend/Family".
     *
     * Three outcomes per value: fine, a warning (saved, but the admin should look: age under 4,
     * an "Other" answer), or an error (not saved).
     *
     * @return array{0:array<string,mixed>,1:string[],2:string[]} [columns, errors, warnings]
     */
    public static function clean_extra(array $x, int $days): array
    {
        $cols = [];
        $e    = [];
        $w    = [];
        $list = function ($v): array {
            $items = is_array($v) ? $v : preg_split('/[,;\/|]+/', (string) $v);
            return array_values(array_filter(array_map(fn($s) => strtolower(trim((string) $s)), $items), fn($s) => $s !== ''));
        };

        $age = trim((string) ($x['age'] ?? ''));
        if ($age !== '' && $age !== '0') {
            if (!preg_match('/^\d{1,3}$/', $age) || (int) $age < 1 || (int) $age > 100) {
                $e[] = 'Age must be a number from 1 to 100.';
            } else {
                $cols['age'] = (int) $age;
                if ((int) $age < 4) $w[] = 'Age under 4 — please confirm.';
            }
        }
        foreach (['country_origin', 'country_res'] as $k) {
            $v = self::clean_text((string) ($x[$k] ?? ''));
            if ($v !== '') $cols[$k] = mb_substr($v, 0, self::EXTRA_FIELDS[$k]); // characters, not bytes (Arabic, Bengali)
        }
        $lvl = strtolower(trim((string) ($x['quran_level'] ?? '')));
        if ($lvl !== '') {
            if (!isset(IQU_Reg_Fields::QURAN_LEVELS[$lvl])) $e[] = "Qur'an level must be Beginner, Intermediate or Advanced.";
            else $cols['quran_level'] = $lvl;
        }
        $mem = trim(sanitize_textarea_field((string) ($x['memorized'] ?? '')));
        if ($mem !== '') $cols['memorized'] = mb_substr(ltrim($mem, "=+@\t\r"), 0, self::EXTRA_FIELDS['memorized']);

        $langs = $list($x['languages'] ?? []);
        if ($langs) {
            $langs = array_map(fn($l) => $l === 'bangla' ? 'bengali' : $l, $langs);
            $bad = array_diff($langs, array_keys(IQU_Reg_Fields::LANGUAGES));
            if ($bad) $e[] = 'Languages must be English, Arabic, Urdu or Bengali.';
            else $cols['languages'] = implode(', ', array_values(array_unique($langs)));
        }

        $want = $list($x['preferred_days'] ?? []);
        if ($want) {
            $by3 = [];
            foreach (IQU_Reg_Fields::WEEK_DAYS as $d) $by3[strtolower(substr($d, 0, 3))] = strtolower($d);
            $clean = [];
            $bad = false;
            foreach ($want as $d) {
                $k = substr($d, 0, 3);
                if (isset($by3[$k]) && (strlen($d) === 3 || $d === $by3[$k])) $clean[] = $by3[$k]; else $bad = true;
            }
            $clean = array_values(array_unique($clean));
            if ($bad) $e[] = 'Preferred days must be days of the week (for example Mon, Wed).';
            elseif ($days >= 1 && count($clean) > $days) $e[] = 'Choose no more preferred days than classes a week.';
            else $cols['preferred_days'] = implode(', ', $clean);
        }

        $start = trim((string) ($x['time_start'] ?? ''));
        $end   = trim((string) ($x['time_end'] ?? ''));
        $tz    = strtoupper(trim((string) ($x['time_zone'] ?? '')));
        if ($start !== '' || $end !== '' || $tz !== '') {
            $t = fn($s) => preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $s) ? DateTimeImmutable::createFromFormat('H:i', $s) : false;
            if (!$t($start) || !$t($end)) $e[] = 'Preferred time: enter a start and an end time.';
            elseif (!isset(IQU_Reg_Fields::TIME_ZONES[$tz])) $e[] = 'Preferred time: choose a time zone.';
            else $cols['time_slot'] = $t($start)->format('h:i A') . ' – ' . $t($end)->format('h:i A') . ' (' . $tz . ')';
        } elseif (($slot = self::clean_text((string) ($x['time_slot'] ?? ''))) !== '') {
            $cols['time_slot'] = mb_substr($slot, 0, self::EXTRA_FIELDS['time_slot']);
        }

        $dur = strtolower(trim((string) ($x['session_dur'] ?? '')));
        if ($dur !== '') {
            $num = preg_replace('/\D/', '', $dur);
            if (!isset(IQU_Reg_Fields::SESSION_DURATIONS[$num])) $e[] = 'Session duration must be 30, 45 or 60 minutes.';
            else $cols['session_dur'] = $num;
        }

        $tp = strtolower(trim(preg_replace('/[\s\-]+/', '_', (string) ($x['teacher_pref'] ?? '')), '_'));
        if ($tp !== '') {
            if (in_array($tp, ['any', 'either', 'none', 'no_pref'], true)) $tp = 'no_preference';
            if (!isset(IQU_Reg_Fields::TEACHER_PREFS[$tp])) $e[] = 'Teacher preference must be Male, Female or No preference.';
            else $cols['teacher_pref'] = $tp;
        }

        $ref = strtolower(trim((string) ($x['referral'] ?? '')));
        // "Other", or "Other: a cousin" / "Other - a cousin" / "Other (a cousin)" in a CSV cell. Any
        // text given is kept, as on the website form; the admin is asked to check it.
        if (preg_match('/^\s*other\b\s*[:\-–(]?\s*(.*?)\)?\s*$/iu', (string) ($x['referral'] ?? ''), $om)) {
            $other = self::clean_text(trim(($om[1] ?? '') !== '' ? $om[1] : (string) ($x['referral_other'] ?? '')));
            $cols['referral']       = 'other';
            $cols['referral_other'] = mb_substr($other, 0, 200);
            $w[] = "'Other' answer — check and update manually.";
            $ref = '';
        }
        if ($ref !== '') {
            $key = '';
            foreach (IQU_Reg_Fields::REFERRALS as $k => $label) {
                if ($ref === $k || $ref === strtolower($label) || preg_replace('/[^a-z]/', '', $ref) === preg_replace('/[^a-z]/', '', $k)) $key = $k;
            }
            if ($key === '') $e[] = 'How they heard about us must be one of: ' . implode(', ', IQU_Reg_Fields::REFERRALS) . ' or Other.';
            else $cols['referral'] = $key;
        }
        return [$cols, $e, $w];
    }

    /**
     * Validate one student and build the registrations row.
     * Shared by the single form and the CSV import so both follow the same rules.
     *
     * @param array $in first_name, last_name, guardian_name, email, whatsapp, course,
     *                  days, use_agreed, agreed_fee, zakat, note, extra[]
     * @return array{errors:string[], warnings:string[], row:array, gross:float, net:float, course_missing?:bool}
     */
    public static function prepare(array $in): array
    {
        $warnings = [];
        // CSV only (course_raw = the cell as typed): an empty course, or one that matches no
        // course on the website, is never a reason to reject the row. It is saved blank with a
        // warning, and billing waits until a course is set (IQU_Billing_Pricing::course_missing).
        // The form's course is a menu and stays required there.
        $from_csv       = array_key_exists('course_raw', $in);
        $raw            = trim((string) ($in['course_raw'] ?? ''));
        $course_missing = $from_csv && !IQU_Pricing::is_valid_course((string) $in['course']);
        if ($course_missing) {
            $allowed = implode(', ', array_map(fn($k) => IQU_Pricing::label($k) . " ({$k})", IQU_Pricing::course_keys()));
            $warnings[]   = $raw === ''
                ? 'Course empty — left blank. Set it before billing starts.'
                : "Course '{$raw}' not found — left blank. Allowed: {$allowed}.";
            $in['course'] = '';
        }

        $errors = self::validate($in, $course_missing);
        if (!empty($in['agreed_fee_invalid'])) {
            $errors[] = 'The agreed fee must be one amount, for example 30 or 30.50.';
            $in['use_agreed'] = false;
        } elseif (!empty($in['use_agreed']) && !empty($in['agreed_fee_missing'])) {
            // Ticked but left empty: never read as $0 (a full scholarship, never billed).
            $errors[] = 'Enter the agreed monthly fee (0 for a full scholarship), or untick the agreed fee.';
            $in['use_agreed'] = false;
        } elseif (!empty($in['use_agreed']) && (!is_finite((float) $in['agreed_fee']) || (float) $in['agreed_fee'] > IQU_Billing_Pricing::AGREED_MAX)) {
            $errors[] = 'The agreed fee must be at most ' . IQU_Pricing::format(IQU_Billing_Pricing::AGREED_MAX) . ' a month — check the amount.';
            $in['use_agreed'] = false;
        }
        [$extra_cols, $extra_errors, $extra_warnings] = self::clean_extra(is_array($in['extra'] ?? null) ? $in['extra'] : [], (int) ($in['days'] ?? 0));
        $errors   = array_merge($errors, $extra_errors);
        $warnings = array_merge($warnings, $extra_warnings);
        $calc     = null;
        if (!$errors && !$course_missing) {
            $calc = IQU_Pricing::calculate($in['course'], (int) $in['days']);
            if (empty($calc['valid'])) {
                $errors[] = $calc['error'] ?: 'Invalid course or days.';
            }
        }

        $gross    = $calc ? round((float) $calc['monthly_amount'], 2) : 0.0;
        $discount = 0.0;
        $agreed   = null;
        if (!$errors && !empty($in['use_agreed']) && $course_missing) {
            // No course to price against yet: keep the fee exactly as given (agreed_fee); it is
            // applied, and compared with the standard price, once a course is set.
            $agreed = round((float) $in['agreed_fee'], 2);
            if ($agreed < 0) {
                $errors[] = 'The agreed fee cannot be negative.';
            } else {
                $warnings[] = 'Agreed fee ' . IQU_Pricing::format($agreed) . ' saved — check against the standard price once the course is set.';
            }
        } elseif (!$errors && !empty($in['use_agreed'])) {
            $agreed = round((float) $in['agreed_fee'], 2);
            if ($agreed < 0) {
                $errors[] = 'The agreed fee cannot be negative.';
            } else {
                // Billed exactly as agreed, lower or higher than the standard fee (a higher one
                // is no reduction; IQU_Billing_Pricing bills agreed_fee as the monthly price).
                $discount = max(0.0, round($gross - $agreed, 2));
                if ($from_csv && $agreed > $gross) { // a CSV cell may be garbled; on the form the admin typed it
                    $warnings[] = 'Agreed fee ' . IQU_Pricing::format($agreed) . ' is higher than the standard fee ' . IQU_Pricing::format($gross) . ' — check the amount.';
                }
            }
        }

        if (!$errors && self::is_duplicate($in['first_name'], $in['last_name'], $in['email'])) {
            $errors[] = 'Already in the enrollment records (same name and email).';
        }

        if ($errors) {
            return ['errors' => $errors, 'warnings' => $warnings, 'row' => [], 'gross' => $gross, 'net' => 0.0, 'course_missing' => $course_missing];
        }

        // How the row is created: this form (admin) or the CSV import (import).
        $via  = ($in['via'] ?? '') === IQU_Database::VIA_IMPORT ? IQU_Database::VIA_IMPORT : IQU_Database::VIA_ADMIN;
        $user = wp_get_current_user();
        $note = trim(($via === IQU_Database::VIA_IMPORT ? 'Imported from CSV' : 'Added from Billing as an existing student')
            . ' by ' . $user->user_login . '. ' . ($in['note'] ?? ''));
        $now  = gmdate('Y-m-d H:i:s');

        $row = [
            'form_type'          => IQU_Database::FORM_FREE,
            'first_name'         => $in['first_name'],
            'last_name'          => $in['last_name'],
            'email'              => $in['email'],
            'whatsapp'           => $in['whatsapp'],
            'guardian_name'      => $in['guardian_name'],
            'guardian_whatsapp'  => $in['guardian_name'] !== '' ? $in['whatsapp'] : '',
            'days_per_week'      => (string) (int) $in['days'],
            // Only what the family answered ('' when not given). Whether the row came from an
            // admin or an import is `created_via`, never the referral.
            'referral'           => $extra_cols['referral'] ?? '',
            'course_type'        => $in['course'],
            'per_class_rate'     => $calc ? (float) $calc['per_class_rate'] : 0.0, // 0 while the course is missing
            'calculated_amount'  => $gross,
            'discount_amount'    => $discount,
            'zakat_declaration'  => !empty($in['zakat']) ? 1 : 0,
            'special_discount'   => 0,
            'flexible_fee_note'  => !empty($in['use_agreed']) ? substr('Agreed monthly fee ' . IQU_Pricing::format($agreed ?? ($gross - $discount)), 0, 300) : '',
            'status'             => 'enrolled',
            'admin_note'         => $note,
            'ip_address'         => '',
            'user_agent'         => $via === IQU_Database::VIA_IMPORT ? 'IQU Billing (imported from CSV)' : 'IQU Billing (added by admin)',
            'created_at'         => $now,
            'updated_at'         => $now,
        ];
        // Written once the column is migrated; until then IQU_Database::created_via() reads the
        // "IQU Billing (…)" user agent above, so the rules are the same either way.
        if (IQU_Database::created_via_ready()) $row['created_via'] = $via;
        // The agreed monthly fee as given (NULL = none agreed); see IQU_Billing_Pricing.
        if ($agreed !== null && IQU_Database::student_fee_columns_ready()) $row['agreed_fee'] = $agreed;

        foreach ($extra_cols as $col => $value) {
            if ($col !== 'referral') $row[$col] = $value;
        }

        return ['errors' => [], 'warnings' => $warnings, 'row' => $row, 'gross' => $gross, 'net' => $agreed !== null && !$course_missing ? $agreed : round($gross - $discount, 2), 'course_missing' => $course_missing];
    }

    /**
     * Plain text from a form or spreadsheet cell. Removes the apostrophe our
     * own CSV export adds, and any leading formula characters.
     */
    public static function clean_text(string $value): string
    {
        $value = sanitize_text_field($value);
        if (strlen($value) > 1 && $value[0] === "'" && in_array($value[1], ['=', '+', '-', '@'], true)) {
            $value = substr($value, 1);
        }
        return ltrim($value, "=+@\t\r");
    }

    /** @param bool $course_missing the course was given but not found (CSV): a warning, not an error */
    private static function validate(array $in, bool $course_missing = false): array
    {
        $e = [];
        if ($in['first_name'] === '' || $in['last_name'] === '') $e[] = 'Enter the student\'s first and last name.';
        if (!is_email($in['email'])) $e[] = 'Enter a valid email address. Payment links and receipts go here.';
        if (strlen(preg_replace('/\D/', '', $in['whatsapp'])) < 7) $e[] = 'Enter a WhatsApp number with country code.';
        if (!$course_missing && !IQU_Pricing::is_valid_course($in['course'])) $e[] = 'Choose a course.';
        if ($in['days'] < 1 || $in['days'] > 7) $e[] = 'Choose classes per week (1–7).';
        return $e;
    }

    /**
     * Which form field a validation message belongs to (presentation only: used to show
     * each server message next to its field, here and in the Import preview). '' = general.
     */
    public static function error_field(string $msg): string
    {
        $map = [
            'first and last name'       => 'first_name',
            'valid email address'       => 'email',
            'WhatsApp number'           => 'whatsapp',
            'Choose a course'           => 'course',
            'classes per week'          => 'days',
            'minimum of'                => 'days',
            'no more than 7 days'       => 'days',
            'valid course'              => 'course',
            'agreed fee'                => 'agreed_fee',
            'enrollment records'        => 'first_name',
            'appears twice'             => 'first_name',
            'Age must be'               => 'age',
            "Qur'an level"              => 'quran_level',
            'Languages must be'         => 'languages',
            'preferred days'            => 'preferred_days',
            'Preferred days'            => 'preferred_days',
            'Preferred time'            => 'time_slot',
            'Session duration'          => 'session_dur',
            'Teacher preference'        => 'teacher_pref',
            'heard about us'            => 'referral',
        ];
        foreach ($map as $needle => $field) {
            if (stripos($msg, $needle) !== false) return $field;
        }
        return '';
    }

    private static function is_duplicate(string $first, string $last, string $email): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . IQU_TABLE_NAME;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE LOWER(first_name) = LOWER(%s) AND LOWER(last_name) = LOWER(%s) AND LOWER(email) = LOWER(%s) AND form_type = %s",
            $first, $last, $email, IQU_Database::FORM_FREE
        ));
    }

    private static function flash(array $data): void
    {
        set_transient(self::FORM_TX . get_current_user_id(), $data, 120);
    }

    private static function back(): void
    {
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG));
        exit;
    }

    // ------------------------------------------------------------
    // Page
    // ------------------------------------------------------------

    public static function render(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die('You do not have permission to view this page.', 403);
        }

        $flash = get_transient(self::FORM_TX . get_current_user_id()) ?: [];
        if ($flash) delete_transient(self::FORM_TX . get_current_user_id());
        $old = $flash['old'] ?? [];
        $v   = fn($k, $d = '') => esc_attr((string) ($old[$k] ?? $d));

        // Server messages, also shown next to their field (the list at the top stays).
        $field_errors = [];
        foreach ((array) ($flash['errors'] ?? []) as $msg) {
            $f = self::error_field((string) $msg);
            if ($f !== '') $field_errors[$f][] = (string) $msg;
        }
        $err = function (string $field) use ($field_errors): string {
            $msgs = $field_errors[$field] ?? [];
            return '<p class="iqu-fld-error" id="iqu-err-' . esc_attr($field) . '"' . ($msgs ? '' : ' hidden') . '>'
                . '<span class="dashicons dashicons-warning" aria-hidden="true"></span><span class="iqu-fld-error-text">' . esc_html(implode(' ', $msgs)) . '</span></p>';
        };
        $invalid = fn(string $field) => isset($field_errors[$field]) ? ' aria-invalid="true"' : '';

        // Pricing rules for the chips and the summary (the server prices again on save).
        $pricing = IQU_Pricing::js_config();

        // Billing emails that already have a family account in this mode (informational notice only).
        global $wpdb;
        $families = [];
        $accounts = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . IQU_Billing_DB::accounts_table() . ' WHERE mode = %s AND status <> %s ORDER BY id',
            IQU_Stripe::expected_mode(), 'canceled'
        ), ARRAY_A) ?: [];
        foreach ($accounts as $acc) {
            $email = strtolower(trim((string) $acc['contact_email']));
            if ($email === '' || isset($families[$email])) continue;
            $families[$email] = ['label' => IQU_Billing_Page::family_label($acc), 'url' => IQU_Billing_Page::family_url((int) $acc['id'])];
        }
        wp_add_inline_script('iqu-billing-js', 'window.IQU_BILLING_ADD = ' . wp_json_encode(['pricing' => $pricing, 'families' => $families], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) . ';', 'before');

        $x          = is_array($old['extra'] ?? null) ? $old['extra'] : [];
        $xv         = fn($k) => esc_attr((string) ($x[$k] ?? ''));
        $xin        = fn($k, $val) => in_array((string) $val, array_map('strval', (array) ($x[$k] ?? [])), true);
        $course_old = (string) ($old['course'] ?? '');
        $days_old   = (int) ($old['days'] ?? 0);
        $show_fee   = !empty($old['use_agreed']) || !empty($old['zakat']);
        $students   = admin_url('admin.php?page=' . IQU_Billing_Page::SLUG);
        ?>
        <div class="wrap iqu-admin-wrap iqu-billing">
            <?php IQU_Billing_Page::tabs('add'); ?>
            <?php IQU_Billing_Page::page_header('Add existing student', 'For students who joined before the website form existed. They join the enrollment records and are always billed as current students (no free month).'); ?>

            <?php if (!empty($flash['errors'])): ?>
                <div class="notice notice-error"><ul class="iqu-billing-notice-list">
                    <?php foreach ($flash['errors'] as $msg): ?><li><?php echo esc_html($msg); ?></li><?php endforeach; ?>
                </ul></div>
            <?php endif; ?>

            <?php if (!empty($flash['confirm']) && !empty($flash['warnings'])): ?>
                <div class="notice notice-warning"><p><strong>Not saved yet — please check:</strong></p><ul class="iqu-billing-notice-list">
                    <?php foreach ($flash['warnings'] as $msg): ?><li><?php echo esc_html($msg); ?></li><?php endforeach; ?>
                </ul><p>If it is correct, tick <em>I have checked this</em> at the bottom of the form and press Add student again.</p></div>
            <?php endif; ?>

            <?php if (!empty($flash['saved'])): $s = $flash['saved']; ?>
                <div class="notice notice-success"><p>
                    <strong><?php echo esc_html($s['name']); ?></strong> added (IQU-<?php echo (int) $s['id']; ?>) —
                    monthly fee <?php echo esc_html(IQU_Pricing::format((float) $s['net'])); ?>.
                    <a href="<?php echo esc_url(admin_url('admin.php?page=iqu-view-registration&id=' . (int) $s['id'])); ?>">View record</a>
                    · <a href="<?php echo esc_url(admin_url('admin.php?page=' . IQU_Billing_Send::SEND_SLUG . '&ids=' . (int) $s['id'])); ?>">Check and send</a>
                </p></div>
            <?php endif; ?>

            <div class="iqu-add-layout">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="iqu-card iqu-add-form" id="iqu-add-student-form" novalidate>
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
                    <?php wp_nonce_field(self::ACTION); ?>

                    <section class="iqu-add-section" aria-labelledby="iqu-sec-1">
                        <div class="iqu-add-section-head"><span class="iqu-add-step" aria-hidden="true">1</span><div>
                            <p class="iqu-add-section-title" id="iqu-sec-1">Student</p>
                            <p class="iqu-fld-hint">Who is joining. Use the name the family uses.</p>
                        </div></div>
                        <div class="iqu-fld-grid iqu-fld-grid--3">
                            <div class="iqu-fld">
                                <label for="first_name">First name <em>*</em></label>
                                <input id="first_name" name="first_name" type="text" required autocomplete="off" aria-describedby="iqu-err-first_name" value="<?php echo $v('first_name'); ?>"<?php echo $invalid('first_name'); ?>>
                                <?php echo $err('first_name'); ?>
                            </div>
                            <div class="iqu-fld">
                                <label for="last_name">Last name <em>*</em></label>
                                <input id="last_name" name="last_name" type="text" required autocomplete="off" aria-describedby="iqu-err-last_name" value="<?php echo $v('last_name'); ?>">
                                <?php echo $err('last_name'); ?>
                            </div>
                            <div class="iqu-fld">
                                <label for="age">Age <span class="iqu-fld-optional">(optional)</span></label>
                                <input id="age" name="age" type="number" min="1" max="100" step="1" inputmode="numeric" aria-describedby="iqu-err-age" value="<?php echo $xv('age'); ?>"<?php echo $invalid('age'); ?>>
                                <?php echo $err('age'); ?>
                            </div>
                        </div>
                    </section>

                    <section class="iqu-add-section" aria-labelledby="iqu-sec-2">
                        <div class="iqu-add-section-head"><span class="iqu-add-step" aria-hidden="true">2</span><div>
                            <p class="iqu-add-section-title" id="iqu-sec-2">Guardian &amp; contact</p>
                            <p class="iqu-fld-hint">The billing email receives the private payment link and every receipt. Siblings can share one email.</p>
                        </div></div>
                        <div class="iqu-fld-grid">
                            <div class="iqu-fld">
                                <label for="guardian_name">Guardian name</label>
                                <input id="guardian_name" name="guardian_name" type="text" autocomplete="off" aria-describedby="iqu-hint-guardian" value="<?php echo $v('guardian_name'); ?>">
                                <p class="iqu-fld-hint" id="iqu-hint-guardian">Leave empty for adult students.</p>
                            </div>
                            <div class="iqu-fld">
                                <label for="email">Billing email <em>*</em></label>
                                <input id="email" name="email" type="email" required autocomplete="off" aria-describedby="iqu-hint-email iqu-err-email iqu-family-note" value="<?php echo $v('email'); ?>"<?php echo $invalid('email'); ?>>
                                <p class="iqu-fld-hint" id="iqu-hint-email">Payment link and receipts go here.</p>
                                <?php echo $err('email'); ?>
                            </div>
                            <div class="iqu-fld">
                                <label for="whatsapp">WhatsApp <em>*</em></label>
                                <input id="whatsapp" name="whatsapp" type="text" inputmode="tel" required placeholder="+1 214 555 0148" aria-describedby="iqu-hint-whatsapp iqu-err-whatsapp" value="<?php echo $v('whatsapp'); ?>"<?php echo $invalid('whatsapp'); ?>>
                                <p class="iqu-fld-hint" id="iqu-hint-whatsapp">With the country code.</p>
                                <?php echo $err('whatsapp'); ?>
                            </div>
                            <div class="iqu-fld iqu-fld--full">
                                <div class="iqu-family-note" id="iqu-family-note" role="status" hidden>
                                    <span class="dashicons dashicons-info" aria-hidden="true"></span>
                                    <p>This email already has a billing account (family: <a href="#" data-family-link><span data-family-label></span></a>). This student is added as a separate enrollment; their monthly billing is set up separately in Check and send.</p>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="iqu-add-section" aria-labelledby="iqu-sec-3">
                        <div class="iqu-add-section-head"><span class="iqu-add-step" aria-hidden="true">3</span><div>
                            <p class="iqu-add-section-title" id="iqu-sec-3">Course &amp; schedule</p>
                            <p class="iqu-fld-hint">The course and classes a week set the standard fee. The schedule fields are optional.</p>
                        </div></div>
                        <div class="iqu-fld-grid">
                            <fieldset class="iqu-fld iqu-fld--full iqu-chips-field" aria-describedby="iqu-err-course">
                                <legend class="iqu-fld-label">Course <em>*</em></legend>
                                <div class="iqu-chips">
                                    <?php foreach (IQU_Pricing::course_keys() as $i => $key): ?>
                                        <label class="iqu-chip-opt"><input type="radio" name="course" value="<?php echo esc_attr($key); ?>"<?php echo $i === 0 ? ' required' : ''; ?> <?php checked($course_old, $key); ?>><span><?php echo esc_html(IQU_Pricing::label($key)); ?></span></label>
                                    <?php endforeach; ?>
                                </div>
                                <?php echo $err('course'); ?>
                            </fieldset>
                            <fieldset class="iqu-fld iqu-fld--full iqu-chips-field" aria-describedby="iqu-hint-days iqu-err-days">
                                <legend class="iqu-fld-label">Classes a week <em>*</em></legend>
                                <div class="iqu-chips iqu-chips--days">
                                    <?php for ($d = 1; $d <= IQU_Pricing::MAX_DAYS_PER_WEEK; $d++): ?>
                                        <label class="iqu-chip-opt" data-day="<?php echo (int) $d; ?>"><input type="radio" name="days" value="<?php echo (int) $d; ?>"<?php echo $d === 1 ? ' required' : ''; ?> <?php checked($days_old, $d); ?>><span><?php echo (int) $d; ?></span></label>
                                    <?php endfor; ?>
                                </div>
                                <p class="iqu-fld-hint" id="iqu-hint-days" data-days-hint>Only the numbers allowed for the chosen course are shown.</p>
                                <?php echo $err('days'); ?>
                            </fieldset>
                            <fieldset class="iqu-fld iqu-fld--full iqu-chips-field" aria-describedby="iqu-hint-pdays iqu-err-preferred_days">
                                <legend class="iqu-fld-label">Preferred days <span class="iqu-fld-optional">(optional)</span></legend>
                                <div class="iqu-chips iqu-chips--weekdays">
                                    <?php foreach (IQU_Reg_Fields::WEEK_DAYS as $day): ?>
                                        <label class="iqu-chip-opt"><input type="checkbox" name="preferred_days[]" value="<?php echo esc_attr($day); ?>" <?php checked($xin('preferred_days', $day)); ?>><span><?php echo esc_html(substr($day, 0, 3)); ?></span></label>
                                    <?php endforeach; ?>
                                </div>
                                <p class="iqu-fld-hint" id="iqu-hint-pdays">No more days than classes a week.</p>
                                <?php echo $err('preferred_days'); ?>
                            </fieldset>
                            <fieldset class="iqu-fld iqu-fld--full" aria-describedby="iqu-err-time_slot">
                                <legend class="iqu-fld-label">Preferred time <span class="iqu-fld-optional">(optional)</span></legend>
                                <div class="iqu-fld-grid iqu-fld-grid--3 iqu-fld-grid--tight">
                                    <div class="iqu-fld"><label for="time_start" class="iqu-billing-sublabel">Start</label><input id="time_start" name="time_start" type="time" value="<?php echo $xv('time_start'); ?>"></div>
                                    <div class="iqu-fld"><label for="time_end" class="iqu-billing-sublabel">End</label><input id="time_end" name="time_end" type="time" value="<?php echo $xv('time_end'); ?>"></div>
                                    <div class="iqu-fld"><label for="time_zone" class="iqu-billing-sublabel">Time zone</label>
                                        <select id="time_zone" name="time_zone">
                                            <option value="">— Time zone —</option>
                                            <?php foreach (IQU_Reg_Fields::TIME_ZONES as $tzv => $tzl): ?><option value="<?php echo esc_attr($tzv); ?>" <?php selected((string) ($x['time_zone'] ?? ''), $tzv); ?>><?php echo esc_html($tzl); ?></option><?php endforeach; ?>
                                        </select></div>
                                </div>
                                <?php echo $err('time_slot'); ?>
                            </fieldset>
                            <div class="iqu-fld">
                                <label for="session_dur">Session duration <span class="iqu-fld-optional">(optional)</span></label>
                                <select id="session_dur" name="session_dur" aria-describedby="iqu-err-session_dur">
                                    <option value="">— Choose —</option>
                                    <?php foreach (IQU_Reg_Fields::SESSION_DURATIONS as $sv => $sl): ?><option value="<?php echo esc_attr($sv); ?>" <?php selected((string) ($x['session_dur'] ?? ''), $sv); ?>><?php echo esc_html($sl); ?></option><?php endforeach; ?>
                                </select>
                                <?php echo $err('session_dur'); ?>
                            </div>
                            <div class="iqu-fld">
                                <label for="teacher_pref">Teacher preference <span class="iqu-fld-optional">(optional)</span></label>
                                <select id="teacher_pref" name="teacher_pref" aria-describedby="iqu-err-teacher_pref">
                                    <option value="">— Choose —</option>
                                    <?php foreach (IQU_Reg_Fields::TEACHER_PREFS as $tv => $tl): ?><option value="<?php echo esc_attr($tv); ?>" <?php selected((string) ($x['teacher_pref'] ?? ''), $tv); ?>><?php echo esc_html($tl); ?></option><?php endforeach; ?>
                                </select>
                                <?php echo $err('teacher_pref'); ?>
                            </div>
                        </div>
                    </section>

                    <section class="iqu-add-section" aria-labelledby="iqu-sec-4">
                        <div class="iqu-add-section-head"><span class="iqu-add-step" aria-hidden="true">4</span><div>
                            <p class="iqu-add-section-title" id="iqu-sec-4">Background</p>
                            <p class="iqu-fld-hint">The same questions as the Enroll for Free form. All optional.</p>
                        </div></div>
                        <div class="iqu-fld-grid">
                            <div class="iqu-fld">
                                <label for="country_origin">Country of origin</label>
                                <input id="country_origin" name="country_origin" type="text" maxlength="100" autocomplete="off" value="<?php echo $xv('country_origin'); ?>">
                            </div>
                            <div class="iqu-fld">
                                <label for="country_res">Country of residence</label>
                                <input id="country_res" name="country_res" type="text" maxlength="100" autocomplete="off" value="<?php echo $xv('country_res'); ?>">
                            </div>
                            <div class="iqu-fld">
                                <label for="quran_level">Qur'an reading level</label>
                                <select id="quran_level" name="quran_level" aria-describedby="iqu-err-quran_level">
                                    <option value="">— Choose —</option>
                                    <?php foreach (IQU_Reg_Fields::QURAN_LEVELS as $lv => $ll): ?><option value="<?php echo esc_attr($lv); ?>" <?php selected((string) ($x['quran_level'] ?? ''), $lv); ?>><?php echo esc_html($ll); ?></option><?php endforeach; ?>
                                </select>
                                <?php echo $err('quran_level'); ?>
                            </div>
                            <div class="iqu-fld">
                                <label for="referral">How they heard about us</label>
                                <select id="referral" name="referral" aria-describedby="iqu-err-referral">
                                    <option value="">— Choose —</option>
                                    <?php foreach (IQU_Reg_Fields::REFERRALS as $rv => $rl): ?><option value="<?php echo esc_attr($rv); ?>" <?php selected((string) ($x['referral'] ?? ''), $rv); ?>><?php echo esc_html($rl); ?></option><?php endforeach; ?>
                                    <option value="other" <?php selected((string) ($x['referral'] ?? ''), 'other'); ?>>Other</option>
                                </select>
                                <label for="referral_other" class="iqu-billing-sublabel">If Other, how? <span class="iqu-fld-optional">(optional)</span></label>
                                <input id="referral_other" name="referral_other" type="text" maxlength="200" autocomplete="off" value="<?php echo $xv('referral_other'); ?>">
                                <?php echo $err('referral'); ?>
                            </div>
                            <fieldset class="iqu-fld iqu-fld--full iqu-chips-field" aria-describedby="iqu-err-languages">
                                <legend class="iqu-fld-label">Languages spoken fluently</legend>
                                <div class="iqu-chips">
                                    <?php foreach (IQU_Reg_Fields::LANGUAGES as $lgv => $lgl): ?>
                                        <label class="iqu-chip-opt"><input type="checkbox" name="languages[]" value="<?php echo esc_attr($lgv); ?>" <?php checked($xin('languages', $lgv)); ?>><span><?php echo esc_html($lgl); ?></span></label>
                                    <?php endforeach; ?>
                                </div>
                                <?php echo $err('languages'); ?>
                            </fieldset>
                            <div class="iqu-fld iqu-fld--full">
                                <label for="memorized">Memorized before (Surahs or Juz)</label>
                                <textarea id="memorized" name="memorized" rows="2" maxlength="500"><?php echo esc_textarea((string) ($x['memorized'] ?? '')); ?></textarea>
                            </div>
                        </div>
                    </section>

                    <section class="iqu-add-section" aria-labelledby="iqu-sec-5">
                        <div class="iqu-add-section-head"><span class="iqu-add-step" aria-hidden="true">5</span><div>
                            <p class="iqu-add-section-title" id="iqu-sec-5">Fee</p>
                            <p class="iqu-fld-hint">The standard fee comes from the pricing rules; a lower agreed fee is stored as a reduction.</p>
                        </div></div>
                        <div class="iqu-fld-grid">
                            <div class="iqu-fld iqu-fld--full">
                                <label class="iqu-billing-check-label"><input type="checkbox" id="iqu-use-agreed" name="use_agreed" value="1" aria-controls="iqu-agreed-panel" <?php checked(!empty($old['use_agreed'])); ?>> This family pays a lower fee agreed with us</label>
                                <div class="iqu-agreed-panel" id="iqu-agreed-panel" data-show="<?php echo $show_fee ? '1' : '0'; ?>">
                                    <label for="agreed_fee" class="iqu-billing-sublabel">Agreed monthly fee ($)</label>
                                    <input id="agreed_fee" name="agreed_fee" type="number" min="0" step="0.01" class="iqu-fld-narrow" aria-describedby="iqu-hint-agreed iqu-err-agreed_fee" value="<?php echo $v('agreed_fee'); ?>"<?php echo $invalid('agreed_fee'); ?>>
                                    <p class="iqu-fld-hint" id="iqu-hint-agreed">Billed exactly as entered, lower or higher than the standard fee. Enter 0 for a full scholarship (they will not be billed).</p>
                                    <?php echo $err('agreed_fee'); ?>
                                    <label class="iqu-billing-check-label"><input type="checkbox" name="zakat" value="1" <?php checked(!empty($old['zakat'])); ?>> The reduction is paid by the Zakat Fund</label>
                                </div>
                            </div>
                            <div class="iqu-fld iqu-fld--full">
                                <label for="note">Note <span class="iqu-fld-optional">(optional)</span></label>
                                <textarea id="note" name="note" rows="3"><?php echo esc_textarea((string) ($old['note'] ?? '')); ?></textarea>
                            </div>
                        </div>
                    </section>

                    <?php if (!empty($flash['confirm']) && !empty($flash['warnings'])): ?>
                        <div class="iqu-confirm-warnings" role="group" aria-labelledby="iqu-confirm-title">
                            <p id="iqu-confirm-title"><span class="dashicons dashicons-warning" aria-hidden="true"></span><strong>Please confirm</strong></p>
                            <ul><?php foreach ($flash['warnings'] as $msg): ?><li><?php echo esc_html($msg); ?></li><?php endforeach; ?></ul>
                            <label class="iqu-billing-check-label"><input type="checkbox" name="confirm_warnings" value="1"> I have checked this — save anyway</label>
                        </div>
                    <?php endif; ?>
                    <div class="iqu-form-actions iqu-btn-group">
                        <a class="iqu-btn iqu-btn--secondary" href="<?php echo esc_url($students); ?>">Cancel</a>
                        <button type="submit" name="submit" value="Add student" class="iqu-btn iqu-btn--primary"><?php echo IQU_Billing_Page::icon('plus-alt2'); ?>Add student</button>
                    </div>
                </form>

                <aside class="iqu-card iqu-add-summary" aria-labelledby="iqu-sum-title">
                    <div class="iqu-card-head"><span class="iqu-card-head-title" id="iqu-sum-title">Summary</span></div>
                    <div class="iqu-billing-body" aria-live="polite">
                        <p class="iqu-sum-name" data-sum="name">New student</p>
                        <p class="iqu-sum-course" data-sum="course">Choose a course and classes a week.</p>
                        <dl class="iqu-sum-fees">
                            <div><dt>Standard fee</dt><dd data-sum="std">—</dd></div>
                            <div data-sum-row="agreed" hidden><dt>Agreed fee</dt><dd data-sum="agreed">—</dd></div>
                            <div data-sum-row="diff" hidden><dt>Difference from standard</dt><dd data-sum="diff">—</dd></div>
                        </dl>
                        <p class="iqu-sum-note" data-sum-row="zakat" hidden><span class="dashicons dashicons-heart" aria-hidden="true"></span>The Zakat Fund pays the reduction.</p>
                        <p class="iqu-sum-next-title">What happens next</p>
                        <ol class="iqu-sum-next">
                            <li>Added as a current student — no free month.</li>
                            <li>Open <strong>Check and send</strong> for them (Student Billing → Not set up).</li>
                            <li>Send the private payment link to the billing email.</li>
                        </ol>
                    </div>
                </aside>
            </div>
        </div>
        <?php
    }
}
