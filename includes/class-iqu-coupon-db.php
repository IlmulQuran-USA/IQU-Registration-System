<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Coupon_DB
 *
 * 🎟️ কুপন টেবিলের স্কিমা ও CRUD।
 *
 * 🔒 Security:
 * - dbDelta দিয়ে নিরাপদ মাইগ্রেশন (IQU_Database-এর প্যাটার্ন অনুসরণ করে)
 * - সব কোয়েরিতে prepared statement
 * - orderby / status হোয়াইটলিস্ট করা
 *
 * টেবিল: {$wpdb->prefix}iqu_coupons
 */
class IQU_Coupon_DB
{
    public const STATUS_ACTIVE   = 'active';
    public const STATUS_DISABLED = 'disabled';

    public const TYPE_PERCENTAGE = 'percentage';
    public const TYPE_FIXED      = 'fixed';

    /** ORDER BY-তে যেসব কলাম অনুমোদিত */
    private const ALLOWED_ORDERBY = [
        'id',
        'code',
        'occasion_name',
        'expire_date',
        'used_count',
        'created_at',
    ];

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . IQU_COUPON_TABLE;
    }

    // ════════════════════════════════════════════════════
    // SCHEMA
    // ════════════════════════════════════════════════════

    public static function create_table(): void
    {
        global $wpdb;
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            code            VARCHAR(40)         NOT NULL DEFAULT '',
            occasion_name   VARCHAR(150)        NOT NULL DEFAULT '',
            course_type     VARCHAR(20)         NOT NULL DEFAULT '',
            discount_type   VARCHAR(20)         NOT NULL DEFAULT 'percentage',
            discount_value  DECIMAL(8,2)        NOT NULL DEFAULT 0,
            expire_date     DATE                NULL DEFAULT NULL,
            max_uses        INT(11) UNSIGNED    NOT NULL DEFAULT 1,
            used_count      INT(11) UNSIGNED    NOT NULL DEFAULT 0,
            status          VARCHAR(20)         NOT NULL DEFAULT 'active',
            description     TEXT,
            created_by      BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            created_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY     (id),
            UNIQUE KEY      code (code),
            KEY             status (status),
            KEY             course_type (course_type),
            KEY             expire_date (expire_date)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option('iqu_coupon_db_version', IQU_VERSION);
    }

    /**
     * ভার্সন বদলালে স্কিমা আবার চালাও — dbDelta idempotent,
     * বিদ্যমান ডেটা অক্ষত রেখে শুধু নতুন কলাম যোগ করে।
     */
    public static function maybe_upgrade(): void
    {
        if ((string) get_option('iqu_coupon_db_version', '') !== IQU_VERSION) {
            self::create_table();
        }
    }

    // ════════════════════════════════════════════════════
    // READ
    // ════════════════════════════════════════════════════

    /** কোড দিয়ে একটি কুপন আনো (case-insensitive) */
    public static function get_by_code(string $code): ?array
    {
        global $wpdb;
        $table = self::table();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM `{$table}` WHERE `code` = %s LIMIT 1",
                strtoupper(trim($code))
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    public static function get(int $id): ?array
    {
        global $wpdb;
        $table = self::table();

        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM `{$table}` WHERE `id` = %d LIMIT 1", $id),
            ARRAY_A
        );

        return $row ?: null;
    }

    public static function code_exists(string $code): bool
    {
        global $wpdb;
        $table = self::table();

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE `code` = %s",
                strtoupper(trim($code))
            )
        ) > 0;
    }

    /**
     * অ্যাডমিন লিস্টের জন্য পেজিনেটেড কুপন।
     *
     * @param array $args  status, course_type, search, orderby, order, per_page, paged
     * @return array{items: array, total: int}
     */
    public static function get_coupons(array $args = []): array
    {
        global $wpdb;
        $table = self::table();

        $defaults = [
            'status'      => '',
            'course_type' => '',
            'search'      => '',
            'orderby'     => 'created_at',
            'order'       => 'DESC',
            'per_page'    => 20,
            'paged'       => 1,
        ];
        $args = array_merge($defaults, $args);

        // 🔒 orderby / order হোয়াইটলিস্ট
        $orderby = in_array($args['orderby'], self::ALLOWED_ORDERBY, true)
            ? $args['orderby']
            : 'created_at';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        $where  = ['1=1'];
        $params = [];

        if ($args['status'] !== '') {
            $where[]  = '`status` = %s';
            $params[] = $args['status'];
        }

        if ($args['course_type'] !== '') {
            $where[]  = '`course_type` = %s';
            $params[] = $args['course_type'];
        }

        if ($args['search'] !== '') {
            $like     = '%' . $wpdb->esc_like($args['search']) . '%';
            $where[]  = '(`code` LIKE %s OR `occasion_name` LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = implode(' AND ', $where);

        // মোট সংখ্যা
        $count_sql = "SELECT COUNT(*) FROM `{$table}` WHERE {$where_sql}";
        $total     = (int) $wpdb->get_var(
            $params ? $wpdb->prepare($count_sql, $params) : $count_sql
        );

        // পেজিনেশন
        $per_page = max(1, (int) $args['per_page']);
        $paged    = max(1, (int) $args['paged']);
        $offset   = ($paged - 1) * $per_page;

        $sql          = "SELECT * FROM `{$table}` WHERE {$where_sql} "
            . "ORDER BY `{$orderby}` {$order} LIMIT %d OFFSET %d";
        $list_params  = $params;
        $list_params[] = $per_page;
        $list_params[] = $offset;

        $items = $wpdb->get_results($wpdb->prepare($sql, $list_params), ARRAY_A) ?: [];

        return ['items' => $items, 'total' => $total];
    }

    /** ড্যাশবোর্ড কাউন্টার */
    public static function get_stats(): array
    {
        global $wpdb;
        $table = self::table();
        $today = current_time('Y-m-d');

        return [
            'total'    => (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`"),
            'active'   => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE `status` = %s AND (`expire_date` IS NULL OR `expire_date` >= %s)",
                self::STATUS_ACTIVE,
                $today
            )),
            'expired'  => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE `expire_date` IS NOT NULL AND `expire_date` < %s",
                $today
            )),
            'redeemed' => (int) $wpdb->get_var("SELECT COALESCE(SUM(`used_count`),0) FROM `{$table}`"),
        ];
    }

    /**
     *
     * @return array{full:float,full_count:int,special:float,special_count:int,
     *               percent:float,percent_count:int,fixed:float,fixed_count:int,total:float}
     */
    public static function get_discount_summary(): array
    {
        global $wpdb;

        $coupons = self::table();
        $regs    = $wpdb->prefix . IQU_TABLE_NAME;
        $fixed   = self::TYPE_FIXED;

        // কোনো ইউজার ইনপুট নেই — সব মান ক্লাস কনস্ট্যান্ট বা টেবিল নাম
        $row = $wpdb->get_row(
            "SELECT
                COALESCE(SUM(CASE WHEN r.coupon_id > 0 AND r.payment_amount <= 0
                    THEN r.discount_amount END), 0) AS full_amount,
                COALESCE(SUM(CASE WHEN r.coupon_id > 0 AND r.payment_amount <= 0
                    THEN 1 END), 0) AS full_count,

                COALESCE(SUM(CASE WHEN r.special_discount = 1
                    THEN r.discount_amount END), 0) AS special_amount,
                COALESCE(SUM(CASE WHEN r.special_discount = 1
                    THEN 1 END), 0) AS special_count,

                COALESCE(SUM(CASE WHEN r.coupon_id > 0 AND r.payment_amount > 0
                    AND (c.discount_type IS NULL OR c.discount_type <> '{$fixed}')
                    THEN r.discount_amount END), 0) AS percent_amount,
                COALESCE(SUM(CASE WHEN r.coupon_id > 0 AND r.payment_amount > 0
                    AND (c.discount_type IS NULL OR c.discount_type <> '{$fixed}')
                    THEN 1 END), 0) AS percent_count,

                COALESCE(SUM(CASE WHEN r.coupon_id > 0 AND r.payment_amount > 0
                    AND c.discount_type = '{$fixed}'
                    THEN r.discount_amount END), 0) AS fixed_amount,
                COALESCE(SUM(CASE WHEN r.coupon_id > 0 AND r.payment_amount > 0
                    AND c.discount_type = '{$fixed}'
                    THEN 1 END), 0) AS fixed_count

             FROM `{$regs}` r
             LEFT JOIN `{$coupons}` c ON c.id = r.coupon_id
             WHERE r.discount_amount > 0",
            ARRAY_A
        );

        $out = [
            'full'          => (float) ($row['full_amount']    ?? 0),
            'full_count'    => (int)   ($row['full_count']     ?? 0),
            'special'       => (float) ($row['special_amount'] ?? 0),
            'special_count' => (int)   ($row['special_count']  ?? 0),
            'percent'       => (float) ($row['percent_amount'] ?? 0),
            'percent_count' => (int)   ($row['percent_count']  ?? 0),
            'fixed'         => (float) ($row['fixed_amount']   ?? 0),
            'fixed_count'   => (int)   ($row['fixed_count']    ?? 0),
        ];

        $out['total'] = $out['full'] + $out['special'] + $out['percent'] + $out['fixed'];

        return $out;
    }

    // ════════════════════════════════════════════════════
    // WRITE
    // ════════════════════════════════════════════════════

    /** @return int|false নতুন কুপনের id */
    public static function insert(array $data)
    {
        global $wpdb;

        $data['code'] = strtoupper(trim($data['code'] ?? ''));

        if (empty($data['created_at'])) {
            $data['created_at'] = current_time('mysql');
        }

        $inserted = $wpdb->insert(self::table(), $data);

        return $inserted ? (int) $wpdb->insert_id : false;
    }

    public static function update(int $id, array $fields): bool
    {
        global $wpdb;

        $allowed = [
            'occasion_name',
            'course_type',
            'discount_type',
            'discount_value',
            'expire_date',
            'max_uses',
            'status',
            'description',
        ];

        $update = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $fields)) {
                $update[$key] = $fields[$key];
            }
        }

        if (empty($update)) {
            return false;
        }

        return false !== $wpdb->update(self::table(), $update, ['id' => $id]);
    }

    public static function delete(int $id): bool
    {
        global $wpdb;
        return false !== $wpdb->delete(self::table(), ['id' => $id], ['%d']);
    }

    /**
     * ব্যবহারের কাউন্ট বাড়াও — atomic।
     *
     * ⚠️ WHERE ক্লজে max_uses চেক থাকায় দুটো রেজিস্ট্রেশন একসাথে এলেও
     *    লিমিটের বেশি রিডিম হবে না (race condition সুরক্ষা)।
     *
     * @return bool  সফলভাবে রিডিম হলে true
     */
    public static function redeem(int $id): bool
    {
        global $wpdb;
        $table = self::table();

        $affected = $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}`
                 SET `used_count` = `used_count` + 1
                 WHERE `id` = %d
                   AND `status` = %s
                   AND (`max_uses` = 0 OR `used_count` < `max_uses`)",
                $id,
                self::STATUS_ACTIVE
            )
        );

        return (int) $affected === 1;
    }
}