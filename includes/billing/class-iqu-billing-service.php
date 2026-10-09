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
 * - The link token is an HMAC of the account id with a secret derived from
 *   the WordPress salts in wp-config.php. It is never stored; only its
 *   SHA-256 hash is, for lookup. A database leak alone cannot produce links.
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

    private static function token_secret(): string
    {
        return hash('sha256', wp_salt('secure_auth') . '|iqu-billing-link|v1', true);
    }

    public static function token_for(array $acc): string
    {
        return hash_hmac('sha256', $acc['mode'] . '|' . (int) $acc['id'] . '|' . $acc['token_created_at'], self::token_secret());
    }

    public static function link_for(array $acc): string
    {
        return add_query_arg('t', self::token_for($acc), home_url('/my-billing/'));
    }

    /** Find the account for a token from a link. Constant-time compare. */
    public static function account_for_token(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        $acc = IQU_Billing_DB::get_account_by_token_hash(hash('sha256', $token));
        if (!$acc) return null;
        return hash_equals(self::token_for($acc), $token) ? $acc : null;
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
        ]);
    }

    // ------------------------------------------------------------
    // New or current student, and the first charge date
    // ------------------------------------------------------------

    /** 'new' while the free first month is still running, otherwise 'current'. */
    public static function student_type(array $reg): string
    {
        if (($reg['referral'] ?? '') === 'existing_student') return 'current'; // added via Billing; see IQU_Billing_Add_Student::MARKER
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
            if (!$p['billable']) { $errors[] = "{$name}: " . ($p['reason'] ?: 'cannot be billed.'); continue; }
            $regs[$id] = $reg;
            $priced[$id] = $p;
        }
        if ($errors) return $fail($errors);

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

        return ['ok' => true, 'errors' => [], 'account_id' => $account_id];
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
            if (($acc['student_type'] ?? '') === 'new') {
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

        $res = IQU_Stripe::post('/checkout/sessions', $params);
        if (!$res['ok'] || empty($res['data']['url'])) return $fail('Could not open the payment page: ' . $res['error']);
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
