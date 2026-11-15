<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Billing_Family (3.7.12)
 *
 * "Add to this family's billing": a brother or sister joins the billing record the family already has — never a second
 * record (a second card and two charges a month).
 *   - Card on file (subscription running): the child's line is added to the same subscription, no proration, nothing
 *     charged today. First charge: the family's next billing day; a child with a free first month, the first of the
 *     family's billing days on or after the end of their 30 free days. When that is later than the next charge, the line
 *     is added by the hourly job (activate_due) just before it — never early. The other children are not changed.
 *   - Link sent, no card yet: the child joins the same record; the family's open Checkout page is closed and the record
 *     priced again, so the payment page shows the new total; the private link stays valid. A child first charged later
 *     than the family is left out of the Checkout page and added the same way once the subscription exists.
 * Refused: a payment problem, review, billing ending or ended, 6 students already, a student Left or Archived, no fee.
 * A $0 student is added to the record but never sent to Stripe. A double click or a retry never adds the child twice
 * (a lock, the members table's unique student + mode, and Stripe idempotency keys).
 */
class IQU_Billing_Family
{
    private const RUNNING   = ['free_month', 'waiting_first_charge', 'active'];
    private const NO_CARD   = ['not_sent', 'link_sent'];
    private const MAX_KIDS  = 6;
    private const MAX_LINES_PER_RUN = 10;

    /** Accounts (current mode, not canceled) that use this email, newest first — the suggested family comes first. */
    public static function accounts_for_email(string $email): array
    {
        global $wpdb;
        if (!is_email($email)) return [];
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . IQU_Billing_DB::accounts_table() . " WHERE LOWER(contact_email) = LOWER(%s) AND mode = %s AND status <> 'canceled' ORDER BY id DESC",
            $email, IQU_Stripe::expected_mode()
        ), ARRAY_A) ?: [];
    }

    /** Every family the admin may pick (current mode, not canceled), for the "another family" choice. */
    public static function all_accounts(): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . IQU_Billing_DB::accounts_table() . " WHERE mode = %s AND status NOT IN ('canceled') ORDER BY guardian_name, id",
            IQU_Stripe::expected_mode()
        ), ARRAY_A) ?: [];
    }

    /** Students on the record who are still billed or on it (not removed, not Left). */
    private static function open_members(array $acc): array
    {
        $removed = class_exists('IQU_Billing_Stop') ? IQU_Billing_Stop::removed($acc) : [];
        $out = [];
        foreach (IQU_Billing_DB::get_members((int) $acc['id']) as $m) {
            if (isset($removed[(int) $m['registration_id']])) continue;
            $reg = IQU_Database::get_registration((int) $m['registration_id']);
            if (!$reg || (string) ($reg['left_at'] ?? '') !== '' || (string) ($reg['billing_ended_at'] ?? '') !== '') continue;
            $out[] = $m;
        }
        return $out;
    }

    /** True when the family's subscription is running (a card or bank on file). */
    public static function running(array $acc): bool
    {
        return !empty($acc['stripe_subscription_id']) && in_array((string) $acc['status'], self::RUNNING, true);
    }

    /** The family's next charge date (Y-m-d, site calendar): the next invoice when running, else the first charge the page sets up. */
    public static function family_next_date(array $acc): string
    {
        if (self::running($acc) && !empty($acc['next_charge_at'])) return wp_date('Y-m-d', (int) strtotime($acc['next_charge_at'] . ' UTC'));
        $ts = IQU_Billing_Service::first_charge_timestamp((string) ($acc['first_charge_date'] ?? ''));
        return $ts ? gmdate('Y-m-d', $ts) : '';
    }

    /**
     * Everything the confirmation shows, or the reason it is refused.
     * @return array{ok:bool, error:string, acc?:array, reg?:array, p?:array, current:float, fee:float, total:float, first:string, deferred:bool, running:bool}
     */
    public static function preview(int $registration_id, int $account_id): array
    {
        $no = fn(string $e) => ['ok' => false, 'error' => $e, 'current' => 0.0, 'fee' => 0.0, 'total' => 0.0, 'first' => '', 'deferred' => false, 'running' => false];
        if (!IQU_Billing_DB::is_installed()) return $no('Billing cannot be changed yet (the database update is still running).');
        $reg = IQU_Database::get_registration($registration_id);
        if (!$reg) return $no('Student not found.');
        $name = trim($reg['first_name'] . ' ' . $reg['last_name']);
        if ((string) ($reg['form_type'] ?? '') !== IQU_Database::FORM_FREE) return $no('Only monthly students can be added to a family\'s billing.');
        if ((string) ($reg['left_at'] ?? '') !== '') return $no($name . ' is marked Left, so they cannot be added to billing.');
        if ((string) ($reg['archived_at'] ?? '') !== '') return $no($name . ' is archived, so they cannot be added to billing.');
        $acc = IQU_Billing_DB::get_account($account_id);
        if (!$acc || (string) $acc['mode'] !== IQU_Stripe::expected_mode()) return $no('That family\'s billing record was not found.');
        $have = IQU_Billing_DB::account_for_registration($registration_id);
        if ($have) return $no((int) $have['id'] === (int) $acc['id'] ? $name . ' is already on this family\'s billing.' : $name . ' already has billing set up.');

        $status = (string) $acc['status'];
        if (in_array($status, ['past_due', 'unpaid', 'paused'], true) || (string) $acc['last_payment_status'] === 'failed') return $no('This family has a payment problem. Solve it first, then add the student.');
        if ($status === 'review' || (string) ($acc['review_reason'] ?? '') !== '') return $no('This family\'s billing is in review. Press "Mark as reviewed" first, then add the student.');
        if (in_array($status, ['ending', 'canceled'], true) || (string) ($acc['ends_at'] ?? '') !== '') return $no('This family\'s billing is ending or has ended, so no student can be added to it.');
        if ($status === 'bank_verification') return $no('This family\'s bank account is still waiting for verification. Add the student once it is verified.');
        if (!self::running($acc) && !in_array($status, self::NO_CARD, true)) return $no('This family\'s billing cannot take another student right now (' . $status . ').');
        if (count(self::open_members($acc)) >= self::MAX_KIDS) return $no('This family already has ' . self::MAX_KIDS . ' students on billing, the most one billing record can have.');

        $p = IQU_Billing_Pricing::for_registration($reg);
        if (empty($p['scholarship']) && (!$p['billable'] || $p['needs_input'])) return $no($name . ': the fee is not set (' . ($p['reason'] ?: 'choose the course and classes a week') . ').');
        $fee = !empty($p['scholarship']) ? 0.0 : round((float) $p['net'], 2);

        $running = self::running($acc);
        $next = self::family_next_date($acc);
        if ($next === '') return $no('This family\'s next charge date is not known. Press "Sync from Stripe" on the family page, then try again.');
        $first = $next;
        if (IQU_Billing_Service::student_type($reg) === 'new') {
            // the first of the family's billing days on or after the end of the child's 30 free days
            $end = (string) IQU_Billing_Service::free_month_end($reg);
            $own = IQU_Billing_Service::billing_day_on_or_after($end, (int) substr($next, 8, 2));
            if ($own > $next) $first = $own;
        }
        $current = round((float) $acc['net_amount'], 2);
        return ['ok' => true, 'error' => '', 'acc' => $acc, 'reg' => $reg, 'p' => $p, 'current' => $current, 'fee' => $fee,
            'total' => round($current + $fee, 2), 'first' => $first, 'deferred' => $fee > 0 && $first > $next, 'running' => $running];
    }

    /**
     * Add the child. $op: the form's one-time id (a retry with the same id finds the child already added).
     * @return array{ok:bool, message:string}
     */
    public static function add(int $registration_id, int $account_id, string $op = ''): array
    {
        $lock = 'iqu_family_add_' . $registration_id;
        if (!add_option($lock, time(), '', 'no')) {
            if ((int) get_option($lock) > time() - 60) return ['ok' => false, 'message' => 'This student is being added right now. Please wait a moment.'];
            update_option($lock, time(), 'no');
        }
        try {
            // a double click or a retry: the child is already on this family's billing — nothing more to do
            $have = IQU_Billing_DB::account_for_registration($registration_id);
            $reg0 = IQU_Database::get_registration($registration_id);
            if ($have && $reg0 && (int) $have['id'] === $account_id) return ['ok' => true, 'message' => trim($reg0['first_name'] . ' ' . $reg0['last_name']) . ' is on this family\'s billing.'];
            $v = self::preview($registration_id, $account_id);
            if (!$v['ok']) return ['ok' => false, 'message' => $v['error']];
            $acc = $v['acc']; $reg = $v['reg']; $p = $v['p'];
            $name = trim($reg['first_name'] . ' ' . $reg['last_name']);
            $id = (int) $acc['id'];

            if (!IQU_Billing_DB::add_member($id, $registration_id, $v['fee'])) return ['ok' => false, 'message' => $name . ' already has billing set up.'];
            if ($v['deferred']) IQU_Billing_DB::set_member_first_charge($id, $registration_id, $v['first']);

            $item = '';
            if ($v['running']) {
                if ($v['fee'] > 0 && !$v['deferred']) {
                    $r = self::add_line($acc, $reg, $p, $v['fee']);
                    if (!$r['ok']) { self::undo_member($id, $registration_id); return ['ok' => false, 'message' => 'Stripe did not accept the change, so nothing was changed: ' . $r['error']]; }
                    $item = $r['item'];
                    IQU_Billing_DB::set_member_item($id, $registration_id, $item);
                }
                $gross = round((float) $acc['monthly_amount'] + (empty($p['scholarship']) ? (float) $p['gross'] : 0.0), 2);
                $disc  = round((float) $acc['discount_amount'] + (empty($p['scholarship']) ? (float) $p['discount'] : 0.0), 2);
                IQU_Billing_DB::update_account($id, ['net_amount' => $v['total'], 'monthly_amount' => $gross, 'discount_amount' => $disc]);
                if (IQU_Database::is_public_row($reg)) IQU_Database::transition_status($registration_id, ['pending_review', 'card_pending', 'expired'], 'enrolled'); // a website sibling waiting for review is finished
            } else {
                // no card yet: close the family's open page, then price the record again (the link itself stays valid)
                if (!IQU_Billing_Service::expire_open_session($acc)) IQU_Billing_DB::update_account($id, ['link_outdated' => 1]);
                IQU_Billing_Service::reprice_account($id);
                // a website enrollment finishes with the family's card (IQU_Enrollment::on_checkout_completed)
                if (IQU_Database::is_public_row($reg)) IQU_Database::transition_status($registration_id, ['pending_review'], 'card_pending');
            }
            if (IQU_Database::schema_ready()) IQU_Database::update_enrollment($registration_id, ['billing_account_id' => $id]);
            self::update_customer_names($acc);
            IQU_Billing_DB::log_action(['action' => 'add_to_family', 'account_id' => $id, 'registration_id' => $registration_id,
                'amount' => $v['fee'], 'reason' => 'first charge ' . ($v['fee'] > 0 ? $v['first'] : 'none ($0)') . ($op !== '' ? ' · op ' . substr($op, 0, 32) : ''), 'stripe_ref' => $item]);

            $acc = IQU_Billing_DB::get_account($id) ?: $acc;
            self::family_email($acc, $reg, $v['fee'], $v['first']);
            if (class_exists('IQU_Billing_Notify')) IQU_Billing_Notify::sibling_added($acc, $reg, $v['fee'], $v['first'], $v['running']);

            $day = wp_date('j M Y', (int) strtotime($v['first'] . ' 15:00:00 UTC'));
            return ['ok' => true, 'message' => $name . ' was added to this family\'s billing. The family now pays ' . IQU_Pricing::format((float) $acc['net_amount']) . ' a month'
                . ($v['fee'] > 0 ? '; ' . trim($reg['first_name']) . ' is first charged on ' . $day . '.' : ' (' . trim($reg['first_name']) . ' is on a full scholarship and is not charged).')
                . ($v['running'] ? ' Nothing was charged today.' : ' Their payment page now shows the new total; the link already sent still works.')];
        } finally {
            delete_option($lock);
        }
    }

    private static function undo_member(int $account_id, int $registration_id): void
    {
        global $wpdb;
        $wpdb->delete(IQU_Billing_DB::members_table(), ['account_id' => $account_id, 'registration_id' => $registration_id, 'mode' => IQU_Stripe::expected_mode()], ['%d', '%d', '%s']);
    }

    /**
     * One Stripe line for the child on the family's subscription: a product named like the Checkout lines (with
     * iqu_registration_id, so "Remove from billing" finds it), then the subscription item, no proration — nothing is
     * charged today; the next invoice charges it. Idempotency keys make a retry return the same objects.
     * @return array{ok:bool, item:string, error:string}
     */
    private static function add_line(array $acc, array $reg, array $p, float $fee): array
    {
        $sub = (string) $acc['stripe_subscription_id'];
        if (!preg_match('/^sub_[A-Za-z0-9]+$/', $sub)) return ['ok' => false, 'item' => '', 'error' => 'No subscription.'];
        $mode = IQU_Stripe::expected_mode();
        $rid  = (int) $reg['id'];
        $prod = IQU_Stripe::post('/products', [
            'name'        => substr(($p['course_label'] ?: 'Tuition') . ' - ' . IQU_Billing_Service::short_name($reg), 0, 250),
            'description' => $p['days_per_week'] ? sprintf('%d %s a week (%d a month)', $p['days_per_week'], (int) $p['days_per_week'] === 1 ? 'class' : 'classes', $p['classes_per_month']) : 'Monthly tuition',
            'metadata'    => ['iqu_registration_id' => (string) $rid, 'iqu_mode' => $mode],
        ], 'iqu-prod-' . $mode . '-' . (int) $acc['id'] . '-' . $rid);
        if (!$prod['ok'] || empty($prod['data']['id'])) return ['ok' => false, 'item' => '', 'error' => (string) $prod['error']];
        $it = IQU_Stripe::post('/subscription_items', [
            'subscription'       => $sub,
            'quantity'           => 1,
            'proration_behavior' => 'none',
            'price_data'         => ['currency' => 'usd', 'product' => (string) $prod['data']['id'], 'unit_amount' => IQU_Billing_Pricing::to_cents($fee), 'recurring' => ['interval' => 'month']],
            'metadata'           => ['iqu_registration_id' => (string) $rid, 'iqu_account_id' => (string) $acc['id'], 'iqu_mode' => $mode],
        ], 'iqu-si-' . $mode . '-' . (int) $acc['id'] . '-' . $rid);
        if (!$it['ok'] || empty($it['data']['id'])) return ['ok' => false, 'item' => '', 'error' => (string) $it['error']];
        return ['ok' => true, 'item' => (string) $it['data']['id'], 'error' => ''];
    }

    /** The student names and references on the family's Stripe invoices (the customer's custom fields). Best effort. */
    private static function update_customer_names(array $acc): void
    {
        if (!preg_match('/^cus_[A-Za-z0-9]+$/', (string) $acc['stripe_customer_id'])) return;
        $regs = [];
        foreach (self::open_members($acc) as $m) { $r = IQU_Database::get_registration((int) $m['registration_id']); if ($r) $regs[(int) $r['id']] = $r; }
        if (!$regs) return;
        $r = IQU_Stripe::post('/customers/' . $acc['stripe_customer_id'], ['invoice_settings' => ['custom_fields' => [
            ['name' => 'Student',   'value' => substr(implode(', ', array_map(['IQU_Billing_Service', 'short_name'], $regs)), 0, 140)],
            ['name' => 'Reference', 'value' => substr(implode(', ', array_map(fn($id) => 'IQU-' . $id, array_keys($regs))), 0, 140)],
        ]]]);
        if (!$r['ok']) error_log('[IQU] Student names on invoices not updated for account #' . (int) $acc['id'] . ' (' . ($r['code'] ?: 'error') . ')');
    }

    /** One email to the family: who was added, the new monthly total, and from which date. Test mode: own addresses only. */
    private static function family_email(array $acc, array $reg, float $fee, string $first): void
    {
        $to = (string) $acc['contact_email'];
        if (!is_email($to)) return;
        if (IQU_Stripe::expected_mode() === 'test') {
            $own = array_map('strtolower', array_filter([get_option('admin_email'), defined('IQU_CONTACT_EMAIL') ? IQU_CONTACT_EMAIL : '']));
            if (!in_array(strtolower(trim($to)), $own, true)) return;
        }
        $kid = trim((string) $reg['first_name']);
        $g   = trim((string) $acc['guardian_name']);
        $day = wp_date('j F Y', (int) strtotime($first . ' 15:00:00 UTC'));
        $running = self::running($acc);
        IQU_Billing_Email::send($to, "{$kid} has been added to your family's monthly tuition — Ilm-Ul-Quran USA", [
            'title'     => 'Added to your family\'s tuition',
            'preheader' => "{$kid} is now on your family's monthly tuition." . ($fee > 0 ? " From {$day}." : ''),
            'greeting'  => 'Assalamu alaikum' . ($g !== '' ? ' ' . explode(' ', $g)[0] : '') . ',',
            'private'   => true,
            'help'      => 'If the fee ever becomes a burden, please talk to us — no child\'s place is ever affected by cost.',
            'blocks'    => [
                ['p', "JazakAllahu khayran! {$kid} has been added to your family's monthly tuition at Ilm-Ul-Quran USA. Everything stays on one monthly payment."],
                ['table', array_merge(IQU_Billing_Email::student_rows($acc), [
                    ['Added', trim($reg['first_name'] . ' ' . $reg['last_name']) . ($fee > 0 ? ' — ' . IQU_Pricing::format($fee) . ' a month' : ' — full scholarship')],
                    ['New monthly total', IQU_Pricing::format((float) $acc['net_amount'])],
                    [$fee > 0 ? 'From' : 'Charged', $fee > 0 ? $day : 'Never — full scholarship'],
                ])],
                ['button', $running ? 'Open my billing page' : 'Set up monthly tuition', IQU_Billing_Service::link_for($acc)],
                ['list', 'Good to know', [
                    $running ? 'Nothing is charged today, and you do not need to add your card again.' : 'Your payment link still works: it now shows the new total.',
                    $fee > 0 ? "{$kid}'s tuition is first included in the payment on {$day}." : '',
                ]],
            ],
        ]);
    }

    // ------------------------------------------------------------
    // Hourly: a sibling's line for their first charge
    // ------------------------------------------------------------

    /**
     * Add the Stripe line of each sibling whose own first charge is the family's next charge (or has passed): the next
     * invoice then includes them. Never earlier (a line added before the previous invoice would be charged then).
     * A line added after the charge it was meant for: the team is told. At most MAX_LINES_PER_RUN per run.
     */
    public static function activate_due(): int
    {
        global $wpdb;
        if (!class_exists('IQU_Billing_DB') || !IQU_Billing_DB::is_installed() || !IQU_Stripe::is_ready()) return 0;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT m.registration_id, m.first_charge_date AS member_first, m.amount AS member_amount, a.* FROM ' . IQU_Billing_DB::members_table() . ' m JOIN ' . IQU_Billing_DB::accounts_table()
            . " a ON a.id = m.account_id WHERE m.mode = %s AND a.mode = %s AND m.first_charge_date IS NOT NULL AND m.stripe_item_id = '' AND m.amount > 0 AND a.stripe_subscription_id <> '' ORDER BY m.first_charge_date LIMIT 50",
            IQU_Stripe::expected_mode(), IQU_Stripe::expected_mode()
        ), ARRAY_A) ?: [];
        $done = 0;
        foreach ($rows as $row) {
            if ($done >= self::MAX_LINES_PER_RUN) break;
            if (!in_array((string) $row['status'], array_merge(self::RUNNING, ['past_due']), true) || empty($row['next_charge_at'])) continue;
            $next = wp_date('Y-m-d', (int) strtotime($row['next_charge_at'] . ' UTC'));
            if ($next < (string) $row['member_first']) continue; // not yet: the next invoice is still before their first charge
            $rid = (int) $row['registration_id'];
            $reg = IQU_Database::get_registration($rid);
            if (!$reg || (string) ($reg['left_at'] ?? '') !== '' || (string) ($reg['billing_ended_at'] ?? '') !== '') continue;
            $acc = IQU_Billing_DB::get_account((int) $row['id']);
            if (!$acc || isset((class_exists('IQU_Billing_Stop') ? IQU_Billing_Stop::removed($acc) : [])[$rid])) continue;
            $p = IQU_Billing_Pricing::for_registration($reg);
            $r = self::add_line($acc, $reg, $p, (float) $row['member_amount']);
            if (!$r['ok']) { if (class_exists('IQU_Billing_Notify') && (int) gmdate('G') === 6) IQU_Billing_Notify::sibling_line_failed($acc, $reg, $r['error']); continue; }
            IQU_Billing_DB::set_member_item((int) $acc['id'], $rid, $r['item']);
            IQU_Billing_DB::log_action(['action' => 'sibling_line_added', 'account_id' => (int) $acc['id'], 'registration_id' => $rid, 'amount' => (float) $row['member_amount'],
                'reason' => 'first charge ' . $row['member_first'], 'stripe_ref' => $r['item'], 'user_id' => 0]);
            if ($next > (string) $row['member_first'] && class_exists('IQU_Billing_Notify')) IQU_Billing_Notify::sibling_line_late($acc, $reg, (string) $row['member_first']);
            $done++;
        }
        return $done;
    }
}
