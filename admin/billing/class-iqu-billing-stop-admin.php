<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Billing_Stop_Admin
 *
 * "Stop billing" on the Billing details page (and "End scholarship" on the student page of a full-scholarship student
 * with no billing record): the button, its confirmation dialog with the required reason, and the action
 * (IQU_Billing_Stop). manage_options + a nonce per account / student on every action. The dialog is a <dialog>
 * opened by admin.js; without JavaScript the button is a link that shows the same dialog open (?iqu_confirm=…).
 */
final class IQU_Billing_Stop_Admin
{
    private const CAP      = 'manage_options';
    public const A_STOP    = 'iqu_billing_stop';
    public const A_REG     = 'iqu_end_scholarship';

    /** kind => [button, title, confirm, keep] */
    private const TEXT = [
        'stop'              => ['Stop billing', 'Stop billing', 'Stop billing', 'Keep billing'],
        'cancel_enrollment' => ['Cancel enrollment', 'Cancel enrollment', 'Cancel enrollment', 'Keep enrollment'],
        'end_scholarship'   => ['End scholarship', 'End scholarship', 'End scholarship', 'Keep scholarship'],
        'remove'            => ['Remove from billing', 'Remove one student from billing', 'Remove from billing', 'Keep billing'], // 3.7.7 Part AI
    ];

    public static function init(): void
    {
        add_action('admin_post_' . self::A_STOP, [__CLASS__, 'handle']);
        add_action('admin_post_' . self::A_REG, [__CLASS__, 'handle_registration']);
        add_action('admin_post_' . self::A_LEFT, [__CLASS__, 'handle_left']); // 3.7.9
        add_action('admin_post_' . self::A_RETURN, [__CLASS__, 'handle_return']); // 3.7.10
    }

    // ------------------------------------------------------------
    // Billing details page
    // ------------------------------------------------------------

    /** The family header's chip and button(s): "Ends {date}" + "Resume billing", or the stop button with its dialog. */
    public static function family_controls(array $acc): string
    {
        if (!current_user_can(self::CAP)) return '';
        $kind = IQU_Billing_Stop::kind($acc);
        $id   = (int) $acc['id'];
        $html = '';
        // (an ending family's chip says "Ends {date}": IQU_Billing_Send::status_chip)
        if ($kind === 'resume') {
            $html .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="iqu-inline-form" onsubmit="return confirm(\'Resume billing? The next charge happens as before.\');">'
                . self::hidden($id, 'resume') . '<button type="submit" class="iqu-action-btn iqu-action-btn--view">Resume billing</button></form>';
        } elseif (isset(self::TEXT[$kind])) {
            $html .= self::dialog('acc', $id, $kind, self::text_for($acc, $kind), add_query_arg('iqu_confirm', $kind, IQU_Billing_Page::family_url($id)));
        }
        // 3.7.7 Part AI: one student of a shared account, next to Stop billing (same dialog, reason and permissions)
        if ($kind === 'stop' && ($who = IQU_Billing_Stop::removable($acc))) {
            $html .= self::dialog('acc', $id, 'remove', self::remove_text($acc, $who), add_query_arg('iqu_confirm', 'remove', IQU_Billing_Page::family_url($id)), self::student_field($id, $who));
        }
        return $html;
    }

    /** The sentence of the dialog. */
    private static function text_for(array $acc, string $kind): string
    {
        if ($kind === 'stop') {
            $day = IQU_Billing_Stop::day(IQU_Billing_Stop::period_end_ts($acc));
            return 'Billing will stop at the end of the period already paid. The family keeps classes until ' . ($day !== '' ? $day : 'the end of that period') . '. No further charges.';
        }
        if ($kind === 'cancel_enrollment') return 'No bank or card has been charged. The family\'s payment page is closed and the enrollment is cancelled now. No further charges.';
        return 'The scholarship ends on ' . IQU_Billing_Stop::day((int) strtotime(IQU_Billing_Stop::month_end_utc() . ' UTC')) . ', the end of this month. Classes continue until then.';
    }

    /** Part AI: the dialog's sentences for the students that can be chosen. */
    private static function remove_text(array $acc, array $who): string
    {
        $day = IQU_Billing_Stop::day(IQU_Billing_Stop::period_end_ts($acc));
        $t = 'Billing ends only for the student you choose. Their line comes off the family\'s monthly payment with no proration: the period already paid is not changed, and the next charge leaves them out. They keep classes until '
            . ($day !== '' ? $day : 'the end of the period already paid') . '. The other students are not changed.';
        if (array_filter($who, fn($s) => !$s['paid'])) $t .= ' A full-scholarship student keeps classes until the end of this month (' . IQU_Billing_Stop::day((int) strtotime(IQU_Billing_Stop::month_end_utc() . ' UTC')) . ').';
        foreach ($who as $s) if ($s['last']) $t .= ' ' . trim($s['reg']['first_name'] . ' ' . $s['reg']['last_name']) . ' is the last student billed: choosing them stops billing for the whole family (Stop billing).';
        return $t;
    }

    /** Part AI: the required Student select. */
    private static function student_field(int $id, array $who): string
    {
        $f = 'iqu-remove-acc-' . $id . '-s';
        $opts = '<option value="">Choose a student…</option>';
        foreach ($who as $s) {
            $name = trim($s['reg']['first_name'] . ' ' . $s['reg']['last_name']);
            $note = $s['last'] ? 'last student billed — stops billing for the family' : ($s['paid'] ? IQU_Pricing::format($s['amount']) . ' a month' : 'full scholarship');
            $opts .= '<option value="' . (int) $s['reg']['id'] . '">' . esc_html($name . ' (IQU-' . (int) $s['reg']['id'] . ') — ' . $note) . '</option>';
        }
        return '<div class="iqu-fld"><label for="' . esc_attr($f) . '">Student <em>*</em></label><select id="' . esc_attr($f) . '" name="registration" required>' . $opts . '</select></div>';
    }

    // ------------------------------------------------------------
    // Student page (full scholarship, no billing record)
    // ------------------------------------------------------------

    public static function student_controls(array $reg): string
    {
        if (!current_user_can(self::CAP) || !class_exists('IQU_Billing_Stop')) return '';
        $id = (int) $reg['id'];
        $ended = (string) ($reg['billing_ended_at'] ?? '');
        if ($ended !== '') {
            return '<span class="iqu-chip iqu-chip--gold" data-iqu-ends>Ends ' . esc_html(IQU_Billing_Stop::day((int) strtotime($ended . ' UTC'))) . '</span>';
        }
        if (IQU_Billing_Stop::kind_for_registration($reg) !== 'end_scholarship') return '';
        return self::dialog('reg', $id, 'end_scholarship', self::text_for([], 'end_scholarship'),
            add_query_arg('iqu_confirm', 'end_scholarship', admin_url('admin.php?page=iqu-view-registration&id=' . $id)));
    }

    // ------------------------------------------------------------
    // The button and its dialog
    // ------------------------------------------------------------

    private static function hidden(int $id, string $kind, string $for = 'acc'): string
    {
        $action = $for === 'reg' ? self::A_REG : self::A_STOP;
        return '<input type="hidden" name="action" value="' . esc_attr($action) . '">'
            . '<input type="hidden" name="' . ($for === 'reg' ? 'id' : 'account') . '" value="' . $id . '">'
            . '<input type="hidden" name="kind" value="' . esc_attr($kind) . '">'
            // the form's one-time id: a double submit sends the same id → the same Stripe idempotency key
            . '<input type="hidden" name="op" value="' . esc_attr(str_replace('-', '', wp_generate_uuid4())) . '">'
            . wp_nonce_field($action . '_' . $id, '_wpnonce', true, false);
    }

    private static function dialog(string $for, int $id, string $kind, string $sentence, string $fallback, string $fields = ''): string
    {
        [$button, $title, $confirm, $keep] = self::TEXT[$kind];
        $d    = 'iqu-' . $kind . '-' . $for . '-' . $id;
        $open = sanitize_key((string) ($_GET['iqu_confirm'] ?? '')) === $kind;
        $opts = '<option value="">Choose a reason…</option>';
        foreach (IQU_Billing_Stop::REASONS as $k => $label) $opts .= '<option value="' . esc_attr($k) . '">' . esc_html($label) . '</option>';
        return '<a class="iqu-action-btn iqu-action-btn--delete" href="' . esc_url($fallback) . '" data-iqu-dialog="' . esc_attr($d) . '">' . esc_html($button) . '</a>'
            . '<dialog class="iqu-modal" id="' . esc_attr($d) . '" aria-labelledby="' . esc_attr($d) . '-t"' . ($open ? ' open' : '') . '>'
            . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="iqu-modal-form" data-iqu-reason-form>'
            . '<h2 class="iqu-modal-title" id="' . esc_attr($d) . '-t">' . esc_html($title) . '</h2>'
            . '<p class="iqu-modal-text">' . esc_html($sentence) . '</p>'
            . self::hidden($id, $kind, $for)
            . $fields
            . '<div class="iqu-fld"><label for="' . esc_attr($d) . '-r">Reason <em>*</em></label>'
            . '<select id="' . esc_attr($d) . '-r" name="reason" required>' . $opts . '</select></div>'
            . '<div class="iqu-fld" data-iqu-other><label for="' . esc_attr($d) . '-o">Other reason <em>*</em></label>'
            . '<input type="text" id="' . esc_attr($d) . '-o" name="reason_other" maxlength="' . IQU_Billing_Stop::OTHER_MAX . '"></div>'
            . '<div class="iqu-modal-actions">'
            . '<button type="submit" class="iqu-btn iqu-btn--danger">' . esc_html($confirm) . '</button>'
            . '<button type="submit" formmethod="dialog" formnovalidate value="cancel" class="iqu-btn iqu-btn--secondary">' . esc_html($keep) . '</button>'
            . '</div></form></dialog>';
    }

    // ------------------------------------------------------------
    // 3.7.9: "Left" on Student Billing (every row) — one shared dialog
    // ------------------------------------------------------------

    public const A_LEFT = 'iqu_billing_left';
    private const LEFT_DIALOG = 'iqu-left-dialog';

    /** The red "Left" button of a Student Billing row (opens the shared dialog; without JavaScript, the page with it open). */
    public static function left_button(array $reg): string
    {
        if (!current_user_can(self::CAP) || !IQU_Database::left_ready()) return '';
        $id = (int) $reg['id'];
        return '<a class="iqu-action-btn iqu-action-btn--delete" href="' . esc_url(add_query_arg(['page' => IQU_Billing_Page::SLUG, 'iqu_left' => $id], admin_url('admin.php')))
            . '" data-iqu-dialog="' . self::LEFT_DIALOG . '" data-iqu-left="' . $id . '" data-iqu-left-name="' . esc_attr(trim($reg['first_name'] . ' ' . $reg['last_name'])) . '">Left</a>';
    }

    /** The shared confirm dialog (once per page, outside the live-search region). Open, filled in, with ?iqu_left=<id>. */
    public static function left_dialog(): string
    {
        if (!current_user_can(self::CAP) || !IQU_Database::left_ready()) return '';
        $open = absint($_GET['iqu_left'] ?? 0);
        $reg  = $open ? IQU_Database::get_registration($open) : null;
        if ($reg && (string) ($reg['left_at'] ?? '') !== '') $reg = null;
        $d    = self::LEFT_DIALOG;
        $opts = '<option value="">Choose a reason…</option>';
        foreach (IQU_Billing_Stop::REASONS as $k => $label) $opts .= '<option value="' . esc_attr($k) . '">' . esc_html($label) . '</option>';
        return '<dialog class="iqu-modal" id="' . $d . '" aria-labelledby="' . $d . '-t"' . ($reg ? ' open' : '') . '>'
            . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="iqu-modal-form" data-iqu-reason-form>'
            . '<h2 class="iqu-modal-title" id="' . $d . '-t">Mark <span data-iqu-left-name>' . esc_html($reg ? trim($reg['first_name'] . ' ' . $reg['last_name']) : 'this student') . '</span> as Left</h2>'
            . '<p class="iqu-modal-text">They leave every billing place (Student Billing, payment links, billing exports) and stay in their course list; their student page shows the date. Running monthly billing ends at the end of the period already paid, never now: through Remove from billing when brothers or sisters stay, or Stop billing for the last student. An unused payment link is closed. Payments already received are kept.</p>'
            . '<input type="hidden" name="action" value="' . self::A_LEFT . '">'
            . '<input type="hidden" name="registration" value="' . ($reg ? (int) $reg['id'] : '') . '">'
            . '<input type="hidden" name="op" value="' . esc_attr(str_replace('-', '', wp_generate_uuid4())) . '">'
            . wp_nonce_field(self::A_LEFT, '_wpnonce', true, false)
            . '<div class="iqu-fld"><label for="' . $d . '-r">Reason <em>*</em></label><select id="' . $d . '-r" name="reason" required>' . $opts . '</select></div>'
            . '<div class="iqu-fld" data-iqu-other><label for="' . $d . '-o">Other reason <em>*</em></label><input type="text" id="' . $d . '-o" name="reason_other" maxlength="' . IQU_Billing_Stop::OTHER_MAX . '"></div>'
            . '<div class="iqu-modal-actions">'
            . '<button type="submit" class="iqu-btn iqu-btn--danger">Mark as Left</button>'
            . '<button type="submit" formmethod="dialog" formnovalidate value="cancel" class="iqu-btn iqu-btn--secondary">Keep</button>'
            . '</div></form></dialog>';
    }

    /** The result of the last Left action (once), for Student Billing. */
    public static function left_notice(): string
    {
        $n = get_transient('iqu_left_notice_' . get_current_user_id());
        if (!$n) return '';
        delete_transient('iqu_left_notice_' . get_current_user_id());
        return '<div class="notice notice-' . (!empty($n['ok']) ? 'success' : 'error') . ' inline" data-iqu-left-notice><p>' . esc_html((string) $n['message']) . '</p></div>';
    }

    public static function handle_left(): void
    {
        if (!current_user_can(self::CAP)) wp_die('You do not have permission to do this.', 403);
        check_admin_referer(self::A_LEFT);
        $id  = absint($_POST['registration'] ?? 0);
        $reg = $id ? IQU_Database::get_registration($id) : null;
        $op  = preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['op'] ?? ''));
        $r   = !$reg ? ['ok' => false, 'message' => 'Student not found.']
            : ($op === '' ? ['ok' => false, 'message' => 'This page was out of date, so nothing was changed.'] : IQU_Billing_Stop::left($reg, self::posted_reason(), $op));
        set_transient('iqu_left_notice_' . get_current_user_id(), $r, 120);
        wp_safe_redirect(admin_url('admin.php?page=' . IQU_Billing_Page::SLUG));
        exit;
    }

    // ------------------------------------------------------------
    // 3.7.10: "Return to billing" (undo Left) on the student page
    // ------------------------------------------------------------

    public const A_RETURN = 'iqu_billing_return';

    /**
     * Next to "Left on <date>": the "Return to billing" button with its confirm dialog (same permission as Left; without
     * JavaScript, ?iqu_confirm=return shows the dialog open). While the family's billing is still ending: a note instead.
     */
    public static function return_controls(array $reg): string
    {
        if (!current_user_can(self::CAP) || !IQU_Database::left_ready() || (string) ($reg['left_at'] ?? '') === '') return '';
        $id = (int) $reg['id'];
        if ($until = IQU_Billing_Stop::return_blocked_until($reg)) {
            return '<p class="iqu-free-month-note" data-iqu-return-later>Return to billing is possible after ' . esc_html(IQU_Billing_Stop::day($until)) . ', when the family\'s billing has ended.</p>';
        }
        $d    = 'iqu-return-reg-' . $id;
        $open = sanitize_key((string) ($_GET['iqu_confirm'] ?? '')) === 'return';
        $name = trim($reg['first_name'] . ' ' . $reg['last_name']);
        return '<a class="iqu-action-btn iqu-action-btn--view" href="' . esc_url(add_query_arg('iqu_confirm', 'return', admin_url('admin.php?page=iqu-view-registration&id=' . $id))) . '" data-iqu-dialog="' . $d . '">Return to billing</a>'
            . '<dialog class="iqu-modal" id="' . $d . '" aria-labelledby="' . $d . '-t"' . ($open ? ' open' : '') . '>'
            . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="iqu-modal-form">'
            . '<h2 class="iqu-modal-title" id="' . $d . '-t">Return ' . esc_html($name) . ' to billing</h2>'
            . '<p class="iqu-modal-text">Left is cleared and they are an enrolled student again. Student Billing shows them as Not set up, so a new payment link can be sent. As a returning student, there is no free month.</p>'
            . '<input type="hidden" name="action" value="' . self::A_RETURN . '">'
            . '<input type="hidden" name="id" value="' . $id . '">'
            . wp_nonce_field(self::A_RETURN . '_' . $id, '_wpnonce', true, false)
            . '<div class="iqu-modal-actions">'
            . '<button type="submit" class="iqu-btn iqu-btn--primary">Return to billing</button>'
            . '<button type="submit" formmethod="dialog" formnovalidate value="cancel" class="iqu-btn iqu-btn--secondary">Keep Left</button>'
            . '</div></form></dialog>';
    }

    /** The result of the last Return to billing (once), for the student page. */
    public static function return_notice(): string
    {
        $n = get_transient('iqu_return_notice_' . get_current_user_id());
        if (!$n) return '';
        delete_transient('iqu_return_notice_' . get_current_user_id());
        return '<p class="iqu-free-month-note" role="' . (!empty($n['ok']) ? 'status' : 'alert') . '" data-iqu-return-notice>' . esc_html((string) $n['message']) . '</p>';
    }

    public static function handle_return(): void
    {
        $id = absint($_POST['id'] ?? 0);
        if (!current_user_can(self::CAP)) wp_die('You do not have permission to do this.', 403);
        check_admin_referer(self::A_RETURN . '_' . $id);
        $reg = $id ? IQU_Database::get_registration($id) : null;
        if (!$reg) wp_die('Student not found.', 404);
        set_transient('iqu_return_notice_' . get_current_user_id(), IQU_Billing_Stop::return_to_billing($reg), 120);
        wp_safe_redirect(admin_url('admin.php?page=iqu-view-registration&id=' . $id));
        exit;
    }

    // ------------------------------------------------------------
    // Actions
    // ------------------------------------------------------------
    private static function posted_reason(): string
    {
        return IQU_Billing_Stop::reason(sanitize_key(wp_unslash($_POST['reason'] ?? '')), sanitize_text_field(wp_unslash($_POST['reason_other'] ?? '')));
    }

    public static function handle(): void
    {
        $id = absint($_POST['account'] ?? 0);
        if (!current_user_can(self::CAP)) wp_die('You do not have permission to do this.', 403);
        check_admin_referer(self::A_STOP . '_' . $id);
        $acc = IQU_Billing_DB::get_account($id);
        if (!$acc) wp_die('Billing record not found.', 404);
        $kind = sanitize_key((string) ($_POST['kind'] ?? ''));
        $op   = preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['op'] ?? ''));
        if (($kind === 'remove' ? IQU_Billing_Stop::kind($acc) !== 'stop' : $kind !== IQU_Billing_Stop::kind($acc)) || $op === '') {
            $r = ['ok' => false, 'message' => 'This page was out of date, so nothing was changed. Please check the status and try again.'];
        } elseif ($kind === 'remove') { // 3.7.7 Part AI
            $r = IQU_Billing_Stop::remove_student($acc, absint($_POST['registration'] ?? 0), self::posted_reason(), $op);
        } elseif ($kind === 'stop') {
            $r = IQU_Billing_Stop::stop($acc, self::posted_reason(), $op);
        } elseif ($kind === 'resume') {
            $r = IQU_Billing_Stop::resume($acc, $op);
        } elseif ($kind === 'cancel_enrollment') {
            $r = IQU_Billing_Stop::cancel_enrollment($acc, self::posted_reason());
        } else { // end_scholarship (everyone on the record has a full scholarship)
            $regs = array_values(array_filter(array_map(fn($m) => IQU_Database::get_registration((int) $m['registration_id']), IQU_Billing_DB::get_members($id)),
                fn($r) => $r && IQU_Database::is_active_student($r) && (string) ($r['billing_ended_at'] ?? '') === ''));
            $r = IQU_Billing_Stop::end_scholarship($regs, self::posted_reason(), $id);
        }
        IQU_Billing_Send::notice(['type' => $r['ok'] ? 'success' : 'error', 'items' => [$r['message']]]);
        IQU_Billing_Send::to_message($id);
    }

    public static function handle_registration(): void
    {
        $id = absint($_POST['id'] ?? 0);
        if (!current_user_can(self::CAP)) wp_die('You do not have permission to do this.', 403);
        check_admin_referer(self::A_REG . '_' . $id);
        $reg = IQU_Database::get_registration($id);
        if (!$reg) wp_die('Student not found.', 404);
        $r = IQU_Billing_Stop::kind_for_registration($reg) === 'end_scholarship'
            ? IQU_Billing_Stop::end_scholarship([$reg], self::posted_reason())
            : ['ok' => false, 'message' => 'This page was out of date, so nothing was changed.'];
        set_transient('iqu_reg_notice_' . get_current_user_id(), $r, 120);
        wp_safe_redirect(admin_url('admin.php?page=iqu-view-registration&id=' . $id));
        exit;
    }
}
