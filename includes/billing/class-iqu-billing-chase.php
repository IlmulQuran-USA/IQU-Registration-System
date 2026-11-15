<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Billing_Chase (3.7.12)
 *
 * A student must never stay in class with no card on file while nobody is chased:
 *   A. reminders for a payment link an admin sent (Student Billing, Add Student, Import): an email to the family 3 and 7
 *      days after the link was last sent (email or WhatsApp, whichever was later), once each — sending it again starts
 *      the count again — and after the 7-day one, one Telegram to the team;
 *   B. the safety net for a missed checkout.session.completed webhook: before a reminder or an expiry, an account whose
 *      Checkout page exists and has no subscription yet is read back from Stripe, and a completed page is finished
 *      exactly as the webhook does (IQU_Billing_Webhook::finish_checkout, once only). At most MAX_CHECKS per run;
 *   C. Daily Summary lines (counts only, never names): monthly students with no billing record, and "Link sent, no
 *      card" over 7 days, as Student Billing groups them; and a warning when the Enroll for Free card step is on but
 *      Stripe is not ready for the site mode.
 * Runs in the hourly IQU_Enrollment::CRON.
 */
class IQU_Billing_Chase
{
    public const BIT_3D = 1;
    public const BIT_7D = 2;
    private const MAX_CHECKS = 10;  // B: Stripe read-backs per run (each is 2–4 Stripe requests)
    private const WINDOW_DAYS = 7;  // A: a reminder is sent only in the week after it is due (never for a link sent months ago)

    private static int $checks = 0;

    public static function init(): void
    {
        add_filter('iqu_daily_summary_rows', [__CLASS__, 'summary_rows'], 15, 2);
    }

    /** The hourly run (A); B is also called by IQU_Enrollment::run_reminders before its own reminders and expiry. */
    public static function run(): void
    {
        if (!IQU_Billing_DB::is_installed() || !IQU_Stripe::is_ready()) return; // one budget per hourly run (reset_budget)
        self::link_reminders();
    }

    /** Start a run's budget of Stripe read-backs (B). */
    public static function reset_budget(): void
    {
        self::$checks = 0;
    }

    // ------------------------------------------------------------
    // B. Safety net: a completed Checkout page whose webhook never arrived
    // ------------------------------------------------------------

    /**
     * Read the account back from Stripe when its Checkout page exists and no subscription is linked yet. When the page
     * has completed, finish it as the webhook does. Returns the account as it is now (unchanged when nothing was read).
     */
    public static function check_checkout(array $acc): array
    {
        $sid = (string) ($acc['checkout_session_id'] ?? '');
        if ($sid === '' || !empty($acc['stripe_subscription_id']) || !preg_match('/^cs_[A-Za-z0-9_]+$/', $sid)) return $acc;
        if (self::$checks >= self::MAX_CHECKS) return $acc;
        self::$checks++;
        $g = IQU_Stripe::get('/checkout/sessions/' . $sid);
        if (!$g['ok'] || (string) ($g['data']['status'] ?? '') !== 'complete') return $acc;
        $before = $acc;
        $sync = IQU_Billing_Sync::sync($acc);
        if (empty($sync['ok'])) return $acc;
        if (in_array($sync['new'], ['free_month', 'waiting_first_charge', 'active'], true)) {
            IQU_Billing_Webhook::finish_checkout($before, $sync, (array) $g['data']);
        }
        return IQU_Billing_DB::get_account((int) $acc['id']) ?: $sync['account'];
    }

    // ------------------------------------------------------------
    // A. Reminders for an admin-sent link
    // ------------------------------------------------------------

    /** When the link was last sent (UTC, MySQL format): the later of the email and the WhatsApp send; '' when never. */
    public static function last_sent(array $acc): string
    {
        return max((string) ($acc['email_sent_at'] ?? ''), (string) ($acc['whatsapp_sent_at'] ?? ''));
    }

    /** Test mode: emails only go to our own addresses, never to families (as IQU_Billing_Send::test_email_allowed). */
    private static function email_allowed(string $email): bool
    {
        if (!is_email($email)) return false;
        if (IQU_Stripe::expected_mode() !== 'test') return true;
        $own = array_map('strtolower', array_filter([get_option('admin_email'), defined('IQU_CONTACT_EMAIL') ? IQU_CONTACT_EMAIL : '']));
        return in_array(strtolower(trim($email)), $own, true);
    }

    /**
     * Why an account gets no link reminder ('' when it may get one): a subscription, bank verification, review, all its
     * students Left or Archived, or a website enrollment (those have their own 24 h / 72 h reminders).
     */
    public static function skip_reason(array $acc): string
    {
        if (!empty($acc['stripe_subscription_id'])) return 'subscription';
        if ((string) $acc['status'] !== 'link_sent') return 'status ' . $acc['status'];
        if ((string) ($acc['review_reason'] ?? '') !== '') return 'review';
        $open = 0;
        foreach (IQU_Billing_DB::get_members((int) $acc['id']) as $m) {
            $reg = IQU_Database::get_registration((int) $m['registration_id']);
            if (!$reg) continue;
            if ((string) ($reg['left_at'] ?? '') !== '' || (string) ($reg['archived_at'] ?? '') !== '') continue;
            if (IQU_Database::is_public_row($reg) && in_array((string) $reg['status'], ['card_pending', 'expired'], true)) return 'website enrollment';
            $open++;
        }
        return $open ? '' : 'students left or archived';
    }

    private static function link_reminders(): void
    {
        global $wpdb;
        $now = time();
        $ids = $wpdb->get_col($wpdb->prepare(
            'SELECT id FROM ' . IQU_Billing_DB::accounts_table() . " WHERE mode = %s AND status = 'link_sent' AND stripe_subscription_id = '' AND review_reason = '' AND (email_sent_at IS NOT NULL OR whatsapp_sent_at IS NOT NULL) ORDER BY id LIMIT 300",
            IQU_Stripe::expected_mode()
        )) ?: [];
        foreach ($ids as $id) {
            $acc = IQU_Billing_DB::get_account((int) $id);
            if (!$acc) continue;
            $base = self::last_sent($acc);
            if ($base === '') continue;
            $age  = $now - (int) strtotime($base . ' UTC');
            $bits = (string) ($acc['link_reminder_base'] ?? '') === $base ? (int) $acc['link_reminders'] : 0;
            $due7 = $age >= 7 * DAY_IN_SECONDS && $age < (7 + self::WINDOW_DAYS) * DAY_IN_SECONDS && !($bits & self::BIT_7D);
            $due3 = !$due7 && $age >= 3 * DAY_IN_SECONDS && $age < 7 * DAY_IN_SECONDS && !($bits & self::BIT_3D);
            if (!$due3 && !$due7) continue;
            if (self::skip_reason($acc) !== '') continue;
            // B: the family may have finished the Stripe page while its webhook was lost
            $acc = self::check_checkout($acc);
            if (self::skip_reason($acc) !== '') continue;
            // Claim first (one UPDATE), send only when this run claimed it.
            if ($due7) {
                if (!IQU_Billing_DB::claim_link_reminder((int) $acc['id'], $base, self::BIT_7D, self::BIT_3D | self::BIT_7D)) continue;
                self::reminder_email($acc, true);
                if (class_exists('IQU_Billing_Notify')) IQU_Billing_Notify::link_not_used($acc, (int) floor($age / DAY_IN_SECONDS));
            } else {
                if (!IQU_Billing_DB::claim_link_reminder((int) $acc['id'], $base, self::BIT_3D, self::BIT_3D)) continue;
                self::reminder_email($acc, false);
            }
        }
    }

    /** The family's first names: "Amina", "Amina and Yusuf". */
    private static function kids(array $acc): string
    {
        $names = [];
        foreach (IQU_Billing_DB::get_members((int) $acc['id']) as $m) {
            $reg = IQU_Database::get_registration((int) $m['registration_id']);
            if ($reg && (string) ($reg['left_at'] ?? '') === '') $names[] = trim((string) $reg['first_name']);
        }
        if (count($names) <= 1) return (string) ($names[0] ?? 'your child');
        $last = array_pop($names);
        return implode(', ', $names) . ' and ' . $last;
    }

    /** The 3-day or 7-day reminder: the same private link (IQU_Billing_Service::link_for). */
    public static function reminder_email(array $acc, bool $last): bool
    {
        $to = (string) $acc['contact_email'];
        if (!self::email_allowed($to)) return false;
        $kids  = self::kids($acc);
        $g     = trim((string) $acc['guardian_name']);
        $first = (string) ($acc['first_charge_date'] ?? '');
        $ts    = $first !== '' ? IQU_Billing_Service::first_charge_timestamp($first) : 0;
        $day   = $ts ? wp_date('j F Y', $ts) : '';
        return IQU_Billing_Email::send($to, $last ? "Reminder: please set up {$kids}'s monthly tuition — Ilm-Ul-Quran USA" : "Reminder: one step left for {$kids}'s monthly tuition — Ilm-Ul-Quran USA", [
            'title'     => $last ? 'A gentle reminder' : 'One step left',
            'preheader' => "Add a US bank account or card once for {$kids}'s monthly tuition." . ($day !== '' ? " Nothing is charged before {$day}." : ''),
            'greeting'  => 'Assalamu alaikum' . ($g !== '' ? ' ' . explode(' ', $g)[0] : '') . ',',
            'private'   => true,
            'help'      => 'If paying online is difficult, or the fee is a burden right now, please talk to us — no child\'s place is ever affected by cost.',
            'blocks'    => [
                ['p', "We recently sent you a link to set up monthly tuition for {$kids}. It only takes about 2 minutes, and there is just one step left."],
                ['table', array_merge(IQU_Billing_Email::student_rows($acc), IQU_Billing_Email::tuition_rows($acc), $day !== '' ? [['First payment', $day]] : [])],
                ['steps', 'What you need to do', [
                    'Press "Set up monthly tuition" below.',
                    'Add a US bank account or card on Stripe\'s secure page.',
                    'That\'s all — you will receive a receipt by email after every payment.',
                ]],
                ['button', 'Set up monthly tuition', IQU_Billing_Service::link_for($acc)],
                ['list', 'Good to know', [
                    $day !== '' ? "Nothing is charged before {$day}." : 'Nothing is charged today.',
                    $last ? 'If you have already done this, thank you — you can ignore this message.' : '',
                ]],
            ],
        ]);
    }

    // ------------------------------------------------------------
    // C. Daily Summary: students with no card
    // ------------------------------------------------------------

    /**
     * Counts only (no names). A student with no billing record is "Pending review" when their registration waits for a
     * review, otherwise "Not set up" or "Needs course" exactly as Student Billing groups them; each with how many are
     * older than 3 days (registration date). "Link sent, no card": Student Billing's "Link sent" students whose link was
     * last sent (or, never sent, whose record was made) more than 7 days ago. A line appears only when its count is above 0.
     */
    public static function summary_rows(array $rows, int $since = 0): array
    {
        try {
            if (!IQU_Database::schema_ready() || !class_exists('IQU_Billing_DB') || !IQU_Billing_DB::is_installed()) return $rows;
            $now = time();
            $old = fn(string $mysql, int $days) => $mysql !== '' && (int) strtotime($mysql . ' UTC') < $now - $days * DAY_IN_SECONDS;
            $c = ['pending_review' => [0, 0], 'not_set_up' => [0, 0], 'needs_course' => [0, 0]];
            $link_old = 0;
            foreach (IQU_Billing_Service::student_rows() as $r) {
                if ($r['acc']) {
                    if ($r['group'] === 'link_sent') {
                        $sent = self::last_sent($r['acc']) ?: (string) $r['acc']['created_at'];
                        if ($old($sent, 7)) $link_old++;
                    }
                    continue;
                }
                $key = (string) $r['reg']['status'] === 'pending_review' ? 'pending_review' : $r['group'];
                if (!isset($c[$key])) continue;
                $c[$key][0]++;
                if ($old((string) $r['reg']['created_at'], 3)) $c[$key][1]++;
            }
            $esc = fn($v) => class_exists('IQU_Telegram') ? IQU_Telegram::esc($v) : htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
            foreach (['not_set_up' => 'No billing — Not set up', 'needs_course' => 'No billing — Needs course', 'pending_review' => 'No billing — Pending review'] as $k => $label) {
                if ($c[$k][0] > 0) $rows[$label] = $c[$k][0] . ($c[$k][1] ? ' (' . $c[$k][1] . ' over 3 days)' : '');
            }
            if ($link_old > 0) $rows['Link sent, no card (over 7 days)'] = (string) $link_old;

            if (class_exists('IQU_Enrollment_Settings') && IQU_Enrollment_Settings::free_mode() === 'on') {
                $why = IQU_Stripe::not_ready_reason();
                if ($why !== '') $rows['⚠️ Card step'] = $esc('Enroll for Free payment is On, but families cannot add a card: ' . $why);
            }
        } catch (\Throwable $e) {
            error_log('[IQU] Daily summary "no card" lines skipped: ' . get_class($e));
        }
        return $rows;
    }
}
