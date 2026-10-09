<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Enrollment_Admin
 *
 * The "Enrollment" card on Billing Settings: safety switches for the
 * country check, the Enroll for Free payment step and the Summer payment.
 *
 * Security: manage_options + nonce on every action; all output escaped.
 */
class IQU_Enrollment_Admin
{
    private const CAP    = 'manage_options';
    private const A_SAVE = 'iqu_enroll_save_settings';
    private const A_GEO  = 'iqu_geo_update_now';
    private const NOTICE = 'iqu_billing_notice_'; // shown by the Billing Settings page

    public static function init(): void
    {
        add_action('admin_post_' . self::A_SAVE, [__CLASS__, 'handle_save']);
        add_action('admin_post_' . self::A_GEO, [__CLASS__, 'handle_geo_update']);
    }

    /** "Update now": download and install the GeoLite2 Country database. */
    public static function handle_geo_update(): void
    {
        if (!current_user_can(self::CAP)) wp_die('You do not have permission to do this.', 403);
        check_admin_referer(self::A_GEO);
        $r = IQU_Geo::update();
        self::back(['type' => $r['ok'] ? 'success' : 'error', 'text' => $r['message']]);
    }

    public static function handle_save(): void
    {
        if (!current_user_can(self::CAP)) wp_die('You do not have permission to do this.', 403);
        check_admin_referer(self::A_SAVE);
        IQU_Enrollment_Settings::save(wp_unslash($_POST));
        self::back(['type' => 'success', 'text' => 'Enrollment settings saved.']);
    }

    private static function back(array $notice): void
    {
        set_transient(self::NOTICE . get_current_user_id(), $notice, 60);
        wp_safe_redirect(admin_url('admin.php?page=iqu-billing-settings'));
        exit;
    }

    /** Radio group for one switch. */
    private static function choice(string $name, string $current, array $options): void
    {
        foreach ($options as $value => [$label, $hint]) {
            ?>
            <label class="iqu-enroll-choice">
                <input type="radio" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>" <?php checked($current, $value); ?>>
                <strong><?php echo esc_html($label); ?></strong>
                <span class="iqu-fld-hint"><?php echo esc_html($hint); ?></span>
            </label>
            <?php
        }
    }

    public static function render_card(): void
    {
        if (!current_user_can(self::CAP)) return;
        $test = class_exists('IQU_Stripe') && IQU_Stripe::expected_mode() === 'test';
        ?>
        <!-- ── Enrollment ─────────────────────────────────── -->
        <div class="iqu-card" id="iqu-enrollment-settings">
            <div class="iqu-card-head">
                <span class="iqu-card-head-title">Enrollment</span>
                <span class="iqu-card-head-badge">Country check and payment at sign-up</span>
            </div>
            <div class="iqu-billing-body">
                <p class="iqu-billing-intro">Safety switches for the three enrollment forms. With the defaults (Monitor, Off, Zeffy) the forms work exactly as before; Monitor only records what the country check would have blocked.</p>
                <?php if ($test): ?>
                    <div class="notice notice-info inline"><p><strong>Stripe is in test mode.</strong> The payment steps run only for logged-in administrators. Every other visitor sees the forms as before.</p></div>
                <?php endif; ?>
            </div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="iqu-cpn-form">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::A_SAVE); ?>">
                <?php wp_nonce_field(self::A_SAVE); ?>
                <div class="iqu-fld-grid">
                    <fieldset class="iqu-fld iqu-fld--full">
                        <legend class="iqu-fld-label">Country check (US and Canada only)</legend>
                        <?php self::choice('country_check', IQU_Enrollment_Settings::country_mode(), [
                            'off'     => ['Off', 'No country check at all.'],
                            'monitor' => ['Monitor', 'Record what would be blocked, never block. Shown in the 9 PM Telegram summary.'],
                            'enforce' => ['Enforce', 'Families outside the US and Canada see a message and can join the waitlist.'],
                        ]); ?>
                    </fieldset>
                    <fieldset class="iqu-fld">
                        <legend class="iqu-fld-label">Enroll for Free payment</legend>
                        <?php self::choice('free_payment', IQU_Enrollment_Settings::free_mode(), [
                            'off' => ['Off', 'The form saves the enrollment and shows the thank-you, as before.'],
                            'on'  => ['On', 'After the form, the family adds a card or US bank account in Stripe. The first month is free.'],
                        ]); ?>
                    </fieldset>
                    <fieldset class="iqu-fld">
                        <legend class="iqu-fld-label">Summer payment</legend>
                        <?php self::choice('summer_payment', IQU_Enrollment_Settings::summer_mode(), [
                            'zeffy'  => ['Zeffy', 'Zelle or Zeffy, as before.'],
                            'stripe' => ['Stripe', 'One card payment through Stripe Checkout, confirmed by the Stripe webhook.'],
                        ]); ?>
                    </fieldset>
                </div>
                <div class="iqu-cpn-actions">
                    <?php submit_button('Save enrollment settings', 'primary', 'submit', false); ?>
                </div>
            </form>
            <?php self::render_geo(); ?>
        </div>
        <?php
    }

    /** GeoLite2 database status and the "Update now" button. */
    private static function render_geo(): void
    {
        $meta  = IQU_Geo::meta();
        $file  = IQU_Geo::db_file();
        $creds = IQU_Geo::has_credentials();
        $when  = fn($ts) => $ts ? wp_date('j M Y, g:i A', (int) $ts) : '—';
        ?>
        <div class="iqu-billing-body">
            <p class="iqu-fld-label"><strong>Country database (MaxMind GeoLite2 Country)</strong></p>
            <div class="iqu-enroll-geo">
                <span>Database date: <strong><?php echo esc_html($file !== '' ? ($meta['build'] ?? 'unknown') : 'not installed'); ?></strong></span>
                <?php if ($file !== ''): ?>
                    <span>Stored: <?php echo esc_html(IQU_Geo::location_label()); ?></span>
                <?php endif; ?>
                <span>Last update: <?php echo esc_html($when($meta['updated_at'] ?? 0)); ?></span>
                <span>Refresh: weekly (WP-Cron)</span>
            </div>
            <?php if (!$creds): ?>
                <p class="iqu-fld-hint">Add <code>IQU_MAXMIND_ACCOUNT_ID</code> and <code>IQU_MAXMIND_LICENSE_KEY</code> to <code>wp-config.php</code> to download the database.</p>
            <?php endif; ?>
            <?php if (!empty($meta['last_error'])): ?>
                <div class="notice notice-warning inline"><p>Last attempt (<?php echo esc_html($when($meta['last_attempt'] ?? 0)); ?>): <?php echo esc_html($meta['last_error']); ?></p></div>
            <?php endif; ?>
            <?php if ($file === ''): ?>
                <p class="iqu-fld-hint">Without the database the country is unknown: only the phone number and country of residence are checked. Forms keep working.</p>
            <?php endif; ?>
            <p class="iqu-fld-hint">Only the 2-letter country code is used; IP addresses are not stored. Keep <code>/wp-json/iqu/v1/geo</code> out of page caching (W3 Total Cache / AirLift): it answers per visitor and is sent with <code>Cache-Control: no-store</code>.</p>
        </div>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="iqu-cpn-actions">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::A_GEO); ?>">
            <?php wp_nonce_field(self::A_GEO); ?>
            <?php submit_button('Update now', 'secondary', 'submit', false, $creds ? [] : ['disabled' => 'disabled']); ?>
        </form>
        <?php
    }
}
