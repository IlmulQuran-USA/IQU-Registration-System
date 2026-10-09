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
        wp_add_inline_script('iqu-billing-js', 'window.IQU_BILLING_ADD = ' . wp_json_encode(['pricing' => $pricing, 'families' => $families]) . ';', 'before');

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
                        <div class="iqu-fld-grid">
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
                        </div>
                    </section>

                    <section class="iqu-add-section" aria-labelledby="iqu-sec-2">
                        <div class="iqu-add-section-head"><span class="iqu-add-step" aria-hidden="true">2</span><div>
                            <p class="iqu-add-section-title" id="iqu-sec-2">Family contact</p>
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
                            <p class="iqu-add-section-title" id="iqu-sec-3">Course and fee</p>
                            <p class="iqu-fld-hint">The standard fee comes from the pricing rules; a lower agreed fee is stored as a reduction.</p>
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
                            <div class="iqu-fld iqu-fld--full">
                                <label class="iqu-billing-check-label"><input type="checkbox" id="iqu-use-agreed" name="use_agreed" value="1" aria-controls="iqu-agreed-panel" <?php checked(!empty($old['use_agreed'])); ?>> This family pays a lower fee agreed with us</label>
                                <div class="iqu-agreed-panel" id="iqu-agreed-panel" data-show="<?php echo $show_fee ? '1' : '0'; ?>">
                                    <label for="agreed_fee" class="iqu-billing-sublabel">Agreed monthly fee ($)</label>
                                    <input id="agreed_fee" name="agreed_fee" type="number" min="0" step="0.01" class="iqu-fld-narrow" aria-describedby="iqu-hint-agreed iqu-err-agreed_fee" value="<?php echo $v('agreed_fee'); ?>"<?php echo $invalid('agreed_fee'); ?>>
                                    <p class="iqu-fld-hint" id="iqu-hint-agreed">Cannot be more than the standard fee. Enter 0 for a full scholarship (they will not be billed).</p>
                                    <?php echo $err('agreed_fee'); ?>
                                    <label class="iqu-billing-check-label"><input type="checkbox" name="zakat" value="1" <?php checked(!empty($old['zakat'])); ?>> The reduction is paid by the Zakat Fund</label>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="iqu-add-section" aria-label="Note">
                        <div class="iqu-fld">
                            <label for="note">Note <span class="iqu-fld-optional">(optional)</span></label>
                            <textarea id="note" name="note" rows="3"><?php echo esc_textarea((string) ($old['note'] ?? '')); ?></textarea>
                        </div>
                    </section>

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
                            <div data-sum-row="diff" hidden><dt>Reduction</dt><dd data-sum="diff">—</dd></div>
                        </dl>
                        <p class="iqu-sum-note" data-sum-row="zakat" hidden><span class="dashicons dashicons-heart" aria-hidden="true"></span>The Zakat Fund pays the reduction.</p>
                        <p class="iqu-sum-next-title">What happens next</p>
                        <ol class="iqu-sum-next">
                            <li>Added as a current student — no free month.</li>
                            <li>Open <strong>Check and send</strong> for them (Students → Not set up).</li>
                            <li>Send the private payment link to the billing email.</li>
                        </ol>
                    </div>
                </aside>
            </div>
        </div>
        <?php
    }
}
