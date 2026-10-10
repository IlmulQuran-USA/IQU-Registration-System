<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Billing_Settings
 *
 * Admin page: IQU Registrations → Billing Settings.
 * Shows key status and mode, and runs a connection test.
 *
 * Security:
 * - Only users with manage_options can see the page or run the test.
 * - The test button is protected by a nonce (blocks cross-site clicks).
 * - Only the masked key is ever printed. All output is escaped.
 * - Switching to live mode is deliberately NOT possible from this page yet.
 */
class IQU_Billing_Settings
{
    public const PAGE_SLUG  = 'iqu-billing-settings';
    private const CAP       = 'manage_options';
    private const ACTION    = 'iqu_billing_test_connection';
    private const NOTICE_TX = 'iqu_billing_notice_';
    private const A_SAVE    = 'iqu_billing_save_settings';
    private const A_SYNC_ALL = 'iqu_billing_sync_all';
    private const A_BACKFILL = 'iqu_billing_backfill_history';
    private const OPT_ZELLE = 'iqu_billing_zelle_end_date';
    private const TIME_BUDGET = 20; // seconds; stay well inside the request time limit

    public static function init(): void
    {
        add_action('admin_menu', [__CLASS__, 'register_menu'], 20);
        add_action('admin_post_' . self::ACTION, [__CLASS__, 'handle_test_connection']);
        add_action('admin_post_' . self::A_SAVE, [__CLASS__, 'handle_save']);
        add_action('admin_post_' . self::A_SYNC_ALL, [__CLASS__, 'handle_sync_all']);
        add_action('admin_post_' . self::A_BACKFILL, [__CLASS__, 'handle_backfill']);
    }

    public static function register_menu(): void
    {
        add_submenu_page(
            'iqu-registrations',
            'Billing Settings — IQU',
            'Billing Settings',
            self::CAP,
            self::PAGE_SLUG,
            [__CLASS__, 'render']
        );
    }

    public static function handle_test_connection(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die('You do not have permission to do this.', 403);
        }
        check_admin_referer(self::ACTION);

        $result = IQU_Stripe::ping();
        $notice = $result['ok']
            ? ['type' => 'success', 'text' => 'Connected to Stripe in ' . IQU_Stripe::key_mode() . ' mode.']
            : ['type' => 'error', 'text' => 'Connection failed: ' . $result['error']];

        set_transient(self::NOTICE_TX . get_current_user_id(), $notice, 60);
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG));
        exit;
    }

    public static function handle_save(): void
    {
        if (!current_user_can(self::CAP)) wp_die('You do not have permission to do this.', 403);
        check_admin_referer(self::A_SAVE);
        $date   = sanitize_text_field(wp_unslash($_POST['zelle_end'] ?? ''));
        $sender = sanitize_key(wp_unslash($_POST['receipt_sender'] ?? IQU_Billing_Receipt::sender()));
        $d      = $date !== '' ? DateTime::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC')) : null;

        // Validate first; nothing is saved when any part is wrong.
        if ($date !== '' && (!$d || $d->format('Y-m-d') !== $date)) {
            $notice = ['type' => 'error', 'text' => 'That date is not valid. Nothing was saved.'];
        } elseif (!in_array($sender, ['stripe', 'iqu'], true)) {
            $notice = ['type' => 'error', 'text' => 'Choose who sends payment receipts. Nothing was saved.'];
        } elseif ($sender === 'iqu' && empty($_POST['receipt_confirm'])) {
            $notice = ['type' => 'error', 'text' => 'Not saved. Before Ilm-ul-Quran USA sends the receipts, turn off "Successful payments" in Stripe → Settings → Customer emails, then tick the box to confirm. Otherwise families would get two receipts.'];
        } else {
            $parts = [];
            if ($date === '') {
                delete_option(self::OPT_ZELLE);
                $parts[] = 'Zelle end date cleared. Messages will not mention Zelle.';
            } else {
                update_option(self::OPT_ZELLE, $date, false);
                $parts[] = 'Saved. Messages now say tuition can no longer be sent by Zelle from ' . $d->format('j F Y') . '.';
            }
            if ($sender !== IQU_Billing_Receipt::sender()) {
                update_option(IQU_Billing_Receipt::OPT_SENDER, $sender, false);
                update_option(IQU_Billing_Receipt::OPT_CHANGED, ['user' => get_current_user_id(), 'at' => time(), 'value' => $sender], false);
                $parts[] = $sender === 'iqu'
                    ? 'Payment receipts are now sent by Ilm-ul-Quran USA.'
                    : 'Payment receipts are now sent by Stripe. Make sure "Successful payments" is on in Stripe.';
            }
            $notice = ['type' => 'success', 'text' => implode(' ', $parts)];
        }
        set_transient(self::NOTICE_TX . get_current_user_id(), $notice, 60);
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG));
        exit;
    }

    public static function handle_sync_all(): void
    {
        if (!current_user_can(self::CAP)) wp_die('You do not have permission to do this.', 403);
        check_admin_referer(self::A_SYNC_ALL);
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            'SELECT id FROM ' . IQU_Billing_DB::accounts_table() . " WHERE mode = %s AND stripe_customer_id <> '' ORDER BY id",
            IQU_Stripe::expected_mode()
        ));
        $start = time(); $done = 0; $changed = 0; $failed = 0; $left = 0; $invoices = 0;
        foreach ($ids as $id) {
            if (time() - $start > self::TIME_BUDGET) { $left++; continue; }
            $acc = IQU_Billing_DB::get_account((int) $id);
            if (!$acc) continue;
            $r = IQU_Billing_Sync::sync($acc);
            if (!$r['ok']) { $failed++; continue; }
            $done++;
            if ($r['old'] !== $r['new']) $changed++;
            $invoices += (int) IQU_Billing_History::refresh_account($r['account']);
        }
        $text = "Synced {$done} families, {$changed} changed, {$failed} could not be read." . ($left ? " {$left} not reached — press Sync again." : '')
            . " Payment history: {$invoices} invoices stored.";
        set_transient(self::NOTICE_TX . get_current_user_id(), ['type' => $failed ? 'warning' : 'success', 'text' => $text], 60);
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG));
        exit;
    }

    /** Read every family's invoices from Stripe into the payment history table. */
    public static function handle_backfill(): void
    {
        if (!current_user_can(self::CAP)) wp_die('You do not have permission to do this.', 403);
        check_admin_referer(self::A_BACKFILL);
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            'SELECT id FROM ' . IQU_Billing_DB::accounts_table() . " WHERE mode = %s AND stripe_customer_id <> '' ORDER BY id",
            IQU_Stripe::expected_mode()
        ));
        $start = time(); $families = 0; $invoices = 0; $failed = 0; $left = 0;
        foreach ($ids as $id) {
            if (time() - $start > self::TIME_BUDGET) { $left++; continue; }
            $acc = IQU_Billing_DB::get_account((int) $id);
            if (!$acc) continue;
            $n = IQU_Billing_History::refresh_account($acc);
            if ($n === null) { $failed++; continue; }
            $families++;
            $invoices += $n;
        }
        $text = "Stored {$invoices} invoices for {$families} families."
            . ($failed ? " {$failed} could not be read from Stripe." : '')
            . ($left ? " {$left} not reached — press Backfill again." : '');
        set_transient(self::NOTICE_TX . get_current_user_id(), ['type' => ($failed || $left) ? 'warning' : 'success', 'text' => $text], 60);
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG));
        exit;
    }

    public static function render(): void
    {
        if (!current_user_can(self::CAP)) {
            wp_die('You do not have permission to view this page.', 403);
        }

        $notice = get_transient(self::NOTICE_TX . get_current_user_id());
        if ($notice) delete_transient(self::NOTICE_TX . get_current_user_id());

        $ready      = IQU_Stripe::is_ready();
        $reason     = IQU_Stripe::not_ready_reason();
        $key_mode   = IQU_Stripe::key_mode();
        $site_mode  = IQU_Stripe::expected_mode();
        $restricted = IQU_Stripe::key_is_restricted();
        $wh_set     = defined('IQU_STRIPE_WEBHOOK_SECRET') && IQU_STRIPE_WEBHOOK_SECRET;
        $wh_url     = rest_url('iqu/v1/stripe-webhook');
        $receipts   = IQU_Billing_Receipt::sender();
        $stripe_emails = 'https://dashboard.stripe.com/' . ($site_mode === 'test' ? 'test/' : '') . 'settings/emails';
        $ch         = get_option(IQU_Billing_Receipt::OPT_CHANGED, []);
        $who        = !empty($ch['user']) ? get_userdata((int) $ch['user']) : false;
        $changed    = !empty($ch['at']) ? trim(($who ? $who->display_name : 'an administrator') . ' on ' . wp_date('j M Y', (int) $ch['at'])) : '';
        ?>
        <div class="wrap iqu-admin-wrap iqu-billing">
            <?php IQU_Billing_Page::tabs('settings'); ?>
            <?php IQU_Billing_Page::page_header('Settings', 'Stripe connection, messages to families, enrollment switches and syncing with Stripe.'); ?>

            <?php if ($notice): ?>
                <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible"><p><?php echo esc_html($notice['text']); ?></p></div>
            <?php endif; ?>

            <!-- Row 1: Stripe connection | Sync with Stripe · Row 2: Messages to families | Country database · then Enrollment (full width) -->
            <div class="iqu-settings-grid">
            <!-- ── Stripe connection ──────────────────────────── -->
            <div class="iqu-card">
                <div class="iqu-card-head">
                    <span class="iqu-card-head-title">Stripe connection</span>
                    <span class="iqu-card-head-badge">Keys in wp-config.php</span>
                </div>
                <div class="iqu-billing-body">
                    <p class="iqu-billing-intro">Stripe connection for monthly tuition. Keys live in <code>wp-config.php</code> and are never shown in full.</p>
                    <?php if ($ready): ?>
                        <div class="notice notice-success inline"><p><strong>Ready.</strong> Stripe is set up in <?php echo esc_html($site_mode); ?> mode.</p></div>
                    <?php else: ?>
                        <div class="notice notice-error inline"><p><strong>Not ready.</strong> <?php echo esc_html($reason); ?></p></div>
                    <?php endif; ?>
                </div>

                <div class="iqu-fld-grid">
                    <div class="iqu-fld">
                        <span class="iqu-fld-label">Site mode</span>
                        <div class="iqu-fld-value"><strong><?php echo esc_html(ucfirst($site_mode)); ?></strong></div>
                        <?php if ($site_mode === 'test'): ?>
                            <p class="iqu-fld-hint">No real money moves in test mode. Switching to live happens at go-live, after the security review.</p>
                        <?php endif; ?>
                    </div>
                    <div class="iqu-fld">
                        <span class="iqu-fld-label">Secret key</span>
                        <?php if (IQU_Stripe::has_key()): ?>
                            <div class="iqu-fld-value"><code><?php echo esc_html(IQU_Stripe::masked_key()); ?></code>
                                &nbsp;from <code>wp-config.php</code></div>
                            <p class="iqu-fld-hint">
                                Key type: <?php echo esc_html($key_mode); ?>,
                                <?php echo $restricted ? 'restricted' : 'standard'; ?>.
                                <?php if (!$restricted): ?>
                                    A restricted key is required before going live.
                                <?php endif; ?>
                            </p>
                        <?php else: ?>
                            <div class="iqu-fld-value"><em>Not set.</em></div>
                        <?php endif; ?>
                    </div>
                    <div class="iqu-fld iqu-fld--full">
                        <span class="iqu-fld-label">Webhook</span>
                        <div class="iqu-fld-value"><?php echo $wh_set ? '<strong>Signing secret set.</strong>' : '<em>Not set yet.</em>'; ?></div>
                        <p class="iqu-fld-hint">Endpoint to add in Stripe: <code><?php echo esc_html($wh_url); ?></code></p>
                    </div>
                </div>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="iqu-cpn-actions">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
                    <?php wp_nonce_field(self::ACTION); ?>
                    <?php submit_button('Test connection', 'secondary', 'submit', false, $ready ? [] : ['disabled' => 'disabled']); ?>
                </form>
            </div>

            <!-- ── Sync with Stripe ───────────────────────────── -->
            <div class="iqu-card">
                <div class="iqu-card-head">
                    <span class="iqu-card-head-title">Sync with Stripe</span>
                </div>
                <div class="iqu-billing-body">
                    <p class="iqu-billing-intro">Stripe updates arrive by webhook within seconds. If one was missed, this reads every family's billing again from Stripe.</p>
                    <p class="iqu-fld-hint"><strong>Backfill payment history</strong> reads every invoice of every family from Stripe — paid, failed, open and refunded — for the Payments charts, Reports and each family's history. Run it once after updating; it is safe to run again.</p>
                </div>
                <div class="iqu-cpn-actions">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="<?php echo esc_attr(self::A_SYNC_ALL); ?>">
                        <?php wp_nonce_field(self::A_SYNC_ALL); ?>
                        <?php submit_button('Sync all families', 'secondary', 'submit', false, $ready ? [] : ['disabled' => 'disabled']); ?>
                    </form>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="<?php echo esc_attr(self::A_BACKFILL); ?>">
                        <?php wp_nonce_field(self::A_BACKFILL); ?>
                        <?php submit_button('Backfill payment history', 'secondary', 'submit', false, $ready ? [] : ['disabled' => 'disabled']); ?>
                    </form>
                </div>
            </div>

            <!-- ── Messages to families ───────────────────────── -->
            <div class="iqu-card">
                <div class="iqu-card-head">
                    <span class="iqu-card-head-title">Messages to families</span>
                    <span class="iqu-chip iqu-chip--<?php echo $receipts === 'iqu' ? 'green' : 'neutral'; ?>">Receipts: <?php echo $receipts === 'iqu' ? 'Ilm-ul-Quran USA' : 'Stripe'; ?></span>
                </div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="iqu-cpn-form">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::A_SAVE); ?>">
                    <?php wp_nonce_field(self::A_SAVE); ?>
                    <div class="iqu-fld-grid">
                        <div class="iqu-fld">
                            <label for="zelle_end">Zelle end date</label>
                            <input id="zelle_end" type="date" name="zelle_end" value="<?php echo esc_attr((string) get_option(self::OPT_ZELLE, '')); ?>">
                            <p class="iqu-fld-hint">When set, every payment link message and email adds: “From [this date], tuition can no longer be sent by Zelle.” Leave empty to leave the line out.</p>
                        </div>
                        <fieldset class="iqu-fld iqu-receipt-fld" data-receipt-sender>
                            <legend class="iqu-fld-label">Who sends payment receipts?</legend>
                            <label class="iqu-enroll-choice">
                                <input type="radio" name="receipt_sender" value="stripe" <?php checked($receipts, 'stripe'); ?>>
                                <strong>Stripe</strong> — Stripe's standard receipt email
                            </label>
                            <label class="iqu-enroll-choice">
                                <input type="radio" name="receipt_sender" value="iqu" <?php checked($receipts, 'iqu'); ?>>
                                <strong>Ilm-ul-Quran USA</strong> — our own receipt with the student, month and next payment
                            </label>
                            <div class="iqu-receipt-note iqu-receipt-note--iqu" data-receipt-for="iqu">
                                <label class="iqu-receipt-confirm">
                                    <input type="checkbox" name="receipt_confirm" value="1">
                                    I have turned off “Successful payments” in Stripe → Settings → Customer emails
                                </label>
                                <p class="iqu-fld-hint">Required before saving, so families do not get two receipts. <a href="<?php echo esc_url($stripe_emails); ?>" target="_blank" rel="noopener noreferrer">Open Stripe’s customer email settings<span class="screen-reader-text"> (opens in a new tab)</span></a></p>
                            </div>
                            <div class="notice notice-info inline iqu-receipt-note" data-receipt-for="stripe">
                                <p>Make sure “Successful payments” is <strong>on</strong> in Stripe → Settings → Customer emails, otherwise families get no receipt. <a href="<?php echo esc_url($stripe_emails); ?>" target="_blank" rel="noopener noreferrer">Open Stripe’s customer email settings<span class="screen-reader-text"> (opens in a new tab)</span></a></p>
                            </div>
                            <?php if ($changed): ?>
                                <p class="iqu-fld-hint iqu-receipt-changed">Changed by <?php echo esc_html($changed); ?></p>
                            <?php endif; ?>
                        </fieldset>
                    </div>
                    <div class="iqu-cpn-actions">
                        <?php submit_button('Save', 'primary', 'submit', false); ?>
                    </div>
                </form>
            </div>

            <?php if (class_exists('IQU_Enrollment_Admin')) IQU_Enrollment_Admin::render_geo_card(); ?>
            </div>

            <?php if (class_exists('IQU_Enrollment_Admin')) IQU_Enrollment_Admin::render_card(); ?>

        </div>
        <?php
    }
}
