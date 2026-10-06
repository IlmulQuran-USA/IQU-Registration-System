<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Billing_Send
 *
 * Admin screens for sending the payment link:
 *   - Buttons on the enrollment lists (added beside each row)
 *   - "Check and send" screen (amount, siblings, first charge date)
 *   - "Message" screen (copy for WhatsApp, open WhatsApp, resend email, reset link)
 *
 * Security:
 * - manage_options only; every action has its own nonce.
 * - Account and registration ids from the URL are re-checked on the server;
 *   accounts are always looked up in the current mode only.
 * - Amounts shown and saved come from IQU_Billing_Pricing (server-side).
 * - The private link is shown only to admins, on the Message screen.
 * - All output is escaped; the WhatsApp text is URL-encoded.
 */
class IQU_Billing_Send
{
    public const SEND_SLUG = 'iqu-billing-send';
    public const MSG_SLUG  = 'iqu-billing-message';
    private const CAP      = 'manage_options';
    private const A_CREATE = 'iqu_billing_create';
    private const A_EMAIL  = 'iqu_billing_send_email';
    private const A_WA     = 'iqu_billing_mark_whatsapp';
    private const A_RESET  = 'iqu_billing_reset_link';
    private const A_SYNC   = 'iqu_billing_sync_one';
    private const TX       = 'iqu_billing_send_notice_';
    public const OPT_ZELLE = 'iqu_billing_zelle_end_date';

    public static function init(): void
    {
        add_action('admin_menu', [__CLASS__, 'register_pages'], 23);
        add_action('admin_head', [__CLASS__, 'hide_pages']); // after WordPress has checked access
        add_action('admin_post_' . self::A_CREATE, [__CLASS__, 'handle_create']);
        add_action('admin_post_' . self::A_EMAIL, [__CLASS__, 'handle_email']);
        add_action('admin_post_' . self::A_WA, [__CLASS__, 'handle_whatsapp']);
        add_action('admin_post_' . self::A_RESET, [__CLASS__, 'handle_reset']);
        add_action('admin_post_' . self::A_SYNC, [__CLASS__, 'handle_sync']);
    }

    public static function register_pages(): void
    {
        add_submenu_page('iqu-registrations', 'Send Payment Link — IQU', 'Send Payment Link', self::CAP, self::SEND_SLUG, [__CLASS__, 'render_send']);
        add_submenu_page('iqu-registrations', 'Payment Link Message — IQU', 'Payment Link Message', self::CAP, self::MSG_SLUG, [__CLASS__, 'render_message']);
    }

    /** Reachable by URL, but not listed in the menu. */
    public static function hide_pages(): void
    {
        remove_submenu_page('iqu-registrations', self::SEND_SLUG);
        remove_submenu_page('iqu-registrations', self::MSG_SLUG);
    }

    public static function status_label(string $status): string
    {
        $labels = [
            'not_sent'             => 'Billing created, link not sent',
            'link_sent'            => 'Link sent, no card yet',
            'free_month'           => 'Free first month',
            'waiting_first_charge' => 'Card added, waiting for first charge',
            'active'               => 'Active',
            'past_due'             => 'Payment failed',
            'unpaid'               => 'Unpaid — contact family',
            'paused'               => 'Paused',
            'canceled'             => 'Canceled',
        ];
        return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    // ------------------------------------------------------------
    // Check and send
    // ------------------------------------------------------------

    private static function ids_from(array $src): array
    {
        $raw = $src['ids'] ?? '';
        $raw = is_array($raw) ? $raw : explode(',', (string) $raw);
        return array_values(array_unique(array_filter(array_map('absint', $raw))));
    }

    public static function render_send(): void
    {
        if (!current_user_can(self::CAP)) wp_die('You do not have permission to view this page.', 403);

        $ids     = self::ids_from($_GET);
        $courses = array_map('sanitize_key', (array) ($_GET['course'] ?? []));
        $days    = array_map('absint', (array) ($_GET['days'] ?? []));
        $then    = ($_GET['then'] ?? '') === 'copy' ? 'copy' : 'email';
        $notice  = get_transient(self::TX . get_current_user_id());
        if ($notice) delete_transient(self::TX . get_current_user_id());

        echo '<div class="wrap"><h1>Billing</h1>';
        IQU_Billing_Page::tabs('students');
        echo '<h2>Check and send</h2>';
        if ($notice) self::print_notice($notice);

        if (!IQU_Stripe::is_ready()) {
            echo '<div class="notice notice-error"><p>' . esc_html(IQU_Stripe::not_ready_reason()) . '</p></div></div>';
            return;
        }
        if (!$ids) {
            echo '<p>No student selected. Use the buttons on the Enroll for Free list.</p></div>';
            return;
        }

        $main = IQU_Database::get_registration($ids[0]);
        if (!$main) { echo '<p>Student not found.</p></div>'; return; }

        // Siblings: other monthly students with the same email and no billing yet.
        global $wpdb;
        $table    = $wpdb->prefix . IQU_TABLE_NAME;
        $siblings = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE form_type = %s AND LOWER(email) = LOWER(%s) AND id <> %d ORDER BY id",
            IQU_Database::FORM_FREE, $main['email'], (int) $main['id']
        ), ARRAY_A) ?: [];
        $siblings = array_values(array_filter($siblings, fn($s) => !IQU_Billing_DB::account_for_registration((int) $s['id'])));

        $rows = [];
        $total = 0.0; $ready = true; $types = [];
        foreach ($ids as $id) {
            $reg = IQU_Database::get_registration($id);
            if (!$reg) continue;
            $existing = IQU_Billing_DB::account_for_registration($id);
            $p = IQU_Billing_Pricing::for_registration($reg, $courses[$id] ?? null, $days[$id] ?? null);
            if ($existing || (!$p['billable'] && !$p['needs_input']) || $p['needs_input']) $ready = false;
            if ($p['billable']) $total += $p['net'];
            $types[] = IQU_Billing_Service::student_type($reg);
            $rows[] = ['reg' => $reg, 'p' => $p, 'existing' => $existing];
        }
        $type = (count(array_unique($types)) === 1 && $types[0] === 'new') ? 'new' : 'current';
        $suggest = ($type === 'new' && count($ids) === 1) ? IQU_Billing_Service::suggested_first_charge($main) : '';
        $base = admin_url('admin.php');
        ?>
        <p>Everything here is worked out on the server from the pricing rules. Nothing is charged today.</p>

        <form method="get" action="<?php echo esc_url($base); ?>">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::SEND_SLUG); ?>">
            <input type="hidden" name="then" value="<?php echo esc_attr($then); ?>">

            <table class="widefat striped" style="max-width:980px">
                <thead><tr><th>Student</th><th>Course</th><th>Standard</th><th>Discount</th><th>Monthly</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): $reg = $r['reg']; $p = $r['p']; $id = (int) $reg['id']; ?>
                    <tr>
                        <td><strong><?php echo esc_html($reg['first_name'] . ' ' . $reg['last_name']); ?></strong><br><span class="description">IQU-<?php echo $id; ?> · <?php echo esc_html(IQU_Billing_Service::student_type($reg) === 'new' ? 'new student' : 'current student'); ?></span>
                            <input type="hidden" name="ids[]" value="<?php echo $id; ?>"></td>
                        <td>
                            <?php if ($p['needs_input'] || ($reg['course_type'] ?? '') === ''): ?>
                                <label class="screen-reader-text" for="c<?php echo $id; ?>">Course</label>
                                <select id="c<?php echo $id; ?>" name="course[<?php echo $id; ?>]">
                                    <option value="">Choose course…</option>
                                    <?php foreach (IQU_Pricing::course_keys() as $k): ?>
                                        <option value="<?php echo esc_attr($k); ?>" <?php selected($courses[$id] ?? '', $k); ?>><?php echo esc_html(IQU_Pricing::label($k)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ((int) $reg['days_per_week'] <= 0): ?>
                                    <label class="screen-reader-text" for="d<?php echo $id; ?>">Days per week</label>
                                    <select id="d<?php echo $id; ?>" name="days[<?php echo $id; ?>]">
                                        <option value="">Days…</option>
                                        <?php for ($i = 1; $i <= 7; $i++): ?><option value="<?php echo $i; ?>" <?php selected($days[$id] ?? 0, $i); ?>><?php echo $i; ?></option><?php endfor; ?>
                                    </select>
                                <?php else: ?>
                                    <br><span class="description"><?php echo (int) $reg['days_per_week']; ?> days/week (from the record)</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php echo esc_html($p['course_label']); ?><br><span class="description"><?php echo (int) $p['days_per_week']; ?> days/week · <?php echo (int) $p['classes_per_month']; ?> classes × <?php echo esc_html(IQU_Pricing::format($p['per_class_rate'])); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $p['gross'] > 0 ? esc_html(IQU_Pricing::format($p['gross'])) : '—'; ?></td>
                        <td><?php echo $p['discount'] > 0 ? esc_html('−' . IQU_Pricing::format($p['discount']) . ' ' . $p['discount_label']) : '—'; ?></td>
                        <td><strong><?php echo $p['billable'] ? esc_html(IQU_Pricing::format($p['net'])) : '—'; ?></strong></td>
                        <td>
                            <?php if ($r['existing']): ?><span style="color:#8a2424">Already has billing.</span>
                            <?php elseif ($p['needs_input']): ?><span style="color:#8a5a00"><?php echo esc_html($p['reason']); ?></span>
                            <?php elseif (!$p['billable']): ?><span><?php echo esc_html($p['reason']); ?></span><?php endif; ?>
                            <?php foreach ($p['warnings'] as $w): ?><br><span class="description" style="color:#8a5a00"><?php echo esc_html($w); ?></span><?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><th colspan="4" style="text-align:right">Charged every month</th><th colspan="2"><strong><?php echo esc_html(IQU_Pricing::format($total)); ?></strong></th></tr></tfoot>
            </table>

            <?php if ($siblings): ?>
                <h2 style="margin-top:22px">Same family?</h2>
                <p>These students use the same email. Tick to bill them together: one charge a month for the whole family.</p>
                <?php foreach ($siblings as $s): $sid = (int) $s['id']; ?>
                    <label style="display:block;margin:4px 0"><input type="checkbox" name="ids[]" value="<?php echo $sid; ?>" <?php checked(in_array($sid, $ids, true)); ?>>
                        <?php echo esc_html($s['first_name'] . ' ' . $s['last_name']); ?> (IQU-<?php echo $sid; ?>)</label>
                <?php endforeach; ?>
            <?php endif; ?>

            <p><?php submit_button('Recalculate', 'secondary', '', false); ?></p>
        </form>

        <hr style="margin:22px 0">

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:720px">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::A_CREATE); ?>">
            <?php wp_nonce_field(self::A_CREATE); ?>
            <?php foreach ($ids as $id): ?><input type="hidden" name="ids[]" value="<?php echo (int) $id; ?>"><?php endforeach; ?>
            <?php foreach ($courses as $id => $c): ?><input type="hidden" name="course[<?php echo (int) $id; ?>]" value="<?php echo esc_attr($c); ?>"><?php endforeach; ?>
            <?php foreach ($days as $id => $d): ?><input type="hidden" name="days[<?php echo (int) $id; ?>]" value="<?php echo (int) $d; ?>"><?php endforeach; ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="first_charge_date">First charge</label></th>
                    <td>
                        <input id="first_charge_date" type="date" name="first_charge_date" required value="<?php echo esc_attr((string) $suggest); ?>" min="<?php echo esc_attr(gmdate('Y-m-d')); ?>" max="<?php echo esc_attr(gmdate('Y-m-d', time() + 45 * DAY_IN_SECONDS)); ?>">
                        <p class="description">
                            <?php if ($type === 'new'): ?>
                                New student: filled in as the day the free first month ends.
                            <?php else: ?>
                                Current student: their usual due date this month. If it has already passed, choose today — they are charged when they add the bank or card.
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Sent to</th>
                    <td><?php echo esc_html($main['email']); ?><?php if ($main['guardian_name']): ?> — <?php echo esc_html($main['guardian_name']); ?><?php endif; ?>
                        <p class="description">To change the email, edit the enrollment record first.</p></td>
                </tr>
            </table>

            <?php if ($ready): ?>
                <p style="display:flex;gap:8px;flex-wrap:wrap">
                    <button type="submit" name="then" value="email" class="button button-primary">Create billing and send email</button>
                    <button type="submit" name="then" value="copy" class="button">Create billing and copy message</button>
                </p>
            <?php else: ?>
                <p><strong>Fix the rows above first</strong> (choose the course and press Recalculate), then the send buttons appear.</p>
            <?php endif; ?>
        </form>
        </div>
        <?php
    }

    public static function handle_create(): void
    {
        if (!current_user_can(self::CAP)) wp_die('You do not have permission to do this.', 403);
        check_admin_referer(self::A_CREATE);

        $ids     = self::ids_from($_POST);
        $courses = array_map('sanitize_key', (array) ($_POST['course'] ?? []));
        $days    = array_map('absint', (array) ($_POST['days'] ?? []));
        $date    = sanitize_text_field(wp_unslash($_POST['first_charge_date'] ?? ''));
        $then    = ($_POST['then'] ?? '') === 'copy' ? 'copy' : 'email';

        $r = IQU_Billing_Service::create_account($ids, ['first_charge_date' => $date, 'courses' => $courses, 'days' => $days]);
        if (!$r['ok']) {
            self::notice(['type' => 'error', 'items' => $r['errors']]);
            wp_safe_redirect(add_query_arg(['page' => self::SEND_SLUG, 'ids' => implode(',', $ids), 'course' => $courses, 'days' => $days], admin_url('admin.php')));
            exit;
        }

        $acc = IQU_Billing_DB::get_account($r['account_id']);
        if ($then === 'email') {
            $sent = self::send_email($acc);
            self::notice($sent ? ['type' => 'success', 'items' => ['Billing created and the email was sent to ' . $acc['contact_email'] . '. You can also send it on WhatsApp below.']]
                               : ['type' => 'warning', 'items' => ['Billing created, but the email was not sent. ' . (self::email_blocked_reason($acc) ?: 'Copy the message below and send it on WhatsApp, or try Resend email.')]]);
        } else {
            self::notice(['type' => 'success', 'items' => ['Billing created. Copy the message below and send it.']]);
        }
        self::to_message((int) $acc['id']);
    }

    // ------------------------------------------------------------
    // Message screen
    // ------------------------------------------------------------

    public static function render_message(): void
    {
        if (!current_user_can(self::CAP)) wp_die('You do not have permission to view this page.', 403);

        $acc = IQU_Billing_DB::get_account(absint($_GET['account'] ?? 0));
        $notice = get_transient(self::TX . get_current_user_id());
        if ($notice) delete_transient(self::TX . get_current_user_id());

        echo '<div class="wrap"><h1>Billing</h1>';
        IQU_Billing_Page::tabs('students');
        echo '<h2>Payment link message</h2>';
        if ($notice) self::print_notice($notice);
        if (!$acc) { echo '<p>Billing record not found.</p></div>'; return; }

        $students = self::students($acc);
        $text     = self::message_text($acc);
        $wa       = preg_replace('/\D/', '', (string) $acc['contact_whatsapp']);
        $wa_url   = $wa !== '' ? 'https://wa.me/' . $wa . '?text=' . rawurlencode($text) : '';
        $post     = esc_url(admin_url('admin-post.php'));
        ?>
        <table class="form-table" role="presentation" style="max-width:820px">
            <tr><th scope="row">Family</th><td><?php echo esc_html($acc['guardian_name'] ?: '—'); ?> · <?php echo esc_html($acc['contact_email']); ?> · <?php echo esc_html($acc['contact_whatsapp'] ?: 'no WhatsApp'); ?></td></tr>
            <tr><th scope="row">Students</th><td><?php echo esc_html(implode(', ', $students)); ?></td></tr>
            <tr><th scope="row">Monthly</th><td><strong><?php echo esc_html(IQU_Pricing::format((float) $acc['net_amount'])); ?></strong> · first charge <?php echo esc_html(self::nice_date($acc['first_charge_date'])); ?></td></tr>
            <tr><th scope="row">Status</th><td><?php echo esc_html(self::status_label($acc['status'])); ?>
                <br><span class="description">Email: <?php echo $acc['email_sent_at'] ? esc_html(self::nice_time($acc['email_sent_at'])) : 'not sent'; ?> · WhatsApp: <?php echo $acc['whatsapp_sent_at'] ? esc_html(self::nice_time($acc['whatsapp_sent_at'])) : 'not marked as sent'; ?></span></td></tr>
        </table>

        <h2>Message</h2>
        <label for="iqu-msg" class="screen-reader-text">Message</label>
        <textarea id="iqu-msg" readonly rows="16" class="large-text" style="max-width:820px;font-size:14px;line-height:1.5"><?php echo esc_textarea($text); ?></textarea>

        <p style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <button type="button" class="button button-primary" id="iqu-copy">Copy message</button>
            <?php if ($wa_url): ?><a class="button" href="<?php echo esc_url($wa_url); ?>" target="_blank" rel="noopener noreferrer">Open in WhatsApp</a><?php endif; ?>
            <span id="iqu-copied" style="display:none;color:#1d6b3a">Copied. Paste it into WhatsApp, SMS or Messenger.</span>
        </p>
        <?php if ($wa !== '' && strlen($wa) === 10): ?>
            <p class="description" style="color:#8a5a00">This number has no country code. For a US number add 1 in front in the enrollment record, or Open in WhatsApp may not find the chat.</p>
        <?php endif; ?>

        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
            <form method="post" action="<?php echo $post; ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::A_WA); ?>"><input type="hidden" name="account" value="<?php echo (int) $acc['id']; ?>">
                <?php wp_nonce_field(self::A_WA . '_' . $acc['id']); ?>
                <button class="button">Mark as sent on WhatsApp</button>
            </form>
            <form method="post" action="<?php echo $post; ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::A_EMAIL); ?>"><input type="hidden" name="account" value="<?php echo (int) $acc['id']; ?>">
                <?php wp_nonce_field(self::A_EMAIL . '_' . $acc['id']); ?>
                <button class="button"><?php echo $acc['email_sent_at'] ? 'Resend email' : 'Send email'; ?></button>
            </form>
            <form method="post" action="<?php echo $post; ?>" onsubmit="return confirm('The old link will stop working at once. Continue?');">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::A_RESET); ?>"><input type="hidden" name="account" value="<?php echo (int) $acc['id']; ?>">
                <?php wp_nonce_field(self::A_RESET . '_' . $acc['id']); ?>
                <button class="button">Reset link</button>
            </form>
            <form method="post" action="<?php echo $post; ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::A_SYNC); ?>"><input type="hidden" name="account" value="<?php echo (int) $acc['id']; ?>">
                <?php wp_nonce_field(self::A_SYNC . '_' . $acc['id']); ?>
                <button class="button">Sync from Stripe</button>
            </form>
        </div>
        <p class="description" style="max-width:820px">The link in this message is private to this family. Reset it only if it was shared with the wrong person; then send the new message.</p>
        </div>
        <script>
        (function () {
            var b = document.getElementById('iqu-copy'), t = document.getElementById('iqu-msg'), ok = document.getElementById('iqu-copied');
            b.addEventListener('click', function () {
                function done() { ok.style.display = 'inline'; }
                if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(t.value).then(done); }
                else { t.select(); document.execCommand('copy'); done(); }
            });
        })();
        </script>
        <?php
    }

    public static function handle_email(): void
    {
        $acc = self::account_for_action(self::A_EMAIL);
        $ok  = self::send_email($acc);
        $why = self::email_blocked_reason($acc);
        self::notice($ok ? ['type' => 'success', 'items' => ['Email sent to ' . $acc['contact_email'] . '.']] : ['type' => 'error', 'items' => [$why ?: 'The email could not be sent. Check the site email settings, or send the message on WhatsApp.']]);
        self::to_message((int) $acc['id']);
    }

    public static function handle_whatsapp(): void
    {
        $acc = self::account_for_action(self::A_WA);
        $upd = ['whatsapp_sent_at' => current_time('mysql', true)];
        if ($acc['status'] === 'not_sent') $upd['status'] = 'link_sent';
        IQU_Billing_DB::update_account((int) $acc['id'], $upd);
        self::notice(['type' => 'success', 'items' => ['Marked as sent on WhatsApp.']]);
        self::to_message((int) $acc['id']);
    }

    public static function handle_reset(): void
    {
        $acc = self::account_for_action(self::A_RESET);
        $ok  = IQU_Billing_Service::reset_link((int) $acc['id']);
        self::notice($ok ? ['type' => 'success', 'items' => ['New link created. The old link no longer works. Send the new message below.']] : ['type' => 'error', 'items' => ['Could not reset the link.']]);
        self::to_message((int) $acc['id']);
    }

    public static function handle_sync(): void
    {
        $acc = self::account_for_action(self::A_SYNC);
        $r   = IQU_Billing_Sync::sync($acc);
        self::notice($r['ok']
            ? ['type' => 'success', 'items' => ['Synced from Stripe. Status: ' . self::status_label($r['new']) . ($r['old'] !== $r['new'] ? ' (was: ' . self::status_label($r['old']) . ')' : '') . '.']]
            : ['type' => 'error', 'items' => ['Could not sync: ' . $r['error']]]);
        self::to_message((int) $acc['id']);
    }

    private static function account_for_action(string $action): array
    {
        if (!current_user_can(self::CAP)) wp_die('You do not have permission to do this.', 403);
        $id = absint($_POST['account'] ?? 0);
        check_admin_referer($action . '_' . $id);
        $acc = IQU_Billing_DB::get_account($id);
        if (!$acc) wp_die('Billing record not found.', 404);
        return $acc;
    }

    // ------------------------------------------------------------
    // Message text and email
    // ------------------------------------------------------------

    private static function students(array $acc): array
    {
        $out = [];
        foreach (IQU_Billing_DB::get_members((int) $acc['id']) as $m) {
            $reg = IQU_Database::get_registration((int) $m['registration_id']);
            if ($reg) $out[] = trim($reg['first_name'] . ' ' . $reg['last_name']);
        }
        return $out;
    }

    private static function first_names(array $acc): string
    {
        $names = [];
        foreach (IQU_Billing_DB::get_members((int) $acc['id']) as $m) {
            $reg = IQU_Database::get_registration((int) $m['registration_id']);
            if ($reg) $names[] = trim($reg['first_name']);
        }
        if (count($names) <= 1) return (string) ($names[0] ?? 'your child');
        $last = array_pop($names);
        return implode(', ', $names) . ' and ' . $last;
    }

    private static function greeting_name(array $acc): string
    {
        $g = trim((string) $acc['guardian_name']);
        if ($g !== '') return explode(' ', $g)[0];
        $students = self::students($acc);
        return $students ? explode(' ', $students[0])[0] : '';
    }

    private static function nice_date(?string $ymd): string
    {
        $ts = $ymd ? strtotime($ymd . ' 12:00:00 UTC') : 0;
        return $ts ? gmdate('j F Y', $ts) : '—';
    }

    private static function nice_time(string $mysql_utc): string
    {
        $ts = strtotime($mysql_utc . ' UTC');
        return $ts ? wp_date('M j, g:i A', $ts) : $mysql_utc;
    }

    /** Plain-text message for WhatsApp, SMS or Messenger. */
    public static function message_text(array $acc): string
    {
        $name   = self::greeting_name($acc);
        $kids   = self::first_names($acc);
        $amount = IQU_Pricing::format((float) $acc['net_amount']);
        $first  = self::nice_date($acc['first_charge_date']);
        $month  = $acc['first_charge_date'] ? gmdate('F', (int) strtotime($acc['first_charge_date'] . ' 12:00:00 UTC')) : '';
        $zelle  = (string) get_option(self::OPT_ZELLE, '');
        $new    = ($acc['student_type'] ?? '') === 'new';

        $lines = [];
        $lines[] = 'Assalamu alaikum' . ($name !== '' ? ' ' . $name : '') . ',';
        $lines[] = '';
        $lines[] = $new
            ? "Tuition for {$kids} will be collected automatically each month after the free first month, so there is nothing to remember and nothing to transfer."
            : "From this month, tuition for {$kids} will be collected automatically, so there is nothing to remember and nothing to transfer.";
        $lines[] = '';
        $lines[] = 'Monthly tuition: ' . $amount;
        $lines[] = 'First payment: ' . $first . ($new ? ' (after the free first month)' : ($month ? " ({$month} tuition)" : ''));
        $lines[] = '';
        $lines[] = 'Please add a bank account or card once, here:';
        $lines[] = IQU_Billing_Service::link_for($acc);
        $lines[] = '';
        $lines[] = 'Nothing is charged before ' . $first . '.';
        if ($zelle !== '') {
            $lines[] = 'From ' . self::nice_date($zelle) . ', tuition can no longer be sent by Zelle.';
        }
        $lines[] = '';
        $lines[] = 'If paying online is difficult, or the fee is a burden right now, just reply here. No child\'s place is ever affected by cost.';
        $lines[] = '';
        $lines[] = 'Jazakum Allahu khayran';
        $lines[] = 'Ilm-ul-Quran USA';
        return implode("\n", $lines);
    }

    /** In test mode, emails only go to our own addresses, never to real families. */
    public static function test_email_allowed(string $email): bool
    {
        $allowed = array_map('strtolower', array_filter([get_option('admin_email'), IQU_CONTACT_EMAIL]));
        return in_array(strtolower(trim($email)), $allowed, true);
    }

    /** Plain reason an email will not be sent, or ''. */
    public static function email_blocked_reason(array $acc): string
    {
        if (!is_email($acc['contact_email'])) return 'This family has no valid email address.';
        if (IQU_Stripe::expected_mode() === 'test' && !self::test_email_allowed($acc['contact_email'])) {
            return 'Test mode: emails only go to our own addresses, never to families. Nothing was sent.';
        }
        return '';
    }

    /** HTML email with the same content and a button. */
    public static function send_email(array $acc): bool
    {
        if (!is_email($acc['contact_email'])) return false;
        if (IQU_Stripe::expected_mode() === 'test' && !self::test_email_allowed($acc['contact_email'])) return false;

        $name   = self::greeting_name($acc);
        $kids   = self::first_names($acc);
        $amount = IQU_Pricing::format((float) $acc['net_amount']);
        $first  = self::nice_date($acc['first_charge_date']);
        $link   = IQU_Billing_Service::link_for($acc);
        $zelle  = (string) get_option(self::OPT_ZELLE, '');
        $new    = ($acc['student_type'] ?? '') === 'new';

        $subject = 'Set up monthly tuition for ' . $kids . ' — one time only';
        $intro   = $new
            ? "Tuition for {$kids} will be collected automatically each month after the free first month, so there is nothing to remember and nothing to transfer."
            : "From this month, tuition for {$kids} will be collected automatically, so there is nothing to remember and nothing to transfer.";

        $p = 'style="margin:0 0 14px;font-size:15px;line-height:23px;color:#1c2b24"';
        $body  = '<div style="background:#f2f1ec;padding:24px 12px;font-family:Arial,Helvetica,sans-serif">';
        $body .= '<div style="max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #dcd7cb;border-radius:12px;padding:28px 30px">';
        $body .= '<div style="font-size:20px;font-weight:bold;color:#1c2b24;margin-bottom:18px">Ilm-ul-Quran USA</div>';
        $body .= '<p ' . $p . '>Assalamu alaikum' . ($name !== '' ? ' ' . esc_html($name) : '') . ',</p>';
        $body .= '<p ' . $p . '>' . esc_html($intro) . '</p>';
        $body .= '<table role="presentation" style="width:100%;background:#f7f5f0;border-radius:10px;margin:0 0 20px;font-size:14px;line-height:24px"><tr><td style="padding:14px 16px">';
        $body .= 'Monthly tuition: <strong>' . esc_html($amount) . '</strong><br>First payment: <strong>' . esc_html($first) . '</strong>' . ($new ? ' (after the free first month)' : '');
        $body .= '</td></tr></table>';
        $body .= '<p style="margin:0 0 18px;text-align:center"><a href="' . esc_url($link) . '" style="display:inline-block;background:#1e4d6b;color:#ffffff;text-decoration:none;font-weight:bold;font-size:16px;padding:14px 26px;border-radius:8px">Set up monthly tuition</a></p>';
        $body .= '<p style="margin:0 0 12px;font-size:13px;line-height:20px;color:#55615a">You add a bank account or card once. Nothing is charged before ' . esc_html($first) . '.';
        if ($zelle !== '') $body .= ' From ' . esc_html(self::nice_date($zelle)) . ', tuition can no longer be sent by Zelle.';
        $body .= '</p>';
        $body .= '<p style="margin:0 0 18px;font-size:13px;line-height:20px;color:#55615a">If paying online is difficult, or the fee is a burden right now, just reply to this email. No child\'s place is ever affected by cost.</p>';
        $body .= '<p ' . $p . '>Jazakum Allahu khayran,<br>Ilm-ul-Quran USA</p>';
        $body .= '<div style="border-top:1px solid #ece8de;padding-top:12px;font-size:12px;line-height:18px;color:#55615a">This link is private to your family. Please do not forward it. Ilm-ul-Quran USA is operated by AL HASANAH FOUNDATION, a 501(c)(3) nonprofit.</div>';
        $body .= '</div></div>';

        $ok = wp_mail($acc['contact_email'], $subject, $body, ['Content-Type: text/html; charset=UTF-8']);
        if ($ok) {
            $upd = ['email_sent_at' => current_time('mysql', true)];
            if ($acc['status'] === 'not_sent') $upd['status'] = 'link_sent';
            IQU_Billing_DB::update_account((int) $acc['id'], $upd);
        }
        return (bool) $ok;
    }

    // ------------------------------------------------------------
    // Notices and redirects
    // ------------------------------------------------------------

    private static function notice(array $n): void
    {
        set_transient(self::TX . get_current_user_id(), $n, 120);
    }

    private static function print_notice(array $n): void
    {
        $type = in_array($n['type'] ?? '', ['success', 'error', 'warning'], true) ? $n['type'] : 'info';
        echo '<div class="notice notice-' . esc_attr($type) . '"><p>' . implode('<br>', array_map('esc_html', (array) ($n['items'] ?? []))) . '</p></div>';
    }

    private static function to_message(int $account_id): void
    {
        wp_safe_redirect(admin_url('admin.php?page=' . self::MSG_SLUG . '&account=' . $account_id));
        exit;
    }
}
