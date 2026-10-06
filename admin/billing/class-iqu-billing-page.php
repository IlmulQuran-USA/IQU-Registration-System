<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Billing_Page
 *
 * IQU Registrations → Billing. One menu item with tabs:
 *   Students (default) · Payments · Add Student · Import · Settings
 *
 * Students tab: every enrollment in a monthly program, its fee, its billing
 * status and the actions. Several families can be sent links at once.
 *
 * Security:
 * - manage_options only; the bulk send has a nonce.
 * - Selections from the URL are re-checked on the server before anything
 *   is created; fees always come from IQU_Billing_Pricing.
 * - At most 25 families per bulk send.
 * - All output is escaped.
 */
class IQU_Billing_Page
{
    public const SLUG          = 'iqu-billing';
    public const PAYMENTS_SLUG = 'iqu-billing-payments';
    public const BULK_SLUG     = 'iqu-billing-bulk';
    private const CAP          = 'manage_options';
    private const A_BULK       = 'iqu_billing_bulk_send';
    private const TX           = 'iqu_billing_bulk_';
    private const PER_PAGE     = 50;
    private const BULK_MAX     = 25;

    /** Programs billed monthly. Summer is a one-time fee paid at enrollment. */
    public const MONTHLY_FORMS = ['free'];

    public static function init(): void
    {
        add_action('admin_menu', [__CLASS__, 'register'], 19);
        add_action('admin_head', [__CLASS__, 'hide_tab_pages']); // after WordPress has checked access
        add_action('admin_post_' . self::A_BULK, [__CLASS__, 'handle_bulk']);
    }

    public static function register(): void
    {
        add_submenu_page('iqu-registrations', 'Billing — IQU', 'Billing', self::CAP, self::SLUG, [__CLASS__, 'render_students']);
        add_submenu_page('iqu-registrations', 'Billing Payments — IQU', 'Billing Payments', self::CAP, self::PAYMENTS_SLUG, [__CLASS__, 'render_payments']);
        add_submenu_page('iqu-registrations', 'Send Links — IQU', 'Send Links', self::CAP, self::BULK_SLUG, [__CLASS__, 'render_bulk']);
    }

    /** Only "Billing" shows in the menu; the rest are tabs inside it. */
    public static function hide_tab_pages(): void
    {
        foreach ([self::PAYMENTS_SLUG, self::BULK_SLUG, 'iqu-billing-add-student', 'iqu-billing-import', 'iqu-billing-settings'] as $slug) {
            remove_submenu_page('iqu-registrations', $slug);
        }
    }

    /** Shared tab bar for every billing screen. */
    public static function tabs(string $active): void
    {
        $tabs = [
            'students' => ['Students', self::SLUG],
            'payments' => ['Payments', self::PAYMENTS_SLUG],
            'add'      => ['Add Student', 'iqu-billing-add-student'],
            'import'   => ['Import', 'iqu-billing-import'],
            'settings' => ['Settings', 'iqu-billing-settings'],
        ];
        echo '<nav class="nav-tab-wrapper" style="margin-bottom:18px">';
        foreach ($tabs as $key => [$label, $slug]) {
            printf(
                '<a href="%s" class="nav-tab%s">%s</a>',
                esc_url(admin_url('admin.php?page=' . $slug)),
                $key === $active ? ' nav-tab-active' : '',
                esc_html($label)
            );
        }
        echo '</nav>';
    }

    // ------------------------------------------------------------
    // Data
    // ------------------------------------------------------------

    /** Every monthly enrollment with its fee and billing state. */
    public static function rows(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . IQU_TABLE_NAME;
        $in    = implode(',', array_fill(0, count(self::MONTHLY_FORMS), '%s'));
        $regs  = $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$table}` WHERE form_type IN ({$in}) ORDER BY id DESC", ...self::MONTHLY_FORMS), ARRAY_A) ?: [];

        $mode = IQU_Stripe::expected_mode();
        $acc_rows = $wpdb->get_results($wpdb->prepare(
            'SELECT m.registration_id, a.* FROM ' . IQU_Billing_DB::members_table() . ' m JOIN ' . IQU_Billing_DB::accounts_table() . ' a ON a.id = m.account_id WHERE m.mode = %s AND a.mode = %s',
            $mode, $mode
        ), ARRAY_A) ?: [];
        $by_reg = [];
        foreach ($acc_rows as $a) $by_reg[(int) $a['registration_id']] = $a;

        $out = [];
        foreach ($regs as $reg) {
            $id  = (int) $reg['id'];
            $acc = $by_reg[$id] ?? null;
            $p   = IQU_Billing_Pricing::for_registration($reg);
            if ($acc) {
                $group = self::group_for_status($acc['status']);
            } elseif ($p['billable']) {
                $group = 'not_set_up';
            } elseif ($p['needs_input']) {
                $group = 'needs_course';
            } else {
                $group = 'not_billed';
            }
            $out[] = ['reg' => $reg, 'p' => $p, 'acc' => $acc, 'group' => $group];
        }
        return $out;
    }

    private static function group_for_status(string $status): string
    {
        if (in_array($status, ['not_sent', 'link_sent'], true)) return 'link_sent';
        if (in_array($status, ['free_month', 'waiting_first_charge', 'active'], true)) return 'active';
        if (in_array($status, ['past_due', 'unpaid', 'paused'], true)) return 'failed';
        return 'not_billed';
    }

    private const GROUPS = [
        'all'          => 'All',
        'not_set_up'   => 'Not set up',
        'needs_course' => 'Needs course',
        'link_sent'    => 'Link sent',
        'active'       => 'Active',
        'failed'       => 'Payment problem',
        'not_billed'   => 'Not billed',
    ];

    // ------------------------------------------------------------
    // Students tab
    // ------------------------------------------------------------

    public static function render_students(): void
    {
        if (!current_user_can(self::CAP)) wp_die('You do not have permission to view this page.', 403);

        $rows   = self::rows();
        $filter = sanitize_key($_GET['show'] ?? 'all');
        if (!isset(self::GROUPS[$filter])) $filter = 'all';
        $q = trim(sanitize_text_field(wp_unslash($_GET['q'] ?? '')));

        $counts = array_fill_keys(array_keys(self::GROUPS), 0);
        $monthly_active = 0.0;
        foreach ($rows as $r) {
            $counts['all']++;
            $counts[$r['group']]++;
            if ($r['group'] === 'active' && $r['acc']) $monthly_active += (float) $r['p']['net'];
        }

        $shown = array_values(array_filter($rows, function ($r) use ($filter, $q) {
            if ($filter !== 'all' && $r['group'] !== $filter) return false;
            if ($q === '') return true;
            $hay = strtolower($r['reg']['first_name'] . ' ' . $r['reg']['last_name'] . ' ' . $r['reg']['email'] . ' ' . $r['reg']['guardian_name'] . ' iqu-' . $r['reg']['id']);
            return strpos($hay, strtolower($q)) !== false;
        }));

        $total = count($shown);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page  = min($pages, max(1, absint($_GET['paged'] ?? 1)));
        $shown = array_slice($shown, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

        $not_set_up_total = 0.0;
        foreach ($rows as $r) if ($r['group'] === 'not_set_up') $not_set_up_total += (float) $r['p']['net'];
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Billing</h1>
            <a href="<?php echo esc_url(admin_url('admin.php?page=iqu-billing-add-student')); ?>" class="page-title-action">+ Add existing student</a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=iqu-billing-import')); ?>" class="page-title-action">⬆ Import CSV</a>
            <hr class="wp-header-end">
            <?php self::tabs('students'); ?>

            <?php if (!IQU_Stripe::is_ready()): ?>
                <div class="notice notice-error inline"><p><?php echo esc_html(IQU_Stripe::not_ready_reason()); ?></p></div>
            <?php elseif (IQU_Stripe::expected_mode() === 'test'): ?>
                <div class="notice notice-warning inline"><p><strong>Test mode.</strong> No real money moves, and emails only go to our own addresses — never to families.</p></div>
            <?php endif; ?>

            <div style="display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;max-width:1100px;margin:14px 0 18px">
                <?php
                $cards = [
                    ['Monthly students', (string) $counts['all']],
                    ['Billing active', (string) $counts['active']],
                    ['Not set up yet', $counts['not_set_up'] . ' · ' . IQU_Pricing::format($not_set_up_total) . '/mo'],
                    ['Payment problems', (string) $counts['failed']],
                ];
                foreach ($cards as [$label, $value]): ?>
                    <div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 14px">
                        <div style="font-size:12px;color:#50575e"><?php echo esc_html($label); ?></div>
                        <div style="font-size:20px;font-weight:600;margin-top:4px"><?php echo esc_html($value); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <ul class="subsubsub" style="float:none;margin-bottom:8px">
                <?php $i = 0; foreach (self::GROUPS as $key => $label): $i++; ?>
                    <li><a href="<?php echo esc_url(add_query_arg(['page' => self::SLUG, 'show' => $key, 'q' => $q ?: null], admin_url('admin.php'))); ?>" class="<?php echo $filter === $key ? 'current' : ''; ?>"><?php echo esc_html($label); ?> <span class="count">(<?php echo (int) $counts[$key]; ?>)</span></a><?php echo $i < count(self::GROUPS) ? ' |' : ''; ?></li>
                <?php endforeach; ?>
            </ul>

            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin:8px 0 12px">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
                <input type="hidden" name="show" value="<?php echo esc_attr($filter); ?>">
                <label class="screen-reader-text" for="iqu-q">Search students</label>
                <input id="iqu-q" type="search" name="q" value="<?php echo esc_attr($q); ?>" placeholder="Name, email or IQU number" style="min-width:280px">
                <?php submit_button('Search', 'secondary', '', false); ?>
            </form>

            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" id="iqu-bulk-form">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::BULK_SLUG); ?>">
                <div style="display:flex;gap:10px;align-items:center;margin-bottom:8px">
                    <button type="submit" class="button button-primary" id="iqu-bulk-btn" disabled>Send payment links to selected</button>
                    <span class="description" id="iqu-bulk-count">Tick students who are “Not set up”. Brothers and sisters with the same email are billed together.</span>
                </div>

                <div style="overflow-x:auto">
                <table class="widefat striped">
                    <thead><tr>
                        <td class="check-column" style="padding:8px 10px"><label class="screen-reader-text" for="iqu-all">Select all</label><input id="iqu-all" type="checkbox"></td>
                        <th>Student and guardian</th><th>Program and course</th><th>Monthly</th><th>Billing</th><th>Actions</th>
                    </tr></thead>
                    <tbody>
                    <?php if (!$shown): ?>
                        <tr><td colspan="6" style="padding:18px">No students here.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($shown as $r): self::render_row($r); endforeach; ?>
                    </tbody>
                </table>
                </div>
            </form>

            <?php if ($pages > 1): ?>
                <div class="tablenav"><div class="tablenav-pages">
                    <?php echo wp_kses_post(paginate_links([
                        'base'    => add_query_arg('paged', '%#%'),
                        'format'  => '',
                        'current' => $page,
                        'total'   => $pages,
                    ])); ?>
                </div></div>
            <?php endif; ?>
        </div>
        <script>
        (function () {
            var form = document.getElementById('iqu-bulk-form'), btn = document.getElementById('iqu-bulk-btn'), info = document.getElementById('iqu-bulk-count');
            var boxes = form.querySelectorAll('input[name="ids[]"]'), all = document.getElementById('iqu-all');
            function sync() {
                var n = form.querySelectorAll('input[name="ids[]"]:checked').length;
                btn.disabled = n === 0;
                btn.textContent = n ? 'Send payment links to ' + n + ' selected' : 'Send payment links to selected';
            }
            boxes.forEach(function (b) { b.addEventListener('change', sync); });
            all.addEventListener('change', function () { boxes.forEach(function (b) { b.checked = all.checked; }); sync(); });
        })();
        </script>
        <?php
    }

    private static function render_row(array $r): void
    {
        $reg = $r['reg']; $p = $r['p']; $acc = $r['acc']; $id = (int) $reg['id'];
        $send = admin_url('admin.php?page=' . IQU_Billing_Send::SEND_SLUG . '&ids=' . $id);
        $pill = function (string $text, string $tone) {
            $styles = [
                'grey'  => 'background:#f0f0f1;color:#3c434a;border:1px solid #dcdcde',
                'amber' => 'background:#fcf0d0;color:#5c4400;border:1px solid #e6cf8b',
                'green' => 'background:#1d6b3a;color:#fff;border:1px solid #1d6b3a',
                'red'   => 'background:#8a2424;color:#fff;border:1px solid #8a2424',
                'blue'  => 'background:#e7f0f7;color:#1e4d6b;border:1px solid #c3d7e6',
            ];
            return '<span style="display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;' . $styles[$tone] . '">' . esc_html($text) . '</span>';
        };
        ?>
        <tr>
            <th scope="row" class="check-column" style="padding:10px">
                <?php if ($r['group'] === 'not_set_up'): ?>
                    <label class="screen-reader-text" for="s<?php echo $id; ?>">Select</label>
                    <input id="s<?php echo $id; ?>" type="checkbox" name="ids[]" value="<?php echo $id; ?>">
                <?php endif; ?>
            </th>
            <td>
                <strong><a href="<?php echo esc_url(admin_url('admin.php?page=iqu-view-registration&id=' . $id)); ?>"><?php echo esc_html($reg['first_name'] . ' ' . $reg['last_name']); ?></a></strong>
                <br><span class="description">IQU-<?php echo $id; ?><?php echo $reg['guardian_name'] ? ' · guardian ' . esc_html($reg['guardian_name']) : ''; ?></span>
                <br><span class="description"><?php echo esc_html($reg['email']); ?></span>
            </td>
            <td>
                Monthly classes
                <br><span class="description">
                    <?php echo esc_html($p['course_label'] ?: 'Course not set'); ?>
                    <?php if ($p['days_per_week']): ?> · <?php echo (int) $p['days_per_week']; ?> days/week<?php endif; ?>
                    <?php if (($reg['referral'] ?? '') === 'existing_student'): ?> · added from billing<?php endif; ?>
                </span>
            </td>
            <td>
                <?php if ($p['billable']): ?>
                    <?php if ($p['discount'] > 0): ?>
                        <span style="text-decoration:line-through;color:#787c82"><?php echo esc_html(IQU_Pricing::format($p['gross'])); ?></span>
                        <strong><?php echo esc_html(IQU_Pricing::format($p['net'])); ?></strong>
                        <br><span class="description"><?php echo esc_html($p['discount_label']); ?></span>
                    <?php else: ?>
                        <strong><?php echo esc_html(IQU_Pricing::format($p['net'])); ?></strong>
                        <?php if (!empty($p['legacy'])): ?><br><span class="description">fee chosen at enrollment</span><?php endif; ?>
                    <?php endif; ?>
                <?php else: ?>
                    —
                <?php endif; ?>
            </td>
            <td>
                <?php
                if ($acc) {
                    $tone = ['link_sent' => 'amber', 'active' => 'green', 'failed' => 'red'][$r['group']] ?? 'grey';
                    echo $pill(IQU_Billing_Send::status_label($acc['status']), $tone);
                    $bits = [];
                    if ($acc['first_charge_date']) $bits[] = 'first charge ' . wp_date('j M', strtotime($acc['first_charge_date'] . ' 12:00:00 UTC'));
                    $bits[] = $acc['email_sent_at'] ? 'email sent' : 'email not sent';
                    $bits[] = $acc['whatsapp_sent_at'] ? 'WhatsApp sent' : 'WhatsApp not sent';
                    echo '<br><span class="description">' . esc_html(implode(' · ', $bits)) . '</span>';
                } elseif ($r['group'] === 'not_set_up') {
                    echo $pill('Not set up', 'grey');
                } elseif ($r['group'] === 'needs_course') {
                    echo $pill('Needs course', 'blue');
                    echo '<br><span class="description">No course or fee on the record</span>';
                } else {
                    echo $pill('Not billed', 'grey');
                    if ($p['reason']) echo '<br><span class="description">' . esc_html($p['reason']) . '</span>';
                }
                ?>
            </td>
            <td>
                <div style="display:flex;flex-wrap:wrap;gap:6px">
                <?php if ($acc): ?>
                    <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=' . IQU_Billing_Send::MSG_SLUG . '&account=' . (int) $acc['id'])); ?>">Message</a>
                <?php elseif ($r['group'] === 'not_set_up'): ?>
                    <a class="button button-small button-primary" href="<?php echo esc_url($send); ?>">Send payment link</a>
                    <a class="button button-small" href="<?php echo esc_url($send . '&then=copy'); ?>">Copy message</a>
                <?php elseif ($r['group'] === 'needs_course'): ?>
                    <a class="button button-small" href="<?php echo esc_url($send); ?>">Choose course</a>
                <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php
    }

    // ------------------------------------------------------------
    // Payments tab (filled in once Stripe updates arrive)
    // ------------------------------------------------------------

    public static function render_payments(): void
    {
        if (!current_user_can(self::CAP)) wp_die('You do not have permission to view this page.', 403);
        global $wpdb;
        $mode = IQU_Stripe::expected_mode();
        $A    = IQU_Billing_DB::accounts_table();
        $P    = IQU_Billing_DB::payments_table();
        $now  = time();
        $m0   = strtotime(gmdate('Y-m-01 00:00:00') . ' UTC');
        $lm0  = strtotime(gmdate('Y-m-01 00:00:00', $m0 - DAY_IN_SECONDS) . ' UTC');

        $this_month = IQU_Billing_DB::paid_between($m0, $now + 60);
        $last_month = IQU_Billing_DB::paid_between($lm0, $m0);
        $on_billing = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(net_amount),0) AS t FROM {$A} WHERE mode = %s AND status IN ('free_month','waiting_first_charge','active','past_due')", $mode), ARRAY_A);
        $upcoming   = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$A} WHERE mode = %s AND status IN ('free_month','waiting_first_charge','active') AND next_charge_at IS NOT NULL AND next_charge_at < %s ORDER BY next_charge_at ASC LIMIT 100", $mode, gmdate('Y-m-d H:i:s', $now + 14 * DAY_IN_SECONDS)), ARRAY_A) ?: [];
        $problems   = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$A} WHERE mode = %s AND status IN ('past_due','unpaid','paused') ORDER BY updated_at DESC", $mode), ARRAY_A) ?: [];
        $recent     = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$P} WHERE mode = %s ORDER BY paid_at DESC LIMIT 50", $mode), ARRAY_A) ?: [];
        $up_total   = array_sum(array_map(fn($a) => (float) $a['net_amount'], $upcoming));
        $dash       = 'https://dashboard.stripe.com/' . ($mode === 'test' ? 'test/' : '') . 'invoices/';
        $msg        = fn(int $id) => esc_url(admin_url('admin.php?page=' . IQU_Billing_Send::MSG_SLUG . '&account=' . $id));
        $day        = fn(?string $utc) => $utc ? wp_date('j M Y', strtotime($utc . ' UTC')) : '—';
        ?>
        <div class="wrap">
            <h1>Billing</h1>
            <?php self::tabs('payments'); ?>
            <?php if ($mode === 'test'): ?><div class="notice notice-warning inline"><p><strong>Test mode.</strong> These are test payments, not real money.</p></div><?php endif; ?>

            <div style="display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;max-width:1200px;margin:14px 0 22px">
                <?php foreach ([
                    ['Collected this month', IQU_Pricing::format($this_month['total']), $this_month['count'] . ' payments'],
                    ['Last month', IQU_Pricing::format($last_month['total']), $last_month['count'] . ' payments'],
                    ['On billing', (int) $on_billing['n'] . ' families', IQU_Pricing::format((float) $on_billing['t']) . ' a month'],
                    ['Next 14 days', IQU_Pricing::format($up_total), count($upcoming) . ' charges expected'],
                    ['Payment problems', (string) count($problems), count($problems) ? 'see below' : 'none'],
                ] as [$label, $value, $sub]): ?>
                    <div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 14px<?php echo ($label === 'Payment problems' && $problems) ? ';border-color:#8a2424' : ''; ?>">
                        <div style="font-size:12px;color:#50575e"><?php echo esc_html($label); ?></div>
                        <div style="font-size:20px;font-weight:600;margin-top:4px"><?php echo esc_html($value); ?></div>
                        <div style="font-size:12px;color:#50575e"><?php echo esc_html($sub); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <h2>Needs attention</h2>
            <?php if (!$problems): ?>
                <p>No payment problems.</p>
            <?php else: ?>
                <table class="widefat striped" style="max-width:1200px"><thead><tr><th>Family</th><th>Monthly</th><th>Status</th><th>Detail</th><th></th></tr></thead><tbody>
                <?php foreach ($problems as $a): ?>
                    <tr>
                        <td><?php echo esc_html(self::family_label($a)); ?></td>
                        <td><?php echo esc_html(IQU_Pricing::format((float) $a['net_amount'])); ?></td>
                        <td><?php echo esc_html(IQU_Billing_Send::status_label($a['status'])); ?></td>
                        <td><?php echo esc_html($a['last_failure_reason'] ?: '—'); ?></td>
                        <td><a class="button button-small" href="<?php echo $msg((int) $a['id']); ?>">Message</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>

            <h2 style="margin-top:26px">Expected in the next 14 days</h2>
            <?php if (!$upcoming): ?>
                <p>No charges in the next 14 days.</p>
            <?php else: ?>
                <table class="widefat striped" style="max-width:1200px"><thead><tr><th>Date</th><th>Family</th><th>Amount</th><th>Paying from</th><th></th></tr></thead><tbody>
                <?php foreach ($upcoming as $a): ?>
                    <tr>
                        <td><?php echo esc_html($day($a['next_charge_at'])); ?></td>
                        <td><?php echo esc_html(self::family_label($a)); ?><?php echo $a['status'] === 'free_month' ? ' <span class="description">(end of free month)</span>' : ''; ?></td>
                        <td><?php echo esc_html(IQU_Pricing::format((float) $a['net_amount'])); ?></td>
                        <td><?php echo esc_html($a['payment_method_label'] ?: '—'); ?></td>
                        <td><a class="button button-small" href="<?php echo $msg((int) $a['id']); ?>">Message</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>

            <h2 style="margin-top:26px">Recent payments</h2>
            <?php if (!$recent): ?>
                <p>No payments received yet.</p>
            <?php else: ?>
                <table class="widefat striped" style="max-width:1200px"><thead><tr><th>Paid</th><th>Family</th><th>Amount</th><th>Invoice</th></tr></thead><tbody>
                <?php foreach ($recent as $p):
                    $a = IQU_Billing_DB::get_account((int) $p['account_id']); ?>
                    <tr>
                        <td><?php echo esc_html($day($p['paid_at'])); ?></td>
                        <td><?php echo $a ? esc_html(self::family_label($a)) : '—'; ?></td>
                        <td><?php echo esc_html(IQU_Pricing::format((float) $p['amount'])); ?></td>
                        <td><a href="<?php echo esc_url($dash . rawurlencode($p['stripe_invoice_id'])); ?>" target="_blank" rel="noopener noreferrer">View in Stripe</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>
        </div>
        <?php
    }

    /** "Sara Khan — Ayesha K., Bilal K." */
    private static function family_label(array $acc): string
    {
        $names = [];
        foreach (IQU_Billing_DB::get_members((int) $acc['id']) as $m) {
            $reg = IQU_Database::get_registration((int) $m['registration_id']);
            if ($reg) $names[] = IQU_Billing_Service::short_name($reg);
        }
        $g = trim((string) $acc['guardian_name']);
        return ($g !== '' ? $g . ' — ' : '') . implode(', ', $names);
    }

    // ------------------------------------------------------------
    // Bulk send
    // ------------------------------------------------------------

    /** Group selected enrollments into families by email. */
    private static function families(array $ids): array
    {
        $fam = [];
        foreach ($ids as $id) {
            $reg = IQU_Database::get_registration($id);
            if (!$reg || !in_array($reg['form_type'], self::MONTHLY_FORMS, true)) continue;
            if (IQU_Billing_DB::account_for_registration($id)) continue;
            $p = IQU_Billing_Pricing::for_registration($reg);
            if (!$p['billable']) continue;
            $key = substr(md5(strtolower(trim($reg['email']))), 0, 12);
            $fam[$key]['email'] = $reg['email'];
            $fam[$key]['regs'][] = $reg;
            $fam[$key]['total'] = ($fam[$key]['total'] ?? 0) + $p['net'];
        }
        return $fam;
    }

    public static function render_bulk(): void
    {
        if (!current_user_can(self::CAP)) wp_die('You do not have permission to view this page.', 403);

        echo '<div class="wrap"><h1>Billing</h1>';
        self::tabs('students');

        $done = get_transient(self::TX . get_current_user_id());
        if ($done) {
            delete_transient(self::TX . get_current_user_id());
            echo '<h2>Result</h2><table class="widefat striped" style="max-width:980px"><thead><tr><th>Family</th><th>Result</th><th></th></tr></thead><tbody>';
            foreach ($done as $d) {
                echo '<tr><td>' . esc_html($d['who']) . '</td><td>' . esc_html($d['text']) . '</td><td>';
                if (!empty($d['account'])) echo '<a class="button button-small" href="' . esc_url(admin_url('admin.php?page=' . IQU_Billing_Send::MSG_SLUG . '&account=' . (int) $d['account'])) . '">Message</a>';
                echo '</td></tr>';
            }
            echo '</tbody></table><p><a href="' . esc_url(admin_url('admin.php?page=' . self::SLUG)) . '">Back to students</a></p></div>';
            return;
        }

        $ids = array_values(array_unique(array_filter(array_map('absint', (array) ($_GET['ids'] ?? [])))));
        $fam = self::families($ids);
        if (!$fam) {
            echo '<p>Nothing to send. Choose students marked “Not set up” on the Students tab.</p></div>';
            return;
        }
        if (count($fam) > self::BULK_MAX) {
            echo '<div class="notice notice-error"><p>Please choose at most ' . (int) self::BULK_MAX . ' families at a time.</p></div></div>';
            return;
        }
        ?>
        <h2>Send payment links to <?php echo count($fam); ?> famil<?php echo count($fam) === 1 ? 'y' : 'ies'; ?></h2>
        <p>Check each family's first charge date. New students are filled in with the end of their free month. For current students, use their usual due date this month — or today if it has passed.</p>
        <?php if (IQU_Stripe::expected_mode() === 'test'): ?>
            <div class="notice notice-warning inline"><p><strong>Test mode:</strong> billing records are created in Stripe test mode and no email goes to families.</p></div>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::A_BULK); ?>">
            <?php wp_nonce_field(self::A_BULK); ?>
            <table class="widefat striped" style="max-width:1100px">
                <thead><tr><th>Send</th><th>Family</th><th>Students</th><th>Monthly</th><th>First charge</th></tr></thead>
                <tbody>
                <?php foreach ($fam as $key => $f):
                    $types = array_unique(array_map(['IQU_Billing_Service', 'student_type'], $f['regs']));
                    $suggest = (count($f['regs']) === 1 && $types === ['new']) ? (string) IQU_Billing_Service::suggested_first_charge($f['regs'][0]) : '';
                    $guardian = trim((string) $f['regs'][0]['guardian_name']); ?>
                    <tr>
                        <td><input type="checkbox" name="inc[<?php echo esc_attr($key); ?>]" value="1" checked aria-label="Send to this family"></td>
                        <td><?php echo esc_html($guardian ?: '—'); ?><br><span class="description"><?php echo esc_html($f['email']); ?></span></td>
                        <td><?php foreach ($f['regs'] as $reg): ?>
                                <?php echo esc_html($reg['first_name'] . ' ' . $reg['last_name']); ?> <span class="description">(<?php echo esc_html(IQU_Billing_Service::student_type($reg) === 'new' ? 'new' : 'current'); ?>)</span><br>
                                <input type="hidden" name="fam[<?php echo esc_attr($key); ?>][]" value="<?php echo (int) $reg['id']; ?>">
                            <?php endforeach; ?></td>
                        <td><strong><?php echo esc_html(IQU_Pricing::format((float) $f['total'])); ?></strong></td>
                        <td><label class="screen-reader-text" for="d<?php echo esc_attr($key); ?>">First charge date</label>
                            <input id="d<?php echo esc_attr($key); ?>" type="date" name="date[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($suggest); ?>" min="<?php echo esc_attr(gmdate('Y-m-d')); ?>" max="<?php echo esc_attr(gmdate('Y-m-d', time() + 45 * DAY_IN_SECONDS)); ?>"></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p style="display:flex;gap:8px;margin-top:14px">
                <button type="submit" class="button button-primary">Create billing and send emails</button>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . self::SLUG)); ?>">Cancel</a>
            </p>
            <p class="description">Families without a date are skipped. After sending, each family's WhatsApp message is one click away in the result list.</p>
        </form>
        </div>
        <?php
    }

    public static function handle_bulk(): void
    {
        if (!current_user_can(self::CAP)) wp_die('You do not have permission to do this.', 403);
        check_admin_referer(self::A_BULK);

        $fam   = (array) ($_POST['fam'] ?? []);
        $inc   = (array) ($_POST['inc'] ?? []);
        $dates = (array) ($_POST['date'] ?? []);
        $out   = [];
        $n     = 0;

        foreach ($fam as $key => $ids) {
            $key = substr(preg_replace('/[^a-f0-9]/', '', (string) $key), 0, 12);
            if ($key === '' || empty($inc[$key])) continue;
            if (++$n > self::BULK_MAX) break;

            $ids  = array_values(array_unique(array_filter(array_map('absint', (array) $ids))));
            $date = sanitize_text_field(wp_unslash((string) ($dates[$key] ?? '')));
            $who  = implode(', ', array_filter(array_map(function ($id) { $r = IQU_Database::get_registration($id); return $r ? $r['first_name'] . ' ' . $r['last_name'] : ''; }, $ids)));

            if ($date === '') { $out[] = ['who' => $who, 'text' => 'Skipped: no first charge date.']; continue; }

            $r = IQU_Billing_Service::create_account($ids, ['first_charge_date' => $date]);
            if (!$r['ok']) { $out[] = ['who' => $who, 'text' => 'Not created: ' . implode(' ', $r['errors'])]; continue; }

            $acc  = IQU_Billing_DB::get_account($r['account_id']);
            $sent = IQU_Billing_Send::send_email($acc);
            $out[] = [
                'who'     => $who,
                'account' => (int) $r['account_id'],
                'text'    => $sent ? 'Billing created, email sent.' : 'Billing created. Email not sent — ' . (IQU_Billing_Send::email_blocked_reason($acc) ?: 'check the site email settings.'),
            ];
        }

        set_transient(self::TX . get_current_user_id(), $out ?: [['who' => '—', 'text' => 'Nothing was selected.']], 300);
        wp_safe_redirect(admin_url('admin.php?page=' . self::BULK_SLUG));
        exit;
    }
}
