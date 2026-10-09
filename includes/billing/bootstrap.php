<?php
/**
 * IQU Billing — monthly tuition through Stripe.
 *
 * Loaded from iqu-registration.php. Registers every billing class.
 * Classes live in includes/billing/ (front end, webhook, family page) and
 * admin/billing/ (IQU Registrations → Billing screens).
 *
 * Keys come from wp-config.php only:
 *   IQU_STRIPE_SECRET_KEY, IQU_STRIPE_WEBHOOK_SECRET
 * Telegram uses the plugin's existing IQU_TELEGRAM_* constants.
 */
if (!defined('ABSPATH')) exit;

// Never load twice (for example while an older copy still exists elsewhere).
if (defined('IQU_BILLING_VERSION') || class_exists('IQU_Stripe', false)) {
    return;
}

define('IQU_BILLING_VERSION', '1.0.0');

add_action('plugins_loaded', function () {
    $inc = IQU_PLUGIN_DIR . 'includes/billing/';
    $adm = IQU_PLUGIN_DIR . 'admin/billing/';

    require_once $inc . 'class-iqu-stripe.php';
    require_once $inc . 'class-iqu-billing-db.php';
    require_once $inc . 'class-iqu-billing-pricing.php';
    require_once $inc . 'class-iqu-billing-service.php';
    require_once $inc . 'class-iqu-billing-sync.php';
    require_once $inc . 'class-iqu-billing-history.php';
    require_once $inc . 'class-iqu-billing-notify.php';
    require_once $inc . 'class-iqu-billing-webhook.php';
    require_once $inc . 'class-iqu-billing-summary.php';
    require_once $inc . 'class-iqu-billing-portal.php';

    add_action('init', ['IQU_Billing_DB', 'maybe_install']);
    IQU_Billing_Webhook::init();
    IQU_Billing_Summary::init();
    IQU_Billing_Portal::init();

    if (is_admin()) {
        require_once $adm . 'class-iqu-billing-settings.php';
        require_once $adm . 'class-iqu-billing-add-student.php';
        require_once $adm . 'class-iqu-billing-import.php';
        require_once $adm . 'class-iqu-billing-send.php';
        require_once $adm . 'class-iqu-billing-page.php';
        IQU_Billing_Page::init();
        IQU_Billing_Settings::init();
        IQU_Billing_Add_Student::init();
        IQU_Billing_Import::init();
        IQU_Billing_Send::init();
    }
}, 20);
