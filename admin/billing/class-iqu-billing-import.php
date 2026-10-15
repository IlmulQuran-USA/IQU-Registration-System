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
    private const A_RESULTS = 'iqu_billing_import_results';

    /** Result of each row: imported, imported with a warning, or not imported. */
    public const LEVEL_OK      = 'ok';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR   = 'error';
    /** How each level is written in the results CSV. */
    private const LEVEL_CSV = [self::LEVEL_OK => 'OK', self::LEVEL_WARNING => 'WARNING', self::LEVEL_ERROR => 'ERROR'];
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
        ['Course',             true,  'qaidah, hifz or arabic (the full course name also works). If the cell is empty or the course is not on the website, the student is still imported with a warning and the course left blank; billing waits until you set it.', 'qaidah'],
        ['Days/Week',          true,  'Classes per week, 1 to 7. Hifz needs at least 3.',     '4'],
        ['Agreed Monthly Fee', false, 'Only for families who pay less than the standard fee. Number only. 0 means full scholarship. Leave empty for the standard fee. Without a recognised course the fee is saved as given, with a warning, and checked once the course is set.', ''],
        ['Zakat',              false, 'Yes if the reduced fee is paid by the Zakat Fund. Otherwise leave empty or No.', 'No'],
        ['Age',                false, 'Student age, a number up to 100. Under 4 is saved with a warning.', '9'],
        ['Country Origin',     false, 'Country the student is from.',                          'Bangladesh'],
        ['Country Residence',  false, 'Country the student lives in.',                         'United States'],
        ['Quran Level',        false, 'Beginner, Intermediate or Advanced.',                   'Beginner'],
        ['Memorized',          false, 'Surahs or Juz memorized before (up to 500 characters).', 'Juz Amma'],
        ['Preferred Days',     false, 'Days of the week, for example: Mon, Tue, Thu, Fri. No more than Days/Week.', 'Mon, Tue, Thu, Fri'],
        ['Time Slot',          false, 'Usual class time, for example 05:00 PM – 06:00 PM (CST). Up to 100 characters.', '05:00 PM – 06:00 PM (CST)'],
        ['Session Duration',   false, '30, 45 or 60 (minutes).',                               '30'],
        ['Languages',          false, 'English, Arabic, Urdu or Bengali; several with commas.', 'English, Bengali'],
        ['Teacher Pref',       false, 'Male, Female or No preference.',                        'Female'],
        ['Referral',           false, 'How they heard about us: Facebook, WhatsApp, LinkedIn, YouTube, Website, Friend/Family, Email or Other (text after "Other:" is kept; saved with a warning).', 'Friend/Family'],
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
        'countryofresidence' => 'country_res', 'countryorigin' => 'country_origin', 'countryoforigin' => 'country_origin',
        'quranlevel' => 'quran_level', 'memorized' => 'memorized', 'memorizedbefore' => 'memorized',
        'preferreddays' => 'preferred_days', 'timeslot' => 'time_slot',
        'sessionduration' => 'session_dur', 'languages' => 'languages',
        'teacherpref' => 'teacher_pref', 'teacherpreference' => 'teacher_pref',
        'referral' => 'referral', 'heardaboutusvia' => 'referral', 'note' => 'note',
    ];

    public static function init(): void
    {
        add_action('admin_menu', [__CLASS__, 'register_menu'], 22);
        add_action('admin_post_' . self::A_SAMPLE, [__CLASS__, 'download_sample']);
        add_action('admin_post_' . self::A_UPLOAD, [__CLASS__, 'handle_upload']);
        add_action('admin_post_' . self::A_CONFIRM, [__CLASS__, 'handle_confirm']);
        add_action('admin_post_' . self::A_CANCEL, [__CLASS__, 'handle_cancel']);
        add_action('admin_post_' . self::A_RESULTS, [__CLASS__, 'download_results']);
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
        // Second example: one value per column of COLUMNS (22), in the same order.
        fputcsv($out, ['Omar', 'Rahman', '', 'omar.r@example.com', '+1 469 555 0172', '', 'arabic', '2', '30', 'Yes', '24', '', 'United States', '', '',
            'Sat, Sun', '10:00 AM – 11:00 AM (CST)', '30', 'English, Arabic', 'No preference', '', 'Adult student, reduced fee agreed']);
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

        $state = self::check_file($file['tmp_name'], (string) $file['name'], !empty($_POST['dry_run']));
        if (isset($state['error'])) $fail($state['error']);
        self::store($state);
        self::back();
    }

    /**
     * Read and check every row of a CSV file. Writes nothing to the database: the result is
     * the preview (also the "Validate only" result), kept for 30 minutes as the admin's
     * temporary import state. Rows are saved only by handle_confirm().
     *
     * @return array ['error' => message] or the preview state: preview rows (line, in, cells,
     *               errors, warnings, level, gross, net, course_missing), header, file, key, dry.
     */
    public static function check_file(string $path, string $name, bool $dry = false): array
    {
        $fail = fn(string $msg) => ['error' => $msg];
        $fh = fopen($path, 'r');
        if (!$fh) return $fail('The file could not be read.');
        // Skip a UTF-8 byte order mark ("CSV UTF-8" from Excel) before parsing, so a quoted
        // first header is read as First Name, not "First Name" with its quotes.
        if (fread($fh, 3) !== "\xEF\xBB\xBF") rewind($fh);

        $header = fgetcsv($fh, 0, ',', '"', '');
        if (!$header) { fclose($fh); return $fail('The file is empty.'); }

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
        // Only these columns must be in the file; any optional column may be left out entirely.
        if ($missing) { fclose($fh); return $fail('These columns are missing: ' . implode(', ', $missing) . '. Use the sample file as a guide.'); }

        $rows = [];
        $line = 1;
        $seen = [];
        while (($cells = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            $line++;
            if (count(array_filter($cells, fn($c) => trim((string) $c) !== '')) === 0) continue;
            if (count($rows) >= self::MAX_ROWS) {
                fclose($fh);
                return $fail('The file has more than ' . self::MAX_ROWS . ' students. Split it into smaller files.');
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
                'line'           => $line,
                'in'             => $in,
                'raw'            => $raw,
                'cells'          => array_map('strval', $cells),
                'errors'         => $prep['errors'],
                'warnings'       => $prep['errors'] ? [] : $prep['warnings'],
                'level'          => self::level($prep['errors'], $prep['warnings']),
                'gross'          => $prep['gross'],
                'net'            => $prep['net'],
                'course_missing' => !empty($prep['course_missing']),
            ];
        }
        fclose($fh);

        if (!$rows) return $fail('No students found in the file.');

        return [
            'preview' => $rows,
            'header'  => array_map('strval', $header),
            'file'    => sanitize_file_name($name),
            'key'     => wp_generate_password(20, false),
            'dry'     => $dry,
        ];
    }

    /** OK (imported), WARNING (imported, check it) or ERROR (not imported). */
    private static function level(array $errors, array $warnings): string
    {
        return $errors ? self::LEVEL_ERROR : ($warnings ? self::LEVEL_WARNING : self::LEVEL_OK);
    }

    /** "X OK · Y with warnings · Z rejected" counts for a preview. */
    public static function level_counts(array $rows): array
    {
        $n = [self::LEVEL_OK => 0, self::LEVEL_WARNING => 0, self::LEVEL_ERROR => 0];
        foreach ($rows as $r) $n[$r['level'] ?? self::level($r['errors'], $r['warnings'] ?? [])]++;
        return $n;
    }

    /**
     * The checked file as CSV: every original column as uploaded, then `result`
     * (OK / WARNING / ERROR) and `messages`. Works for a preview and for "Validate only".
     */
    public static function download_results(): void
    {
        if (!current_user_can(self::CAP)) wp_die('You do not have permission to do this.', 403);
        check_admin_referer(self::A_RESULTS);
        $state = self::load();
        if (empty($state['preview'])) {
            self::store(['error' => 'The results have expired. Please upload the file again.']);
            self::back();
        }
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name(pathinfo((string) $state['file'], PATHINFO_FILENAME) . '-results.csv') . '"');
        $out = fopen('php://output', 'w');
        self::write_results($out, $state);
        fclose($out);
        exit;
    }

    /** Writes the results CSV for a preview state to a stream. */
    public static function write_results($out, array $state): void
    {
        $header = (array) ($state['header'] ?? []);
        $width  = count($header);
        fputs($out, "\xEF\xBB\xBF");
        fputcsv($out, array_merge(array_map([__CLASS__, 'csv_safe'], $header), ['result', 'messages']));
        foreach ($state['preview'] as $r) {
            $cells = array_slice(array_pad((array) ($r['cells'] ?? []), $width, ''), 0, $width);
            $msgs  = array_merge((array) $r['errors'], (array) ($r['warnings'] ?? []));
            fputcsv($out, array_merge(array_map([__CLASS__, 'csv_safe'], $cells), [
                self::LEVEL_CSV[$r['level'] ?? self::level($r['errors'], $r['warnings'] ?? [])],
                self::csv_safe(implode(' | ', $msgs)),
            ]));
        }
    }

    /** Spreadsheet formula characters at the start of a cell are neutralised with an apostrophe. */
    private static function csv_safe(string $value): string
    {
        return ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) ? "'" . $value : $value;
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
            'course_raw'    => IQU_Billing_Add_Student::clean_text((string) ($r['course'] ?? '')), // for the "not found" warning
            'days'          => (int) preg_replace('/\D/', '', (string) ($r['days'] ?? '')),
            'use_agreed'    => $fee_raw !== '',
            'agreed_fee'    => $fee_raw !== '' ? (float) $fee_raw : 0.0,
            'zakat'         => in_array($zak, ['yes', 'y', 'true', '1'], true),
            'note'          => sanitize_textarea_field((string) ($r['note'] ?? '')),
            'via'           => IQU_Database::VIA_IMPORT,
            // Optional enrollment columns; checked by IQU_Billing_Add_Student::clean_extra() like the form.
            'extra'         => [
                'age'            => (string) ($r['age'] ?? ''),
                'country_origin' => $r['country_origin'] ?? '',
                'country_res'    => $r['country_res'] ?? '',
                'quran_level'    => $r['quran_level'] ?? '',
                'memorized'      => $r['memorized'] ?? '',
                'languages'      => $r['languages'] ?? '',
                'preferred_days' => $r['preferred_days'] ?? '',
                'time_slot'      => $r['time_slot'] ?? '',
                'session_dur'    => $r['session_dur'] ?? '',
                'teacher_pref'   => $r['teacher_pref'] ?? '',
                'referral'       => $r['referral'] ?? '',
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
        if (!empty($state['dry'])) {
            self::store(['error' => 'That file was only validated. To import it, upload it again without "Validate only".']);
            self::back();
        }

        $added = 0; $billed = 0; $warned = 0; $no_course = 0; $failed = [];
        $skipped = []; // for the result card only: rows the preview already marked as skipped, with their reasons
        $levels  = []; // line => what happened to it (ok / warning / error)
        foreach ($state['preview'] as $r) {
            if ($r['errors']) {
                $skipped[] = ['line' => (int) $r['line'], 'name' => trim(($r['in']['first_name'] ?? '') . ' ' . ($r['in']['last_name'] ?? '')), 'errors' => $r['errors']];
                $levels[(int) $r['line']] = self::LEVEL_ERROR;
                continue;
            }
            $prep = IQU_Billing_Add_Student::prepare($r['in']); // check again: records may have changed
            if ($prep['errors']) { $failed[] = 'Row ' . $r['line'] . ': ' . implode(' ', $prep['errors']); $levels[(int) $r['line']] = self::LEVEL_ERROR; continue; }
            $id = IQU_Database::insert_registration($prep['row']);
            if (!$id) { $failed[] = 'Row ' . $r['line'] . ': could not be saved.'; $levels[(int) $r['line']] = self::LEVEL_ERROR; continue; }
            $added++;
            $levels[(int) $r['line']] = self::level([], $prep['warnings']);
            if ($prep['warnings']) $warned++;
            if (!empty($prep['course_missing'])) $no_course++;
            elseif ($prep['net'] > 0) $billed++;
        }

        self::store(['done' => [
            'added' => $added, 'billed' => $billed, 'warned' => $warned, 'no_course' => $no_course,
            'failed' => $failed, 'skipped' => $skipped, 'levels' => $levels,
        ]]);
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
                        <label class="iqu-billing-check-label iqu-import-dry"><input type="checkbox" name="dry_run" value="1" aria-describedby="iqu-dry-hint"> Validate only</label>
                        <p class="iqu-fld-hint" id="iqu-dry-hint">Runs every check and shows the results, which you can download as CSV. Nothing is saved, and there is no Import button.</p>
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
            'age' => 'Age', 'quran_level' => 'Quran Level', 'languages' => 'Languages', 'preferred_days' => 'Preferred Days',
            'time_slot' => 'Time Slot', 'session_dur' => 'Session Duration', 'teacher_pref' => 'Teacher Pref', 'referral' => 'Referral',
        ];
        if (strpos($msg, "Course '") === 0) return 'Course';
        if (strpos($msg, 'Age under 4') === 0) return 'Age';
        if (strpos($msg, "'Other' answer") === 0) return 'Referral';
        return $labels[IQU_Billing_Add_Student::error_field($msg)] ?? 'Row';
    }

    private static function problem_list(array $errors, string $class = ''): string
    {
        $out = '<ul class="iqu-problem-list' . ($class !== '' ? ' ' . esc_attr($class) : '') . '">';
        foreach ($errors as $e) {
            $out .= '<li><strong>' . esc_html(self::problem_column((string) $e)) . '</strong> — ' . esc_html((string) $e) . '</li>';
        }
        return $out . '</ul>';
    }

    /** "X OK · Y with warnings · Z rejected". */
    private static function summary_line(array $n): string
    {
        return sprintf('%d OK · %d with warnings · %d rejected', $n[self::LEVEL_OK], $n[self::LEVEL_WARNING], $n[self::LEVEL_ERROR]);
    }

    /**
     * Step 3 (or the "Validate only" result). The table shows the key columns; every other
     * column of the row is in its expandable detail row.
     */
    private static function render_preview(array $state): void
    {
        $rows   = $state['preview'];
        $dry    = !empty($state['dry']);
        $n      = self::level_counts($rows);
        $ok     = array_filter($rows, fn($r) => !$r['errors']);
        $free   = count(array_filter($ok, fn($r) => (float) $r['net'] <= 0 && empty($r['course_missing'])));
        $nocrs  = count(array_filter($ok, fn($r) => !empty($r['course_missing'])));
        $tocheck = count(array_filter($ok, fn($r) => !empty($r['course_missing']) && !empty($r['in']['use_agreed'])));
        $total  = array_sum(array_map(fn($r) => (float) $r['net'], $ok));
        $post   = esc_url(admin_url('admin-post.php'));
        $labels = array_column(self::COLUMNS, 0);
        $fields = ['first_name', 'last_name', 'guardian_name', 'email', 'whatsapp', 'guardian_whatsapp', 'course', 'days', 'agreed_fee', 'zakat', 'age',
                   'country_origin', 'country_res', 'quran_level', 'memorized', 'preferred_days', 'time_slot', 'session_dur', 'languages', 'teacher_pref', 'referral', 'note'];
        $label_of = array_combine($fields, $labels);
        $in_table = ['first_name', 'last_name', 'guardian_name', 'email', 'age', 'course', 'days', 'teacher_pref'];
        $result   = [
            self::LEVEL_OK      => ['OK', 'green', $dry ? 'Would be added' : 'Will be added'],
            self::LEVEL_WARNING => ['Warning', 'gold', $dry ? 'Would be added — check the messages' : 'Will be added — check the messages'],
            self::LEVEL_ERROR   => ['Rejected', 'red', $dry ? 'Would be skipped' : 'Will be skipped'],
        ];
        ?>
        <section class="iqu-card iqu-import-preview<?php echo $dry ? ' is-dry-run' : ''; ?>" aria-labelledby="iqu-step3-title">
            <div class="iqu-card-head">
                <span class="iqu-card-head-title" id="iqu-step3-title"><?php echo esc_html(($dry ? 'Validation results — ' : '3 · Check and import — ') . (string) $state['file']); ?></span>
                <span class="iqu-card-head-badge"><?php echo count($rows); ?> rows</span>
            </div>
            <div class="iqu-billing-body">
                <p class="iqu-import-summary" role="status" data-import-summary><strong><?php echo esc_html(self::summary_line($n)); ?></strong></p>
                <?php if ($dry): ?>
                    <p class="iqu-fld-hint">Validate only: nothing was saved. Download the results, fix the file, then upload it again without "Validate only" to import.</p>
                <?php endif; ?>
                <?php if ($nocrs): ?>
                    <p class="iqu-fld-hint" data-course-missing-hint><?php echo (int) $nocrs; ?> <?php echo $nocrs === 1 ? 'student has' : 'students have'; ?> no course: <?php echo $nocrs === 1 ? 'it is' : 'they are'; ?> imported with the course left blank, and billing waits until it is set<?php echo $tocheck ? '; agreed fees are saved and checked against the standard price then' : ''; ?>.</p>
                <?php endif; ?>
                <ul class="iqu-import-chips" aria-label="Summary">
                    <li class="iqu-ichip iqu-ichip--ok"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>OK <strong><?php echo (int) $n[self::LEVEL_OK]; ?></strong></li>
                    <li class="iqu-ichip iqu-ichip--warn"><span class="dashicons dashicons-warning" aria-hidden="true"></span>With warnings <strong><?php echo (int) $n[self::LEVEL_WARNING]; ?></strong></li>
                    <li class="iqu-ichip iqu-ichip--bad"><span class="dashicons dashicons-dismiss" aria-hidden="true"></span>Rejected <strong><?php echo (int) $n[self::LEVEL_ERROR]; ?></strong></li>
                    <?php if ($nocrs): ?><li class="iqu-ichip iqu-ichip--warn"><span class="dashicons dashicons-info" aria-hidden="true"></span>Course missing <strong><?php echo (int) $nocrs; ?></strong></li><?php endif; ?>
                    <?php if ($tocheck): ?><li class="iqu-ichip iqu-ichip--warn"><span class="dashicons dashicons-info" aria-hidden="true"></span>Agreed fee to check <strong><?php echo (int) $tocheck; ?></strong></li><?php endif; ?>
                    <?php if ($free): ?><li class="iqu-ichip"><span class="dashicons dashicons-info" aria-hidden="true"></span>Full scholarship <strong><?php echo (int) $free; ?></strong></li><?php endif; ?>
                    <li class="iqu-ichip"><span class="dashicons dashicons-money-alt" aria-hidden="true"></span>Monthly tuition <strong><?php echo esc_html(IQU_Pricing::format($total)); ?></strong></li>
                </ul>
                <div class="iqu-import-tools">
                    <?php if ($n[self::LEVEL_WARNING] + $n[self::LEVEL_ERROR] > 0): ?>
                        <label class="iqu-billing-check-label"><input type="checkbox" id="iqu-only-problems" data-only-problems="iqu-import-tbl"> Show only rows with warnings or errors</label>
                    <?php endif; ?>
                    <form method="post" action="<?php echo $post; ?>" class="iqu-import-results-form">
                        <input type="hidden" name="action" value="<?php echo esc_attr(self::A_RESULTS); ?>">
                        <?php wp_nonce_field(self::A_RESULTS); ?>
                        <button type="submit" name="submit" value="Download results" class="iqu-btn iqu-btn--secondary"><?php echo IQU_Billing_Page::icon('download'); ?>Download results (CSV)</button>
                    </form>
                </div>
            </div>
            <div class="iqu-table-wrap iqu-import-wrap">
            <table class="iqu-tbl iqu-billing-tbl iqu-import-tbl" id="iqu-import-tbl">
                <caption class="screen-reader-text">Rows in the file and what <?php echo $dry ? 'would happen' : 'will happen'; ?> to each. Use "Details" for the other columns.</caption>
                <thead><tr>
                    <th scope="col">Row</th><th scope="col">Family email</th><th scope="col">Parent</th><th scope="col">Student</th><th scope="col" class="is-num">Age</th>
                    <th scope="col">Course</th><th scope="col" class="is-num">Classes/week</th><th scope="col">Teacher</th><th scope="col">Result</th><th scope="col">Messages</th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $in    = $r['in'];
                    $raw   = (array) ($r['raw'] ?? []);
                    $level = $r['level'] ?? self::level($r['errors'], $r['warnings'] ?? []);
                    [$res_label, $tone, $res_sub] = $result[$level];
                    $did   = 'iqu-import-detail-' . (int) $r['line'];
                    $course_ok = IQU_Pricing::is_valid_course((string) $in['course']);
                    ?>
                    <tr data-level="<?php echo esc_attr($level); ?>"<?php echo $level !== self::LEVEL_OK ? ' data-problem="1"' : ''; ?><?php echo $level === self::LEVEL_ERROR ? ' class="is-problem"' : ''; ?>>
                        <td class="iqu-td-id">
                            <?php echo (int) $r['line']; ?>
                            <button type="button" class="iqu-import-detail-btn" aria-expanded="false" aria-controls="<?php echo esc_attr($did); ?>" data-detail-toggle>Details<span class="screen-reader-text"> for row <?php echo (int) $r['line']; ?></span></button>
                        </td>
                        <td class="iqu-td-email"><?php echo esc_html($in['email'] !== '' ? $in['email'] : (string) ($raw['email'] ?? '—')); ?></td>
                        <td><?php echo esc_html($in['guardian_name'] !== '' ? $in['guardian_name'] : '—'); ?></td>
                        <td><strong><?php echo esc_html(trim($in['first_name'] . ' ' . $in['last_name']) ?: '—'); ?></strong></td>
                        <td class="is-num"><?php echo esc_html((string) ($raw['age'] ?? '') !== '' ? (string) $raw['age'] : '—'); ?></td>
                        <td>
                            <?php if ($course_ok): echo esc_html(IQU_Pricing::label($in['course']));
                            elseif (!empty($r['course_missing'])): ?><span class="iqu-chip iqu-chip--gold">Course missing</span><?php
                            else: echo esc_html((string) ($raw['course'] ?? '') !== '' ? (string) $raw['course'] : '—'); endif; ?>
                        </td>
                        <td class="is-num"><?php echo $in['days'] ? (int) $in['days'] : '—'; ?></td>
                        <td><?php echo esc_html((string) ($raw['teacher_pref'] ?? '') !== '' ? (string) $raw['teacher_pref'] : '—'); ?></td>
                        <td><span class="iqu-chip iqu-chip--<?php echo esc_attr($tone); ?>"><?php echo esc_html($res_label); ?></span><br><span class="iqu-billing-sub"><?php echo esc_html($res_sub); ?></span></td>
                        <td class="iqu-billing-wrap iqu-import-msgs">
                            <?php if ($r['errors']) echo self::problem_list($r['errors'], 'is-error'); ?>
                            <?php if (!empty($r['warnings'])) echo self::problem_list($r['warnings'], 'is-warning'); ?>
                            <?php if (!$r['errors'] && empty($r['warnings'])): ?><span class="iqu-billing-sub">—</span><?php endif; ?>
                        </td>
                    </tr>
                    <tr class="iqu-import-detail" id="<?php echo esc_attr($did); ?>" hidden>
                        <td colspan="10">
                            <dl class="iqu-import-detail-list">
                                <?php foreach ($fields as $f): if (in_array($f, $in_table, true) || !array_key_exists($f, $raw)) continue; ?>
                                    <div><dt><?php echo esc_html($label_of[$f]); ?></dt><dd><?php echo esc_html((string) $raw[$f] !== '' ? (string) $raw[$f] : '—'); ?></dd></div>
                                <?php endforeach; ?>
                                <div><dt>Standard fee</dt><dd><?php echo $r['gross'] > 0 ? esc_html(IQU_Pricing::format((float) $r['gross'])) : '—'; ?></dd></div>
                                <div><dt>Monthly fee</dt><dd><?php echo !$r['errors'] && empty($r['course_missing']) ? esc_html(IQU_Pricing::format((float) $r['net'])) : '—'; ?></dd></div>
                            </dl>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr class="iqu-import-none" hidden><td colspan="10" class="iqu-empty-state">No rows with warnings or errors.</td></tr>
                </tbody>
            </table>
            </div>

            <div class="iqu-sticky-bar">
                <p class="iqu-sticky-bar-note">
                    <?php if ($dry): ?>
                        Nothing was saved (Validate only).
                    <?php elseif (!$ok): ?>
                        No rows can be imported. Fix the file and upload it again.
                    <?php elseif ($n[self::LEVEL_ERROR]): ?>
                        <?php echo (int) $n[self::LEVEL_ERROR]; ?> <?php echo $n[self::LEVEL_ERROR] === 1 ? 'row is rejected and will be skipped' : 'rejected rows will be skipped'; ?>; fix them in your spreadsheet and upload them again later (students already added are recognised).
                    <?php elseif ($n[self::LEVEL_WARNING]): ?>
                        Rows with warnings are imported; check them afterwards.
                    <?php else: ?>
                        All rows are ready.
                    <?php endif; ?>
                </p>
                <div class="iqu-btn-group">
                    <form method="post" action="<?php echo $post; ?>">
                        <input type="hidden" name="action" value="<?php echo esc_attr(self::A_CANCEL); ?>">
                        <?php wp_nonce_field(self::A_CANCEL); ?>
                        <button type="submit" name="submit" value="<?php echo $dry ? 'Start over' : 'Cancel'; ?>" class="iqu-btn iqu-btn--secondary"><?php echo $dry ? 'Start over' : 'Cancel'; ?></button>
                    </form>
                    <?php if ($ok && !$dry): ?>
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
        $added     = (int) $d['added'];
        $billed    = (int) $d['billed'];
        $warned    = (int) ($d['warned'] ?? 0);
        $no_course = (int) ($d['no_course'] ?? 0);
        $skipped   = (array) ($d['skipped'] ?? []);
        $failed    = (array) ($d['failed'] ?? []);
        ?>
        <section class="iqu-card iqu-import-result" aria-labelledby="iqu-result-title">
            <div class="iqu-card-head">
                <span class="iqu-card-head-title" id="iqu-result-title">Import finished</span>
            </div>
            <div class="iqu-billing-body" role="status">
                <p class="iqu-import-summary"><strong><?php echo esc_html(sprintf('%d OK · %d with warnings · %d rejected', $added - $warned, $warned, count($skipped) + count($failed))); ?></strong></p>
                <ul class="iqu-import-chips">
                    <li class="iqu-ichip iqu-ichip--ok"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span><strong><?php echo $added; ?></strong> <?php echo $added === 1 ? 'student added' : 'students added'; ?></li>
                    <li class="iqu-ichip"><?php echo $billed; ?> will be billed · <?php echo $added - $billed - $no_course; ?> on full scholarship</li>
                    <?php if ($no_course): ?><li class="iqu-ichip iqu-ichip--warn"><strong><?php echo $no_course; ?></strong> course missing — not billed until a course is set</li><?php endif; ?>
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
                <p class="iqu-fld-hint">The new students are also listed in <a href="<?php echo esc_url(admin_url('admin.php?page=iqu-list-free')); ?>">Enroll for Free</a>.<?php if ($no_course): ?> Students with a missing course are under <a href="<?php echo esc_url(admin_url('admin.php?page=' . IQU_Billing_Page::SLUG . '&show=needs_course')); ?>">Student Billing → Needs course</a>.<?php endif; ?></p>
            </div>
            <div class="iqu-form-actions iqu-btn-group">
                <a class="iqu-btn iqu-btn--secondary" href="<?php echo esc_url(admin_url('admin.php?page=' . IQU_Billing_Page::SLUG)); ?>">Go to Student Billing</a>
                <a class="iqu-btn iqu-btn--primary" href="<?php echo esc_url(admin_url('admin.php?page=' . IQU_Billing_Page::SLUG . '&show=not_set_up')); ?>"><?php echo IQU_Billing_Page::icon('email-alt'); ?>Send payment links</a>
            </div>
        </section>
        <?php
    }
}
