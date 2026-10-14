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

    /** টপ বার — IQU_Admin::top_bar() এর একই ট্যাব সারি। */
    private function render_top_bar(string $title, string $active_page): void
    {
        IQU_Admin::top_bar($title, 'Ilm-ul-Quran USA — Admin Panel', $active_page);
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
<div class="wrap iqu-admin-wrap iqu-cpn-create">

    <?php $this->render_top_bar('Create Coupon', self::PAGE_CREATE); ?>
    <?php $this->render_notice(); ?>

    <?php if ($new_code !== ''): ?>
    <div class="iqu-card iqu-cpn-success" role="status">
        <div class="iqu-card-head">
            <span class="iqu-card-head-title"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span> Coupon created</span>
            <span class="iqu-card-head-badge">Ready to share</span>
        </div>
        <div class="iqu-cpn-success-body">
            <p>Share this code with the student. They enter it on the enrollment form.</p>
            <div class="iqu-code-row">
                <code id="iqu-new-code"><?php echo esc_html($new_code); ?></code>
                <button type="button" class="iqu-btn iqu-btn--secondary" id="iqu-copy-code" aria-describedby="iqu-new-code"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span><span class="iqu-copy-label">Copy code</span></button>
                <span class="screen-reader-text" id="iqu-copy-status" aria-live="polite"></span>
            </div>
            <p class="iqu-fld-hint">
                Each coupon respects its usage limit and stops working automatically
                once that limit is reached.
            </p>
        </div>
    </div>
    <?php endif; ?>

    <form method="POST" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="iqu-cpn-form iqu-cpn-create-form" id="iqu-cpn-create-form" novalidate>
        <input type="hidden" name="action" value="iqu_create_coupon">
        <?php wp_nonce_field('iqu_create_coupon'); ?>

        <div class="iqu-cpn-create-layout">
            <div class="iqu-cpn-create-main">

                <!-- 1. Coupon details -->
                <section class="iqu-card iqu-cpn-section" aria-labelledby="iqu-cpn-s1">
                    <div class="iqu-card-head"><h2 class="iqu-cpn-section-title" id="iqu-cpn-s1">Coupon details</h2><span class="iqu-card-head-badge">The code is generated when you save</span></div>
                    <div class="iqu-cpn-section-body">
                        <div class="iqu-fld">
                            <label for="occasion_name">Occasion name <em>*</em></label>
                            <input type="text" id="occasion_name" name="occasion_name" required maxlength="150"
                                placeholder="Ramadan Zakat Scholarship 2026" aria-describedby="occasion_name-hint occasion_name-err">
                            <p class="iqu-fld-hint" id="occasion_name-hint">Internal label only — students never see this.</p>
                            <p class="iqu-fld-error" id="occasion_name-err" hidden></p>
                        </div>
                        <div class="iqu-fld">
                            <label for="description">Description</label>
                            <textarea id="description" name="description" rows="3" maxlength="500"
                                placeholder="Approved for Fatima R. after reviewing her situation on Messenger."></textarea>
                            <p class="iqu-fld-hint">Optional note for your own records.</p>
                        </div>
                    </div>
                </section>

                <!-- 2. Discount -->
                <section class="iqu-card iqu-cpn-section" aria-labelledby="iqu-cpn-s2">
                    <div class="iqu-card-head"><h2 class="iqu-cpn-section-title" id="iqu-cpn-s2">Discount</h2></div>
                    <div class="iqu-cpn-section-body">
                        <fieldset class="iqu-fld">
                            <legend class="iqu-fld-label">Discount type <em>*</em></legend>
                            <div class="iqu-cpn-seg">
                                <label class="iqu-cpn-seg-opt"><input type="radio" name="discount_type" value="percentage" checked required><span>Percentage (%)</span></label>
                                <label class="iqu-cpn-seg-opt"><input type="radio" name="discount_type" value="fixed"><span>Fixed amount ($)</span></label>
                            </div>
                            <p class="iqu-fld-hint">Percentage scales with the student's chosen days — recommended for scholarships.</p>
                        </fieldset>
                        <div class="iqu-fld">
                            <label for="discount_value">Discount value <em>*</em></label>
                            <div class="iqu-input-group">
                                <input type="number" id="discount_value" name="discount_value" step="0.01" min="0.01" max="100"
                                    required placeholder="100" aria-describedby="iqu-value-hint discount_value-hint discount_value-err">
                                <span class="iqu-input-suffix" id="iqu-value-hint">% off</span>
                            </div>
                            <p class="iqu-fld-hint" id="discount_value-hint">Enter <strong>100</strong> for a full Zakat scholarship.</p>
                            <p class="iqu-fld-error" id="discount_value-err" hidden></p>
                        </div>
                    </div>
                </section>

                <!-- 3. Who can use it -->
                <section class="iqu-card iqu-cpn-section" aria-labelledby="iqu-cpn-s3">
                    <div class="iqu-card-head"><h2 class="iqu-cpn-section-title" id="iqu-cpn-s3">Who can use it</h2></div>
                    <div class="iqu-cpn-section-body">
                        <div class="iqu-fld">
                            <label for="course_type">Applies to</label>
                            <select id="course_type" name="course_type">
                                <option value="">All courses</option>
                                <?php foreach (IQU_Pricing::course_options() as $key => $label): ?>
                                <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="iqu-fld-hint">Restricting the course prevents a Hifz coupon being used for a cheaper Qa'idah enrollment.</p>
                        </div>
                    </div>
                </section>

                <!-- 4. Limits and dates -->
                <section class="iqu-card iqu-cpn-section" aria-labelledby="iqu-cpn-s4">
                    <div class="iqu-card-head"><h2 class="iqu-cpn-section-title" id="iqu-cpn-s4">Limits and dates</h2></div>
                    <div class="iqu-cpn-section-body iqu-cpn-two">
                        <div class="iqu-fld">
                            <label for="max_uses">Usage limit</label>
                            <input type="number" id="max_uses" name="max_uses" min="0" max="9999" step="1" value="1" aria-describedby="max_uses-hint max_uses-err">
                            <p class="iqu-fld-hint" id="max_uses-hint"><strong>1</strong> = single student (recommended). <strong>0</strong> = unlimited — use with care, a shared code could be redeemed endlessly.</p>
                            <p class="iqu-fld-error" id="max_uses-err" hidden></p>
                        </div>
                        <div class="iqu-fld">
                            <label for="expire_date">Expiry date <em>*</em></label>
                            <input type="date" id="expire_date" name="expire_date" required
                                value="<?php echo esc_attr($default_expiry); ?>"
                                min="<?php echo esc_attr(current_time('Y-m-d')); ?>" aria-describedby="expire_date-hint expire_date-err">
                            <p class="iqu-fld-hint" id="expire_date-hint">The coupon stops working after this date.</p>
                            <p class="iqu-fld-error" id="expire_date-err" hidden></p>
                        </div>
                    </div>
                </section>
            </div>

            <!-- Live summary: beside the form on wide screens, below it on phones -->
            <aside class="iqu-card iqu-cpn-summary" aria-labelledby="iqu-cpn-sum-title">
                <div class="iqu-card-head"><h2 class="iqu-cpn-section-title" id="iqu-cpn-sum-title">Summary</h2></div>
                <div class="iqu-cpn-section-body">
                    <p class="iqu-cpn-sum-code"><span class="iqu-fld-hint">Code</span><code>Generated when you save</code></p>
                    <p class="iqu-cpn-sum-line" id="iqu-cpn-sum-line" aria-live="polite">100% off · all courses · 1 use · expires <?php echo esc_html(date('j M Y', strtotime($default_expiry))); ?></p>
                    <dl class="iqu-cpn-sum-list">
                        <div><dt>Discount</dt><dd id="iqu-sum-discount">100% off</dd></div>
                        <div><dt>Course</dt><dd id="iqu-sum-course">All courses</dd></div>
                        <div><dt>Uses</dt><dd id="iqu-sum-uses">1 use</dd></div>
                        <div><dt>Expires</dt><dd id="iqu-sum-expires"><?php echo esc_html(date('j M Y', strtotime($default_expiry))); ?></dd></div>
                    </dl>
                    <div class="iqu-cpn-actions">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_LIST)); ?>" class="iqu-btn iqu-btn--secondary">View all coupons</a>
                        <button type="submit" class="iqu-btn iqu-btn--primary"><span class="dashicons dashicons-tickets-alt" aria-hidden="true"></span>Generate coupon</button>
                    </div>
                </div>
            </aside>
        </div>
    </form>
</div>

<script>
(function () {
    // Copy the new code.
    var copy = document.getElementById('iqu-copy-code');
    if (copy) copy.addEventListener('click', function () {
        var code = document.getElementById('iqu-new-code').textContent.trim();
        var label = copy.querySelector('.iqu-copy-label'), status = document.getElementById('iqu-copy-status');
        var done = function () { label.textContent = 'Copied'; status.textContent = 'Code copied'; setTimeout(function () { label.textContent = 'Copy code'; status.textContent = ''; }, 2000); };
        if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(code).then(done, done); else done();
    });

    var form = document.getElementById('iqu-cpn-create-form');
    if (!form) return;
    var val = document.getElementById('discount_value'), hint = document.getElementById('iqu-value-hint');
    var course = document.getElementById('course_type'), uses = document.getElementById('max_uses'), date = document.getElementById('expire_date');
    var occ = document.getElementById('occasion_name');
    var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    function type() { var r = form.querySelector('input[name="discount_type"]:checked'); return r ? r.value : 'percentage'; }
    function nice(d) { var p = /^(\d{4})-(\d{2})-(\d{2})$/.exec(d || ''); return p ? (+p[3]) + ' ' + months[+p[2] - 1] + ' ' + p[1] : 'no date'; }
    function money(n) { return '$' + n.toFixed(2).replace(/\.00$/, ''); }

    // Live summary, e.g. "100% off · all courses · 3 uses · expires 10 Sep 2026".
    function summary() {
        var pct = type() === 'percentage', n = parseFloat(val.value);
        var disc = isFinite(n) && n > 0 ? (pct ? Math.min(100, n) + '% off' : money(n) + ' off') : (pct ? '—% off' : '$— off');
        var c = course.value === '' ? 'all courses' : course.options[course.selectedIndex].text;
        var u = parseInt(uses.value, 10), usesTxt = u === 0 ? 'unlimited uses' : (isFinite(u) && u > 0 ? u + (u === 1 ? ' use' : ' uses') : '— uses');
        var e = nice(date.value);
        document.getElementById('iqu-cpn-sum-line').textContent = disc + ' · ' + c + ' · ' + usesTxt + ' · expires ' + e;
        document.getElementById('iqu-sum-discount').textContent = disc;
        document.getElementById('iqu-sum-course').textContent = course.value === '' ? 'All courses' : c;
        document.getElementById('iqu-sum-uses').textContent = usesTxt.charAt(0).toUpperCase() + usesTxt.slice(1);
        document.getElementById('iqu-sum-expires').textContent = e;
    }

    // The discount type switches the value's unit and limit (as before).
    function syncType() {
        var pct = type() === 'percentage';
        hint.textContent = pct ? '% off' : 'USD off';
        val.max = pct ? 100 : 10000;
        val.placeholder = pct ? '100' : '25';
        summary();
    }

    // Inline errors under each field: the same rules the server checks.
    function check(input) {
        var msg = '', v = input.value.trim();
        if (input === occ && v === '') msg = 'Enter an occasion name.';
        if (input === val) {
            var n = parseFloat(v);
            if (v === '' || !isFinite(n) || n <= 0) msg = 'Enter a value above 0.';
            else if (type() === 'percentage' && n > 100) msg = 'A percentage cannot be more than 100.';
            else if (type() === 'fixed' && n > 10000) msg = 'Enter an amount up to $10,000.';
        }
        if (input === uses && v !== '' && !/^\d{1,4}$/.test(v)) msg = 'Enter a whole number from 0 to 9999.';
        if (input === date && (v === '' || (date.min && v < date.min))) msg = 'Choose today or a later date.';
        var err = document.getElementById(input.id + '-err');
        if (err) { err.textContent = msg; err.hidden = msg === ''; }
        input.setAttribute('aria-invalid', msg ? 'true' : 'false');
        return msg === '';
    }
    [occ, val, uses, date].forEach(function (i) { i.addEventListener('blur', function () { check(i); }); i.addEventListener('input', function () { if (i.getAttribute('aria-invalid') === 'true') check(i); summary(); }); });
    form.querySelectorAll('input[name="discount_type"]').forEach(function (r) { r.addEventListener('change', function () { syncType(); if (val.value !== '') check(val); }); });
    course.addEventListener('change', summary);
    date.addEventListener('change', summary);
    form.addEventListener('submit', function (e) {
        var bad = [occ, val, uses, date].filter(function (i) { return !check(i); });
        if (bad.length) { e.preventDefault(); bad[0].focus(); }
    });
    syncType();
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

        // Filter tab = the coupon's state (IQU_Coupon::effective_status). "disabled" is the
        // value older links used for the same thing.
        $state = sanitize_key($_GET['status'] ?? '');
        if ($state === IQU_Coupon_DB::STATUS_DISABLED) $state = IQU_Coupon::STATE_INACTIVE;
        if (!isset(IQU_Coupon::STATE_LABELS[$state])) $state = '';
        $course_type = sanitize_text_field($_GET['course_type'] ?? '');
        $search      = sanitize_text_field($_GET['search'] ?? '');
        $paged       = max(1, absint($_GET['paged'] ?? 1));

        $allowed_per_page = [10, 20, 50];
        $raw_per_page     = isset($_GET['per_page']) ? (int) $_GET['per_page'] : 20;
        $per_page         = in_array($raw_per_page, $allowed_per_page, true) ? $raw_per_page : 20;

        $data  = IQU_Coupon_DB::get_coupons([
            'state'       => $state,
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

    <!-- ── Metric Cards: icon, number, label, and what the number means ── -->
    <?php
        $students = fn(int $n) => $n . ' ' . ($n === 1 ? 'student' : 'students');
        $cards = [
            ['total', 'tag', (int) $stats['total'], 'Total coupons', (int) $stats['total'] . ' created'],
            ['active', 'yes-alt', (int) $stats['active'], 'Active', 'can be used now'],
            ['used_up', 'dismiss', (int) $stats['used_up'], 'Used up', 'all uses taken — create a new one if needed'],
            ['expired', 'clock', (int) $stats['expired'], 'Expired', 'end date has passed'],
            ['redeemed', 'tickets-alt', (int) $stats['redeemed'], 'Times redeemed',
                (int) $stats['unlimited'] > 0 || (int) $stats['capacity'] === 0 ? 'across all coupons' : (int) $stats['redeemed'] . ' of ' . (int) $stats['capacity'] . ' uses'],
        ];
    ?>
    <div class="iqu-metrics iqu-metrics--coupons">
        <?php foreach ($cards as [$key, $icon, $num, $label, $line]): ?>
        <div class="iqu-metric iqu-cpn-card iqu-cpn-card--<?php echo esc_attr($key); ?>">
            <div class="iqu-metric-accent iqu-metric-accent--<?php echo esc_attr(['total' => 'total', 'active' => 'free', 'used_up' => 'usedup', 'expired' => 'expired', 'redeemed' => 'redeemed'][$key]); ?>"></div>
            <span class="iqu-cpn-icon" aria-hidden="true"><span class="dashicons dashicons-<?php echo esc_attr($icon); ?>"></span></span>
            <div class="iqu-metric-num"><?php echo (int) $num; ?></div>
            <div class="iqu-metric-lbl"><?php echo esc_html($label); ?></div>
            <div class="iqu-cpn-line"><?php echo esc_html($line); ?></div>
            <?php if ($key === 'active' && $num === 0): ?>
            <p class="iqu-cpn-note">No coupon can be used right now. <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_CREATE)); ?>">Create a coupon</a></p>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

    <!-- Discount summary — inside the grid: two columns wide on wide screens, full width below -->
    <div class="iqu-metric iqu-discount-card">
        <div class="iqu-metric-accent iqu-metric-accent--discount"></div>
        <div class="iqu-discount-head">
            <span class="iqu-discount-title"><span class="dashicons dashicons-tag" aria-hidden="true"></span> Total Discount Given</span>
            <span class="iqu-discount-total">
                <?php echo esc_html(IQU_Pricing::format($discounts['total'])); ?>
            </span>
        </div>
        <ul class="iqu-discount-rows">
            <?php foreach ([
                ['full', 'Full Scholarship'], ['special', 'Special Discount'], ['percent', 'Percentage Coupon'], ['fixed', 'Fixed Amount Coupon'],
            ] as [$k, $label]):
                $amt   = (float) $discounts[$k];
                $share = $discounts['total'] > 0 ? (int) round($amt / $discounts['total'] * 100) : 0; ?>
            <li class="iqu-discount-row">
                <span class="iqu-discount-lbl"><?php echo esc_html($label); ?></span>
                <span class="iqu-discount-amt"><?php echo esc_html(IQU_Pricing::format($amt)); ?></span>
                <span class="iqu-discount-bar" role="img" aria-label="<?php echo esc_attr($share . '% of the total'); ?>"><span style="width:<?php echo (int) $share; ?>%"></span></span>
                <span class="iqu-discount-sub"><?php echo esc_html($share . '% · ' . $students((int) $discounts[$k . '_count'])); ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    </div>

    <!-- ── State tabs: same counts as the cards (IQU_Coupon::effective_status) ── -->
    <?php
        $tab_url = fn(string $s) => add_query_arg(array_filter([
            'page' => self::PAGE_LIST, 'status' => $s, 'search' => $search, 'course_type' => $course_type,
            'per_page' => $per_page !== 20 ? $per_page : null,
        ], fn($v) => $v !== null && $v !== ''), admin_url('admin.php'));
        $tabs = ['' => ['All', (int) $stats['total']]];
        foreach (IQU_Coupon::STATE_LABELS as $k => $label) $tabs[$k] = [$label, (int) $stats[$k]];
    ?>
    <nav class="iqu-cpn-tabs" aria-label="Filter coupons by state">
        <?php foreach ($tabs as $k => [$label, $n]): ?>
        <a class="iqu-cpn-tab iqu-cpn-tab--<?php echo esc_attr($k !== '' ? $k : 'all'); ?>" href="<?php echo esc_url($tab_url($k)); ?>"<?php echo $state === $k ? ' aria-current="page"' : ''; ?>>
            <?php echo esc_html($label); ?> <span class="iqu-cpn-tab-count"><?php echo (int) $n; ?></span>
        </a>
        <?php endforeach; ?>
    </nav>

    <!-- ── Filter Bar ───────────────────────────────── -->
    <form method="GET" class="iqu-filter-bar">
        <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_LIST); ?>">
        <?php if ($state !== ''): ?><input type="hidden" name="status" value="<?php echo esc_attr($state); ?>"><?php endif; ?>
        <input type="search" name="search" value="<?php echo esc_attr($search); ?>"
            placeholder="Search code or occasion…">
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
        <a href="<?php echo esc_url($export_url); ?>" class="iqu-export-btn"><span class="dashicons dashicons-download" aria-hidden="true"></span>Export CSV</a>
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
                                    $row_state  = IQU_Coupon::effective_status($row, $today);
                                    $is_active  = $row_state !== IQU_Coupon::STATE_INACTIVE; // Enable / Disable button

                                    $toggle_url = wp_nonce_url(
                                        admin_url('admin-post.php?action=iqu_toggle_coupon&coupon_id=' . $id),
                                        'iqu_toggle_coupon_' . $id
                                    );
                                    $delete_url = wp_nonce_url(
                                        admin_url('admin-post.php?action=iqu_delete_coupon&coupon_id=' . $id),
                                        'iqu_delete_coupon_' . $id
                                    );

                                    // ব্যাজ: নিষ্ক্রিয় > মেয়াদোত্তীর্ণ > সীমা শেষ > সক্রিয় (IQU_Coupon::effective_status)
                                    $badge_class = [
                                        IQU_Coupon::STATE_ACTIVE   => 'iqu-sb-confirmed',
                                        IQU_Coupon::STATE_USED_UP  => 'iqu-sb-contacted',
                                        IQU_Coupon::STATE_EXPIRED  => 'iqu-sb-cancelled',
                                        IQU_Coupon::STATE_INACTIVE => 'iqu-sb-cancelled',
                                    ][$row_state];
                                    $badge = '<span class="iqu-status-badge ' . esc_attr($badge_class) . '" data-state="' . esc_attr($row_state) . '">'
                                        . esc_html(IQU_Coupon::STATE_LABELS[$row_state]) . '</span>';

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
                IQU_Coupon::STATE_LABELS[IQU_Coupon::effective_status($r)], // Active, Used up, Expired or Disabled
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