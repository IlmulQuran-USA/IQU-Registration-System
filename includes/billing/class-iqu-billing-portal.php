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

        $link = IQU_Billing_Service::link_for($acc);
        // Same frame as the registration emails (IQU_Mailer): logo header with a deep blue rule, white card, deep blue footer.
        $body = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F1F1F4;font-family:\'Segoe UI\',Arial,Helvetica,sans-serif"><tr><td align="center" style="padding:24px 12px">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:620px;background:#ffffff;border:1px solid #E5E7EB;border-radius:14px;overflow:hidden">'
            . '<tr><td align="center" style="padding:24px 24px 16px;background:#EFF8FC;border-bottom:3px solid #1E4D6B">'
            . '<table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0"><tr><td>'
            . '<img src="' . esc_url(content_url('uploads/2026/10/iqu-email-logo.png')) . '" width="240" alt="Ilm-ul-Quran USA" style="display:block;border:0;height:auto;max-width:240px">'
            . '</td></tr></table></td></tr>'
            . '<tr><td style="padding:30px 30px 12px;font-size:15px;line-height:23px;color:#15303F">'
            . '<p style="margin:0 0 14px">Assalamu alaikum,</p><p style="margin:0 0 14px">Here is your private billing page for Ilm-ul-Quran USA:</p>'
            . '<p style="margin:0 0 20px;text-align:center"><a href="' . esc_url($link) . '" style="display:inline-block;background:#1E4D6B;color:#ffffff;text-decoration:none;font-weight:bold;font-size:16px;padding:14px 28px;border-radius:8px">Open my billing page</a></p>'
            . '<p style="margin:0 0 14px;font-size:13px;line-height:20px;color:#414B58">You asked for this link on our website. If you did not, you can ignore this email. Please do not forward it.</p>'
            . '<p style="margin:0 0 14px">Jazakum Allahu khayran,<br>Ilm-ul-Quran USA</p>'
            . '</td></tr>'
            . '<tr><td style="background:#1E4D6B;height:8px;line-height:8px;font-size:0">&nbsp;</td></tr>'
            . '</table></td></tr></table>';
        wp_mail($acc['contact_email'], 'Your billing page — Ilm-ul-Quran USA', $body, ['Content-Type: text/html; charset=UTF-8']);
    }

    // ------------------------------------------------------------
    // Views
    // ------------------------------------------------------------

    private static function render_find(string $intro = '', string $error = '', string $success = ''): void
    {
        $html  = '<h1>Find your billing page</h1>';
        $html .= '<p class="muted">' . esc_html($intro ?: 'Enter the email you gave us when you enrolled. We will email you your private link.') . '</p>';
        if ($error)   $html .= '<div class="box bad">' . esc_html($error) . '</div>';
        if ($success) $html .= '<div class="box good">' . esc_html($success) . '</div>';
        $html .= '<form rel="noreferrer" method="post" action="' . esc_url(home_url('/' . self::PATH . '/')) . '" class="card">'
            . '<input type="hidden" name="do" value="find">'
            . '<label for="email">Email</label>'
            . '<input id="email" name="email" type="email" required autocomplete="email" placeholder="you@example.com">'
            . '<button type="submit" class="btn">Email me my link</button>'
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

        $h = '';
        if (isset($_GET['setup']) && $_GET['setup'] === 'done' && !$is_setup) {
            $h .= '<div class="box good"><strong>All set.</strong> Tuition will now be taken automatically, and you will get a receipt by email each time.</div>';
        }
        if ($error) $h .= '<div class="box bad">' . esc_html($error) . '</div>';

        $h .= '<p class="muted">Assalamu alaikum' . ($greet !== '' ? ', ' . esc_html($greet) : '') . '</p>';
        $h .= '<h1>' . ($is_setup ? 'Set up monthly tuition' : 'Monthly tuition') . ($kids !== '' ? ' <span class="for">for ' . esc_html($kids) . '</span>' : '') . '</h1>';

        if ($failed) {
            $h .= self::failed_box($acc, $url, $tok);
        }

        // Students and fee
        $h .= '<div class="card"><div class="label">Students</div>';
        foreach ($students as $s) {
            $reg = $s['reg']; $p = $s['p'];
            $h .= '<div class="row"><div><strong>' . esc_html($reg['first_name'] . ' ' . $reg['last_name']) . '</strong><br><span class="muted small">'
                . esc_html(trim(($p['course_label'] ?: 'Monthly tuition') . ($p['days_per_week'] ? ' · ' . $p['days_per_week'] . ' classes a week' : '')))
                . ' · IQU-' . (int) $reg['id'] . '</span></div><div class="amt">'
                . ($p['discount'] > 0 ? '<span class="strike">' . esc_html(IQU_Pricing::format($p['gross'])) . '</span> ' : '')
                . esc_html(IQU_Pricing::format($s['amount'])) . '</div></div>';
            if ($p['discount'] > 0 && $p['discount_label']) {
                $h .= '<div class="row sub"><span class="muted small">' . esc_html($p['discount_label']) . '</span><span class="muted small">−' . esc_html(IQU_Pricing::format($p['discount'])) . '</span></div>';
            }
        }
        $h .= '<div class="row total"><span>You pay each month</span><span>' . esc_html(IQU_Pricing::format((float) $acc['net_amount'])) . '</span></div></div>';

        if ($is_setup) {
            $first = self::nice_date($acc['first_charge_date'] ? $acc['first_charge_date'] . ' 15:00:00' : null);
            $soon  = IQU_Billing_Service::first_charge_timestamp($acc['first_charge_date']) === 0;
            $h .= '<div class="box good"><strong>' . ($soon ? 'Your first payment is taken when you finish setting up.' : 'First payment: ' . esc_html($first)) . '</strong>'
                . ($soon ? '' : '<br><span class="small">' . (($acc['student_type'] ?? '') === 'new' ? 'After the free first month. ' : '') . 'Nothing is charged before then. After that, the same date every month.</span>') . '</div>';
            $h .= '<form rel="noreferrer" method="post" action="' . $url . '"><input type="hidden" name="do" value="checkout"><input type="hidden" name="t" value="' . $tok . '">'
                . '<button type="submit" class="btn wide">Add bank account or card</button></form>'
                . '<p class="muted small center">This opens Stripe\'s secure page. Ilm-ul-Quran USA never sees or stores your bank or card details.</p>';
        } else {
            $h .= self::status_card($acc, $url, $tok);
            $h .= self::history($acc);
        }

        $h .= '<div class="card"><div class="label">Need help?</div><p class="small">If the fee is a burden for your family right now, or you need to pause or stop classes, write to us at ' . self::contact_link() . '. No child\'s place is ever affected by cost.</p></div>';
        $h .= '<p class="muted small">This page is private to your family. If you think someone else has your link, tell us and we will send you a new one.</p>';

        self::page('Monthly tuition', $h);
    }

    private static function status_card(array $acc, string $url, string $tok): string
    {
        $labels = [
            'free_month'           => ['Free first month', 'good'],
            'waiting_first_charge' => ['Set up — waiting for the first payment', 'good'],
            'active'               => ['Active', 'good'],
            'past_due'             => ['Payment did not go through', 'bad'],
            'unpaid'               => ['On hold — please contact us', 'bad'],
            'paused'               => ['On hold — please contact us', 'bad'],
            'canceled'             => ['Stopped', 'muted'],
        ];
        [$label, $tone] = $labels[$acc['status']] ?? ['Being set up', 'muted'];
        $next = $acc['status'] === 'canceled' ? '—' : self::nice_date($acc['next_charge_at']);

        $h  = '<div class="card"><div class="row"><div><div class="label">Status</div><span class="pill ' . $tone . '">' . esc_html($label) . '</span></div></div>';
        $h .= '<div class="grid">'
            . '<div><div class="label">Next payment</div>' . esc_html($next) . '</div>'
            . '<div><div class="label">Paying from</div>' . esc_html($acc['payment_method_label'] ?: '—') . '</div>'
            . '<div><div class="label">Receipts go to</div>' . esc_html(self::mask_email((string) $acc['contact_email'])) . '</div>'
            . '</div>';
        if ($acc['status'] !== 'canceled') {
            $h .= '<form rel="noreferrer" method="post" action="' . $url . '"><input type="hidden" name="do" value="portal"><input type="hidden" name="t" value="' . $tok . '">'
                . '<button type="submit" class="btn ghost">Change bank or card</button></form>';
        }
        return $h . '</div>';
    }

    private static function failed_box(array $acc, string $url, string $tok): string
    {
        $pay = '';
        $open = self::invoices($acc, 'open');
        if ($open && !empty($open[0]['hosted_invoice_url']) && strpos($open[0]['hosted_invoice_url'], 'https://invoice.stripe.com/') === 0) {
            $pay = '<a class="btn" rel="noopener noreferrer" href="' . esc_url($open[0]['hosted_invoice_url']) . '">Pay ' . esc_html(IQU_Pricing::format(((int) $open[0]['amount_due']) / 100)) . ' now</a> ';
        }
        return '<div class="box bad"><strong>Your last payment did not go through.</strong><br><span class="small">'
            . ($acc['status'] === 'past_due' ? 'We will try again automatically. You can also pay now, or use a different bank or card.' : 'Please pay now or contact us, and we will sort it out together.')
            . '</span><div class="actions">' . $pay
            . '<form rel="noreferrer" method="post" action="' . $url . '" style="display:inline"><input type="hidden" name="do" value="portal"><input type="hidden" name="t" value="' . $tok . '"><button type="submit" class="btn ghost">Change bank or card</button></form>'
            . '</div></div>';
    }

    private static function history(array $acc): string
    {
        $inv = self::invoices($acc);
        $h = '<div class="card"><div class="label">Payment history</div>';
        $rows = '';
        foreach ($inv as $i) {
            if (($i['status'] ?? '') === 'draft') continue;
            $paid  = (int) ($i['amount_paid'] ?? 0);
            $due   = (int) ($i['amount_due'] ?? 0);
            $start = (int) ($i['lines']['data'][0]['period']['start'] ?? $i['period_start'] ?? $i['created']);
            $month = gmdate('F Y', $start);
            if ($i['status'] === 'paid' && $paid === 0) {
                $what = 'Free month'; $amt = '$0.00';
            } elseif ($i['status'] === 'paid') {
                $what = 'Paid ' . gmdate('j M', (int) ($i['status_transitions']['paid_at'] ?? $i['created'])); $amt = IQU_Pricing::format($paid / 100);
            } elseif ($i['status'] === 'open') {
                $what = 'Not paid yet'; $amt = IQU_Pricing::format($due / 100);
            } else {
                $what = ucfirst((string) $i['status']); $amt = IQU_Pricing::format($due / 100);
            }
            $receipt = '';
            foreach (['hosted_invoice_url' => 'https://invoice.stripe.com/', 'invoice_pdf' => 'https://pay.stripe.com/'] as $k => $prefix) {
                if (!empty($i[$k]) && strpos((string) $i[$k], $prefix) === 0) { $receipt = '<a rel="noopener noreferrer" href="' . esc_url($i[$k]) . '">Receipt</a>'; break; }
            }
            $rows .= '<div class="row"><div>' . esc_html($month) . '<br><span class="muted small">' . esc_html($what) . '</span></div><div class="amt">' . esc_html($amt) . ($receipt ? '<br><span class="small">' . $receipt . '</span>' : '') . '</div></div>';
        }
        $h .= $rows !== '' ? $rows : '<p class="muted small">No payments yet. Every payment will appear here with a receipt.</p>';
        return $h . '</div>';
    }

    /** Last 12 invoices for this family, read from Stripe. */
    private static function invoices(array $acc, string $status = ''): array
    {
        if (empty($acc['stripe_customer_id'])) return [];
        $params = ['customer' => $acc['stripe_customer_id'], 'limit' => 12];
        if ($status !== '') $params['status'] = $status;
        $r = IQU_Stripe::get('/invoices', $params);
        return $r['ok'] ? (array) ($r['data']['data'] ?? []) : [];
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

    /** Full HTML page, then stop. */
    private static function page(string $title, string $body): void
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
            . '<meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer"><title>' . esc_html($title) . ' — Ilm-ul-Quran USA</title>'
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

        // Theme palette: deep blue #1E4D6B, dark navy #15303F, slate #414B58, light gray #F1F1F4,
        // ice blue #EFF8FC, mint #EDFAEE, forest green #0A553A, pale gray #E5E7EB. Red only for payment problems.
        return $face
            . 'body{margin:0;background:#F1F1F4;color:#15303F;font:16px/1.55 "Poppins",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}'
            . 'strong,b{font-weight:600}'
            . '.wrap{max-width:680px;margin:0 auto;padding:0 20px}header{background:#fff;border-bottom:1px solid #E5E7EB;margin-bottom:28px}'
            . '.head{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:16px 20px}.brand{font-weight:600;font-size:19px;color:#1E4D6B}'
            . 'h1{font-size:27px;font-weight:600;line-height:1.25;margin:4px 0 20px;color:#15303F}h1 .for{display:block;font-size:17px;font-weight:400;color:#414B58;margin-top:4px}'
            . '.muted{color:#414B58}.small{font-size:14px}.center{text-align:center}'
            . '.card{background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:20px 22px;margin:0 0 18px}'
            . '.label{font-size:12px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#414B58;margin-bottom:8px}'
            . '.row{display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-top:1px solid #E5E7EB}.row:first-of-type{border-top:0}.row.sub{padding-top:0;border-top:0}'
            . '.row.total{font-weight:600;font-size:18px;color:#1E4D6B;border-top:2px solid #E5E7EB;margin-top:6px;padding-top:12px}.amt{text-align:right;white-space:nowrap}.strike{text-decoration:line-through;color:#414B58;font-weight:400}'
            . '.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin:14px 0;padding:14px 16px;background:#EFF8FC;border-radius:10px}'
            . '.box{border-radius:12px;padding:14px 18px;margin:0 0 18px}.box.good{background:#EDFAEE;border:1px solid #EDFAEE;border-left:4px solid #0A553A;color:#0A553A}.box.bad{background:#fbece6;border:1px solid #ebc9bb;border-left:4px solid #8a2424;color:#7a2e12}'
            . '.pill{display:inline-block;padding:3px 12px;border-radius:999px;font-size:14px;font-weight:500}.pill.good{background:#EDFAEE;color:#0A553A;border:1px solid #0A553A}.pill.bad{background:#8a2424;color:#fff}.pill.muted{background:#F1F1F4;color:#414B58;border:1px solid #E5E7EB}'
            . '.btn{display:inline-block;background:#1E4D6B;color:#fff;border:1px solid #1E4D6B;border-radius:9px;padding:13px 22px;font-family:inherit;font-weight:600;font-size:16px;line-height:1.2;cursor:pointer;text-decoration:none;min-height:46px;box-sizing:border-box}'
            . '.btn:hover{background:#15303F;border-color:#15303F}.btn:focus-visible,input:focus-visible{outline:3px solid #F7941D;outline-offset:2px}'
            . '.btn.wide{width:100%;margin:4px 0 8px}.btn.ghost{background:#fff;color:#1E4D6B;border:1px solid #1E4D6B}.btn.ghost:hover{background:#EFF8FC}.actions{margin-top:12px;display:flex;gap:8px;flex-wrap:wrap}'
            . 'label{display:block;font-weight:600;font-size:14px;margin-bottom:6px}input[type=email]{width:100%;box-sizing:border-box;height:48px;padding:0 14px;font-family:inherit;font-size:16px;color:#15303F;background:#fff;border:1px solid #414B58;border-radius:9px;margin-bottom:14px}'
            . 'a{color:#1E4D6B}footer{padding:24px 20px 40px}footer a{color:#414B58}@media (max-width:480px){h1{font-size:23px}.card{padding:16px}}';
    }
}
