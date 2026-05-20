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
  private const ALLOWED_STATUSES = ['pending', 'confirmed', 'contacted', 'enrolled', 'cancelled'];
  private const ALLOWED_BACK_PAGES = [
    'iqu-registrations',
    'iqu-list-free',
    'iqu-list-summer-l1',
    'iqu-list-summer-l2',
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
    add_submenu_page('iqu-registrations', 'View Registration', '', 'manage_options', 'iqu-view-registration', [$this, 'render_view_page']);
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

    return [
      'weekly'   => ['labels' => $week_labels, 'data' => $week_counts],
      'revenue'  => ['labels' => ['Free Enrollment', 'Summer L1', 'Summer L2'], 'data' => [$revenue_free, $revenue_l1, $revenue_l2], 'total' => $total_revenue],
      'referral' => ['labels' => $ref_labels, 'data' => $ref_data],

    ];
  }

  // ════════════════════════════════════════════════════
  // SHARED HELPERS
  // ════════════════════════════════════════════════════

  private function render_logo(): void
  {
?>
<img src="https://ilmulquranus.org/wp-content/uploads/2025/08/Favicon.png" alt="IQU Logo" width="44" height="39"
    style="display:block" />
<?php
  }

  private function render_top_bar(string $title, string $subtitle, string $active_page = ''): void
  {
    $tabs = [
      'iqu-registrations' => 'All',
      'iqu-list-free' => 'Free',
      'iqu-list-summer-l1' => 'Level 1',
      'iqu-list-summer-l2' => 'Level 2',
    ];
  ?>
<div class="iqu-top-bar">
    <div class="iqu-top-bar-left">
        <div class="iqu-logo-mark"><?php $this->render_logo(); ?></div>
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

  private static function status_badge_html(string $status): string
  {
    $map = [
      'pending' => 'iqu-sb-pending',
      'confirmed' => 'iqu-sb-confirmed',
      'enrolled' => 'iqu-sb-enrolled',
      'cancelled' => 'iqu-sb-cancelled',
      'contacted' => 'iqu-sb-contacted',
    ];
    $cls = $map[$status] ?? 'iqu-sb-pending';
    return '<span class="iqu-status-badge ' . esc_attr($cls) . '">' . esc_html(ucfirst($status)) . '</span>';
  }

  private static function type_chip_html(string $form_type): string
  {
    return '<span class="iqu-chip iqu-chip-' . esc_attr($form_type) . '">'
      . esc_html(IQU_Mailer::form_label($form_type))
      . '</span>';
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
      'pending' => IQU_Database::count_registrations(['status' => 'pending']),
      'confirmed' => IQU_Database::count_registrations(['status' => 'confirmed']),
      'enrolled' => IQU_Database::count_registrations(['status' => 'enrolled']),
      'contacted' => IQU_Database::count_registrations(['status' => 'contacted']),
      'cancelled' => IQU_Database::count_registrations(['status' => 'cancelled']),
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
        </div>

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
                    <div class="iqu-spill-name"><?php echo esc_html(ucfirst($s)); ?></div>
                    <div><span class="iqu-spill-count"><?php echo $cnt; ?></span><span
                            class="iqu-spill-pct"><?php echo $pct; ?>%</span></div>
                </div>
                <?php endforeach; ?>
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
                    <td colspan="8" class="iqu-empty-state">No registrations yet.</td>
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
                    <td><?php echo self::status_badge_html($row['status']); ?></td>
                    <td><?php echo self::referral_chip_html($row['referral'] ?? ''); ?></td>
                    <td>
                        <?php
                    $form_type = $row['form_type'] ?? '';
                    $admission_fee = $row['admission_fee'] ?? '';
                    $fee_pref = $row['fee_pref'] ?? '';
                    $flexible_note = trim($row['flexible_fee_note'] ?? '');
                    $pay_amount = (float) ($row['payment_amount'] ?? 0);
 
                    // ── Free enrollment form ──────────────────────────────
                    if ($form_type === 'free') {

                      // ১. এখানে helper string তৈরি করুন
                      $pending_badge = '<span class="iqu-free-pending-badge">Pending</span>';

                      if ($fee_pref === 'free') {
                        // Zakat-eligible free (এখানে badge দরকার নেই)
                        echo '<span class="iqu-no-pay-chip"><span class="iqu-no-pay-badge">No Payment</span> Totally Free (Zakat)</span>';
                      } elseif ($fee_pref === 'other' && $flexible_note !== '') {
                        // Custom / "other" amount typed by user
                        $display_note = preg_match('/^\$?\d+(\.\d{1,2})?$/', $flexible_note)
                          ? '$' . ltrim($flexible_note, '$')
                          : $flexible_note;
                          
                        // ২. এখানে শেষে $pending_badge যোগ করুন
                        echo '<span class="iqu-pay-cell">' . $pending_badge . '<span class="iqu-amt">' . esc_html($display_note) . '</span></span>';
                      } elseif ($fee_pref !== '') {
                        // Standard package — e.g. hifz_80, qaidah_60, arabic_50
                        $labels = [
                          'arabic_50' => 'Arabic — $50/mo',
                          'hifz_100' => 'Hifz — $100/mo',
                          'hifz_90' => 'Hifz — $90/mo',
                          'hifz_80' => 'Hifz — $80/mo',
                          'hifz_70' => 'Hifz — $70/mo',
                          'hifz_60' => 'Hifz — $60/mo',
                          'qaidah_80' => "Qa'idah — $80/mo",
                          'qaidah_70' => "Qa'idah — $70/mo",
                          'qaidah_60' => "Qa'idah — $60/mo",
                          'qaidah_50' => "Qa'idah — $50/mo",
                        ];
                        $label = $labels[$fee_pref] ?? $fee_pref;
                        
                        // ৩. এখানে শেষে $pending_badge যোগ করুন
                        echo '<span class="iqu-pay-cell">' . $pending_badge . '<span class="iqu-amt">' . esc_html($label) . '</span></span>';
                      } else {
                        echo '<span class="iqu-amt-free">—</span>';
                      }

                      // ── Summer Level 1 / Level 2 ──────────────────────────
                    } elseif (in_array($form_type, ['summer_level1', 'summer_level2'], true)) {

                      $pay_method = strtolower(trim($row['payment_method'] ?? ''));

                      // ── ১. Complimentary ──────────────────────────────────
                      if ($admission_fee === 'complimentary') {
                        echo '<span class="iqu-no-pay-chip"><span class="iqu-no-pay-badge">No Payment</span> Complimentary</span>';

                        // ── ২. Totally Free ───────────────────────────────────
                      } elseif (
                        $admission_fee === 'flexible'
                        && $pay_amount <= 0
                        && ($flexible_note === '' || strtolower($flexible_note) === 'requesting free enrollment')
                      ) {
                        echo '<span class="iqu-no-pay-chip"><span class="iqu-no-pay-badge">No Payment</span> Totally Free</span>';

                        // ── ৩. কোনো amount আছে ───────────────────────────────
                      } else {

                        // flexible_fee_note থেকে amount নাও যদি payment_amount শূন্য হয়
                        $resolved = $pay_amount;
                        if (
                          $resolved <= 0 && $admission_fee === 'flexible'
                          && preg_match('/^\$?(\d+(\.\d{1,2})?)$/', $flexible_note, $m)
                        ) {
                          $resolved = (float) $m[1];
                        }

                        // Package label
                        $pkg_map   = [50 => 'Standard', 30 => 'Supported'];
                        $pkg_label = $resolved > 0 ? ($pkg_map[(int) $resolved] ?? 'Custom') : '';

                        // Payment method CSS class
                        $method_class_map = ['zelle' => 'iqu-pay-zelle', 'zeffy' => 'iqu-pay-zeffy'];
                        $method_cls = $method_class_map[$pay_method] ?? 'iqu-pay-other';

                        echo '<span class="iqu-pay-cell">';

                        if ($pay_method !== '') {
                          echo '<span class="iqu-pay-method ' . esc_attr($method_cls) . '">'
                            . esc_html(ucfirst($pay_method))
                            . '</span>'
                            . '<span class="iqu-pay-sep">·</span>';
                        }

                        if ($resolved > 0) {
                          echo '<span class="iqu-amt">$' . esc_html(number_format($resolved, 0)) . '</span>';
                          if ($pkg_label !== '') {
                            echo ' <span class="iqu-pkg-label">(' . esc_html($pkg_label) . ')</span>';
                          }
                        } else {
                          echo '<span class="iqu-amt-free">—</span>';
                        }

                        echo '</span>';
                      }
                    }
                    ?>
                    </td>
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

  public function render_list_page(string $form_type, string $title): void
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

    $args = ['form_type' => $form_type, 'status' => $status, 'search' => $search, 'page' => $page, 'per_page' => $perpage];
    $rows = IQU_Database::get_registrations($args);
    $total_rows = IQU_Database::count_registrations($args);
    $pages = (int) ceil($total_rows / $perpage);

    $export_url = admin_url('admin-ajax.php?action=iqu_export_csv&form_type=' . rawurlencode($form_type) . '&_wpnonce=' . wp_create_nonce('iqu_export_csv'));
    $is_summer = in_array($form_type, [IQU_Database::FORM_SUMMER_LEVEL1, IQU_Database::FORM_SUMMER_LEVEL2], true);

    $tab_map = [
      IQU_Database::FORM_FREE => 'iqu-list-free',
      IQU_Database::FORM_SUMMER_LEVEL1 => 'iqu-list-summer-l1',
      IQU_Database::FORM_SUMMER_LEVEL2 => 'iqu-list-summer-l2',
    ];
    $active_tab = $tab_map[$form_type] ?? '';
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
                <?php echo esc_html(ucfirst($s)); ?></option>
            <?php endforeach; ?>
        </select>
        <select name="per_page" onchange="this.form.submit()">
            <?php foreach ([10, 20, 30] as $n): ?>
            <option value="<?php echo $n; ?>" <?php selected($perpage, $n); ?>>
                <?php echo $n; ?> per page
            </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="button">Filter</button>
        <a href="<?php echo esc_url($export_url); ?>" class="iqu-export-btn">⬇ Export CSV</a>
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
                    <th>Level</th>
                    <th>Country</th>
                    <?php endif; ?>
                    <th>Status</th>
                    <th>Registered</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                <tr>
                    <td colspan="10" class="iqu-empty-state">No registrations found.</td>
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
                    <td><?php echo esc_html(ucfirst($row['quran_level'])); ?></td>
                    <td><?php echo esc_html($row['country_res']); ?></td>
                    <?php endif; ?>
                    <td>
                        <select class="iqu-status-select" data-id="<?php echo (int) $row['id']; ?>">
                            <?php foreach (self::ALLOWED_STATUSES as $s): ?>
                            <option value="<?php echo esc_attr($s); ?>" <?php selected($row['status'], $s); ?>>
                                <?php echo esc_html(ucfirst($s)); ?></option>
                            <?php endforeach; ?>
                        </select>
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
                        <?php echo self::status_badge_html($row['status']); ?>
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

            <?php if ($is_summer): ?>
            <div class="iqu-section-title">Guardian Information</div>
            <div class="iqu-detail-data">
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Guardian Name</div>
                    <div class="iqu-detail-val"><?php echo esc_html($row['guardian_name'] ?: '—'); ?></div>
                </div>
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
            </div>
            <?php endif; ?>

            <div class="iqu-section-title">Academic</div>
            <div class="iqu-detail-data">
                <?php if (!$is_summer): ?>
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

            <?php if (!$is_summer): ?>
            <div class="iqu-section-title">Fee Preference</div>
            <div class="iqu-detail-data">
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Monthly Fee</div>
                    <div class="iqu-detail-val"><?php echo esc_html($row['fee_pref'] ?: '—'); ?></div>
                </div>
                <div class="iqu-detail-item">
                    <div class="iqu-detail-lbl">Free Request Reason</div>
                    <div class="iqu-detail-val"><?php echo esc_html($row['free_request_reason'] ?: '—'); ?></div>
                </div>
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
                <?php if (!empty($row['admin_note'])): ?>
                <div class="iqu-detail-item iqu-detail-full">
                    <div class="iqu-detail-lbl">Admin Note</div>
                    <div class="iqu-detail-val"><?php echo esc_html($row['admin_note']); ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── Right: Status Panel ─────────────────────── -->
        <div class="iqu-status-panel">
            <h3>Update Status</h3>
            <select id="iqu-detail-status" data-id="<?php echo (int) $id; ?>">
                <?php foreach (self::ALLOWED_STATUSES as $s): ?>
                <option value="<?php echo esc_attr($s); ?>" <?php selected($row['status'], $s); ?>>
                    <?php echo esc_html(ucfirst($s)); ?></option>
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

  public function handle_export_csv(): void
  {
    check_admin_referer('iqu_export_csv');
    if (!current_user_can('manage_options'))
      wp_die('Permission denied.');
    $form_type = sanitize_text_field($_GET['form_type'] ?? '');
    $args = ['per_page' => 9999, 'page' => 1];
    if ($form_type)
      $args['form_type'] = $form_type;
    $rows = IQU_Database::get_registrations($args);
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="iqu-registrations-' . ($form_type ?: 'all') . '-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ID', 'Form Type', 'First Name', 'Last Name', 'Email', 'Age', 'Country Origin', 'Country Residence', "Qur'an Level", 'Days/Week', 'Preferred Days', 'Time Slot', 'Session Duration', 'Languages', 'WhatsApp', 'Memorized', 'Teacher Pref', 'Device', 'Referral', 'WA Updates', 'Fee Pref', 'Free Request Note', 'Enrollment Level', 'Guardian', 'Guardian Contact', 'Guardian WhatsApp', 'WA Group', 'Admission Fee', 'Payment Method', 'Payment Amount', 'Transaction ID', 'Payment Status', 'Status', 'Registered At']);
    foreach ($rows as $r) {
      fputcsv($out, [$r['id'], $r['form_type'], $r['first_name'], $r['last_name'], $r['email'], $r['age'], $r['country_origin'], $r['country_res'], $r['quran_level'], $r['days_per_week'], $r['preferred_days'], $r['time_slot'], $r['session_dur'], $r['languages'], $r['whatsapp'], $r['memorized'], $r['teacher_pref'], $r['device'], $r['referral'], $r['wa_updates'], $r['fee_pref'], $r['free_request_reason'], $r['enrollment_level'], $r['guardian_name'], $r['guardian_contact'], $r['guardian_whatsapp'], $r['whatsapp_group'], $r['admission_fee'], $r['payment_method'], $r['payment_amount'], $r['transaction_id'], $r['payment_status'], $r['status'], $r['created_at']]);
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