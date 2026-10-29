<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Billing_Refund
 *
 * Refunding one tuition payment (3.7.0), for named people only: the WordPress user logins in the wp-config.php
 * constant IQU_REFUND_USERS (comma-separated). Not defined or empty → nobody. There is no setting for it. Every
 * request is checked on the server (can_refund), with manage_options as well.
 *
 * A refund is a manual decision (e.g. within 48 hours of a charge when no class took place); nothing is automatic.
 * Stripe refund on the invoice's payment intent, with an idempotency key (the payment row + the dialog's one-time id:
 * a double click is one refund) and metadata (reason, WP user). The webhook (charge.refunded) keeps the payment
 * history up to date as before; the row is also read again at once. Logged (IQU_Billing_DB::log_action) and told on
 * Telegram.
 */
final class IQU_Billing_Refund
{
    public const REASONS = [
        'mistake'   => 'Charged by mistake',
        'twice'     => 'Charged twice',
        'no_class'  => 'Class not held',
        'within_48' => 'Cancelled within 48 hours',
        'other'     => 'Other',
    ];
    public const OTHER_MAX = 200;
    public const ACTION    = 'iqu_billing_refund';

    /** The logins in IQU_REFUND_USERS (lower case); [] when the constant is missing or empty. */
    public static function allowed_logins(): array
    {
        if (!defined('IQU_REFUND_USERS')) return [];
        $list = array_map(fn($l) => strtolower(trim($l)), explode(',', (string) constant('IQU_REFUND_USERS')));
        return array_values(array_filter($list, fn($l) => $l !== ''));
    }

    /** True only for a logged-in admin whose login is in IQU_REFUND_USERS. */
    public static function can_refund(): bool
    {
        if (!is_user_logged_in() || !current_user_can('manage_options')) return false;
        $login = strtolower((string) (wp_get_current_user()->user_login ?? ''));
        return $login !== '' && in_array($login, self::allowed_logins(), true);
    }

    /** What can still be refunded on a payment row, in dollars (0 when nothing). */
    public static function remainder(array $row): float
    {
        if (!in_array((string) $row['status'], ['paid', 'refunded'], true)) return 0.0;
        return max(0.0, round((float) $row['amount_paid'] - (float) $row['amount_refunded'], 2));
    }

    public static function reason(string $key, string $other): string
    {
        if (!isset(self::REASONS[$key])) return '';
        if ($key !== 'other') return self::REASONS[$key];
        $other = trim(preg_replace('/\s+/', ' ', $other));
        return ($other === '' || mb_strlen($other) > self::OTHER_MAX) ? '' : 'Other: ' . $other;
    }

    /** A payment row of the current mode by id, or null. */
    public static function payment(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . IQU_Billing_DB::payments_table() . ' WHERE id = %d AND mode = %s', $id, IQU_Stripe::expected_mode()), ARRAY_A);
        return $row ?: null;
    }

    /**
     * Refund $amount (dollars) of payment row $row. $op: the dialog's one-time id. Returns ['ok', 'message', 'refund'].
     * The caller has checked can_refund().
     */
    public static function refund(array $row, float $amount, string $reason, string $op): array
    {
        $fail = fn(string $m) => ['ok' => false, 'message' => $m, 'refund' => ''];
        $left = self::remainder($row);
        $cents = (int) round($amount * 100);
        if ($left <= 0) return $fail('Nothing is left to refund on this payment.');
        if ($cents <= 0 || $cents > (int) round($left * 100)) return $fail('Enter an amount above $0.00 and at most ' . IQU_Pricing::format($left) . '.');
        if ($reason === '') return $fail('Choose a reason (for "Other", a short text up to 200 characters).');
        if (!preg_match('/^[A-Za-z0-9]{8,64}$/', $op)) return $fail('This dialog is out of date. Please reload the page.');
        $acc = IQU_Billing_DB::get_account((int) $row['account_id']);
        if (!$acc) return $fail('Billing record not found.');
        $inv = (string) $row['stripe_invoice_id'];
        $r = IQU_Stripe::get('/invoices/' . rawurlencode($inv), ['expand' => ['payments']]);
        if (!$r['ok']) return $fail('Stripe could not be reached: ' . $r['error']);
        $pi = '';
        foreach ((array) ($r['data']['payments']['data'] ?? []) as $p) {
            $id = $p['payment']['payment_intent'] ?? '';
            $id = is_array($id) ? (string) ($id['id'] ?? '') : (string) $id;
            if (preg_match('/^pi_[A-Za-z0-9]+$/', $id) && (string) ($p['status'] ?? 'paid') === 'paid') { $pi = $id; break; }
        }
        if ($pi === '') return $fail('No card or bank payment was found on this invoice in Stripe.');
        $user = (string) (wp_get_current_user()->user_login ?? '');
        $res = IQU_Stripe::post('/refunds', [
            'payment_intent' => $pi,
            'amount'         => $cents,
            'metadata'       => ['iqu_reason' => substr($reason, 0, 200), 'iqu_user' => $user, 'iqu_invoice' => $inv, 'iqu_account_id' => (string) $acc['id']],
        ], 'iqu_refund_' . (int) $row['id'] . '_' . $op);
        if (!$res['ok']) return $fail('Stripe did not make the refund: ' . $res['error']);
        $refund = (string) ($res['data']['id'] ?? '');
        $done = round(((int) ($res['data']['amount'] ?? $cents)) / 100, 2);
        // a double click returns the same refund (idempotency): logged and told once
        $known = array_filter(IQU_Billing_DB::actions_for_account((int) $acc['id'], 'refund'), fn($a) => $a['stripe_ref'] === $refund);
        if (!$known) {
            IQU_Billing_DB::log_action(['action' => 'refund', 'account_id' => (int) $acc['id'], 'amount' => $done, 'reason' => $reason, 'stripe_ref' => $refund]);
            if (class_exists('IQU_Billing_Notify')) IQU_Billing_Notify::refund_issued($acc, $done, $reason, $row, $user);
        }
        IQU_Billing_History::refresh_invoice($inv, $acc); // the webhook (charge.refunded) does it too
        return ['ok' => true, 'message' => 'Refunded ' . IQU_Pricing::format($done) . '.', 'refund' => $refund];
    }

    // ------------------------------------------------------------
    // Admin: the AJAX handler (registered for everyone; it refuses anyone not in IQU_REFUND_USERS)
    // ------------------------------------------------------------

    public static function init(): void
    {
        add_action('wp_ajax_' . self::ACTION, [__CLASS__, 'ajax']);
    }

    public static function ajax(): void
    {
        if (!self::can_refund()) wp_send_json_error(['message' => 'You are not allowed to refund payments.'], 403);
        $id = absint($_POST['payment'] ?? 0);
        if (!check_ajax_referer(self::ACTION . '_' . $id, '_ajax_nonce', false)) wp_send_json_error(['message' => 'This page has expired. Please reload it.'], 403);
        $row = self::payment($id);
        if (!$row) wp_send_json_error(['message' => 'Payment not found.'], 404);
        $raw = trim(str_replace(['$', ',', ' '], '', (string) wp_unslash($_POST['amount'] ?? '')));
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $raw)) wp_send_json_error(['message' => 'Enter an amount like 30 or 30.50.'], 400);
        $reason = self::reason(sanitize_key(wp_unslash($_POST['reason'] ?? '')), sanitize_text_field(wp_unslash($_POST['reason_other'] ?? '')));
        $r = self::refund($row, (float) $raw, $reason, preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['op'] ?? '')));
        $r['ok'] ? wp_send_json_success($r) : wp_send_json_error($r, 400);
    }
}
