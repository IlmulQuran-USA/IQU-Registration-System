<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Waitlist_Admin
 *
 * IQU Registrations → Waitlist: families outside the US and Canada who asked to hear
 * when classes open in their country. Filters by country and program; CSV export.
 *
 * Security: manage_options on the page and the export; nonce on the export;
 * CSV cells guarded against formulas; all output escaped.
 */
class IQU_Waitlist_Admin
{
    public const SLUG    = 'iqu-waitlist';
    private const CAP    = 'manage_options';
    private const EXPORT = 'iqu_waitlist_export';
    private const PER    = 50;
    private const PROGRAM_LABELS = ['free' => 'Enroll for Free', 'summer' => 'Summer', 'weekend' => 'Weekend'];

    public static function init(): void
    {
        add_action('admin_menu', [__CLASS__, 'menu'], 30);
        add_action('admin_post_' . self::EXPORT, [__CLASS__, 'export']);
    }

    public static function menu(): void
    {
        add_submenu_page('iqu-registrations', 'Waitlist — IQU', 'Waitlist', self::CAP, self::SLUG, [__CLASS__, 'render']);
    }

    /** Filters from a request array, validated. */
    private static function filters(array $src): array
    {
        $country = strtoupper(sanitize_text_field(wp_unslash($src['country'] ?? '')));
        $program = sanitize_key(wp_unslash($src['program'] ?? ''));
        return [
            preg_match('/^[A-Z]{2}$/', $country) ? $country : '',
            isset(self::PROGRAM_LABELS[$program]) ? $program : '',
        ];
    }

    /** Leading = + - @ tab or CR would make a spreadsheet run the cell as a formula. */
    public static function csv_safe(string $v): string
    {
        if ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false && !preg_match('/^-?\d+(\.\d+)?$/', $v)) return "'" . $v;
        return $v;
    }

    public static function write_csv($out, array $rows): void
    {
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Email', 'Name', 'Country', 'Program', 'Source', 'Joined (UTC)'], ',', '"', '');
        foreach ($rows as $r) {
            fputcsv($out, array_map(fn($v) => self::csv_safe((string) $v), [
                $r['email'], $r['name'], $r['country'], self::PROGRAM_LABELS[$r['program']] ?? $r['program'], $r['source'], $r['created_at'],
            ]), ',', '"', '');
        }
    }

    public static function export(): void
    {
        if (!current_user_can(self::CAP)) wp_die('You do not have permission to do this.', 403);
        check_admin_referer(self::EXPORT);
        [$country, $program] = self::filters($_GET);
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="iqu-waitlist-' . gmdate('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        self::write_csv($out, IQU_Waitlist::rows($country, $program));
        fclose($out);
        exit;
    }

    public static function render(): void
    {
        if (!current_user_can(self::CAP)) wp_die('You do not have permission to view this page.', 403);
        [$country, $program] = self::filters($_GET);
        $page   = max(1, absint($_GET['paged'] ?? 1));
        $total  = IQU_Waitlist::count($country, $program);
        $rows   = IQU_Waitlist::rows($country, $program, self::PER, ($page - 1) * self::PER);
        $export = wp_nonce_url(add_query_arg(array_filter(['action' => self::EXPORT, 'country' => $country, 'program' => $program]), admin_url('admin-post.php')), self::EXPORT);
        ?>
        <div class="wrap iqu-admin-wrap">
            <h1>Waitlist</h1>
            <p>Families outside the United States and Canada who asked to be told when classes open in their country. Only the email, the optional name and a 2-letter country code are kept.</p>

            <form method="GET" class="iqu-filter-bar">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
                <select name="country" aria-label="Country">
                    <option value="">All countries</option>
                    <?php foreach (IQU_Waitlist::countries() as $c): ?>
                        <option value="<?php echo esc_attr($c); ?>" <?php selected($country, $c); ?>><?php echo esc_html($c); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="program" aria-label="Program">
                    <option value="">All programs</option>
                    <?php foreach (self::PROGRAM_LABELS as $k => $label): ?>
                        <option value="<?php echo esc_attr($k); ?>" <?php selected($program, $k); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button">Filter</button>
                <a href="<?php echo esc_url($export); ?>" class="iqu-export-btn">⬇ Export CSV</a>
            </form>

            <p><strong><?php echo (int) $total; ?></strong> <?php echo $total === 1 ? 'family' : 'families'; ?></p>
            <div class="iqu-table-wrap">
                <table class="iqu-tbl">
                    <thead><tr><th>Email</th><th>Name</th><th>Country</th><th>Program</th><th>Source</th><th>Joined</th></tr></thead>
                    <tbody>
                    <?php if (!$rows): ?>
                        <tr><td colspan="6" class="iqu-empty-state">Nobody on the waitlist yet.</td></tr>
                    <?php else: foreach ($rows as $r): ?>
                        <tr>
                            <td><?php echo esc_html($r['email']); ?></td>
                            <td><?php echo esc_html($r['name']); ?></td>
                            <td><?php echo esc_html($r['country'] ?: '—'); ?></td>
                            <td><?php echo esc_html(self::PROGRAM_LABELS[$r['program']] ?? $r['program']); ?></td>
                            <td><?php echo esc_html($r['source']); ?></td>
                            <td><?php echo esc_html(wp_date('j M Y', (int) strtotime($r['created_at'] . ' UTC'))); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($total > self::PER): ?>
                <div class="tablenav"><div class="tablenav-pages"><?php echo wp_kses_post(paginate_links([
                    'base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $page, 'total' => (int) ceil($total / self::PER),
                ])); ?></div></div>
            <?php endif; ?>
        </div>
        <?php
    }
}
