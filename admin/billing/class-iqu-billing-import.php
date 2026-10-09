<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Billing_Import
 *
 * Admin page: IQU Registrations → Import Students (CSV).
 * Two steps: upload + preview, then confirm. Nothing is saved until the
 * admin has seen every row and pressed Import.
 *
 * Security:
 * - manage_options only; separate nonce on sample download, upload and confirm.
 * - The upload is read in memory and never moved or kept on the server.
 * - .csv only, 1 MB and 500 rows maximum.
 * - Every row goes through IQU_Billing_Add_Student::prepare() — the same
 *   validation and pricing as the single form — and is checked again at confirm.
 * - Fees are always computed by IQU_Pricing; an agreed fee can only lower it.
 * - Cells are cleaned of spreadsheet formula characters.
 */
class IQU_Billing_Import
{
    public const PAGE_SLUG  = 'iqu-billing-import';
    private const CAP       = 'manage_options';
    private const A_SAMPLE  = 'iqu_billing_import_sample';
    private const A_UPLOAD  = 'iqu_billing_import_upload';
    private const A_CONFIRM = 'iqu_billing_import_confirm';
    private const A_CANCEL  = 'iqu_billing_import_cancel';
    private const TX        = 'iqu_billing_import_';
    private const MAX_BYTES = 1048576;
    private const MAX_ROWS  = 500;

    /** Sample file columns, in order. Names match the existing CSV export. */
    private const COLUMNS = [
        ['First Name',         true,  'Student first name.',                                   'Ayesha'],
        ['Last Name',          true,  'Student last name.',                                    'Khan'],
        ['Guardian',           false, 'Parent or guardian. Leave empty for adult students.',  'Sara Khan'],
        ['Email',              true,  'Billing email. The payment link and receipts go here. Siblings may share one.', 'sara.k@example.com'],
        ['WhatsApp',           true,  'With country code. If empty, Guardian WhatsApp is used.', '+1 214 555 0148'],
        ['Guardian WhatsApp',  false, 'Used when WhatsApp is empty.',                          ''],
        ['Course',             true,  'qaidah, hifz or arabic (the full course name also works).', 'qaidah'],
        ['Days/Week',          true,  'Classes per week, 1 to 7. Hifz needs at least 3.',     '4'],
        ['Agreed Monthly Fee', false, 'Only for families who pay less than the standard fee. Number only. 0 means full scholarship. Leave empty for the standard fee.', ''],
        ['Zakat',              false, 'Yes if the reduced fee is paid by the Zakat Fund. Otherwise leave empty or No.', 'No'],
        ['Age',                false, 'Student age.',                                          '9'],
        ['Country Residence',  false, 'Country the student lives in.',                         'United States'],
        ['Preferred Days',     false, 'For example: Mon, Tue, Thu, Fri.',                      'Mon, Tue, Thu, Fri'],
        ['Time Slot',          false, 'Usual class time.',                                     '5:00 PM CST'],
        ['Session Duration',   false, 'For example: 30 min.',                                  '30 min'],
        ['Languages',          false, 'Languages spoken.',                                     'English, Bangla'],
        ['Teacher Pref',       false, 'Male, female or any.',                                  'female'],
        ['Note',               false, 'Anything the admin should know.',                       ''],
    ];

    /** Normalised header => field name. Export column names are accepted. */
    private const HEADER_MAP = [
        'firstname' => 'first_name', 'lastname' => 'last_name',
        'guardian' => 'guardian_name', 'guardianname' => 'guardian_name',
        'email' => 'email', 'whatsapp' => 'whatsapp', 'guardianwhatsapp' => 'guardian_whatsapp',
        'course' => 'course', 'daysweek' => 'days', 'daysperweek' => 'days',
        'agreedmonthlyfee' => 'agreed_fee', 'zakat' => 'zakat', 'zakatsupport' => 'zakat',
        'declarationsigned' => 'zakat', 'age' => 'age', 'countryresidence' => 'country_res',
        'preferreddays' => 'preferred_days', 'timeslot' => 'time_slot',
        'sessionduration' => 'session_dur', 'languages' => 'languages',
        'teacherpref' => 'teacher_pref', 'note' => 'note',
    ];

    public static function init(): void
    {
        add_action('admin_menu', [__CLASS__, 'register_menu'], 22);
        add_action('admin_post_' . self::A_SAMPLE, [__CLASS__, 'download_sample']);
        add_action('admin_post_' . self::A_UPLOAD, [__CLASS__, 'handle_upload']);
        add_action('admin_post_' . self::A_CONFIRM, [__CLASS__, 'handle_confirm']);
        add_action('admin_post_' . self::A_CANCEL, [__CLASS__, 'handle_cancel']);
        add_action('admin_footer', [__CLASS__, 'add_button_next_to_export']);
    }

    public static function register_menu(): void
    {
        add_submenu_page('iqu-registrations', 'Import Students — IQU', 'Import Students', self::CAP, self::PAGE_SLUG, [__CLASS__, 'render']);
    }

    /** Puts an "Import CSV" button beside "Export CSV" on the Enroll for Free list. */
    public static function add_button_next_to_export(): void
    {
        if (!current_user_can(self::CAP) || ($_GET['page'] ?? '') !== 'iqu-list-free') return;
        $url = admin_url('admin.php?page=' . self::PAGE_SLUG);
        ?>
        <script>
        (function () {
            var exp = document.querySelector('a.iqu-export-btn');
            if (!exp || document.getElementById('iqu-import-btn')) return;
            var a = document.createElement('a');
            a.id = 'iqu-import-btn';
            a.className = exp.className;
            a.href = <?php echo wp_json_encode($url); ?>;
            var icon = document.createElement('span');
            icon.className = 'dashicons dashicons-upload';
            icon.setAttribute('aria-hidden', 'true');
            a.appendChild(icon);
            a.appendChild(document.createTextNode('Import CSV'));
            a.style.marginLeft = '6px';
            exp.parentNode.insertBefore(a, exp.nextSibling);
        })();
        </script>
        <?php
    }

    // ------------------------------------------------------------
    // Sample file
    // ------------------------------------------------------------

    public static function download_sample(): void
    {
        if (!current_user_can(self::CAP)) wp_die('You do not have permission to do this.', 403);
        check_admin_referer(self::A_SAMPLE);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="iqu-import-students-sample.csv"');
        $out = fopen('php://output', 'w');
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, array_column(self::COLUMNS, 0));
        fputcsv($out, array_column(self::COLUMNS, 3));
        fputcsv($out, ['Omar', 'Rahman', '', 'omar.r@example.com', '+1 469 555 0172', '', 'arabic', '2', '30', 'Yes', '24', 'United States', 'Sat, Sun', '10:00 AM CST', '30 min', 'English, Arabic', 'any', 'Adult student, reduced fee agreed']);
        fclose($out);
        exit;
    }

    // ------------------------------------------------------------
    // Step 1: upload + preview
    // ------------------------------------------------------------

    public static function handle_upload(): void
    {
        if (!current_user_can(self::CAP)) wp_die('You do not have permission to do this.', 403);
        check_admin_referer(self::A_UPLOAD);

        $file = $_FILES['csv'] ?? null;
        $fail = function (string $msg) { self::store(['error' => $msg]); self::back(); };

        if (!$file || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) $fail('The file did not upload. Please try again.');
        if (!is_uploaded_file($file['tmp_name'])) $fail('The upload could not be verified.');
        if ((int) $file['size'] <= 0 || (int) $file['size'] > self::MAX_BYTES) $fail('The file must be a CSV under 1 MB.');
        if (strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'csv') $fail('Please upload a .csv file. In Excel or Google Sheets use "Save as / Download as CSV".');

        $fh = fopen($file['tmp_name'], 'r');
        if (!$fh) $fail('The file could not be read.');

        $header = fgetcsv($fh, 0, ',', '"', '');
        if (!$header) { fclose($fh); $fail('The file is empty.'); }
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);

        $map = [];
        foreach ($header as $i => $h) {
            $norm = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $h));
            if (isset(self::HEADER_MAP[$norm]) && !in_array(self::HEADER_MAP[$norm], $map, true)) {
                $map[$i] = self::HEADER_MAP[$norm];
            }
        }
        $missing = [];
        foreach (['first_name' => 'First Name', 'last_name' => 'Last Name', 'email' => 'Email', 'course' => 'Course', 'days' => 'Days/Week'] as $field => $label) {
            if (!in_array($field, $map, true)) $missing[] = $label;
        }
        if (!in_array('whatsapp', $map, true) && !in_array('guardian_whatsapp', $map, true)) $missing[] = 'WhatsApp';
        if ($missing) { fclose($fh); $fail('These columns are missing: ' . implode(', ', $missing) . '. Use the sample file as a guide.'); }

        $rows = [];
        $line = 1;
        $seen = [];
        while (($cells = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            $line++;
            if (count(array_filter($cells, fn($c) => trim((string) $c) !== '')) === 0) continue;
            if (count($rows) >= self::MAX_ROWS) {
                fclose($fh);
                $fail('The file has more than ' . self::MAX_ROWS . ' students. Split it into smaller files.');
            }

            $raw = [];
            foreach ($map as $i => $field) $raw[$field] = trim((string) ($cells[$i] ?? ''));

            $in   = self::to_input($raw);
            $prep = IQU_Billing_Add_Student::prepare($in);
            $key  = strtolower($in['first_name'] . '|' . $in['last_name'] . '|' . $in['email']);
            if (!$prep['errors'] && isset($seen[$key])) {
                $prep['errors'][] = 'Same student appears twice in this file (row ' . $seen[$key] . ').';
            }
            $seen[$key] = $seen[$key] ?? $line;

            $rows[] = [
                'line'   => $line,
                'in'     => $in,
                'errors' => $prep['errors'],
                'gross'  => $prep['gross'],
                'net'    => $prep['net'],
            ];
        }
        fclose($fh);

        if (!$rows) $fail('No students found in the file.');

        self::store(['preview' => $rows, 'file' => sanitize_file_name((string) $file['name']), 'key' => wp_generate_password(20, false)]);
        self::back();
    }

    /** Turns one CSV row into the same input shape the single form uses. */
    private static function to_input(array $r): array
    {
        $wa = $r['whatsapp'] ?? '';
        if ($wa === '') $wa = $r['guardian_whatsapp'] ?? '';
        $wa = trim(substr(preg_replace('/[^0-9+\-\s()]/', '', $wa), 0, 25));

        $fee_raw = preg_replace('/[^0-9.]/', '', (string) ($r['agreed_fee'] ?? ''));
        $zak     = strtolower((string) ($r['zakat'] ?? ''));

        return [
            'first_name'    => IQU_Billing_Add_Student::clean_text($r['first_name'] ?? ''),
            'last_name'     => IQU_Billing_Add_Student::clean_text($r['last_name'] ?? ''),
            'guardian_name' => IQU_Billing_Add_Student::clean_text($r['guardian_name'] ?? ''),
            'email'         => sanitize_email($r['email'] ?? ''),
            'whatsapp'      => $wa,
            'course'        => self::normalise_course((string) ($r['course'] ?? '')),
            'days'          => (int) preg_replace('/\D/', '', (string) ($r['days'] ?? '')),
            'use_agreed'    => $fee_raw !== '',
            'agreed_fee'    => $fee_raw !== '' ? (float) $fee_raw : 0.0,
            'zakat'         => in_array($zak, ['yes', 'y', 'true', '1'], true),
            'note'          => sanitize_textarea_field((string) ($r['note'] ?? '')),
            'extra'         => [
                'age'            => (int) ($r['age'] ?? 0),
                'country_res'    => $r['country_res'] ?? '',
                'preferred_days' => $r['preferred_days'] ?? '',
                'time_slot'      => $r['time_slot'] ?? '',
                'session_dur'    => $r['session_dur'] ?? '',
                'languages'      => $r['languages'] ?? '',
                'teacher_pref'   => $r['teacher_pref'] ?? '',
            ],
        ];
    }

    /** Accepts the course key, its short code, or its full name. */
    private static function normalise_course(string $value): string
    {
        $flat = strtolower(preg_replace('/[^a-z0-9]/i', '', $value));
        if ($flat === '') return '';
        foreach (IQU_Pricing::course_keys() as $key) {
            if ($flat === strtolower($key)) return $key;
            if ($flat === strtolower(IQU_Pricing::short_code($key))) return $key;
            if ($flat === strtolower(preg_replace('/[^a-z0-9]/i', '', IQU_Pricing::label($key)))) return $key;
        }
        return sanitize_key($value);
    }

    // ------------------------------------------------------------
    // Step 2: confirm
    // ------------------------------------------------------------

    public static function handle_confirm(): void
    {
        if (!current_user_can(self::CAP)) wp_die('You do not have permission to do this.', 403);
        check_admin_referer(self::A_CONFIRM);

        $state = self::load();
        $key   = sanitize_text_field(wp_unslash($_POST['key'] ?? ''));
        if (empty($state['preview']) || !hash_equals((string) ($state['key'] ?? ''), $key)) {
            self::store(['error' => 'This preview has expired. Please upload the file again.']);
            self::back();
        }

        $added = 0; $billed = 0; $failed = [];
        foreach ($state['preview'] as $r) {
            if ($r['errors']) continue;
            $prep = IQU_Billing_Add_Student::prepare($r['in']); // check again: records may have changed
            if ($prep['errors']) { $failed[] = 'Row ' . $r['line'] . ': ' . implode(' ', $prep['errors']); continue; }
            $id = IQU_Database::insert_registration($prep['row']);
            if (!$id) { $failed[] = 'Row ' . $r['line'] . ': could not be saved.'; continue; }
            $added++;
            if ($prep['net'] > 0) $billed++;
        }

        self::store(['done' => ['added' => $added, 'billed' => $billed, 'failed' => $failed]]);
        self::back();
    }

    public static function handle_cancel(): void
    {
        if (!current_user_can(self::CAP)) wp_die('You do not have permission to do this.', 403);
        check_admin_referer(self::A_CANCEL);
        delete_transient(self::TX . get_current_user_id());
        self::back();
    }

    // ------------------------------------------------------------
    // State
    // ------------------------------------------------------------

    private static function store(array $data): void
    {
        set_transient(self::TX . get_current_user_id(), $data, 30 * MINUTE_IN_SECONDS);
    }

    private static function load(): array
    {
        $d = get_transient(self::TX . get_current_user_id());
        return is_array($d) ? $d : [];
    }

    private static function back(): void
    {
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG));
        exit;
    }

    // ------------------------------------------------------------
    // Page
    // ------------------------------------------------------------

    public static function render(): void
    {
        if (!current_user_can(self::CAP)) wp_die('You do not have permission to view this page.', 403);

        $state = self::load();
        if (!empty($state['error']) || !empty($state['done'])) {
            delete_transient(self::TX . get_current_user_id());
        }
        $post = esc_url(admin_url('admin-post.php'));
        ?>
        <div class="wrap iqu-admin-wrap iqu-billing">
            <?php IQU_Billing_Page::tabs('import'); ?>

            <?php if (!empty($state['error'])): ?>
                <div class="notice notice-error"><p><?php echo esc_html($state['error']); ?></p></div>
            <?php endif; ?>

            <?php if (!empty($state['done'])): $d = $state['done']; ?>
                <div class="notice notice-success"><p>
                    <strong><?php echo (int) $d['added']; ?> students added.</strong>
                    <?php echo (int) $d['billed']; ?> will be billed, <?php echo (int) ($d['added'] - $d['billed']); ?> on full scholarship.
                    <a href="<?php echo esc_url(admin_url('admin.php?page=iqu-list-free')); ?>">See them in Enroll for Free</a>
                </p></div>
                <?php if (!empty($d['failed'])): ?>
                    <div class="notice notice-warning"><p><strong>Not added:</strong></p><ul class="iqu-billing-notice-list">
                        <?php foreach ($d['failed'] as $f): ?><li><?php echo esc_html($f); ?></li><?php endforeach; ?>
                    </ul></div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="iqu-card">
                <div class="iqu-card-head">
                    <span class="iqu-card-head-title">Import students</span>
                    <span class="iqu-card-head-badge">CSV · nothing saved until you confirm</span>
                </div>
                <div class="iqu-billing-body">
                    <p class="iqu-billing-intro">Add many existing students at once from a spreadsheet. They are added to the enrollment records like any other student, and billed as current students (no free month). Nothing is saved until you check the preview and press Import.</p>
                </div>
            </div>

            <?php if (!empty($state['preview'])): self::render_preview($state); else: ?>

            <!-- ── 1. Sample ─────────────────────────────────── -->
            <div class="iqu-card">
                <div class="iqu-card-head">
                    <span class="iqu-card-head-title">1. Download the sample file</span>
                </div>
                <div class="iqu-billing-body">
                    <p class="iqu-billing-intro">Fill it in with Excel or Google Sheets, one student per row, then save it as CSV. You can also use a file from <strong>Export CSV</strong> — the column names are the same, and columns the system calculates itself are ignored.</p>
                </div>
                <form method="post" action="<?php echo $post; ?>" class="iqu-cpn-actions">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::A_SAMPLE); ?>">
                    <?php wp_nonce_field(self::A_SAMPLE); ?>
                    <?php submit_button('Download sample CSV', 'secondary', 'submit', false); ?>
                </form>
            </div>

            <!-- ── 2. Columns ────────────────────────────────── -->
            <div class="iqu-card">
                <div class="iqu-card-head">
                    <span class="iqu-card-head-title">2. What goes in each column</span>
                </div>
                <div class="iqu-table-wrap">
                <table class="iqu-tbl iqu-billing-tbl">
                    <thead><tr><th>Column</th><th>Required</th><th>What to enter</th><th>Example</th></tr></thead>
                    <tbody>
                    <?php foreach (self::COLUMNS as $c): ?>
                        <tr>
                            <td><strong><?php echo esc_html($c[0]); ?></strong></td>
                            <td><?php echo $c[1] ? 'Yes' : '—'; ?></td>
                            <td class="iqu-billing-wrap"><?php echo esc_html($c[2]); ?></td>
                            <td><code><?php echo esc_html($c[3] !== '' ? $c[3] : '(empty)'); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <div class="iqu-billing-body">
                    <p class="iqu-fld-hint">The monthly fee is not a column on purpose. It is always worked out from Course and Days/Week using the pricing rules. Use Agreed Monthly Fee only for families who pay less.</p>
                </div>
            </div>

            <!-- ── 3. Upload ─────────────────────────────────── -->
            <div class="iqu-card">
                <div class="iqu-card-head">
                    <span class="iqu-card-head-title">3. Upload and check</span>
                </div>
                <form method="post" action="<?php echo $post; ?>" enctype="multipart/form-data" class="iqu-cpn-form">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::A_UPLOAD); ?>">
                    <?php wp_nonce_field(self::A_UPLOAD); ?>
                    <div class="iqu-fld-grid">
                        <div class="iqu-fld iqu-fld--full">
                            <label for="iqu-csv">CSV file <em>*</em></label>
                            <input id="iqu-csv" type="file" name="csv" accept=".csv,text/csv" required>
                            <p class="iqu-fld-hint">CSV only, up to 1 MB and <?php echo (int) self::MAX_ROWS; ?> students. The file is checked and then discarded; it is not stored on the server.</p>
                        </div>
                    </div>
                    <div class="iqu-cpn-actions">
                        <?php submit_button('Upload and preview', 'primary', 'submit', false); ?>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function render_preview(array $state): void
    {
        $rows  = $state['preview'];
        $ok    = array_filter($rows, fn($r) => !$r['errors']);
        $bad   = count($rows) - count($ok);
        $total = array_sum(array_map(fn($r) => (float) $r['net'], $ok));
        $post  = esc_url(admin_url('admin-post.php'));
        ?>
        <!-- ── Preview summary ──────────────────────────── -->
        <div class="iqu-metrics">
            <div class="iqu-metric">
                <div class="iqu-metric-accent iqu-metric-accent--l1"></div>
                <div class="iqu-metric-num"><?php echo count($ok); ?></div>
                <div class="iqu-metric-lbl">Ready to add</div>
            </div>
            <div class="iqu-metric">
                <div class="iqu-metric-accent iqu-metric-accent--problem"></div>
                <div class="iqu-metric-num"><?php echo (int) $bad; ?></div>
                <div class="iqu-metric-lbl">With problems</div>
                <div class="iqu-metric-sub">These will be skipped</div>
            </div>
            <div class="iqu-metric">
                <div class="iqu-metric-accent iqu-metric-accent--revenue"></div>
                <div class="iqu-metric-num"><?php echo esc_html(IQU_Pricing::format($total)); ?></div>
                <div class="iqu-metric-lbl">Monthly tuition</div>
                <div class="iqu-metric-sub">From the new students</div>
            </div>
        </div>

        <div class="iqu-card">
            <div class="iqu-card-head">
                <span class="iqu-card-head-title">Preview — <?php echo esc_html((string) $state['file']); ?></span>
                <span class="iqu-card-head-badge"><?php echo count($rows); ?> rows</span>
            </div>
            <div class="iqu-table-wrap">
            <table class="iqu-tbl iqu-billing-tbl">
                <thead><tr><th>Row</th><th>Student</th><th>Email</th><th>Course</th><th>Standard</th><th>Monthly</th><th>Result</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): $in = $r['in']; ?>
                    <tr>
                        <td class="iqu-td-id"><?php echo (int) $r['line']; ?></td>
                        <td><?php echo esc_html(trim($in['first_name'] . ' ' . $in['last_name'])); ?>
                            <?php if ($in['guardian_name'] !== ''): ?><br><span class="iqu-billing-sub">Guardian: <?php echo esc_html($in['guardian_name']); ?></span><?php endif; ?></td>
                        <td class="iqu-td-email"><?php echo esc_html($in['email']); ?></td>
                        <td><?php echo esc_html(IQU_Pricing::is_valid_course($in['course']) ? IQU_Pricing::label($in['course']) : ($in['course'] ?: '—')); ?>
                            <?php if ($in['days']): ?><br><span class="iqu-billing-sub"><?php echo (int) $in['days']; ?> days/week</span><?php endif; ?></td>
                        <td><?php echo $r['gross'] > 0 ? esc_html(IQU_Pricing::format((float) $r['gross'])) : '—'; ?></td>
                        <td><?php echo !$r['errors'] ? esc_html(IQU_Pricing::format((float) $r['net'])) : '—'; ?></td>
                        <td class="iqu-billing-wrap">
                            <?php if ($r['errors']): ?>
                                <span class="iqu-billing-error"><strong>Skipped:</strong> <?php echo esc_html(implode(' ', $r['errors'])); ?></span>
                            <?php elseif ($r['net'] <= 0): ?>
                                <span class="iqu-chip iqu-chip--neutral">Will be added — full scholarship, not billed</span>
                            <?php else: ?>
                                <span class="iqu-chip iqu-chip--green">Will be added</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <div class="iqu-cpn-actions">
                <?php if ($ok): ?>
                <form method="post" action="<?php echo $post; ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::A_CONFIRM); ?>">
                    <input type="hidden" name="key" value="<?php echo esc_attr((string) $state['key']); ?>">
                    <?php wp_nonce_field(self::A_CONFIRM); ?>
                    <?php submit_button('Import ' . count($ok) . ' students', 'primary', 'submit', false); ?>
                </form>
                <?php endif; ?>
                <form method="post" action="<?php echo $post; ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::A_CANCEL); ?>">
                    <?php wp_nonce_field(self::A_CANCEL); ?>
                    <?php submit_button('Cancel', 'secondary', 'submit', false); ?>
                </form>
            </div>
            <div class="iqu-billing-body">
                <p class="iqu-fld-hint">Fix skipped rows in your spreadsheet and upload them again later — students already added are recognised and will not be added twice.</p>
            </div>
        </div>
        <?php
    }
}
