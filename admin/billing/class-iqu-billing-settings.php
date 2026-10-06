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
    private const OPT_ZELLE = 'iqu_billing_zelle_end_date';

    public static function init(): void
    {
        add_action('admin_menu', [__CLASS__, 'register_menu'], 20);
        add_action('admin_post_' . self::ACTION, [__CLASS__, 'handle_test_connection']);
        add_action('admin_post_' . self::A_SAVE, [__CLASS__, 'handle_save']);
        add_action('admin_post_' . self::A_SYNC_ALL, [__CLASS__, 'handle_sync_all']);
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
        $date = sanitize_text_field(wp_unslash($_POST['zelle_end'] ?? ''));
        if ($date === '') {
            delete_option(self::OPT_ZELLE);
            $notice = ['type' => 'success', 'text' => 'Zelle end date cleared. Messages will not mention Zelle.'];
        } else {
            $d = DateTime::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));
            if (!$d || $d->format('Y-m-d') !== $date) {
                $notice = ['type' => 'error', 'text' => 'That date is not valid.'];
            } else {
                update_option(self::OPT_ZELLE, $date, false);
                $notice = ['type' => 'success', 'text' => 'Saved. Messages now say tuition can no longer be sent by Zelle from ' . $d->format('j F Y') . '.'];
            }
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
        $start = time(); $done = 0; $changed = 0; $failed = 0; $left = 0;
        foreach ($ids as $id) {
            if (time() - $start > 20) { $left++; continue; } // stay well inside the request time limit
            $acc = IQU_Billing_DB::get_account((int) $id);
            if (!$acc) continue;
            $r = IQU_Billing_Sync::sync($acc);
            if (!$r['ok']) { $failed++; continue; }
            $done++;
            if ($r['old'] !== $r['new']) $changed++;
        }
        $text = "Synced {$done} families, {$changed} changed, {$failed} could not be read." . ($left ? " {$left} not reached — press Sync again." : '');
        set_transient(self::NOTICE_TX . get_current_user_id(), ['type' => $failed ? 'warning' : 'success', 'text' => $text], 60);
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
        ?>
        <div class="wrap iqu-admin-wrap iqu-billing">
            <?php IQU_Billing_Page::tabs('settings'); ?>

            <?php if ($notice): ?>
                <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible"><p><?php echo esc_html($notice['text']); ?></p></div>
            <?php endif; ?>

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

            <!-- ── Messages to families ───────────────────────── -->
            <div class="iqu-card">
                <div class="iqu-card-head">
                    <span class="iqu-card-head-title">Messages to families</span>
                    <span class="iqu-card-head-badge">Payment link message and email</span>
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
                    </div>
                    <div class="iqu-cpn-actions">
                        <?php submit_button('Save', 'primary', 'submit', false); ?>
                    </div>
                </form>
            </div>

            <!-- ── Sync with Stripe ───────────────────────────── -->
            <div class="iqu-card">
                <div class="iqu-card-head">
                    <span class="iqu-card-head-title">Sync with Stripe</span>
                </div>
                <div class="iqu-billing-body">
                    <p class="iqu-billing-intro">Stripe updates arrive by webhook within seconds. If one was missed, this reads every family's billing again from Stripe.</p>
                </div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="iqu-cpn-actions">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::A_SYNC_ALL); ?>">
                    <?php wp_nonce_field(self::A_SYNC_ALL); ?>
                    <?php submit_button('Sync all families', 'secondary', 'submit', false, $ready ? [] : ['disabled' => 'disabled']); ?>
                </form>
            </div>
        </div>
        <?php
    }
}
