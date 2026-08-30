<?php

/**
 * Plugin Name:       IQU Registration System
 * Plugin URI:        https://ilmulquranus.org
 * Description:       Secure student registration system for Ilm-ul-Quran USA — includes Free Enrollment & Summer Program forms, dashboard, Zelle/Zeffy payments, and Google reCAPTCHA v3.
 * Version:           2.9.9
 * Author:            Ilm-ul-Quran USA (Muhammad Nurul Ahsan)
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
define('IQU_VERSION', '2.9.9');
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
define('IQU_RECAPTCHA_THRESHOLD', 0.5);

// Zeffy fundraising URL
define('IQU_ZEFFY_URL', 'https://www.zeffy.com/en-US/fundraising/d912691e-fbd0-4140-9453-a7b230b333eb');

// Zelle / contact info
define('IQU_ZELLE_PHONE', '469-275-6450');
define('IQU_ZELLE_EMAIL', 'admin@alhasanahfoundation.org');
define('IQU_CONTACT_PHONE', '(214) 529-3544');
define('IQU_CONTACT_EMAIL', 'info@ilmulquranusa.org'); 
define('IQU_MESSENGER_URL', 'https://www.facebook.com/ilmulquranusa');

define(
  'IQU_ZELLE_LOGO_URL',
  IQU_PLUGIN_URL . 'assets/images/Zelle-Logo-Color.svg'
);

define(
  'IQU_ZEFFY_LOGO_URL',
  IQU_PLUGIN_URL . 'assets/images/Zeffy-Logo-Color.svg'
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
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-database.php';
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
require_once IQU_PLUGIN_DIR . 'admin/class-iqu-admin.php';
require_once IQU_PLUGIN_DIR . 'admin/class-iqu-zeffy-admin.php';
require_once IQU_PLUGIN_DIR . 'admin/class-iqu-coupon-admin.php';
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-telegram.php';
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-notifier.php';
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-pricing.php';
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-coupon-db.php';
require_once IQU_PLUGIN_DIR . 'includes/class-iqu-coupon.php';

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
}

function iqu_on_deactivation(): void
{
  IQU_Database::on_deactivation();
  IQU_Zeffy_Sync::unschedule();
  IQU_Notifier::unschedule();
}

register_activation_hook(__FILE__, 'iqu_on_activation');
register_deactivation_hook(__FILE__, 'iqu_on_deactivation');

add_action('plugins_loaded', function () {
  IQU_Database::maybe_upgrade();
  IQU_Zeffy_DB::maybe_upgrade();
  IQU_Coupon_DB::maybe_upgrade();

  IQU_Zeffy_Sync::schedule();
  IQU_Notifier::schedule();

  new IQU_Form();
  new IQU_Summer_Form();

  new IQU_Zeffy_Sync();
  new IQU_Zeffy_Webhook();
  new IQU_Notifier();

  if (is_admin()) {
    new IQU_Admin();
    new IQU_Zeffy_Admin();
    new IQU_Coupon_Admin(); 
  }
});

add_action('wp_ajax_iqu_geoip', 'iqu_geoip_callback');
add_action('wp_ajax_nopriv_iqu_geoip', 'iqu_geoip_callback');

function iqu_geoip_callback()
{
  // 1. প্রথমে Cloudflare হেডারে থাকলে সেট ব্যবহার করুন
  $country = '';
  if (!empty($_SERVER['HTTP_CF_IPCOUNTRY']) && $_SERVER['HTTP_CF_IPCOUNTRY'] !== 'XX') {
    $country = strtolower(sanitize_text_field($_SERVER['HTTP_CF_IPCOUNTRY']));
  }

  // 2. Cloudflare হেডার না থাকলে ক্লায়েন্ট IP বের করে GeoIP API কল করুন
  if (!$country) {
    // প্রক্সি থাকলে X-Forwarded-For থেকে প্রকৃত IP নিন, নইলে REMOTE_ADDR
    $ip = '';
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
      $ip = sanitize_text_field($_SERVER['HTTP_CF_CONNECTING_IP']);
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
      $ip_parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
      $ip = sanitize_text_field(trim($ip_parts[0]));
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
      $ip = sanitize_text_field($_SERVER['REMOTE_ADDR']);
    }

    // IP থাকলে ipapi.co থেকে দেশ কোড আনুন
    if ($ip) {
      // এই এন্ডপয়েন্ট একটি IP‑এর দেশ কোড রিটার্ন করে:contentReference[oaicite:1]{index=1}
      $response = wp_remote_get("https://ipapi.co/{$ip}/country/");
      if (!is_wp_error($response)) {
        $code = strtolower(trim(wp_remote_retrieve_body($response)));
        if (preg_match('/^[a-z]{2}$/', $code)) {
          $country = $code;
        }
      }
    }

    // API থেকে কিছু না পেলে fallback হিসাবে us দিন
    if (!$country) {
      $country = 'us';
    }
  }

  wp_send_json(['countryCode' => $country]);
}