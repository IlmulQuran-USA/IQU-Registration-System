<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Billing_Family_Admin (3.7.12)
 *
 * "Add to this family's billing" on the student page and in Check and send (IQU_Billing_Family). The family is suggested
 * by the same email; the admin can pick another family (parents often use two emails). The confirmation shows the
 * family's current monthly total, this child's fee, the new total and the first date the child is charged.
 *
 * Security: manage_options, a nonce per student, a one-time id per form (the lock and the members table stop a second add).
 * All output escaped.
 */
class IQU_Billing_Family_Admin
{
    public const A_ADD = 'iqu_family_add';
    private const CAP = 'manage_options';
    private const TX  = 'iqu_family_notice_';

    public static function init(): void
    {
        add_action('admin_post_' . self::A_ADD, [__CLASS__, 'handle_add']);
    }

    /** The student page, with the return target kept (F). */
    public static function student_url(int $id, array $args = []): string
    {
        $url = add_query_arg(array_merge(['page' => 'iqu-view-registration', 'id' => $id], $args), admin_url('admin.php'));
        return class_exists('IQU_Back') ? IQU_Back::keep($url) : $url;
    }

    /** May this student be added to a family's billing at all? (monthly, no billing yet, not Left / Archived) */
    private static function eligible(array $row): bool
    {
        return (string) ($row['form_type'] ?? '') === IQU_Database::FORM_FREE && class_exists('IQU_Billing_Family') && IQU_Billing_DB::is_installed()
            && (string) ($row['left_at'] ?? '') === '' && (string) ($row['archived_at'] ?? '') === ''
            && !IQU_Billing_DB::account_for_registration((int) $row['id']);
    }

    /** "Amina Khan (guardian) · amina@… · Active · $80.00/month" */
    private static function label(array $acc): string
    {
        $who = trim((string) $acc['guardian_name']) ?: (string) $acc['contact_email'];
        $kids = [];
        foreach (IQU_Billing_DB::get_members((int) $acc['id']) as $m) { $r = IQU_Database::get_registration((int) $m['registration_id']); if ($r) $kids[] = trim((string) $r['first_name']); }
        $status = class_exists('IQU_Billing_Send') ? IQU_Billing_Send::status_chip($acc) : (string) $acc['status'];
        return $who . ($kids ? ' (' . implode(', ', $kids) . ')' : '') . ' · ' . $acc['contact_email'] . ' · ' . $status . ' · ' . IQU_Pricing::format((float) $acc['net_amount']) . '/month';
    }

    /** The family picker (GET to the student page's confirmation). $intro: a sentence before it. Escaped HTML. */
    public static function picker(array $row, array $suggested, string $intro, string $button = 'Add to this family\'s billing…'): string
    {
        $id = (int) $row['id'];
        $sug_ids = array_map(fn($a) => (int) $a['id'], $suggested);
        $others = array_values(array_filter(IQU_Billing_Family::all_accounts(), fn($a) => !in_array((int) $a['id'], $sug_ids, true)));
        if (!$suggested && !$others) return '';
        $h = '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" class="iqu-set-course-form" data-iqu-family-picker>'
            . '<input type="hidden" name="page" value="iqu-view-registration"><input type="hidden" name="id" value="' . $id . '">'
            . (class_exists('IQU_Back') ? IQU_Back::hidden_field() : '')
            . ($intro !== '' ? '<p class="iqu-fld-hint">' . esc_html($intro) . '</p>' : '')
            . '<label class="screen-reader-text" for="iqu-family-' . $id . '">Family</label><select id="iqu-family-' . $id . '" name="iqu_family" required>';
        if ($suggested) {
            $h .= '<optgroup label="Same email">';
            foreach ($suggested as $a) $h .= '<option value="' . (int) $a['id'] . '">' . esc_html(self::label($a)) . '</option>';
            $h .= '</optgroup>';
        } else {
            $h .= '<option value="">Choose the family…</option>';
        }
        if ($others) {
            $h .= '<optgroup label="Another family">';
            foreach ($others as $a) $h .= '<option value="' . (int) $a['id'] . '">' . esc_html(self::label($a)) . '</option>';
            $h .= '</optgroup>';
        }
        return $h . '</select> <button type="submit" class="iqu-action-btn iqu-action-btn--view">' . esc_html($button) . '</button></form>';
    }

    /**
     * The student page item: the result of the last add (once), the confirmation (?iqu_family=), or — when a family with
     * the same email already has billing — the picker. '' otherwise (pages without it are unchanged).
     */
    public static function student_item(array $row): string
    {
        $notice = get_transient(self::TX . get_current_user_id());
        $out = '';
        if ($notice && (int) ($notice['id'] ?? 0) === (int) $row['id']) {
            delete_transient(self::TX . get_current_user_id());
            $out .= '<div class="iqu-detail-item iqu-detail-full" data-iqu-family-notice><div class="iqu-detail-val"><p class="iqu-free-month-note" role="' . ($notice['ok'] ? 'status' : 'alert') . '">' . esc_html($notice['message']) . '</p></div></div>';
        }
        if (!self::eligible($row)) return $out;
        $id = (int) $row['id'];
        $pick = absint($_GET['iqu_family'] ?? 0);
        $suggested = IQU_Billing_Family::accounts_for_email((string) $row['email']);
        if (!$pick && !$suggested) return $out;

        $out .= '<div class="iqu-detail-item iqu-detail-full" data-iqu-family-add><div class="iqu-detail-lbl">Family billing</div><div class="iqu-detail-val">';
        if ($pick) {
            $v = IQU_Billing_Family::preview($id, $pick);
            if (!$v['ok']) {
                $out .= '<p class="iqu-free-month-note" role="alert" data-iqu-family-refused>' . esc_html($v['error']) . '</p>'
                    . self::picker($row, $suggested, 'Choose another family:');
            } else {
                $first = wp_date('j F Y', (int) strtotime($v['first'] . ' 15:00:00 UTC'));
                $out .= '<p><strong>Add ' . esc_html(trim($row['first_name'] . ' ' . $row['last_name'])) . ' to ' . esc_html(self::label($v['acc'])) . '?</strong></p>'
                    . '<dl class="iqu-refund-facts" data-iqu-family-confirm>'
                    . '<div><dt>Current monthly total</dt><dd>' . esc_html(IQU_Pricing::format($v['current'])) . '</dd></div>'
                    . '<div><dt>This child\'s fee</dt><dd>' . esc_html($v['fee'] > 0 ? IQU_Pricing::format($v['fee']) . ' a month' : '$0 — full scholarship') . '</dd></div>'
                    . '<div><dt>New monthly total</dt><dd><strong>' . esc_html(IQU_Pricing::format($v['total'])) . '</strong></dd></div>'
                    . '<div><dt>First charged on</dt><dd>' . esc_html($v['fee'] > 0 ? $first : 'Never (not sent to Stripe)') . '</dd></div></dl>'
                    . '<p class="iqu-fld-hint">' . esc_html($v['running']
                        ? 'The family\'s card stays the same: the child joins their Stripe subscription. Nothing is charged today, and the other children\'s amounts do not change.'
                        : 'The family has not added a card yet: their payment page will show the new total, and the link already sent keeps working.') . '</p>'
                    . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="iqu-set-course-form">'
                    . '<input type="hidden" name="action" value="' . esc_attr(self::A_ADD) . '"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="account" value="' . (int) $v['acc']['id'] . '">'
                    . '<input type="hidden" name="op" value="' . esc_attr(str_replace('-', '', wp_generate_uuid4())) . '">'
                    . wp_nonce_field(self::A_ADD . '_' . $id, '_wpnonce', true, false)
                    . (class_exists('IQU_Back') ? IQU_Back::hidden_field() : '')
                    . '<button type="submit" class="iqu-action-btn iqu-action-btn--view" data-iqu-once>Add to this family\'s billing</button> '
                    . '<a class="iqu-action-btn" href="' . esc_url(self::student_url($id)) . '">Cancel</a></form>';
            }
        } else {
            $out .= self::picker($row, $suggested, 'This email already has a family billing record. Add this child to it — one card and one charge a month — instead of creating a second one.');
        }
        return $out . '</div></div>';
    }

    /** Check and send: for a selected student whose family may already be on billing. Escaped HTML ('' when none). */
    public static function send_notice(array $regs): string
    {
        $h = '';
        foreach ($regs as $row) {
            if (!self::eligible($row)) continue;
            $suggested = IQU_Billing_Family::accounts_for_email((string) $row['email']);
            $name = trim($row['first_name'] . ' ' . $row['last_name']);
            if ($suggested) {
                $h .= '<div class="iqu-billing-section" data-iqu-family-send><div class="iqu-section-title">Family already on billing</div>'
                    . self::picker($row, $suggested, $name . '\'s email already has a family billing record. Add ' . trim((string) $row['first_name']) . ' to it instead of creating a second record (the family would add a card again and be charged twice a month).') . '</div>';
            } else {
                $p = self::picker($row, [], 'Is ' . trim((string) $row['first_name']) . '\'s brother or sister already on billing under another email? Add ' . trim((string) $row['first_name']) . ' to that family instead:');
                if ($p !== '') $h .= '<details class="iqu-billing-section" data-iqu-family-send-other><summary>Brother or sister already on billing (another email)?</summary>' . $p . '</details>';
            }
        }
        return $h;
    }

    public static function handle_add(): void
    {
        if (!current_user_can(self::CAP)) wp_die('You do not have permission to do this.', 403);
        $id = absint($_POST['id'] ?? 0);
        check_admin_referer(self::A_ADD . '_' . $id);
        $account = absint($_POST['account'] ?? 0);
        $op = preg_replace('/[^a-f0-9]/', '', (string) ($_POST['op'] ?? ''));
        $r = IQU_Billing_Family::add($id, $account, $op);
        set_transient(self::TX . get_current_user_id(), ['id' => $id, 'ok' => $r['ok'], 'message' => $r['message']], 120);
        wp_safe_redirect(self::student_url($id));
        exit;
    }
}
