<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Billing_Service
 *
 * The billing workflow:
 *   create_account()          enrollment(s) -> local account + Stripe customer
 *   link_for()                the family's private /my-billing link
 *   create_checkout_session() Stripe page where the family adds a bank or card;
 *                             the subscription is created there
 *   create_portal_session()   Stripe page to change the bank or card later
 *
 * Security:
 * - Amounts come only from IQU_Billing_Pricing (server-side).
 * - The link token is an HMAC of the account id with the link key from
 *   IQU_Link_Keys (IQU_LINK_SECRET in wp-config.php, or the WordPress salts
 *   until that is set). It is never stored; only its SHA-256 hash is, for
 *   lookup. A database leak alone cannot produce links.
 *   Resetting the link changes the token and the old link stops working.
 * - Stripe customer creation uses an idempotency key, so a retry never
 *   creates a second customer.
 * - Every Stripe object carries the account id and mode in metadata.
 */
class IQU_Billing_Service
{
    private const FREE_MONTH_DAYS  = 30;
    private const MAX_DAYS_AHEAD   = 45;
    private const CHARGE_HOUR_UTC  = 15; // about 10 AM US Central
    private const MIN_FUTURE_HOURS = 48; // Stripe needs a trial to end at least 48h ahead

    // ------------------------------------------------------------
    // Private link
    // ------------------------------------------------------------

    /** $key: the link key to sign with; default the current one (IQU_Link_Keys). */
    public static function token_for(array $acc, ?string $key = null): string
    {
        return hash_hmac('sha256', $acc['mode'] . '|' . (int) $acc['id'] . '|' . $acc['token_created_at'], $key ?? IQU_Link_Keys::key(IQU_Link_Keys::PURPOSE_BILLING));
    }

    public static function link_for(array $acc): string
    {
        return add_query_arg('t', self::token_for($acc), home_url('/my-billing/'));
    }

    /**
     * Find the account for a token from a link. Constant-time compare.
     * A link made with IQU_LINK_SECRET_PREVIOUS (before a secret rotation) is accepted too: it is
     * found by its stored previous hash and must match an HMAC with the previous secret.
     */
    public static function account_for_token(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        $hash = hash('sha256', $token);
        $acc  = IQU_Billing_DB::get_account_by_token_hash($hash);
        if ($acc) return hash_equals(self::token_for($acc), $token) ? $acc : null;

        $prev = IQU_Link_Keys::previous_key(IQU_Link_Keys::PURPOSE_BILLING);
        if ($prev === null) return null;
        $acc = IQU_Billing_DB::get_account_by_prev_token_hash($hash);
        return ($acc && hash_equals(self::token_for($acc, $prev), $token)) ? $acc : null;
    }

    /**
     * The link key changed (IQU_Link_Keys::maybe_switch_over): recompute every account's link
     * hash, both modes. The old hash is kept in token_hash_prev, so IQU_LINK_SECRET_PREVIOUS can
     * still accept it. token_created_at is unchanged.
     * @return int Accounts renewed, or -1 when a write failed (the switch-over is then retried).
     */
    public static function renew_all_links(): int
    {
        $n = 0;
        foreach (IQU_Billing_DB::link_rows() as $acc) {
            $hash = hash('sha256', self::token_for($acc));
            $old  = (string) ($acc['token_hash'] ?? '');
            if ($old !== '' && hash_equals($old, $hash)) continue;
            if (!IQU_Billing_DB::set_link_hashes((int) $acc['id'], (string) $acc['mode'], $hash, $old !== '' ? $old : null)) return -1;
            $n++;
        }
        return $n;
    }

    /** Issue a new token. Any earlier link for this account stops working. */
    public static function reset_link(int $account_id): bool
    {
        $acc = IQU_Billing_DB::get_account($account_id);
        if (!$acc) return false;
        $now = gmdate('Y-m-d H:i:s');
        $old = (string) $acc['token_created_at'];
        if ($now <= $old) {
            $now = gmdate('Y-m-d H:i:s', (int) strtotime($old . ' UTC') + 1);
        }
        $acc['token_created_at'] = $now;
        return IQU_Billing_DB::update_account($account_id, [
            'token_created_at' => $now,
            'token_hash'       => hash('sha256', self::token_for($acc)),
            'token_hash_prev'  => null, // a reset ends every earlier link, also one from before a key rotation
        ]);
    }

    // ------------------------------------------------------------
    // New or current student, and the first charge date
    // ------------------------------------------------------------

    /**
     * New-student families get the free first month: their subscription starts with a trial that
     * ends on the first charge date. Current students are billed from the billing cycle anchor.
     */
    public static function has_free_first_month(array $acc): bool
    {
        return ($acc['student_type'] ?? '') === 'new';
    }

    /**
     * Only genuinely new students get the free first month. Not eligible when ANY of:
     * - the row was added by an admin or a CSV import (IQU_Database::created_via, which also
     *   reads the older Billing markers);
     * - the family answered "already an Ilm-ul-Quran student = Yes", or an admin marked the row
     *   "Existing student — no free month" (existing_student). Before that column is migrated,
     *   the website answer is read from the review reasons, where it was stored until now.
     * Used for every free-month decision and label (student_type() below).
     */
    public static function is_free_month_eligible(array $reg): bool
    {
        if (!IQU_Database::is_public_row($reg)) return false;
        if (!empty($reg['existing_student'])) return false;
        if (!IQU_Database::student_fee_columns_ready()
            && in_array('existing_student', explode(',', (string) ($reg['review_reason'] ?? '')), true)) return false;
        return true;
    }

    /** Why a row gets no free month, in a few words ('' when it is eligible). */
    public static function no_free_month_reason(array $reg): string
    {
        if (self::is_free_month_eligible($reg)) return '';
        $via = IQU_Database::created_via($reg);
        if ($via === IQU_Database::VIA_ADMIN) return 'added by an admin';
        if ($via === IQU_Database::VIA_IMPORT) return 'imported from CSV';
        return in_array('existing_student', explode(',', (string) ($reg['review_reason'] ?? '')), true)
            ? 'existing student (answered Yes on the website form)'
            : 'existing student (marked by an admin)';
    }

    /** 'new' while the free first month is still running, otherwise 'current'. */
    public static function student_type(array $reg): string
    {
        // Existing students (and rows added by an admin or an import) are always current.
        if (!self::is_free_month_eligible($reg)) return 'current';
        $created = strtotime((string) ($reg['created_at'] ?? '') . ' UTC');
        if ($created && $created > time() - self::FREE_MONTH_DAYS * DAY_IN_SECONDS) return 'new';
        return 'current';
    }

    /** Suggested first charge date (Y-m-d) for a new student; null for current students. */
    public static function suggested_first_charge(array $reg): ?string
    {
        if (self::student_type($reg) !== 'new') return null;
        $created = strtotime((string) $reg['created_at'] . ' UTC');
        return gmdate('Y-m-d', $created + self::FREE_MONTH_DAYS * DAY_IN_SECONDS);
    }

    /** Unix time of the first charge, or 0 when it should be charged at checkout. */
    public static function first_charge_timestamp(?string $date): int
    {
        if (!$date) return 0;
        $ts = strtotime($date . ' ' . self::CHARGE_HOUR_UTC . ':00:00 UTC');
        if (!$ts || $ts < time() + self::MIN_FUTURE_HOURS * HOUR_IN_SECONDS) return 0;
        return $ts;
    }

    // ------------------------------------------------------------
    // Create an account for one family
    // ------------------------------------------------------------

    /**
     * @param int[] $registration_ids One child, or several children of one family.
     * @param array $opts first_charge_date (Y-m-d, required for current students),
     *                    courses [reg_id => course], days [reg_id => int] for records missing them.
     * @return array{ok:bool, errors:string[], account_id:int}
     */
    public static function create_account(array $registration_ids, array $opts = []): array
    {
        $fail = fn(array $e) => ['ok' => false, 'errors' => $e, 'account_id' => 0];

        if (!IQU_Stripe::is_ready()) return $fail([IQU_Stripe::not_ready_reason()]);

        $ids = array_values(array_unique(array_filter(array_map('absint', $registration_ids))));
        if (!$ids) return $fail(['Choose at least one student.']);
        if (count($ids) > 6) return $fail(['A family account can have at most 6 students.']);

        $regs = [];
        $priced = [];
        $errors = [];
        $scholarship = [];
        foreach ($ids as $id) {
            $reg = IQU_Database::get_registration($id);
            if (!$reg) { $errors[] = "Enrollment #{$id} was not found."; continue; }
            $name = trim($reg['first_name'] . ' ' . $reg['last_name']);
            if (IQU_Billing_DB::account_for_registration($id)) { $errors[] = "{$name} already has billing set up."; continue; }
            $p = IQU_Billing_Pricing::for_registration(
                $reg,
                isset($opts['courses'][$id]) ? sanitize_key((string) $opts['courses'][$id]) : null,
                isset($opts['days'][$id]) ? absint($opts['days'][$id]) : null
            );
            // A full-scholarship student ($0) is never billed: left out of the account, so a mixed
            // family is billed only for the paid students and an all-$0 family gets no Stripe customer.
            if (!empty($p['scholarship'])) { $scholarship[] = $name; continue; }
            if (!$p['billable']) { $errors[] = "{$name}: " . ($p['reason'] ?: 'cannot be billed.'); continue; }
            $regs[$id] = $reg;
            $priced[$id] = $p;
        }
        if ($errors) return $fail($errors);
        if (!$priced) return $fail(['Full scholarship — no billing. ' . implode(', ', $scholarship) . (count($scholarship) === 1 ? ' is' : ' are') . ' not charged, so no Stripe customer or payment link is created.']);

        // One family = one billing email.
        $emails = array_unique(array_map(fn($r) => strtolower(trim($r['email'])), $regs));
        if (count($emails) > 1) return $fail(['These students have different emails. Bill each family separately.']);
        $email = reset($emails);
        if (!is_email($email)) return $fail(['The enrollment has no valid email address.']);

        // New only if every child is still in the free month.
        $types = array_values(array_unique(array_map([__CLASS__, 'student_type'], $regs)));
        $type  = (count($types) === 1 && $types[0] === 'new') ? 'new' : 'current';

        $date = (string) ($opts['first_charge_date'] ?? '');
        if ($date === '' && $type === 'new' && count($regs) === 1) {
            $date = (string) self::suggested_first_charge(reset($regs));
        }
        if ($date === '') return $fail(['Choose the date of the first charge.']);
        $d = DateTime::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
        if (!$d || $d->format('Y-m-d') !== $date) return $fail(['The first charge date is not valid.']);
        if ($d->getTimestamp() > time() + self::MAX_DAYS_AHEAD * DAY_IN_SECONDS) {
            return $fail(['The first charge date must be within the next ' . self::MAX_DAYS_AHEAD . ' days.']);
        }

        $first    = reset($regs);
        $gross    = round(array_sum(array_column($priced, 'gross')), 2);
        $discount = round(array_sum(array_column($priced, 'discount')), 2);
        $net      = round(array_sum(array_column($priced, 'net')), 2);
        $guardian = trim((string) $first['guardian_name']);
        $wa       = trim((string) ($first['guardian_whatsapp'] ?: $first['whatsapp']));

        $account_id = IQU_Billing_DB::create_account([
            'guardian_name'     => $guardian,
            'contact_email'     => $email,
            'contact_whatsapp'  => substr($wa, 0, 25),
            'student_type'      => $type,
            'monthly_amount'    => $gross,
            'discount_amount'   => $discount,
            'net_amount'        => $net,
            'first_charge_date' => $date,
            'status'            => 'not_sent',
            'token_created_at'  => gmdate('Y-m-d H:i:s'),
        ]);
        if (!$account_id) return $fail(['Could not save the billing record.']);

        foreach ($priced as $id => $p) {
            IQU_Billing_DB::add_member($account_id, (int) $id, (float) $p['net']);
        }

        // Stripe customer. The idempotency key makes a retry return the same customer.
        $students = implode(', ', array_map([__CLASS__, 'short_name'], $regs));
        $refs     = implode(', ', array_map(fn($id) => 'IQU-' . $id, array_keys($regs)));
        $res = IQU_Stripe::post('/customers', [
            'name'              => $guardian !== '' ? $guardian : trim($first['first_name'] . ' ' . $first['last_name']),
            'email'             => $email,
            'phone'             => substr($wa, 0, 20),
            'preferred_locales' => ['en'],
            // QA only: attach the customer to a Stripe test clock. Ignored outside test mode.
            'test_clock'        => (IQU_Stripe::expected_mode() === 'test' && preg_match('/^clock_[A-Za-z0-9]+$/', (string) ($opts['test_clock'] ?? ''))) ? (string) $opts['test_clock'] : null,
            'invoice_settings'  => ['custom_fields' => [
                ['name' => 'Student',   'value' => substr($students, 0, 140)],
                ['name' => 'Reference', 'value' => substr($refs, 0, 140)],
            ]],
            'metadata' => [
                'iqu_account_id'       => (string) $account_id,
                'iqu_mode'             => IQU_Stripe::expected_mode(),
                'iqu_registration_ids' => implode(',', array_keys($regs)),
            ],
        ], 'iqu-cus-' . IQU_Stripe::expected_mode() . '-' . $account_id);

        if (!$res['ok'] || empty($res['data']['id'])) {
            self::delete_local($account_id);
            return $fail(['Stripe did not accept the customer: ' . $res['error']]);
        }

        $acc = IQU_Billing_DB::get_account($account_id);
        IQU_Billing_DB::update_account($account_id, [
            'stripe_customer_id' => (string) $res['data']['id'],
            'token_hash'         => hash('sha256', self::token_for($acc)),
        ]);

        // A course chosen here for a student whose record has none is saved on the record, so
        // the student page, Reports and emails show the course that is billed. Only a missing
        // course (and missing days) is filled; nothing the record already has is changed.
        foreach ($priced as $id => $p) {
            if (IQU_Billing_Pricing::course_missing($regs[$id]) && $p['course'] !== '') {
                IQU_Database::fill_course((int) $id, $p['course'], (int) $p['days_per_week'], (float) $p['per_class_rate'], (float) $p['gross']);
            }
        }

        return ['ok' => true, 'errors' => [], 'account_id' => $account_id];
    }

    /**
     * Price an account again from its students' records, while no Stripe subscription exists
     * yet (link not used): the member amounts and totals the checkout link will charge. Used
     * after an agreed fee is changed on the student page. Returns false once billing is running.
     */
    public static function reprice_account(int $account_id): bool
    {
        $acc = IQU_Billing_DB::get_account($account_id);
        if (!$acc || !empty($acc['stripe_subscription_id'])) return false;
        $gross = $discount = $net = 0.0;
        foreach (IQU_Billing_DB::get_members($account_id) as $m) {
            $reg = IQU_Database::get_registration((int) $m['registration_id']);
            if (!$reg) continue;
            $p = IQU_Billing_Pricing::for_registration($reg);
            IQU_Billing_DB::set_member_amount($account_id, (int) $m['registration_id'], (float) $p['net']);
            $gross += (float) $p['gross']; $discount += (float) $p['discount']; $net += (float) $p['net'];
        }
        $set = ['monthly_amount' => round($gross, 2), 'discount_amount' => round($discount, 2), 'net_amount' => round($net, 2)];
        // Everyone on it is now a full scholarship: nothing to pay, so the unused payment link is
        // closed (no subscription exists, so there is nothing to cancel in Stripe).
        if ($net <= 0) $set['status'] = 'canceled';
        // A paid fee again on a link closed that way: open it again, to be sent again.
        elseif ($acc['status'] === 'canceled') $set['status'] = 'not_sent';
        return IQU_Billing_DB::update_account($account_id, $set);
    }

    /**
     * Close the family's open Stripe Checkout page (if any) so it can no longer set up a
     * subscription. True when nothing is open any more (expired now, or already expired or
     * completed); false when Stripe could not be reached or refused. Needs only Checkout
     * Sessions (write), which creating the page already needs.
     */
    public static function expire_open_session(array $acc): bool
    {
        $sid = (string) ($acc['checkout_session_id'] ?? '');
        if ($sid === '' || !empty($acc['stripe_subscription_id'])) return true;
        if (!preg_match('/^cs_[A-Za-z0-9_]+$/', $sid)) return true;
        $r = IQU_Stripe::post('/checkout/sessions/' . $sid . '/expire', []);
        $closed = $r['ok'];
        if (!$closed) {
            // Stripe refuses to expire a page that is not open; check which it is.
            $g = IQU_Stripe::get('/checkout/sessions/' . $sid);
            $closed = $g['ok'] && (string) ($g['data']['status'] ?? '') !== 'open';
        }
        if ($closed) IQU_Billing_DB::update_account((int) $acc['id'], ['checkout_session_id' => '']);
        return $closed;
    }

    /**
     * Change something that changes what a family pays (agreed fee, course, a student's fee to
     * $0 ...): first close the family's open Checkout page, then save, then price the account
     * again. If the page cannot be closed, the change is still saved and the account is marked
     * "Link outdated — resend". Only for accounts without a subscription (after that the fee is
     * locked). Returns what $save returned.
     */
    public static function change_amount(int $registration_id, callable $save): bool
    {
        $acc  = IQU_Billing_DB::account_for_registration($registration_id);
        $open = $acc && empty($acc['stripe_subscription_id']);
        if ($open && !self::expire_open_session($acc)) {
            IQU_Billing_DB::update_account((int) $acc['id'], ['link_outdated' => 1]);
        }
        $ok = (bool) $save();
        if ($ok && $open) self::reprice_account((int) $acc['id']);
        return $ok;
    }

    /** What the family should pay each month now, in cents, from the students' records. */
    public static function billable_cents(array $acc): int
    {
        $cents = 0;
        foreach (IQU_Billing_DB::get_members((int) $acc['id']) as $m) {
            $reg = IQU_Database::get_registration((int) $m['registration_id']);
            if (!$reg) continue;
            $p = IQU_Billing_Pricing::for_registration($reg);
            if ($p['billable']) $cents += IQU_Billing_Pricing::to_cents((float) $p['net']);
        }
        return $cents;
    }

    /**
     * What the family's billing record is set up to charge, in cents: the students' saved amounts. They are
     * set when the record is created and priced again whenever a student's fee changes (change_amount →
     * reprice_account), so an old Checkout page from before such a change is still caught — but a later
     * change of the general course rates no longer sends every family who checks out afterwards to review
     * (billable_cents() re-priced from today's rates).
     */
    public static function stored_cents(array $acc): int
    {
        $cents = 0;
        foreach (IQU_Billing_DB::get_members((int) $acc['id']) as $m) $cents += max(0, IQU_Billing_Pricing::to_cents((float) $m['amount']));
        return $cents;
    }

    /**
     * checkout.session.completed: the monthly amount the new subscription charges (its items;
     * else the amount kept with the session) compared with billable_cents().
     * @return array{match:bool, charged:?int, expected:int}
     */
    public static function amount_check(array $acc, ?array $sub, array $session): array
    {
        $charged = null;
        foreach ((array) ($sub['items']['data'] ?? []) as $it) {
            // An item without a price is "unknown", never $0 (then the amount kept with the session is used).
            if (!isset($it['price']['unit_amount'])) { $charged = null; break; }
            $charged = (int) $charged + (int) $it['price']['unit_amount'] * max(1, (int) ($it['quantity'] ?? 1));
        }
        if ($charged === null && isset($session['metadata']['iqu_amount_cents'])) $charged = (int) $session['metadata']['iqu_amount_cents'];
        if ($charged === null && (string) ($session['id'] ?? '') !== '' && (string) $session['id'] === (string) ($acc['checkout_session_id'] ?? '')) {
            $charged = IQU_Billing_Pricing::to_cents((float) $acc['checkout_amount']);
        }
        $expected = self::stored_cents($acc);
        return ['match' => $charged === null || $charged === $expected, 'charged' => $charged, 'expected' => $expected];
    }

    /**
     * An old Checkout page set up a subscription at a different amount: the family is not marked
     * active. The account goes to "Review: amount mismatch" with both amounts, and the admins get
     * the usual Telegram alert. The subscription is not cancelled.
     */
    public static function flag_amount_mismatch(array $acc, array $check, string $session_id): void
    {
        $note = sprintf('Stripe Checkout set up %s a month; the billing record is %s a month%s.',
            IQU_Pricing::format(((int) $check['charged']) / 100), IQU_Pricing::format($check['expected'] / 100),
            $session_id !== '' ? ' (Checkout ' . $session_id . ')' : '');
        IQU_Billing_DB::update_account((int) $acc['id'], ['status' => 'review', 'review_reason' => 'amount_mismatch', 'review_note' => substr($note, 0, 255)]);
        if (class_exists('IQU_Billing_Notify')) IQU_Billing_Notify::amount_mismatch(IQU_Billing_DB::get_account((int) $acc['id']) ?: $acc, $note);
    }

    /**
     * An admin has checked the mismatch: clear it, read the real state back from Stripe, and
     * finish a website enrollment that was waiting for it.
     */
    public static function clear_review(int $account_id): bool
    {
        if (!IQU_Billing_DB::update_account($account_id, ['review_reason' => '', 'review_note' => ''])) return false;
        $acc  = IQU_Billing_DB::get_account($account_id);
        $sync = $acc ? IQU_Billing_Sync::sync($acc) : ['ok' => false];
        if (!empty($sync['ok']) && class_exists('IQU_Enrollment')) IQU_Enrollment::on_checkout_completed($sync['account']);
        return true;
    }

    /** Remove a local account that never reached Stripe. */
    private static function delete_local(int $account_id): void
    {
        global $wpdb;
        $wpdb->delete(IQU_Billing_DB::members_table(), ['account_id' => $account_id], ['%d']);
        $wpdb->delete(IQU_Billing_DB::accounts_table(), ['id' => $account_id, 'stripe_customer_id' => ''], ['%d', '%s']);
    }

    public static function short_name(array $reg): string
    {
        $last = trim((string) $reg['last_name']);
        return trim($reg['first_name'] . ($last !== '' ? ' ' . mb_substr($last, 0, 1) . '.' : ''));
    }

    // ------------------------------------------------------------
    // Stripe pages
    // ------------------------------------------------------------

    /**
     * Stripe Checkout in subscription mode. The family adds a bank or card;
     * the monthly subscription is created when they finish.
     * @param array $opts success_args: extra query args on the success URL (enrollment flow:
     *                    enrolled=1). Without it the session is exactly as before.
     * @return array{ok:bool, url:string, error:string, id?:string}
     */
    public static function create_checkout_session(array $acc, array $opts = []): array
    {
        $fail = fn(string $e) => ['ok' => false, 'url' => '', 'error' => $e];

        if (empty($acc['stripe_customer_id'])) return $fail('This billing record is not linked to Stripe.');
        if (!empty($acc['stripe_subscription_id'])) return $fail('Monthly billing is already set up.');

        $members = IQU_Billing_DB::get_members((int) $acc['id']);
        if (!$members) return $fail('No students on this billing record.');

        $items = [];
        foreach ($members as $m) {
            $reg = IQU_Database::get_registration((int) $m['registration_id']);
            if (!$reg) return $fail('A student on this billing record was not found.');
            $p = IQU_Billing_Pricing::for_registration($reg);
            // The amount saved when the account was created (priced on the server then).
            $cents = IQU_Billing_Pricing::to_cents((float) $m['amount']);
            if ($cents <= 0) continue;
            $label = ($p['course_label'] ?: 'Tuition') . ' - ' . self::short_name($reg);
            $desc  = $p['days_per_week'] ? sprintf('%d classes a week (%d a month)', $p['days_per_week'], $p['classes_per_month']) : 'Monthly tuition';
            $items[] = [
                'quantity'   => 1,
                'price_data' => [
                    'currency'     => 'usd',
                    'unit_amount'  => $cents,
                    'recurring'    => ['interval' => 'month'],
                    'product_data' => [
                        'name'        => substr($label, 0, 250),
                        'description' => $desc,
                        'metadata'    => ['iqu_registration_id' => (string) $m['registration_id']],
                    ],
                ],
            ];
        }
        if (!$items) return $fail('Nothing to bill on this record.');

        $link = self::link_for($acc);
        $sub  = [
            'description' => 'Ilm-ul-Quran USA monthly tuition',
            'metadata'    => ['iqu_account_id' => (string) $acc['id'], 'iqu_mode' => (string) $acc['mode']],
        ];

        $first_ts = self::first_charge_timestamp($acc['first_charge_date'] ?? null);
        if ($first_ts) {
            if (self::has_free_first_month($acc)) {
                $sub['trial_end'] = $first_ts;
            } else {
                $sub['billing_cycle_anchor'] = $first_ts;
                $sub['proration_behavior']   = 'none';
            }
        }

        $params = [
            'mode'                   => 'subscription',
            'customer'               => $acc['stripe_customer_id'],
            'client_reference_id'    => (string) $acc['id'],
            'line_items'             => $items,
            'subscription_data'      => $sub,
            'payment_method_options' => ['us_bank_account' => ['verification_method' => 'automatic']],
            'locale'                 => 'en',
            'success_url'            => empty($opts['success_args'])
                ? add_query_arg('setup', 'done', $link)
                : add_query_arg(array_merge(['setup' => 'done'], array_map('strval', (array) $opts['success_args'])), $link),
            'cancel_url'             => $link,
            'metadata'               => ['iqu_account_id' => (string) $acc['id'], 'iqu_mode' => (string) $acc['mode']],
            'managed_payments'       => ['enabled' => false], // tuition is never sold through Managed Payments
        ];
        if ($first_ts) {
            $params['custom_text'] = ['submit' => ['message' => 'Nothing is charged today. Your first payment is on ' . gmdate('j F Y', $first_ts) . ', then on the same date every month.']];
        }

        // The amount this page sets up, kept with the session (and on the record below) so an old
        // page can be recognised; the webhook compares the subscription with the current amount.
        $cents = array_sum(array_map(fn($i) => (int) $i['price_data']['unit_amount'] * (int) $i['quantity'], $items));
        $params['metadata']['iqu_amount_cents'] = (string) $cents;
        // One page at a time per family (a double tap, two tabs): atomic lock, released below.
        $lock = 'iqu_co_lock_' . (int) $acc['id'];
        if (!add_option($lock, time(), '', 'no')) {
            if ((int) get_option($lock) > time() - 60) return $fail('Your payment page is being prepared. Please try again in a moment.');
            update_option($lock, time(), 'no');
        }
        try {
            // One open page per family: close the previous one first. If it cannot be closed, no second
            // page is made (both could be completed — two subscriptions).
            $previous_closed = self::expire_open_session(IQU_Billing_DB::get_account((int) $acc['id']) ?: $acc);
            if (!$previous_closed) return $fail('The previous payment page could not be closed. Please try again in a minute.');
            $res = IQU_Stripe::post('/checkout/sessions', $params);
        } finally {
            delete_option($lock);
        }
        if (!$res['ok'] || empty($res['data']['url'])) return $fail('Could not open the payment page: ' . $res['error']) + ['code' => (string) ($res['code'] ?? '')];
        $set = [
            'checkout_session_id' => substr((string) ($res['data']['id'] ?? ''), 0, 100),
            'checkout_amount'     => round($cents / 100, 2),
            'checkout_created_at' => current_time('mysql', true),
        ];
        if ($previous_closed) $set['link_outdated'] = 0;
        IQU_Billing_DB::update_account((int) $acc['id'], $set);
        return ['ok' => true, 'url' => (string) $res['data']['url'], 'error' => '', 'id' => (string) ($res['data']['id'] ?? '')];
    }

    /** Stripe customer portal: change bank or card, see invoices. */
    public static function create_portal_session(array $acc): array
    {
        if (empty($acc['stripe_customer_id'])) return ['ok' => false, 'url' => '', 'error' => 'This billing record is not linked to Stripe.'];
        $res = IQU_Stripe::post('/billing_portal/sessions', [
            'customer'   => $acc['stripe_customer_id'],
            'return_url' => self::link_for($acc),
        ]);
        if (!$res['ok'] || empty($res['data']['url'])) return ['ok' => false, 'url' => '', 'error' => 'Could not open the billing portal: ' . $res['error']];
        return ['ok' => true, 'url' => (string) $res['data']['url'], 'error' => ''];
    }
}
