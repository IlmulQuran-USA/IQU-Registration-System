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
        $problems = IQU_Enrollment_Settings::save(wp_unslash($_POST));
        self::back($problems
            ? ['type' => 'error', 'text' => 'Saved, except: ' . implode(' ', $problems)]
            : ['type' => 'success', 'text' => 'Enrollment settings saved.']);
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
                <?php if ($hint !== ''): ?><span class="iqu-fld-hint"><?php echo esc_html($hint); ?></span><?php endif; ?>
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
                <span class="iqu-card-head-badge">Who can enroll and how families pay</span>
            </div>
            <div class="iqu-billing-body">
                <p class="iqu-billing-intro">Switches for the three enrollment forms. With the defaults (everyone can enroll while visitors from outside the US and Canada are recorded, no card step, Zeffy for Summer) the forms work exactly as before.</p>
                <?php if ($test): ?>
                    <div class="notice notice-info inline"><p><strong>Stripe is in test mode, so only administrators see the card step.</strong> Families still enroll the usual way.</p></div>
                <?php endif; ?>
            </div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="iqu-cpn-form">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::A_SAVE); ?>">
                <?php wp_nonce_field(self::A_SAVE); ?>
                <div class="iqu-fld-grid">
                    <fieldset class="iqu-fld iqu-fld--full">
                        <legend class="iqu-fld-label">Who can enroll</legend>
                        <?php self::choice('country_check', IQU_Enrollment_Settings::country_mode(), [
                            'off'     => ['Everyone — no country check', ''],
                            'monitor' => ['Everyone, but record visitors from outside the US and Canada (testing)', 'Nobody is blocked. What would have been blocked is listed in the 9 PM Telegram summary.'],
                            'enforce' => ['Only families in the US and Canada', 'Families outside the US and Canada see a short message and can join the waitlist.'],
                        ]); ?>
                    </fieldset>
                    <fieldset class="iqu-fld">
                        <legend class="iqu-fld-label">Card step after Enroll for Free</legend>
                        <?php self::choice('free_payment', IQU_Enrollment_Settings::free_mode(), [
                            'off' => ['Off — families enroll without adding a card', 'The form saves the enrollment and shows the thank-you, as before.'],
                            'on'  => ['On — families add a card or US bank account (first month free)', 'After the form, the family adds it once on Stripe\'s secure page.'],
                        ]); ?>
                    </fieldset>
                    <fieldset class="iqu-fld">
                        <legend class="iqu-fld-label">How Summer families pay</legend>
                        <?php self::choice('summer_payment', IQU_Enrollment_Settings::summer_mode(), [
                            'zeffy'  => ['Zeffy (current)', 'Zelle or Zeffy, as before.'],
                            'stripe' => ['Card on our website (Stripe)', 'One payment on Stripe\'s secure page, confirmed by Stripe.'],
                        ]); ?>
                    </fieldset>
                    <div class="iqu-fld">
                        <label for="iqu_summer_fee">Summer fee — Standard (USD)</label>
                        <input id="iqu_summer_fee" type="text" inputmode="decimal" name="summer_fee" value="<?php echo esc_attr(number_format(IQU_Enrollment_Settings::summer_fee('standard'), 2, '.', '')); ?>">
                        <p class="iqu-fld-hint">Paid once, at registration, for the whole program.</p>
                    </div>
                    <div class="iqu-fld">
                        <label for="iqu_summer_fee_supported">Summer fee — Supported rate (USD)</label>
                        <input id="iqu_summer_fee_supported" type="text" inputmode="decimal" name="summer_fee_supported" value="<?php echo esc_attr(number_format(IQU_Enrollment_Settings::summer_fee('supported'), 2, '.', '')); ?>">
                        <p class="iqu-fld-hint">Paid once, at registration, for the whole program.</p>
                    </div>
                </div>
                <div class="iqu-cpn-actions">
                    <?php submit_button('Save enrollment settings', 'primary', 'submit', false); ?>
                </div>
            </form>
        </div>
        <?php
    }

    /** GeoLite2 database status and the "Update now" button, as its own card on Billing Settings. */
    public static function render_geo_card(): void
    {
        if (!current_user_can(self::CAP)) return;
        $meta  = IQU_Geo::meta();
        $file  = IQU_Geo::db_file();
        $creds = IQU_Geo::has_credentials();
        $when  = fn($ts) => $ts ? wp_date('j M Y', (int) $ts) : '—';
        ?>
        <!-- ── Country database ───────────────────────────── -->
        <div class="iqu-card" id="iqu-geo-settings">
        <div class="iqu-card-head">
            <span class="iqu-card-head-title">Country lookup database</span>
        </div>
        <div class="iqu-billing-body">
            <div class="iqu-enroll-geo">
                <span>Last updated: <strong><?php echo esc_html($file !== '' ? $when($meta['updated_at'] ?? 0) : 'not installed yet'); ?></strong></span>
                <span>Updates automatically every week</span>
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
        </div>
        <?php
    }
}
