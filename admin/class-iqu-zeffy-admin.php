<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Zeffy_Admin
 *
 * Standalone admin screen for Zeffy donation collection.
 * Lives at:  IQU Registrations → Zeffy Payments
 *
 * Deliberately self-contained: its own page slug, its own stylesheet
 * (admin/css/zeffy.css) and its own script (admin/js/zeffy.js), so the
 * donation UI can evolve without disturbing the registration screens.
 *
 * All markup uses the `iquz-` prefix to avoid colliding with `iqu-`.
 */
class IQU_Zeffy_Admin
{
    public const PAGE_SLUG = 'iqu-zeffy-payments';



    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_menu'], 20);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_init', [$this, 'maybe_save_settings']);
        add_action('wp_ajax_iqu_zeffy_export_csv', [$this, 'handle_export_csv']);
        // more than IQU_Pager::LOCAL_MAX donations: the table alone, for live search and paging
        IQU_Pager::remote('iqu-zeffy', function () { $this->render_table($this->table_context($this->request_args())); });
    }

    // ════════════════════════════════════════════════════
    // MENU
    // ════════════════════════════════════════════════════

    public function register_menu(): void
    {
        add_submenu_page(
            'iqu-registrations',
            'Zeffy Payment Collection',
            'Zeffy Payments',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    private function is_our_page(string $hook): bool
    {
        return strpos($hook, self::PAGE_SLUG) !== false;
    }

    public function enqueue_assets(string $hook): void
    {
        if (!$this->is_our_page($hook)) {
            return;
        }

        wp_enqueue_style(
            'iqu-zeffy-style',
            IQU_PLUGIN_URL . 'admin/css/zeffy.css',
            ['iqu-admin-style'],
            IQU_VERSION
        );

        wp_enqueue_script(
            'iqu-zeffy-script',
            IQU_PLUGIN_URL . 'admin/js/zeffy.js',
            ['jquery', 'chartjs'],
            IQU_VERSION,
            true
        );

        wp_localize_script('iqu-zeffy-script', 'IQU_Zeffy', [
            'ajax_url'   => admin_url('admin-ajax.php'),
            'nonce_sync' => wp_create_nonce('iqu_zeffy_sync'),
            'chart'      => IQU_Zeffy_DB::get_monthly_series(6),
            'analytics'  => IQU_Zeffy_DB::get_analytics(),
            'i18n'       => [
                'syncing'  => 'Syncing…',
                'testing'  => 'Testing…',
                'importing' => 'Importing full history — this can take a minute…',
                'failed'   => 'Something went wrong. Please try again.',
                'confirm_backfill' => 'Import the entire Zeffy payment history? This is safe to repeat but may take a few minutes.',
            ],
        ]);
    }

    // ════════════════════════════════════════════════════
    // SETTINGS
    // ════════════════════════════════════════════════════

    public function maybe_save_settings(): void
    {
        if (empty($_POST['iqu_zeffy_settings_submit'])) {
            return;
        }
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied.');
        }
        check_admin_referer('iqu_zeffy_settings');

        // Only persist to the DB when the value is not locked by a constant. The two fields are never
        // pre-filled (the saved key shows only as a masked placeholder), so an empty field means "keep
        // the saved one" — saving the reconcile window must not wipe the key and secret. Only the
        // "Remove the saved …" box deletes one.
        if (!IQU_Zeffy_API::key_is_locked()) {
            if (!empty($_POST['iqu_zeffy_api_key_remove'])) {
                delete_option(IQU_Zeffy_API::OPT_API_KEY);
            } elseif (isset($_POST['iqu_zeffy_api_key']) && is_string($_POST['iqu_zeffy_api_key'])) {
                // A token is kept exactly as pasted (sanitize_text_field strips %xx sequences); only whitespace and control characters go.
                $key = preg_replace('/[\s\x00-\x1F\x7F]+/', '', wp_unslash($_POST['iqu_zeffy_api_key']));
                // An all-bullet value (the masked placeholder pasted back) also means "unchanged".
                if ($key !== '' && strpos($key, '•') === false) {
                    update_option(IQU_Zeffy_API::OPT_API_KEY, $key, false);
                }
            }
        }

        if (!IQU_Zeffy_Webhook::secret_is_locked()) {
            if (!empty($_POST['iqu_zeffy_webhook_secret_remove'])) {
                delete_option(IQU_Zeffy_Webhook::OPT_SECRET);
            } elseif (isset($_POST['iqu_zeffy_webhook_secret']) && is_string($_POST['iqu_zeffy_webhook_secret'])) {
                $secret = preg_replace('/[\s\x00-\x1F\x7F]+/', '', wp_unslash($_POST['iqu_zeffy_webhook_secret']));
                if ($secret !== '' && strpos($secret, '•') === false) {
                    update_option(IQU_Zeffy_Webhook::OPT_SECRET, $secret, false);
                }
            }
        }

        if (isset($_POST['iqu_zeffy_window_days'])) {
            $days = absint($_POST['iqu_zeffy_window_days']);
            if ($days >= 1 && $days <= 365) {
                update_option(IQU_Zeffy_Sync::OPT_WINDOW_DAYS, $days, false);
            }
        }

        wp_safe_redirect(add_query_arg(
            ['page' => self::PAGE_SLUG, 'saved' => '1'],
            admin_url('admin.php')
        ));
        exit;
    }

    // ════════════════════════════════════════════════════
    // PAGE
    // ════════════════════════════════════════════════════

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied.');
        }

        $args = $this->request_args();

        $stats     = IQU_Zeffy_DB::get_stats();
        $table     = $this->table_context($args);
        $args['per_page'] = $table['pager']['per'];
        $campaigns = IQU_Zeffy_DB::get_campaign_options();

        $configured = IQU_Zeffy_API::has_key();
        $show_setup = !$configured || !empty($_GET['setup']);
?>
<div class="wrap iquz-wrap">

    <?php $this->render_header(); ?>

    <?php if (!empty($_GET['saved'])): ?>
    <div class="iquz-notice iquz-notice--ok">Settings saved.</div>
    <?php endif; ?>

    <?php if (!$configured): ?>
    <div class="iquz-notice iquz-notice--warn">
        <strong>Not connected yet.</strong> Add your Zeffy API key below to start pulling donation records.
    </div>
    <?php endif; ?>

    <?php if ($configured): ?>
    <?php $this->render_metrics($stats); ?>
    <?php $this->render_insights($stats); ?>
    <?php $this->render_analytics(); ?>
    <?php $this->render_filters($args, $campaigns); ?>
    <?php $this->render_table($table); ?>
    <?php endif; ?>

    <?php $this->render_settings($show_setup); ?>

</div>
<?php
    }

    /**
     * Normalised, sanitised query arguments from $_GET.
     */
    private function request_args(): array
    {
        $per_page = IQU_Pager::per($_GET);

        $date_from = sanitize_text_field($_GET['date_from'] ?? '');
        $date_to   = sanitize_text_field($_GET['date_to'] ?? '');

        // Reject anything that is not a plain Y-m-d date.
        $date_re = '/^\d{4}-\d{2}-\d{2}$/';
        if (!preg_match($date_re, $date_from)) $date_from = '';
        if (!preg_match($date_re, $date_to))   $date_to   = '';

        return [
            'search'      => sanitize_text_field(wp_unslash($_GET['search'] ?? '')), // O'Brien, not O\'Brien
            'status'      => sanitize_text_field($_GET['status'] ?? ''),
            'campaign_id' => sanitize_text_field(wp_unslash($_GET['campaign_id'] ?? '')),
            'recurring'   => sanitize_text_field($_GET['recurring'] ?? ''),
            'date_from'   => $date_from,
            'date_to'     => $date_to,
            'per_page'    => $per_page,
            'page'        => max(1, absint($_GET['paged'] ?? 1)),
            'orderby'     => 'created_ts',
            'order'       => 'DESC',
        ];
    }

    // ════════════════════════════════════════════════════
    // HEADER
    // ════════════════════════════════════════════════════

    /**
     * The shared IQU header (IQU_Admin::top_bar): title + the shared tab list on the first row, like
     * every other IQU page; webhook status + last sync on the left and Sync now / Settings on the
     * right of its second row.
     */
    private function render_header(): void
    {
        $last_sync = IQU_Zeffy_Sync::last_sync_ts();
        $receiving = IQU_Zeffy_Webhook::is_receiving();
        $meta = '<span class="iquz-pill ' . ($receiving ? 'iquz-pill--live' : 'iquz-pill--idle') . '">' . ($receiving ? '● Webhook live' : '○ Webhook idle') . '</span>'
            . '<span class="iquz-sync-stamp">' . ($last_sync ? 'Last synced ' . esc_html(IQU_Zeffy_DB::format_date($last_sync, 'M j, g:i A')) : 'Never synced') . '</span>';
        $actions = '<button type="button" class="iqu-btn-primary" id="iquz-sync-now">↻ Sync now</button>'
            . '<a href="' . esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG . '&setup=1#iquz-settings')) . '" class="iqu-btn-ghost">⚙ Settings</a>';
        IQU_Admin::top_bar('Zeffy Payment Collection', 'Donations received through Zeffy · AL HASANAH FOUNDATION', self::PAGE_SLUG, $meta, $actions);
        ?>
<div class="iquz-flash" id="iquz-flash" hidden></div>
<?php
    }

    // ════════════════════════════════════════════════════
    // METRICS
    // ════════════════════════════════════════════════════

    private function render_metrics(array $s): void
    {
        $cards = [
            [
                'accent' => 'net', 'icon' => 'wallet', 'tone' => 'green',
                'value'  => IQU_Zeffy_DB::money($s['net_cents']),
                'label'  => 'Net Collected',
                'sub'    => 'Gross minus refunds',
            ],
            [
                'accent' => 'month', 'icon' => 'calendar', 'tone' => 'purple',
                'value'  => IQU_Zeffy_DB::money($s['month_cents']),
                'label'  => 'This Month',
                'sub'    => gmdate('F Y'),
            ],
            [
                'accent' => 'count', 'icon' => 'heart', 'tone' => 'blue',
                'value'  => number_format($s['total_count']),
                'label'  => 'Donations',
                'sub'    => 'Successful payments',
            ],
            [
                'accent' => 'donors', 'icon' => 'user-check', 'tone' => 'teal',
                'value'  => number_format($s['donor_count']),
                'label'  => 'Unique Donors',
                'sub'    => 'By email address',
            ],
            [
                'accent' => 'recurring', 'icon' => 'repeat', 'tone' => 'gold',
                'value'  => number_format($s['recurring_count']),
                'label'  => 'Recurring',
                'sub'    => 'Subscription donations',
            ],
            [
                'accent' => 'avg', 'icon' => 'bar-chart', 'tone' => 'purple',
                'value'  => IQU_Zeffy_DB::money($s['avg_cents']),
                'label'  => 'Average Donation',
                'sub'    => 'Per donation',
            ],
        ];
    ?>
<div class="iquz-metrics">
    <?php foreach ($cards as $c): ?>
    <div class="iquz-metric iqu-stat">
        <div class="iquz-metric-accent iquz-metric-accent--<?php echo esc_attr($c['accent']); ?>"></div>
        <?php echo IQU_Icons::stat_head($c['icon'], $c['tone'], esc_html($c['value']), 'iquz-metric-num'); ?>
        <div class="iquz-metric-lbl"><?php echo esc_html($c['label']); ?></div>
        <div class="iquz-metric-sub"><?php echo esc_html($c['sub']); ?></div>
    </div>
    <?php endforeach; ?>
</div>
<?php
    }

    // ════════════════════════════════════════════════════
    // CHART + REFUND CARD
    // ════════════════════════════════════════════════════

    private function render_insights(array $s): void
    {
        $refund_pct = $s['gross_cents'] > 0
            ? round(($s['refunded_cents'] / $s['gross_cents']) * 100, 1)
            : 0;
    ?>
<div class="iquz-insights">
    <div class="iquz-card iquz-card--chart">
        <div class="iquz-card-head">
            <span class="iquz-card-title">Donations per month</span>
            <span class="iquz-card-badge">Last 6 months</span>
        </div>
        <div class="iquz-chart-body">
            <canvas id="iquz-month-chart" aria-label="Monthly donation totals"></canvas>
        </div>
    </div>

    <div class="iquz-card">
        <div class="iquz-card-head">
            <span class="iquz-card-title">Reconciliation</span>
        </div>
        <div class="iquz-recon">
            <div class="iquz-recon-row">
                <span>Gross received</span>
                <strong><?php echo esc_html(IQU_Zeffy_DB::money($s['gross_cents'])); ?></strong>
            </div>
            <div class="iquz-recon-row iquz-recon-row--neg">
                <span>Refunded</span>
                <strong>− <?php echo esc_html(IQU_Zeffy_DB::money($s['refunded_cents'])); ?></strong>
            </div>
            <div class="iquz-recon-row iquz-recon-row--total">
                <span>Net</span>
                <strong><?php echo esc_html(IQU_Zeffy_DB::money($s['net_cents'])); ?></strong>
            </div>
            <div class="iquz-recon-note">
                Refund rate <strong><?php echo esc_html($refund_pct); ?>%</strong>.
                Refunds have no webhook event, so these figures refresh on the
                <?php echo (int) IQU_Zeffy_Sync::window_days(); ?>-day reconcile sync.
            </div>
        </div>
    </div>
</div>
<?php
    }

    // ════════════════════════════════════════════════════
    // ANALYTICS GRID
    // ════════════════════════════════════════════════════

    /**
     * Six supplementary charts laid out in fixed rows.
     *
     * Each row is its own grid container rather than one big grid
     * with column spans. That is deliberate: CSS Grid stretches all
     * items in a row to the tallest one, so a per-row container
     * guarantees the cards sitting side by side are always the same
     * height, no matter how long their caption text runs.
     *
     * Row 1 — donor identity   (2 up, ranked bars need width)
     * Row 2 — donation shape   (3 up, compact charts)
     * Row 3 — retention        (full width, it is a timeline)
     */
    private function render_analytics(): void
    {
        $month_label = gmdate('F Y');
    ?>
<div class="iquz-section-head">
    <h2>Donation insights</h2>
    <p>Patterns worth acting on — who gives, what they respond to, and whether they come back.</p>
</div>

<!-- ══ Row 1 — who is giving ══════════════════════════ -->
<div class="iquz-chart-row iquz-chart-row--2">

    <div class="iquz-card iquz-card--focus">
        <div class="iquz-card-head">
            <span class="iquz-card-title">Who donated this month</span>
            <span class="iquz-card-badge iquz-card-badge--live"><?php echo esc_html($month_label); ?></span>
        </div>
        <div class="iquz-chart-body iquz-chart-body--rank">
            <canvas id="iquz-month-donors" aria-label="Donors in the current month"></canvas>
        </div>
        <p class="iquz-card-note" id="iquz-month-summary"></p>
    </div>

    <div class="iquz-card">
        <div class="iquz-card-head">
            <span class="iquz-card-title">Top donors</span>
            <span class="iquz-card-badge">Last 3 months</span>
        </div>
        <div class="iquz-chart-body iquz-chart-body--rank">
            <canvas id="iquz-top-donors" aria-label="Highest giving donors"></canvas>
        </div>
        <p class="iquz-card-note">
            Grouped by email. Repeat major donors usually account for most of a small
            nonprofit's income — these names are worth a personal thank-you.
        </p>
    </div>

</div>

<!-- ══ Row 2 — what they respond to ═══════════════════ -->
<div class="iquz-chart-row iquz-chart-row--3">

    <div class="iquz-card">
        <div class="iquz-card-head">
            <span class="iquz-card-title">Recurring vs one-time</span>
            <span class="iquz-card-badge">12 mo</span>
        </div>
        <div class="iquz-chart-body iquz-chart-body--compact">
            <canvas id="iquz-type-split" aria-label="Recurring versus one-time donations"></canvas>
        </div>
        <p class="iquz-card-note">
            Recurring income is predictable income.
        </p>
    </div>

    <div class="iquz-card">
        <div class="iquz-card-head">
            <span class="iquz-card-title">Donation size</span>
            <span class="iquz-card-badge">12 mo</span>
        </div>
        <div class="iquz-chart-body iquz-chart-body--compact">
            <canvas id="iquz-donation-sizes" aria-label="Number of donations per amount bracket"></canvas>
        </div>
        <p class="iquz-card-note">
            The tallest bar reflects your form's suggested amounts.
        </p>
    </div>

    <div class="iquz-card">
        <div class="iquz-card-head">
            <span class="iquz-card-title">By campaign</span>
            <span class="iquz-card-badge">12 mo</span>
        </div>
        <div class="iquz-chart-body iquz-chart-body--compact">
            <canvas id="iquz-campaigns" aria-label="Donations by campaign"></canvas>
        </div>
        <p class="iquz-card-note">
            Separate Zakat and Sadaqah forms would make this far more actionable.
        </p>
    </div>

</div>

<!-- ══ Row 3 — are they coming back ═══════════════════ -->
<div class="iquz-chart-row iquz-chart-row--1">

    <div class="iquz-card">
        <div class="iquz-card-head">
            <span class="iquz-card-title">New vs returning donors</span>
            <span class="iquz-card-badge">Last 6 months</span>
        </div>
        <div class="iquz-chart-body iquz-chart-body--compact">
            <canvas id="iquz-donor-mix" aria-label="New versus returning donors per month"></canvas>
        </div>
        <p class="iquz-card-note">
            A donor counts as new in the month of their first-ever donation. Growing
            returning bars mean retention is working; a flat returning line means
            donations are one-off.
        </p>
    </div>

</div>
<?php
    }




    // ════════════════════════════════════════════════════
    // FILTERS
    // ════════════════════════════════════════════════════

    private function render_filters(array $args, array $campaigns): void
    {
        $export = admin_url('admin-ajax.php?action=iqu_zeffy_export_csv&_wpnonce='
            . wp_create_nonce('iqu_zeffy_export_csv')
            . '&' . http_build_query(array_intersect_key($args, array_flip([
                'search',
                'status',
                'campaign_id',
                'recurring',
                'date_from',
                'date_to'
            ]))));
    ?>
<form method="GET" class="iquz-filters" role="search" data-iqu-live="iqu-zeffy">
    <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">

    <input type="search" name="search" value="<?php echo esc_attr($args['search']); ?>"
        placeholder="Search donor, email, company, campaign…" class="iquz-input iquz-input--search" aria-label="Search donations" data-iqu-search="like" data-iqu-zero="none">

    <select name="status" class="iquz-input" aria-label="Status">
        <option value="">All statuses</option>
        <?php foreach (IQU_Zeffy_DB::PAYMENT_STATES as $st): ?>
        <option value="<?php echo esc_attr($st); ?>" <?php selected($args['status'], $st); ?>>
            <?php echo esc_html(ucfirst($st)); ?>
        </option>
        <?php endforeach; ?>
    </select>

    <?php if (!empty($campaigns)): ?>
    <select name="campaign_id" class="iquz-input">
        <option value="">All campaigns</option>
        <?php foreach ($campaigns as $cid => $title): ?>
        <option value="<?php echo esc_attr($cid); ?>" <?php selected($args['campaign_id'], $cid); ?>>
            <?php echo esc_html(wp_html_excerpt($title, 40, '…')); ?>
        </option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>

    <select name="recurring" class="iquz-input">
        <option value="">One-time & recurring</option>
        <option value="1" <?php selected($args['recurring'], '1'); ?>>Recurring only</option>
        <option value="0" <?php selected($args['recurring'], '0'); ?>>One-time only</option>
    </select>

    <input type="date" name="date_from" value="<?php echo esc_attr($args['date_from']); ?>" class="iquz-input"
        title="From date" data-iqu-op="ge" data-iqu-on="day">
    <input type="date" name="date_to" value="<?php echo esc_attr($args['date_to']); ?>" class="iquz-input"
        title="To date" data-iqu-op="le" data-iqu-on="day">

    <?php if ($args['per_page'] !== IQU_Pager::DEFAULT): ?><input type="hidden" name="per_page" value="<?php echo (int) $args['per_page']; ?>"><?php endif; ?>

    <?php echo IQU_Pager::live_status() . IQU_Pager::noscript_submit('Filter', 'iquz-btn'); // results update as you type ?>
    <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)); ?>" class="iquz-btn
        iquz-btn--ghost" data-iqu-reset="iqu-zeffy">Reset</a>
    <a href="<?php echo esc_url($export); ?>" class="iquz-btn iquz-btn--export">⬇ Export CSV</a>
</form>
<?php
    }

    // ════════════════════════════════════════════════════
    // TABLE
    // ════════════════════════════════════════════════════

    /** The donations list's filters (empty ones left out), for the pager's links. */
    private function list_url(array $args): callable
    {
        return IQU_Pager::url(self::PAGE_SLUG, array_intersect_key($args, array_flip(['search', 'status', 'campaign_id', 'recurring', 'date_from', 'date_to'])));
    }

    /**
     * The donations for the request's filters and page. Up to IQU_Pager::LOCAL_MAX donations: every one
     * is sent and pager.js filters and pages them (local); more: counted with the same filters, then
     * one page (LIMIT/OFFSET).
     */
    private function table_context(array $args): array
    {
        $local = IQU_Pager::local(IQU_Zeffy_DB::count_payments([]));
        $out = $shown = [];
        if ($local) {
            $rows  = IQU_Zeffy_DB::get_payments(['per_page' => IQU_Pager::LOCAL_MAX, 'page' => 1, 'orderby' => $args['orderby'], 'order' => $args['order']]);
            $set   = array_filter(array_intersect_key($args, array_flip(['search', 'status', 'campaign_id', 'recurring', 'date_from', 'date_to'])), fn($v) => $v !== '');
            $match = $set ? array_column(IQU_Zeffy_DB::get_payments(['per_page' => IQU_Pager::LOCAL_MAX, 'page' => 1] + $args), 'payment_id') : array_column($rows, 'payment_id');
            ['pager' => $pager, 'out' => $out, 'shown' => $shown] = IQU_Pager::local_view(array_column($rows, 'payment_id'), $match, $_GET);
            $total = $pager['total'];
        } else {
            $total = IQU_Zeffy_DB::count_payments($args);
            $pager = IQU_Pager::from($_GET, $total);
            $rows  = IQU_Zeffy_DB::get_payments(['per_page' => $pager['per'], 'page' => $pager['page']] + $args);
        }
        $args['per_page'] = $pager['per'];
        $args['page']     = $pager['page'];
        return ['args' => $args, 'local' => $local, 'rows' => $rows, 'total' => $total, 'pager' => $pager, 'out' => $out, 'shown' => $shown];
    }

    /** The donations table (data-iqu-live-region="iqu-zeffy"): printed by the page and by IQU_Pager::ajax(). */
    private function render_table(array $c): void
    {
        ['args' => $args, 'local' => $local, 'rows' => $rows, 'total' => $total, 'pager' => $pager] = $c;
        $url = $this->list_url($args);
        $tid = $local ? 'iquz-donations' : '';
        $clear = $this->list_url(['search' => ''] + $args)(['per_page' => $args['per_page']]);
    ?>
<div class="iquz-table-wrap iqu-list-card"<?php echo IQU_Pager::region_attrs('iqu-zeffy', $local); ?>>
    <div class="iqu-card-head iqu-list-head">
        <span class="iqu-card-head-title">Donations</span>
        <?php echo IQU_Pager::head($pager, $url, 'Donations per page', $tid); // escaped inside ?>
    </div>
    <table class="iquz-tbl"<?php echo $local ? ' id="iquz-donations"' . IQU_Pager::local_attrs() : ''; ?>>
        <thead>
            <tr>
                <th>Date</th>
                <th>Donor</th>
                <th>Amount</th>
                <th>Campaign</th>
                <th>Method</th>
                <th>Type</th>
                <th>Status</th>
                <th></th>
            </tr>
        </thead>
        <?php if ($local):
            // before the first import nothing can match yet: that one sentence, whatever the filters
            $none = !IQU_Zeffy_Sync::backfill_done()
                ? '<span data-iqu-none-filter>No donations imported yet. Run <strong>Import full history</strong> in Settings below.</span>'
                : IQU_Pager::none_variants($args['search'], $clear, 'No donations match these filters.');
            // every donation: what pager.js filters on (status, campaign, recurring, the UTC day; the search's fields)
            IQU_Pager::local_body($rows, 'payment_id', fn(array $r) => [
                'v-status' => (string) $r['status'], 'v-campaign_id' => (string) $r['campaign_id'],
                'v-recurring' => !empty($r['is_recurring']) ? '1' : '0', 'v-day' => gmdate('Y-m-d', (int) $r['created_ts']),
                's' => [$r['buyer_email'], $r['buyer_first_name'], $r['buyer_last_name'], $r['buyer_first_name'] . ' ' . $r['buyer_last_name'], $r['company_name'], $r['description'], $r['payment_id']],
            ], $c, '<td colspan="8" class="iquz-empty">' . $none . '</td>', fn(array $r, string $attrs) => $this->render_row($r, $attrs));
        else: ?>
        <tbody>
            <?php if (empty($rows)): ?>
            <tr>
                <td colspan="8" class="iquz-empty">
                    <?php if ($total === 0 && !IQU_Zeffy_Sync::backfill_done()): ?>
                    No donations imported yet. Run <strong>Import full history</strong> in Settings below.
                    <?php elseif ($args['search'] !== ''): ?>
                    <?php echo IQU_Pager::no_results($args['search'], $clear); // escaped inside ?>
                    <?php else: ?>
                    No donations match these filters.
                    <?php endif; ?>
                </td>
            </tr>
            <?php else: foreach ($rows as $row) $this->render_row($row, ''); endif; ?>
        </tbody>
        <?php endif; ?>
    </table>

    <?php echo IQU_Pager::foot($pager, $url, $tid); // escaped inside ?>
</div>
<?php
    }

    /** One donation and its (closed) detail row ($attrs: IQU_Pager::row_attrs on a local list). */
    private function render_row(array $row, string $attrs): void
    {
                            $net = (int) $row['amount'] - (int) $row['refunded_amount'];
                            $cur = (string) $row['currency'];
                        ?>
            <tr class="iquz-row" data-payment="<?php echo esc_attr($row['payment_id']); ?>"<?php echo $attrs; ?>>
                <td class="iquz-td-date">
                    <?php echo esc_html(IQU_Zeffy_DB::format_date((int) $row['created_ts'], 'M j, Y')); ?>
                    <small><?php echo esc_html(IQU_Zeffy_DB::format_date((int) $row['created_ts'], 'g:i A')); ?></small>
                </td>

                <td class="iquz-td-donor">
                    <strong><?php echo esc_html(IQU_Zeffy_DB::donor_name($row)); ?></strong>
                    <?php if (!empty($row['is_corporate'])): ?>
                    <span class="iquz-tag iquz-tag--org">Org</span>
                    <?php endif; ?>
                    <small><?php echo esc_html($row['buyer_email'] ?: '—'); ?></small>
                    <?php if (!empty($row['tribute_type'])): ?>
                    <span class="iquz-tribute">
                        <?php echo $row['tribute_type'] === 'in_memory_of' ? '🕊 In memory of' : '🎗 In honour of'; ?>
                        <?php echo esc_html($row['tribute_name'] ?: '—'); ?>
                    </span>
                    <?php endif; ?>
                </td>

                <td class="iquz-td-amount">
                    <span class="iquz-amt"><?php echo esc_html(IQU_Zeffy_DB::money($net, $cur)); ?></span>
                    <?php if ((int) $row['refunded_amount'] > 0): ?>
                    <small class="iquz-refunded">
                        <?php echo esc_html(IQU_Zeffy_DB::money((int) $row['amount'], $cur)); ?> −
                        <?php echo esc_html(IQU_Zeffy_DB::money((int) $row['refunded_amount'], $cur)); ?> refunded
                    </small>
                    <?php endif; ?>
                    <small class="iquz-cur"><?php echo esc_html(strtoupper($cur)); ?></small>
                </td>

                <td class="iquz-td-campaign">
                    <?php echo esc_html($row['description'] ?: '—'); ?>
                    <?php if (!empty($row['fund_name'])): ?>
                    <small>Fund: <?php echo esc_html($row['fund_name']); ?></small>
                    <?php endif; ?>
                </td>

                <td class="iquz-td-method">
                    <?php if (!empty($row['card_brand'])): ?>
                    <span class="iquz-card-brand"><?php echo esc_html(strtoupper($row['card_brand'])); ?></span>
                    <?php if (!empty($row['card_last4'])): ?>
                    <small>•••• <?php echo esc_html($row['card_last4']); ?></small>
                    <?php endif; ?>
                    <?php else: ?>
                    <?php echo esc_html(ucfirst($row['method_type'] ?: '—')); ?>
                    <?php endif; ?>
                </td>

                <td>
                    <?php if (!empty($row['is_recurring'])): ?>
                    <span class="iquz-tag iquz-tag--recurring">
                        🔁 <?php echo esc_html(ucfirst($row['recurring_interval'] ?: 'recurring')); ?>
                    </span>
                    <?php else: ?>
                    <span class="iquz-tag iquz-tag--once">One-time</span>
                    <?php endif; ?>
                </td>

                <td><?php echo $this->status_badge($row); ?></td>

                <td class="iquz-td-actions">
                    <?php if (!empty($row['receipt_url'])): ?>
                    <a href="<?php echo esc_url($row['receipt_url']); ?>" target="_blank" rel="noopener"
                        class="iquz-link">Receipt ↗</a>
                    <?php endif; ?>
                    <button type="button" class="iquz-link iquz-toggle-detail">Details</button>
                </td>
            </tr>

            <tr class="iquz-detail-row iqu-pager-detail" hidden>
                <td colspan="8">
                    <?php $this->render_detail($row); ?>
                </td>
            </tr>
<?php
    }

    private function status_badge(array $row): string
    {
        if (!empty($row['dispute_status'])) {
            return '<span class="iquz-badge iquz-badge--dispute">⚠ Disputed</span>';
        }

        $refund = (string) $row['refund_status'];
        if ($refund === 'full') {
            return '<span class="iquz-badge iquz-badge--refunded">Refunded</span>';
        }
        if ($refund === 'partial') {
            return '<span class="iquz-badge iquz-badge--partial">Partial refund</span>';
        }

        $map = [
            'succeeded' => ['iquz-badge--ok', 'Succeeded'],
            'pending'   => ['iquz-badge--pending', 'Pending'],
            'failed'    => ['iquz-badge--failed', 'Failed'],
        ];
        [$class, $label] = $map[$row['status']] ?? ['iquz-badge--pending', ucfirst((string) $row['status'])];

        return '<span class="iquz-badge ' . esc_attr($class) . '">' . esc_html($label) . '</span>';
    }

    /**
     * Expanded row: the fields worth seeing without leaving WordPress,
     * including any custom questions the donor answered at checkout.
     */
    private function render_detail(array $row): void
    {
        $raw   = json_decode((string) $row['raw_json'], true);
        $raw   = is_array($raw) ? $raw : [];
        $items = is_array($raw['items'] ?? null) ? $raw['items'] : [];
        $qa    = is_array($raw['buyer_questions'] ?? null) ? $raw['buyer_questions'] : [];
    ?>
<div class="iquz-detail">
    <div class="iquz-detail-grid">
        <div>
            <span class="iquz-dl">Payment ID</span>
            <code><?php echo esc_html($row['payment_id']); ?></code>
        </div>
        <div>
            <span class="iquz-dl">Tax-receipt eligible</span>
            <?php echo esc_html(IQU_Zeffy_DB::money((int) $row['eligible_amount'], (string) $row['currency'])); ?>
        </div>
        <div>
            <span class="iquz-dl">Source</span>
            <?php echo esc_html($row['source'] === 'webhook' ? 'Webhook (real-time)' : 'API sync'); ?>
        </div>
        <div>
            <span class="iquz-dl">Payment type</span>
            <?php echo esc_html(ucfirst($row['pay_type'] ?: '—')); ?>
        </div>
        <?php if (!empty($row['subscription_id'])): ?>
        <div>
            <span class="iquz-dl">Subscription</span>
            <code><?php echo esc_html($row['subscription_id']); ?></code>
        </div>
        <?php endif; ?>
        <?php if (!empty($row['dispute_status'])): ?>
        <div>
            <span class="iquz-dl">Dispute</span>
            <?php echo esc_html(ucfirst(str_replace('_', ' ', $row['dispute_status']))); ?>
            (<?php echo esc_html($row['dispute_reason']); ?>)
        </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($items)): ?>
    <div class="iquz-detail-block">
        <span class="iquz-dl">Line items</span>
        <ul class="iquz-items">
            <?php foreach ($items as $item):
                            if (!is_array($item)) continue; ?>
            <li>
                <span><?php echo esc_html(ucfirst(str_replace('_', ' ', (string) ($item['type'] ?? 'item')))); ?></span>
                <strong><?php echo esc_html(IQU_Zeffy_DB::money(
                                            (int) ($item['amount'] ?? 0),
                                            (string) ($item['currency'] ?? $row['currency'])
                                        )); ?></strong>
                <?php if (!empty($item['recurrence_interval'])): ?>
                <em><?php echo esc_html($item['recurrence_interval']); ?></em>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php if (!empty($qa)): ?>
    <div class="iquz-detail-block">
        <span class="iquz-dl">Checkout questions</span>
        <ul class="iquz-qa">
            <?php foreach ($qa as $entry):
                            if (!is_array($entry)) continue;
                            $answer = $entry['answer'] ?? '';
                            if (is_array($answer))  $answer = implode(', ', array_map('strval', $answer));
                            if (is_bool($answer))   $answer = $answer ? 'Yes' : 'No';
                        ?>
            <li>
                <span><?php echo esc_html((string) ($entry['question'] ?? '')); ?></span>
                <strong><?php echo esc_html((string) $answer); ?></strong>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
</div>
<?php
    }

    // ════════════════════════════════════════════════════
    // SETTINGS PANEL
    // ════════════════════════════════════════════════════

    private function render_settings(bool $open): void
    {
        $key_locked    = IQU_Zeffy_API::key_is_locked();
        $secret_locked = IQU_Zeffy_Webhook::secret_is_locked();
        $result        = IQU_Zeffy_Sync::last_result();
    ?>
<details class="iquz-settings" id="iquz-settings" <?php echo $open ? 'open' : ''; ?>>
    <summary>
        <span>⚙ Connection & sync settings</span>
        <small><?php echo IQU_Zeffy_API::has_key() ? 'Connected' : 'Not configured'; ?></small>
    </summary>

    <form method="POST" class="iquz-settings-body">
        <?php wp_nonce_field('iqu_zeffy_settings'); ?>

        <div class="iquz-field">
            <label for="iquz-api-key">Zeffy API key</label>
            <?php if ($key_locked): ?>
            <input type="text" id="iquz-api-key" value="<?php echo esc_attr(IQU_Zeffy_API::masked_key()); ?>" disabled>
            <p class="iquz-hint iquz-hint--ok">
                Defined in <code>wp-config.php</code> as <code>IQU_ZEFFY_API_KEY</code>. This is the recommended
                setup — the key never touches the database.
            </p>
            <?php else: ?>
            <input type="password" id="iquz-api-key" name="iqu_zeffy_api_key" autocomplete="off"
                placeholder="<?php echo esc_attr(IQU_Zeffy_API::masked_key() ?: 'Paste your Zeffy API key'); ?>">
            <?php if ((string) get_option(IQU_Zeffy_API::OPT_API_KEY, '') !== ''): ?>
            <label class="iquz-hint"><input type="checkbox" name="iqu_zeffy_api_key_remove" value="1"> Remove the saved key (leave the field empty to keep it)</label>
            <?php endif; ?>
            <p class="iquz-hint">
                Zeffy dashboard → Settings → Organization → Integrations → API Key.
                For better security, define <code>IQU_ZEFFY_API_KEY</code> in <code>wp-config.php</code> instead.
            </p>
            <?php endif; ?>
        </div>

        <div class="iquz-field">
            <label for="iquz-secret">Webhook signing secret</label>
            <?php if ($secret_locked): ?>
            <input type="text" id="iquz-secret" value="whsec_••••••••" disabled>
            <p class="iquz-hint iquz-hint--ok">
                Defined in <code>wp-config.php</code> as <code>IQU_ZEFFY_WEBHOOK_SECRET</code>.
            </p>
            <?php else: ?>
            <input type="password" id="iquz-secret" name="iqu_zeffy_webhook_secret" autocomplete="off"
                placeholder="<?php echo IQU_Zeffy_Webhook::has_secret() ? 'whsec_••••••••' : 'whsec_…'; ?>">
            <?php if ((string) get_option(IQU_Zeffy_Webhook::OPT_SECRET, '') !== ''): ?>
            <label class="iquz-hint"><input type="checkbox" name="iqu_zeffy_webhook_secret_remove" value="1"> Remove the saved secret (leave the field empty to keep it)</label>
            <?php endif; ?>
            <p class="iquz-hint">
                Zeffy dashboard → Settings → Integrations → Webhook. Without this, incoming deliveries are rejected.
            </p>
            <?php endif; ?>
        </div>

        <div class="iquz-field">
            <label>Your webhook URL</label>
            <div class="iquz-copyrow">
                <input type="text" id="iquz-endpoint" readonly
                    value="<?php echo esc_attr(IQU_Zeffy_Webhook::endpoint_url()); ?>">
                <button type="button" class="iquz-btn" id="iquz-copy-endpoint">Copy</button>
            </div>
            <p class="iquz-hint">Paste this into Zeffy → Integrations → Webhook, then enable it.</p>
        </div>

        <div class="iquz-field iquz-field--narrow">
            <label for="iquz-window">Reconcile window (days)</label>
            <input type="number" id="iquz-window" name="iqu_zeffy_window_days" min="1" max="365"
                value="<?php echo (int) IQU_Zeffy_Sync::window_days(); ?>">
            <p class="iquz-hint">
                How far back each scheduled sync re-checks for refunds and disputes. 30 days suits most organisations.
            </p>
        </div>

        <div class="iquz-settings-actions">
            <button type="submit" name="iqu_zeffy_settings_submit" value="1" class="iquz-btn
                iquz-btn--primary">Save settings</button>
            <button type="button" class="iquz-btn" id="iquz-test">Test connection</button>
            <button type="button" class="iquz-btn iquz-btn--ghost" id="iquz-backfill">Import full history</button>

            <button type="button" class="iquz-btn" id="iquz-telegram-test"
                <?php disabled(!IQU_Telegram::is_configured()); ?>>
                <?php echo IQU_Telegram::is_configured()
                            ? '✈ Send test message'
                            : '✈ Telegram not configured'; ?>
            </button>
        </div>
    </form>

    <?php if (!empty($result)): ?>
    <div class="iquz-lastrun <?php echo empty($result['ok']) ? 'iquz-lastrun--bad' : ''; ?>">
        <strong>Last <?php echo esc_html($result['mode'] ?? 'sync'); ?>:</strong>
        <?php echo esc_html($result['message'] ?? ''); ?>
        <em><?php echo esc_html(IQU_Zeffy_DB::format_date((int) ($result['time'] ?? 0), 'M j, g:i A')); ?></em>
    </div>
    <?php endif; ?>

    <?php $log = IQU_Zeffy_Webhook::get_log();
            if (!empty($log)): ?>
    <div class="iquz-log">
        <span class="iquz-dl">Recent webhook deliveries</span>
        <ul>
            <?php foreach (array_slice($log, 0, 8) as $entry): ?>
            <li>
                <span class="iquz-logdot iquz-logdot--<?php echo esc_attr($entry['status'] ?? ''); ?>"></span>
                <time><?php echo esc_html(IQU_Zeffy_DB::format_date((int) ($entry['time'] ?? 0), 'M j, g:i A')); ?></time>
                <span><?php echo esc_html($entry['message'] ?? ''); ?></span>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
</details>
<?php
    }

    // ════════════════════════════════════════════════════
    // CSV EXPORT
    // ════════════════════════════════════════════════════

    public function handle_export_csv(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Permission denied.');
        }
        check_admin_referer('iqu_zeffy_export_csv');

        $args = $this->request_args();
        $args['per_page'] = 5000;
        $args['page']     = 1;

        $rows = IQU_Zeffy_DB::get_payments($args);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=zeffy-donations-' . gmdate('Y-m-d') . '.csv');

        $out = fopen('php://output', 'w');

        IQU_CSV::put($out, [
            'Date (UTC)',
            'Payment ID',
            'Donor',
            'Email',
            'Organization',
            'Gross',
            'Refunded',
            'Net',
            'Tax-eligible',
            'Currency',
            'Campaign',
            'Fund',
            'Method',
            'Card',
            'Recurring',
            'Interval',
            'Tribute',
            'Status',
            'Refund status',
            'Receipt URL',
        ]);

        foreach ($rows as $r) {
            $net = (int) $r['amount'] - (int) $r['refunded_amount'];

            IQU_CSV::put($out, [
                gmdate('Y-m-d H:i:s', (int) $r['created_ts']),
                $r['payment_id'],
                IQU_Zeffy_DB::donor_name($r),
                $r['buyer_email'],
                $r['company_name'],
                number_format((int) $r['amount'] / 100, 2, '.', ''),
                number_format((int) $r['refunded_amount'] / 100, 2, '.', ''),
                number_format($net / 100, 2, '.', ''),
                number_format((int) $r['eligible_amount'] / 100, 2, '.', ''),
                strtoupper($r['currency']),
                $r['description'],
                $r['fund_name'],
                $r['method_type'],
                trim($r['card_brand'] . ' ' . $r['card_last4']),
                $r['is_recurring'] ? 'Yes' : 'No',
                $r['recurring_interval'],
                trim(str_replace('_', ' ', $r['tribute_type']) . ' ' . $r['tribute_name']),
                $r['status'],
                $r['refund_status'],
                $r['receipt_url'],
            ]);
        }

        fclose($out);
        exit;
    }
}