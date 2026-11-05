<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Billing_Stop
 *
 * Stopping a family's billing (3.7.0). Policy: billing always stops at the END of the period already paid — there is
 * no immediate cancellation anywhere. The family keeps classes until then and the next charge never happens.
 *
 *   stop               a subscription (free month, active, payment problem …): Stripe cancel_at_period_end = true.
 *                      In the free month the trial ends and no charge ever happens. Never prorated, never refunded.
 *   resume             set to stop, before that date: cancel_at_period_end = false.
 *   cancel_enrollment  no subscription yet (link sent, card pending …): the open Checkout page is expired and the
 *                      registrations are cancelled now. No subscription call.
 *   end_scholarship    a full scholarship (never billed): the registrations end at the end of this month
 *                      (billing_ended_at), then become "cancelled" (finish_due, daily).
 *
 * Every action is logged (IQU_Billing_DB::log_action: who, when, what, amount, reason, Stripe id). Every Stripe POST has
 * an idempotency key made of the account, the subscription and the form's one-time id, so a double click is one call.
 * When Stripe then deletes the subscription (customer.subscription.deleted), on_synced() ends the registrations and
 * tells the team once.
 */
final class IQU_Billing_Stop
{
    public const REASONS = ['moved' => 'Moved', 'financial' => 'Financial', 'schedule' => 'Schedule', 'not_satisfied' => 'Not satisfied', 'other' => 'Other'];
    public const OTHER_MAX = 200;

    /** Statuses of an account whose Stripe subscription can be set to stop. */
    private const STOPPABLE = ['free_month', 'waiting_first_charge', 'active', 'past_due', 'unpaid', 'paused', 'review', 'bank_verification'];

    // ------------------------------------------------------------
    // What applies
    // ------------------------------------------------------------

    /** What the Billing details page offers: 'stop' | 'resume' | 'cancel_enrollment' | 'end_scholarship' | '' (nothing). */
    public static function kind(array $acc): string
    {
        $status = (string) $acc['status'];
        if ($status === 'ending') return self::ends_ts($acc) > time() ? 'resume' : '';
        if ((string) $acc['stripe_subscription_id'] !== '') return in_array($status, self::STOPPABLE, true) ? 'stop' : '';
        $regs = self::open_members($acc);
        if (!$regs) return '';
        $paid = array_filter($regs, fn($r) => !IQU_Billing_Pricing::for_registration($r)['scholarship']);
        if (!$paid) return 'end_scholarship';
        return $status === 'canceled' ? '' : 'cancel_enrollment';
    }

    /** A full-scholarship student with no billing record (the student page): 'end_scholarship' or ''. */
    public static function kind_for_registration(array $reg): string
    {
        if (!IQU_Database::billing_ended_at_ready() || !IQU_Database::is_active_student($reg) || (string) ($reg['billing_ended_at'] ?? '') !== '') return '';
        if ((string) ($reg['form_type'] ?? '') !== IQU_Database::FORM_FREE) return '';
        if (IQU_Billing_DB::account_for_registration((int) $reg['id'])) return '';
        return IQU_Billing_Pricing::for_registration($reg)['scholarship'] ? 'end_scholarship' : '';
    }

    /** Members of the account still running (active, no end date set). */
    private static function open_members(array $acc): array
    {
        $out = [];
        foreach (IQU_Billing_DB::get_members((int) $acc['id']) as $m) {
            $reg = IQU_Database::get_registration((int) $m['registration_id']);
            if ($reg && IQU_Database::is_active_student($reg) && (string) ($reg['billing_ended_at'] ?? '') === '') $out[] = $reg;
        }
        return $out;
    }

    /** When billing stops (stored ends_at), as a timestamp; 0 when not known. */
    public static function ends_ts(array $acc): int
    {
        return !empty($acc['ends_at']) ? (int) strtotime($acc['ends_at'] . ' UTC') : 0;
    }

    /** The date the family keeps classes until: the end of the period already paid (the free month's end). 0 = unknown. */
    public static function period_end_ts(array $acc): int
    {
        return !empty($acc['next_charge_at']) ? (int) strtotime($acc['next_charge_at'] . ' UTC') : 0;
    }

    /** The last moment of this month in the site time zone, as UTC 'Y-m-d H:i:s' (a scholarship's end). */
    public static function month_end_utc(): string
    {
        $end = new DateTimeImmutable(wp_date('Y-m-t 23:59:59'), wp_timezone());
        return $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** "Nov 4, 2026" (site time zone). */
    public static function day(int $ts): string
    {
        return $ts ? wp_date('M j, Y', $ts) : '';
    }

    /**
     * The reason as stored: the label, or "Other: …" (max 200 characters). '' when it is not valid (no reason, an
     * unknown one, or "Other" without text).
     */
    public static function reason(string $key, string $other): string
    {
        if (!isset(self::REASONS[$key])) return '';
        if ($key !== 'other') return self::REASONS[$key];
        $other = trim(preg_replace('/\s+/', ' ', $other));
        if ($other === '' || mb_strlen($other) > self::OTHER_MAX) return '';
        return 'Other: ' . $other;
    }

    // ------------------------------------------------------------
    // Actions — each: ['ok' => bool, 'message' => string]
    // ------------------------------------------------------------

    /** Set the subscription to stop at the end of the period already paid. $op: the form's one-time id. */
    public static function stop(array $acc, string $reason, string $op): array
    {
        $sub = (string) $acc['stripe_subscription_id'];
        if (self::kind($acc) !== 'stop' || $sub === '') return self::fail('Billing cannot be stopped from here for this family.');
        if ($reason === '') return self::fail('Choose a reason (for "Other", a short text up to 200 characters).');
        $r = IQU_Stripe::post('/subscriptions/' . rawurlencode($sub), ['cancel_at_period_end' => true], self::key('stop', $acc, $op));
        if (!$r['ok']) return self::fail('Stripe did not accept the change: ' . $r['error']);
        $s = (array) $r['data'];
        if (empty($s['cancel_at_period_end']) && empty($s['cancel_at'])) return self::fail('Stripe did not set the subscription to stop. Nothing was changed.');
        $ends = IQU_Billing_Sync::ends_at($s);
        IQU_Billing_DB::update_account((int) $acc['id'], ['status' => (string) $acc['status'] === 'review' ? 'review' : IQU_Billing_Sync::status_from($s), 'ends_at' => $ends, 'next_charge_at' => null]);
        IQU_Billing_DB::log_action(['action' => 'stop_billing', 'account_id' => (int) $acc['id'], 'amount' => (float) $acc['net_amount'], 'reason' => $reason, 'stripe_ref' => $sub]);
        $day = self::day($ends ? (int) strtotime($ends . ' UTC') : 0);
        return ['ok' => true, 'message' => 'Billing will stop at the end of the period already paid' . ($day ? ' (' . $day . ')' : '') . '. No further charges.'];
    }

    /** Undo a stop before its date: cancel_at_period_end = false. */
    public static function resume(array $acc, string $op): array
    {
        $sub = (string) $acc['stripe_subscription_id'];
        if (self::kind($acc) !== 'resume' || $sub === '') return self::fail('Billing cannot be resumed for this family (it is not set to stop, or the date has passed).');
        $r = IQU_Stripe::post('/subscriptions/' . rawurlencode($sub), ['cancel_at_period_end' => false], self::key('resume', $acc, $op));
        if (!$r['ok']) return self::fail('Stripe did not accept the change: ' . $r['error']);
        $s = (array) $r['data'];
        if (!empty($s['cancel_at_period_end']) || !empty($s['cancel_at'])) return self::fail('Stripe still has the subscription set to stop. Nothing was changed.');
        $end = (int) ($s['trial_end'] ?? 0);
        if ((string) ($s['status'] ?? '') !== 'trialing' || !$end) $end = (int) ($s['items']['data'][0]['current_period_end'] ?? $s['current_period_end'] ?? 0);
        IQU_Billing_DB::update_account((int) $acc['id'], ['status' => IQU_Billing_Sync::status_from($s), 'ends_at' => null, 'next_charge_at' => $end ? gmdate('Y-m-d H:i:s', $end) : null]);
        IQU_Billing_DB::log_action(['action' => 'resume_billing', 'account_id' => (int) $acc['id'], 'amount' => (float) $acc['net_amount'], 'reason' => 'Billing resumed', 'stripe_ref' => $sub]);
        return ['ok' => true, 'message' => 'Billing resumed. The next charge happens as before.'];
    }

    /** No subscription yet: close the open Checkout page, cancel the registrations now. No subscription call. */
    public static function cancel_enrollment(array $acc, string $reason): array
    {
        if (self::kind($acc) !== 'cancel_enrollment') return self::fail('This enrollment cannot be cancelled from here.');
        if ($reason === '') return self::fail('Choose a reason (for "Other", a short text up to 200 characters).');
        if (!IQU_Billing_Service::expire_open_session($acc)) return self::fail('The family\'s payment page could not be closed. Nothing was changed; please try again in a minute.');
        $now = current_time('mysql', true);
        $regs = self::open_members($acc);
        foreach ($regs as $reg) {
            IQU_Database::set_status((int) $reg['id'], 'cancelled');
            IQU_Database::set_billing_ended((int) $reg['id'], $now);
        }
        IQU_Billing_DB::update_account((int) $acc['id'], ['status' => 'canceled', 'ends_at' => $now, 'next_charge_at' => null]);
        IQU_Billing_DB::log_action(['action' => 'cancel_enrollment', 'account_id' => (int) $acc['id'], 'amount' => (float) $acc['net_amount'], 'reason' => $reason, 'stripe_ref' => (string) $acc['checkout_session_id']]);
        return ['ok' => true, 'message' => 'Enrollment cancelled (' . count($regs) . ' ' . (count($regs) === 1 ? 'student' : 'students') . '). Nothing was charged.'];
    }

    /** A full scholarship ends at the end of this month: billing_ended_at set now, "cancelled" after that day. */
    public static function end_scholarship(array $regs, string $reason, int $account_id = 0): array
    {
        if ($reason === '') return self::fail('Choose a reason (for "Other", a short text up to 200 characters).');
        $end = self::month_end_utc();
        $n = 0;
        foreach ($regs as $reg) {
            if (!IQU_Database::set_billing_ended((int) $reg['id'], $end)) continue;
            $n++;
            IQU_Billing_DB::log_action(['action' => 'end_scholarship', 'account_id' => $account_id, 'registration_id' => (int) $reg['id'],
                'amount' => (float) IQU_Billing_Pricing::for_registration($reg)['discount'], 'reason' => $reason]);
        }
        if (!$n) return self::fail('The scholarship could not be ended.');
        if ($account_id) IQU_Billing_DB::update_account($account_id, ['ends_at' => $end]);
        return ['ok' => true, 'message' => 'The scholarship ends on ' . self::day((int) strtotime($end . ' UTC')) . '. Classes continue until then.'];
    }

    // ------------------------------------------------------------
    // 3.7.7 Part AI: remove one student from a shared billing account
    // ------------------------------------------------------------

    /**
     * Students removed from this account (Part AI): [registration id => the logged remove_student action, newest].
     * They stay members (their history and the student page link are kept) but are no longer billed.
     */
    public static function removed(array $acc): array
    {
        $out = [];
        foreach (IQU_Billing_DB::actions_for_account((int) $acc['id'], 'remove_student') as $a) {
            $rid = (int) $a['registration_id'];
            if ($rid && !isset($out[$rid])) $out[$rid] = $a;
        }
        return $out;
    }

    /**
     * Who "Remove from billing" offers, in member order: [['reg' => …, 'amount' => float, 'paid' => bool, 'last' => bool]].
     * Only on a running subscription that can be stopped (kind 'stop') with at least two students still billed or on
     * a scholarship. 'paid': the student has a line on the Stripe subscription (amount > 0); 'last': the only paid
     * student left — choosing them uses Stop billing for the whole family (Stripe keeps at least one line).
     */
    public static function removable(array $acc): array
    {
        if (self::kind($acc) !== 'stop') return [];
        $gone = self::removed($acc);
        $out = [];
        foreach (IQU_Billing_DB::get_members((int) $acc['id']) as $m) {
            if (isset($gone[(int) $m['registration_id']])) continue;
            $reg = IQU_Database::get_registration((int) $m['registration_id']);
            if (!$reg || !IQU_Database::is_active_student($reg) || (string) ($reg['billing_ended_at'] ?? '') !== '') continue;
            $amount = (float) $m['amount'];
            $out[] = ['reg' => $reg, 'amount' => $amount, 'paid' => IQU_Billing_Pricing::to_cents($amount) > 0, 'last' => false];
        }
        if (count($out) < 2) return [];
        $paid = array_keys(array_filter($out, fn($s) => $s['paid']));
        if (count($paid) === 1) $out[$paid[0]]['last'] = true;
        return $out;
    }

    /**
     * End one student's billing. A paid student: the one Stripe request that deletes their subscription item, with no
     * proration (this period is already paid, so the next invoice simply leaves them out; the other items and prices
     * are not touched); their enrollment ends at the end of the period already paid. A full-scholarship student (no
     * Stripe line): no Stripe call; their classes end at the end of this month. The last paid student: Stop billing.
     * Stripe refuses or cannot be reached → nothing is changed here. $op: the form's one-time id.
     */
    public static function remove_student(array $acc, int $registration_id, string $reason, string $op): array
    {
        $pick = null;
        foreach (self::removable($acc) as $s) if ((int) $s['reg']['id'] === $registration_id) $pick = $s;
        if (!$pick) return self::fail('This student cannot be removed from billing here.');
        if ($reason === '') return self::fail('Choose a reason (for "Other", a short text up to 200 characters).');
        $reg  = $pick['reg'];
        $name = trim($reg['first_name'] . ' ' . $reg['last_name']);

        if ($pick['last']) { // the last student Stripe charges: the existing Stop billing flow
            $r = self::stop($acc, $reason, $op);
            return $r['ok'] ? ['ok' => true, 'message' => $name . ' was the last student billed, so billing stops for the whole family. ' . $r['message']] : $r;
        }

        $sub  = (string) $acc['stripe_subscription_id'];
        $item = '';
        if ($pick['paid']) {
            // Find the student's line (each line's product carries iqu_registration_id, set at Checkout). A read only.
            $g = IQU_Stripe::get('/subscriptions/' . rawurlencode($sub), ['expand' => ['items.data.price.product']]);
            if (!$g['ok']) return self::fail('Stripe could not be read, so nothing was changed: ' . $g['error']);
            $s     = (array) $g['data'];
            $lines = (array) ($s['items']['data'] ?? []);
            foreach ($lines as $it) {
                if ((string) ($it['price']['product']['metadata']['iqu_registration_id'] ?? '') === (string) $registration_id) $item = (string) ($it['id'] ?? '');
            }
            if ($item === '') return self::fail($name . '\'s line was not found on the family\'s Stripe subscription. Nothing was changed.');
            if (count($lines) < 2) return self::fail('This is the only line on the Stripe subscription. Use Stop billing instead. Nothing was changed.');
            $end = (string) ($s['status'] ?? '') === 'trialing' && !empty($s['trial_end']) ? (int) $s['trial_end']
                : (int) ($s['items']['data'][0]['current_period_end'] ?? $s['current_period_end'] ?? 0);
            if (!$end) $end = self::period_end_ts($acc);
            if (!$end) return self::fail('The end of the period already paid is not known. Nothing was changed.');
            // The one change: delete this student's item, no proration.
            $d = IQU_Stripe::delete('/subscription_items/' . rawurlencode($item), ['proration_behavior' => 'none']);
            if (!$d['ok'] || empty($d['data']['deleted'])) return self::fail('Stripe did not accept the change, so nothing was changed' . ($d['ok'] ? '.' : ': ' . $d['error']));
            $ended = gmdate('Y-m-d H:i:s', $end);
        } else {
            $ended = self::month_end_utc(); // a full scholarship: classes until the end of this month (as End scholarship)
        }

        IQU_Database::set_billing_ended((int) $reg['id'], $ended);
        $p = IQU_Billing_Pricing::for_registration($reg);
        $net  = max(0, round((float) $acc['net_amount'] - $pick['amount'], 2));
        $disc = max(0, round((float) $acc['discount_amount'] - (float) $p['discount'], 2));
        IQU_Billing_DB::update_account((int) $acc['id'], ['net_amount' => $net, 'discount_amount' => $disc, 'monthly_amount' => round($net + $disc, 2)]);
        IQU_Billing_DB::log_action(['action' => 'remove_student', 'account_id' => (int) $acc['id'], 'registration_id' => (int) $reg['id'],
            'amount' => $pick['amount'], 'reason' => $reason, 'stripe_ref' => $item]);
        $day = self::day((int) strtotime($ended . ' UTC'));
        return ['ok' => true, 'message' => $name . ' was removed from billing and keeps classes until ' . $day . '. The family now pays '
            . IQU_Pricing::format($net) . ' a month' . ($pick['paid'] ? ' from the next charge' : '') . '. The other students are not changed.'];
    }

    /** "Removed from billing · classes until Nov 30, 2026" (or "classes ended …" once that day has passed); '' when not removed. */
    public static function removed_line(array $reg, array $removed): string
    {
        if (!isset($removed[(int) $reg['id']])) return '';
        $ts = !empty($reg['billing_ended_at']) ? (int) strtotime($reg['billing_ended_at'] . ' UTC') : 0;
        return 'Removed from billing' . ($ts ? ' · classes ' . ($ts > time() ? 'until ' : 'ended ') . self::day($ts) : '');
    }

    private static function fail(string $m): array
    {
        return ['ok' => false, 'message' => $m];
    }

    private static function key(string $what, array $acc, string $op): string
    {
        return 'iqu_' . $what . '_' . (int) $acc['id'] . '_' . preg_replace('/[^A-Za-z0-9]/', '', (string) $acc['stripe_subscription_id']) . '_' . preg_replace('/[^A-Za-z0-9]/', '', $op);
    }

    // ------------------------------------------------------------
    // From Stripe, and the daily step
    // ------------------------------------------------------------

    /**
     * After a webhook's sync: the subscription has just been deleted (customer.subscription.deleted, or found deleted)
     * → every member registration "cancelled" with billing_ended_at = the subscription's end, and one Telegram notice
     * (family, students, end date, reason). True when it handled the change (the webhook then sends nothing else).
     * A replay finds the status unchanged and does nothing.
     */
    public static function on_synced(array $before, array $sync): bool
    {
        if (($sync['new'] ?? '') !== 'canceled' || ($sync['old'] ?? '') === 'canceled' || empty($sync['subscription'])) return false;
        $acc   = IQU_Billing_DB::get_account((int) $before['id']) ?: $before;
        $ended = IQU_Billing_Sync::ends_at((array) $sync['subscription']) ?: current_time('mysql', true);
        foreach (IQU_Billing_DB::get_members((int) $acc['id']) as $m) {
            $reg = IQU_Database::get_registration((int) $m['registration_id']);
            if (!$reg) continue;
            if ((string) $reg['status'] !== 'cancelled') IQU_Database::set_status((int) $reg['id'], 'cancelled');
            if ((string) ($reg['billing_ended_at'] ?? '') === '') IQU_Database::set_billing_ended((int) $reg['id'], $ended);
        }
        $why = IQU_Billing_DB::actions_for_account((int) $acc['id'], 'stop_billing');
        $reason = $why ? (string) $why[0]['reason'] : 'Stopped in Stripe (no reason recorded here)';
        if (class_exists('IQU_Billing_Notify')) IQU_Billing_Notify::billing_ended($acc, $ended, $reason);
        return true;
    }

    /** Daily: a registration whose billing_ended_at has passed and is still "enrolled" becomes "cancelled". */
    public static function finish_due(): int
    {
        global $wpdb;
        if (!IQU_Database::billing_ended_at_ready()) return 0;
        $ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . $wpdb->prefix . IQU_TABLE_NAME . " WHERE status = 'enrolled' AND billing_ended_at IS NOT NULL AND billing_ended_at < %s", current_time('mysql', true))) ?: [];
        foreach ($ids as $id) IQU_Database::set_status((int) $id, 'cancelled');
        return count($ids);
    }
}
