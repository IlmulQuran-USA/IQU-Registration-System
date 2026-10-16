<?php
if (! defined('ABSPATH')) exit;

/**
 * Class IQU_Database
 *
 * 🔒 Security:
 * - dbDelta for safe schema migrations
 * - Prepared statements for ALL queries
 * - Whitelisted orderby / status values
 *
 * Supports three form types via `form_type` column:
 *   - free           → Ilm-ul-Quran Free Enrollment
 *   - summer_level1  → Summer Program Level 1 (ages 5–10)
 *   - summer_level2  → Summer Program Level 2 (ages 11–15)
 */
class IQU_Database
{

    public const FORM_FREE          = 'free';
    public const FORM_SUMMER_LEVEL1 = 'summer_level1';
    public const FORM_SUMMER_LEVEL2 = 'summer_level2';

    // 🆕 Weekend Ilm Program — নতুন ও পুরনো ছাত্র আলাদা রাখা হয়,
    //    যাতে ড্যাশবোর্ডে "কতজন নতুন এসেছে" সরাসরি গোনা যায়।
    public const FORM_WEEKEND_EXISTING = 'weekend_existing';
    public const FORM_WEEKEND_NEW      = 'weekend_new';

    /**
     * Registration statuses. The first five are the original ones; 3.3.0 adds the
     * enrollment flow: card_pending (sent to Stripe to add a card), pending_review (the team
     * confirms the fee), expired (no card after 7 days), paid (Summer payment confirmed by Stripe).
     */
    public const STATUSES = ['pending', 'confirmed', 'contacted', 'enrolled', 'cancelled', 'card_pending', 'pending_review', 'expired', 'paid'];

    /**
     * How a row was created (`created_via` column): a public website form, Billing → Add
     * existing student, or Billing → Import students. Read it with created_via(). Rows added
     * by an admin or an import never get a free month, Meta events, card reminders, the 7-day
     * expiry or the "card_pending" status. `referral` is only what the family answered.
     */
    public const VIA_PUBLIC = 'public';
    public const VIA_ADMIN  = 'admin';
    public const VIA_IMPORT = 'import';
    public const VIA_VALUES = [self::VIA_PUBLIC, self::VIA_ADMIN, self::VIA_IMPORT];

    /** What earlier versions wrote on rows added from Billing (before `created_via`). */
    public const LEGACY_ADMIN_REFERRAL = 'existing_student';
    public const LEGACY_ADMIN_UA_PREFIX = 'IQU Billing (';

    /** Set once the created_via column is added, backfilled and verified: ['at', 'admin', 'public']. */
    private const OPT_CREATED_VIA = 'iqu_created_via_migrated';

    /** Weekend প্রোগ্রামের দুটো form_type একসাথে। */
    public static function weekend_types(): array
    {
        return [self::FORM_WEEKEND_EXISTING, self::FORM_WEEKEND_NEW];
    }

    /**
     * Create or update a table safely.
     * - dbDelta() needs exactly one space between a column name and its type; the
     *   aligned SQL in this plugin uses several, which made dbDelta re-alter every
     *   column on each version bump. Spaces are normalised first.
     * - On PHP 8.5, dbDelta() crashes when the table does not exist yet, so a missing
     *   table is created directly and dbDelta() only updates existing tables.
     */
    public static function apply_schema(string $table, string $sql): void
    {
        global $wpdb;

        $lines = explode("\n", str_replace("\r", '', $sql));
        foreach ($lines as &$line) {
            $indent = strlen($line) - strlen(ltrim($line));
            $line   = substr($line, 0, $indent) . preg_replace('/ {2,}/', ' ', ltrim($line));
            $line   = preg_replace('/^(\s*)PRIMARY KEY \(/i', '$1PRIMARY KEY  (', $line);
            // dbDelta compares index column lists without spaces ("(mode,status)"); with them it would add the index again.
            if (preg_match('/^\s*(PRIMARY KEY|UNIQUE KEY|KEY)\b/i', $line)) $line = preg_replace('/,\s+/', ',', $line);
        }
        unset($line);
        $sql = implode("\n", $lines);

        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        if (!$exists) {
            $wpdb->query(preg_replace('/^\s*CREATE TABLE /i', 'CREATE TABLE IF NOT EXISTS ', $sql, 1));
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * The tables this class owns (registrations, waitlist), each with its CREATE TABLE statement
     * (dbDelta format). The one definition used by create_table() and by IQU_Schema's check.
     */
    public static function schemas(): array
    {
        global $wpdb;
        $table    = $wpdb->prefix . IQU_TABLE_NAME;
        $waitlist = self::waitlist_table();
        $charset  = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id                 BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            form_type          VARCHAR(30)         NOT NULL DEFAULT 'free',
            first_name         VARCHAR(100)        NOT NULL DEFAULT '',
            last_name          VARCHAR(100)        NOT NULL DEFAULT '',
            email              VARCHAR(191)        NOT NULL DEFAULT '',
            age                TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
            country_origin     VARCHAR(100)        NOT NULL DEFAULT '',
            country_res        VARCHAR(100)        NOT NULL DEFAULT '',
            quran_level        VARCHAR(20)         NOT NULL DEFAULT '',
            days_per_week      VARCHAR(10)         NOT NULL DEFAULT '',
            preferred_days     VARCHAR(200)        NOT NULL DEFAULT '',
            time_slot          VARCHAR(100)        NOT NULL DEFAULT '',
            session_dur        VARCHAR(20)         NOT NULL DEFAULT '',
            languages          VARCHAR(200)        NOT NULL DEFAULT '',
            whatsapp           VARCHAR(25)         NOT NULL DEFAULT '',
            memorized          TEXT,
            teacher_pref       VARCHAR(20)         NOT NULL DEFAULT '',
            device             VARCHAR(30)         NOT NULL DEFAULT '',
            referral           VARCHAR(50)         NOT NULL DEFAULT '',
            referral_other     VARCHAR(200)        NOT NULL DEFAULT '',
            wa_updates         VARCHAR(5)          NOT NULL DEFAULT 'no',
            fee_pref           VARCHAR(50)         NOT NULL DEFAULT '',
            course_type        VARCHAR(20)         NOT NULL DEFAULT '',
            per_class_rate     DECIMAL(6,2)        NOT NULL DEFAULT 0,
            calculated_amount  DECIMAL(8,2)        NOT NULL DEFAULT 0,
            coupon_code        VARCHAR(40)         NOT NULL DEFAULT '',
            coupon_id          BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            discount_amount    DECIMAL(8,2)        NOT NULL DEFAULT 0,
            special_discount   TINYINT(1)          NOT NULL DEFAULT 0,
            zakat_declaration  TINYINT(1)          NOT NULL DEFAULT 0,
            free_request_reason TEXT,
            enrollment_level   VARCHAR(20)         NOT NULL DEFAULT '',
            guardian_name      VARCHAR(150)        NOT NULL DEFAULT '',
            guardian_contact   VARCHAR(25)         NOT NULL DEFAULT '',
            guardian_whatsapp  VARCHAR(25)         NOT NULL DEFAULT '',
            whatsapp_group     VARCHAR(10)         NOT NULL DEFAULT '',
            future_updates     VARCHAR(10)         NOT NULL DEFAULT '',
            admission_fee      VARCHAR(20)         NOT NULL DEFAULT '',
            flexible_fee_note  VARCHAR(300)        NOT NULL DEFAULT '',
            payment_method     VARCHAR(20)         NOT NULL DEFAULT '',
            payment_amount     DECIMAL(8,2)        NOT NULL DEFAULT 0,
            transaction_id     VARCHAR(100)        NOT NULL DEFAULT '',
            payment_status     VARCHAR(20)         NOT NULL DEFAULT 'pending',
            ip_address         VARCHAR(45)         NOT NULL DEFAULT '',
            user_agent         VARCHAR(255)        NOT NULL DEFAULT '',
            status             VARCHAR(20)         NOT NULL DEFAULT 'pending',
            admin_note         TEXT,
            created_at         DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at         DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            review_reason      VARCHAR(100)        NOT NULL DEFAULT '',
            country_ip         CHAR(2)             NOT NULL DEFAULT '',
            fbp                VARCHAR(100)        NOT NULL DEFAULT '',
            fbc                VARCHAR(255)        NOT NULL DEFAULT '',
            pay_session_id     VARCHAR(100)        NOT NULL DEFAULT '',
            billing_account_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            reminders_sent     TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
            created_via        VARCHAR(10)         NOT NULL DEFAULT 'public',
            existing_student   TINYINT(1)          NOT NULL DEFAULT 0,
            agreed_fee         DECIMAL(8,2)        NULL DEFAULT NULL,
            marketing_consent  VARCHAR(10)         NOT NULL DEFAULT 'unknown',
            PRIMARY KEY        (id),
            KEY                email (email),
            KEY                form_type (form_type),
            KEY                status (status),
            KEY                created_at (created_at),
            KEY                coupon_id (coupon_id),
            KEY                review_reason (review_reason)
        ) {$charset};";

        return [
            $table    => $sql,
            // Waitlist for families outside the US and Canada (3.3.0). No IP address is stored.
            $waitlist => "CREATE TABLE {$waitlist} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(191) NOT NULL DEFAULT '',
            name VARCHAR(150) NOT NULL DEFAULT '',
            country CHAR(2) NOT NULL DEFAULT '',
            program VARCHAR(20) NOT NULL DEFAULT '',
            source VARCHAR(30) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY email_program (email,program),
            KEY country (country)
        ) {$charset};",
        ];
    }

    /**
     * Plugin activation / upgrade: create or extend the tables (dbDelta), then verify. The version
     * is stored only when every column and index of schemas() exists (IQU_Schema::missing), so a
     * step that did not finish is tried again on the next load.
     */
    public static function create_table(): void
    {
        foreach (self::schemas() as $t => $sql) self::apply_schema($t, $sql);

        $problem = self::verify_schema_330();
        if ($problem === '' && ($miss = IQU_Schema::missing_any(self::schemas()))) $problem = 'still missing ' . wp_json_encode($miss);
        if ($problem !== '') {
            // Leave the version behind so the next page load tries again.
            error_log('[IQU] Registration table upgrade to ' . IQU_VERSION . ' not finished — ' . $problem);
            return;
        }

        update_option('iqu_db_version', IQU_VERSION);
    }

    /**
     * The registrations schema step IQU_Schema runs when a column or index is missing, whatever
     * the stored version: the tables, then the one-time backfills. Each backfill has its own flag,
     * so one that already ran (created_via, existing_student …) never runs again.
     */
    public static function schema_step(): void
    {
        self::create_table();
        self::maybe_migrate_created_via();
        self::maybe_migrate_student_fee_columns();
        self::maybe_migrate_marketing_consent();
    }

    /** Registration columns added in 3.3.0, with the definition used if dbDelta did not add one. */
    private const COLUMNS_330 = [
        'review_reason'      => "VARCHAR(100) NOT NULL DEFAULT ''",
        'country_ip'         => "CHAR(2) NOT NULL DEFAULT ''",
        'fbp'                => "VARCHAR(100) NOT NULL DEFAULT ''",
        'fbc'                => "VARCHAR(255) NOT NULL DEFAULT ''",
        'pay_session_id'     => "VARCHAR(100) NOT NULL DEFAULT ''",
        'billing_account_id' => 'BIGINT(20) UNSIGNED NOT NULL DEFAULT 0',
        'reminders_sent'     => 'TINYINT(3) UNSIGNED NOT NULL DEFAULT 0',
    ];

    /**
     * True when the 3.3.0 columns are verified (iqu_db_version is bumped only after that).
     * New columns are written only then, so a failed upgrade can never make an insert fail.
     */
    public static function schema_ready(): bool
    {
        return get_option('iqu_db_version') === IQU_VERSION;
    }

    // ------------------------------------------------------------
    // How a row was created (created_via)
    // ------------------------------------------------------------

    /**
     * public | admin | import. A stored admin / import wins. Otherwise what Billing writes on
     * every row it adds counts as admin: the "IQU Billing (…)" user agent, or the older
     * `existing_student` referral marker. That covers rows from before the column existed and
     * rows saved while the column holds only its default ('public') because the migration has
     * not finished. Website forms never write either marker. Everything else → public.
     */
    public static function created_via(array $row): string
    {
        $via = (string) ($row['created_via'] ?? '');
        if ($via === self::VIA_ADMIN || $via === self::VIA_IMPORT) return $via;
        if ((string) ($row['referral'] ?? '') === self::LEGACY_ADMIN_REFERRAL
            || strpos((string) ($row['user_agent'] ?? ''), self::LEGACY_ADMIN_UA_PREFIX) === 0) {
            return self::VIA_ADMIN;
        }
        return self::VIA_PUBLIC;
    }

    /** True for rows a family submitted on the website. */
    public static function is_public_row(array $row): bool
    {
        return self::created_via($row) === self::VIA_PUBLIC;
    }

    /** True once created_via exists and is backfilled; only then is it written or queried. */
    public static function created_via_ready(): bool
    {
        return is_array(get_option(self::OPT_CREATED_VIA));
    }

    /** Rows by how they were created: ['public' => n, 'admin' => n, 'import' => n]. */
    public static function created_via_counts(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . IQU_TABLE_NAME;
        $out   = array_fill_keys(self::VIA_VALUES, 0);
        if (self::created_via_ready()) {
            foreach ((array) $wpdb->get_results("SELECT created_via AS via, COUNT(*) AS n FROM {$table} GROUP BY created_via", ARRAY_A) as $r) {
                if (isset($out[$r['via']])) $out[$r['via']] = (int) $r['n'];
            }
            return $out;
        }
        $out[self::VIA_ADMIN]  = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE referral = %s OR user_agent LIKE %s",
            self::LEGACY_ADMIN_REFERRAL, $wpdb->esc_like(self::LEGACY_ADMIN_UA_PREFIX) . '%'
        ));
        $out[self::VIA_PUBLIC] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}") - $out[self::VIA_ADMIN];
        return $out;
    }

    /** Backfill counts from the migration (admin, public), or [] before it has run. */
    public static function created_via_backfill(): array
    {
        $o = get_option(self::OPT_CREATED_VIA);
        return is_array($o) ? $o : [];
    }

    /**
     * One-time migration: add `created_via` (apply_schema first, then an explicit ALTER if
     * dbDelta did not add it; dbDelta also never changes NULL / NOT NULL, so a nullable
     * column is fixed with MODIFY after its NULLs are filled), then backfill:
     * rows with the old Billing markers → admin, every other row → public.
     * Nothing else in any row is changed. The flag is set only after verifying.
     */
    public static function maybe_migrate_created_via(): void
    {
        global $wpdb;
        if (self::created_via_ready()) return;
        $table = $wpdb->prefix . IQU_TABLE_NAME;
        $def   = "VARCHAR(10) NOT NULL DEFAULT '" . self::VIA_PUBLIC . "'";

        $cols = self::registration_columns();
        if (!$cols) return;
        if (!isset($cols['created_via'])) {
            self::create_table();
            $cols = self::registration_columns();
        }
        if (!isset($cols['created_via'])) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN created_via {$def}");
            $cols = self::registration_columns();
        }
        if (!isset($cols['created_via'])) {
            error_log('[IQU] created_via column could not be added (' . $wpdb->last_error . '); will retry.');
            return;
        }

        // Backfill. Empty / NULL first (a nullable column added by hand), then the markers.
        $wpdb->query($wpdb->prepare("UPDATE {$table} SET created_via = %s WHERE created_via IS NULL OR created_via = ''", self::VIA_PUBLIC));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET created_via = %s WHERE created_via = %s AND (referral = %s OR user_agent LIKE %s)",
            self::VIA_ADMIN, self::VIA_PUBLIC, self::LEGACY_ADMIN_REFERRAL, $wpdb->esc_like(self::LEGACY_ADMIN_UA_PREFIX) . '%'
        ));
        if (strtoupper((string) ($cols['created_via']['Null'] ?? 'NO')) === 'YES') {
            $wpdb->query("ALTER TABLE {$table} MODIFY COLUMN created_via {$def}");
        }

        // Verify: every row has a known value.
        $in  = implode(',', array_fill(0, count(self::VIA_VALUES), '%s'));
        $bad = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE created_via IS NULL OR created_via NOT IN ({$in})", ...self::VIA_VALUES));
        if ($bad > 0) {
            error_log("[IQU] created_via backfill left {$bad} rows without a value; will retry.");
            return;
        }
        $count = fn(string $v) => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE created_via = %s", $v));
        update_option(self::OPT_CREATED_VIA, [
            'at'     => gmdate('Y-m-d H:i:s'),
            'admin'  => $count(self::VIA_ADMIN),
            'import' => $count(self::VIA_IMPORT),
            'public' => $count(self::VIA_PUBLIC),
        ], false);
    }

    // ------------------------------------------------------------
    // existing_student and agreed_fee
    // ------------------------------------------------------------

    /** Set once both columns exist and existing_student is backfilled: ['at', 'existing_student'] */
    private const OPT_STUDENT_FEE = 'iqu_student_fee_columns_migrated';

    /**
     * existing_student: 1 when the family answered "already an Ilm-ul-Quran student = Yes" on
     *   the website form, or an admin marked the row "Existing student — no free month".
     * agreed_fee: the monthly fee agreed with an admin (Add existing student / CSV import);
     *   NULL when none was agreed (0 is a real value: a full scholarship).
     * Only written or queried once this returns true.
     */
    public static function student_fee_columns_ready(): bool
    {
        return is_array(get_option(self::OPT_STUDENT_FEE));
    }

    /** What the migration recorded (['at', 'existing_student' => rows set from the form answer]), or []. */
    public static function student_fee_backfill(): array
    {
        $o = get_option(self::OPT_STUDENT_FEE);
        return is_array($o) ? $o : [];
    }

    /**
     * One-time migration: add existing_student (NOT NULL DEFAULT 0) and agreed_fee (NULL),
     * through apply_schema() first and an explicit ALTER where dbDelta did not add them. Backfill
     * existing_student = 1 on rows whose review reasons include "existing_student" (the website
     * answer, which was stored only there until now). Nothing else is changed.
     */
    public static function maybe_migrate_student_fee_columns(): void
    {
        global $wpdb;
        if (self::student_fee_columns_ready()) return;
        $table = $wpdb->prefix . IQU_TABLE_NAME;
        $defs  = ['existing_student' => 'TINYINT(1) NOT NULL DEFAULT 0', 'agreed_fee' => 'DECIMAL(8,2) NULL DEFAULT NULL'];

        $cols = self::registration_columns();
        if (!$cols) return;
        if (array_diff_key($defs, $cols)) {
            self::create_table();
            $cols = self::registration_columns();
        }
        foreach ($defs as $col => $def) {
            if (!isset($cols[$col])) $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$col} {$def}");
        }
        $cols = self::registration_columns();
        if ($missing = array_diff(array_keys($defs), array_keys($cols))) {
            error_log('[IQU] Columns could not be added: ' . implode(', ', $missing) . ' (' . $wpdb->last_error . '); will retry.');
            return;
        }
        $wpdb->query("UPDATE {$table} SET existing_student = 0 WHERE existing_student IS NULL");
        $wpdb->query($wpdb->prepare(
            // No other review reason contains this text (help, sibling, card_country, payment_setup …).
            "UPDATE {$table} SET existing_student = 1 WHERE existing_student = 0 AND review_reason LIKE %s",
            '%existing_student%'
        ));
        update_option(self::OPT_STUDENT_FEE, [
            'at'               => gmdate('Y-m-d H:i:s'),
            'existing_student' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE existing_student = 1"),
        ], false);
    }

    // ------------------------------------------------------------
    // marketing_consent
    // ------------------------------------------------------------

    /** The visitor's marketing-cookie choice at submit (IQU_Pixel::consent_from_request). */
    public const CONSENT_ALLOW   = 'allow';
    public const CONSENT_DENY    = 'deny';
    public const CONSENT_UNKNOWN = 'unknown'; // no choice made, or no consent tool active
    public const CONSENT_VALUES  = [self::CONSENT_ALLOW, self::CONSENT_DENY, self::CONSENT_UNKNOWN];

    /** Set once the column exists and every row has a value: ['at', 'rows'] */
    private const OPT_MARKETING_CONSENT = 'iqu_marketing_consent_migrated';

    /** marketing_consent is only written once this returns true (the column exists). */
    public static function marketing_consent_ready(): bool
    {
        return is_array(get_option(self::OPT_MARKETING_CONSENT));
    }

    /** The stored choice of a row; anything else (older rows, before the migration) is unknown. */
    public static function marketing_consent(array $row): string
    {
        $v = (string) ($row['marketing_consent'] ?? '');
        return in_array($v, self::CONSENT_VALUES, true) ? $v : self::CONSENT_UNKNOWN;
    }

    /**
     * One-time migration: add marketing_consent (NOT NULL DEFAULT 'unknown'), through
     * apply_schema() first and an explicit ALTER where dbDelta did not add it. Existing rows get
     * 'unknown' — nobody was asked — so their later server events still go to Meta. Verified
     * before the flag is set; nothing else is changed.
     */
    public static function maybe_migrate_marketing_consent(): void
    {
        global $wpdb;
        if (self::marketing_consent_ready()) return;
        $table = $wpdb->prefix . IQU_TABLE_NAME;
        $def   = "VARCHAR(10) NOT NULL DEFAULT 'unknown'";

        $cols = self::registration_columns();
        if (!$cols) return;
        if (!isset($cols['marketing_consent'])) {
            self::create_table();
            $cols = self::registration_columns();
        }
        if (!isset($cols['marketing_consent'])) {
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN marketing_consent {$def}");
            $cols = self::registration_columns();
        }
        if (!isset($cols['marketing_consent'])) {
            error_log('[IQU] marketing_consent column could not be added (' . $wpdb->last_error . '); will retry.');
            return;
        }
        $wpdb->query($wpdb->prepare("UPDATE {$table} SET marketing_consent = %s WHERE marketing_consent IS NULL OR marketing_consent = ''", self::CONSENT_UNKNOWN));
        $in  = implode(',', array_fill(0, count(self::CONSENT_VALUES), '%s'));
        $bad = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE marketing_consent IS NULL OR marketing_consent NOT IN ({$in})", ...self::CONSENT_VALUES));
        if ($bad > 0) {
            error_log("[IQU] marketing_consent backfill left {$bad} rows without a value; will retry.");
            return;
        }
        update_option(self::OPT_MARKETING_CONSENT, [
            'at'   => gmdate('Y-m-d H:i:s'),
            'rows' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
        ], false);
    }

    /**
     * Admin override on the student page: mark a row "Existing student — no free month" (true)
     * or undo it (false). Stored in existing_student, never in the referral.
     */
    public static function set_existing_student(int $id, bool $existing): bool
    {
        global $wpdb;
        if (!self::student_fee_columns_ready() || !self::get_registration($id)) return false;
        return false !== $wpdb->update($wpdb->prefix . IQU_TABLE_NAME, ['existing_student' => $existing ? 1 : 0], ['id' => $id], ['%d'], ['%d']);
    }

    /**
     * Set (a number) or clear (null) the agreed monthly fee of a registration. The fee is billed
     * exactly (IQU_Billing_Pricing). Setting it also records the note and, when the standard price
     * is known, the reduction it means (shown in reports); clearing it removes an agreed reduction
     * so the standard price applies again (a website coupon's discount is left as it was).
     */
    public static function set_agreed_fee(int $id, ?float $fee): bool
    {
        global $wpdb;
        $row = self::get_registration($id);
        if (!$row || !self::student_fee_columns_ready()) return false;
        $table    = $wpdb->prefix . IQU_TABLE_NAME;
        $standard = round((float) ($row['calculated_amount'] ?? 0), 2);
        $agreed_n = strpos((string) ($row['flexible_fee_note'] ?? ''), 'Agreed monthly fee') === 0;
        if ($fee === null) {
            $ok = false !== $wpdb->query($wpdb->prepare("UPDATE {$table} SET agreed_fee = NULL WHERE id = %d", $id));
            if ($ok && $agreed_n) $ok = false !== $wpdb->update($table, ['flexible_fee_note' => '', 'discount_amount' => 0], ['id' => $id], ['%s', '%f'], ['%d']);
            return $ok;
        }
        $set = ['agreed_fee' => round($fee, 2), 'flexible_fee_note' => 'Agreed monthly fee ' . IQU_Pricing::format($fee)];
        if ($standard > 0 && ($agreed_n || (float) $row['discount_amount'] <= 0)) $set['discount_amount'] = max(0.0, round($standard - $fee, 2));
        return false !== $wpdb->update($table, $set, ['id' => $id]);
    }

    public static function waitlist_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'iqu_waitlist';
    }

    /** Column metadata from SHOW COLUMNS, keyed by column name. */
    private static function registration_columns(): array
    {
        global $wpdb;
        $out = [];
        foreach ((array) $wpdb->get_results('SHOW COLUMNS FROM ' . $wpdb->prefix . IQU_TABLE_NAME, ARRAY_A) as $c) {
            $out[(string) $c['Field']] = $c;
        }
        return $out;
    }

    /**
     * 3.3.0 check after apply_schema(): every new column exists and has a default, and the
     * waitlist table exists. A column dbDelta did not add is added explicitly, once.
     * @return string '' when ready, otherwise the reason.
     */
    private static function verify_schema_330(): string
    {
        global $wpdb;
        $table = $wpdb->prefix . IQU_TABLE_NAME;

        $cols = self::registration_columns();
        if (!$cols) return 'registrations table not readable';
        foreach (self::COLUMNS_330 as $col => $def) {
            if (!isset($cols[$col])) {
                $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$col} {$def}");
            }
        }

        $cols    = self::registration_columns();
        $missing = array_diff(array_keys(self::COLUMNS_330), array_keys($cols));
        if ($missing) return 'missing columns: ' . implode(', ', $missing) . ' (' . $wpdb->last_error . ')';
        foreach (array_keys(self::COLUMNS_330) as $col) {
            if (strtoupper((string) $cols[$col]['Null']) === 'NO' && $cols[$col]['Default'] === null) {
                return "column {$col} is NOT NULL without a default";
            }
        }

        $waitlist = self::waitlist_table();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $waitlist)) !== $waitlist) return 'waitlist table not created';
        return '';
    }

    /**
     * Set the status of one registration without touching its admin note
     * (update_status() replaces the note). Used by the enrollment flow.
     */
    public static function set_status(int $id, string $status): bool
    {
        global $wpdb;
        if (!in_array($status, self::STATUSES, true)) return false;
        if (!self::status_allowed_for($id, $status)) return false;
        return false !== $wpdb->update($wpdb->prefix . IQU_TABLE_NAME, ['status' => $status], ['id' => $id], ['%s'], ['%d']);
    }

    /**
     * "card_pending" (waiting for the family to add a card on the website) only exists for
     * rows a family submitted online; admin and import rows never enter it.
     */
    private static function status_allowed_for(int $id, string $status): bool
    {
        if ($status !== 'card_pending') return true;
        $row = self::get_registration($id);
        return $row !== null && self::is_public_row($row);
    }

    /** Update the 3.3.0 enrollment columns of one registration. Unknown keys are ignored. */
    public static function update_enrollment(int $id, array $fields): bool
    {
        global $wpdb;
        $formats = [
            'review_reason' => '%s', 'country_ip' => '%s', 'fbp' => '%s', 'fbc' => '%s',
            'pay_session_id' => '%s', 'billing_account_id' => '%d', 'reminders_sent' => '%d',
        ];
        $row = [];
        $fmt = [];
        foreach ($formats as $col => $f) {
            if (array_key_exists($col, $fields)) {
                $row[$col] = $fields[$col];
                $fmt[]     = $f;
            }
        }
        if (!$row) return false;
        return false !== $wpdb->update($wpdb->prefix . IQU_TABLE_NAME, $row, ['id' => $id], $fmt, ['%d']);
    }

    /**
     * Set the course of a registration that has none (empty or not a course on the website),
     * with its price at that time. Days are set only when the record has none. Used when an
     * admin chooses the course while setting up billing. A record with a course is not changed.
     */
    public static function fill_course(int $id, string $course, int $days, float $rate, float $amount): bool
    {
        global $wpdb;
        $row = self::get_registration($id);
        if (!$row || !IQU_Pricing::is_valid_course($course) || IQU_Pricing::is_valid_course((string) $row['course_type'])) return false;
        $set = ['course_type' => $course, 'per_class_rate' => round($rate, 2), 'calculated_amount' => round($amount, 2)];
        if ((int) $row['days_per_week'] <= 0 && $days > 0) $set['days_per_week'] = (string) $days;
        // A fee agreed before the course was known becomes the reduction from the standard price
        // (never an increase), so the record prices the same way as Add existing student.
        if (isset($row['agreed_fee']) && $row['agreed_fee'] !== null && (float) $row['discount_amount'] <= 0) {
            $set['discount_amount'] = max(0.0, round($amount - (float) $row['agreed_fee'], 2));
        }
        return false !== $wpdb->update($wpdb->prefix . IQU_TABLE_NAME, $set, ['id' => $id]);
    }

    /** Add a review reason to a registration, keeping the ones it already has. */
    public static function add_review_reason(int $id, string $reason): bool
    {
        $row = self::get_registration($id);
        if (!$row) return false;
        $have = array_filter(explode(',', (string) ($row['review_reason'] ?? '')));
        if (in_array($reason, $have, true)) return true;
        $have[] = $reason;
        return self::update_enrollment($id, ['review_reason' => substr(implode(',', $have), 0, 100)]);
    }


    /**
     * Run create_table again if the stored version is older — dbDelta is idempotent
     * and will add any missing columns without touching existing data.
     */
    public static function maybe_upgrade(): void
    {
        $stored = (string) get_option('iqu_db_version', '');
        if ($stored !== IQU_VERSION) {
            self::create_table();
        }
        // Own flags rather than the version: they also run on sites already at this version.
        self::maybe_migrate_created_via();
        self::maybe_migrate_student_fee_columns();
        self::maybe_migrate_marketing_consent();
        // One-time import from the legacy IUQ plugin table, if present.
        self::maybe_migrate_from_iuq();
    }

    /**
     * One-time auto-migration from the legacy `{$prefix}iuq_registrations`
     * table into the new `{$prefix}iqu_registrations` table.
     *
     * - Runs at most once (guarded by `iqu_iuq_migrated` option).
     * - Copies rows defensively (only columns that exist in both tables).
     * - Forces form_type = 'free' for all imported rows.
     * - Skips rows whose (email + form_type='free') already exists in the new table.
     * - Leaves the legacy table untouched so nothing is lost.
     */
    public static function maybe_migrate_from_iuq(): void
    {
        global $wpdb;

        // Already migrated → nothing to do.
        if (get_option('iqu_iuq_migrated') !== false) {
            return;
        }

        $old_table = $wpdb->prefix . 'iuq_registrations';
        $new_table = $wpdb->prefix . IQU_TABLE_NAME;

        // Check if old table exists.
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $old_table));
        if ($exists !== $old_table) {
            update_option('iqu_iuq_migrated', 0);
            return;
        }

        // Discover columns present on the old table.
        $old_cols = $wpdb->get_col("SHOW COLUMNS FROM `{$old_table}`", 0);
        if (empty($old_cols)) {
            update_option('iqu_iuq_migrated', 0);
            return;
        }
        $old_cols = array_flip($old_cols); // O(1) lookups

        // Columns we are willing to copy (whitelist of new-schema columns).
        $copyable = [
            'first_name',
            'last_name',
            'email',
            'age',
            'country_origin',
            'country_res',
            'quran_level',
            'days_per_week',
            'preferred_days',
            'time_slot',
            'session_dur',
            'languages',
            'whatsapp',
            'memorized',
            'teacher_pref',
            'device',
            'referral',
            'wa_updates',
            'fee_pref',
            'ip_address',
            'user_agent',
            'status',
            'admin_note',
            'created_at',
            'updated_at',
        ];

        $rows = $wpdb->get_results("SELECT * FROM `{$old_table}`", ARRAY_A);
        if (empty($rows)) {
            update_option('iqu_iuq_migrated', 0);
            return;
        }

        $migrated = 0;
        foreach ($rows as $row) {
            $email = isset($row['email']) ? sanitize_email($row['email']) : '';

            // Skip if duplicate (same email already exists as free enrollment).
            if ($email !== '') {
                $dup = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$new_table}` WHERE email = %s AND form_type = %s",
                    $email,
                    self::FORM_FREE
                ));
                if ($dup > 0) continue;
            }

            $data = ['form_type' => self::FORM_FREE];
            foreach ($copyable as $col) {
                if (isset($old_cols[$col]) && isset($row[$col])) {
                    $data[$col] = $row[$col];
                }
            }

            if ($wpdb->insert($new_table, $data)) {
                $migrated++;
            }
        }

        // Record count (non-zero so we never re-run).
        update_option('iqu_iuq_migrated', $migrated > 0 ? $migrated : 0);
    }

    /**
     * Insert a new registration (form_type must already be in $data).
     */
    public static function insert_registration(array $data)
    {
        global $wpdb;
        $table = $wpdb->prefix . IQU_TABLE_NAME;

        if (empty($data['created_at'])) {
            $data['created_at'] = gmdate('Y-m-d H:i:s');
        }

        $inserted = $wpdb->insert($table, $data);

        return $inserted ? (int) $wpdb->insert_id : false;
    }

    /**
     * Update payment fields on an existing row.
     */
    public static function update_payment(int $id, array $fields): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . IQU_TABLE_NAME;

        $allowed = ['payment_method', 'payment_amount', 'transaction_id', 'payment_status'];
        $update  = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $fields)) {
                $update[$k] = $fields[$k];
            }
        }
        if (empty($update)) return false;

        return false !== $wpdb->update($table, $update, ['id' => $id]);
    }

    /**
     * Check duplicate email within a form_type scope
     */
    public static function email_exists(string $email, string $form_type = ''): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . IQU_TABLE_NAME;

        if ($form_type !== '') {
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$table}` WHERE `email` = %s AND `form_type` = %s",
                    $email,
                    $form_type
                )
            );
        } else {
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$table}` WHERE `email` = %s",
                    $email
                )
            );
        }

        return (int) $count > 0;
    }

    /**
     * Paginated registrations
     */
    public static function get_registrations(array $args = []): array
    {
        global $wpdb;
        $table = $wpdb->prefix . IQU_TABLE_NAME;

        $defaults = [
            'form_type' => '',
            'status'    => '',
            'search'    => '',
            'per_page'  => 20,
            'page'      => 1,
            'orderby'   => 'created_at',
            'order'     => 'DESC',
        ];
        $args = wp_parse_args($args, $defaults);

        $allowed_orderby = ['id', 'first_name', 'last_name', 'email', 'status', 'created_at', 'form_type'];
        $orderby = in_array($args['orderby'], $allowed_orderby, true) ? $args['orderby'] : 'created_at';
        $order   = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        $where  = '1=1';
        $values = [];

        if (! empty($args['form_type'])) {
            // string বা array — দুটোই চলে (Weekend-এ দুটো টাইপ একসাথে লাগে)
            $types = array_map('sanitize_text_field', (array) $args['form_type']);
            $ph    = implode(',', array_fill(0, count($types), '%s'));
            $where .= " AND form_type IN ({$ph})";
            foreach ($types as $t) {
                $values[] = $t;
            }
        }

        if (! empty($args['status'])) {
            $where   .= ' AND status = %s';
            $values[] = sanitize_text_field($args['status']);
        }
        // 3.3.0: "Needs review" (any review reason) and enrollments that went through Stripe.
        if (! empty($args['review']) && self::schema_ready()) {
            $where .= " AND review_reason <> ''";
        }
        if (! empty($args['stripe_flow']) && self::schema_ready()) {
            $where .= ' AND billing_account_id > 0';
        }

        if (! empty($args['search'])) {
            $search   = '%' . $wpdb->esc_like(sanitize_text_field($args['search'])) . '%';
            $where   .= ' AND (first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR whatsapp LIKE %s)';
            $values[] = $search;
            $values[] = $search;
            $values[] = $search;
            $values[] = $search;
        }

        $offset   = (absint($args['page']) - 1) * absint($args['per_page']);
        $values[] = absint($args['per_page']);
        $values[] = $offset;

        $sql = "SELECT * FROM `{$table}` WHERE {$where} ORDER BY `{$orderby}` {$order} LIMIT %d OFFSET %d";
        if (! empty($values)) {
            $sql = $wpdb->prepare($sql, $values);
        }

        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    public static function count_registrations(array $args = []): int
    {
        global $wpdb;
        $table  = $wpdb->prefix . IQU_TABLE_NAME;
        $where  = '1=1';
        $values = [];

        if (! empty($args['form_type'])) {
            $types = array_map('sanitize_text_field', (array) $args['form_type']);
            $ph    = implode(',', array_fill(0, count($types), '%s'));
            $where .= " AND form_type IN ({$ph})";
            foreach ($types as $t) {
                $values[] = $t;
            }
        }
        if (! empty($args['status'])) {
            $where   .= ' AND status = %s';
            $values[] = sanitize_text_field($args['status']);
        }
        // 3.3.0: "Needs review" (any review reason) and enrollments that went through Stripe.
        if (! empty($args['review']) && self::schema_ready()) {
            $where .= " AND review_reason <> ''";
        }
        if (! empty($args['stripe_flow']) && self::schema_ready()) {
            $where .= ' AND billing_account_id > 0';
        }
        if (! empty($args['search'])) {
            $search   = '%' . $wpdb->esc_like(sanitize_text_field($args['search'])) . '%';
            $where   .= ' AND (first_name LIKE %s OR last_name LIKE %s OR email LIKE %s)';
            $values[] = $search;
            $values[] = $search;
            $values[] = $search;
        }

        $sql = "SELECT COUNT(*) FROM `{$table}` WHERE {$where}";
        if (! empty($values)) {
            $sql = $wpdb->prepare($sql, $values);
        }

        return (int) $wpdb->get_var($sql);
    }

    public static function get_registration(int $id): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . IQU_TABLE_NAME;

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM `{$table}` WHERE id = %d", $id),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function update_status(int $id, string $status, string $note = ''): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . IQU_TABLE_NAME;

        if (! in_array($status, self::STATUSES, true)) {
            return false;
        }
        if (! self::status_allowed_for($id, $status)) {
            return false;
        }

        $result = $wpdb->update(
            $table,
            ['status' => $status, 'admin_note' => sanitize_textarea_field($note)],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );
        return false !== $result;
    }

    public static function on_deactivation(): void
    {
        wp_cache_flush();
        delete_transient('iqu_stats');
    }
}