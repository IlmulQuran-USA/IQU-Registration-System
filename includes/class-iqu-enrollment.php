<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Enrollment
 *
 * Enroll for Free → Stripe (Billing Settings → Enrollment → "Enroll for Free payment: On").
 *
 * At submit (IQU_Form::handle_ajax_submit):
 *   - Exceptions are saved as "pending_review" and never sent to Stripe:
 *       help              a Zakat coupon was used, or the monthly amount is $0
 *       existing_student  "Are you already an Ilm-ul-Quran student?" = Yes
 *       sibling           the email already has a billing account in this mode
 *   - Everyone else is saved as "card_pending"; the billing account is created through
 *     IQU_Billing_Service (new student → free first month) and the family is sent straight
 *     to Stripe Checkout to add a card or US bank account.
 *
 * Completion: only the Stripe webhook (checkout.session.completed → existing sync) marks the
 * registrations "enrolled", then sends the welcome email, Telegram "✅ Enrolled" and Meta
 * CompleteRegistration. Returning to the success URL never completes anything.
 * A card issued outside the US / Canada is flagged "card_country" (never cancelled).
 *
 * Reminders (hourly WP-Cron iqu_enroll_reminders): email after 24 h and 72 h (once each),
 * "expired" after 7 days. Nothing is deleted.
 */
class IQU_Enrollment
{
    public const CRON = 'iqu_enroll_reminders';

    // reminders_sent bits
    public const BIT_24H    = 1;
    public const BIT_72H    = 2;
    public const BIT_SUMMER = 4;

    public const THANKS_REVIEW = 'We received your enrollment. Our team will contact you within 1–2 days to confirm the fee.';
    public const TO_STRIPE     = 'Taking you to our secure payment page…';

    private const EXPIRE_DAYS = 7;
    private const ACTIVE      = ['free_month', 'waiting_first_charge', 'active'];

    public static function init(): void
    {
        add_action(self::CRON, [__CLASS__, 'run_reminders']);
    }

    public static function schedule(): void
    {
        if (!wp_next_scheduled(self::CRON)) {
            wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CRON);
        }
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::CRON);
    }

    private static function now(): int
    {
        return (int) current_time('timestamp', true);
    }

    // ------------------------------------------------------------
    // Submit
    // ------------------------------------------------------------

    /**
     * Decide the route before the registration is saved. Sets $clean['status'],
     * review reasons and the saved fbp / fbc.
     * @return array{route?:string, reasons?:string[], error?:array}
     */
    public static function prepare_free(array &$clean, array $post): array
    {
        $answer = sanitize_key((string) wp_unslash($post['is_existing_student'] ?? ''));
        if (!in_array($answer, ['yes', 'no'], true)) {
            return ['error' => [
                'message' => 'Please fix the errors below.',
                'errors'  => ['is_existing_student' => 'Please tell us whether your child is already an Ilm-ul-Quran student.'],
                'code'    => 'validation_failed',
            ]];
        }

        $reasons = [];
        if (!empty($clean['coupon_code']) || (float) ($clean['payment_amount'] ?? 0) <= 0) $reasons[] = 'help';
        if ($answer === 'yes') $reasons[] = 'existing_student';
        if (self::account_for_email((string) ($clean['email'] ?? ''))) $reasons[] = 'sibling';

        if ($reasons) {
            $clean['status'] = 'pending_review';
            $clean['review_reason'] = self::merge_reasons((string) ($clean['review_reason'] ?? ''), $reasons);
            return ['route' => 'review', 'reasons' => $reasons];
        }

        $clean['status'] = 'card_pending';
        $ids = IQU_Pixel::browser_ids();
        $clean['fbp'] = $ids['fbp'];
        $clean['fbc'] = $ids['fbc'];
        return ['route' => 'card', 'reasons' => []];
    }

    private static function merge_reasons(string $have, array $add): string
    {
        $all = array_values(array_unique(array_merge(array_filter(explode(',', $have)), $add)));
        return substr(implode(',', $all), 0, 100);
    }

    /** A billing account in this mode (not canceled) that already uses this email. */
    public static function account_for_email(string $email): ?array
    {
        global $wpdb;
        if (!is_email($email) || !class_exists('IQU_Billing_DB')) return null;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . IQU_Billing_DB::accounts_table() . ' WHERE LOWER(contact_email) = LOWER(%s) AND mode = %s AND status <> %s ORDER BY id DESC LIMIT 1',
            $email, IQU_Stripe::expected_mode(), 'canceled'
        ), ARRAY_A);
        return $row ?: null;
    }

    /**
     * After the registration is saved (emails, Telegram and Lead already sent as before).
     * Card route: billing account + Stripe Checkout; the response gets checkout_url.
     * Any Stripe problem → pending_review with the normal thank-you (never a dead end).
     */
    public static function start_free(int $reg_id, array $clean, array $prep, array $response): array
    {
        $response['enrollment'] = $prep['route'];

        if ($prep['route'] === 'review') {
            self::telegram('🔎 Enrollment — pending review', self::reg_rows($clean, $reg_id) + [
                'Why' => IQU_Telegram::esc(implode(', ', array_map([__CLASS__, 'reason_label'], $prep['reasons']))),
            ], 'No payment step. Please contact the family to confirm the fee.');
            $response['message'] = self::THANKS_REVIEW;
            return $response;
        }

        $res = IQU_Billing_Service::create_account([$reg_id], []);
        if (!$res['ok']) return self::fallback($reg_id, $clean, $response, implode(' ', $res['errors']));

        $acc = IQU_Billing_DB::get_account((int) $res['account_id']);
        if (!$acc) return self::fallback($reg_id, $clean, $response, 'billing account not readable');
        IQU_Database::update_enrollment($reg_id, ['billing_account_id' => (int) $acc['id']]);

        $co = IQU_Billing_Service::create_checkout_session($acc, ['success_args' => ['enrolled' => '1']]);
        if (!$co['ok']) return self::fallback($reg_id, $clean, $response, $co['error']);
        IQU_Database::update_enrollment($reg_id, ['pay_session_id' => substr((string) ($co['id'] ?? ''), 0, 100)]);

        self::telegram('🕓 Enrollment started — card pending', self::reg_rows($clean, $reg_id) + [
            'Monthly' => IQU_Telegram::esc(IQU_Pricing::format((float) $acc['net_amount'])) . ' (first month free)',
        ], 'Sent to Stripe to add a card or bank account. Completes when Stripe confirms.');

        $response['message']      = self::TO_STRIPE;
        $response['checkout_url'] = $co['url'];
        return $response;
    }

    private static function fallback(int $reg_id, array $clean, array $response, string $why): array
    {
        error_log('[IQU] Enrollment card step could not start for #' . $reg_id . ': ' . $why);
        IQU_Database::set_status($reg_id, 'pending_review');
        IQU_Database::add_review_reason($reg_id, 'payment_setup');
        self::telegram('⚠️ Enrollment — card step could not start', self::reg_rows($clean, $reg_id), 'Saved as Pending review. Please contact the family.');
        $response['enrollment'] = 'review';
        $response['message']    = self::THANKS_REVIEW;
        unset($response['checkout_url']);
        return $response;
    }

    public static function reason_label(string $r): string
    {
        $labels = [
            'help' => 'Zakat / help with the fee', 'existing_student' => 'Existing student', 'sibling' => 'Email already has billing',
            'location' => 'Location', 'card_country' => 'Card country', 'payment_setup' => 'Card step failed', 'amount' => 'Amount',
        ];
        return $labels[$r] ?? $r;
    }

    // ------------------------------------------------------------
    // Webhook: checkout.session.completed
    // ------------------------------------------------------------

    /**
     * Called by IQU_Billing_Webhook after the existing sync of a checkout.session.completed
     * event. Never throws.
     */
    public static function on_checkout_completed(array $acc): void
    {
        try {
            if (!in_array((string) $acc['status'], self::ACTIVE, true)) return;

            $done = [];
            foreach (IQU_Billing_DB::get_members((int) $acc['id']) as $m) {
                $reg = IQU_Database::get_registration((int) $m['registration_id']);
                if ($reg && $reg['status'] === 'card_pending' && IQU_Database::set_status((int) $reg['id'], 'enrolled')) {
                    $reg['status'] = 'enrolled';
                    $done[] = $reg;
                }
            }
            if (!$done) return;

            [$country, $method] = self::payment_country($acc);
            $kids = implode(', ', array_map(fn($r) => trim($r['first_name'] . ' ' . $r['last_name']), $done));

            foreach ($done as $reg) {
                IQU_Pixel::send_server_event('CompleteRegistration', $reg, 'enroll_' . (int) $reg['id'], [
                    'content_name' => 'Free Enrollment',
                    'value'        => 0,
                    'currency'     => 'USD',
                ]);
            }

            self::welcome_email($acc, $done);

            $first = $acc['next_charge_at'] ?: ($acc['first_charge_date'] ? $acc['first_charge_date'] . ' 15:00:00' : null);
            self::telegram('✅ Enrolled', [
                'Student'      => IQU_Telegram::esc($kids),
                'Email'        => IQU_Telegram::esc((string) $acc['contact_email']),
                'Monthly'      => IQU_Telegram::esc(IQU_Pricing::format((float) $acc['net_amount'])),
                'First charge' => IQU_Telegram::esc($first ? wp_date('j M Y', (int) strtotime($first . ' UTC')) : '—'),
                'Method'       => IQU_Telegram::esc((string) ($acc['payment_method_label'] ?: $method)),
            ], 'Card or bank confirmed by Stripe. First month free.');

            if ($country !== '' && !in_array($country, ['US', 'CA'], true)) {
                foreach ($done as $reg) IQU_Database::add_review_reason((int) $reg['id'], 'card_country');
                self::telegram('⚠️ Review: card country', [
                    'Student' => IQU_Telegram::esc($kids),
                    'Card'    => IQU_Telegram::esc($country),
                ], 'The card was issued outside the US and Canada. Nothing was cancelled — please check with the family.');
            }
        } catch (\Throwable $e) {
            error_log('[IQU] Enrollment completion failed for account #' . (int) ($acc['id'] ?? 0) . ': ' . $e->getMessage());
        }
    }

    /**
     * Issuing country of the subscription's payment method:
     * card → card.country; US bank account → US; Link and others → '' (not flagged).
     * @return array{0:string,1:string} country, method label
     */
    public static function payment_country(array $acc): array
    {
        $sub = (string) ($acc['stripe_subscription_id'] ?? '');
        if (!preg_match('/^sub_[A-Za-z0-9]+$/', $sub)) return ['', ''];
        $r = IQU_Stripe::get('/subscriptions/' . $sub, ['expand' => ['default_payment_method']]);
        if (!$r['ok'] || (string) ($r['data']['customer'] ?? '') !== (string) $acc['stripe_customer_id']) return ['', ''];
        $pm = $r['data']['default_payment_method'] ?? null;
        if (!is_array($pm)) return ['', ''];
        $type = (string) ($pm['type'] ?? '');
        if ($type === 'card') {
            $c = strtoupper((string) ($pm['card']['country'] ?? ''));
            return [preg_match('/^[A-Z]{2}$/', $c) ? $c : '', 'Card'];
        }
        if ($type === 'us_bank_account') return ['US', 'US bank account'];
        return ['', $type === 'link' ? 'Link' : ucfirst(str_replace('_', ' ', $type))];
    }

    private static function welcome_email(array $acc, array $regs): void
    {
        $to = (string) $acc['contact_email'];
        if (!is_email($to)) return;
        $kids = implode(', ', array_map(fn($r) => trim((string) $r['first_name']), $regs));
        $facts = [];
        foreach ($regs as $r) {
            $p = IQU_Billing_Pricing::for_registration($r);
            $facts[trim($r['first_name'] . ' ' . $r['last_name'])] = ($p['course_label'] ?: 'Classes')
                . ($p['days_per_week'] ? ' · ' . $p['days_per_week'] . ' classes a week' : '');
        }
        $first = $acc['first_charge_date'] ? wp_date('j F Y', (int) strtotime($acc['first_charge_date'] . ' 15:00:00 UTC')) : '';
        $facts['Monthly tuition'] = IQU_Pricing::format((float) $acc['net_amount']);
        if ($first !== '') $facts['First payment'] = $first . ' (after the free first month)';

        IQU_Billing_Email::send($to, 'Welcome to Ilm-ul-Quran USA — enrollment complete', [
            'preheader' => "{$kids}'s enrollment is complete. The first month is free.",
            'greeting'  => 'Assalamu alaikum,',
            'blocks'    => [
                ['p', "JazakAllahu Khairan! {$kids}'s enrollment at Ilm-ul-Quran USA is complete, and your payment method is saved securely with Stripe."],
                ['facts', $facts],
                ['p', 'Nothing is charged during the free first month. After that, tuition is taken automatically on the same date every month, and you will get a receipt by email each time.'],
                ['p', "Our team will contact you on WhatsApp within 24–48 hours to arrange the class schedule, in-sha'-Allah."],
                ['button', 'Open my billing page', IQU_Billing_Service::link_for($acc)],
                ['note', 'Please keep this link private. If the fee ever becomes a burden, just reply here — no child\'s place is ever affected by cost.'],
            ],
        ]);
    }

    // ------------------------------------------------------------
    // Reminders and expiry (hourly)
    // ------------------------------------------------------------

    public static function run_reminders(): void
    {
        global $wpdb;
        if (!IQU_Database::schema_ready()) return;
        $now = self::now();
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . $wpdb->prefix . IQU_TABLE_NAME . ' WHERE form_type = %s AND status = %s AND created_at <= %s ORDER BY id LIMIT 200',
            IQU_Database::FORM_FREE, 'card_pending', gmdate('Y-m-d H:i:s', $now - DAY_IN_SECONDS)
        ), ARRAY_A) ?: [];

        foreach ($rows as $reg) {
            $id   = (int) $reg['id'];
            $age  = $now - (int) strtotime($reg['created_at'] . ' UTC');
            $bits = (int) $reg['reminders_sent'];

            if ($age >= self::EXPIRE_DAYS * DAY_IN_SECONDS) {
                IQU_Database::set_status($id, 'expired');
                continue;
            }

            $acc = class_exists('IQU_Billing_DB') ? IQU_Billing_DB::account_for_registration($id) : null;
            // Card already added (the webhook will complete it), or no account to link to: no reminder.
            if (!$acc || !empty($acc['stripe_subscription_id']) || in_array((string) $acc['status'], self::ACTIVE, true)) continue;

            if ($age >= 72 * HOUR_IN_SECONDS && !($bits & self::BIT_72H)) {
                self::reminder_email($reg, $acc, true);
                IQU_Database::update_enrollment($id, ['reminders_sent' => $bits | self::BIT_72H | self::BIT_24H]);
            } elseif ($age < 72 * HOUR_IN_SECONDS && !($bits & self::BIT_24H)) {
                self::reminder_email($reg, $acc, false);
                IQU_Database::update_enrollment($id, ['reminders_sent' => $bits | self::BIT_24H]);
            }
        }
    }

    private static function reminder_email(array $reg, array $acc, bool $last): void
    {
        $to = (string) $reg['email'];
        if (!is_email($to)) return;
        $kid = trim((string) $reg['first_name']);
        IQU_Billing_Email::send($to, $last ? "Last reminder: finish {$kid}'s enrollment" : "One step left to enroll {$kid}", [
            'preheader' => "Add a card or US bank account to finish {$kid}'s enrollment. The first month is free.",
            'greeting'  => 'Assalamu alaikum,',
            'blocks'    => [
                ['p', "You started enrolling {$kid} at Ilm-ul-Quran USA. There is one step left: add a card or US bank account on Stripe's secure page."],
                ['p', 'Nothing is charged today — the first month is free.'],
                ['button', 'Finish enrollment', IQU_Billing_Service::link_for($acc)],
                ['note', $last
                    ? 'If we do not hear from you, this enrollment will close in a few days. You can enroll again at any time. If the fee is a burden, just reply here — no child\'s place is ever affected by cost.'
                    : 'If you have a question, or the fee is a burden for your family, just reply here — no child\'s place is ever affected by cost.'],
            ],
        ]);
    }

    // ------------------------------------------------------------
    // Family page (/my-billing) after Stripe: confirmed yet?
    // ------------------------------------------------------------

    /**
     * 'complete' when the webhook has enrolled the family's registrations,
     * 'confirming' while any is still card_pending, '' otherwise.
     */
    public static function portal_state(array $regs): string
    {
        $statuses = array_map(fn($r) => (string) ($r['status'] ?? ''), $regs);
        if (in_array('card_pending', $statuses, true)) return 'confirming';
        if (in_array('enrolled', $statuses, true)) return 'complete';
        return '';
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private static function reg_rows(array $r, int $reg_id): array
    {
        return [
            'Student' => IQU_Telegram::esc(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''))),
            'Email'   => IQU_Telegram::esc((string) ($r['email'] ?? '')),
            'Ref'     => IQU_Telegram::link(admin_url('admin.php?page=iqu-view-registration&id=' . $reg_id), '#' . $reg_id),
        ];
    }

    private static function telegram(string $title, array $rows, string $footer = ''): void
    {
        if (!class_exists('IQU_Telegram') || !IQU_Telegram::is_configured()) return;
        IQU_Telegram::send(IQU_Telegram::compose($title, $rows, $footer));
    }
}
