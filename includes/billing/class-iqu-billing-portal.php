<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Billing_Portal
 *
 * The family's page: https://ilmulquranus.org/my-billing/?t=TOKEN
 * Rendered directly (no WordPress page, no theme) so headers and output are
 * fully controlled.
 *
 * Security:
 * - The token is checked by hash lookup + constant-time compare
 *   (IQU_Billing_Service::account_for_token). Only that family's data is shown.
 * - Invalid tokens and "find my link" requests are rate limited per IP;
 *   link requests are also limited per email. The page never says whether
 *   an email is on file.
 * - Headers: no-store (never cached), noindex, no-referrer (the token is not
 *   leaked to other sites), DENY framing, nosniff, strict CSP.
 * - W3 Total Cache is told not to cache (DONOTCACHEPAGE).
 * - Actions are POST only, same-origin checked, and only redirect to
 *   checkout.stripe.com or billing.stripe.com.
 * - Emails are masked; card and bank show the last four digits only.
 */
class IQU_Billing_Portal
{
    private const PATH        = 'my-billing';
    private const CONTACT     = IQU_CONTACT_EMAIL;
    private const RL_BAD      = 20;  // invalid token views per IP per 15 min
    private const RL_FIND_IP  = 5;   // link requests per IP per hour
    private const RL_FIND_EML = 3;   // link emails per address per hour
    private const CONFIRM_TRIES = 6; // enrollment "confirming your card" refreshes (5 s apart)

    public static function init(): void
    {
        add_action('parse_request', [__CLASS__, 'maybe_handle'], 1);
    }

    private static function is_portal_request(): bool
    {
        $path = trim((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
        $home = trim((string) parse_url(home_url('/'), PHP_URL_PATH), '/');
        if ($home !== '' && strpos($path, $home . '/') === 0) $path = substr($path, strlen($home) + 1);
        return $path === self::PATH;
    }

    public static function maybe_handle(): void
    {
        if (!self::is_portal_request()) return;

        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        self::headers();

        if (!class_exists('IQU_Stripe') || !IQU_Stripe::is_ready()) {
            self::page('Billing is not available right now', '<p>Please try again later, or email us at ' . self::contact_link() . '.</p>');
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method === 'POST') self::handle_post();
        self::handle_get();
    }

    private static function headers(): void
    {
        nocache_headers();
        header('Cache-Control: no-store, no-cache, must-revalidate, private, max-age=0');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data: https:; font-src 'self'; form-action 'self' https://checkout.stripe.com https://billing.stripe.com; frame-ancestors 'none'; base-uri 'none'");
    }

    // ------------------------------------------------------------
    // GET
    // ------------------------------------------------------------

    private static function handle_get(): void
    {
        $token = isset($_GET['t']) ? (string) wp_unslash($_GET['t']) : '';
        if ($token === '') self::render_find();

        if (self::limited('bad_' . self::ip(), self::RL_BAD, 15 * MINUTE_IN_SECONDS, false)) {
            self::page('Too many attempts', '<p>Please wait a few minutes and try again.</p>');
        }

        $acc = IQU_Billing_Service::account_for_token($token);
        if (!$acc) {
            self::limited('bad_' . self::ip(), self::RL_BAD, 15 * MINUTE_IN_SECONDS, true);
            self::render_find('This link is not valid any more. Enter your email and we will send you the current one.');
        }

        // Just back from Stripe: refresh straight away instead of waiting for the webhook.
        if (isset($_GET['setup']) && $_GET['setup'] === 'done') {
            $sync = IQU_Billing_Sync::sync($acc);
            if ($sync['ok']) $acc = $sync['account'];
        }

        self::render_account($acc, $token);
    }

    // ------------------------------------------------------------
    // POST
    // ------------------------------------------------------------

    private static function handle_post(): void
    {
        // Same-origin check. Browsers send Origin on POST; a mismatch is refused.
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin !== '' && strtolower(rtrim($origin, '/')) !== strtolower(rtrim(home_url(), '/'))) {
            status_header(403);
            self::page('Not allowed', '<p>This request was refused.</p>');
        }

        $do = (string) ($_POST['do'] ?? '');

        if ($do === 'find') self::handle_find();

        $token = (string) wp_unslash($_POST['t'] ?? '');
        $acc   = IQU_Billing_Service::account_for_token($token);
        if (!$acc) {
            self::limited('bad_' . self::ip(), self::RL_BAD, 15 * MINUTE_IN_SECONDS, true);
            self::render_find('This link is not valid any more. Enter your email and we will send you the current one.');
        }

        if ($do === 'checkout') {
            $r = IQU_Billing_Service::create_checkout_session($acc);
            if ($r['ok']) self::to_stripe($r['url'], 'checkout.stripe.com');
            self::render_account($acc, $token, 'We could not open the payment page just now. Please try again in a minute.');
        }

        if ($do === 'portal') {
            $r = IQU_Billing_Service::create_portal_session($acc);
            if ($r['ok']) self::to_stripe($r['url'], 'billing.stripe.com');
            self::render_account($acc, $token, 'We could not open that page just now. Please try again in a minute.');
        }

        self::render_account($acc, $token);
    }

    private static function to_stripe(string $url, string $host): void
    {
        if (strtolower((string) parse_url($url, PHP_URL_HOST)) !== $host || strpos($url, 'https://') !== 0) {
            self::page('Something went wrong', '<p>Please try again, or email us at ' . self::contact_link() . '.</p>');
        }
        header('Location: ' . $url, true, 303);
        exit;
    }

    // ------------------------------------------------------------
    // Find my link
    // ------------------------------------------------------------

    private static function handle_find(): void
    {
        $done = 'If this email is in our records, your link is on its way. Please check your spam folder too.';

        if (self::limited('find_' . self::ip(), self::RL_FIND_IP, HOUR_IN_SECONDS, true)) {
            self::render_find('', 'Too many requests. Please try again in an hour.');
        }

        $email = sanitize_email((string) wp_unslash($_POST['email'] ?? ''));
        if (!is_email($email)) self::render_find('', 'Please enter a valid email address.');

        if (!self::limited('find_eml_' . strtolower($email), self::RL_FIND_EML, HOUR_IN_SECONDS, true)) {
            global $wpdb;
            $rows = $wpdb->get_results($wpdb->prepare(
                'SELECT * FROM ' . IQU_Billing_DB::accounts_table() . ' WHERE LOWER(contact_email) = LOWER(%s) AND mode = %s AND status <> %s',
                $email, IQU_Stripe::expected_mode(), 'canceled'
            ), ARRAY_A) ?: [];
            foreach ($rows as $acc) self::send_link_email($acc);
        }

        self::render_find('', '', $done);
    }

    private static function send_link_email(array $acc): void
    {
        if (!is_email($acc['contact_email'])) return;
        if (IQU_Stripe::expected_mode() === 'test' && !self::test_email_allowed($acc['contact_email'])) return;

        // Shared formal email layout (IQU_Billing_Email).
        IQU_Billing_Email::send($acc['contact_email'], 'Your billing page — Ilm-ul-Quran USA', [
            'title'     => 'Your private billing page',
            'preheader' => 'Here is your private billing page for Ilm-ul-Quran USA.',
            'greeting'  => 'Assalamu alaikum,',
            'private'   => true,
            'blocks'    => [
                ['p', 'You asked for your family\'s billing page on our website. Here is your private link.'],
                ['table', IQU_Billing_Email::student_rows($acc)],
                ['button', 'Open my billing page', IQU_Billing_Service::link_for($acc)],
                ['list', 'Good to know', [
                    'If you did not ask for this link, you can ignore this email — nothing changes.',
                    'The page opens without a password, so please keep the link private.',
                ]],
            ],
        ]);
    }

    // ------------------------------------------------------------
    // Views
    // ------------------------------------------------------------

    private static function render_find(string $intro = '', string $error = '', string $success = ''): void
    {
        $html  = '<h1>Find your billing page</h1>';
        $html .= '<p class="lead">' . esc_html($intro ?: 'Enter the email you gave us when you enrolled. We will email you your private link.') . '</p>';
        if ($error)   $html .= '<div class="box bad" role="alert">' . esc_html($error) . '</div>';
        if ($success) $html .= '<div class="box good" role="status">' . esc_html($success) . '</div>';
        $html .= '<form rel="noreferrer" method="post" action="' . esc_url(home_url('/' . self::PATH . '/')) . '" class="card">'
            . '<input type="hidden" name="do" value="find">'
            . '<label for="email">Email</label>'
            . '<input id="email" name="email" type="email" required autocomplete="email" placeholder="you@example.com">'
            . '<button type="submit" class="btn wide">Email me my link</button>'
            . '</form>'
            . '<p class="muted small">For privacy, this page never says whether an email is in our records. Changed your email? Write to us at ' . self::contact_link() . '.</p>';
        self::page('Find your billing page', $html);
    }

    private static function render_account(array $acc, string $token, string $error = ''): void
    {
        $members  = IQU_Billing_DB::get_members((int) $acc['id']);
        $students = [];
        foreach ($members as $m) {
            $reg = IQU_Database::get_registration((int) $m['registration_id']);
            if (!$reg) continue;
            $p = IQU_Billing_Pricing::for_registration($reg);
            $students[] = ['reg' => $reg, 'p' => $p, 'amount' => (float) $m['amount']];
        }

        $status   = (string) $acc['status'];
        $is_setup = in_array($status, ['not_sent', 'link_sent'], true) && empty($acc['stripe_subscription_id']);
        $failed   = in_array($status, ['past_due', 'unpaid', 'paused'], true);
        $greet    = trim((string) $acc['guardian_name']) !== '' ? explode(' ', trim($acc['guardian_name']))[0] : '';
        $kids     = implode(', ', array_map(fn($s) => trim($s['reg']['first_name']), $students));
        $url      = esc_url(home_url('/' . self::PATH . '/'));
        $tok      = esc_attr($token);

        // Enroll for Free, back from Stripe (enrolled=1): welcome once the webhook has confirmed,
        // otherwise "confirming" with a plain meta refresh (no JavaScript; CSP unchanged).
        [$enroll_box, $head] = (isset($_GET['enrolled']) && $_GET['enrolled'] === '1' && class_exists('IQU_Enrollment'))
            ? self::enrollment_box($students, $token)
            : ['', ''];

        $h = $enroll_box;
        if (isset($_GET['setup']) && $_GET['setup'] === 'done' && !$is_setup && $enroll_box === '') {
            $h .= '<div class="box good"><strong>All set.</strong> Tuition will now be taken automatically, and you will get a receipt by email each time.</div>';
        }
        if ($error) $h .= '<div class="box bad">' . esc_html($error) . '</div>';

        $h .= '<p class="hello">Assalamu alaikum' . ($greet !== '' ? ', ' . esc_html($greet) : '') . '</p>';
        $h .= '<h1>' . ($is_setup ? 'Set up monthly tuition' : 'Monthly tuition') . ($kids !== '' ? ' <span class="for">for ' . esc_html($kids) . '</span>' : '') . '</h1>';

        if ($is_setup) {
            $h .= self::hero_setup($acc, $url, $tok);
        } elseif ($failed) {
            $h .= self::hero_problem($acc, $url, $tok);
        } elseif ($status === 'canceled') {
            $h .= self::hero_stopped($kids);
        } else {
            $h .= self::hero_active($acc, $url, $tok);
        }

        $h .= self::students_card($students, $acc);
        if (!$is_setup) $h .= self::history($acc);
        $h .= self::how_it_works();

        $h .= '<section class="card help"><h2 class="label">Need help?</h2><p class="small">If the fee is a burden for your family right now, or you need to pause or stop classes, write to us at ' . self::contact_link() . '. No child\'s place is ever affected by cost.</p></section>';
        $h .= '<p class="muted small">This page is private to your family. If you think someone else has your link, tell us and we will send you a new one.</p>';

        self::page('Monthly tuition', $h, $head);
    }

    /**
     * Enrollment banner after Stripe.
     * @return array{0:string,1:string} box HTML, extra <head> tags
     */
    private static function enrollment_box(array $students, string $token): array
    {
        $state = IQU_Enrollment::portal_state(array_column($students, 'reg'));
        if ($state === 'complete') {
            return ['<div class="box good" role="status"><strong>Welcome to Ilm-ul-Quran USA — enrollment complete.</strong> JazakAllahu Khairan! The first month is free. We will contact you on WhatsApp within 24–48 hours to arrange the class schedule, in-sha\'-Allah.</div>', ''];
        }
        if ($state !== 'confirming') return ['', ''];

        $try = min(self::CONFIRM_TRIES, absint($_GET['w'] ?? 0));
        if ($try >= self::CONFIRM_TRIES) {
            return ['<div class="box good" role="status"><strong>We are still confirming your card with Stripe.</strong> You do not need to do anything — we will email you as soon as it is confirmed.</div>', ''];
        }
        $next = add_query_arg(['t' => $token, 'setup' => 'done', 'enrolled' => '1', 'w' => $try + 1], home_url('/' . self::PATH . '/'));
        return [
            '<div class="box good" role="status"><strong>We are confirming your card…</strong> This usually takes a few seconds. This page refreshes by itself.</div>',
            '<meta http-equiv="refresh" content="5;url=' . esc_url($next) . '">',
        ];
    }

    /** Status label and pill tone shown on the family page. */
    private const STATUS_PILLS = [
        'free_month'           => ['Free first month', 'good'],
        'waiting_first_charge' => ['Set up — waiting for the first payment', 'good'],
        'active'               => ['Active', 'good'],
        'past_due'             => ['Payment did not go through', 'bad'],
        'unpaid'               => ['On hold — please contact us', 'bad'],
        'paused'               => ['On hold — please contact us', 'bad'],
        'canceled'             => ['Stopped', 'muted'],
    ];

    private static function pill(string $status): string
    {
        [$label, $tone] = self::STATUS_PILLS[$status] ?? ['Being set up', 'muted'];
        return '<span class="pill ' . $tone . '">' . esc_html($label) . '</span>';
    }

    /** Not set up: three steps and one big button. */
    private static function hero_setup(array $acc, string $url, string $tok): string
    {
        $first = self::nice_date($acc['first_charge_date'] ? $acc['first_charge_date'] . ' 15:00:00' : null);
        $soon  = IQU_Billing_Service::first_charge_timestamp($acc['first_charge_date']) === 0;
        $new   = ($acc['student_type'] ?? '') === 'new';

        return '<section class="card hero" aria-labelledby="hero-title"><h2 id="hero-title" class="label">Three steps, once</h2><ol class="steps">'
            . '<li><span class="step-n" aria-hidden="true">1</span><div><strong>Add a bank account or card</strong>'
            . '<span class="small muted">This opens Stripe\'s secure page. Ilm-ul-Quran USA never sees or stores your bank or card details.</span></div></li>'
            . '<li><span class="step-n" aria-hidden="true">2</span><div>'
            . ($soon
                ? '<strong>Your first payment is taken when you finish setting up.</strong><span class="small muted">After that, the same date every month.</span>'
                : '<strong>Nothing is charged before ' . esc_html($first) . '</strong><span class="small muted">First payment: ' . esc_html($first) . ($new ? ' (after the free first month)' : '') . '. After that, the same date every month.</span>')
            . '</div></li>'
            . '<li><span class="step-n" aria-hidden="true">3</span><div><strong>Receipts arrive by email</strong>'
            . '<span class="small muted">Every payment, to ' . esc_html(self::mask_email((string) $acc['contact_email'])) . '.</span></div></li>'
            . '</ol>'
            . '<form rel="noreferrer" method="post" action="' . $url . '"><input type="hidden" name="do" value="checkout"><input type="hidden" name="t" value="' . $tok . '">'
            . '<button type="submit" class="btn wide">Add bank account or card</button></form>'
            . '</section>';
    }

    /** Active, free month or waiting for the first charge: the next payment, big. */
    private static function hero_active(array $acc, string $url, string $tok): string
    {
        return '<section class="card hero">'
            . '<div class="hero-top"><h2 class="label">Next payment</h2>' . self::pill((string) $acc['status']) . '</div>'
            . '<p class="big">' . esc_html(IQU_Pricing::format((float) $acc['net_amount'])) . '</p>'
            . '<p class="big-sub">' . esc_html(self::nice_date($acc['next_charge_at'])) . '</p>'
            . '<dl class="facts">'
            . '<div><dt>Paying from</dt><dd>' . esc_html($acc['payment_method_label'] ?: '—') . '</dd></div>'
            . '<div><dt>Receipts go to</dt><dd>' . esc_html(self::mask_email((string) $acc['contact_email'])) . '</dd></div>'
            . '</dl>'
            . '<form rel="noreferrer" method="post" action="' . $url . '"><input type="hidden" name="do" value="portal"><input type="hidden" name="t" value="' . $tok . '">'
            . '<button type="submit" class="btn wide">Change bank or card</button></form>'
            . '</section>';
    }

    /** Payment problem: what happened, how much, and the way out. */
    private static function hero_problem(array $acc, string $url, string $tok): string
    {
        $open   = self::unpaid_invoice($acc);
        $amount = $open ? (float) $open['amount_due'] : (float) $acc['net_amount'];
        $month  = $open ? IQU_Billing_History::month_label((string) $open['period_month']) : '';

        $h = '<section class="card hero problem" role="alert">'
            . '<div class="hero-top"><h2 class="label">Your last payment did not go through.</h2>' . self::pill((string) $acc['status']) . '</div>'
            . '<p class="big">' . esc_html(IQU_Pricing::format($amount)) . '</p>'
            . ($month !== '' ? '<p class="big-sub">' . esc_html($month . ' tuition') . '</p>' : '')
            . '<p>' . ($acc['status'] === 'past_due' ? 'We will try again automatically. You can also pay now, or use a different bank or card.' : 'Please pay now or contact us, and we will sort it out together.') . '</p>'
            . '<div class="actions">';
        if ($open) {
            $h .= '<a class="btn wide" rel="noopener noreferrer" href="' . esc_url($open['hosted_invoice_url']) . '">Pay ' . esc_html(IQU_Pricing::format($amount)) . ' now</a>';
        }
        $h .= '<form rel="noreferrer" method="post" action="' . $url . '"><input type="hidden" name="do" value="portal"><input type="hidden" name="t" value="' . $tok . '">'
            . '<button type="submit" class="btn wide' . ($open ? ' ghost' : '') . '">Change bank or card</button></form>'
            . '</div>'
            . '<p class="small">If paying is difficult right now, write to us at ' . self::contact_link() . '. No child\'s place is ever affected by cost.</p>'
            . '</section>';
        return $h;
    }

    /** Billing stopped: calm, with the history still below. */
    private static function hero_stopped(string $kids): string
    {
        return '<section class="card hero calm">'
            . '<div class="hero-top"><h2 class="label">Monthly billing</h2>' . self::pill('canceled') . '</div>'
            . '<p class="lead">Monthly tuition billing' . ($kids !== '' ? ' for ' . esc_html($kids) : '') . ' has stopped, and nothing more will be charged.</p>'
            . '<p class="small muted">You can still see your past payments and receipts below.</p>'
            . '</section>';
    }

    /** Your students: one small card each, then the monthly total. */
    private static function students_card(array $students, array $acc): string
    {
        $h = '<section class="card"><h2 class="label">' . (count($students) === 1 ? 'Your student' : 'Your students') . '</h2><div class="students">';
        foreach ($students as $s) {
            $reg = $s['reg']; $p = $s['p'];
            $h .= '<div class="student"><div class="student-top"><strong>' . esc_html($reg['first_name'] . ' ' . $reg['last_name']) . '</strong><span class="amt">'
                . ($p['discount'] > 0 ? '<span class="strike">' . esc_html(IQU_Pricing::format($p['gross'])) . '</span> ' : '')
                . esc_html(IQU_Pricing::format($s['amount'])) . '</span></div>'
                . '<span class="muted small">' . esc_html(trim(($p['course_label'] ?: 'Monthly tuition') . ($p['days_per_week'] ? ' · ' . $p['days_per_week'] . ' classes a week' : ''))) . ' · IQU-' . (int) $reg['id'] . '</span>'
                . (($p['discount'] > 0 && $p['discount_label']) ? '<span class="small discount">' . esc_html($p['discount_label']) . ' −' . esc_html(IQU_Pricing::format($p['discount'])) . '</span>' : '')
                . '</div>';
        }
        $h .= '</div><div class="row total"><span>You pay each month</span><span>' . esc_html(IQU_Pricing::format((float) $acc['net_amount'])) . '</span></div></section>';
        return $h;
    }

    /** Payment history from the local table, grouped by year, newest first. */
    private static function history(array $acc): string
    {
        $tones = ['green' => 'good', 'red' => 'bad', 'gold' => 'warn', 'neutral' => 'muted'];
        $years = [];
        foreach (IQU_Billing_History::for_account((int) $acc['id']) as $i) {
            $years[substr((string) $i['period_month'], 0, 4) ?: '—'][] = $i;
        }

        $h = '<section class="card"><h2 class="label">Payment history</h2>';
        if (!$years) {
            return $h . '<p class="muted small">No payments yet. Every payment will appear here with a receipt.</p></section>';
        }
        foreach ($years as $year => $rows) {
            $h .= '<h3 class="year">' . esc_html($year) . '</h3><ul class="hist">';
            foreach ($rows as $i) {
                [$label, $tone] = IQU_Billing_History::badge($i);
                $paid = in_array($i['status'], ['paid', 'refunded'], true);
                $amt  = $paid ? (float) $i['amount_paid'] : (float) $i['amount_due'];
                if ($paid && (float) $i['amount_due'] > 0) {
                    $when = $i['paid_at'] ? 'Paid ' . gmdate('j M', (int) strtotime($i['paid_at'] . ' UTC')) : 'Paid';
                } elseif ($paid) {
                    $when = 'Nothing to pay';
                } elseif (in_array($i['status'], ['open', 'failed'], true)) {
                    $when = 'Not paid yet';
                } else {
                    $when = '';
                }
                $links = '';
                $receipt = IQU_Billing_History::safe_url($i['hosted_invoice_url'] ?? '');
                $pdf     = IQU_Billing_History::safe_url($i['invoice_pdf'] ?? '');
                if ($receipt !== '') $links .= '<a class="link-btn" rel="noopener noreferrer" href="' . esc_url($receipt) . '">Receipt</a>';
                if ($pdf !== '')     $links .= '<a class="link-btn" rel="noopener noreferrer" href="' . esc_url($pdf) . '">Invoice</a>';
                $h .= '<li class="hist-row"><div class="hist-main"><strong>' . esc_html(IQU_Billing_History::month_label((string) $i['period_month'])) . '</strong>'
                    . '<span class="badge ' . ($tones[$tone] ?? 'muted') . '">' . esc_html($label) . '</span></div>'
                    . '<div class="hist-meta"><span class="amt">' . esc_html(IQU_Pricing::format($amt)) . '</span>'
                    . ($when !== '' ? '<span class="muted small">' . esc_html($when) . '</span>' : '')
                    . ((float) $i['amount_refunded'] > 0 && $i['status'] === 'paid' ? '<span class="muted small">Refunded ' . esc_html(IQU_Pricing::format((float) $i['amount_refunded'])) . '</span>' : '')
                    . '</div>'
                    . ($links !== '' ? '<div class="hist-links">' . $links . '</div>' : '')
                    . '</li>';
            }
            $h .= '</ul>';
        }
        return $h . '</section>';
    }

    /** "How billing works": plain answers, no JavaScript (<details>). */
    private static function how_it_works(): string
    {
        $items = [
            'When am I charged?' =>
                'Tuition is taken automatically once a month, on the same date as your first payment. You get a receipt by email each time, and every payment also appears on this page.',
            'Changing your card or bank' =>
                'Press "Change bank or card" on this page. It opens Stripe\'s secure page, and the new card or bank is used from the next payment.',
            'If a payment fails' =>
                'Nothing is lost and classes continue. We try again automatically over the next few days. You can also pay now from this page or switch to a different card or bank — and if paying is difficult, just talk to us.',
            'Pausing or stopping' =>
                'Please tell us at least 7 days before your next payment date if you need to pause or stop classes, and we will update your billing.',
        ];
        $h = '<section class="card"><h2 class="label">How billing works</h2>';
        foreach ($items as $q => $a) {
            $h .= '<details><summary>' . esc_html($q) . '</summary><p class="small">' . esc_html($a) . '</p></details>';
        }
        $h .= '<details><summary>Refunds</summary><p class="small">Refunds follow our <a href="' . esc_url(home_url('/tuition-refund-policy/')) . '">Tuition, Payment &amp; Refund Policy</a>. If you think you were charged by mistake, write to us at ' . self::contact_link() . ' and we will look into it.</p></details>';
        return $h . '</section>';
    }

    /** Newest unpaid invoice with a Stripe payment page, from the local payment history. */
    private static function unpaid_invoice(array $acc): ?array
    {
        foreach (IQU_Billing_History::for_account((int) $acc['id']) as $i) {
            if (in_array($i['status'], ['failed', 'open'], true) && (float) $i['amount_due'] > 0
                && strpos((string) $i['hosted_invoice_url'], 'https://invoice.stripe.com/') === 0) {
                return $i;
            }
        }
        return null;
    }

    // ------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------

    private static function ip(): string
    {
        return preg_replace('/[^0-9a-fA-F:.]/', '', (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
    }

    /** Returns true when the limit is already reached. When $count is true, records one more attempt. */
    private static function limited(string $key, int $max, int $window, bool $count): bool
    {
        $k = 'iqu_bl_rl_' . md5($key);
        $n = (int) get_transient($k);
        if ($n >= $max) return true;
        if ($count) set_transient($k, $n + 1, $window);
        return false;
    }

    /** In test mode, emails only go to our own addresses, never to families. */
    private static function test_email_allowed(string $email): bool
    {
        $allowed = array_map('strtolower', array_filter([get_option('admin_email'), self::CONTACT]));
        return in_array(strtolower(trim($email)), $allowed, true);
    }

    private static function mask_email(string $email): string
    {
        if (!strpos($email, '@')) return '';
        [$user, $domain] = explode('@', $email, 2);
        return mb_substr($user, 0, 1) . '•••@' . $domain;
    }

    private static function nice_date(?string $mysql_utc): string
    {
        $ts = $mysql_utc ? strtotime($mysql_utc . ' UTC') : 0;
        return $ts ? gmdate('j F Y', $ts) : '—';
    }

    private static function contact_link(): string
    {
        return '<a href="mailto:' . esc_attr(self::CONTACT) . '">' . esc_html(self::CONTACT) . '</a>';
    }

    /** Full HTML page, then stop. $head: extra tags for <head> (only the enrollment refresh uses it). */
    private static function page(string $title, string $body, string $head = ''): void
    {
        $icon = get_site_icon_url(64);
        $logo = '';
        $logo_id = (int) get_theme_mod('custom_logo');
        if ($logo_id) {
            $src = wp_get_attachment_image_url($logo_id, 'medium');
            if ($src) $logo = '<img src="' . esc_url($src) . '" alt="Ilm-ul-Quran USA" style="max-height:44px;width:auto">';
        }
        if ($logo === '') $logo = '<span class="brand">Ilm-ul-Quran USA</span>';

        // Drop anything other plugins queued for this response (optimizers inject
        // analytics scripts that would see the private link). CSP blocks them too.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        status_header(http_response_code() ?: 200);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer">' . $head . '<title>' . esc_html($title) . ' — Ilm-ul-Quran USA</title>'
            . ($icon ? '<link rel="icon" href="' . esc_url($icon) . '">' : '')
            . '<style>' . self::css() . '</style></head><body>'
            . '<header><div class="wrap head">' . $logo . '<span class="muted small">Private billing page</span></div></header>'
            . '<main class="wrap">' . $body . '</main>'
            . '<footer class="wrap muted small">Ilm-ul-Quran USA is operated by AL HASANAH FOUNDATION, a 501(c)(3) nonprofit. Payments are processed securely by Stripe.<br>'
            . '<a href="' . esc_url(home_url('/tuition-refund-policy/')) . '">Tuition, Payment &amp; Refund Policy</a> · '
            . '<a href="' . esc_url(home_url('/terms-and-conditions/')) . '">Terms and Conditions</a> · '
            . '<a href="' . esc_url(home_url('/privacy-policy/')) . '">Privacy Policy</a></footer>'
            . '</body></html>';
        exit;
    }

    private static function css(): string
    {
        // Poppins from the active theme (same origin, allowed by font-src 'self'); system fonts until it loads.
        $fonts = get_stylesheet_directory_uri() . '/assets/fonts/poppins/';
        $face  = '';
        foreach ([400 => 'Regular', 500 => 'Medium', 600 => 'SemiBold'] as $weight => $file) {
            $face .= '@font-face{font-family:"Poppins";font-style:normal;font-weight:' . $weight . ';font-display:swap;src:url("' . esc_url($fonts . 'Poppins-' . $file . '.ttf') . '") format("truetype")}';
        }

        // Theme palette: deep blue #1E4D6B, vibrant orange #F7941D (accent only, never text),
        // dark navy #15303F, slate #414B58, light gray #F1F1F4, ice blue #EFF8FC, mint #EDFAEE,
        // forest green #0A553A, pale gray #E5E7EB. Red only for payment problems.
        // Mobile first (360px+), 720px max, tap targets ≥ 44px, visible keyboard focus.
        return $face
            . '*,*::before,*::after{box-sizing:border-box}'
            . 'body{margin:0;background:#F1F1F4;color:#15303F;font:16px/1.6 "Poppins",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}'
            . 'strong,b{font-weight:600}a{color:#1E4D6B}'
            . '.wrap{max-width:720px;margin:0 auto;padding:0 16px}'
            . 'header{background:#fff;border-bottom:1px solid #E5E7EB;border-top:4px solid #F7941D;margin-bottom:24px}'
            . '.head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:14px 16px}.brand{font-weight:600;font-size:19px;color:#1E4D6B}'
            . 'h1{font-size:26px;font-weight:600;line-height:1.25;margin:2px 0 20px;color:#15303F}h1 .for{display:block;font-size:17px;font-weight:400;color:#414B58;margin-top:4px}'
            . '.hello{margin:0;color:#414B58}.lead{font-size:17px;margin:0 0 18px}'
            . '.muted{color:#414B58}.small{font-size:14px;line-height:1.55}.center{text-align:center}'
            . '.card{background:#fff;border:1px solid #E5E7EB;border-radius:16px;padding:20px;margin:0 0 16px}'
            . '.label{margin:0 0 12px;font-size:12px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#414B58}'
            // hero by state
            . '.hero{border-top:4px solid #1E4D6B}.hero.calm{border-top-color:#414B58}'
            . '.hero.problem{background:#fbece6;border-color:#ebc9bb;border-top-color:#8a2424;color:#7a2e12}'
            . '.hero.problem .label{color:#7a2e12;font-size:17px;letter-spacing:0;text-transform:none}'
            . '.hero-top{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px 12px}.hero-top .label{margin:0}'
            . '.big{margin:10px 0 0;font-size:40px;font-weight:600;line-height:1.1;color:#1E4D6B}.big-sub{margin:4px 0 0;font-size:17px;color:#414B58}'
            . '.hero.problem .big,.hero.problem .big-sub{color:#7a2e12}'
            . '.facts{display:grid;gap:12px;margin:18px 0;padding:14px 16px;background:#EFF8FC;border-radius:12px}'
            . '.facts div{margin:0}dt{font-size:12px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:#414B58}dd{margin:2px 0 0;font-weight:500}'
            . '.steps{list-style:none;margin:0 0 18px;padding:0;display:grid;gap:16px}.steps li{display:flex;gap:14px;align-items:flex-start}'
            . '.steps strong{display:block;font-size:17px}.steps .small{display:block;margin-top:2px}'
            . '.step-n{flex:0 0 36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:600;color:#1E4D6B;background:#EFF8FC;border:2px solid #1E4D6B}'
            // students
            . '.students{display:grid;gap:10px}.student{display:flex;flex-direction:column;gap:2px;padding:14px 16px;border:1px solid #E5E7EB;border-radius:12px}'
            . '.student-top{display:flex;justify-content:space-between;gap:12px}.discount{color:#0A553A}'
            . '.row{display:flex;justify-content:space-between;gap:12px;padding:10px 0}'
            . '.row.total{font-weight:600;font-size:18px;color:#1E4D6B;border-top:2px solid #E5E7EB;margin-top:12px;padding-top:12px}'
            . '.amt{text-align:right;white-space:nowrap;font-weight:600}.strike{text-decoration:line-through;color:#414B58;font-weight:400}'
            // history
            . '.year{margin:20px 0 8px;font-size:15px;font-weight:600}.label+.year{margin-top:4px}'
            . '.hist{list-style:none;margin:0;padding:0;border:1px solid #E5E7EB;border-radius:12px}'
            . '.hist-row{display:grid;gap:6px;padding:12px 14px;border-top:1px solid #E5E7EB}.hist-row:first-child{border-top:0}'
            . '.hist-main{display:flex;justify-content:space-between;align-items:center;gap:10px}'
            . '.hist-meta{display:flex;flex-wrap:wrap;align-items:baseline;gap:4px 14px}.hist-links{display:flex;flex-wrap:wrap;gap:8px}'
            . '.link-btn{display:inline-flex;align-items:center;min-height:44px;padding:0 16px;font-size:14px;font-weight:500;color:#1E4D6B;text-decoration:none;background:#fff;border:1px solid #E5E7EB;border-radius:10px}'
            . '.link-btn:hover{background:#EFF8FC}'
            . '.badge{display:inline-block;padding:2px 10px;border-radius:999px;font-size:13px;font-weight:500;white-space:nowrap}'
            . '.badge.good{background:#EDFAEE;color:#0A553A}.badge.bad{background:#fbece6;color:#8a2424}.badge.warn{background:#EFF8FC;color:#1E4D6B}.badge.muted{background:#F1F1F4;color:#414B58}'
            // messages, pills, buttons, form
            . '.box{border-radius:12px;padding:14px 18px;margin:0 0 16px}.box.good{background:#EDFAEE;border:1px solid #EDFAEE;border-left:4px solid #0A553A;color:#0A553A}'
            . '.box.bad{background:#fbece6;border:1px solid #ebc9bb;border-left:4px solid #8a2424;color:#7a2e12}'
            . '.pill{display:inline-block;padding:3px 12px;border-radius:999px;font-size:13px;font-weight:500;white-space:nowrap}'
            . '.pill.good{background:#EDFAEE;color:#0A553A;border:1px solid #0A553A}.pill.bad{background:#8a2424;color:#fff;border:1px solid #8a2424}.pill.muted{background:#F1F1F4;color:#414B58;border:1px solid #E5E7EB}'
            . '.btn{display:inline-flex;align-items:center;justify-content:center;min-height:52px;padding:12px 22px;font-family:inherit;font-size:17px;font-weight:600;line-height:1.2;text-align:center;text-decoration:none;color:#fff;background:#1E4D6B;border:1px solid #1E4D6B;border-radius:12px;cursor:pointer}'
            . '.btn:hover{background:#15303F;border-color:#15303F}.btn.wide{width:100%;margin:6px 0}'
            . '.btn.ghost{color:#1E4D6B;background:#fff}.btn.ghost:hover{background:#EFF8FC}'
            . '.actions{display:flex;flex-wrap:wrap;gap:0 10px;margin:14px 0 6px}.actions>*{flex:1 1 240px}.actions form{margin:0}'
            . 'a:focus-visible,.btn:focus-visible,input:focus-visible,summary:focus-visible{outline:3px solid #1E4D6B;outline-offset:3px}'
            . 'label{display:block;margin-bottom:6px;font-size:15px;font-weight:600}'
            . 'input[type=email]{width:100%;height:52px;margin-bottom:14px;padding:0 14px;font-family:inherit;font-size:17px;color:#15303F;background:#fff;border:1px solid #414B58;border-radius:12px}'
            // "How billing works"
            . 'details{border-top:1px solid #E5E7EB}.label+details{border-top:0}'
            . 'summary{display:flex;justify-content:space-between;align-items:center;gap:12px;min-height:48px;padding:8px 0;font-weight:600;cursor:pointer;list-style:none}'
            . 'summary::-webkit-details-marker{display:none}summary::after{content:"+";font-size:22px;font-weight:400;color:#1E4D6B}details[open] summary::after{content:"\2212"}'
            . 'details p{margin:0 0 14px}.help p{margin:0}'
            . 'footer{padding:24px 16px 40px}footer a{display:inline-block;padding:6px 0;color:#414B58}'
            . '@media (min-width:640px){.wrap{padding:0 24px}h1{font-size:30px}.card{padding:24px 26px}.big{font-size:46px}'
            . '.facts{grid-template-columns:1fr 1fr}.students{grid-template-columns:1fr 1fr}}';
    }
}
