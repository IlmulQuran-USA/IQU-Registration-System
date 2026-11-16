<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Billing_Arrears_Admin (3.7.14)
 *
 * The family page's "Unpaid earlier months" card: each month a family still owes (a month missed before the card, an
 * invoice never paid, or one left to the team), with what happens to it. "Waive" (IQU_Billing_Arrears::waive) is shown
 * only to the admins who may refund (IQU_REFUND_USERS); its dialog shows the student(s), the month and the amount, and
 * needs a reason. Security: those admins only (checked again in the handler), a nonce per family, a one-time id per form.
 */
class IQU_Billing_Arrears_Admin
{
    public const A_WAIVE = 'iqu_billing_waive_month';
    private const TX = 'iqu_waive_notice_';

    public static function init(): void
    {
        add_action('admin_post_' . self::A_WAIVE, [__CLASS__, 'handle_waive']);
    }

    private static function can_waive(): bool
    {
        return class_exists('IQU_Billing_Refund') && IQU_Billing_Refund::can_refund();
    }

    /** The card ('' when the family owes no earlier month and there is no result to show). Escaped HTML. */
    public static function card(array $acc): string
    {
        if (!class_exists('IQU_Billing_Arrears')) return '';
        $notice = get_transient(self::TX . get_current_user_id());
        $msg = '';
        if ($notice && (int) ($notice['account'] ?? 0) === (int) $acc['id']) {
            delete_transient(self::TX . get_current_user_id());
            $msg = '<div class="notice notice-' . ($notice['ok'] ? 'success' : 'error') . ' inline"><p>' . esc_html($notice['message']) . '</p></div>';
        }
        $months = IQU_Billing_Arrears::unpaid_months($acc);
        if (!$months && $msg === '') return '';
        $can = self::can_waive();
        $h = '<div class="iqu-card" data-iqu-unpaid-months><div class="iqu-card-head"><span class="iqu-card-head-title">Unpaid earlier months</span>'
            . '<span class="iqu-card-head-badge">' . count($months) . ' ' . (count($months) === 1 ? 'month' : 'months') . '</span></div>'
            . ($msg !== '' ? '<div class="iqu-billing-body">' . $msg . '</div>' : '');
        if ($months) {
            $h .= '<div class="iqu-table-wrap"><table class="iqu-tbl iqu-billing-tbl"><thead><tr><th>Month</th><th>Students</th><th class="is-num">Amount</th><th>What happens</th><th></th></tr></thead><tbody>';
            foreach ($months as $month => $m) {
                $name = IQU_Billing_Arrears::month_name($month);
                $h .= '<tr data-iqu-unpaid-month="' . esc_attr($month) . '"><td>' . esc_html($name) . '</td><td class="iqu-billing-wrap">' . esc_html(implode(', ', $m['students'])) . '</td>'
                    . '<td class="is-num">' . esc_html(IQU_Pricing::format((float) $m['amount'])) . '</td><td class="iqu-billing-wrap">' . esc_html($m['status']) . '</td><td class="iqu-td-actions">'
                    . ($can && $m['waivable'] ? self::dialog($acc, $month, $m) : '') . '</td></tr>';
            }
            $h .= '</tbody></table></div><div class="iqu-billing-body"><p class="iqu-fld-hint">At most one earlier month is charged together with the next billing day; older months are left to the team.'
                . ($can ? ' A waived month is never charged.' : '') . '</p></div>';
        }
        return $h . '</div>';
    }

    private static function dialog(array $acc, string $month, array $m): string
    {
        $id = (int) $acc['id'];
        $d = 'iqu-waive-' . $id . '-' . str_replace('-', '', $month);
        $name = IQU_Billing_Arrears::month_name($month);
        return '<a class="iqu-action-btn iqu-action-btn--delete" href="#' . esc_attr($d) . '" data-iqu-dialog="' . esc_attr($d) . '">Waive</a>'
            . '<dialog class="iqu-modal" id="' . esc_attr($d) . '" aria-labelledby="' . esc_attr($d) . '-t">'
            . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="iqu-modal-form" data-iqu-waive>'
            . '<h2 class="iqu-modal-title" id="' . esc_attr($d) . '-t">Waive ' . esc_html($name) . '?</h2>'
            . '<dl class="iqu-refund-facts">'
            . '<div><dt>Student' . (count($m['students']) > 1 ? 's' : '') . '</dt><dd>' . esc_html(implode(', ', $m['students'])) . '</dd></div>'
            . '<div><dt>Month</dt><dd>' . esc_html($name) . '</dd></div>'
            . '<div><dt>Amount</dt><dd>' . esc_html(IQU_Pricing::format((float) $m['amount'])) . '</dd></div></dl>'
            . '<p class="iqu-modal-text">This month will never be charged: it leaves the next payment, and an unpaid Stripe invoice is closed without payment. The family gets an email.</p>'
            . '<input type="hidden" name="action" value="' . esc_attr(self::A_WAIVE) . '"><input type="hidden" name="account" value="' . $id . '"><input type="hidden" name="month" value="' . esc_attr($month) . '">'
            . '<input type="hidden" name="op" value="' . esc_attr(str_replace('-', '', wp_generate_uuid4())) . '">'
            . wp_nonce_field(self::A_WAIVE . '_' . $id, '_wpnonce', true, false)
            . '<div class="iqu-fld"><label for="' . esc_attr($d) . '-r">Reason <em>*</em></label><input type="text" id="' . esc_attr($d) . '-r" name="reason" maxlength="200" required></div>'
            . '<div class="iqu-modal-actions"><button type="submit" class="iqu-btn iqu-btn--danger">Waive ' . esc_html(IQU_Pricing::format((float) $m['amount'])) . '</button>'
            . '<button type="submit" formmethod="dialog" formnovalidate value="cancel" class="iqu-btn iqu-btn--secondary">Keep it</button></div>'
            . '</form></dialog>';
    }

    public static function handle_waive(): void
    {
        $id = absint($_POST['account'] ?? 0);
        if (!current_user_can('manage_options')) wp_die('You do not have permission to do this.', 403);
        check_admin_referer(self::A_WAIVE . '_' . $id);
        if (!self::can_waive()) wp_die('Only the administrators who may refund can waive a month.', 403);
        $acc = IQU_Billing_DB::get_account($id);
        if (!$acc) wp_die('Billing record not found.', 404);
        $month = sanitize_text_field(wp_unslash((string) ($_POST['month'] ?? '')));
        $reason = sanitize_text_field(wp_unslash((string) ($_POST['reason'] ?? '')));
        $op = preg_replace('/[^a-f0-9]/', '', (string) ($_POST['op'] ?? ''));
        $r = IQU_Billing_Arrears::waive($acc, $month, $reason, $op);
        set_transient(self::TX . get_current_user_id(), ['account' => $id, 'ok' => $r['ok'], 'message' => $r['message']], 120);
        wp_safe_redirect(admin_url('admin.php?page=' . IQU_Billing_Send::MSG_SLUG . '&account=' . $id));
        exit;
    }
}
