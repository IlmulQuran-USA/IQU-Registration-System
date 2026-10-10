<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Billing_DB
 *
 * Three tables, all separate from the registration tables:
 *   iqu_billing_accounts  one row per family / Stripe subscription
 *   iqu_billing_members   which enrollment belongs to which account
 *   iqu_billing_events    every Stripe webhook event seen (dedupe + audit)
 *
 * Security:
 * - Every query uses $wpdb->prepare or $wpdb->insert/update with formats.
 * - update_account() accepts only whitelisted columns.
 * - The private billing link is never stored; only its SHA-256 hash.
 * - Test and live data are kept apart by a `mode` column on every row.
 * - Webhook payloads are not stored (no personal data in the events table).
 */
class IQU_Billing_DB
{
    public const DB_VERSION = '1.3.0';
    private const OPT_VERSION = 'iqu_billing_db_version';

    /** Columns update_account() may change, with their formats. */
    private const ACCOUNT_FIELDS = [
        'stripe_customer_id'     => '%s',
        'stripe_subscription_id' => '%s',
        'guardian_name'          => '%s',
        'contact_email'          => '%s',
        'contact_whatsapp'       => '%s',
        'token_hash'             => '%s',
        'token_created_at'       => '%s',
        'student_type'           => '%s',
        'monthly_amount'         => '%f',
        'discount_amount'        => '%f',
        'net_amount'             => '%f',
        'first_charge_date'      => '%s',
        'next_charge_at'         => '%s',
        'status'                 => '%s',
        'payment_method_label'   => '%s',
        'last_payment_status'    => '%s',
        'last_payment_at'        => '%s',
        'last_failure_reason'    => '%s',
        'email_sent_at'          => '%s',
        'whatsapp_sent_at'       => '%s',
    ];

    public const STATUSES = [
        'not_sent', 'link_sent', 'free_month', 'waiting_first_charge',
        'active', 'past_due', 'unpaid', 'paused', 'canceled',
    ];

    // ------------------------------------------------------------
    // Tables
    // ------------------------------------------------------------

    public static function accounts_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'iqu_billing_accounts';
    }

    public static function members_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'iqu_billing_members';
    }

    public static function events_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'iqu_billing_events';
    }

    /** Create or upgrade tables when the stored version is behind. */
    public static function maybe_install(): void
    {
        if (get_option(self::OPT_VERSION) === self::DB_VERSION) return;
        self::install();
    }

    public static function install(): void
    {
        global $wpdb;

        $charset  = $wpdb->get_charset_collate();
        $accounts = self::accounts_table();
        $members  = self::members_table();
        $events   = self::events_table();

        $wpdb->query("CREATE TABLE IF NOT EXISTS {$accounts} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            mode VARCHAR(4) NOT NULL DEFAULT 'test',
            stripe_customer_id VARCHAR(64) NOT NULL DEFAULT '',
            stripe_subscription_id VARCHAR(64) NOT NULL DEFAULT '',
            guardian_name VARCHAR(150) NOT NULL DEFAULT '',
            contact_email VARCHAR(191) NOT NULL DEFAULT '',
            contact_whatsapp VARCHAR(25) NOT NULL DEFAULT '',
            token_hash CHAR(64) DEFAULT NULL,
            token_created_at DATETIME DEFAULT NULL,
            student_type VARCHAR(10) NOT NULL DEFAULT 'current',
            monthly_amount DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            discount_amount DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            net_amount DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            first_charge_date DATE DEFAULT NULL,
            next_charge_at DATETIME DEFAULT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'not_sent',
            payment_method_label VARCHAR(60) NOT NULL DEFAULT '',
            last_payment_status VARCHAR(20) NOT NULL DEFAULT '',
            last_payment_at DATETIME DEFAULT NULL,
            last_failure_reason VARCHAR(255) NOT NULL DEFAULT '',
            email_sent_at DATETIME DEFAULT NULL,
            whatsapp_sent_at DATETIME DEFAULT NULL,
            created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token_hash (token_hash),
            KEY mode_status (mode, status),
            KEY stripe_customer_id (stripe_customer_id),
            KEY stripe_subscription_id (stripe_subscription_id)
        ) {$charset};");

        $wpdb->query("CREATE TABLE IF NOT EXISTS {$members} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            account_id BIGINT(20) UNSIGNED NOT NULL,
            registration_id BIGINT(20) UNSIGNED NOT NULL,
            mode VARCHAR(4) NOT NULL DEFAULT 'test',
            amount DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            stripe_item_id VARCHAR(64) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY registration_mode (registration_id, mode),
            KEY account_id (account_id)
        ) {$charset};");

        $wpdb->query("CREATE TABLE IF NOT EXISTS {$events} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            stripe_event_id VARCHAR(64) NOT NULL,
            mode VARCHAR(4) NOT NULL DEFAULT 'test',
            type VARCHAR(80) NOT NULL DEFAULT '',
            account_id BIGINT(20) UNSIGNED DEFAULT NULL,
            received_at DATETIME NOT NULL,
            processed_at DATETIME DEFAULT NULL,
            result VARCHAR(20) NOT NULL DEFAULT 'received',
            note VARCHAR(255) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            UNIQUE KEY stripe_event_id (stripe_event_id),
            KEY account_id (account_id)
        ) {$charset};");

        // Payment history (1.2.0): one row per Stripe invoice — paid, failed, open, void,
        // uncollectible or refunded. Created or extended through apply_schema() (PHP 8.5 safe).
        // Index names and definitions match the 1.1.0 table exactly, so dbDelta adds no duplicates.
        $payments = self::payments_table();
        IQU_Database::apply_schema($payments, "CREATE TABLE {$payments} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            stripe_invoice_id VARCHAR(64) NOT NULL,
            account_id BIGINT(20) UNSIGNED NOT NULL,
            mode VARCHAR(4) NOT NULL DEFAULT 'test',
            amount DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            paid_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT '',
            period_month CHAR(7) NOT NULL DEFAULT '',
            period_start DATETIME DEFAULT NULL,
            period_end DATETIME DEFAULT NULL,
            amount_due DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            amount_paid DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            amount_refunded DECIMAL(8,2) NOT NULL DEFAULT 0.00,
            method_label VARCHAR(60) NOT NULL DEFAULT '',
            hosted_invoice_url VARCHAR(500) NOT NULL DEFAULT '',
            invoice_pdf VARCHAR(500) NOT NULL DEFAULT '',
            receipt_number VARCHAR(64) NOT NULL DEFAULT '',
            attempt_count SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
            failure_reason VARCHAR(255) NOT NULL DEFAULT '',
            updated_at DATETIME DEFAULT NULL,
            receipt_sent_at DATETIME DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY stripe_invoice_id (stripe_invoice_id),
            KEY mode_paid (mode,paid_at),
            KEY account_id (account_id),
            KEY mode_period (mode,period_month),
            KEY account_period (account_id,period_month)
        ) {$charset};");

        $problem = self::migrate_payments_120();
        if ($problem === '') $problem = self::migrate_payments_130();
        if ($problem !== '') {
            // Leave the version behind so the next page load tries again.
            error_log('IQU Billing: payments table upgrade to ' . self::DB_VERSION . ' not finished — ' . $problem);
            return;
        }

        update_option(self::OPT_VERSION, self::DB_VERSION, false);
    }

    /** Columns the 1.2.0 payments table must have. */
    private const PAYMENT_COLUMNS_120 = [
        'status', 'period_month', 'period_start', 'period_end', 'amount_due', 'amount_paid',
        'amount_refunded', 'method_label', 'hosted_invoice_url', 'invoice_pdf', 'receipt_number',
        'attempt_count', 'failure_reason', 'updated_at',
    ];

    /**
     * 1.3.0: receipt_sent_at on the payments table — when our own receipt email went out for
     * that invoice (NULL = not sent). Added by apply_schema() above; verified here.
     * @return string '' when the column exists, otherwise the reason.
     */
    private static function migrate_payments_130(): string
    {
        global $wpdb;
        $cols = self::payment_columns();
        if (!isset($cols['receipt_sent_at'])) {
            $wpdb->query('ALTER TABLE ' . self::payments_table() . ' ADD COLUMN receipt_sent_at DATETIME DEFAULT NULL');
            $cols = self::payment_columns();
        }
        return isset($cols['receipt_sent_at']) ? '' : 'missing column: receipt_sent_at (' . $wpdb->last_error . ')';
    }

    /** paid_at column metadata from SHOW COLUMNS, keyed by column name. */
    private static function payment_columns(): array
    {
        global $wpdb;
        $out = [];
        foreach ((array) $wpdb->get_results('SHOW COLUMNS FROM ' . self::payments_table(), ARRAY_A) as $c) {
            $out[(string) $c['Field']] = $c;
        }
        return $out;
    }

    /**
     * 1.2.0 upgrade of the payments table after apply_schema():
     * dbDelta does not relax NOT NULL, so paid_at is changed explicitly (only when needed),
     * the result is verified, and rows from the 1.1.0 ledger get their status and amounts.
     * @return string '' when the table is ready, otherwise the reason.
     */
    private static function migrate_payments_120(): string
    {
        global $wpdb;
        $table = self::payments_table();

        $cols = self::payment_columns();
        if (isset($cols['paid_at']) && strtoupper((string) $cols['paid_at']['Null']) === 'NO') {
            $wpdb->query("ALTER TABLE {$table} MODIFY paid_at DATETIME NULL DEFAULT NULL");
        }

        $cols = self::payment_columns();
        if (!isset($cols['paid_at'])) return 'column paid_at not found';
        if (strtoupper((string) $cols['paid_at']['Null']) !== 'YES') return 'paid_at is still NOT NULL (' . $wpdb->last_error . ')';
        $missing = array_diff(self::PAYMENT_COLUMNS_120, array_keys($cols));
        if ($missing) return 'missing columns: ' . implode(', ', $missing);

        // Rows written by record_payment() before 1.2.0 are paid invoices.
        // period_month is provisional (month of payment); Backfill replaces it with the invoice period.
        $tz = wp_timezone();
        foreach (['test', 'live'] as $mode) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, amount, paid_at FROM {$table} WHERE mode = %s AND status = %s",
                $mode, ''
            ), ARRAY_A) ?: [];
            foreach ($rows as $r) {
                $month = '';
                if (!empty($r['paid_at'])) {
                    $month = (new DateTimeImmutable((string) $r['paid_at'], new DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m');
                }
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$table} SET status = %s, amount_paid = amount, amount_due = amount, period_month = %s, updated_at = %s WHERE id = %d AND mode = %s AND status = %s",
                    'paid', $month, current_time('mysql', true), (int) $r['id'], $mode, ''
                ));
            }
        }
        return '';
    }

    // ------------------------------------------------------------
    // Accounts
    // ------------------------------------------------------------

    /** @return int New account id, or 0 on failure. */
    public static function create_account(array $data): int
    {
        global $wpdb;
        $now  = current_time('mysql', true);
        $row  = ['mode' => IQU_Stripe::expected_mode(), 'created_by' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now];
        $fmt  = ['%s', '%d', '%s', '%s'];
        foreach (self::ACCOUNT_FIELDS as $col => $f) {
            if (array_key_exists($col, $data)) {
                $row[$col] = $data[$col];
                $fmt[]     = $f;
            }
        }
        if (isset($row['status']) && !in_array($row['status'], self::STATUSES, true)) return 0;

        $ok = $wpdb->insert(self::accounts_table(), $row, $fmt);
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    public static function get_account(int $id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::accounts_table() . ' WHERE id = %d AND mode = %s',
            $id, IQU_Stripe::expected_mode()
        ), ARRAY_A);
        return $row ?: null;
    }

    /** Look up by the SHA-256 hash of a billing link token. */
    public static function get_account_by_token_hash(string $hash): ?array
    {
        global $wpdb;
        if (!preg_match('/^[a-f0-9]{64}$/', $hash)) return null;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::accounts_table() . ' WHERE token_hash = %s AND mode = %s',
            $hash, IQU_Stripe::expected_mode()
        ), ARRAY_A);
        return $row ?: null;
    }

    public static function get_account_by_subscription(string $sub_id): ?array
    {
        global $wpdb;
        if ($sub_id === '') return null;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::accounts_table() . ' WHERE stripe_subscription_id = %s AND mode = %s',
            $sub_id, IQU_Stripe::expected_mode()
        ), ARRAY_A);
        return $row ?: null;
    }

    public static function get_account_by_customer(string $cus_id): ?array
    {
        global $wpdb;
        if ($cus_id === '') return null;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::accounts_table() . ' WHERE stripe_customer_id = %s AND mode = %s',
            $cus_id, IQU_Stripe::expected_mode()
        ), ARRAY_A);
        return $row ?: null;
    }

    /** Update whitelisted columns only. Unknown keys are ignored. */
    public static function update_account(int $id, array $data): bool
    {
        global $wpdb;
        $row = [];
        $fmt = [];
        foreach (self::ACCOUNT_FIELDS as $col => $f) {
            if (array_key_exists($col, $data)) {
                $row[$col] = $data[$col];
                $fmt[]     = $f;
            }
        }
        if (!$row) return false;
        if (isset($row['status']) && !in_array($row['status'], self::STATUSES, true)) return false;

        $row['updated_at'] = current_time('mysql', true);
        $fmt[]             = '%s';

        return false !== $wpdb->update(
            self::accounts_table(), $row,
            ['id' => $id, 'mode' => IQU_Stripe::expected_mode()],
            $fmt, ['%d', '%s']
        );
    }

    // ------------------------------------------------------------
    // Members (enrollments inside an account)
    // ------------------------------------------------------------

    public static function add_member(int $account_id, int $registration_id, float $amount, string $item_id = ''): bool
    {
        global $wpdb;
        return (bool) $wpdb->insert(self::members_table(), [
            'account_id'      => $account_id,
            'registration_id' => $registration_id,
            'mode'            => IQU_Stripe::expected_mode(),
            'amount'          => $amount,
            'stripe_item_id'  => $item_id,
            'created_at'      => current_time('mysql', true),
        ], ['%d', '%d', '%s', '%f', '%s', '%s']);
    }

    public static function get_members(int $account_id): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::members_table() . ' WHERE account_id = %d AND mode = %s ORDER BY id',
            $account_id, IQU_Stripe::expected_mode()
        ), ARRAY_A) ?: [];
    }

    /** The account an enrollment already belongs to in the current mode, if any. */
    public static function account_for_registration(int $registration_id): ?array
    {
        global $wpdb;
        $account_id = $wpdb->get_var($wpdb->prepare(
            'SELECT account_id FROM ' . self::members_table() . ' WHERE registration_id = %d AND mode = %s',
            $registration_id, IQU_Stripe::expected_mode()
        ));
        return $account_id ? self::get_account((int) $account_id) : null;
    }

    // ------------------------------------------------------------
    // Webhook events (dedupe + audit, no payloads)
    // ------------------------------------------------------------

    /**
     * Record an event the first time it is seen.
     * @return bool true if new, false if already recorded (skip processing).
     */
    public static function record_event(string $event_id, string $type): bool
    {
        global $wpdb;
        if (!preg_match('/^evt_[A-Za-z0-9]+$/', $event_id)) return false;
        $ok = $wpdb->query($wpdb->prepare(
            'INSERT IGNORE INTO ' . self::events_table() . ' (stripe_event_id, mode, type, received_at) VALUES (%s, %s, %s, %s)',
            $event_id, IQU_Stripe::expected_mode(), substr($type, 0, 80), current_time('mysql', true)
        ));
        return $ok === 1;
    }

    public static function finish_event(string $event_id, string $result, ?int $account_id = null, string $note = ''): void
    {
        global $wpdb;
        $wpdb->update(
            self::events_table(),
            [
                'processed_at' => current_time('mysql', true),
                'result'       => substr($result, 0, 20),
                'account_id'   => $account_id,
                'note'         => substr($note, 0, 255),
            ],
            ['stripe_event_id' => $event_id],
            ['%s', '%s', '%d', '%s'],
            ['%s']
        );
    }
    // ------------------------------------------------------------
    // Payments ledger (one row per paid invoice)
    // ------------------------------------------------------------

    public static function payments_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'iqu_billing_payments';
    }

    /** Record a paid invoice once. A repeat of the same invoice is ignored. */
    public static function record_payment(string $invoice_id, int $account_id, float $amount, int $paid_at): void
    {
        global $wpdb;
        if (!preg_match('/^in_[A-Za-z0-9]+$/', $invoice_id) || $amount <= 0) return;
        $wpdb->query($wpdb->prepare(
            'INSERT IGNORE INTO ' . self::payments_table() . ' (stripe_invoice_id, account_id, mode, amount, paid_at, created_at) VALUES (%s, %d, %s, %f, %s, %s)',
            $invoice_id, $account_id, IQU_Stripe::expected_mode(), $amount, gmdate('Y-m-d H:i:s', $paid_at), current_time('mysql', true)
        ));
    }

    /** @return array{count:int, total:float} payments in the current mode between two UTC times. */
    public static function paid_between(int $from, int $to): array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total FROM ' . self::payments_table() . ' WHERE mode = %s AND paid_at >= %s AND paid_at < %s',
            IQU_Stripe::expected_mode(), gmdate('Y-m-d H:i:s', $from), gmdate('Y-m-d H:i:s', $to)
        ), ARRAY_A);
        return ['count' => (int) ($row['n'] ?? 0), 'total' => round((float) ($row['total'] ?? 0), 2)];
    }
}
