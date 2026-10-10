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

        echo '<div class="wrap iqu-admin-wrap iqu-billing">';
        IQU_Billing_Page::tabs('students');
        if ($notice) self::print_notice($notice);

        if (!IQU_Stripe::is_ready()) {
            echo '<div class="notice notice-error"><p>' . esc_html(IQU_Stripe::not_ready_reason()) . '</p></div></div>';
            return;
        }
        if (!$ids) {
            echo '<div class="iqu-card"><div class="iqu-empty-state">No student selected. Use the buttons on the Enroll for Free list.</div></div></div>';
            return;
        }

        $main = IQU_Database::get_registration($ids[0]);
        if (!$main) { echo '<div class="iqu-card"><div class="iqu-empty-state">Student not found.</div></div></div>'; return; }

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
        <!-- ── Check ──────────────────────────────────────── -->
        <div class="iqu-card">
            <div class="iqu-card-head">
                <span class="iqu-card-head-title">Check and send</span>
            </div>
            <div class="iqu-billing-body">
                <p class="iqu-billing-intro">Everything here is worked out on the server from the pricing rules. Nothing is charged today.</p>
            </div>

            <form method="get" action="<?php echo esc_url($base); ?>">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::SEND_SLUG); ?>">
                <input type="hidden" name="then" value="<?php echo esc_attr($then); ?>">

                <div class="iqu-table-wrap">
                <table class="iqu-tbl iqu-billing-tbl">
                    <thead><tr><th>Student</th><th>Course</th><th>Standard</th><th>Discount</th><th>Monthly</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): $reg = $r['reg']; $p = $r['p']; $id = (int) $reg['id']; ?>
                        <tr>
                            <td class="iqu-td-name"><strong><?php echo esc_html($reg['first_name'] . ' ' . $reg['last_name']); ?></strong><br><span class="iqu-billing-sub">IQU-<?php echo $id; ?> · <?php echo esc_html(IQU_Billing_Service::student_type($reg) === 'new' ? 'new student' : 'current student'); ?></span>
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
                                        <br><span class="iqu-billing-sub"><?php echo (int) $reg['days_per_week']; ?> days/week (from the record)</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php echo esc_html($p['course_label']); ?><br><span class="iqu-billing-sub"><?php echo (int) $p['days_per_week']; ?> days/week · <?php echo (int) $p['classes_per_month']; ?> classes × <?php echo esc_html(IQU_Pricing::format($p['per_class_rate'])); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $p['gross'] > 0 ? esc_html(IQU_Pricing::format($p['gross'])) : '—'; ?></td>
                            <td><?php echo $p['discount'] > 0 ? esc_html('−' . IQU_Pricing::format($p['discount']) . ' ' . $p['discount_label']) : '—'; ?></td>
                            <td><strong><?php echo $p['billable'] ? esc_html(IQU_Pricing::format($p['net'])) : '—'; ?></strong></td>
                            <td class="iqu-billing-wrap">
                                <?php if ($r['existing']): ?><span class="iqu-billing-error">Already has billing.</span>
                                <?php elseif ($p['needs_input']): ?><span class="iqu-billing-warn"><?php echo esc_html($p['reason']); ?></span>
                                <?php elseif (!$p['billable']): ?><span><?php echo esc_html($p['reason']); ?></span><?php endif; ?>
                                <?php foreach ($p['warnings'] as $w): ?><br><span class="iqu-billing-sub iqu-billing-warn"><?php echo esc_html($w); ?></span><?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot><tr><th colspan="4" class="iqu-billing-total-label">Charged every month</th><th colspan="2"><strong><?php echo esc_html(IQU_Pricing::format($total)); ?></strong></th></tr></tfoot>
                </table>
                </div>

                <?php if ($siblings): ?>
                    <div class="iqu-billing-section">
                        <div class="iqu-section-title">Same family?</div>
                        <p>These students use the same email. Tick to bill them together: one charge a month for the whole family.</p>
                        <?php foreach ($siblings as $s): $sid = (int) $s['id']; ?>
                            <label class="iqu-billing-check-label"><input type="checkbox" name="ids[]" value="<?php echo $sid; ?>" <?php checked(in_array($sid, $ids, true)); ?>>
                                <?php echo esc_html($s['first_name'] . ' ' . $s['last_name']); ?> (IQU-<?php echo $sid; ?>)</label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="iqu-cpn-actions"><?php submit_button('Recalculate', 'secondary', '', false); ?></div>
            </form>
        </div>

        <!-- ── Create ─────────────────────────────────────── -->
        <div class="iqu-card">
            <div class="iqu-card-head">
                <span class="iqu-card-head-title">Create billing</span>
                <span class="iqu-card-head-badge"><?php echo $type === 'new' ? 'New student' : 'Current student'; ?></span>
            </div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="iqu-cpn-form">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::A_CREATE); ?>">
                <?php wp_nonce_field(self::A_CREATE); ?>
                <?php foreach ($ids as $id): ?><input type="hidden" name="ids[]" value="<?php echo (int) $id; ?>"><?php endforeach; ?>
                <?php foreach ($courses as $id => $c): ?><input type="hidden" name="course[<?php echo (int) $id; ?>]" value="<?php echo esc_attr($c); ?>"><?php endforeach; ?>
                <?php foreach ($days as $id => $d): ?><input type="hidden" name="days[<?php echo (int) $id; ?>]" value="<?php echo (int) $d; ?>"><?php endforeach; ?>

                <div class="iqu-fld-grid">
                    <div class="iqu-fld">
                        <label for="first_charge_date">First charge <em>*</em></label>
                        <input id="first_charge_date" type="date" name="first_charge_date" required value="<?php echo esc_attr((string) $suggest); ?>" min="<?php echo esc_attr(gmdate('Y-m-d')); ?>" max="<?php echo esc_attr(gmdate('Y-m-d', time() + 45 * DAY_IN_SECONDS)); ?>">
                        <p class="iqu-fld-hint">
                            <?php if ($type === 'new'): ?>
                                New student: filled in as the day the free first month ends.
                            <?php else: ?>
                                Current student: their usual due date this month. If it has already passed, choose today — they are charged when they add the bank or card.
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="iqu-fld">
                        <span class="iqu-fld-label">Sent to</span>
                        <div class="iqu-fld-value"><?php echo esc_html($main['email']); ?><?php if ($main['guardian_name']): ?> — <?php echo esc_html($main['guardian_name']); ?><?php endif; ?></div>
                        <p class="iqu-fld-hint">To change the email, edit the enrollment record first.</p>
                    </div>
                </div>

                <div class="iqu-cpn-actions">
                    <?php if ($ready): ?>
                        <button type="submit" name="then" value="email" class="iqu-btn-primary">Create billing and send email</button>
                        <button type="submit" name="then" value="copy" class="iqu-btn-ghost">Create billing and copy message</button>
                    <?php else: ?>
                        <p><strong>Fix the rows above first</strong> (choose the course and press Recalculate), then the send buttons appear.</p>
                    <?php endif; ?>
                </div>
            </form>
        </div>
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

        echo '<div class="wrap iqu-admin-wrap iqu-billing">';
        IQU_Billing_Page::tabs('students');
        echo '<p class="iqu-back-row"><a href="' . esc_url(IQU_Billing_Page::list_url_from_referer()) . '" class="iqu-back-btn">← Back to Student Billing</a></p>';
        if ($notice) self::print_notice($notice);
        if (!$acc) { echo '<div class="iqu-card"><div class="iqu-empty-state">Billing record not found.</div></div></div>'; return; }

        $students = self::students($acc);
        $text     = self::message_text($acc);
        $kind     = ['setup' => ['Set-up message', 'gold'], 'account' => ['Billing page message', 'green'], 'problem' => ['Payment problem message', 'red'], 'stopped' => ['Billing stopped message', 'neutral']][self::message_kind($acc)];
        $wa       = preg_replace('/\D/', '', (string) $acc['contact_whatsapp']);
        $wa_url   = $wa !== '' ? 'https://wa.me/' . $wa . '?text=' . rawurlencode($text) : '';
        $post     = esc_url(admin_url('admin-post.php'));
        ?>
        <?php
        $history = IQU_Billing_History::for_account((int) $acc['id']);
        self::family_header($acc, $students);
        self::family_students($acc);
        ?>

        <!-- ── Message ────────────────────────────────────── -->
        <div class="iqu-card">
            <div class="iqu-card-head">
                <span class="iqu-card-head-title">Message</span>
                <span class="iqu-card-head-badge">WhatsApp, SMS or Messenger</span>
            </div>
            <div class="iqu-billing-body">
                <div class="iqu-fld iqu-billing-msg">
                    <span class="iqu-fld-label"><span class="iqu-chip iqu-chip--<?php echo $kind[1]; ?>"><?php echo esc_html($kind[0]); ?></span></span>
                    <label for="iqu-msg" class="screen-reader-text">Message</label>
                    <textarea id="iqu-msg" readonly rows="16"><?php echo esc_textarea($text); ?></textarea>
                </div>

                <div class="iqu-billing-msg-tools">
                    <button type="button" class="iqu-btn-primary" id="iqu-copy">Copy message</button>
                    <?php if ($wa_url): ?><a class="iqu-btn-ghost" href="<?php echo esc_url($wa_url); ?>" target="_blank" rel="noopener noreferrer">Open in WhatsApp</a><?php endif; ?>
                    <span id="iqu-copied" class="iqu-billing-copied">Copied. Paste it into WhatsApp, SMS or Messenger.</span>
                </div>
                <?php if ($wa !== '' && strlen($wa) === 10): ?>
                    <p class="iqu-fld-hint iqu-billing-warn">This number has no country code. For a US number add 1 in front in the enrollment record, or Open in WhatsApp may not find the chat.</p>
                <?php endif; ?>
            </div>

            <div class="iqu-cpn-actions">
                <form method="post" action="<?php echo $post; ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::A_WA); ?>"><input type="hidden" name="account" value="<?php echo (int) $acc['id']; ?>">
                    <?php wp_nonce_field(self::A_WA . '_' . $acc['id']); ?>
                    <button class="iqu-btn-ghost">Mark as sent on WhatsApp</button>
                </form>
                <form method="post" action="<?php echo $post; ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::A_EMAIL); ?>"><input type="hidden" name="account" value="<?php echo (int) $acc['id']; ?>">
                    <?php wp_nonce_field(self::A_EMAIL . '_' . $acc['id']); ?>
                    <button class="iqu-btn-ghost"><?php echo $acc['email_sent_at'] ? 'Resend email' : 'Send email'; ?></button>
                </form>
                <form method="post" action="<?php echo $post; ?>" onsubmit="return confirm('The old link will stop working at once. Continue?');">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::A_RESET); ?>"><input type="hidden" name="account" value="<?php echo (int) $acc['id']; ?>">
                    <?php wp_nonce_field(self::A_RESET . '_' . $acc['id']); ?>
                    <button class="iqu-btn-ghost">Reset link</button>
                </form>
                <form method="post" action="<?php echo $post; ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::A_SYNC); ?>"><input type="hidden" name="account" value="<?php echo (int) $acc['id']; ?>">
                    <?php wp_nonce_field(self::A_SYNC . '_' . $acc['id']); ?>
                    <button class="iqu-btn-ghost">Sync from Stripe</button>
                </form>
            </div>
            <div class="iqu-billing-body">
                <p class="iqu-fld-hint">The link in this message is private to this family. Reset it only if it was shared with the wrong person; then send the new message.</p>
            </div>
        </div>
        <?php
        self::family_history($history);
        self::family_activity((int) $acc['id'], $history);
        ?>
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

    // ------------------------------------------------------------
    // Family page sections (Message screen)
    // ------------------------------------------------------------

    /** 1. Header: name, avatar, status, quick facts, link to Stripe. */
    private static function family_header(array $acc, array $students): void
    {
        $name   = trim((string) $acc['guardian_name']) ?: implode(', ', $students);
        $next   = !empty($acc['next_charge_at']) ? self::nice_utc((string) $acc['next_charge_at']) : ($acc['first_charge_date'] ? self::nice_date($acc['first_charge_date']) . ' (first charge)' : '—');
        $stripe = !empty($acc['stripe_customer_id'])
            ? 'https://dashboard.stripe.com/' . (IQU_Stripe::expected_mode() === 'test' ? 'test/' : '') . 'customers/' . rawurlencode((string) $acc['stripe_customer_id'])
            : '';
        ?>
        <!-- ── Family ─────────────────────────────────────── -->
        <div class="iqu-card">
            <div class="iqu-card-head iqu-family-head">
                <span class="iqu-family-cell">
                    <span class="iqu-avatar" aria-hidden="true"><?php echo esc_html(IQU_Billing_Page::initials($name)); ?></span>
                    <span>
                        <span class="iqu-profile-name"><?php echo esc_html($name ?: '—'); ?></span>
                        <span class="iqu-profile-meta"><?php echo esc_html($acc['contact_email']); ?> · <?php echo esc_html($acc['contact_whatsapp'] ?: 'no WhatsApp'); ?></span>
                    </span>
                </span>
                <span class="iqu-family-head-right">
                    <span class="iqu-chip iqu-chip--<?php echo esc_attr(IQU_Billing_Page::status_tone((string) $acc['status'])); ?>"><?php echo esc_html(self::status_label((string) $acc['status'])); ?></span>
                    <?php if ($stripe): ?><a class="iqu-action-btn" href="<?php echo esc_url($stripe); ?>" target="_blank" rel="noopener noreferrer">View in Stripe</a><?php endif; ?>
                </span>
            </div>
            <div class="iqu-billing-body">
                <div class="iqu-detail-data">
                    <div class="iqu-detail-item">
                        <div class="iqu-detail-lbl">Monthly</div>
                        <div class="iqu-detail-val"><strong><?php echo esc_html(IQU_Pricing::format((float) $acc['net_amount'])); ?></strong><?php echo (float) $acc['discount_amount'] > 0 ? ' · ' . esc_html(IQU_Pricing::format((float) $acc['discount_amount'])) . ' discount' : ''; ?></div>
                    </div>
                    <div class="iqu-detail-item">
                        <div class="iqu-detail-lbl">Next charge</div>
                        <div class="iqu-detail-val"><?php echo esc_html($next); ?></div>
                    </div>
                    <div class="iqu-detail-item">
                        <div class="iqu-detail-lbl">Paying from</div>
                        <div class="iqu-detail-val"><?php echo esc_html($acc['payment_method_label'] ?: 'No bank or card yet'); ?></div>
                    </div>
                    <div class="iqu-detail-item">
                        <div class="iqu-detail-lbl">Link sent</div>
                        <div class="iqu-detail-val">Email: <?php echo $acc['email_sent_at'] ? esc_html(self::nice_time($acc['email_sent_at'])) : 'not sent'; ?> · WhatsApp: <?php echo $acc['whatsapp_sent_at'] ? esc_html(self::nice_time($acc['whatsapp_sent_at'])) : 'not marked as sent'; ?></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /** 2. Students: one row per student. */
    private static function family_students(array $acc): void
    {
        $rows = [];
        foreach (IQU_Billing_DB::get_members((int) $acc['id']) as $m) {
            $reg = IQU_Database::get_registration((int) $m['registration_id']);
            if ($reg) $rows[] = [$reg, IQU_Billing_Pricing::for_registration($reg), (float) $m['amount']];
        }
        ?>
        <div class="iqu-card">
            <div class="iqu-card-head">
                <span class="iqu-card-head-title">Students</span>
                <span class="iqu-card-head-badge"><?php echo (int) count($rows); ?> <?php echo count($rows) === 1 ? 'student' : 'students'; ?></span>
            </div>
            <?php if (!$rows): ?>
                <div class="iqu-empty-state">No students on this billing record.</div>
            <?php else: ?>
            <div class="iqu-table-wrap">
            <table class="iqu-tbl iqu-dt iqu-billing-tbl">
                <thead><tr><th>Student</th><th>IQU no</th><th>Course</th><th class="is-num">Days/week</th><th class="is-num">Monthly</th><th>Discount / Zakat</th></tr></thead>
                <tbody>
                <?php foreach ($rows as [$reg, $p, $amount]): ?>
                    <tr>
                        <td class="iqu-td-name"><strong><?php echo esc_html(trim($reg['first_name'] . ' ' . $reg['last_name'])); ?></strong></td>
                        <td><a href="<?php echo esc_url(admin_url('admin.php?page=iqu-view-registration&id=' . (int) $reg['id'])); ?>">IQU-<?php echo (int) $reg['id']; ?></a></td>
                        <td><?php echo esc_html($p['course_label'] ?: '—'); ?></td>
                        <td class="is-num"><?php echo (int) $p['days_per_week'] ?: '—'; ?></td>
                        <td class="is-num"><?php echo esc_html(IQU_Pricing::format($amount)); ?></td>
                        <td class="iqu-billing-wrap"><?php echo (float) $p['discount'] > 0 ? esc_html(($p['discount_label'] ?: 'Discount') . ' −' . IQU_Pricing::format((float) $p['discount'])) : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="4">Charged together each month</td><td class="is-num"><?php echo esc_html(IQU_Pricing::format((float) $acc['net_amount'])); ?></td><td></td></tr></tfoot>
            </table>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /** 4. Payment history: every tuition month, newest first, with yearly subtotals. */
    private static function family_history(array $history): void
    {
        $years = [];
        foreach ($history as $r) $years[substr((string) $r['period_month'], 0, 4) ?: '—'][] = $r;
        ?>
        <div class="iqu-card">
            <div class="iqu-card-head">
                <span class="iqu-card-head-title">Payment history</span>
                <span class="iqu-card-head-badge"><?php echo (int) count($history); ?> <?php echo count($history) === 1 ? 'invoice' : 'invoices'; ?></span>
            </div>
            <?php if (!$history): ?>
                <div class="iqu-empty-state">No payments stored for this family yet. New payments appear here as Stripe reports them; to load earlier months, use <a href="<?php echo esc_url(admin_url('admin.php?page=iqu-billing-settings')); ?>">Settings → Backfill payment history</a> (or Sync from Stripe above).</div>
            <?php else: ?>
            <div class="iqu-table-wrap">
            <table class="iqu-tbl iqu-dt iqu-billing-tbl">
                <thead><tr><th>Tuition month</th><th class="is-num">Amount due</th><th class="is-num">Amount paid</th><th>Status</th><th>Paid on</th><th>Method</th><th>Receipt</th><th>Attempts</th></tr></thead>
                <tbody>
                <?php foreach ($years as $year => $rows):
                    $due = 0.0; $paid = 0.0; ?>
                    <?php foreach ($rows as $r):
                        [$blabel, $btone] = IQU_Billing_History::badge($r);
                        if ($r['status'] !== 'void') $due += (float) $r['amount_due'];
                        if ($r['status'] === 'paid') $paid += (float) $r['amount_paid'];
                        $receipt = IQU_Billing_History::safe_url($r['hosted_invoice_url']);
                        $pdf     = IQU_Billing_History::safe_url($r['invoice_pdf']); ?>
                        <tr>
                            <td><?php echo esc_html(IQU_Billing_History::month_label((string) $r['period_month'])); ?></td>
                            <td class="is-num"><?php echo esc_html(IQU_Pricing::format((float) $r['amount_due'])); ?></td>
                            <td class="is-num"><?php echo esc_html(IQU_Pricing::format((float) $r['amount_paid'])); ?><?php if ((float) $r['amount_refunded'] > 0): ?><br><span class="iqu-billing-sub">Refunded <?php echo esc_html(IQU_Pricing::format((float) $r['amount_refunded'])); ?></span><?php endif; ?></td>
                            <td><span class="iqu-chip iqu-chip--<?php echo esc_attr($btone); ?>"><?php echo esc_html($blabel); ?></span></td>
                            <td class="iqu-td-date"><?php echo $r['paid_at'] ? esc_html(self::nice_time((string) $r['paid_at'])) : '—'; ?></td>
                            <td><?php echo esc_html($r['method_label'] ?: '—'); ?></td>
                            <td><?php if ($receipt): ?><a href="<?php echo esc_url($receipt); ?>" target="_blank" rel="noopener noreferrer">Receipt</a><?php endif; ?><?php if ($receipt && $pdf): ?> · <?php endif; ?><?php if ($pdf): ?><a href="<?php echo esc_url($pdf); ?>" target="_blank" rel="noopener noreferrer">Invoice PDF</a><?php endif; ?><?php echo (!$receipt && !$pdf) ? '—' : ''; ?></td>
                            <td class="iqu-billing-wrap"><?php echo (int) $r['attempt_count']; ?><?php if ($r['failure_reason'] !== ''): ?><br><span class="iqu-billing-sub"><?php echo esc_html($r['failure_reason']); ?></span><?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="iqu-dt-subtotal"><td><?php echo esc_html($year); ?> total</td><td class="is-num"><?php echo esc_html(IQU_Pricing::format($due)); ?></td><td class="is-num"><?php echo esc_html(IQU_Pricing::format($paid)); ?></td><td colspan="5"></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /** Plain-English line for a webhook event. */
    private static function event_text(array $e, array $history): string
    {
        $map = [
            'checkout.session.completed'      => 'Bank or card added on the Stripe page',
            'customer.subscription.created'   => 'Monthly billing set up',
            'customer.subscription.updated'   => 'Billing updated',
            'customer.subscription.deleted'   => 'Billing stopped',
            'customer.subscription.paused'    => 'Billing paused',
            'customer.subscription.resumed'   => 'Billing resumed',
            'invoice.paid'                    => 'Payment received',
            'invoice.payment_failed'          => 'Payment failed',
            'invoice.payment_action_required' => 'The bank asked the family to confirm a payment',
            'charge.refunded'                 => 'Refund issued',
        ];
        $text = $map[$e['type']] ?? (string) $e['type'];
        if ($e['type'] === 'invoice.payment_failed') {
            $at = (int) strtotime($e['received_at'] . ' UTC');
            foreach ($history as $r) {
                if ($r['failure_reason'] !== '' && abs((int) strtotime((string) $r['updated_at'] . ' UTC') - $at) < 900) {
                    $text .= ' — ' . lcfirst(rtrim((string) $r['failure_reason'], '.'));
                    break;
                }
            }
        }
        if (preg_match('/^(\w+) -> (\w+)$/', (string) $e['note'], $m) && $m[1] !== $m[2]) {
            $text .= ' (' . self::status_label($m[1]) . ' → ' . self::status_label($m[2]) . ')';
        }
        if (!in_array($e['result'], ['ok', 'received'], true)) $text .= ' [' . $e['result'] . ']';
        return $text;
    }

    /** 5. Activity: this family's webhook events, newest first (last 50). */
    private static function family_activity(int $account_id, array $history): void
    {
        $events = IQU_Billing_History::events_for_account($account_id, 50);
        ?>
        <div class="iqu-card">
            <div class="iqu-card-head">
                <span class="iqu-card-head-title">Activity</span>
                <span class="iqu-card-head-badge">From Stripe, newest first</span>
            </div>
            <?php if (!$events): ?>
                <div class="iqu-empty-state">No Stripe activity yet. Card added, payments and failures appear here as they happen.</div>
            <?php else: ?>
            <div class="iqu-table-wrap">
            <table class="iqu-tbl iqu-dt iqu-billing-tbl">
                <thead><tr><th>When</th><th>What happened</th></tr></thead>
                <tbody>
                <?php foreach ($events as $e): ?>
                    <tr><td class="iqu-td-date"><?php echo esc_html(self::nice_time((string) $e['received_at'])); ?></td><td class="iqu-billing-wrap"><?php echo esc_html(self::event_text($e, $history)); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
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
        $n   = $r['ok'] ? IQU_Billing_History::refresh_account($r['account']) : null;
        self::notice($r['ok']
            ? ['type' => 'success', 'items' => ['Synced from Stripe. Status: ' . self::status_label($r['new']) . ($r['old'] !== $r['new'] ? ' (was: ' . self::status_label($r['old']) . ')' : '') . '.'
                . ($n === null ? ' Payment history could not be read.' : ' Payment history: ' . $n . ' invoices.')]]
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

    /** "5 November 2026" from a UTC DATETIME such as next_charge_at. */
    private static function nice_utc(string $mysql_utc): string
    {
        $ts = strtotime($mysql_utc . ' UTC');
        return $ts ? gmdate('j F Y', $ts) : '—';
    }

    /**
     * Which message the family gets: 'setup' (no bank or card yet), 'problem' (a payment failed),
     * 'stopped' (billing canceled) or 'account' (link to the billing page).
     */
    private static function message_kind(array $acc): string
    {
        $status = (string) $acc['status'];
        // Same rule as $is_setup on the family page (IQU_Billing_Portal::render_account).
        if (in_array($status, ['not_sent', 'link_sent'], true) && empty($acc['stripe_subscription_id'])) return 'setup';
        if (in_array($status, ['past_due', 'unpaid', 'paused'], true)) return 'problem';
        if ($status === 'canceled') return 'stopped';
        return 'account';
    }

    /**
     * Plain-text message for WhatsApp, SMS or Messenger, by kind (see message_kind()):
     * setup, account, problem or stopped. The "Need help?" lines come from IQU_Contact
     * (constants only; a missing one is left out).
     */
    public static function message_text(array $acc): string
    {
        $name   = self::greeting_name($acc);
        $kids   = self::first_names($acc);
        $amount = IQU_Pricing::format((float) $acc['net_amount']);
        $link   = IQU_Billing_Service::link_for($acc);
        $kind   = self::message_kind($acc);

        $p = []; // paragraphs, joined by a blank line
        $p[] = 'Assalamu alaikum' . ($name !== '' ? ' ' . $name : '') . ',';

        if ($kind === 'setup') {
            $first = self::nice_date($acc['first_charge_date']);
            $month = $acc['first_charge_date'] ? gmdate('F', (int) strtotime($acc['first_charge_date'] . ' 12:00:00 UTC')) : '';
            $zelle = (string) get_option(self::OPT_ZELLE, '');
            $free  = IQU_Billing_Service::has_free_first_month($acc);
            $p[] = $free
                ? "Welcome to Ilm-ul-Quran USA! The first month of classes is free. After that, tuition for {$kids} will be collected automatically each month, so there is nothing to remember and nothing to transfer."
                : "Starting this month, tuition for {$kids} will be collected automatically each month, so there is nothing to remember and nothing to transfer.";
            $p[] = "Monthly tuition: {$amount}\n" . ($free ? "First month: free\n" : '') . "First payment: {$first}" . ($month !== '' ? " ({$month} tuition)" : '');
            $p[] = "Please add a US bank account or card once, using your family's private link:\n{$link}";
            $p[] = "Nothing is charged before {$first}." . ($zelle !== '' ? "\nFrom " . self::nice_date($zelle) . ', tuition can no longer be sent by Zelle.' : '');
            $p[] = 'If paying online is difficult, or the fee is a burden right now, please let us know. No child\'s place is ever affected by cost.';
        } elseif ($kind === 'account') {
            $p[] = "Here is your family's private billing page for {$kids}:\n{$link}";
            $p[] = 'On this page you can see every payment, download receipts, and change your bank account or card at any time.';
            $facts = "Monthly tuition: {$amount}";
            if (!empty($acc['next_charge_at']) && $acc['status'] !== 'canceled') $facts .= "\nNext payment: " . self::nice_utc((string) $acc['next_charge_at']);
            $p[] = $facts;
        } elseif ($kind === 'problem') {
            $p[] = "We wanted to let you know that the tuition payment of {$amount} for {$kids} did not go through.";
            $p[] = ($acc['status'] === 'past_due'
                ? 'We will try again automatically. You can also pay now, or switch to a different bank account or card, here:'
                : 'You can pay it, or switch to a different bank account or card, on your private billing page:') . "\n{$link}";
            $p[] = 'If paying is difficult right now, or the fee is a burden, please reply and we will sort it out together. No child\'s place is ever affected by cost.';
        } else { // stopped
            $p[] = "Monthly tuition billing for {$kids} has now stopped, and nothing more will be charged.";
            $p[] = "You can still see your past payments and receipts here:\n{$link}";
            $p[] = 'If you would like to restart classes, or if this was a mistake, please contact us.';
        }

        $help = IQU_Contact::text_block();
        if ($help !== '') $p[] = $help;
        $p[] = "Jazakum Allahu khayran,\nIlm-ul-Quran USA";
        return implode("\n\n", $p);
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

    /**
     * Subject and content of the family email for one kind (see message_kind()), in the shared
     * formal layout (IQU_Billing_Email). Local database only.
     * @return array{0:string, 1:array} [subject, IQU_Billing_Email::render() options]
     */
    public static function email_content(array $acc): array
    {
        $name   = self::greeting_name($acc);
        $kids   = self::first_names($acc);
        $amount = IQU_Pricing::format((float) $acc['net_amount']);
        $link   = IQU_Billing_Service::link_for($acc);
        $kind   = self::message_kind($acc);
        $method = trim((string) ($acc['payment_method_label'] ?? ''));
        $rows   = IQU_Billing_Email::student_rows($acc);
        $base   = ['greeting' => 'Assalamu alaikum' . ($name !== '' ? ' ' . $name : '') . ',', 'private' => true];
        $burden = 'If paying online is difficult, or the fee is a burden right now, please talk to us — no child\'s place is ever affected by cost.';

        if ($kind === 'setup') {
            $first = self::nice_date($acc['first_charge_date']);
            $ts    = $acc['first_charge_date'] ? (int) strtotime($acc['first_charge_date'] . ' 12:00:00 UTC') : 0;
            $zelle = (string) get_option(self::OPT_ZELLE, '');
            $free  = IQU_Billing_Service::has_free_first_month($acc);
            $table = array_merge($rows, IQU_Billing_Email::tuition_rows($acc), $free ? [['First month', 'Free']] : [], [
                ['First payment', $first . ($ts ? ' (' . gmdate('F', $ts) . ' tuition)' : '')],
                ['After that', $ts ? $amount . ' on the ' . IQU_Billing_Email::ordinal((int) gmdate('j', $ts)) . ' of each month' : ''],
                ['Pay with', 'US bank account (ACH) or card — your choice'],
            ]);
            return ["Action needed: set up {$kids}'s monthly tuition — Ilm-ul-Quran USA", $base + [
                'title'     => 'Monthly tuition set-up',
                'preheader' => "Add a US bank account or card once. Nothing is charged before {$first}.",
                'help'      => $burden,
                'blocks'    => [
                    ['p', 'We hope you and your family are well.'],
                    ['p', $free
                        ? "Welcome to Ilm-ul-Quran USA! The first month of classes is free. After that, tuition for {$kids} will be collected automatically each month, so there is nothing to remember and nothing to transfer."
                        : "Starting this month, tuition for {$kids} will be collected automatically each month — no reminders and no transfers."],
                    ['table', $table],
                    ['steps', 'What you need to do', [
                        'Press "Set up monthly tuition" below.',
                        'Add a US bank account or card on Stripe\'s secure page (about 2 minutes).',
                        'That\'s all — you will receive a receipt by email after every payment.',
                    ]],
                    ['button', 'Set up monthly tuition', $link],
                    ['list', 'Good to know', [
                        $free ? "Your first month is free — nothing is charged before {$first}." : "Nothing is charged before {$first}.",
                        'You can change your bank account or card at any time from your private billing page.',
                        'To pause or stop classes, please tell us at least 7 days before the next payment.',
                        $zelle !== '' ? 'From ' . self::nice_date($zelle) . ', tuition can no longer be sent by Zelle.' : '',
                    ]],
                ],
            ]];
        }

        if ($kind === 'problem') {
            $past_due = $acc['status'] === 'past_due';
            return ["Action needed: {$kids}'s tuition payment did not go through — Ilm-ul-Quran USA", $base + [
                'title'     => 'Tuition payment did not go through',
                'preheader' => "The tuition payment of {$amount} for {$kids} did not go through.",
                'help'      => 'If paying is difficult right now, or the fee is a burden, please reply and we will sort it out together. No child\'s place is ever affected by cost.',
                'blocks'    => [
                    ['p', "We wanted to let you know that the tuition payment of {$amount} for {$kids} did not go through."],
                    ['table', array_merge($rows, [
                        ['Amount', $amount],
                        ['Paying with', $method],
                        ['Status', $past_due ? 'Not paid yet — we will try again automatically' : 'Not paid yet'],
                    ])],
                    ['steps', 'What you need to do', [
                        'Press "Pay or update my card" below.',
                        'Pay the amount now, or switch to a different US bank account or card.',
                        'That\'s all — you will receive a receipt by email once it is paid.',
                    ]],
                    ['button', 'Pay or update my card', $link],
                    ['list', 'Good to know', [
                        $past_due ? 'We will try again automatically, so if the account now has enough money you do not need to do anything.' : '',
                        'You can change your bank account or card at any time from your private billing page.',
                    ]],
                ],
            ]];
        }

        if ($kind === 'stopped') {
            return ["Monthly tuition for {$kids} has stopped — Ilm-ul-Quran USA", $base + [
                'title'     => 'Monthly tuition stopped',
                'preheader' => "Monthly tuition billing for {$kids} has stopped. Nothing more will be charged.",
                'help'      => 'If you would like to restart classes, or if this was a mistake, please contact us.',
                'blocks'    => [
                    ['p', "Monthly tuition billing for {$kids} has now stopped, and nothing more will be charged."],
                    ['table', array_merge($rows, [['Status', 'Stopped — nothing more will be charged']])],
                    ['button', 'See my payments', $link],
                    ['list', 'Good to know', [
                        'You can still see your past payments and download receipts on your private billing page.',
                    ]],
                ],
            ]];
        }

        // account: the billing page
        $next = !empty($acc['next_charge_at']) && $acc['status'] !== 'canceled' ? self::nice_utc((string) $acc['next_charge_at']) : '';
        return ["Your family's billing page — Ilm-ul-Quran USA", $base + [
            'title'     => 'Your billing page',
            'preheader' => "Your family's private billing page for {$kids}.",
            'help'      => $burden,
            'blocks'    => [
                ['p', "Here is your family's private billing page for {$kids}."],
                ['p', 'On this page you can see every payment, download receipts, and change your bank account or card at any time.'],
                ['table', array_merge($rows, IQU_Billing_Email::tuition_rows($acc), [
                    ['Next payment', $next],
                    ['Paying with', $method],
                ])],
                ['button', 'Open my billing page', $link],
                ['list', 'Good to know', [
                    'You can change your bank account or card at any time from your private billing page.',
                    'To pause or stop classes, please tell us at least 7 days before the next payment.',
                ]],
            ],
        ]];
    }

    /** Send the family email for its kind (email_content()). */
    public static function send_email(array $acc): bool
    {
        if (!is_email($acc['contact_email'])) return false;
        if (IQU_Stripe::expected_mode() === 'test' && !self::test_email_allowed($acc['contact_email'])) return false;

        [$subject, $content] = self::email_content($acc);
        $ok = IQU_Billing_Email::send($acc['contact_email'], $subject, $content);
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
