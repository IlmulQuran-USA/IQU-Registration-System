<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Summer_Pay
 *
 * Summer → one Stripe payment for the whole program (Billing Settings → Enrollment →
 * "Summer payment: Stripe"; in Stripe test mode only for logged-in administrators).
 *
 *   Standard (form value 50)      → iqu_enroll_summer_fee            via Stripe Checkout
 *   Supported (form value 30)     → iqu_enroll_summer_fee_supported  via Stripe Checkout
 *   Existing student (complimentary) → free as before, plus review reason existing_student
 *   Flexible / Free               → pending_review (help), no Stripe
 * The amount always comes from the settings on the server, never from the browser.
 *
 * Completion: only checkout.session.completed (mode=payment, iqu_kind=summer), after the
 * session is read back from Stripe and its amount, metadata, mode and id are checked.
 * Unpaid: a signed resume link (HMAC with the WordPress salts) opens a fresh session;
 * one reminder email after 24 h.
 */
class IQU_Summer_Pay
{
    public const PAY_ARG = 'iqu_pay';
    private const RL_MAX  = 10; // resume-link uses per visitor per hour

    public static function init(): void
    {
        add_action('template_redirect', [__CLASS__, 'maybe_handle_link'], 1);
    }

    // ------------------------------------------------------------
    // Submit
    // ------------------------------------------------------------

    /**
     * Before the registration is saved (Stripe mode only). Sets amount, method and status.
     * @return array{route:string, reason?:string}  card | review | free
     */
    public static function prepare(array &$clean): array
    {
        $fee = (string) ($clean['admission_fee'] ?? '');

        if ($fee === 'complimentary') {
            $clean['review_reason'] = self::merge((string) ($clean['review_reason'] ?? ''), 'existing_student');
            return ['route' => 'free'];
        }
        if ($fee === 'flexible') {
            $clean['status']         = 'pending_review';
            $clean['payment_method'] = '';
            $clean['transaction_id'] = '';
            $clean['review_reason']  = self::merge((string) ($clean['review_reason'] ?? ''), 'help');
            return ['route' => 'review', 'reason' => 'help'];
        }

        // Standard or Supported: amount from the settings; Zelle is not offered in Stripe mode.
        $amount = IQU_Enrollment_Settings::summer_fee($fee === '30' ? 'supported' : 'standard');
        $clean['payment_amount'] = $amount;
        $clean['payment_method'] = 'stripe';
        $clean['transaction_id'] = '';
        if ($amount < 1.00) {
            // Stripe's minimum is $0.50; anything under $1.00 is checked by the team instead.
            $clean['status']         = 'pending_review';
            $clean['payment_status'] = 'pending';
            $clean['review_reason']  = self::merge((string) ($clean['review_reason'] ?? ''), 'amount');
            return ['route' => 'review', 'reason' => 'amount'];
        }
        $clean['payment_status'] = 'stripe_pending';
        $ids = IQU_Pixel::browser_ids();
        $clean['fbp'] = $ids['fbp'];
        $clean['fbc'] = $ids['fbc'];
        return ['route' => 'card'];
    }

    private static function merge(string $have, string $add): string
    {
        $all = array_values(array_unique(array_merge(array_filter(explode(',', $have)), [$add])));
        return substr(implode(',', $all), 0, 100);
    }

    /**
     * After the registration is saved and the usual emails / Telegram / Lead went out.
     * Card route: open Stripe Checkout; the response gets payment.checkout_url.
     */
    public static function start(int $reg_id, array $prep, array $response): array
    {
        unset($response['payment']['zeffy_url']); // Stripe mode never sends anyone to Zeffy
        if ($prep['route'] === 'review') {
            $response['message'] = IQU_Enrollment::THANKS_REVIEW;
            $response['payment']['method'] = '';
            return $response;
        }
        if ($prep['route'] !== 'card') return $response;

        $reg = IQU_Database::get_registration($reg_id);
        $co  = $reg ? self::create_session($reg, 'v1') : ['ok' => false, 'error' => 'registration not found'];
        if (empty($co['ok'])) {
            error_log('[IQU] Summer payment page could not open for #' . $reg_id . ': ' . ($co['error'] ?? ''));
            IQU_Database::set_status($reg_id, 'pending_review');
            IQU_Database::add_review_reason($reg_id, 'payment_setup');
            $response['message'] = IQU_Enrollment::THANKS_REVIEW;
            return $response;
        }
        $response['message'] = IQU_Enrollment::TO_STRIPE;
        $response['payment']['checkout_url'] = $co['url'];
        return $response;
    }

    /**
     * A Checkout Session (mode=payment) for one Summer registration at its saved amount.
     * @return array{ok:bool, url:string, id:string, error:string}
     */
    public static function create_session(array $reg, string $attempt): array
    {
        $fail  = fn(string $e) => ['ok' => false, 'url' => '', 'id' => '', 'error' => $e];
        $cents = IQU_Billing_Pricing::to_cents((float) $reg['payment_amount']);
        if ($cents < 100) return $fail('amount below 1.00');
        $id    = (int) $reg['id'];
        $mode  = IQU_Stripe::expected_mode();
        $first = trim((string) $reg['first_name']);
        $meta  = ['iqu_kind' => 'summer', 'iqu_registration_id' => (string) $id, 'iqu_mode' => $mode];

        $res = IQU_Stripe::post('/checkout/sessions', [
            'mode'                 => 'payment',
            'line_items'           => [[
                'quantity'   => 1,
                'price_data' => [
                    'currency'     => 'usd',
                    'unit_amount'  => $cents,
                    'product_data' => [
                        'name'     => substr('Summer Ilm Camp — ' . $first, 0, 250),
                        'metadata' => ['iqu_registration_id' => (string) $id],
                    ],
                ],
            ]],
            'payment_method_types' => ['card', 'link'],
            'customer_email'       => (string) $reg['email'],
            'metadata'             => $meta,
            'payment_intent_data'  => [
                'description' => substr('Summer Ilm Camp — ' . trim($first . ' ' . $reg['last_name']) . ' (IQU-' . $id . ')', 0, 250),
                'metadata'    => $meta,
            ],
            'submit_type'          => 'pay',
            'locale'               => 'en',
            'success_url'          => self::link_for($id, 'done'),
            'cancel_url'           => self::link_for($id, 'cancelled'),
            'managed_payments'     => ['enabled' => false],
        ], 'iqu-summer-' . $mode . '-' . $id . '-' . $attempt);

        if (!$res['ok'] || empty($res['data']['url']) || empty($res['data']['id'])) return $fail((string) $res['error']);
        IQU_Database::update_enrollment($id, ['pay_session_id' => substr((string) $res['data']['id'], 0, 100)]);
        return ['ok' => true, 'url' => (string) $res['data']['url'], 'id' => (string) $res['data']['id'], 'error' => ''];
    }

    // ------------------------------------------------------------
    // Webhook: checkout.session.completed with iqu_kind = summer
    // ------------------------------------------------------------

    /**
     * Called by IQU_Billing_Webhook::process() before the billing-account lookup.
     * Throws only when Stripe cannot be read (so Stripe retries the event).
     * @return array{0:string,1:null,2:string} result, account id (none), note
     */
    public static function on_session_completed(string $session_id): array
    {
        if (!preg_match('/^cs_[A-Za-z0-9_]+$/', $session_id)) return ['summer_bad_session', null, ''];
        $r = IQU_Stripe::get('/checkout/sessions/' . $session_id, ['expand' => ['payment_intent.payment_method']]);
        if (!$r['ok']) throw new RuntimeException('summer session read failed');
        $s  = $r['data'];
        $md = (array) ($s['metadata'] ?? []);
        $id = (int) ($md['iqu_registration_id'] ?? 0);
        $reg = $id ? IQU_Database::get_registration($id) : null;

        $problem = '';
        if (($md['iqu_kind'] ?? '') !== 'summer')                                   $problem = 'not a summer session';
        elseif (($md['iqu_mode'] ?? '') !== IQU_Stripe::expected_mode())             $problem = 'other mode';
        elseif (!$reg || !in_array($reg['form_type'], [IQU_Database::FORM_SUMMER_LEVEL1, IQU_Database::FORM_SUMMER_LEVEL2], true)) $problem = 'registration not found';
        elseif (($s['mode'] ?? '') !== 'payment')                                    $problem = 'not a one-time payment';
        elseif (($s['payment_status'] ?? '') !== 'paid')                             $problem = 'not paid';
        elseif (strtolower((string) ($s['currency'] ?? '')) !== 'usd')               $problem = 'currency';
        elseif ((string) $reg['pay_session_id'] !== (string) ($s['id'] ?? ''))       $problem = 'session id does not match the registration';
        elseif ((int) ($s['amount_total'] ?? -1) !== IQU_Billing_Pricing::to_cents((float) $reg['payment_amount'])) $problem = 'amount does not match the summer fee';

        if ($problem !== '') {
            error_log('[IQU] Summer payment not applied (' . $session_id . '): ' . $problem);
            self::telegram('⚠️ Summer payment needs a check', [
                'Registration' => $reg ? IQU_Telegram::link(admin_url('admin.php?page=iqu-view-registration&id=' . $id), '#' . $id) : '—',
                'Session'      => IQU_Telegram::esc($session_id),
                'Problem'      => IQU_Telegram::esc($problem),
            ], 'Not marked as paid. Please check in Stripe.');
            return ['summer_mismatch', null, substr($problem, 0, 255)];
        }
        if ($reg['payment_status'] === 'paid') return ['summer_already_paid', null, 'IQU-' . $id];

        $pi = $s['payment_intent'] ?? null;
        $pi_id = is_array($pi) ? (string) ($pi['id'] ?? '') : (string) $pi;
        $amount = ((int) $s['amount_total']) / 100;
        IQU_Database::update_payment($id, [
            'payment_status' => 'paid',
            'payment_amount' => $amount,
            'transaction_id' => substr($pi_id, 0, 100),
            'payment_method' => 'stripe',
        ]);
        IQU_Database::set_status($id, 'paid');
        $reg = IQU_Database::get_registration($id) ?: $reg;

        // Card issued outside the US / Canada: flag only, never refund or cancel.
        $country = '';
        $pm = is_array($pi) ? ($pi['payment_method'] ?? null) : null;
        if (is_array($pm) && ($pm['type'] ?? '') === 'card') $country = strtoupper((string) ($pm['card']['country'] ?? ''));

        IQU_Pixel::send_server_event('Purchase', $reg, 'summer_' . $id, [
            'content_name' => 'Summer Ilm Camp',
            'value'        => $amount,
            'currency'     => 'USD',
        ]);
        self::confirmation_email($reg, $amount);
        $name = trim($reg['first_name'] . ' ' . $reg['last_name']);
        self::telegram('✅ Summer payment', [
            'Student' => IQU_Telegram::esc($name),
            'Level'   => IQU_Telegram::esc(strtoupper((string) $reg['enrollment_level'])),
            'Amount'  => IQU_Telegram::esc(IQU_Pricing::format($amount)),
            'Email'   => IQU_Telegram::esc((string) $reg['email']),
            'Ref'     => IQU_Telegram::link(admin_url('admin.php?page=iqu-view-registration&id=' . $id), '#' . $id),
        ], 'Paid through Stripe (confirmed by the webhook).');
        if (preg_match('/^[A-Z]{2}$/', $country) && !in_array($country, ['US', 'CA'], true)) {
            IQU_Database::add_review_reason($id, 'card_country');
            self::telegram('⚠️ Review: card country', ['Student' => IQU_Telegram::esc($name), 'Card' => IQU_Telegram::esc($country)],
                'Summer payment with a card issued outside the US and Canada. Nothing was refunded — please check with the family.');
        }
        return ['ok', null, 'summer IQU-' . $id];
    }

    private static function confirmation_email(array $reg, float $amount): void
    {
        $to = (string) $reg['email'];
        if (!is_email($to)) return;
        $kid = trim((string) $reg['first_name']);
        IQU_Billing_Email::send($to, 'Summer Ilm Camp — payment received', [
            'title'     => 'Summer Ilm Camp — payment received',
            'preheader' => "We received your payment for {$kid}'s place in the Summer Ilm Camp.",
            'greeting'  => 'Assalamu alaikum,',
            'blocks'    => [
                ['p', "JazakAllahu khayran! We received your payment for {$kid}'s place in the Summer Ilm Camp. It covers the whole program."],
                ['table', [
                    ['Student', trim($reg['first_name'] . ' ' . $reg['last_name']) . ' (IQU-' . (int) $reg['id'] . ')'],
                    ['Program', 'Summer Ilm Camp — ' . ($reg['enrollment_level'] === 'level2' ? 'Level 2 (ages 11–15)' : 'Level 1 (ages 5–10)')],
                    ['Amount paid', IQU_Pricing::format($amount) . ' — the whole program'],
                    ['Reference', 'IQU-' . (int) $reg['id']],
                ]],
                ['list', 'Good to know', [
                    "Our team will contact you on WhatsApp with the class schedule, in-sha'-Allah.",
                    'Please keep this email as your confirmation.',
                ]],
            ],
        ]);
    }

    // ------------------------------------------------------------
    // Signed resume link: /?iqu_pay=summer&r={id}&s={hmac}
    // ------------------------------------------------------------

    private static function secret(): string
    {
        return hash('sha256', wp_salt('secure_auth') . '|iqu-summer-pay|v1', true);
    }

    public static function sig(int $reg_id): string
    {
        return hash_hmac('sha256', 'summer|' . $reg_id, self::secret());
    }

    /** $state: '' = open a fresh payment page; 'done' / 'cancelled' = Stripe's return pages. */
    public static function link_for(int $reg_id, string $state = ''): string
    {
        $args = [self::PAY_ARG => 'summer', 'r' => $reg_id, 's' => self::sig($reg_id)];
        if (in_array($state, ['done', 'cancelled'], true)) $args[$state] = '1';
        return add_query_arg($args, home_url('/'));
    }

    public static function maybe_handle_link(): void
    {
        if (($_GET[self::PAY_ARG] ?? '') !== 'summer') return;
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow');
        header('Referrer-Policy: no-referrer');

        $id  = absint($_GET['r'] ?? 0);
        $sig = (string) ($_GET['s'] ?? '');
        $key = 'iqu_pay_' . substr(hash_hmac('sha256', class_exists('IQU_Geo') ? IQU_Geo::client_ip() : '', wp_salt('nonce')), 0, 32);
        $n = (int) get_transient($key);
        if ($n >= self::RL_MAX) self::page('Please try again later', 'Too many attempts. Please wait a while and try again.');
        set_transient($key, $n + 1, HOUR_IN_SECONDS);

        $reg = ($id && preg_match('/^[a-f0-9]{64}$/', $sig) && hash_equals(self::sig($id), $sig)) ? IQU_Database::get_registration($id) : null;
        if (!$reg || $reg['payment_method'] !== 'stripe' || !in_array($reg['form_type'], [IQU_Database::FORM_SUMMER_LEVEL1, IQU_Database::FORM_SUMMER_LEVEL2], true)) {
            self::page('Link not valid', 'This payment link is not valid. Please contact us at ' . IQU_CONTACT_EMAIL . '.');
        }
        $kid = trim((string) $reg['first_name']);
        if ($reg['payment_status'] === 'paid') {
            self::page('Payment received', "JazakAllahu Khairan! The payment for {$kid}'s Summer Ilm Camp place has been received. A confirmation email is on its way.");
        }
        if (!empty($_GET['done'])) {
            // Back from Stripe: completion comes only from the webhook, so nothing is marked paid here.
            self::page('Thank you', "JazakAllahu Khairan! We are confirming {$kid}'s payment with Stripe. You will receive a confirmation email shortly, in-sha'-Allah.");
        }
        if (!empty($_GET['cancelled'])) {
            self::page('Payment not finished', "The payment for {$kid}'s Summer Ilm Camp place was not finished. Nothing was charged.", self::link_for($id), 'Pay now');
        }
        if (!IQU_Stripe::is_ready()) {
            self::page('Please try again later', 'Online payment is not available right now. Please try again later or contact us at ' . IQU_CONTACT_EMAIL . '.');
        }
        $co = self::create_session($reg, 'r' . intdiv(time(), 60));
        if (empty($co['ok']) || strpos($co['url'], 'https://checkout.stripe.com/') !== 0) {
            self::page('Please try again later', 'We could not open the payment page just now. Please try again in a minute.');
        }
        header('Location: ' . $co['url'], true, 303);
        exit;
    }

    /** Minimal page (no scripts), then stop. */
    private static function page(string $title, string $text, string $button_url = '', string $button = ''): void
    {
        status_header(200);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow"><title>' . esc_html($title) . ' — Ilm-ul-Quran USA</title>'
            . '<style>body{margin:0;background:#F1F1F4;color:#15303F;font:16px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif}'
            . 'main{max-width:560px;margin:40px auto;padding:0 16px}.card{background:#fff;border:1px solid #E5E7EB;border-top:4px solid #1E4D6B;border-radius:16px;padding:24px}'
            . 'h1{font-size:24px;margin:0 0 12px}.btn{display:block;margin-top:18px;min-height:52px;line-height:52px;text-align:center;border-radius:12px;background:#1E4D6B;color:#fff;font-weight:600;text-decoration:none}'
            . '.btn:focus-visible{outline:3px solid #F7941D;outline-offset:3px}</style></head><body><main><div class="card">'
            . '<h1>' . esc_html($title) . '</h1><p>' . esc_html($text) . '</p>'
            . ($button_url !== '' ? '<a class="btn" href="' . esc_url($button_url) . '">' . esc_html($button) . '</a>' : '')
            . '</div></main></body></html>';
        exit;
    }

    // ------------------------------------------------------------
    // Reminder (24 h, once) — run from the hourly enrollment cron
    // ------------------------------------------------------------

    public static function run_reminders(int $now): void
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . $wpdb->prefix . IQU_TABLE_NAME . ' WHERE form_type IN (%s, %s) AND payment_method = %s AND payment_status = %s AND created_at <= %s ORDER BY id LIMIT 200',
            IQU_Database::FORM_SUMMER_LEVEL1, IQU_Database::FORM_SUMMER_LEVEL2, 'stripe', 'stripe_pending', gmdate('Y-m-d H:i:s', $now - DAY_IN_SECONDS)
        ), ARRAY_A) ?: [];
        foreach ($rows as $reg) {
            $bits = (int) $reg['reminders_sent'];
            if ($bits & IQU_Enrollment::BIT_SUMMER) continue;
            if (in_array($reg['status'], ['pending_review', 'cancelled', 'paid'], true)) continue;
            $to  = (string) $reg['email'];
            $kid = trim((string) $reg['first_name']);
            if (is_email($to)) {
                IQU_Billing_Email::send($to, "Finish {$kid}'s Summer Ilm Camp enrollment", [
                    'title'     => 'One step left',
                    'preheader' => "One step left: the payment for {$kid}'s place.",
                    'greeting'  => 'Assalamu alaikum,',
                    'private'   => true,
                    'help'      => 'If the fee is a burden for your family, please talk to us — no child\'s place is ever affected by cost.',
                    'blocks'    => [
                        ['p', "You started enrolling {$kid} in the Summer Ilm Camp. One step is left: the payment."],
                        ['table', [
                            ['Student', trim($reg['first_name'] . ' ' . $reg['last_name'])],
                            ['Program', 'Summer Ilm Camp'],
                            ['Amount', IQU_Pricing::format((float) $reg['payment_amount']) . ' — once, for the whole program'],
                        ]],
                        ['steps', 'What you need to do', [
                            'Press "Pay now" below.',
                            'Pay on Stripe\'s secure page (about 2 minutes).',
                            'That\'s all — you will receive a confirmation by email.',
                        ]],
                        ['button', 'Pay now', self::link_for((int) $reg['id'])],
                    ],
                ]);
            }
            IQU_Database::update_enrollment((int) $reg['id'], ['reminders_sent' => $bits | IQU_Enrollment::BIT_SUMMER]);
        }
    }

    private static function telegram(string $title, array $rows, string $footer = ''): void
    {
        if (!class_exists('IQU_Telegram') || !IQU_Telegram::is_configured()) return;
        IQU_Telegram::send(IQU_Telegram::compose($title, $rows, $footer));
    }
}
