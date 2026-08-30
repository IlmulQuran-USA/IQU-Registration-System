<?php
if (!defined('ABSPATH'))
  exit;

/**
 * Class IQU_Mailer
 *
 * Sends elegant, formal HTML emails to both the admin and the registrant
 * for every form type (free / summer level 1 / summer level 2) and
 * includes payment information when present.
 */
class IQU_Mailer
{

  /**
   * Admin notification for a new registration.
   */
  public static function send_admin_notification(array $data, int $reg_id): void
  {
    $admin_email = get_option('admin_email');
    $site_name = get_bloginfo('name');

    $subject = sprintf(
      '[%s] New %s Registration #%d — %s %s',
      $site_name,
      self::form_label($data['form_type'] ?? ''),
      $reg_id,
      $data['first_name'] ?? '',
      $data['last_name'] ?? ''
    );

    $body = self::admin_email_body($data, $reg_id);

    wp_mail($admin_email, $subject, $body, self::get_headers());
  }

  /**
   * Confirmation email for the registrant / guardian.
   */
  public static function send_student_confirmation(array $data): void
  {
    if (empty($data['email']) || !is_email($data['email']))
      return;

    $subject = (($data['form_type'] ?? '') === IQU_Database::FORM_FREE)
      ? 'Registration Confirmed — Ilm-ul-Quran USA'
      : 'Summer Program Enrollment Confirmed — Ilm-ul-Quran USA';

    $body = self::student_email_body($data);

    wp_mail($data['email'], $subject, $body, self::get_headers());
  }

  // ────────────────────────────────────────────────────

  private static function get_headers(): array
  {
    return [
      'Content-Type: text/html; charset=UTF-8',
      'From: Ilm-ul-Quran USA <noreply@ilmulquranusa.org>',
    ];
  }

  public static function form_label(string $form_type): string
  {
    switch ($form_type) {
      case IQU_Database::FORM_SUMMER_LEVEL1:
        return 'Summer Program — Level 1';
      case IQU_Database::FORM_SUMMER_LEVEL2:
        return 'Summer Program — Level 2';
      case IQU_Database::FORM_FREE:
      default:
        return 'Free Enrollment';
    }
  }

  private static function admin_email_body(array $data, int $id): string
  {
    $admin_url = admin_url('admin.php?page=iqu-view-registration&id=' . $id);
    $form_type = $data['form_type'] ?? '';

    $rows = [];
    $rows[] = ['Form', self::form_label($form_type)];
    $rows[] = ['Participant', ($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')];
    $rows[] = ['Email', $data['email'] ?? ''];

    if ($form_type === IQU_Database::FORM_FREE) {
      $rows[] = ['Age', (int) ($data['age'] ?? 0)];
      $rows[] = ['Country of Origin', $data['country_origin'] ?? ''];
      $rows[] = ['Country of Residence', $data['country_res'] ?? ''];
      $rows[] = ['Qur\'an Level', ucfirst($data['quran_level'] ?? '')];
      $rows[] = ['Days / Week', $data['days_per_week'] ?? ''];
      $rows[] = ['Preferred Days', $data['preferred_days'] ?? ''];
      $rows[] = ['Time Slot', $data['time_slot'] ?? ''];
      $rows[] = ['Session Duration', $data['session_dur'] ?? ''];
      $rows[] = ['Languages', $data['languages'] ?? ''];
      $rows[] = ['WhatsApp', $data['whatsapp'] ?? ''];
      $rows[] = ['Memorized', $data['memorized'] ?? ''];
      $rows[] = ['Teacher Preference', $data['teacher_pref'] ?? ''];
      $rows[] = ['Device', $data['device'] ?? ''];
      $rows[] = ['Referral', $data['referral'] ?? ''];
      $rows[] = ['Monthly Fee Pref.', self::format_free_fee($data['fee_pref'] ?? '')];
      if (!empty($data['free_request_reason'])) {
        $rows[] = ['Free Enrollment Note', $data['free_request_reason']];
      }
    } else {
      $rows[] = ['Age', (int) ($data['age'] ?? 0)];
      $rows[] = ['Enrollment Level', strtoupper($data['enrollment_level'] ?? '')];
      $rows[] = ['Country of Origin', $data['country_origin'] ?? ''];
      $rows[] = ['Country of Residence', $data['country_res'] ?? ''];
      $rows[] = ['Guardian', $data['guardian_name'] ?? ''];
      $rows[] = ['Guardian Contact', $data['guardian_contact'] ?? ''];
      $rows[] = ['Guardian WhatsApp', $data['guardian_whatsapp'] ?? ''];
      $rows[] = ['Join WhatsApp Group', ucfirst($data['whatsapp_group'] ?? '')];
      $rows[] = ['Referral', $data['referral'] ?? ''];

      $fee_display = self::format_admission_fee($data['admission_fee'] ?? '');
      $rows[] = ['Admission Fee', $fee_display];

      if (!empty($data['flexible_fee_note'])) {
        $rows[] = ['Flexible/Free Note', $data['flexible_fee_note']];
      }

      if (!empty($data['payment_method'])) {
        $rows[] = ['Payment Method', strtoupper($data['payment_method'])];
      }
      if (isset($data['payment_amount']) && (float) $data['payment_amount'] > 0) {
        $rows[] = ['Payment Amount', '$' . number_format((float) $data['payment_amount'], 2)];
      }
      if (!empty($data['transaction_id'])) {
        $rows[] = ['Transaction ID', $data['transaction_id']];
      }
      if (!empty($data['payment_status'])) {
        $rows[] = ['Payment Status', ucfirst($data['payment_status'])];
      }
    }

    // Submitted at
    $rows[] = ['Submitted', date('M j, Y · g:i A (T)')];

    ob_start();
?>
<div
    style="font-family:'Segoe UI',Arial,sans-serif;max-width:640px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e1e8ef;box-shadow:0 2px 12px rgba(26,82,118,.08)">
    <?php echo self::render_email_logo(); ?>

    <!-- Header -->
    <div style="background:linear-gradient(135deg,#1a5276 0%,#2874a6 100%);padding:28px 24px;text-align:center">
        <div
            style="display:inline-block;background:rgba(255,255,255,.15);color:#fff;padding:4px 14px;border-radius:20px;font-size:11px;font-weight:700;letter-spacing:.5px;margin-bottom:12px">
            NEW REGISTRATION
        </div>
        <h1 style="color:#fff;margin:0;font-size:24px;font-weight:700;line-height:1.3">Registration
            #<?php echo (int) $id; ?></h1>
        <p style="color:#f0b429;margin:8px 0 0;font-size:14px;font-weight:600;letter-spacing:.3px">
            <?php echo esc_html(self::form_label($form_type)); ?>
        </p>
    </div>

    <!-- Quick Summary Bar -->
    <div style="background:#f0f7fa;padding:14px 24px;border-bottom:1px solid #e1e8ef;text-align:center">
        <span
            style="color:#1a5276;font-size:15px;font-weight:700"><?php echo esc_html(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')); ?></span>
        <span style="color:#888;margin:0 8px">·</span>
        <span style="color:#555;font-size:13px"><?php echo esc_html($data['email'] ?? ''); ?></span>
        <?php if (!empty($data['whatsapp']) || !empty($data['guardian_whatsapp'])): ?>
        <span style="color:#888;margin:0 8px">·</span>
        <span
            style="color:#555;font-size:13px"><?php echo esc_html($data['whatsapp'] ?? $data['guardian_whatsapp'] ?? ''); ?></span>
        <?php endif; ?>
    </div>

    <!-- Data Table -->
    <div style="padding:0">
        <table style="width:100%;border-collapse:collapse" cellpadding="0" cellspacing="0">
            <?php foreach ($rows as $index => [$label, $value]):
            $bgColor = ($index % 2 === 0) ? '#ffffff' : '#f9fbfd';
          ?>
            <tr>
                <th
                    style="padding:11px 20px;border-bottom:1px solid #eef2f7;font-weight:600;color:#1a5276;text-align:left;width:38%;background:<?php echo $bgColor; ?>;font-size:13px;vertical-align:top">
                    <?php echo esc_html($label); ?>
                </th>
                <td
                    style="padding:11px 20px;border-bottom:1px solid #eef2f7;color:#333;font-size:13px;background:<?php echo $bgColor; ?>;vertical-align:top">
                    <?php echo esc_html((string) $value); ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <!-- Action Button -->
    <div style="padding:24px;text-align:center;background:#f9fbfd">
        <a href="<?php echo esc_url($admin_url); ?>"
            style="background:linear-gradient(135deg,#1a5276,#2874a6);color:#fff;padding:12px 32px;text-decoration:none;border-radius:8px;display:inline-block;font-weight:600;font-size:14px;letter-spacing:.3px">
            View Full Details →
        </a>
    </div>

    <!-- Footer -->
    <div style="background:#1a5276;padding:16px 24px;text-align:center">
        <p style="color:rgba(255,255,255,.7);margin:0;font-size:12px;letter-spacing:.3px">
            © <?php echo (int) date('Y'); ?> Ilm-ul-Quran USA · Subsidized by Al-Hasanah Foundation (501(c)(3))
        </p>
    </div>
</div>
<?php
    return ob_get_clean();
  }


  // OLD: Find the entire student_email_body method and replace with this:

  private static function student_email_body(array $data): string
  {
    $form_type = $data['form_type'] ?? '';
    $is_summer = in_array($form_type, [IQU_Database::FORM_SUMMER_LEVEL1, IQU_Database::FORM_SUMMER_LEVEL2], true);
    $salutation = $is_summer ? ($data['guardian_name'] ?? $data['first_name'] ?? '') : ($data['first_name'] ?? '');
    $contact_wa = $is_summer ? ($data['guardian_whatsapp'] ?? '') : ($data['whatsapp'] ?? '');

    // Build clickable WhatsApp link
    $wa_digits = preg_replace('/[^\d]/', '', $contact_wa);
    $wa_link = $wa_digits ? 'https://wa.me/' . $wa_digits : '';

    // Summary rows
    $summary = [];
    if ($is_summer) {
      $summary[] = ['Participant', ($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')];
      $summary[] = ['Enrollment Level', strtoupper($data['enrollment_level'] ?? '')];
      $summary[] = ['Age', (int) ($data['age'] ?? 0)];
      $summary[] = ['Country', $data['country_res'] ?? ''];
      $summary[] = ['Guardian', $data['guardian_name'] ?? ''];
      $fee = self::format_admission_fee($data['admission_fee'] ?? '');
      if ($fee)
        $summary[] = ['Admission Fee', $fee];
      if (!empty($data['flexible_fee_note'])) {
        $summary[] = ['Flexible/Free Note', $data['flexible_fee_note']];
      }
      if (!empty($data['payment_method'])) {
        $summary[] = ['Payment Method', strtoupper($data['payment_method'])];
      }
      if (isset($data['payment_amount']) && (float) $data['payment_amount'] > 0) {
        $summary[] = ['Amount Paid', '$' . number_format((float) $data['payment_amount'], 2)];
      }
      if (!empty($data['transaction_id'])) {
        $summary[] = ['Transaction ID', $data['transaction_id']];
      }
    } else {
      $summary[] = ['Name', ($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')];
      $summary[] = ['Qur\'an Level', ucfirst($data['quran_level'] ?? '')];
      $summary[] = ['Preferred Days', $data['preferred_days'] ?? ''];
      $summary[] = ['Time Slot', $data['time_slot'] ?? ''];
      $summary[] = ['Session Duration', ($data['session_dur'] ?? '') . ' minutes'];
      $summary[] = ['Teacher Preference', ucfirst($data['teacher_pref'] ?? '')];
      $summary[] = ['Monthly Fee', self::format_free_fee($data['fee_pref'] ?? '')];
      if (!empty($data['free_request_reason'])) {
        $summary[] = ['Free Enrollment Note', $data['free_request_reason']];
      }
    }

    $title = $is_summer
      ? 'Your Summer Program Enrollment Is Confirmed'
      : 'Your Free Enrollment Has Been Received';

    ob_start();
  ?>
<div
    style="font-family:'Segoe UI',Arial,sans-serif;max-width:620px;margin:0 auto;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 4px 22px rgba(26,82,118,.08)">

    <!-- Hero -->
    <div style="text-align:center">
        <div style="background:#f0f7fa;padding:24px 24px 16px;border-bottom:3px solid #1a5276">
            <img src="<?php echo esc_attr(IQU_EMAIL_LOGO_SRC); ?>" alt="Ilm-ul-Quran USA logo"
                style="max-width:180px;width:100%;height:auto;display:inline-block;border:0">
        </div>
        <div style="background:linear-gradient(135deg,#1a5276 0%,#2874a6 100%);padding:16px 24px">
            <p style="color:rgba(255,255,255,.7);margin:0;font-size:11px;letter-spacing:.5px;font-weight:700">
                LEARN QURAN WITH LOVE &amp; PATIENCE
            </p>
        </div>
    </div>

    <!-- Greeting -->
    <div style="padding:30px 30px 10px;text-align:center">
        <div
            style="display:inline-block;background:#eaf6ec;color:#1e8449;padding:6px 16px;border-radius:20px;font-size:12px;font-weight:700;letter-spacing:.5px">
            ✓ REGISTRATION CONFIRMED
        </div>
        <h2 style="color:#1a5276;margin:18px 0 6px;font-size:22px;font-weight:700">
            Assalamu Alaikum, <?php echo esc_html($salutation); ?>
        </h2>
        <p style="color:#333;margin:0;font-size:15px;line-height:1.6">
            <strong>Jazakallahu Khairan</strong> — <?php echo esc_html($title); ?>.
        </p>
    </div>

    <!-- Summary Card -->
    <div style="padding:22px 30px 8px">
        <div
            style="background:#f7fafc;border:1px solid #e1e8ef;border-left:4px solid #f0b429;border-radius:10px;padding:20px 22px">
            <h3
                style="margin:0 0 14px;color:#1a5276;font-size:14px;font-weight:700;letter-spacing:.5px;text-transform:uppercase">
                Registration Summary
            </h3>
            <table style="width:100%;border-collapse:collapse" cellpadding="0" cellspacing="0">
                <?php foreach ($summary as [$k, $v]): ?>
                <tr>
                    <td style="padding:7px 0;color:#1a5276;font-size:13px;font-weight:600;width:42%;vertical-align:top">
                        <?php echo esc_html($k); ?>
                    </td>
                    <td style="padding:7px 0;color:#333;font-size:13px;vertical-align:top">
                        <?php echo esc_html((string) $v); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

    <!-- What's Next -->
    <div style="padding:20px 30px 8px">
        <h3
            style="color:#1a5276;font-size:14px;margin:0 0 12px;text-transform:uppercase;letter-spacing:.5px;font-weight:700">
            What Happens Next?
        </h3>

        <!-- Step 1 -->
        <div style="display:table;width:100%;margin-bottom:10px">
            <div style="display:table-cell;width:32px;vertical-align:top;padding-top:2px">
                <div
                    style="width:26px;height:26px;background:#eaf6ec;color:#1e8449;border-radius:50%;text-align:center;line-height:26px;font-size:13px;font-weight:700">
                    1</div>
            </div>
            <div style="display:table-cell;vertical-align:top;padding-left:10px">
                <p style="margin:0;color:#333;font-size:14px;line-height:1.6">
                    Our team will reach out via WhatsApp
                    <?php if ($contact_wa): ?>
                    <?php if ($wa_link): ?>
                    (<a href="<?php echo esc_url($wa_link); ?>"
                        style="color:#1a5276;font-weight:600;text-decoration:none"><?php echo esc_html($contact_wa); ?></a>)
                    <?php else: ?>
                    (<strong><?php echo esc_html($contact_wa); ?></strong>)
                    <?php endif; ?>
                    <?php endif; ?>
                    within <strong>24–48 hours</strong>, in-sha'-Allah.
                </p>
            </div>
        </div>

        <!-- Step 2 -->
        <div style="display:table;width:100%;margin-bottom:10px">
            <div style="display:table-cell;width:32px;vertical-align:top;padding-top:2px">
                <div
                    style="width:26px;height:26px;background:#e8f0fe;color:#1a5276;border-radius:50%;text-align:center;line-height:26px;font-size:13px;font-weight:700">
                    2</div>
            </div>
            <div style="display:table-cell;vertical-align:top;padding-left:10px">
                <p style="margin:0;color:#333;font-size:14px;line-height:1.6">
                    You will receive your <strong>class schedule</strong>, <strong>Zoom link</strong>, and
                    <strong>teacher
                        details</strong>.
                </p>
            </div>
        </div>

        <!-- Step 3 -->
        <div style="display:table;width:100%;margin-bottom:4px">
            <div style="display:table-cell;width:32px;vertical-align:top;padding-top:2px">
                <div
                    style="width:26px;height:26px;background:#fff8e1;color:#a07a00;border-radius:50%;text-align:center;line-height:26px;font-size:13px;font-weight:700">
                    3</div>
            </div>
            <div style="display:table-cell;vertical-align:top;padding-left:10px">
                <p style="margin:0;color:#333;font-size:14px;line-height:1.6">
                    If you have any questions, simply <strong>reply to this email</strong> or contact us below.
                </p>
            </div>
        </div>
    </div>

    <?php if ($is_summer): ?>
    <!-- Summer Program Quick Info -->
    <div
        style="margin:16px 30px;padding:16px 20px;background:#e8f4fd;border-radius:10px;border:1px solid #c8dff0;display:flex;align-items:center;justify-content:center">
        <p style="margin:0;color:#1a5276;font-size:13px;line-height:1.6;text-align:center">
            <strong>📅 Program:</strong> June 1 – July 31, 2026 &nbsp;·&nbsp;
            <strong>🕙 Time:</strong> Mon–Thu, 10 AM – 12 PM CDT <br />
            <strong>💻 Platform:</strong> Live Zoom Sessions
        </p>
    </div>
    <?php endif; ?>

    <!-- Hadith -->
    <div
        style="margin:18px 30px;padding:18px 22px;background:#fff8e1;border-radius:10px;border:1px dashed #e2b422;text-align:center">
        <p style="margin:0;color:#7a5a00;font-style:italic;font-size:14px;line-height:1.6">
            "The best among you are those who learn the Qur'an and teach it."
        </p>
        <p style="margin:6px 0 0;font-size:12px;color:#a07a00;letter-spacing:.5px">— Saḥīḥ al-Bukharī</p>
    </div>

    <!-- Contact -->
    <div style="padding:16px 30px 24px;text-align:center;font-size:13px;color:#555;line-height:1.7">
        <strong style="color:#1a5276">Need help?</strong><br>
        📞 <?php echo esc_html(IQU_CONTACT_PHONE); ?> (Text Only) &nbsp;·&nbsp;
        ✉ <a href="mailto:<?php echo esc_attr(IQU_CONTACT_EMAIL); ?>"
            style="color:#1a5276;text-decoration:none;font-weight:600"><?php echo esc_html(IQU_CONTACT_EMAIL); ?></a>
    </div>

    <!-- Footer -->
    <div style="background:#1a5276;padding:16px 24px;text-align:center">
        <p style="color:rgba(255,255,255,.7);margin:0;font-size:11px;letter-spacing:.3px; font-weight:700">
            © <?php echo (int) date('Y'); ?> Ilm-ul-Quran USA · Subsidized by Al-Hasanah Foundation (501(c)(3))
        </p>
    </div>
</div>
<?php
    return ob_get_clean();
  }

  public static function format_admission_fee(string $fee): string
  {
    switch ($fee) {
      case '50':
        return '$50 — Standard Enrollment Fee';
      case '30':
        return '$30 — Supported Rate';
      case 'flexible':
        return 'Flexible / Free Enrollment';
      case 'complimentary':
        return 'Complimentary — Existing Student';
      default:
        return '';
    }
  }
  public static function format_free_fee(string $fee): string
  {
    if (strpos($fee, 'custom_') === 0) {
      return '$' . substr($fee, 7) . '/month (Custom)';
    }

    $map = [
      'arabic_50' => 'Arabic Language $50/month',
      'qaidah_50' => "QÄ'idah / Nazirah Students $50/month",
      'qaidah_60' => "QÄ'idah / Nazirah Students $60/month",
      'qaidah_70' => "QÄ'idah / Nazirah Students $70/month",
      'qaidah_80' => "QÄ'idah / Nazirah Students $80/month",
      'hifz_60' => 'Hifz Students $60/month',
      'hifz_70' => 'Hifz Students $70/month',
      'hifz_80' => 'Hifz Students $80/month',
      'hifz_90' => 'Hifz Students $90/month',
      'hifz_100' => 'Hifz Students $100/month',
      'free' => 'Free Enrollment Requested',
      'other' => 'Other',
    ];

    return $map[$fee] ?? $fee;
  }

  private static function render_email_logo(): string
  {
    $src = trim((string) apply_filters('iqu_email_logo_src', IQU_EMAIL_LOGO_SRC));
    if ($src === '') {
      return '';
    }

    $is_data_uri = strpos($src, 'data:image/') === 0;
    $is_url = (bool) preg_match('#^https?://#i', $src);
    if (!$is_data_uri && !$is_url) {
      return '';
    }

    ob_start();
  ?>
<div style="padding:24px 24px 16px;text-align:center;background:#f0f7fa;border-bottom:3px solid #1a5276">
    <img src="<?php echo esc_attr($src); ?>" alt="Ilm-ul-Quran USA logo"
        style="max-width:180px;width:100%;height:auto;display:inline-block;border:0;outline:none;text-decoration:none">
</div>
<?php
    return ob_get_clean();
  }
}