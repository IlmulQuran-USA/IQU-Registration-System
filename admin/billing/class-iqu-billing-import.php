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
        $skipped = []; // for the result card only: rows the preview already marked as skipped, with their reasons
        foreach ($state['preview'] as $r) {
            if ($r['errors']) {
                $skipped[] = ['line' => (int) $r['line'], 'name' => trim(($r['in']['first_name'] ?? '') . ' ' . ($r['in']['last_name'] ?? '')), 'errors' => $r['errors']];
                continue;
            }
            $prep = IQU_Billing_Add_Student::prepare($r['in']); // check again: records may have changed
            if ($prep['errors']) { $failed[] = 'Row ' . $r['line'] . ': ' . implode(' ', $prep['errors']); continue; }
            $id = IQU_Database::insert_registration($prep['row']);
            if (!$id) { $failed[] = 'Row ' . $r['line'] . ': could not be saved.'; continue; }
            $added++;
            if ($prep['net'] > 0) $billed++;
        }

        self::store(['done' => ['added' => $added, 'billed' => $billed, 'failed' => $failed, 'skipped' => $skipped]]);
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
        $step = !empty($state['done']) ? 4 : (!empty($state['preview']) ? 3 : 1);
        ?>
        <div class="wrap iqu-admin-wrap iqu-billing">
            <?php IQU_Billing_Page::tabs('import'); ?>
            <?php IQU_Billing_Page::page_header('Import students', 'Add many existing students at once from a spreadsheet. They are billed as current students (no free month). Nothing is saved until you check the rows and press Import.'); ?>
            <?php self::steps($step); ?>

            <?php if (!empty($state['error'])): ?>
                <div class="notice notice-error"><p><?php echo esc_html($state['error']); ?></p></div>
            <?php endif; ?>

            <?php if (!empty($state['done'])): self::render_result($state['done']); ?>
            <?php elseif (!empty($state['preview'])): self::render_preview($state); else: ?>

            <!-- ── Step 1: prepare the file ─────────────────── -->
            <section class="iqu-card iqu-import-step" aria-labelledby="iqu-step1-title">
                <div class="iqu-card-head">
                    <span class="iqu-card-head-title" id="iqu-step1-title">1 · Prepare your file</span>
                </div>
                <div class="iqu-billing-body">
                    <p class="iqu-billing-intro">Fill in the sample with Excel or Google Sheets, one student per row, then save it as CSV. A file from <strong>Export CSV</strong> works too: the column names are the same.</p>
                    <form method="post" action="<?php echo $post; ?>" class="iqu-import-sample">
                        <input type="hidden" name="action" value="<?php echo esc_attr(self::A_SAMPLE); ?>">
                        <?php wp_nonce_field(self::A_SAMPLE); ?>
                        <button type="submit" name="submit" value="Download sample CSV" class="iqu-btn iqu-btn--secondary"><?php echo IQU_Billing_Page::icon('download'); ?>Download sample CSV</button>
                    </form>
                    <details class="iqu-columns-guide">
                        <summary>Which columns do I need?</summary>
                        <p class="iqu-fld-hint">Required columns:</p>
                        <ul class="iqu-column-chips" aria-label="Required columns">
                            <?php foreach (self::COLUMNS as $c): if (!$c[1]) continue; ?>
                                <li><?php echo esc_html($c[0]); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <div class="iqu-table-wrap">
                        <table class="iqu-tbl iqu-billing-tbl">
                            <caption class="screen-reader-text">All columns</caption>
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
                        <p class="iqu-fld-hint">The monthly fee is not a column on purpose. It is always worked out from Course and Days/Week using the pricing rules. Use Agreed Monthly Fee only for families who pay less.</p>
                    </details>
                </div>
            </section>

            <!-- ── Step 2: upload ───────────────────────────── -->
            <section class="iqu-card iqu-import-step" aria-labelledby="iqu-step2-title">
                <div class="iqu-card-head">
                    <span class="iqu-card-head-title" id="iqu-step2-title">2 · Upload it</span>
                </div>
                <form method="post" action="<?php echo $post; ?>" enctype="multipart/form-data" class="iqu-import-upload" id="iqu-import-upload">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::A_UPLOAD); ?>">
                    <?php wp_nonce_field(self::A_UPLOAD); ?>
                    <div class="iqu-billing-body">
                        <div class="iqu-dropzone" data-dropzone>
                            <input id="iqu-csv" class="iqu-dropzone-input" type="file" name="csv" accept=".csv,text/csv" required aria-describedby="iqu-csv-hint iqu-csv-status">
                            <div class="iqu-dropzone-inner" aria-hidden="true">
                                <span class="dashicons dashicons-upload"></span>
                                <span class="iqu-dropzone-title">Drag your CSV file here, or <span class="iqu-dropzone-link">choose a file</span></span>
                            </div>
                            <label for="iqu-csv" class="screen-reader-text">CSV file (required)</label>
                        </div>
                        <p class="iqu-fld-hint" id="iqu-csv-hint">CSV only, up to 1 MB and <?php echo (int) self::MAX_ROWS; ?> students. The file is checked and then discarded; it is not stored on the server.</p>
                        <p class="iqu-dropzone-status" id="iqu-csv-status" role="status" aria-live="polite"></p>
                    </div>
                    <div class="iqu-form-actions iqu-btn-group">
                        <button type="submit" name="submit" value="Upload and check" class="iqu-btn iqu-btn--primary"><?php echo IQU_Billing_Page::icon('upload'); ?>Upload and check</button>
                    </div>
                </form>
            </section>
            <?php endif; ?>
        </div>
        <?php
    }

    /** Step indicator: 1 Prepare file → 2 Upload → 3 Check and import. $current 4 = all done. */
    private static function steps(int $current): void
    {
        $steps = [1 => 'Prepare file', 2 => 'Upload', 3 => 'Check and import'];
        echo '<nav class="iqu-steps" aria-label="Import steps"><ol data-steps>';
        foreach ($steps as $n => $label) {
            $done = $n < $current;
            $now  = $n === $current;
            echo '<li class="' . ($done ? 'is-done' : ($now ? 'is-current' : '')) . '" data-step="' . (int) $n . '"' . ($now ? ' aria-current="step"' : '') . '>'
                . '<span class="iqu-steps-n" aria-hidden="true">' . (int) $n . '</span>'
                . '<span class="iqu-steps-label">' . esc_html($label) . '<span class="screen-reader-text iqu-steps-done">' . ($done ? ' (done)' : '') . '</span></span></li>';
        }
        echo '</ol></nav>';
    }

    /** Plain-English column for a problem message (presentation only). */
    private static function problem_column(string $msg): string
    {
        $labels = [
            'first_name' => 'Student name', 'email' => 'Email', 'whatsapp' => 'WhatsApp',
            'course' => 'Course', 'days' => 'Days/Week', 'agreed_fee' => 'Agreed Monthly Fee',
        ];
        return $labels[IQU_Billing_Add_Student::error_field($msg)] ?? 'Row';
    }

    private static function problem_list(array $errors): string
    {
        $out = '<ul class="iqu-problem-list">';
        foreach ($errors as $e) {
            $out .= '<li><strong>' . esc_html(self::problem_column((string) $e)) . '</strong> — ' . esc_html((string) $e) . '</li>';
        }
        return $out . '</ul>';
    }

    private static function render_preview(array $state): void
    {
        $rows  = $state['preview'];
        $ok    = array_filter($rows, fn($r) => !$r['errors']);
        $bad   = count($rows) - count($ok);
        $free  = count(array_filter($ok, fn($r) => (float) $r['net'] <= 0));
        $total = array_sum(array_map(fn($r) => (float) $r['net'], $ok));
        $post  = esc_url(admin_url('admin-post.php'));
        ?>
        <section class="iqu-card iqu-import-preview" aria-labelledby="iqu-step3-title">
            <div class="iqu-card-head">
                <span class="iqu-card-head-title" id="iqu-step3-title">3 · Check and import — <?php echo esc_html((string) $state['file']); ?></span>
                <span class="iqu-card-head-badge"><?php echo count($rows); ?> rows</span>
            </div>
            <div class="iqu-billing-body">
                <ul class="iqu-import-chips" aria-label="Summary">
                    <li class="iqu-ichip iqu-ichip--ok"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>Ready <strong><?php echo count($ok); ?></strong></li>
                    <li class="iqu-ichip iqu-ichip--warn"><span class="dashicons dashicons-info" aria-hidden="true"></span>Full scholarship <strong><?php echo (int) $free; ?></strong></li>
                    <li class="iqu-ichip iqu-ichip--bad"><span class="dashicons dashicons-dismiss" aria-hidden="true"></span>Will be skipped <strong><?php echo (int) $bad; ?></strong></li>
                    <li class="iqu-ichip"><span class="dashicons dashicons-money-alt" aria-hidden="true"></span>Monthly tuition <strong><?php echo esc_html(IQU_Pricing::format($total)); ?></strong></li>
                </ul>
                <?php if ($bad): ?>
                    <label class="iqu-billing-check-label"><input type="checkbox" id="iqu-only-problems" data-only-problems="iqu-import-tbl"> Show only rows with problems</label>
                <?php endif; ?>
            </div>
            <div class="iqu-table-wrap iqu-import-wrap">
            <table class="iqu-tbl iqu-billing-tbl iqu-import-tbl" id="iqu-import-tbl">
                <caption class="screen-reader-text">Rows in the file and what will happen to each</caption>
                <thead><tr><th scope="col">Row</th><th scope="col">Student</th><th scope="col">Email</th><th scope="col">Course</th><th scope="col">Standard</th><th scope="col">Monthly</th><th scope="col">Result</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): $in = $r['in']; ?>
                    <tr<?php echo $r['errors'] ? ' data-problem="1" class="is-problem"' : ''; ?>>
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
                                <span class="iqu-billing-error"><strong>Will be skipped:</strong></span>
                                <?php echo self::problem_list($r['errors']); ?>
                            <?php elseif ($r['net'] <= 0): ?>
                                <span class="iqu-chip iqu-chip--neutral">Will be added — full scholarship, not billed</span>
                            <?php else: ?>
                                <span class="iqu-chip iqu-chip--green">Will be added</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr class="iqu-import-none" hidden><td colspan="7" class="iqu-empty-state">No rows with problems.</td></tr>
                </tbody>
            </table>
            </div>

            <div class="iqu-sticky-bar">
                <p class="iqu-sticky-bar-note">
                    <?php if (!$ok): ?>
                        No rows are ready to import. Fix the file and upload it again.
                    <?php elseif ($bad): ?>
                        <?php echo (int) $bad; ?> <?php echo $bad === 1 ? 'row has problems and will be skipped' : 'rows with problems will be skipped'; ?>; fix them in your spreadsheet and upload them again later (students already added are recognised).
                    <?php else: ?>
                        All rows are ready.
                    <?php endif; ?>
                </p>
                <div class="iqu-btn-group">
                    <form method="post" action="<?php echo $post; ?>">
                        <input type="hidden" name="action" value="<?php echo esc_attr(self::A_CANCEL); ?>">
                        <?php wp_nonce_field(self::A_CANCEL); ?>
                        <button type="submit" name="submit" value="Cancel" class="iqu-btn iqu-btn--secondary">Cancel</button>
                    </form>
                    <?php if ($ok): ?>
                    <form method="post" action="<?php echo $post; ?>">
                        <input type="hidden" name="action" value="<?php echo esc_attr(self::A_CONFIRM); ?>">
                        <input type="hidden" name="key" value="<?php echo esc_attr((string) $state['key']); ?>">
                        <?php wp_nonce_field(self::A_CONFIRM); ?>
                        <button type="submit" name="submit" value="<?php echo esc_attr('Import ' . count($ok) . ' students'); ?>" class="iqu-btn iqu-btn--primary"><?php echo esc_html('Import ' . count($ok) . ' students'); ?></button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </section>
        <?php
    }

    /** After Import: what was added, what was skipped and why, and where to go next. */
    private static function render_result(array $d): void
    {
        $added   = (int) $d['added'];
        $billed  = (int) $d['billed'];
        $skipped = (array) ($d['skipped'] ?? []);
        $failed  = (array) ($d['failed'] ?? []);
        ?>
        <section class="iqu-card iqu-import-result" aria-labelledby="iqu-result-title">
            <div class="iqu-card-head">
                <span class="iqu-card-head-title" id="iqu-result-title">Import finished</span>
            </div>
            <div class="iqu-billing-body" role="status">
                <ul class="iqu-import-chips">
                    <li class="iqu-ichip iqu-ichip--ok"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><strong><?php echo $added; ?></strong> <?php echo $added === 1 ? 'student added' : 'students added'; ?></li>
                    <li class="iqu-ichip"><?php echo $billed; ?> will be billed · <?php echo $added - $billed; ?> on full scholarship</li>
                    <li class="iqu-ichip<?php echo ($skipped || $failed) ? ' iqu-ichip--bad' : ''; ?>"><strong><?php echo count($skipped) + count($failed); ?></strong> skipped</li>
                </ul>
                <?php if ($skipped || $failed): ?>
                    <p class="iqu-result-sub">Skipped, with the reason:</p>
                    <ul class="iqu-result-list">
                        <?php foreach ($skipped as $s): ?>
                            <li><strong>Row <?php echo (int) $s['line']; ?><?php echo $s['name'] !== '' ? ' — ' . esc_html($s['name']) : ''; ?></strong><?php echo self::problem_list((array) $s['errors']); ?></li>
                        <?php endforeach; ?>
                        <?php foreach ($failed as $f): ?><li><?php echo esc_html((string) $f); ?></li><?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <p class="iqu-fld-hint">The new students are also listed in <a href="<?php echo esc_url(admin_url('admin.php?page=iqu-list-free')); ?>">Enroll for Free</a>.</p>
            </div>
            <div class="iqu-form-actions iqu-btn-group">
                <a class="iqu-btn iqu-btn--secondary" href="<?php echo esc_url(admin_url('admin.php?page=' . IQU_Billing_Page::SLUG)); ?>">Go to Student Billing</a>
                <a class="iqu-btn iqu-btn--primary" href="<?php echo esc_url(admin_url('admin.php?page=' . IQU_Billing_Page::SLUG . '&show=not_set_up')); ?>"><?php echo IQU_Billing_Page::icon('email-alt'); ?>Send payment links</a>
            </div>
        </section>
        <?php
    }
}
