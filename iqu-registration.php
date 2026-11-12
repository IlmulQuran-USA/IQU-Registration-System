<?php

/**
 * Plugin Name:       IQU Registration System
 * Plugin URI:        https://ilmulquranus.org
 * Description:       Secure student registration system for Ilm-Ul-Quran USA — includes Free Enrollment & Summer Program forms, dashboard, Zelle/Zeffy payments, Google reCAPTCHA v3, and Stripe monthly tuition billing.
 * Version:           3.7.11
 * Author:            Ilm-Ul-Quran USA (Muhammad Nurul Ahsan)
 * License:           GPL-2.0+
 * Text Domain:       iqu-registration
 */

// ============================================================
// 🔒 SECURITY: Direct access থেকে রক্ষা
// ============================================================
if (!defined('ABSPATH')) {
  exit('Direct access not allowed.');
}

// ============================================================
// 📌 Constants
// ============================================================
define('IQU_VERSION', '3.7.11');
define('IQU_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('IQU_PLUGIN_URL', plugin_dir_url(__FILE__));
define('IQU_TABLE_NAME', 'iqu_registrations');
define('IQU_ZEFFY_TABLE', 'iqu_zeffy_payments');
define('IQU_COUPON_TABLE', 'iqu_coupons');

// Version-pinned CDN assets for intl-tel-input.
define('IQU_INTL_TEL_INPUT_VERSION', '23.0.10');
define(
  'IQU_INTL_TEL_INPUT_CSS',
  'https://cdn.jsdelivr.net/npm/intl-tel-input@23.0.10/build/css/intlTelInput.css'
);
define(
  'IQU_INTL_TEL_INPUT_JS',
  'https://cdn.jsdelivr.net/npm/intl-tel-input@23.0.10/build/js/intlTelInput.min.js'
);

// ✅ utils.js এর বদলে global bundle use করুন
define(
  'IQU_INTL_TEL_INPUT_UTILS_JS',
  'https://cdn.jsdelivr.net/npm/intl-tel-input@23.0.10/build/js/utils.js?module=false'
);

// Google reCAPTCHA v3 keys (user-provided) 
if (!defined('IQU_RECAPTCHA_THRESHOLD')) define('IQU_RECAPTCHA_THRESHOLD', 0.5); // may be set in wp-config.php

// Zeffy fundraising URL
define('IQU_ZEFFY_URL', 'https://www.zeffy.com/en-US/fundraising/d912691e-fbd0-4140-9453-a7b230b333eb');

// Zelle / contact info
define('IQU_ZELLE_PHONE', '469-275-6450');
define('IQU_ZELLE_EMAIL', 'admin@alhasanahfoundation.org');
define('IQU_CONTACT_PHONE', '(214) 529-3544');
define('IQU_CONTACT_EMAIL', 'info@ilmulquranus.org');
define('IQU_MESSENGER_URL', 'https://www.facebook.com/ilmulquranusa');

define(
  'IQU_ZELLE_LOGO_URL',
  IQU_PLUGIN_URL . 'assets/images/Zelle-Logo-Color.svg'
);

define(
  'IQU_ZEFFY_LOGO_URL',
  IQU_PLUGIN_URL . 'assets/images/Zeffy-Logo-Color.svg'
);

define(
  'IQU_WEEKEND_HERO_IMG',
  IQU_PLUGIN_URL . 'assets/images/weekend-hero.png'
);

// Set this to a hosted image URL or a data:image/... string when the logo is ready.
if (!defined('IQU_EMAIL_LOGO_SRC')) {
  $logo_path = WP_CONTENT_DIR . '/uploads/2024/11/Icon-Text.png';

  if (file_exists($logo_path)) {
    $logo_b64 = base64_encode(file_get_contents($logo_path));
    define('IQU_EMAIL_LOGO_SRC', 'data:image/png;base64,' . $logo_b64);
  } else {
    define('IQU_EMAIL_LOGO_SRC', '');
  }
}

// ============================================================
// 📂 Includes
// ============================================================
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-schema.php';
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-csv.php';
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-xlsx.php';   // Excel (.xlsx) files: PHP's ZipArchive + XMLReader, no library
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-export.php'; // CSV or Excel for every export and import, and the button that offers both
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-cdn.php';
IQU_CDN::init(); // integrity hashes on the version-pinned CDN files
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-submit-guard.php';
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-database.php';
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-contact.php';
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-link-keys.php';
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-dob.php';
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-birthday.php';
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-validator.php';
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-recaptcha.php';
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-mailer.php';
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-reg-fields.php';
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-pixel.php';
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-zeffy-db.php';
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-zeffy-api.php';
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-zeffy-sync.php';
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-zeffy-webhook.php';
require_once IQU_PLUGIN_DIR . 'public/class-iqu-form.php';
require_once IQU_PLUGIN_DIR . 'public/class-iqu-summer-form.php';
require_once IQU_PLUGIN_DIR . 'public/class-iqu-weekend-form.php';
require_once IQU_PLUGIN_DIR . 'admin/class-iqu-icons.php';
require_once IQU_PLUGIN_DIR . 'admin/class-iqu-pager.php';
require_once IQU_PLUGIN_DIR . 'admin/class-iqu-admin.php';
require_once IQU_PLUGIN_DIR . 'admin/class-iqu-zeffy-admin.php';
require_once IQU_PLUGIN_DIR . 'admin/class-iqu-coupon-admin.php';
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-telegram.php';
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-notifier.php';
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-pricing.php';
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-coupon-db.php';
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-coupon.php';
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-enrollment-settings.php';
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-geo.php';
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-eligibility.php';
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-waitlist.php';
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-enrollment.php';
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-summer-pay.php';

// 💳 Monthly tuition billing (Stripe) — see includes/billing/bootstrap.php
require_once IQU_PLUGIN_DIR . 'includes/billing/bootstrap.php';

// 📒 Zakat / Special discount ledger, month by month (3.6.0)
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-discount-ledger.php';

// 🗄️ Archive (never delete billed students) and the hard-delete rules (3.7.0)
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-archive.php';
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-reenroll.php'; // 3.7.9: re-enrollment updates the existing record

// ============================================================
// 🚀 Activation / Deactivation
// ============================================================
function iqu_on_activation(): void
{
  IQU_Database::create_table();
  IQU_Zeffy_DB::create_table();
  IQU_Coupon_DB::create_table();
  IQU_Zeffy_Sync::schedule();
  IQU_Notifier::schedule();
  IQU_Geo::schedule();
  IQU_Enrollment::schedule();
  IQU_Discount_Ledger::maybe_install();
  // (the 3.6.2 migration is not run here: the billing classes it needs are not loaded while a plugin is
  // being activated — it runs on the next request, on init; see below)
  IQU_Discount_Ledger::schedule();
}

function iqu_on_deactivation(): void
{
  IQU_Database::on_deactivation();
  IQU_Zeffy_Sync::unschedule();
  IQU_Notifier::unschedule();
  IQU_Geo::unschedule();
  IQU_Enrollment::unschedule();
  IQU_Discount_Ledger::unschedule();
}

register_activation_hook(__FILE__, 'iqu_on_activation');
register_deactivation_hook(__FILE__, 'iqu_on_deactivation');

add_action('plugins_loaded', function () {
  IQU_Database::maybe_upgrade();
  IQU_Zeffy_DB::maybe_upgrade();
  IQU_Coupon_DB::maybe_upgrade();

  IQU_Zeffy_Sync::schedule();
  IQU_Notifier::schedule();
  IQU_Geo::schedule();
  IQU_Geo::init();
  IQU_Waitlist::init();
  IQU_Eligibility::init();
  IQU_Enrollment::schedule();
  IQU_Enrollment::init();
  IQU_Summer_Pay::init();
  IQU_Discount_Ledger::maybe_install();
  // One-time migrations that need the billing classes run on init: includes/billing/bootstrap.php loads
  // those classes on plugins_loaded at priority 20, after this callback (priority 10), and installs its
  // tables on init at 10. On plugins_loaded the 3.6.2 migration found no IQU_Billing_Pricing and never ran.
  add_action('init', ['IQU_Discount_Ledger', 'migrate_admin_discounts'], 12);
  add_action('init', ['IQU_Database', 'fix_quoted_amounts_3711'], 12); // 3.7.11: the $0.00 of admin-added / imported students
  IQU_Discount_Ledger::schedule();
  IQU_Discount_Ledger::init();

  new IQU_Form();
  new IQU_Summer_Form();
  new IQU_Weekend_Form();

  new IQU_Zeffy_Sync();
  new IQU_Zeffy_Webhook();
  new IQU_Notifier();

  if (is_admin()) {
    IQU_Pager::init(); // a long list's table alone (live search), see admin/class-iqu-pager.php
    new IQU_Admin();
    new IQU_Zeffy_Admin();
    new IQU_Coupon_Admin();
    require_once IQU_PLUGIN_DIR . 'admin/class-iqu-discount-ledger-admin.php';
    IQU_Discount_Ledger_Admin::init();
    require_once IQU_PLUGIN_DIR . 'admin/class-iqu-discount-students-admin.php'; // 3.7.0: Zakat & Discount students
    IQU_Discount_Students_Admin::init();
    require_once IQU_PLUGIN_DIR . 'admin/class-iqu-enrollment-admin.php';
    require_once IQU_PLUGIN_DIR . 'admin/class-iqu-waitlist-admin.php';
    IQU_Enrollment_Admin::init();
    IQU_Waitlist_Admin::init();
  }
});

add_action('wp_ajax_iqu_geoip', 'iqu_geoip_callback');
// A fresh form nonce for a page served from a page cache (see IQU_Submit_Guard::ajax_fresh_nonce).
add_action('wp_ajax_iqu_fresh_nonce', ['IQU_Submit_Guard', 'ajax_fresh_nonce']);
add_action('wp_ajax_nopriv_iqu_fresh_nonce', ['IQU_Submit_Guard', 'ajax_fresh_nonce']);
add_action('wp_ajax_nopriv_iqu_geoip', 'iqu_geoip_callback');

function iqu_geoip_callback()
{
  // Country from the local GeoLite2 database (IQU_Geo); "us" when unknown.
  // No Cloudflare header and no third-party lookup — the IP address is not sent anywhere.
  $country = strtolower(IQU_Geo::country());
  nocache_headers();
  wp_send_json(['countryCode' => $country !== '' ? $country : 'us']);
}