<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Coupon_Admin
 *
 * 🎟️ কুপন ম্যানেজমেন্টের দুটো অ্যাডমিন স্ক্রিন:
 *   - Create Coupon  (iqu-coupon-create)
 *   - Coupon List    (iqu-coupon-list)
 *
 * 🔒 Security:
 * - প্রতিটি অ্যাকশনে current_user_can('manage_options') চেক
 * - সব ফর্ম ও লিংকে nonce যাচাই
 * - আউটপুটে esc_html / esc_attr / esc_url
 * - ডেটাবেজ কাজ সব IQU_Coupon_DB-এর prepared statement দিয়ে
 *
 * CSS পুরোটাই বিদ্যমান admin.css-এর ক্লাস পুনঃব্যবহার করে,
 * তাই নতুন স্টাইলশিট লাগে না।
 */
class IQU_Coupon_Admin
{
    public const PAGE_CREATE = 'iqu-coupon-create';
    public const PAGE_LIST   = 'iqu-coupon-list';

    /** কুপন কত দিন পরে মেয়াদ শেষ হবে (ডিফল্ট) */
    private const DEFAULT_EXPIRY_DAYS = 30;

    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_post_iqu_create_coupon', [$this, 'handle_create']);
        add_action('admin_post_iqu_toggle_coupon', [$this, 'handle_toggle']);
        add_action('admin_post_iqu_delete_coupon', [$this, 'handle_delete']);
        add_action('wp_ajax_iqu_export_coupons', [$this, 'handle_export_csv']);
    }

    // ════════════════════════════════════════════════════
    // MENU
    // ════════════════════════════════════════════════════

    public function register_menu(): void
    {
        add_submenu_page(
            'iqu-registrations',
            'Create Coupon — IQU',
            'Create Coupon',
            'manage_options',
            self::PAGE_CREATE,
            [$this, 'render_create_page']
        );

        add_submenu_page(
            'iqu-registrations',
            'Coupon List — IQU',
            'Coupon List',
            'manage_options',
            self::PAGE_LIST,
            [$this, 'render_list_page']
        );
    }

    // ════════════════════════════════════════════════════
    // SHARED UI
    // ════════════════════════════════════════════════════

    /**
     * টপ বার — IQU_Admin::render_top_bar() private হওয়ায় এখানে
     * একই CSS ক্লাস দিয়ে সমতুল্য মার্কআপ।
     */
    private function render_top_bar(string $title, string $active_page): void
    {
        $tabs = [
            'iqu-registrations'  => 'All',
            'iqu-list-free'      => 'Free',
            'iqu-list-summer-l1' => 'Level 1',
            'iqu-list-summer-l2' => 'Level 2',
            self::PAGE_CREATE    => 'Create Coupon',
            self::PAGE_LIST      => 'Coupon List',
            'iqu-zeffy-payments' => 'Zeffy Payments',
        ];
?>
<div class="iqu-top-bar">
    <div class="iqu-top-bar-left">
        <div class="iqu-logo-mark">
            <img src="https://ilmulquranus.org/wp-content/uploads/2025/08/Favicon.png" alt="IQU Logo" width="44"
                height="39" style="display:block" />
        </div>
        <div>
            <div class="iqu-page-title"><?php echo esc_html($title); ?></div>
            <div class="iqu-page-sub">Ilm-ul-Quran USA — Admin Panel</div>
        </div>
    </div>
    <div class="iqu-top-bar-right">
        <span class="iqu-badge-live">● Live</span>
        <div class="iqu-tabs">
            <?php foreach ($tabs as $slug => $label): ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=' . $slug)); ?>"
                class="iqu-tab <?php echo ($active_page === $slug) ? 'iqu-tab-active' : ''; ?>">
                <?php echo esc_html($label); ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php
    }

    /** URL প্যারামিটার থেকে নোটিশ */
    private function render_notice(): void
    {
        $msg = sanitize_text_field($_GET['iqu_msg'] ?? '');
        if ($msg === '') return;

        $map = [
            'created'   => ['success', 'Coupon created successfully.'],
            'enabled'   => ['success', 'Coupon enabled.'],
            'disabled'  => ['success', 'Coupon disabled.'],
            'deleted'   => ['success', 'Coupon deleted.'],
            'error'     => ['error',   'Something went wrong. Please try again.'],
            'gen_fail'  => ['error',   'Could not generate a unique coupon code. Please try again.'],
            'not_found' => ['error',   'Coupon not found.'],
        ];

        if (!isset($map[$msg])) return;

        [$type, $text] = $map[$msg];
        $class = $type === 'success' ? 'notice-success' : 'notice-error';

        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>'
            . esc_html($text) . '</p></div>';
    }

    // ════════════════════════════════════════════════════
    // CREATE PAGE
    // ════════════════════════════════════════════════════

    public function render_create_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied.');
        }

        // সদ্য তৈরি কোড — redirect-এর পরে দেখানোর জন্য
        $new_code = sanitize_text_field($_GET['new_code'] ?? '');

        $default_expiry = date('Y-m-d', strtotime('+' . self::DEFAULT_EXPIRY_DAYS . ' days', current_time('timestamp')));
    ?>
<div class="wrap iqu-admin-wrap">

    <?php $this->render_top_bar('Create Coupon', self::PAGE_CREATE); ?>
    <?php $this->render_notice(); ?>

    <?php if ($new_code !== ''): ?>
    <div class="iqu-card iqu-cpn-success">
        <div class="iqu-card-head">
            <span class="iqu-card-head-title">✅ Coupon Created</span>
            <span class="iqu-card-head-badge">Ready to share</span>
        </div>
        <div class="iqu-cpn-success-body">
            <p>Share this code with the student. They will enter it on the enrollment form.</p>
            <div class="iqu-code-row">
                <code id="iqu-new-code"><?php echo esc_html($new_code); ?></code>
                <button type="button" class="iqu-btn-ghost" onclick="
                    navigator.clipboard.writeText(document.getElementById('iqu-new-code').textContent.trim());
                    this.textContent='✓ Copied';
                    setTimeout(()=>{this.textContent='Copy Code';},2000);
                ">Copy Code</button>
            </div>
            <p class="iqu-fld-hint">
                Each coupon respects its usage limit and stops working automatically
                once that limit is reached.
            </p>
        </div>
    </div>
    <?php endif; ?>

    <div class="iqu-card">
        <div class="iqu-card-head">
            <span class="iqu-card-head-title">🎟️ Coupon Details</span>
            <span class="iqu-card-head-badge">Code generated automatically</span>
        </div>

        <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="iqu-cpn-form">
            <input type="hidden" name="action" value="iqu_create_coupon">
            <?php wp_nonce_field('iqu_create_coupon'); ?>

            <div class="iqu-fld-grid">

                <!-- Occasion — পুরো প্রস্থ -->
                <div class="iqu-fld iqu-fld--full">
                    <label for="occasion_name">Occasion Name <em>*</em></label>
                    <input type="text" id="occasion_name" name="occasion_name" required maxlength="150"
                        placeholder="Ramadan Zakat Scholarship 2026">
                    <p class="iqu-fld-hint">Internal label only — students never see this.</p>
                </div>

                <!-- Applies To -->
                <div class="iqu-fld">
                    <label for="course_type">Applies To</label>
                    <select id="course_type" name="course_type">
                        <option value="">All Courses</option>
                        <?php foreach (IQU_Pricing::course_options() as $key => $label): ?>
                        <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="iqu-fld-hint">
                        Restricting the course prevents a Hifz coupon being used
                        for a cheaper Qa'idah enrollment.
                    </p>
                </div>

                <!-- Coupon Type -->
                <div class="iqu-fld">
                    <label for="discount_type">Coupon Type <em>*</em></label>
                    <select id="discount_type" name="discount_type" required>
                        <option value="percentage">Percentage (%)</option>
                        <option value="fixed">Fixed Amount ($)</option>
                    </select>
                    <p class="iqu-fld-hint">
                        Percentage scales with the student's chosen days —
                        recommended for scholarships.
                    </p>
                </div>

                <!-- Coupon Value — সংখ্যা + সাফিক্স একসাথে -->
                <div class="iqu-fld">
                    <label for="discount_value">Coupon Value <em>*</em></label>
                    <div class="iqu-input-group">
                        <input type="number" id="discount_value" name="discount_value" step="0.01" min="0.01" max="100"
                            required placeholder="100">
                        <span class="iqu-input-suffix" id="iqu-value-hint">% off</span>
                    </div>
                    <p class="iqu-fld-hint">Enter <strong>100</strong> for a full Zakat scholarship.</p>
                </div>

                <!-- Expire Date -->
                <div class="iqu-fld">
                    <label for="expire_date">Expire Date <em>*</em></label>
                    <input type="date" id="expire_date" name="expire_date" required
                        value="<?php echo esc_attr($default_expiry); ?>"
                        min="<?php echo esc_attr(current_time('Y-m-d')); ?>">
                    <p class="iqu-fld-hint">The coupon stops working after this date.</p>
                </div>

                <!-- Usage Limit -->
                <div class="iqu-fld iqu-fld--full">
                    <label for="max_uses">Usage Limit</label>
                    <input type="number" id="max_uses" name="max_uses" min="0" max="9999" value="1"
                        class="iqu-fld-narrow">
                    <p class="iqu-fld-hint">
                        <strong>1</strong> = single student (recommended).
                        <strong>0</strong> = unlimited — use with care, a shared
                        code could be redeemed endlessly.
                    </p>
                </div>

                <!-- Description -->
                <div class="iqu-fld iqu-fld--full">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" rows="3" maxlength="500"
                        placeholder="Approved for Fatima R. after reviewing her situation on Messenger."></textarea>
                    <p class="iqu-fld-hint">Optional note for your own records.</p>
                </div>
            </div>

            <div class="iqu-cpn-actions">
                <button type="submit" class="iqu-btn-primary">Generate Coupon</button>
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_LIST)); ?>"
                    class="iqu-btn-ghost">View All Coupons</a>
            </div>
        </form>
    </div>
</div>

<script>
(function() {
    var type = document.getElementById('discount_type');
    var hint = document.getElementById('iqu-value-hint');
    var val = document.getElementById('discount_value');
    if (!type || !hint) return;
    type.addEventListener('change', function() {
        var pct = this.value === 'percentage';
        hint.textContent = pct ? '% off' : 'USD off';
        val.max = pct ? 100 : 10000;
        val.placeholder = pct ? '100' : '25';
    });
})();
</script>
<?php
    }

    // ════════════════════════════════════════════════════
    // LIST PAGE
    // ════════════════════════════════════════════════════

    public function render_list_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied.');
        }

        $status      = sanitize_text_field($_GET['status'] ?? '');
        $course_type = sanitize_text_field($_GET['course_type'] ?? '');
        $search      = sanitize_text_field($_GET['search'] ?? '');
        $paged       = max(1, absint($_GET['paged'] ?? 1));

        $allowed_per_page = [10, 20, 50];
        $raw_per_page     = isset($_GET['per_page']) ? (int) $_GET['per_page'] : 20;
        $per_page         = in_array($raw_per_page, $allowed_per_page, true) ? $raw_per_page : 20;

        $data  = IQU_Coupon_DB::get_coupons([
            'status'      => $status,
            'course_type' => $course_type,
            'search'      => $search,
            'per_page'    => $per_page,
            'paged'       => $paged,
        ]);
        $rows  = $data['items'];
        $total = $data['total'];
        $pages = (int) ceil($total / $per_page);
        $stats     = IQU_Coupon_DB::get_stats();
        $discounts = IQU_Coupon_DB::get_discount_summary();

        $today = current_time('Y-m-d');

        $export_url = admin_url(
            'admin-ajax.php?action=iqu_export_coupons&_wpnonce=' . wp_create_nonce('iqu_export_coupons')
        );
    ?>
<div class="wrap iqu-admin-wrap">

    <?php $this->render_top_bar('Coupon List', self::PAGE_LIST); ?>
    <?php $this->render_notice(); ?>

    <!-- ── Metric Cards ─────────────────────────────── -->
    <div class="iqu-metrics">
        <div class="iqu-metric">
            <div class="iqu-metric-accent iqu-metric-accent--total"></div>
            <div class="iqu-metric-num"><?php echo (int) $stats['total']; ?></div>
            <div class="iqu-metric-lbl">Total Coupons</div>
        </div>
        <div class="iqu-metric">
            <div class="iqu-metric-accent iqu-metric-accent--free"></div>
            <div class="iqu-metric-num"><?php echo (int) $stats['active']; ?></div>
            <div class="iqu-metric-lbl">✅ Active</div>
        </div>
        <div class="iqu-metric">
            <div class="iqu-metric-accent iqu-metric-accent--expired"></div>
            <div class="iqu-metric-num"><?php echo (int) $stats['expired']; ?></div>
            <div class="iqu-metric-lbl">⌛ Expired</div>
        </div>
        <div class="iqu-metric">
            <div class="iqu-metric-accent iqu-metric-accent--redeemed"></div>
            <div class="iqu-metric-num"><?php echo (int) $stats['redeemed']; ?></div>
            <div class="iqu-metric-lbl">🎟️ Times Redeemed</div>
        </div>

    </div>
    <!-- 💸 Discount Summary — গ্রিডের পুরো প্রস্থ জুড়ে
             (মোবাইলে উপরের দুই কার্ডের সমান চওড়া) -->
    <div class="iqu-metric iqu-discount-card">
        <div class="iqu-metric-accent iqu-metric-accent--discount"></div>
        <div class="iqu-discount-head">
            <span class="iqu-discount-title">💸 Total Discount Given</span>
            <span class="iqu-discount-total">
                <?php echo esc_html(IQU_Pricing::format($discounts['total'])); ?>
            </span>
        </div>
        <div class="iqu-discount-grid">
            <div class="iqu-discount-item">
                <div class="iqu-discount-amt">
                    <?php echo esc_html(IQU_Pricing::format($discounts['full'])); ?>
                </div>
                <div class="iqu-discount-lbl">🎓 Full Scholarship</div>
                <div class="iqu-discount-sub">
                    <?php echo (int) $discounts['full_count']; ?> student(s)
                </div>
            </div>
            <div class="iqu-discount-item">
                <div class="iqu-discount-amt">
                    <?php echo esc_html(IQU_Pricing::format($discounts['special'])); ?>
                </div>
                <div class="iqu-discount-lbl">🎁 Special Discount</div>
                <div class="iqu-discount-sub">
                    <?php echo (int) $discounts['special_count']; ?> student(s)
                </div>
            </div>
            <div class="iqu-discount-item">
                <div class="iqu-discount-amt">
                    <?php echo esc_html(IQU_Pricing::format($discounts['percent'])); ?>
                </div>
                <div class="iqu-discount-lbl">％ Percentage Coupon</div>
                <div class="iqu-discount-sub">
                    <?php echo (int) $discounts['percent_count']; ?> student(s)
                </div>
            </div>
            <div class="iqu-discount-item">
                <div class="iqu-discount-amt">
                    <?php echo esc_html(IQU_Pricing::format($discounts['fixed'])); ?>
                </div>
                <div class="iqu-discount-lbl">💵 Fixed Amount Coupon</div>
                <div class="iqu-discount-sub">
                    <?php echo (int) $discounts['fixed_count']; ?> student(s)
                </div>
            </div>
        </div>
    </div>

    <!-- ── Filter Bar ───────────────────────────────── -->
    <form method="GET" class="iqu-filter-bar">
        <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_LIST); ?>">
        <input type="search" name="search" value="<?php echo esc_attr($search); ?>"
            placeholder="Search code or occasion…">
        <select name="status">
            <option value="">All statuses</option>
            <option value="active" <?php selected($status, 'active'); ?>>Active</option>
            <option value="disabled" <?php selected($status, 'disabled'); ?>>Disabled</option>
        </select>
        <select name="course_type">
            <option value="">All courses</option>
            <?php foreach (IQU_Pricing::course_options() as $key => $label): ?>
            <option value="<?php echo esc_attr($key); ?>" <?php selected($course_type, $key); ?>>
                <?php echo esc_html($label); ?>
            </option>
            <?php endforeach; ?>
        </select>
        <select name="per_page" onchange="this.form.submit()">
            <?php foreach ($allowed_per_page as $n): ?>
            <option value="<?php echo (int) $n; ?>" <?php selected($per_page, $n); ?>>
                <?php echo (int) $n; ?> per page
            </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="button">Filter</button>
        <a href="<?php echo esc_url($export_url); ?>" class="iqu-export-btn">⬇ Export CSV</a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_CREATE)); ?>"
            class="button button-primary">+ New Coupon</a>
    </form>

    <!-- ── Table ────────────────────────────────────── -->
    <div class="iqu-card">
        <div class="iqu-card-head">
            <span class="iqu-card-head-title">All Coupons</span>
            <span class="iqu-card-head-badge"><?php echo (int) $total; ?> total</span>
        </div>
        <div class="iqu-table-wrap">
            <table class="iqu-tbl iqu-coupon-tbl">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Occasion</th>
                        <th>Course</th>
                        <th>Discount</th>
                        <th>Expires</th>
                        <th>Uses</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="9" class="iqu-empty-state">
                            No coupons found. <a
                                href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_CREATE)); ?>">Create
                                your first one.</a>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($rows as $row):
                                    $id         = (int) $row['id'];
                                    $is_expired = !empty($row['expire_date']) && $row['expire_date'] < $today;
                                    $max_uses   = (int) $row['max_uses'];
                                    $used       = (int) $row['used_count'];
                                    $exhausted  = $max_uses > 0 && $used >= $max_uses;
                                    $is_active  = $row['status'] === IQU_Coupon_DB::STATUS_ACTIVE;

                                    $toggle_url = wp_nonce_url(
                                        admin_url('admin-post.php?action=iqu_toggle_coupon&coupon_id=' . $id),
                                        'iqu_toggle_coupon_' . $id
                                    );
                                    $delete_url = wp_nonce_url(
                                        admin_url('admin-post.php?action=iqu_delete_coupon&coupon_id=' . $id),
                                        'iqu_delete_coupon_' . $id
                                    );

                                    // ব্যাজ: নিষ্ক্রিয় > মেয়াদোত্তীর্ণ > সীমা শেষ > সক্রিয়
                                    if (!$is_active) {
                                        $badge = '<span class="iqu-status-badge iqu-sb-cancelled">Disabled</span>';
                                    } elseif ($is_expired) {
                                        $badge = '<span class="iqu-status-badge iqu-sb-cancelled">Expired</span>';
                                    } elseif ($exhausted) {
                                        $badge = '<span class="iqu-status-badge iqu-sb-contacted">Used Up</span>';
                                    } else {
                                        $badge = '<span class="iqu-status-badge iqu-sb-confirmed">Active</span>';
                                    }

                                    $discount = $row['discount_type'] === IQU_Coupon_DB::TYPE_PERCENTAGE
                                        ? rtrim(rtrim(number_format((float) $row['discount_value'], 2), '0'), '.') . '%'
                                        : '$' . number_format((float) $row['discount_value'], 2);
                                ?>
                    <tr>
                        <td>
                            <span style="display:flex;align-items:center;gap:6px;">
                                <code style="font-weight:600;"><?php echo esc_html($row['code']); ?></code>
                                <button type="button" class="iqu-copy-btn" title="Copy code" onclick="
                                navigator.clipboard.writeText('<?php echo esc_js($row['code']); ?>');
                                var btn=this;
                                btn.classList.add('iqu-copied');
                                btn.innerHTML='<svg xmlns=&quot;http://www.w3.org/2000/svg&quot; width=&quot;14&quot; height=&quot;14&quot; viewBox=&quot;0 0 24 24&quot; fill=&quot;none&quot; stroke=&quot;#1a8a45&quot; stroke-width=&quot;3&quot; stroke-linecap=&quot;round&quot; stroke-linejoin=&quot;round&quot;><polyline points=&quot;20 6 9 17 4 12&quot;></polyline></svg>';
                                setTimeout(function(){
                                    btn.classList.remove('iqu-copied');
                                    btn.innerHTML='<svg xmlns=&quot;http://www.w3.org/2000/svg&quot; width=&quot;14&quot; height=&quot;14&quot; viewBox=&quot;0 0 24 24&quot; fill=&quot;none&quot; stroke=&quot;currentColor&quot; stroke-width=&quot;2&quot; stroke-linecap=&quot;round&quot; stroke-linejoin=&quot;round&quot;><rect x=&quot;9&quot; y=&quot;9&quot; width=&quot;13&quot; height=&quot;13&quot; rx=&quot;2&quot; ry=&quot;2&quot;></rect><path d=&quot;M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1&quot;></path></svg>';
                                },1200);
                                " "><svg xmlns=" http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24"
                                    fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round">
                                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                                    </svg>
                                </button>
                            </span>
                        </td>
                        <td><?php echo esc_html($row['occasion_name']); ?></td>
                        <td>
                            <?php echo $row['course_type'] === ''
                                                ? '<em>All courses</em>'
                                                : esc_html(IQU_Pricing::label($row['course_type'])); ?>
                        </td>
                        <td><strong><?php echo esc_html($discount); ?></strong>
                        </td>
                        <td<?php echo $is_expired ? ' style="color:#d63638;"' : ''; ?>>
                            <?php echo esc_html($row['expire_date'] ? date('d-m-Y', strtotime($row['expire_date'])) : '—'); ?>
                            </td>
                            <td>
                                <?php echo (int) $used; ?> /
                                <?php echo $max_uses === 0 ? '∞' : (int) $max_uses; ?>
                            </td>
                            <td><?php echo $badge; // ভেতরের ভ্যালু আগেই esc করা 
                                                ?></td>
                            <td><?php echo esc_html(mysql2date('j M, Y', $row['created_at'])); ?></td>
                            <td class="iqu-td-actions">
                                <a href="<?php echo esc_url($toggle_url); ?>"
                                    class="iqu-action-btn iqu-action-btn--view">
                                    <?php echo $is_active ? 'Disable' : 'Enable'; ?>
                                </a>
                                <!-- ⚠️ ক্লাসটি iqu-coupon-del-btn — iqu-delete-btn নয়।
                             admin.js-এর গ্লোবাল হ্যান্ডলার ওই ক্লাসে রেজিস্ট্রেশন
                             ডিলিট করে, কুপন নয়। -->
                                <a href="<?php echo esc_url($delete_url); ?>"
                                    class="iqu-action-btn iqu-action-btn--delete iqu-coupon-del-btn"
                                    onclick="return confirm('Delete this coupon permanently? This cannot be undone.');">
                                    Delete
                                </a>
                            </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ── Pagination ───────────────────────────────── -->
    <?php if ($pages > 1): ?>
    <div class="iqu-pagination">
        <span><?php echo (int) $total; ?> items</span>
        <div>
            <?php
            echo paginate_links([
                'base'      => add_query_arg('paged', '%#%'),
                'format'    => '',
                'current'   => $paged,
                'total'     => $pages,
                'prev_text' => '‹',
                'next_text' => '›',
                'end_size'  => 2,
                'mid_size'  => 3,
            ]);
            ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php

    }

    // ════════════════════════════════════════════════════
    // ACTIONS
    // ════════════════════════════════════════════════════

    public function handle_create(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied.');
        }
        check_admin_referer('iqu_create_coupon');

        $occasion    = sanitize_text_field($_POST['occasion_name'] ?? '');
        $course_type = sanitize_text_field($_POST['course_type'] ?? '');
        $type        = sanitize_text_field($_POST['discount_type'] ?? '');
        $value       = (float) ($_POST['discount_value'] ?? 0);
        $expire      = sanitize_text_field($_POST['expire_date'] ?? '');
        $max_uses    = absint($_POST['max_uses'] ?? 1);
        $description = sanitize_textarea_field($_POST['description'] ?? '');

        // ── ভ্যালিডেশন ──
        $valid_types = [IQU_Coupon_DB::TYPE_PERCENTAGE, IQU_Coupon_DB::TYPE_FIXED];

        if (
            $occasion === ''
            || !in_array($type, $valid_types, true)
            || $value <= 0
            || ($course_type !== '' && !IQU_Pricing::is_valid_course($course_type))
        ) {
            $this->redirect_create('error');
        }

        // শতকরা কখনোই ১০০-এর বেশি নয়
        if ($type === IQU_Coupon_DB::TYPE_PERCENTAGE) {
            $value = min(100.00, $value);
        }

        // তারিখ যাচাই — অতীতের তারিখ গ্রহণ করা হবে না
        $expire_clean = null;
        if ($expire !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $expire)) {
            if ($expire >= current_time('Y-m-d')) {
                $expire_clean = $expire;
            }
        }
        if ($expire_clean === null) {
            $this->redirect_create('error');
        }

        $code = IQU_Coupon::generate_code($course_type, $type, $value);
        if ($code === '') {
            $this->redirect_create('gen_fail');
        }

        $id = IQU_Coupon_DB::insert([
            'code'           => $code,
            'occasion_name'  => $occasion,
            'course_type'    => $course_type,
            'discount_type'  => $type,
            'discount_value' => $value,
            'expire_date'    => $expire_clean,
            'max_uses'       => $max_uses,
            'used_count'     => 0,
            'status'         => IQU_Coupon_DB::STATUS_ACTIVE,
            'description'    => $description,
            'created_by'     => get_current_user_id(),
        ]);

        if (!$id) {
            $this->redirect_create('error');
        }

        wp_safe_redirect(add_query_arg(
            [
                'page'     => self::PAGE_CREATE,
                'iqu_msg'  => 'created',
                'new_code' => rawurlencode($code),
            ],
            admin_url('admin.php')
        ));
        exit;
    }

    public function handle_toggle(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied.');
        }

        $id = absint($_GET['coupon_id'] ?? 0);
        check_admin_referer('iqu_toggle_coupon_' . $id);

        $coupon = IQU_Coupon_DB::get($id);
        if (!$coupon) {
            $this->redirect_list('not_found');
        }

        $new_status = $coupon['status'] === IQU_Coupon_DB::STATUS_ACTIVE
            ? IQU_Coupon_DB::STATUS_DISABLED
            : IQU_Coupon_DB::STATUS_ACTIVE;

        IQU_Coupon_DB::update($id, ['status' => $new_status]);

        $this->redirect_list(
            $new_status === IQU_Coupon_DB::STATUS_ACTIVE ? 'enabled' : 'disabled'
        );
    }

    public function handle_delete(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied.');
        }

        $id = absint($_GET['coupon_id'] ?? 0);
        check_admin_referer('iqu_delete_coupon_' . $id);

        if (!IQU_Coupon_DB::get($id)) {
            $this->redirect_list('not_found');
        }

        IQU_Coupon_DB::delete($id);
        $this->redirect_list('deleted');
    }

    public function handle_export_csv(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied.');
        }
        check_ajax_referer('iqu_export_coupons');

        $data = IQU_Coupon_DB::get_coupons(['per_page' => 10000, 'paged' => 1]);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=iqu-coupons-'
            . current_time('Y-m-d') . '.csv');

        $out = fopen('php://output', 'w');

        fputcsv($out, [
            'ID',
            'Code',
            'Occasion',
            'Course',
            'Discount Type',
            'Discount Value',
            'Expire Date',
            'Max Uses',
            'Used Count',
            'Status',
            'Description',
            'Created',
        ]);

        foreach ($data['items'] as $r) {
            fputcsv($out, [
                $r['id'],
                // 🔒 CSV formula injection সুরক্ষা
                self::csv_safe($r['code']),
                self::csv_safe($r['occasion_name']),
                $r['course_type'] === '' ? 'All Courses' : IQU_Pricing::label($r['course_type']),
                $r['discount_type'],
                $r['discount_value'],
                $r['expire_date'],
                $r['max_uses'],
                $r['used_count'],
                $r['status'],
                self::csv_safe($r['description']),
                $r['created_at'],
            ]);
        }

        fclose($out);
        exit;
    }

    // ════════════════════════════════════════════════════
    // HELPERS
    // ════════════════════════════════════════════════════

    /**
     * Excel/Sheets-এ সেল `=`, `+`, `-`, `@` দিয়ে শুরু হলে সূত্র হিসেবে
     * চলে — সামনে একটা apostrophe দিয়ে তা আটকানো হয়।
     */
    private static function csv_safe(?string $value): string
    {
        $value = (string) $value;
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }
        return $value;
    }

    private function redirect_create(string $msg): void
    {
        wp_safe_redirect(add_query_arg(
            ['page' => self::PAGE_CREATE, 'iqu_msg' => $msg],
            admin_url('admin.php')
        ));
        exit;
    }

    private function redirect_list(string $msg): void
    {
        wp_safe_redirect(add_query_arg(
            ['page' => self::PAGE_LIST, 'iqu_msg' => $msg],
            admin_url('admin.php')
        ));
        exit;
    }
}