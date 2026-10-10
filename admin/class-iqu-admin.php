<?php
if (!defined('ABSPATH'))
  exit;

/**
 * Class IQU_Admin
 * Dashboard + list pages for three form types.
 * Includes Chart.js charts for weekly registrations and revenue.
 */
class IQU_Admin
{
  private const ALLOWED_STATUSES = IQU_Database::STATUSES;
  private const ALLOWED_BACK_PAGES = [
    'iqu-registrations',
    'iqu-list-free',
    'iqu-list-summer-l1',
    'iqu-list-summer-l2',
    'iqu-list-weekend',
  ];

  private static function referral_chip_html(string $referral): string
  {
    if ($referral === '') {
      return '<span style="color:#9ca3af;font-size:11px">—</span>';
    }

    $config = [
      'facebook'      => ['Facebook',        '#1877F2', '#e8f0fe', '#bdd3fb'],
      'whatsapp'      => ['WhatsApp',        '#075E54', '#e6f7f1', '#b2dfdb'],
      'linkedin'      => ['LinkedIn',        '#0A66C2', '#e8f3fc', '#a8d0f5'],
      'youtube'       => ['YouTube',         '#CC0000', '#fee2e2', '#fca5a5'],
      'website'       => ['Website',         '#6366f1', '#ede9fe', '#c4b5fd'],
      'friend_family' => ['Friend / Family', '#92400e', '#fef3e0', '#fcd59a'],
      'email'        => ['Email',          '#065f46', '#d1fae5', '#6ee7b7'],
    ];

    if (!isset($config[$referral])) {
      $label = ucfirst(str_replace('_', ' ', $referral));
      return '<span class="iqu-ref-chip" style="color:#6b7280;background:#f3f4f6;border-color:#e5e7eb">'
        . esc_html($label) . '</span>';
    }

    [$label, $color, $bg, $border] = $config[$referral];

    return sprintf(
      '<span class="iqu-ref-chip" style="color:%s;background:%s;border-color:%s">%s</span>',
      esc_attr($color),
      esc_attr($bg),
      esc_attr($border),
      esc_html($label)
    );
  }

  public function __construct()
  {
    add_action('admin_menu', [$this, 'register_menu']);
    add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    add_action('wp_ajax_iqu_update_status', [$this, 'handle_update_status']);
    add_action('wp_ajax_iqu_export_csv', [$this, 'handle_export_csv']);
    add_action('wp_ajax_iqu_delete_reg', [$this, 'handle_delete']);
  }

  public function register_menu(): void
  {
    add_menu_page(
      'IQU Registrations',
      'IQU Registrations',
      'manage_options',
      'iqu-registrations',
      [$this, 'render_dashboard'],
      'dashicons-groups',
      30
    );
    add_submenu_page('iqu-registrations', 'Dashboard', 'Dashboard', 'manage_options', 'iqu-registrations', [$this, 'render_dashboard']);
    add_submenu_page('iqu-registrations', 'Ilm-ul-Quran USA — Enroll for Free Registration', 'Enroll for Free', 'manage_options', 'iqu-list-free', function () {
      $this->render_list_page(IQU_Database::FORM_FREE, 'Ilm-ul-Quran USA — Enroll for Free Registration');
    });
    add_submenu_page('iqu-registrations', 'Summer Program — Level 1', 'Summer Program L1', 'manage_options', 'iqu-list-summer-l1', function () {
      $this->render_list_page(IQU_Database::FORM_SUMMER_LEVEL1, 'Summer Program — Level 1 (Ages 5–10)');
    });
    add_submenu_page('iqu-registrations', 'Summer Program — Level 2', 'Summer Program L2', 'manage_options', 'iqu-list-summer-l2', function () {
      $this->render_list_page(IQU_Database::FORM_SUMMER_LEVEL2, 'Summer Program — Level 2 (Ages 11–15)');
    }); 
    add_submenu_page('iqu-registrations', 'Weekend Ilm Program', 'Weekend Program', 'manage_options', 'iqu-list-weekend', function () {
      $this->render_list_page(IQU_Database::weekend_types(), 'Weekend Ilm Program (Ages 5–12)');
    });
    add_submenu_page('iqu-registrations', 'Registration Details — IQU', 'View Registration', 'manage_options', 'iqu-view-registration', [$this, 'render_view_page']);
    add_action('admin_head', function () {
      remove_submenu_page('iqu-registrations', 'iqu-view-registration');
    });
  }

  public function enqueue_assets(string $hook): void
  {
    if (strpos($hook, 'iqu') === false)
      return;

    wp_enqueue_style('iqu-admin-style', IQU_PLUGIN_URL . 'admin/css/admin.css', [], IQU_VERSION);

    // Chart.js from CDN
    wp_enqueue_script('chartjs', 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js', [], '4.4.1', true);

    wp_enqueue_script('iqu-admin-script', IQU_PLUGIN_URL . 'admin/js/admin.js', ['jquery', 'chartjs'], IQU_VERSION, true);

    // Build chart data from DB for dashboard
    $chart_data = $this->build_chart_data();

    wp_localize_script('iqu-admin-script', 'IQU_Admin', [
      'ajax_url' => admin_url('admin-ajax.php'),
      'nonce_status' => wp_create_nonce('iqu_update_status'),
      'nonce_delete' => wp_create_nonce('iqu_delete_reg'),
      'confirm_delete' => 'Are you sure you want to delete this registration? This cannot be undone.',
      'chart_data' => $chart_data,
    ]);
  }

  // ════════════════════════════════════════════════════
  // CHART DATA BUILDER
  // ════════════════════════════════════════════════════

  /**
   * Build weekly registrations (last 6 weeks) and revenue by form type.
   */
  private function build_chart_data(): array
  {
    global $wpdb;
    $table = $wpdb->prefix . IQU_TABLE_NAME;

    // ── Weekly registrations (last 6 weeks) ──────────────
    $weeks = [];
    $week_labels = [];
    $week_counts = [];

    for ($i = 5; $i >= 0; $i--) {
      $start = date('Y-m-d 00:00:00', strtotime('-' . $i . ' weeks monday this week'));
      $end = date('Y-m-d 23:59:59', strtotime('-' . $i . ' weeks sunday this week'));
      $label = 'W' . date('W', strtotime($start));

      $count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM `{$table}` WHERE created_at BETWEEN %s AND %s",
        $start,
        $end
      ));

      $week_labels[] = $label;
      $week_counts[] = $count;
    }

    // ── Revenue by form type (summer forms only) ─────────
    $revenue_free = (float) $wpdb->get_var($wpdb->prepare(
      "SELECT COALESCE(SUM(payment_amount),0) FROM `{$table}` WHERE form_type = %s AND payment_amount > 0",
      IQU_Database::FORM_FREE
    ));
    $revenue_l1 = (float) $wpdb->get_var($wpdb->prepare(
      "SELECT COALESCE(SUM(payment_amount),0) FROM `{$table}` WHERE form_type = %s AND payment_amount > 0",
      IQU_Database::FORM_SUMMER_LEVEL1
    ));
    $revenue_l2 = (float) $wpdb->get_var($wpdb->prepare(
      "SELECT COALESCE(SUM(payment_amount),0) FROM `{$table}` WHERE form_type = %s AND payment_amount > 0",
      IQU_Database::FORM_SUMMER_LEVEL2
    ));

    // ── Total revenue ─────────────────────────────────────
    $total_revenue = $revenue_l1 + $revenue_l2;

    // ── Referral breakdown ────────────────────────────────────
    $referral_rows = $wpdb->get_results(
      "SELECT 
        COALESCE(NULLIF(TRIM(referral), ''), 'unknown') AS ref_key,
        COUNT(*) AS cnt
     FROM `{$table}`
     GROUP BY ref_key
     ORDER BY cnt DESC
     LIMIT 8",
      ARRAY_A
    );
    $ref_labels = [];
    $ref_data   = [];
    $map = [
      'facebook'      => 'Facebook',
      'whatsapp'      => 'WhatsApp',
      'linkedin'      => 'LinkedIn',
      'youtube'       => 'YouTube',
      'website'       => 'Website',
      'friend_family' => 'Friend / Family',
      'email'        => 'Email',
    ];
    foreach ($referral_rows as $rr) {
      $ref_labels[] = $map[$rr['ref_key']] ?? ucfirst(str_replace('_', ' ', $rr['ref_key']));
      $ref_data[]   = (int) $rr['cnt'];
    }

    // ── 📊 Enrollment breakdown (ভার্টিকেল বার চার্ট) ──────
    $breakdown = [
      'labels' => ['Free', 'Summer L1', 'Summer L2', 'Weekend · Existing', 'Weekend · New'],
      'data'   => [
        IQU_Database::count_registrations(['form_type' => IQU_Database::FORM_FREE]),
        IQU_Database::count_registrations(['form_type' => IQU_Database::FORM_SUMMER_LEVEL1]),
        IQU_Database::count_registrations(['form_type' => IQU_Database::FORM_SUMMER_LEVEL2]),
        IQU_Database::count_registrations(['form_type' => IQU_Database::FORM_WEEKEND_EXISTING]),
        IQU_Database::count_registrations(['form_type' => IQU_Database::FORM_WEEKEND_NEW]),
      ],
      'colors' => ['#1e5fa0', '#1a8a45', '#c98a00', '#0e9490', '#f7941d'],
    ];

    return [
      'weekly'    => ['labels' => $week_labels, 'data' => $week_counts],
      'revenue'   => ['labels' => ['Free Enrollment', 'Summer L1', 'Summer L2'], 'data' => [$revenue_free, $revenue_l1, $revenue_l2], 'total' => $total_revenue],
      'referral'  => ['labels' => $ref_labels, 'data' => $ref_data],
      'breakdown' => $breakdown,
    ];
  }

  // ════════════════════════════════════════════════════
  // SHARED HELPERS
  // ════════════════════════════════════════════════════

  /** Tabs of the shared top bar (dashboard, lists, coupons, waitlist). */
  public const TOP_TABS = [
    'iqu-registrations'   => 'All',
    'iqu-list-free'       => 'Free',
    'iqu-list-summer-l1'  => 'Level 1',
    'iqu-list-summer-l2'  => 'Level 2',
    'iqu-list-weekend'    => 'Weekend Program',
    'iqu-coupon-create'   => 'Create Coupon',
    'iqu-coupon-list'     => 'Coupon List',
    'iqu-billing'         => 'Billing',
    'iqu-waitlist'        => 'Waitlist',
    'iqu-zeffy-payments'  => 'Zeffy Payments',
  ];

  private static function render_logo(): void
  {
?>
<img src="https://ilmulquranus.org/wp-content/uploads/2025/08/Favicon.png" alt="IQU Logo" width="44" height="39"
    style="display:block" />
<?php
  }

  private function render_top_bar(string $title, string $subtitle, string $active_page = ''): void
  {
    self::top_bar($title, $subtitle, $active_page);
  }

  /** The IQU top bar with the tab row — shared by every IQU admin page that shows it. */
  public static function top_bar(string $title, string $subtitle, string $active_page = ''): void
  {
    $tabs = self::TOP_TABS;
  ?>
<div class="iqu-top-bar">
    <div class="iqu-top-bar-left">
        <div class="iqu-logo-mark"><?php self::render_logo(); ?></div>
        <div>
            <div class="iqu-page-title"><?php echo esc_html($title); ?></div>
            <div class="iqu-page-sub"><?php echo esc_html($subtitle); ?></div>
        </div>
    </div>
    <div class="iqu-top-bar-right">
        <span class="iqu-badge-live">● Live</span>
        <div class="iqu-tabs">
            <?php foreach ($tabs as $slug => $label):
            $url = admin_url('admin.php?page=' . $slug); ?>
            <a href="<?php echo esc_url($url); ?>"
                class="iqu-tab <?php echo ($active_page === $slug) ? 'iqu-tab-active' : ''; ?>">
                <?php echo esc_html($label); ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php
  }

  /**
   * "Monthly payments" on a registration's detail page (Enroll for Free = monthly students only):
   * one row per invoice of the student's billing family, newest first, from the current month back,
   * plus "Upcoming — <date>" when this month is not billed yet. Local database only (payments
   * history in the current mode); no Stripe call. Summer and Weekend registrations get nothing.
   */
  private static function monthly_payments_html(array $row): string
  {
    if (($row['form_type'] ?? '') !== IQU_Database::FORM_FREE || !class_exists('IQU_Billing_DB') || !class_exists('IQU_Billing_History')) return '';

    $acc = IQU_Billing_DB::account_for_registration((int) $row['id']);
    if (!$acc && !empty($row['billing_account_id'])) $acc = IQU_Billing_DB::get_account((int) $row['billing_account_id']);

    $out = '<div class="iqu-section-title">Monthly payments</div>';
    if (!$acc) {
      return $out . '<div class="iqu-detail-data iqu-mp"><div class="iqu-detail-item iqu-detail-full"><div class="iqu-detail-val iqu-mp-none">Not on monthly billing yet.</div></div></div>';
    }

    $tz   = wp_timezone();
    $now  = new DateTimeImmutable('now', $tz);
    $cur  = $now->format('Y-m');
    $rows = array_values(array_filter(IQU_Billing_History::for_account((int) $acc['id']),
      fn($r) => IQU_Billing_History::is_month((string) $r['period_month']) && (string) $r['period_month'] <= $cur));

    $lines = [];
    // This month not billed yet, but the next charge falls in it → "Upcoming — 5 Oct".
    $billed_now = (bool) array_filter($rows, fn($r) => $r['period_month'] === $cur);
    if (!$billed_now && !empty($acc['next_charge_at']) && $acc['status'] !== 'canceled') {
      $next = (new DateTimeImmutable($acc['next_charge_at'], new DateTimeZone('UTC')))->setTimezone($tz);
      if ($next->format('Y-m') === $cur) {
        $lines[] = [$cur, (float) $acc['net_amount'], 'Upcoming — ' . $next->format('j M'), 'blue'];
      }
    }
    foreach ($rows as $r) {
      $due = (float) $r['amount_due'];
      $refunded = (float) $r['amount_refunded'];
      if ($due <= 0) {
        [$label, $tone] = ['Free month', 'neutral'];
      } elseif ($r['status'] === 'paid') {
        [$label, $tone] = $refunded > 0 ? ['Paid (refunded ' . IQU_Pricing::format($refunded) . ')', 'green'] : ['Paid', 'green'];
      } else {
        [$label, $tone] = [
          'refunded'      => ['Refunded', 'neutral'],
          'failed'        => ['Failed', 'red'],
          'open'          => ['Due', 'gold'],
          'uncollectible' => ['Unpaid', 'red'],
          'void'          => ['Cancelled', 'neutral'],
        ][$r['status']] ?? [ucfirst((string) $r['status']), 'neutral'];
      }
      $lines[] = [(string) $r['period_month'], $due, $label, $tone];
    }

    $out .= '<div class="iqu-mp"><table class="iqu-tbl iqu-mp-tbl"><caption class="screen-reader-text">Monthly payments, newest first</caption>'
      . '<thead><tr><th scope="col">Month</th><th scope="col" class="is-num">Amount</th><th scope="col">Status</th></tr></thead><tbody>';
    if (!$lines) {
      $out .= '<tr><td colspan="3" class="iqu-mp-none">No payments yet.</td></tr>';
    }
    foreach ($lines as [$ym, $amount, $label, $tone]) {
      $out .= '<tr><td>' . esc_html(IQU_Billing_History::month_label($ym)) . '</td>'
        . '<td class="is-num">' . esc_html(IQU_Pricing::format($amount)) . '</td>'
        . '<td><span class="iqu-chip iqu-mp-chip iqu-mp-chip--' . esc_attr($tone) . '">' . esc_html($label) . '</span></td></tr>';
    }
    $out .= '</tbody></table>';

    // Brothers and sisters billed together.
    $names = [];
    foreach (IQU_Billing_DB::get_members((int) $acc['id']) as $m) {
      $reg = IQU_Database::get_registration((int) $m['registration_id']);
      if ($reg) $names[] = trim($reg['first_name'] . ' ' . $reg['last_name']);
    }
    if (count($names) > 1) {
      $out .= '<p class="iqu-mp-note">One monthly payment covers: ' . esc_html(implode(', ', $names)) . '</p>';
    }
    $family = class_exists('IQU_Billing_Page') ? IQU_Billing_Page::family_url((int) $acc['id']) : admin_url('admin.php?page=iqu-billing-message&account=' . (int) $acc['id']);
    $out .= '<p class="iqu-mp-link"><a href="' . esc_url($family) . '">Full payment details on the billing page →</a></p></div>';
    return $out;
  }

  private static function status_badge_html(string $status): string
  {
    $map = [
      'pending' => 'iqu-sb-pending',
      'confirmed' => 'iqu-sb-confirmed',
      'enrolled' => 'iqu-sb-enrolled',
      'cancelled' => 'iqu-sb-cancelled',
      'contacted' => 'iqu-sb-contacted',
      'card_pending' => 'iqu-sb-card_pending',
      'pending_review' => 'iqu-sb-pending_review',
      'expired' => 'iqu-sb-expired',
      'paid' => 'iqu-sb-paid',
      'stripe_pending' => 'iqu-sb-card_pending',
    ];
    $cls = $map[$status] ?? 'iqu-sb-pending';
    return '<span class="iqu-status-badge ' . esc_attr($cls) . '">' . esc_html(self::status_label($status)) . '</span>';
  }

  /** Label for a status. The original five keep their old labels ("Pending", …). */
  private static function status_label(string $status): string
  {
    $labels = [
      'card_pending' => 'Card pending',
      'pending_review' => 'Pending review',
      'expired' => 'Expired',
      'paid' => 'Paid',
      'stripe_pending' => 'Card pending',
      'needs_review' => 'Needs review',
    ];
    return $labels[$status] ?? ucfirst($status);
  }

  /** "Review: location" etc. for a registration with review reasons (3.3.0). */
  private static function review_badges_html(array $row): string
  {
    $reasons = array_filter(explode(',', (string) ($row['review_reason'] ?? '')));
    if (!$reasons) return '';
    $out = '';
    foreach ($reasons as $r) {
      $label = class_exists('IQU_Enrollment') ? IQU_Enrollment::reason_label($r) : $r;
      $out .= ' <span class="iqu-review-badge iqu-review-' . esc_attr(sanitize_key($r)) . '">Review: ' . esc_html(strtolower($label)) . '</span>';
    }
    return $out;
  }

  private static function type_chip_html(string $form_type): string
  {
    return '<span class="iqu-chip iqu-chip-' . esc_attr($form_type) . '">'
      . esc_html(IQU_Mailer::form_label($form_type))
      . '</span>';
  }

  /**
   * Render the "Fee Preference" / payment cell for a registration row.
   *
   * Every payment display rule lives here — free vs summer forms,
   * Zakat-eligible free places, complimentary seats, flexible amounts
   * typed as free text, and standard packages.
   */
  private static function payment_cell_html(array $row): string
  {
    $form_type     = $row['form_type'] ?? '';
    $admission_fee = $row['admission_fee'] ?? '';
    $fee_pref      = $row['fee_pref'] ?? '';
    $flexible_note = trim($row['flexible_fee_note'] ?? '');
    $pay_amount    = (float) ($row['payment_amount'] ?? 0);

    if ($form_type === 'free') {
      return self::free_payment_html($row);
    }

    if (in_array($form_type, ['summer_level1', 'summer_level2'], true)) {
      return self::summer_payment_html($row, $admission_fee, $flexible_note, $pay_amount);
    }

    if (in_array($form_type, IQU_Database::weekend_types(), true)) {
      return self::weekend_payment_html($row);
    }

    return '';
  }

  /**
   * Weekend Ilm Program-এর Payment কলাম।
   * 🎁 প্রোগ্রামটি সবার জন্য সম্পূর্ণ ফ্রি — দেখানোর মতো কোনো অঙ্ক নেই।
   */
  private static function weekend_payment_html(array $row): string
  {
    $is_new = ($row['form_type'] ?? '') === IQU_Database::FORM_WEEKEND_NEW;

    return '<span class="iqu-pay-cell">'
      . '<span class="iqu-no-pay-badge">Free</span>'
      . '<span class="iqu-amt">'
      . ($is_new ? 'New student' : 'Existing student')
      . '</span>'
      . '</span>';
  }

  /**
   * Payment cell for the free enrollment form.
   *
   * v2.8.0 থেকে দাম কোর্স + দিন থেকে হিসাব হয় এবং `payment_amount`
   * কলামে বসে। কিন্তু পুরনো রেকর্ডে সেই কলাম খালি আর `fee_pref`-এ
   * তথ্য আছে — তাই দুটো পথই এখানে সামলানো হয়।
   *
   * কোনো টাকা এখনো সংগ্রহ হয়নি, তাই সব অঙ্কে "Pending" ব্যাজ থাকে।
   */
  private static function free_payment_html(array $row): string
  {
    $pending  = '<span class="iqu-free-pending-badge">Pending</span>';
    $course   = $row['course_type'] ?? '';
    $fee_pref = $row['fee_pref'] ?? '';

    // ── নতুন সিস্টেম (v2.8.0+) ──
    if ($course !== '') {
      $amount   = (float) ($row['payment_amount'] ?? 0);
      $discount = (float) ($row['discount_amount'] ?? 0);
      $original = (float) ($row['calculated_amount'] ?? 0);

      // ছাড়ের উৎস — IQU_Form::handle_submit অনুযায়ী কুপন ও
      // স্পেশাল ডিসকাউন্ট কখনো একসাথে চলে না, তাই একটাই সত্য।
      // ⚠️ কুপন কোড ইচ্ছাকৃতভাবে দেখানো হয় না — লিস্ট পরিষ্কার
      //    রাখতে; কোড দরকার হলে View পেজে আছে।
      if (!empty($row['coupon_id'])) {
        $src_class = 'coupon';
        $src_label = 'Coupon applied';
      } elseif (!empty($row['special_discount'])) {
        $src_class = 'special';
        $src_label = 'Special discount';
      } else {
        $src_class = 'none';
        $src_label = 'No discount applied';
      }

      $source_html = '<span class="iqu-fee-source iqu-fee-source--'
        . $src_class . '">' . esc_html($src_label) . '</span>';

      // পূর্ণ স্কলারশিপ — টিউশন সম্পূর্ণ মওকুফ
      if ($amount <= 0 && $discount > 0) {
        return '<span class="iqu-pay-cell">'
          . '<span class="iqu-no-pay-badge">Scholarship</span>'
          . '<span class="iqu-amt">Full Zakat Scholarship</span>'
          . $source_html
          . '</span>';
      }

      $html = '<span class="iqu-pay-cell">' . $pending
        . '<span class="iqu-amt">$' . number_format($amount, 2) . '</span>';

      // আংশিক ছাড় হলে মূল দামটা কেটে দেখাও
      // ⚠️ <br> ব্যবহার করা হয় না — সেল যেন দুই লাইনে না ভাঙে
      if ($discount > 0 && $original > 0) {
        $percent = (int) round(($discount / $original) * 100);
        $html .= '<span class="iqu-fee-orig">'
          . '<s>$' . number_format($original, 2) . '</s> · '
          . $percent . '% off'
          . '</span>';
      }

      $html .= $source_html . '</span>';
      return $html;
    }

    // ── পুরনো রেকর্ড (v2.7.x এবং তার আগে) ──
    $flexible_note = trim($row['flexible_fee_note'] ?? '');

    // ⚠️ v2.8.0-এর আগের রেকর্ডে coupon/discount কলামই নেই,
    //    তাই ছাড়ের উৎস বলা সম্ভব নয় — "Legacy record" নোট বসে।
    $legacy_html = '<span class="iqu-fee-source iqu-fee-source--legacy">Legacy record</span>';

    if ($fee_pref === 'free') {
      return '<span class="iqu-pay-cell">'
        . '<span class="iqu-no-pay-badge">No Payment</span>'
        . '<span class="iqu-amt">Totally Free (Zakat)</span>'
        . $legacy_html
        . '</span>';
    }

    if ($fee_pref === 'other' && $flexible_note !== '') {
      $display = preg_match('/^\$?\d+(\.\d{1,2})?$/', $flexible_note)
        ? '$' . ltrim($flexible_note, '$')
        : $flexible_note;

      return '<span class="iqu-pay-cell">' . $pending
        . '<span class="iqu-amt">' . esc_html($display) . '</span>'
        . $legacy_html . '</span>';
    }

    if ($fee_pref !== '') {
      return '<span class="iqu-pay-cell">' . $pending
        . '<span class="iqu-amt">' . esc_html(self::fee_pref_label($fee_pref)) . '</span>'
        . $legacy_html . '</span>';
    }

    return '<span class="iqu-amt-free">—</span>';
  }

  /**
   * পুরনো fee_pref key-এর মানুষ-পাঠযোগ্য লেবেল।
   *
   * ⚠️ শুধুমাত্র v2.8.0-এর আগের রেকর্ডের জন্য। `custom_25` ধরনের
   *    key-ও এখানে পড়ে — সেগুলো "$25/mo (custom)" হিসেবে দেখানো হয়,
   *    কারণ পুরনো বাগের কারণে ওসব রেকর্ডে payment_amount শূন্য বসেছিল।
   */
  private static function fee_pref_label(string $fee_pref): string
  {
    $labels = [
      'arabic_50' => 'Arabic — $50/mo',
      'hifz_100'  => 'Hifz — $100/mo',
      'hifz_90'   => 'Hifz — $90/mo',
      'hifz_80'   => 'Hifz — $80/mo',
      'hifz_70'   => 'Hifz — $70/mo',
      'hifz_60'   => 'Hifz — $60/mo',
      'qaidah_80' => "Qa'idah — $80/mo",
      'qaidah_70' => "Qa'idah — $70/mo",
      'qaidah_60' => "Qa'idah — $60/mo",
      'qaidah_50' => "Qa'idah — $50/mo",
    ];

    if (isset($labels[$fee_pref])) {
      return $labels[$fee_pref];
    }

    // custom_25 → $25/mo (custom)
    if (strpos($fee_pref, 'custom_') === 0) {
      $amount = (float) substr($fee_pref, 7);
      if ($amount > 0) {
        return '$' . number_format($amount, 2) . '/mo (custom)';
      }
    }

    return $fee_pref;
  }

  /**
   * Payment cell for the summer program forms.
   */
  private static function summer_payment_html(
    array $row,
    string $admission_fee,
    string $flexible_note,
    float $pay_amount
  ): string {
    if ($admission_fee === 'complimentary') {
      return '<span class="iqu-no-pay-chip">'
        . '<span class="iqu-no-pay-badge">No Payment</span> Complimentary'
        . '</span>';
    }

    $asked_for_free = $flexible_note === ''
      || strtolower($flexible_note) === 'requesting free enrollment';

    if ($admission_fee === 'flexible' && $pay_amount <= 0 && $asked_for_free) {
      return '<span class="iqu-no-pay-chip">'
        . '<span class="iqu-no-pay-badge">No Payment</span> Totally Free'
        . '</span>';
    }

    $resolved = self::resolve_amount($admission_fee, $flexible_note, $pay_amount);
    $method   = strtolower(trim($row['payment_method'] ?? ''));

    $html = '<span class="iqu-pay-cell">';

    if ($method !== '') {
      $method_classes = ['zelle' => 'iqu-pay-zelle', 'zeffy' => 'iqu-pay-zeffy'];
      $method_class   = $method_classes[$method] ?? 'iqu-pay-other';

      $html .= '<span class="iqu-pay-method ' . esc_attr($method_class) . '">'
        . esc_html(ucfirst($method)) . '</span>'
        . '<span class="iqu-pay-sep">·</span>';
    }

    if ($resolved > 0) {
      $html .= '<span class="iqu-amt">$' . esc_html(number_format($resolved, 0)) . '</span>';

      $package = self::package_label($resolved);
      if ($package !== '') {
        $html .= ' <span class="iqu-pkg-label">(' . esc_html($package) . ')</span>';
      }
    } else {
      $html .= '<span class="iqu-amt-free">—</span>';
    }

    return $html . '</span>';
  }

  /**
   * Fall back to the flexible note when payment_amount is empty —
   * applicants sometimes type "$30" without a payment being recorded.
   */
  private static function resolve_amount(string $admission_fee, string $flexible_note, float $pay_amount): float
  {
    if ($pay_amount > 0) {
      return $pay_amount;
    }

    if ($admission_fee === 'flexible'
      && preg_match('/^\$?(\d+(\.\d{1,2})?)$/', $flexible_note, $m)
    ) {
      return (float) $m[1];
    }

    return 0.0;
  }

  /**
   * Package name for a known price point.
   */
  private static function package_label(float $amount): string
  {
    if ($amount <= 0) {
      return '';
    }

    $packages = [50 => 'Standard', 30 => 'Supported'];
    return $packages[(int) $amount] ?? 'Custom';
  }


  // ════════════════════════════════════════════════════
  // DASHBOARD
  // ════════════════════════════════════════════════════

  public function render_dashboard(): void
  {
    if (!current_user_can('manage_options'))
      wp_die('Permission denied.');

    $counts = [
      'total' => IQU_Database::count_registrations(),
      'free' => IQU_Database::count_registrations(['form_type' => IQU_Database::FORM_FREE]),
      'summer_l1' => IQU_Database::count_registrations(['form_type' => IQU_Database::FORM_SUMMER_LEVEL1]),
      'summer_l2' => IQU_Database::count_registrations(['form_type' => IQU_Database::FORM_SUMMER_LEVEL2]),
      'wk_existing' => IQU_Database::count_registrations(['form_type' => IQU_Database::FORM_WEEKEND_EXISTING]),
      'wk_new' => IQU_Database::count_registrations(['form_type' => IQU_Database::FORM_WEEKEND_NEW]),
      'weekend' => IQU_Database::count_registrations(['form_type' => IQU_Database::weekend_types()]),
      'pending' => IQU_Database::count_registrations(['status' => 'pending']),
      'confirmed' => IQU_Database::count_registrations(['status' => 'confirmed']),
      'enrolled' => IQU_Database::count_registrations(['status' => 'enrolled']),
      'contacted' => IQU_Database::count_registrations(['status' => 'contacted']),
      'cancelled' => IQU_Database::count_registrations(['status' => 'cancelled']),
      // 3.3.0 online enrollment: started (waiting for the card) vs completed (confirmed by Stripe)
      'card_pending' => IQU_Database::count_registrations(['status' => 'card_pending']),
      'stripe_done' => IQU_Database::count_registrations(['status' => 'enrolled', 'stripe_flow' => true]),
      'pending_review' => IQU_Database::count_registrations(['status' => 'pending_review']),
      'expired' => IQU_Database::count_registrations(['status' => 'expired']),
      'paid' => IQU_Database::count_registrations(['status' => 'paid']),
      'needs_review' => IQU_Database::count_registrations(['review' => true]),
    ];

    $allowed_per_page = [10, 20, 30];
    $raw_per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
    $dash_perpage = in_array($raw_per_page, $allowed_per_page) ? $raw_per_page : 10;
    $dash_perpage = max(1, $dash_perpage);
    $dash_page    = max(1, absint($_GET['paged'] ?? 1));
    $dash_total   = IQU_Database::count_registrations();
    $dash_pages   = (int)ceil($dash_total / $dash_perpage);

    $latest = IQU_Database::get_registrations([
      'per_page' => $dash_perpage,
      'page'     => $dash_page,
    ]);
    $total = max(1, (int) $counts['total']);

    // Revenue total for metric card
    global $wpdb;
    $table = $wpdb->prefix . IQU_TABLE_NAME;
    $total_revenue = (float) $wpdb->get_var(
      "SELECT COALESCE(SUM(payment_amount),0) FROM `{$table}` WHERE payment_amount > 0"
    );
  ?>
<div class="wrap iqu-admin-wrap">

    <?php $this->render_top_bar('IQU Registrations', 'Ilm-ul-Quran USA — Admin Panel', 'iqu-registrations'); ?>

    <!-- ── Metric Cards ─────────────────────────────── -->
    <div class="iqu-metrics">
        <div class="iqu-metric">
            <div class="iqu-metric-accent iqu-metric-accent--total"></div>
            <div class="iqu-metric-num"><?php echo (int) $counts['total']; ?></div>
            <div class="iqu-metric-lbl">Total Registrations</div>
        </div>
        <div class="iqu-metric">
            <div class="iqu-metric-accent iqu-metric-accent--free"></div>
            <div class="iqu-metric-num"><?php echo (int) $counts['free']; ?></div>
            <div class="iqu-metric-lbl">📖 Free Registration</div>
            <a class="iqu-metric-link" href="<?php echo esc_url(admin_url('admin.php?page=iqu-list-free')); ?>">View
                list →</a>
        </div>
        <div class="iqu-metric">
            <div class="iqu-metric-accent iqu-metric-accent--l1"></div>
            <div class="iqu-metric-num"><?php echo (int) $counts['summer_l1']; ?></div>
            <div class="iqu-metric-lbl">🌞 Summer · Level 1</div>
            <a class="iqu-metric-link"
                href="<?php echo esc_url(admin_url('admin.php?page=iqu-list-summer-l1')); ?>">View list →</a>
        </div>
        <div class="iqu-metric">
            <div class="iqu-metric-accent iqu-metric-accent--l2"></div>
            <div class="iqu-metric-num"><?php echo (int) $counts['summer_l2']; ?></div>
            <div class="iqu-metric-lbl">🌞 Summer · Level 2</div>
            <a class="iqu-metric-link"
                href="<?php echo esc_url(admin_url('admin.php?page=iqu-list-summer-l2')); ?>">View list →</a>
        </div>
        <div class="iqu-metric">
            <div class="iqu-metric-accent iqu-metric-accent--weekend"></div>
            <div class="iqu-metric-num"><?php echo (int) $counts['weekend']; ?></div>
            <div class="iqu-metric-lbl">🗓️ Weekend Program</div>
            <div class="iqu-metric-sub">
                <?php echo (int) $counts['wk_new']; ?> new ·
                <?php echo (int) $counts['wk_existing']; ?> existing
            </div>
            <a class="iqu-metric-link" href="<?php echo esc_url(admin_url('admin.php?page=iqu-list-weekend')); ?>">View
                list →</a>
        </div>
        <div class="iqu-metric">
            <div class="iqu-metric-accent iqu-metric-accent--revenue"></div>
            <div class="iqu-metric-num">$<?php echo number_format($total_revenue, 0); ?></div>
            <div class="iqu-metric-lbl">💰 Payment Collected</div>
            <div class="iqu-metric-sub">Student Confirmed Amount</div>
        </div>
    </div>

    <!-- ── Breakdown + Status Row ───────────────────── -->
    <div class="iqu-section-row">
        <div class="iqu-card">
            <div class="iqu-card-head">
                <span class="iqu-card-head-title">Enrollment breakdown</span>
                <span class="iqu-card-head-badge"><?php echo (int) $counts['total']; ?> total</span>
            </div>
            <?php
          $bars = [
            ['Free Enrollment', $counts['free'], '#1e5fa0'],
            ['Summer · Level 1', $counts['summer_l1'], '#1a8a45'],
            ['Summer · Level 2', $counts['summer_l2'], '#c98a00'],
            ['Weekend · Existing', $counts['wk_existing'], '#0e9490'],
            ['Weekend · New', $counts['wk_new'], '#f7941d'],
          ];
          foreach ($bars as [$label, $count, $color]):
            $pct = round(((int) $count / $total) * 100);
          ?>
            <div class="iqu-bar-row">
                <div class="iqu-bar-lbl"><?php echo esc_html($label); ?><small><?php echo (int) $count; ?>
                        students</small></div>
                <div class="iqu-bar-track">
                    <div class="iqu-bar-fill"
                        style="width:<?php echo (int) $pct; ?>%;background:<?php echo esc_attr($color); ?>"></div>
                </div>
                <div class="iqu-bar-pct"><?php echo (int) $pct; ?>%</div>
            </div>
            <?php endforeach; ?>

            <!-- 📊 প্রোগ্রামভিত্তিক ভার্টিকেল বার চার্ট -->
            <div class="iqu-breakdown-chart">
                <canvas id="iqu-breakdown-chart" aria-label="Registrations by program"></canvas>
            </div>
        </div>

        <div class="iqu-dash-stack">
        <div class="iqu-card">
            <div class="iqu-card-head">
                <span class="iqu-card-head-title">Status overview</span>
            </div>
            <div class="iqu-status-pills">
                <?php foreach (['pending', 'confirmed', 'enrolled', 'contacted', 'cancelled'] as $s):
              $cnt = (int) ($counts[$s] ?? 0);
              $pct = $total > 0 ? round(($cnt / $total) * 100) : 0; ?>
                <div class="iqu-spill">
                    <div class="iqu-spill-dot iqu-spill-dot--<?php echo esc_attr($s); ?>"></div>
                    <div class="iqu-spill-name"><?php echo esc_html(self::status_label($s)); ?></div>
                    <div><span class="iqu-spill-count"><?php echo $cnt; ?></span><span
                            class="iqu-spill-pct"><?php echo $pct; ?>%</span></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ── Online enrollment (3.3.0) ───────────────── -->
        <div class="iqu-card iqu-enroll-overview">
            <div class="iqu-card-head">
                <span class="iqu-card-head-title">Online enrollment</span>
                <span class="iqu-card-head-badge">Started and completed are counted separately</span>
            </div>
            <div class="iqu-status-pills">
                <?php foreach ([
                  ['card_pending', 'Started — waiting for card'],
                  ['stripe_done', 'Completed — card confirmed by Stripe'],
                  ['pending_review', 'Pending review'],
                  ['expired', 'Expired (no card after 7 days)'],
                  ['paid', 'Summer paid by Stripe'],
                  ['needs_review', 'Needs review (any reason)'],
                ] as [$k, $label]): ?>
                <div class="iqu-spill">
                    <div class="iqu-spill-dot iqu-spill-dot--<?php echo esc_attr($k); ?>"></div>
                    <div class="iqu-spill-name"><?php echo esc_html($label); ?></div>
                    <div><span class="iqu-spill-count"><?php echo (int) ($counts[$k] ?? 0); ?></span></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        </div>
    </div>

    <!-- ── Charts Row ───────────────────────────────── -->
    <div class="iqu-chart-row">
        <div class="iqu-card">
            <div class="iqu-card-head">
                <span class="iqu-card-head-title">Registrations per week</span>
                <span class="iqu-card-head-badge">Last 6 weeks</span>
            </div>
            <div class="iqu-chart-body">
                <canvas id="iqu-week-chart" aria-label="Weekly registration trend"></canvas>
            </div>
        </div>
        <div class="iqu-card">
            <div class="iqu-card-head">
                <span class="iqu-card-head-title">Referral sources</span>
                <span class="iqu-card-head-badge">All registrations</span>
            </div>
            <div class="iqu-chart-body iqu-chart-body--referral">
                <canvas id="iqu-referral-chart" aria-label="Registrations by referral source"></canvas>
            </div>
        </div>
        <div class="iqu-card">
            <div class="iqu-card-head">
                <span class="iqu-card-head-title">Payment collected</span>
                <span class="iqu-card-head-badge">$<?php echo number_format($total_revenue, 0); ?> total</span>
            </div>
            <div class="iqu-chart-body iqu-chart-body-donut">
                <canvas id="iqu-revenue-chart" aria-label="Revenue by form type"></canvas>
                <div class="iqu-donut-center">
                    <span class="iqu-donut-amt">$<?php echo number_format($total_revenue, 0); ?></span>
                    <span class="iqu-donut-lbl">collected</span>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Recent Submissions ────────────────────────── -->
    <div class="iqu-recent-bar">
        <div class="iqu-dash-recent-title">Recent Submissions</div>
        <div class="iqu-perpage-btns">
            <?php foreach ([10, 20, 30] as $n): ?>
            <a href="<?php echo esc_url(add_query_arg(['per_page' => $n, 'paged' => 1])); ?>"
                class="iqu-perpage-btn <?php echo $dash_perpage === $n ? 'iqu-perpage-btn--active' : ''; ?>">
                <?php echo $n; ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="iqu-table-wrap">
        <table class="iqu-tbl">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Student</th>
                    <th>Type</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Referral</th>
                    <th>Fee Preference</th>
                    <th>Registered</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($latest)): ?>
                <tr>
                    <td colspan="9" class="iqu-empty-state">No registrations yet.</td>
                </tr>
                <?php else:
              foreach ($latest as $row):
                $view = admin_url('admin.php?page=iqu-view-registration&id=' . (int) $row['id'] . '&from=iqu-registrations'); ?>
                <tr>
                    <td class="iqu-td-id">#<?php echo (int) $row['id']; ?></td>
                    <td class="iqu-td-name"><strong>
                            <a
                                href="<?php echo esc_url($view); ?>"><?php echo esc_html($row['first_name'] . ' ' . $row['last_name']); ?></a></strong>
                    </td>
                    <td><?php echo self::type_chip_html($row['form_type']); ?></td>
                    <td class="iqu-td-email"><?php echo esc_html($row['email']); ?></td>
                    <td><?php echo self::status_badge_html($row['status']) . self::review_badges_html($row); ?></td>
                    <td><?php echo self::referral_chip_html($row['referral'] ?? ''); ?></td>
                    <td><?php echo self::payment_cell_html($row); ?></td>
                    <td class="iqu-td-date"><?php
                      $dt = new DateTime($row['created_at'], new DateTimeZone('UTC'));
                      $dt->setTimezone(new DateTimeZone('Asia/Dhaka'));
                      echo esc_html($dt->format('M j, Y g:i A'));
                      ?>
                    </td>
                    <td class="iqu-td-actions"><a href="<?php echo esc_url($view); ?>"
                            class="iqu-action-btn iqu-action-btn--view">View</a>
                        <button class="iqu-action-btn iqu-action-btn--delete iqu-delete-btn"
                            data-id="<?php echo (int) $row['id']; ?>">Delete</button>
                    </td>
                </tr>
                <?php endforeach;
            endif; ?>
            </tbody>
        </table>
        <!-- iqu-table-wrap-এর ভেতরে, </table> এর পরে -->
        <?php if ($dash_pages > 1): ?>
        <div class="iqu-pagination">
            <span>Page <?php echo $dash_page; ?> of <?php echo $dash_pages; ?></span>
            <div>
                <?php echo paginate_links([
                'base'     => add_query_arg(['paged' => '%#%', 'per_page' => $dash_perpage]),
                'format'   => '',
                'current'  => $dash_page,
                'total'    => $dash_pages,
                'end_size' => 2,
                'mid_size' => 3,
              ]); ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php
  }

  // ════════════════════════════════════════════════════
  // LIST PAGE
  // ════════════════════════════════════════════════════

  /**
   * @param string|array $form_type একটি টাইপ, বা Weekend-এর মতো টাইপের তালিকা
   */
  public function render_list_page($form_type, string $title): void
  {
    if (!current_user_can('manage_options'))
      wp_die('Permission denied.');

    $status = sanitize_text_field($_GET['status'] ?? '');
    $search = sanitize_text_field($_GET['search'] ?? '');
    $page = max(1, absint($_GET['paged'] ?? 1));
    $allowed_per_page = [10, 20, 30];
    $raw_per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
    $perpage = in_array($raw_per_page, $allowed_per_page) ? $raw_per_page : 10;
    $perpage = max(1, $perpage);
    $page_slug = sanitize_key($_GET['page'] ?? '');

    // "Needs review" (3.3.0) is a filter on review reasons, not a status.
    $needs_review = ($status === 'needs_review');
    $args = ['form_type' => $form_type, 'status' => $needs_review ? '' : $status, 'review' => $needs_review, 'search' => $search, 'page' => $page, 'per_page' => $perpage];
    $rows = IQU_Database::get_registrations($args);
    $total_rows = IQU_Database::count_registrations($args);
    $pages = (int) ceil($total_rows / $perpage);

    // একাধিক টাইপ হলে URL-এ একটাই সিন্থেটিক কী যায় — 'weekend'
    $type_key = is_array($form_type) ? 'weekend' : $form_type;

    $export_url = admin_url('admin-ajax.php?action=iqu_export_csv&form_type=' . rawurlencode($type_key) . '&_wpnonce=' . wp_create_nonce('iqu_export_csv'));
    $is_summer = in_array($type_key, [IQU_Database::FORM_SUMMER_LEVEL1, IQU_Database::FORM_SUMMER_LEVEL2], true);

    $tab_map = [
      IQU_Database::FORM_FREE => 'iqu-list-free',
      IQU_Database::FORM_SUMMER_LEVEL1 => 'iqu-list-summer-l1',
      IQU_Database::FORM_SUMMER_LEVEL2 => 'iqu-list-summer-l2',
      'weekend' => 'iqu-list-weekend',
    ];
    $active_tab = $tab_map[$type_key] ?? '';
  ?>
<div class="wrap iqu-admin-wrap">

    <?php $this->render_top_bar($title, 'Ilm-ul-Quran USA — Admin Panel', $active_tab); ?>

    <!-- ── Filter Bar ────────────────────────────────── -->
    <form method="GET" class="iqu-filter-bar">
        <input type="hidden" name="page" value="<?php echo esc_attr($page_slug); ?>">
        <input type="search" name="search" value="<?php echo esc_attr($search); ?>"
            placeholder="Search name, email, WhatsApp…">
        <select name="status">
            <option value="">All statuses</option>
            <?php foreach (self::ALLOWED_STATUSES as $s): ?>
            <option value="<?php echo esc_attr($s); ?>" <?php selected($status, $s); ?>>
                <?php echo esc_html(self::status_label($s)); ?></option>
            <?php endforeach; ?>
            <option value="needs_review" <?php selected($status, 'needs_review'); ?>>Needs review</option>
        </select>
        <select name="per_page" onchange="this.form.submit()">
            <?php foreach ([10, 20, 30] as $n): ?>
            <option value="<?php echo $n; ?>" <?php selected($perpage, $n); ?>>
                <?php echo $n; ?> per page
            </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="button">Filter</button>
        <a href="<?php echo esc_url($export_url); ?>" class="iqu-export-btn"><span class="dashicons dashicons-download" aria-hidden="true"></span>Export CSV</a>
    </form>

    <!-- ── Table ─────────────────────────────────────── -->
    <div class="iqu-table-wrap">
        <table class="iqu-tbl">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Student</th>
                    <th>Email</th>
                    <?php if ($is_summer): ?>
                    <th>Guardian</th>
                    <th>Level</th>
                    <th>Referral</th>
                    <th>Fee</th>
                    <th>Payment</th>
                    <?php else: ?>
                    <th>WhatsApp</th>
                    <th>Course</th>
                    <th>Level</th>
                    <th>Tuition</th>
                    <?php endif; ?>
                    <th>Status</th>
                    <th>Registered</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                <tr>
                    <td colspan="<?php echo $is_summer ? 11 : 11; ?>" class="iqu-empty-state">No registrations found.
                    </td>
                </tr>
                <?php else:
              foreach ($rows as $row):
                $view = admin_url('admin.php?page=iqu-view-registration&id=' . (int) $row['id'] . '&from=' . urlencode($page_slug)); ?>
                <tr data-id="<?php echo (int) $row['id']; ?>">
                    <td class="iqu-td-id">#<?php echo (int) $row['id']; ?></td>
                    <td class="iqu-td-name"><strong><a
                                href="<?php echo esc_url($view); ?>"><?php echo esc_html($row['first_name'] . ' ' . $row['last_name']); ?></a></strong>
                    </td>
                    <td class="iqu-td-email"><?php echo esc_html($row['email']); ?></td>
                    <?php if ($is_summer): ?>
                    <td><?php echo esc_html($row['guardian_name']); ?></td>
                    <td><?php echo esc_html(strtoupper($row['enrollment_level'])); ?></td>
                    <td><?php echo self::referral_chip_html($row['referral'] ?? ''); ?></td>
                    <td>
                        <?php if ($row['admission_fee'] === 'complimentary'): ?>
                        <span class="iqu-amt-free">Complimentary</span>
                        <?php else: ?>
                        <span
                            class="iqu-amt"><?php echo esc_html(IQU_Mailer::format_admission_fee($row['admission_fee'])); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($row['payment_method']): ?>
                        <?php echo esc_html(strtoupper($row['payment_method'])); ?>
                        <?php if ((float) $row['payment_amount'] > 0):
                          $lp = [50 => 'Standard', 30 => 'Supported'];
                          $pkg = $lp[(int)(float)$row['payment_amount']] ?? 'Custom';
                        ?>
                        · <span class="iqu-amt">$<?php echo number_format((float) $row['payment_amount'], 2); ?></span>
                        <span style="font-size:10px;color:#6b7280;">(<?php echo esc_html($pkg); ?>)</span>
                        <?php endif; ?>
                        <br><small><?php echo self::status_badge_html($row['payment_status']); ?></small>
                        <?php else: ?>
                        <span class="iqu-amt-free">—</span>
                        <?php endif; ?>
                    </td>
                    <?php else: ?>
                    <td>
                        <?php if ($row['whatsapp']): ?>
                        <a class="iqu-wa-link"
                            href="https://wa.me/<?php echo esc_attr(preg_replace('/\D/', '', $row['whatsapp'])); ?>"
                            target="_blank"><?php echo esc_html($row['whatsapp']); ?></a>
                        <?php else: ?>
                        <span class="iqu-amt-free">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $lc = $row['course_type'] ?? '';
                        echo $lc !== ''
                            ? '<strong>' . esc_html(IQU_Pricing::label($lc)) . '</strong><br>'
                              . '<small style="opacity:.7">' . (int) $row['days_per_week'] . ' days/week</small>'
                            : '<span class="iqu-amt-free">—</span>';
                        ?>
                    </td>
                    <td><?php echo esc_html(ucfirst($row['quran_level'])); ?></td>
                    <td><?php echo self::payment_cell_html($row); ?></td>
                    <?php endif; ?>
                    <td>
                        <select class="iqu-status-select" data-id="<?php echo (int) $row['id']; ?>">
                            <?php foreach (self::ALLOWED_STATUSES as $s): ?>
                            <option value="<?php echo esc_attr($s); ?>" <?php selected($row['status'], $s); ?>>
                                <?php echo esc_html(self::status_label($s)); ?></option>
                            <?php endforeach; ?>
                        </select><?php echo self::review_badges_html($row); ?>
                    </td>
                    <td class="iqu-td-date"><?php
                                          $dt = new DateTime($row['created_at'], new DateTimeZone('UTC'));
                                          $dt->setTimezone(new DateTimeZone('Asia/Dhaka'));
                                          echo esc_html($dt->format('M j, Y g:i A'));
                                          ?>
                    </td>
                    <td class="iqu-td-actions">
                        <a href="<?php echo esc_url($view); ?>" class="iqu-action-btn iqu-action-btn--view">View</a>
                        <button class="iqu-action-btn iqu-action-btn--delete iqu-delete-btn"
                            data-id="<?php echo (int) $row['id']; ?>">Delete</button>
                    </td>
                </tr>
                <?php endforeach;
            endif; ?>
            </tbody>
        </table>

        <?php if ($pages > 1): ?>
        <div class="iqu-pagination">
            <span>Page <?php echo (int) $page; ?> of <?php echo (int) $pages; ?></span>
            <div>
                <?php echo paginate_links([
                'base'     => add_query_arg(['paged' => '%#%', 'per_page' => $perpage]),
                'format'   => '',
                'current'  => $page,
                'total'    => $pages,
                'end_size' => 2,
                'mid_size' => 3,
              ]); ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php
  }

  // ════════════════════════════════════════════════════
  // DETAIL VIEW
  // ════════════════════════════════════════════════════

  public function render_view_page(): void
  {
    if (!current_user_can('manage_options'))
      wp_die('Permission denied.');

    $id = absint($_GET['id'] ?? 0);
    $row = $id ? IQU_Database::get_registration($id) : null;
    if (!$row) {
      echo '<div class="wrap iqu-admin-wrap"><h1>Registration Not Found</h1></div>';
      return;
    }

    $is_summer = in_array($row['form_type'], [IQU_Database::FORM_SUMMER_LEVEL1, IQU_Database::FORM_SUMMER_LEVEL2], true);
    $is_weekend = in_array($row['form_type'], IQU_Database::weekend_types(), true);
    $ref_page = sanitize_key($_GET['from'] ?? 'iqu-registrations');
    $back_page = in_array($ref_page, self::ALLOWED_BACK_PAGES, true) ? $ref_page : 'iqu-registrations';
    $back_url = admin_url('admin.php?page=' . $back_page);
    $initials = strtoupper(mb_substr($row['first_name'], 0, 1) . mb_substr($row['last_name'], 0, 1));
    $full_name = $row['first_name'] . ' ' . $row['last_name'];
  ?>
<div class="wrap iqu-admin-wrap">
    <a href="<?php echo esc_url($back_url); ?>" class="iqu-back-btn">← Back to list</a>

    <div class="iqu-detail-grid">
        <!-- ── Left: Data ──────────────────────────────── -->
        <div>
            <div class="iqu-profile-top">
                <div class="iqu-avatar"><?php echo esc_html($initials); ?></div>
                <div>
                    <div class="iqu-profile-name"><?php echo esc_html($full_name); ?></div>
                    <div class="iqu-profile-meta">
                        <?php echo self::type_chip_html($row['form_type']); ?>
                        <?php echo self::status_badge_html($row['status']) . self::review_badges_html($row); ?>
                        <span style="font-size:11px;color:var(--iqu-text2)">Age <?php echo (int) $row['age']; ?></span>
                        <span style="font-size:11px;color:var(--iqu-text2)">#<?php echo (int) $row['id']; ?></span>
                    </div>
                </div>
            </div>

            <div class="iqu-section-title">Contact</div>
            <div class="iqu-detail-data">
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Email</div>
                    <div class="iqu-detail-val" style="font-size:11px"><?php echo esc_html($row['email']); ?></div>
                </div>
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl"><?php echo $is_summer ? 'Contact Number' : 'WhatsApp'; ?></div>
                    <div class="iqu-detail-val">
                        <?php if ($is_summer):
                  $contact = $row['guardian_contact'] ?? '';
                  echo $contact ? esc_html($contact) : '—';
                else:
                  $wa = $row['whatsapp'] ?: ($row['guardian_whatsapp'] ?? '');
                  if ($wa) {
                    $wc = preg_replace('/\D/', '', $wa);
                    echo '<a class="iqu-wa-link" href="https://wa.me/' . esc_attr($wc) . '" target="_blank">' . esc_html($wa) . '</a>';
                  } else {
                    echo '—';
                  }
                endif; ?>
                    </div>
                </div>
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Country of Origin</div>
                    <div class="iqu-detail-val"><?php echo esc_html($row['country_origin']); ?></div>
                </div>
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Country of Residence</div>
                    <div class="iqu-detail-val"><?php echo esc_html($row['country_res']); ?></div>
                </div>
            </div>

            <?php if ($is_summer || $is_weekend): ?>
            <div class="iqu-section-title">Guardian Information</div>
            <div class="iqu-detail-data">
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Guardian Name</div>
                    <div class="iqu-detail-val"><?php echo esc_html($row['guardian_name'] ?: '—'); ?></div>
                </div>
                <?php if ($is_summer): ?>
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Guardian WhatsApp</div>
                    <div class="iqu-detail-val">
                        <?php if ($row['guardian_whatsapp']): ?>
                        <a class="iqu-wa-link"
                            href="https://wa.me/<?php echo esc_attr(preg_replace('/\D/', '', $row['guardian_whatsapp'])); ?>"
                            target="_blank"><?php echo esc_html($row['guardian_whatsapp']); ?></a>
                        <?php else: echo '—';
                  endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="iqu-section-title">Academic</div>
            <div class="iqu-detail-data">
                <?php if ($is_weekend): ?>
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Program</div>
                    <div class="iqu-detail-val">Weekend Ilm Program</div>
                </div>
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Student Status</div>
                    <div class="iqu-detail-val">
                        <?php echo $row['form_type'] === IQU_Database::FORM_WEEKEND_NEW
                          ? '🆕 New student'
                          : '✅ Existing student'; ?>
                    </div>
                </div>
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Class Days</div>
                    <div class="iqu-detail-val">Saturday &amp; Sunday · 10&ndash;11 AM (CST)</div>
                </div>
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Referral</div>
                    <div class="iqu-detail-val"><?php echo esc_html($row['referral'] ?: '—'); ?></div>
                </div>
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Tuition</div>
                    <div class="iqu-detail-val"><span class="iqu-amt-free">Fully free</span></div>
                </div>
                <?php elseif (!$is_summer): ?>
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Qur'an Level</div>
                    <div class="iqu-detail-val"><?php echo esc_html(ucfirst($row['quran_level']) ?: '—'); ?></div>
                </div>
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Days / Week</div>
                    <div class="iqu-detail-val"><?php echo esc_html($row['days_per_week'] ?: '—'); ?></div>
                </div>
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Preferred Days</div>
                    <div class="iqu-detail-val"><?php echo esc_html($row['preferred_days'] ?: '—'); ?></div>
                </div>
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Time Slot</div>
                    <div class="iqu-detail-val"><?php echo esc_html($row['time_slot'] ?: '—'); ?></div>
                </div>
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Session Duration</div>
                    <div class="iqu-detail-val">
                        <?php echo esc_html($row['session_dur'] ? $row['session_dur'] . ' min' : '—'); ?></div>
                </div>
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Teacher Preference</div>
                    <div class="iqu-detail-val"><?php echo esc_html(ucfirst($row['teacher_pref']) ?: '—'); ?></div>
                </div>
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Device</div>
                    <div class="iqu-detail-val"><?php echo esc_html($row['device'] ?: '—'); ?></div>
                </div>
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Languages</div>
                    <div class="iqu-detail-val"><?php echo esc_html($row['languages'] ?: '—'); ?></div>
                </div>
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">WA Updates</div>
                    <div class="iqu-detail-val"><?php echo esc_html($row['wa_updates'] ?: '—'); ?></div>
                </div>
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Referral</div>
                    <div class="iqu-detail-val"><?php echo esc_html($row['referral'] ?: '—'); ?></div>
                </div>
                <div class="iqu-detail-item iqu-detail-full">
                    <div class="iqu-detail-lbl">Memorized</div>
                    <div class="iqu-detail-val"><?php echo esc_html($row['memorized'] ?: '—'); ?></div>
                </div>
                <?php else: ?>
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Enrollment Level</div>
                    <div class="iqu-detail-val"><?php echo esc_html(strtoupper($row['enrollment_level'])); ?></div>
                </div>
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">WhatsApp Group</div>
                    <div class="iqu-detail-val"><?php echo esc_html(ucfirst($row['whatsapp_group'])); ?></div>
                </div>
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Referral</div>
                    <div class="iqu-detail-val"><?php echo esc_html($row['referral'] ?: '—'); ?></div>
                </div>
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Qur'an Level</div>
                    <div class="iqu-detail-val"><?php echo esc_html(ucfirst($row['quran_level']) ?: '—'); ?></div>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!$is_summer && !$is_weekend): ?>
            <div class="iqu-section-title">Course &amp; Tuition</div>
            <div class="iqu-detail-data">
                <?php
                $d_course   = $row['course_type'] ?? '';
                $d_original = (float) ($row['calculated_amount'] ?? 0);
                $d_discount = (float) ($row['discount_amount'] ?? 0);
                $d_final    = (float) ($row['payment_amount'] ?? 0);
                $d_coupon   = $row['coupon_code'] ?? '';
                $d_rate     = (float) ($row['per_class_rate'] ?? 0);
                $d_days     = (int) ($row['days_per_week'] ?? 0);
                ?>

                <?php if ($d_course !== ''): ?>
                <!-- ── নতুন সিস্টেম (v2.8.0+) ── -->
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Course</div>
                    <div class="iqu-detail-val">
                        <strong><?php echo esc_html(IQU_Pricing::label($d_course) ?: $d_course); ?></strong>
                    </div>
                </div>
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Rate Breakdown</div>
                    <div class="iqu-detail-val">
                        <?php if ($d_rate > 0 && $d_days > 0): ?>
                        <?php echo (int) ($d_days * IQU_Pricing::WEEKS_PER_MONTH); ?> classes/month
                        &times; $<?php echo number_format($d_rate, 2); ?>/class
                        <?php else: ?>
                        &mdash;
                        <?php endif; ?>
                    </div>
                </div>
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Regular Tuition</div>
                    <div class="iqu-detail-val" style="font-family:var(--iqu-font-mono);">
                        <?php echo $d_original > 0 ? '$' . number_format($d_original, 2) : '&mdash;'; ?>
                    </div>
                </div>

                <?php if ($d_discount > 0 && !empty($row['special_discount'])): ?>
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Special Discount</div>
                    <div class="iqu-detail-val" style="color:#1a8a45;font-weight:600;">
                        &minus;$<?php echo number_format($d_discount, 2); ?>
                        <?php if ($d_original > 0): ?>
                        <small style="opacity:.7">
                            (<?php echo (int) round(($d_discount / $d_original) * 100); ?>% off &middot; student opted
                            in)
                        </small>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($d_discount > 0 && empty($row['special_discount'])): ?>
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Coupon Code</div>
                    <div class="iqu-detail-val">
                        <code><?php echo esc_html($d_coupon ?: '—'); ?></code>
                    </div>
                </div>
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Zakat Scholarship</div>
                    <div class="iqu-detail-val" style="color:#1a8a45;font-weight:600;">
                        &minus;$<?php echo number_format($d_discount, 2); ?>
                        <?php if ($d_original > 0): ?>
                        <small style="opacity:.7">
                            (<?php echo (int) round(($d_discount / $d_original) * 100); ?>%)
                        </small>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Zakat Declaration</div>
                    <div class="iqu-detail-val">
                        <?php echo !empty($row['zakat_declaration'])
                            ? '<span style="color:#1a8a45;font-weight:600;">&check; Confirmed by student</span>'
                            : '<span style="color:#c0392b;font-weight:600;">&times; Not confirmed</span>'; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Monthly Amount Due</div>
                    <div class="iqu-detail-val"
                        style="font-family:var(--iqu-font-mono);font-size:1.15em;font-weight:700;<?php echo $d_final > 0 ? 'color:#1a5276' : 'color:#1a8a45'; ?>">
                        $<?php echo number_format($d_final, 2); ?>
                        <?php if ($d_final <= 0): ?>
                        <small style="font-weight:400;">(full scholarship)</small>
                        <?php endif; ?>
                    </div>
                </div>

                <?php else: ?>
                <!-- ── পুরনো রেকর্ড (v2.7.x ও তার আগে) ── -->
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Monthly Fee <small style="opacity:.6">(legacy)</small></div>
                    <div class="iqu-detail-val"><?php echo esc_html($row['fee_pref'] ?: '—'); ?></div>
                </div>
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Free Request Reason</div>
                    <div class="iqu-detail-val"><?php echo esc_html($row['free_request_reason'] ?: '—'); ?></div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($is_summer): ?>
            <div class="iqu-section-title">Payment Details</div>
            <div class="iqu-detail-data">
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Admission Fee</div>
                    <div class="iqu-detail-val">
                        <?php
                  if ($row['admission_fee'] === 'complimentary')
                    echo 'Complimentary';
                  elseif ($row['admission_fee'] === 'flexible')
                    echo 'Flexible / Custom';
                  elseif ($row['admission_fee'])
                    echo '$' . esc_html($row['admission_fee']);
                  else
                    echo '—';
                  ?>
                    </div>
                </div>
                <!-- Admission Fee item-এর পরে এটা যোগ করুন -->
                <?php if ($row['admission_fee'] === 'flexible'): ?>
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">
                        <?php
                    $flexible_note = trim($row['flexible_fee_note'] ?? '');
                    $is_dollar = (bool) preg_match('/^\$?\d+(\.\d{1,2})?$/', $flexible_note);
                    echo $is_dollar ? 'Flexible Amount' : 'Free Request Reason';
                    ?>
                    </div>
                    <div class="iqu-detail-val">
                        <?php if ($flexible_note !== ''): ?>
                        <?php if ($is_dollar): ?>
                        <span
                            style="color:#1a8a45;font-weight:600">$<?php echo esc_html(ltrim($flexible_note, '$')); ?></span>
                        <?php else: ?>
                        <?php echo esc_html($flexible_note); ?>
                        <?php endif; ?>
                        <?php else: ?>
                        —
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Amount Paid</div>
                    <div class="iqu-detail-val"
                        style="font-family:var(--iqu-font-mono);<?php echo (float) $row['payment_amount'] > 0 ? 'color:#1a8a45' : ''; ?>">
                        <?php echo (float) $row['payment_amount'] > 0 ? '$' . number_format((float) $row['payment_amount'], 2) : '—'; ?>
                    </div>
                </div>
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Payment Method</div>
                    <div class="iqu-detail-val"><?php echo esc_html(strtoupper($row['payment_method']) ?: '—'); ?></div>
                </div>
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Payment Status</div>
                    <div class="iqu-detail-val">
                        <?php echo self::status_badge_html($row['payment_status'] ?: 'pending'); ?></div>
                </div>
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Transaction ID</div>
                    <div class="iqu-detail-val" style="font-family:var(--iqu-font-mono)">
                        <?php echo esc_html($row['transaction_id'] ?: '—'); ?></div>
                </div>
            </div>
            <?php endif; ?>

            <div class="iqu-section-title">Registration Info</div>
            <div class="iqu-detail-data">
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">IP Address</div>
                    <div class="iqu-detail-val" style="font-family:var(--iqu-font-mono);font-size:11px">
                        <?php echo esc_html($row['ip_address'] ?: '—'); ?></div>
                </div>
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Registered At</div>
                    <div class="iqu-detail-val" style="font-size:11px"><?php
                                                                  $dt = new DateTime($row['created_at'], new DateTimeZone('UTC'));
                                                                  $dt->setTimezone(new DateTimeZone('Asia/Dhaka'));
                                                                  echo esc_html($dt->format('M j, Y g:i A'));
                                                                  ?>
                    </div>
                </div>
                <?php if (!empty($row['country_ip']) || !empty($row['review_reason']) || !empty($row['billing_account_id'])): ?>
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Country (from IP)</div>
                    <div class="iqu-detail-val"><?php echo esc_html($row['country_ip'] ?: '—'); ?></div>
                </div>
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Needs review</div>
                    <div class="iqu-detail-val"><?php echo self::review_badges_html($row) ?: '—'; ?></div>
                </div>
                <?php if (!empty($row['billing_account_id']) && class_exists('IQU_Billing_Page')): ?>
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Monthly billing</div>
                    <div class="iqu-detail-val"><a href="<?php echo esc_url(IQU_Billing_Page::family_url((int) $row['billing_account_id'])); ?>">Open family page →</a></div>
                </div>
                <?php endif; ?>
                <?php endif; ?>
                <?php if (!empty($row['admin_note'])): ?>
                <div class="iqu-detail-item iqu-detail-full">
                    <div class="iqu-detail-lbl">Admin Note</div>
                    <div class="iqu-detail-val"><?php echo esc_html($row['admin_note']); ?></div>
                </div>
                <?php endif; ?>
            </div>

            <?php echo self::monthly_payments_html($row); // escaped inside ?>
        </div>

        <!-- ── Right: Status Panel ─────────────────────── -->
        <div class="iqu-status-panel">
            <h3>Update Status</h3>
            <select id="iqu-detail-status" data-id="<?php echo (int) $id; ?>">
                <?php foreach (self::ALLOWED_STATUSES as $s): ?>
                <option value="<?php echo esc_attr($s); ?>" <?php selected($row['status'], $s); ?>>
                    <?php echo esc_html(self::status_label($s)); ?></option>
                <?php endforeach; ?>
            </select>
            <h3 style="margin-top:18px">Admin Note</h3>
            <textarea id="iqu-admin-note" rows="5"
                placeholder="Internal notes…"><?php echo esc_textarea($row['admin_note'] ?? ''); ?></textarea>
            <button id="iqu-save-status" class="button button-primary" data-id="<?php echo (int) $id; ?>">Save
                Changes</button>
            <div id="iqu-status-msg" style="margin-top:10px;font-size:13px"></div>
        </div>
    </div>
</div>
<?php
  }

  // ════════════════════════════════════════════════════
  // AJAX HANDLERS
  // ════════════════════════════════════════════════════

  public function handle_update_status(): void
  {
    check_ajax_referer('iqu_update_status', '_wpnonce');
    if (!current_user_can('manage_options'))
      wp_send_json_error('Permission denied.');
    $id = absint($_POST['id'] ?? 0);
    $status = sanitize_text_field($_POST['status'] ?? '');
    $note = sanitize_textarea_field($_POST['note'] ?? '');
    if (!$id)
      wp_send_json_error('Invalid ID.');
    if (!in_array($status, self::ALLOWED_STATUSES, true))
      wp_send_json_error('Invalid status value.');
    self::send_json(IQU_Database::update_status($id, $status, $note));
  }

  /**
   * 🔒 CSV formula injection সুরক্ষা।
   *
   * Excel/Sheets-এ `=`, `+`, `-`, `@` দিয়ে শুরু হওয়া সেল সূত্র হিসেবে
   * চলে। কেউ নাম বা নোটে `=HYPERLINK(...)` লিখে দিলে অ্যাডমিন ফাইল
   * খোলামাত্র সেটা কার্যকর হতো। সামনে apostrophe দিলে তা আটকায়।
   */
  private static function csv_safe(?string $value): string
  {
    $value = (string) $value;
    if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
      return "'" . $value;
    }
    return $value;
  }

  public function handle_export_csv(): void
  {
    check_admin_referer('iqu_export_csv');
    if (!current_user_can('manage_options'))
      wp_die('Permission denied.');
    $form_type = sanitize_text_field($_GET['form_type'] ?? '');
    $args = ['per_page' => 9999, 'page' => 1];
    if ($form_type === 'weekend') {
      $args['form_type'] = IQU_Database::weekend_types();
    } elseif ($form_type) {
      $args['form_type'] = $form_type;
    }
    $rows = IQU_Database::get_registrations($args);
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="iqu-registrations-' . ($form_type ?: 'all') . '-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, [
      'ID', 'Form Type', 'First Name', 'Last Name', 'Email', 'Age',
      'Country Origin', 'Country Residence', "Qur'an Level",

      // 💰 নতুন কলাম — হেডার ও রো একই ক্রমে রাখতে হবে
      'Course', 'Days/Week', 'Rate/Class', 'Regular Tuition',
      'Coupon Code', 'Discount Amount', 'Special Discount', 'Declaration Signed',

      'Preferred Days', 'Time Slot', 'Session Duration', 'Languages',
      'WhatsApp', 'Memorized', 'Teacher Pref', 'Device', 'Referral',
      'WA Updates', 'Fee Pref (legacy)', 'Free Request Note',
      'Enrollment Level', 'Guardian', 'Guardian Contact', 'Guardian WhatsApp',
      'WA Group', 'Admission Fee', 'Payment Method', 'Amount Due',
      'Transaction ID', 'Payment Status', 'Status', 'Registered At',
    ]);
    foreach ($rows as $r) {
      fputcsv($out, [
        $r['id'],
        $r['form_type'],
        self::csv_safe($r['first_name']),
        self::csv_safe($r['last_name']),
        self::csv_safe($r['email']),
        $r['age'],
        self::csv_safe($r['country_origin']),
        self::csv_safe($r['country_res']),
        $r['quran_level'],

        // 💰 নতুন কোর্স ও প্রাইসিং কলাম
        $r['course_type'] ?? '',
        $r['days_per_week'],
        $r['per_class_rate'] ?? '',
        $r['calculated_amount'] ?? '',
        self::csv_safe($r['coupon_code'] ?? ''),
        $r['discount_amount'] ?? '',
        !empty($r['special_discount']) ? 'Yes' : 'No',
        !empty($r['zakat_declaration']) ? 'Yes' : 'No',

        self::csv_safe($r['preferred_days']),
        self::csv_safe($r['time_slot']),
        $r['session_dur'],
        self::csv_safe($r['languages']),
        self::csv_safe($r['whatsapp']),
        self::csv_safe($r['memorized']),
        $r['teacher_pref'],
        $r['device'],
        $r['referral'],
        $r['wa_updates'],
        $r['fee_pref'],
        self::csv_safe($r['free_request_reason']),
        $r['enrollment_level'],
        self::csv_safe($r['guardian_name']),
        self::csv_safe($r['guardian_contact']),
        self::csv_safe($r['guardian_whatsapp']),
        $r['whatsapp_group'],
        $r['admission_fee'],
        $r['payment_method'],
        $r['payment_amount'],
        self::csv_safe($r['transaction_id']),
        $r['payment_status'],
        $r['status'],
        $r['created_at'],
      ]);
    }
    fclose($out);
    exit;
  }

  public function handle_delete(): void
  {
    check_ajax_referer('iqu_delete_reg', '_wpnonce');
    if (!current_user_can('manage_options'))
      wp_send_json_error('Permission denied.');
    global $wpdb;
    $id = absint($_POST['id'] ?? 0);
    $table = $wpdb->prefix . IQU_TABLE_NAME;
    if (!$id)
      wp_send_json_error('Invalid ID.');
    $deleted = $wpdb->delete($table, ['id' => $id], ['%d']);
    $deleted ? wp_send_json_success('Registration deleted.') : wp_send_json_error('Delete failed.');
  }

  private static function send_json(bool $ok): void
  {
    $ok ? wp_send_json_success('Status updated successfully.') : wp_send_json_error('Invalid status or update failed.');
  }
}