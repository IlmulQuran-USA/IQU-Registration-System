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

    /**
     * Plugin activation: create table (or migrate)
     */
    public static function create_table(): void
    {
        global $wpdb;
        $table   = $wpdb->prefix . IQU_TABLE_NAME;
        $charset = $wpdb->get_charset_collate();

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
            PRIMARY KEY        (id),
            KEY                email (email),
            KEY                form_type (form_type),
            KEY                status (status),
            KEY                created_at (created_at),
            KEY                coupon_id (coupon_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option('iqu_db_version', IQU_VERSION);
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
            $where   .= ' AND form_type = %s';
            $values[] = sanitize_text_field($args['form_type']);
        }

        if (! empty($args['status'])) {
            $where   .= ' AND status = %s';
            $values[] = sanitize_text_field($args['status']);
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
            $where   .= ' AND form_type = %s';
            $values[] = sanitize_text_field($args['form_type']);
        }
        if (! empty($args['status'])) {
            $where   .= ' AND status = %s';
            $values[] = sanitize_text_field($args['status']);
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

        $allowed = ['pending', 'confirmed', 'contacted', 'enrolled', 'cancelled'];
        if (! in_array($status, $allowed, true)) {
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