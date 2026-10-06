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
 * - The fee is computed by IQU_Pricing; an agreed fee may only LOWER it,
 *   never raise it, and is stored as a discount.
 * - Exact duplicates (same student name + email) are refused.
 * - Writes through IQU_Database::insert_registration() with a fixed set of
 *   columns. No notification emails or Telegram messages are triggered.
 */
class IQU_Billing_Add_Student
{
    public const PAGE_SLUG = 'iqu-billing-add-student';
    public const MARKER    = 'existing_student'; // stored in `referral`
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
            'whatsapp'      => preg_replace('/[^0-9+\-\s()]/', '', (string) wp_unslash($_POST['whatsapp'] ?? '')),
            'course'        => sanitize_key(wp_unslash($_POST['course'] ?? '')),
            'days'          => absint($_POST['days'] ?? 0),
            'use_agreed'    => !empty($_POST['use_agreed']),
            'agreed_fee'    => isset($_POST['agreed_fee']) ? (float) $_POST['agreed_fee'] : 0.0,
            'zakat'         => !empty($_POST['zakat']),
            'note'          => sanitize_textarea_field(wp_unslash($_POST['note'] ?? '')),
        ];
        $in['whatsapp'] = trim(substr($in['whatsapp'], 0, 25));

        $prep = self::prepare($in);
        if ($prep['errors']) {
            self::flash(['errors' => $prep['errors'], 'old' => $in]);
            self::back();
        }

        $id = IQU_Database::insert_registration($prep['row']);
        if (!$id) {
            self::flash(['errors' => ['Could not save the student. Nothing was added.'], 'old' => $in]);
            self::back();
        }

        self::flash(['saved' => [
            'id'   => (int) $id,
            'name' => $in['first_name'] . ' ' . $in['last_name'],
            'net'  => $prep['net'],
        ]]);
        self::back();
    }

    /** Optional enrollment columns that may be filled from the form or a CSV. */
    private const EXTRA_FIELDS = [
        'country_res'    => 100,
        'preferred_days' => 200,
        'time_slot'      => 100,
        'session_dur'    => 20,
        'languages'      => 200,
        'teacher_pref'   => 20,
    ];

    /**
     * Validate one student and build the registrations row.
     * Shared by the single form and the CSV import so both follow the same rules.
     *
     * @param array $in first_name, last_name, guardian_name, email, whatsapp, course,
     *                  days, use_agreed, agreed_fee, zakat, note, extra[]
     * @return array{errors:string[], row:array, gross:float, net:float}
     */
    public static function prepare(array $in): array
    {
        $errors = self::validate($in);
        $calc   = null;
        if (!$errors) {
            $calc = IQU_Pricing::calculate($in['course'], (int) $in['days']);
            if (empty($calc['valid'])) {
                $errors[] = $calc['error'] ?: 'Invalid course or days.';
            }
        }

        $gross    = $calc ? round((float) $calc['monthly_amount'], 2) : 0.0;
        $discount = 0.0;
        if (!$errors && !empty($in['use_agreed'])) {
            $agreed = round((float) $in['agreed_fee'], 2);
            if ($agreed < 0) {
                $errors[] = 'The agreed fee cannot be negative.';
            } elseif ($agreed > $gross) {
                $errors[] = sprintf('The agreed fee cannot be more than the standard fee (%s).', IQU_Pricing::format($gross));
            } else {
                $discount = round($gross - $agreed, 2);
            }
        }

        if (!$errors && self::is_duplicate($in['first_name'], $in['last_name'], $in['email'])) {
            $errors[] = 'Already in the enrollment records (same name and email).';
        }

        if ($errors) {
            return ['errors' => $errors, 'row' => [], 'gross' => $gross, 'net' => 0.0];
        }

        $user = wp_get_current_user();
        $note = trim('Added from Billing as an existing student by ' . $user->user_login . '. ' . ($in['note'] ?? ''));
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
            'referral'           => self::MARKER,
            'course_type'        => $in['course'],
            'per_class_rate'     => (float) $calc['per_class_rate'],
            'calculated_amount'  => $gross,
            'discount_amount'    => $discount,
            'zakat_declaration'  => !empty($in['zakat']) ? 1 : 0,
            'special_discount'   => 0,
            'flexible_fee_note'  => !empty($in['use_agreed']) ? substr('Agreed monthly fee ' . IQU_Pricing::format($gross - $discount), 0, 300) : '',
            'status'             => 'enrolled',
            'admin_note'         => $note,
            'ip_address'         => '',
            'user_agent'         => 'IQU Billing (added by admin)',
            'created_at'         => $now,
            'updated_at'         => $now,
        ];

        $extra = is_array($in['extra'] ?? null) ? $in['extra'] : [];
        foreach (self::EXTRA_FIELDS as $col => $max) {
            if (isset($extra[$col]) && $extra[$col] !== '') {
                $row[$col] = substr(self::clean_text((string) $extra[$col]), 0, $max);
            }
        }
        if (isset($extra['age']) && (int) $extra['age'] > 0 && (int) $extra['age'] < 120) {
            $row['age'] = (int) $extra['age'];
        }

        return ['errors' => [], 'row' => $row, 'gross' => $gross, 'net' => round($gross - $discount, 2)];
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

    private static function validate(array $in): array
    {
        $e = [];
        if ($in['first_name'] === '' || $in['last_name'] === '') $e[] = 'Enter the student\'s first and last name.';
        if (!is_email($in['email'])) $e[] = 'Enter a valid email address. Payment links and receipts go here.';
        if (strlen(preg_replace('/\D/', '', $in['whatsapp'])) < 7) $e[] = 'Enter a WhatsApp number with country code.';
        if (!IQU_Pricing::is_valid_course($in['course'])) $e[] = 'Choose a course.';
        if ($in['days'] < 1 || $in['days'] > 7) $e[] = 'Choose classes per week (1–7).';
        return $e;
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

        $rates = [];
        foreach (IQU_Pricing::course_keys() as $key) {
            $rates[$key] = ['label' => IQU_Pricing::label($key), 'min' => IQU_Pricing::min_days($key), 'prices' => []];
            for ($d = 1; $d <= 7; $d++) {
                $c = IQU_Pricing::calculate($key, $d);
                $rates[$key]['prices'][$d] = !empty($c['valid']) ? (float) $c['monthly_amount'] : null;
            }
        }
        ?>
        <div class="wrap iqu-admin-wrap iqu-billing">
            <?php IQU_Billing_Page::tabs('add'); ?>

            <?php if (!empty($flash['errors'])): ?>
                <div class="notice notice-error"><ul class="iqu-billing-notice-list">
                    <?php foreach ($flash['errors'] as $msg): ?><li><?php echo esc_html($msg); ?></li><?php endforeach; ?>
                </ul></div>
            <?php endif; ?>

            <?php if (!empty($flash['saved'])): $s = $flash['saved']; ?>
                <div class="notice notice-success"><p>
                    <strong><?php echo esc_html($s['name']); ?></strong> added (IQU-<?php echo (int) $s['id']; ?>) —
                    monthly fee <?php echo esc_html(IQU_Pricing::format((float) $s['net'])); ?>.
                    <a href="<?php echo esc_url(admin_url('admin.php?page=iqu-view-registration&id=' . (int) $s['id'])); ?>">View record</a>
                </p></div>
            <?php endif; ?>

            <div class="iqu-card">
                <div class="iqu-card-head">
                    <span class="iqu-card-head-title">Add existing student</span>
                    <span class="iqu-card-head-badge">Billed as a current student</span>
                </div>
                <div class="iqu-billing-body">
                    <p class="iqu-billing-intro">For students who joined before the website form existed. They are added to the enrollment records like any other student, and always billed as current students (no free month).</p>
                </div>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="iqu-cpn-form">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
                    <?php wp_nonce_field(self::ACTION); ?>

                    <div class="iqu-fld-grid">
                        <div class="iqu-fld">
                            <label for="first_name">Student first name <em>*</em></label>
                            <input id="first_name" name="first_name" type="text" required value="<?php echo $v('first_name'); ?>">
                        </div>
                        <div class="iqu-fld">
                            <label for="last_name">Student last name <em>*</em></label>
                            <input id="last_name" name="last_name" type="text" required value="<?php echo $v('last_name'); ?>">
                        </div>
                        <div class="iqu-fld">
                            <label for="guardian_name">Guardian name</label>
                            <input id="guardian_name" name="guardian_name" type="text" value="<?php echo $v('guardian_name'); ?>">
                            <p class="iqu-fld-hint">Leave empty for adult students.</p>
                        </div>
                        <div class="iqu-fld">
                            <label for="email">Billing email <em>*</em></label>
                            <input id="email" name="email" type="email" required value="<?php echo $v('email'); ?>">
                            <p class="iqu-fld-hint">Payment link and every receipt go here. Siblings can share one email.</p>
                        </div>
                        <div class="iqu-fld">
                            <label for="whatsapp">WhatsApp <em>*</em></label>
                            <input id="whatsapp" name="whatsapp" type="text" required placeholder="+1 214 555 0148" value="<?php echo $v('whatsapp'); ?>">
                        </div>
                        <div class="iqu-fld">
                            <label for="course">Course <em>*</em></label>
                            <select id="course" name="course" required>
                                <option value="">Choose…</option>
                                <?php foreach ($rates as $key => $r): ?>
                                    <option value="<?php echo esc_attr($key); ?>" <?php selected($old['course'] ?? '', $key); ?>><?php echo esc_html($r['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="iqu-fld">
                            <label for="days">Classes per week <em>*</em></label>
                            <select id="days" name="days" required>
                                <option value="">Choose…</option>
                                <?php for ($d = 1; $d <= 7; $d++): ?>
                                    <option value="<?php echo $d; ?>" <?php selected((int) ($old['days'] ?? 0), $d); ?>><?php echo $d; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="iqu-fld">
                            <span class="iqu-fld-label">Standard monthly fee</span>
                            <div class="iqu-fld-value"><strong id="iqu-std-fee">—</strong></div>
                            <p class="iqu-fld-hint">Worked out from the pricing rules. Shown here for checking; the server calculates it again when you save.</p>
                        </div>
                        <div class="iqu-fld iqu-fld--full">
                            <span class="iqu-fld-label">Agreed reduced fee</span>
                            <label class="iqu-billing-check-label"><input type="checkbox" name="use_agreed" value="1" <?php checked(!empty($old['use_agreed'])); ?>> This family pays a lower fee agreed with us</label>
                            <label for="agreed_fee" class="iqu-billing-sublabel">Agreed monthly fee ($)</label>
                            <input id="agreed_fee" name="agreed_fee" type="number" min="0" step="0.01" class="iqu-fld-narrow" value="<?php echo $v('agreed_fee'); ?>">
                            <p class="iqu-fld-hint">Can only be lower than the standard fee. Enter 0 for a full scholarship (they will not be billed).</p>
                        </div>
                        <div class="iqu-fld iqu-fld--full">
                            <span class="iqu-fld-label">Zakat support</span>
                            <label class="iqu-billing-check-label"><input type="checkbox" name="zakat" value="1" <?php checked(!empty($old['zakat'])); ?>> The reduction is paid by the Zakat Fund</label>
                        </div>
                        <div class="iqu-fld iqu-fld--full">
                            <label for="note">Note</label>
                            <textarea id="note" name="note" rows="3"><?php echo esc_textarea((string) ($old['note'] ?? '')); ?></textarea>
                        </div>
                    </div>

                    <div class="iqu-cpn-actions">
                        <?php submit_button('Add student'); ?>
                    </div>
                </form>
            </div>
        </div>
        <script>
        (function () {
            var rates = <?php echo wp_json_encode($rates); ?>;
            var c = document.getElementById('course'), d = document.getElementById('days'), out = document.getElementById('iqu-std-fee');
            function show() {
                var r = rates[c.value], p = r && d.value ? r.prices[d.value] : null;
                if (!r || !d.value) { out.textContent = '\u2014'; return; }
                out.textContent = p === null ? (r.label + ' needs at least ' + r.min + ' classes a week') : ('$' + Number(p).toFixed(2) + ' a month');
            }
            c.addEventListener('change', show); d.addEventListener('change', show); show();
        })();
        </script>
        <?php
    }
}
