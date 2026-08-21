<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Zeffy_DB
 *
 * Storage layer for Zeffy donation payments.
 *
 * 🔒 Security:
 * - dbDelta for safe schema migrations
 * - Prepared statements for ALL queries
 * - Whitelisted orderby / status values
 *
 * 💰 Money is stored in CENTS (BIGINT) exactly as Zeffy returns it.
 *    Never use FLOAT for currency. Use self::money() to display.
 *
 * Idempotency: `payment_id` is UNIQUE, so upsert() is safe to call
 * repeatedly from both the webhook and the cron reconcile job.
 */
class IQU_Zeffy_DB
{
    /** Refund states returned by Zeffy */
    public const REFUND_STATES = ['none', 'partial', 'full'];

    /** Payment states returned by Zeffy */
    public const PAYMENT_STATES = ['succeeded', 'failed', 'pending'];

    /** Where a row came from */
    public const SOURCE_API     = 'api';
    public const SOURCE_WEBHOOK = 'webhook';

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . IQU_ZEFFY_TABLE;
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
            id                 BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            payment_id         VARCHAR(64)         NOT NULL DEFAULT '',
            created_ts         BIGINT(20)          NOT NULL DEFAULT 0,
            amount             BIGINT(20)          NOT NULL DEFAULT 0,
            eligible_amount    BIGINT(20)          NOT NULL DEFAULT 0,
            currency           VARCHAR(8)          NOT NULL DEFAULT 'usd',
            status             VARCHAR(20)         NOT NULL DEFAULT '',
            pay_type           VARCHAR(20)         NOT NULL DEFAULT '',
            refund_status      VARCHAR(20)         NOT NULL DEFAULT 'none',
            refunded_amount    BIGINT(20)          NOT NULL DEFAULT 0,
            dispute_status     VARCHAR(30)         NOT NULL DEFAULT '',
            dispute_reason     VARCHAR(100)        NOT NULL DEFAULT '',
            description        VARCHAR(255)        NOT NULL DEFAULT '',
            contact_id         VARCHAR(64)         NOT NULL DEFAULT '',
            buyer_email        VARCHAR(191)        NOT NULL DEFAULT '',
            buyer_first_name   VARCHAR(100)        NOT NULL DEFAULT '',
            buyer_last_name    VARCHAR(100)        NOT NULL DEFAULT '',
            is_corporate       TINYINT(1)          NOT NULL DEFAULT 0,
            company_name       VARCHAR(191)        NOT NULL DEFAULT '',
            method_type        VARCHAR(30)         NOT NULL DEFAULT '',
            card_brand         VARCHAR(30)         NOT NULL DEFAULT '',
            card_last4         VARCHAR(8)          NOT NULL DEFAULT '',
            campaign_id        VARCHAR(64)         NOT NULL DEFAULT '',
            campaign_type      VARCHAR(30)         NOT NULL DEFAULT '',
            campaign_category  VARCHAR(30)         NOT NULL DEFAULT '',
            fund_code          VARCHAR(64)         NOT NULL DEFAULT '',
            fund_name          VARCHAR(191)        NOT NULL DEFAULT '',
            is_recurring       TINYINT(1)          NOT NULL DEFAULT 0,
            recurring_interval VARCHAR(20)         NOT NULL DEFAULT '',
            recurring_status   VARCHAR(20)         NOT NULL DEFAULT '',
            subscription_id    VARCHAR(64)         NOT NULL DEFAULT '',
            tribute_type       VARCHAR(20)         NOT NULL DEFAULT '',
            tribute_name       VARCHAR(191)        NOT NULL DEFAULT '',
            receipt_url        VARCHAR(255)        NOT NULL DEFAULT '',
            raw_json           LONGTEXT,
            source             VARCHAR(10)         NOT NULL DEFAULT 'api',
            synced_at          DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY        (id),
            UNIQUE KEY         payment_id (payment_id),
            KEY                created_ts (created_ts),
            KEY                status (status),
            KEY                campaign_id (campaign_id),
            KEY                buyer_email (buyer_email),
            KEY                is_recurring (is_recurring)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option('iqu_zeffy_db_version', IQU_VERSION);
    }

    /**
     * Called from IQU_Database::maybe_upgrade() flow — dbDelta is idempotent.
     */
    public static function maybe_upgrade(): void
    {
        if ((string) get_option('iqu_zeffy_db_version', '') !== IQU_VERSION) {
            self::create_table();
        }
    }

    // ════════════════════════════════════════════════════
    // MAPPING  (Zeffy payment object → DB row)
    // ════════════════════════════════════════════════════

    /**
     * Flatten a Zeffy Payment object into our column set.
     *
     * The API is Beta, so every field is read defensively and the
     * untouched payload is preserved in `raw_json` as the source of truth.
     *
     * @param array  $p      Decoded Payment object from Zeffy.
     * @param string $source self::SOURCE_API | self::SOURCE_WEBHOOK
     */
    public static function map_payment(array $p, string $source = self::SOURCE_API): array
    {
        $buyer   = is_array($p['buyer'] ?? null) ? $p['buyer'] : [];
        $method  = is_array($p['payment_method'] ?? null) ? $p['payment_method'] : [];
        $fund    = is_array($p['fund'] ?? null) ? $p['fund'] : [];
        $rec     = is_array($p['recurring'] ?? null) ? $p['recurring'] : [];
        $dispute = is_array($p['dispute'] ?? null) ? $p['dispute'] : [];

        // Sum only refunds that actually succeeded.
        $refunded = 0;
        if (!empty($p['refunds']) && is_array($p['refunds'])) {
            foreach ($p['refunds'] as $r) {
                if (is_array($r) && ($r['status'] ?? '') === 'succeeded') {
                    $refunded += (int) ($r['amount'] ?? 0);
                }
            }
        }

        // Tribute lives on donation line items, not on the payment root.
        $tribute_type = '';
        $tribute_name = '';
        if (!empty($p['items']) && is_array($p['items'])) {
            foreach ($p['items'] as $item) {
                if (is_array($item) && is_array($item['tribute'] ?? null)) {
                    $tribute_type = (string) ($item['tribute']['type'] ?? '');
                    $tribute_name = (string) ($item['tribute']['honouree_name'] ?? '');
                    break;
                }
            }
        }

        // Fund name is a locale-keyed object: {"EN": "...", "FR": "..."}
        $fund_name = '';
        if (!empty($fund['name'])) {
            if (is_array($fund['name'])) {
                $fund_name = (string) ($fund['name']['EN'] ?? reset($fund['name']));
            } else {
                $fund_name = (string) $fund['name'];
            }
        }

        return [
            'payment_id'         => (string) ($p['id'] ?? ''),
            'created_ts'         => (int) ($p['created'] ?? 0),
            'amount'             => (int) ($p['amount'] ?? 0),
            'eligible_amount'    => (int) ($p['eligible_amount'] ?? 0),
            'currency'           => strtolower((string) ($p['currency'] ?? 'usd')),
            'status'             => (string) ($p['status'] ?? ''),
            'pay_type'           => (string) ($p['type'] ?? ''),
            'refund_status'      => (string) ($p['refund_status'] ?? 'none'),
            'refunded_amount'    => $refunded,
            'dispute_status'     => (string) ($dispute['status'] ?? ''),
            'dispute_reason'     => (string) ($dispute['reason'] ?? ''),
            'description'        => (string) ($p['description'] ?? ''),
            'contact_id'         => (string) ($p['contact'] ?? ''),
            'buyer_email'        => (string) ($buyer['email'] ?? ''),
            'buyer_first_name'   => (string) ($buyer['first_name'] ?? ''),
            'buyer_last_name'    => (string) ($buyer['last_name'] ?? ''),
            'is_corporate'       => !empty($buyer['is_corporate']) ? 1 : 0,
            'company_name'       => (string) ($buyer['company_name'] ?? ''),
            'method_type'        => (string) ($method['type'] ?? ''),
            'card_brand'         => (string) ($method['brand'] ?? ''),
            'card_last4'         => (string) ($method['last4'] ?? ''),
            'campaign_id'        => (string) ($p['campaign_id'] ?? ''),
            'campaign_type'      => (string) ($p['campaign_type'] ?? ''),
            'campaign_category'  => (string) ($p['campaign_category'] ?? ''),
            'fund_code'          => (string) ($fund['code'] ?? ''),
            'fund_name'          => $fund_name,
            'is_recurring'       => !empty($rec['is_recurring']) ? 1 : 0,
            'recurring_interval' => (string) ($rec['interval'] ?? ''),
            'recurring_status'   => (string) ($rec['status'] ?? ''),
            'subscription_id'    => (string) ($rec['subscription_id'] ?? ''),
            'tribute_type'       => $tribute_type,
            'tribute_name'       => $tribute_name,
            'receipt_url'        => (string) ($p['receipt_url'] ?? ''),
            'raw_json'           => wp_json_encode($p),
            'source'             => $source === self::SOURCE_WEBHOOK ? self::SOURCE_WEBHOOK : self::SOURCE_API,
            'synced_at'          => current_time('mysql', true),
        ];
    }

    // ════════════════════════════════════════════════════
    // WRITE
    // ════════════════════════════════════════════════════

    /**
     * Insert or update a single payment, keyed on the Zeffy payment_id.
     *
     * @return string '' on failure, 'inserted' or 'updated' on success.
     */
    public static function upsert(array $payment, string $source = self::SOURCE_API): string
    {
        global $wpdb;

        $row = self::map_payment($payment, $source);
        if ($row['payment_id'] === '') {
            return '';
        }

        $table    = self::table();
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM `{$table}` WHERE payment_id = %s",
            $row['payment_id']
        ));

        if ($existing) {
            $ok = $wpdb->update($table, $row, ['id' => (int) $existing]);
            return ($ok !== false) ? 'updated' : '';
        }

        $ok = $wpdb->insert($table, $row);
        return $ok ? 'inserted' : '';
    }

    /**
     * Bulk upsert. Returns ['inserted' => n, 'updated' => n, 'failed' => n].
     */
    public static function upsert_many(array $payments, string $source = self::SOURCE_API): array
    {
        $result = ['inserted' => 0, 'updated' => 0, 'failed' => 0];

        foreach ($payments as $p) {
            if (!is_array($p)) {
                $result['failed']++;
                continue;
            }
            $outcome = self::upsert($p, $source);
            if ($outcome === '') {
                $result['failed']++;
            } else {
                $result[$outcome]++;
            }
        }

        return $result;
    }

    // ════════════════════════════════════════════════════
    // READ
    // ════════════════════════════════════════════════════

    /**
     * Paginated payment list with filters.
     *
     * Accepted args: status, campaign_id, search, recurring ('1'|'0'|''),
     *                date_from / date_to (Y-m-d), page, per_page, orderby, order
     */
    public static function get_payments(array $args = []): array
    {
        global $wpdb;
        $table = self::table();

        $args = wp_parse_args($args, [
            'status'      => '',
            'campaign_id' => '',
            'search'      => '',
            'recurring'   => '',
            'date_from'   => '',
            'date_to'     => '',
            'per_page'    => 20,
            'page'        => 1,
            'orderby'     => 'created_ts',
            'order'       => 'DESC',
        ]);

        [$where, $values] = self::build_where($args);

        $allowed_orderby = ['created_ts', 'amount', 'status', 'buyer_last_name', 'buyer_email', 'campaign_id'];
        $orderby = in_array($args['orderby'], $allowed_orderby, true) ? $args['orderby'] : 'created_ts';
        $order   = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        $per_page = max(1, absint($args['per_page']));
        $offset   = (max(1, absint($args['page'])) - 1) * $per_page;

        $values[] = $per_page;
        $values[] = $offset;

        $sql = "SELECT * FROM `{$table}` WHERE {$where} ORDER BY `{$orderby}` {$order} LIMIT %d OFFSET %d";
        $sql = $wpdb->prepare($sql, $values);

        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    public static function count_payments(array $args = []): int
    {
        global $wpdb;
        $table = self::table();

        [$where, $values] = self::build_where($args);

        $sql = "SELECT COUNT(*) FROM `{$table}` WHERE {$where}";
        if (!empty($values)) {
            $sql = $wpdb->prepare($sql, $values);
        }

        return (int) $wpdb->get_var($sql);
    }

    public static function get_payment(string $payment_id): ?array
    {
        global $wpdb;
        $table = self::table();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `{$table}` WHERE payment_id = %s",
            $payment_id
        ), ARRAY_A);

        return $row ?: null;
    }

    /**
     * Shared WHERE builder so list + count always agree.
     *
     * @return array{0:string,1:array}
     */
    private static function build_where(array $args): array
    {
        global $wpdb;

        // count_payments() may be called with a partial arg set.
        $args = wp_parse_args($args, [
            'status'      => '',
            'campaign_id' => '',
            'search'      => '',
            'recurring'   => '',
            'date_from'   => '',
            'date_to'     => '',
        ]);

        $where  = '1=1';
        $values = [];

        if (!empty($args['status']) && in_array($args['status'], self::PAYMENT_STATES, true)) {
            $where   .= ' AND status = %s';
            $values[] = $args['status'];
        }

        if (!empty($args['campaign_id'])) {
            $where   .= ' AND campaign_id = %s';
            $values[] = sanitize_text_field($args['campaign_id']);
        }

        $recurring = (string) ($args['recurring'] ?? '');
        if ($recurring === '1' || $recurring === '0') {
            $where   .= ' AND is_recurring = %d';
            $values[] = (int) $recurring;
        }

        if (!empty($args['search'])) {
            $like     = '%' . $wpdb->esc_like(sanitize_text_field($args['search'])) . '%';
            $where   .= ' AND (buyer_email LIKE %s OR buyer_first_name LIKE %s OR buyer_last_name LIKE %s'
                . ' OR company_name LIKE %s OR description LIKE %s OR payment_id LIKE %s)';
            array_push($values, $like, $like, $like, $like, $like, $like);
        }

        if (!empty($args['date_from'])) {
            $ts = strtotime($args['date_from'] . ' 00:00:00 UTC');
            if ($ts) {
                $where   .= ' AND created_ts >= %d';
                $values[] = $ts;
            }
        }

        if (!empty($args['date_to'])) {
            $ts = strtotime($args['date_to'] . ' 23:59:59 UTC');
            if ($ts) {
                $where   .= ' AND created_ts <= %d';
                $values[] = $ts;
            }
        }

        return [$where, $values];
    }

    // ════════════════════════════════════════════════════
    // AGGREGATES  (for the metric cards)
    // ════════════════════════════════════════════════════

    /**
     * Totals across successful, non-refunded donations.
     */
    public static function get_stats(): array
    {
        global $wpdb;
        $table = self::table();

        $row = $wpdb->get_row(
            "SELECT
                COUNT(*)                                   AS total_count,
                COALESCE(SUM(amount), 0)                   AS gross_cents,
                COALESCE(SUM(refunded_amount), 0)          AS refunded_cents,
                COUNT(DISTINCT NULLIF(buyer_email, ''))    AS donor_count,
                COALESCE(SUM(is_recurring), 0)             AS recurring_count
             FROM `{$table}`
             WHERE status = 'succeeded'",
            ARRAY_A
        ) ?: [];

        $gross    = (int) ($row['gross_cents'] ?? 0);
        $refunded = (int) ($row['refunded_cents'] ?? 0);
        $count    = (int) ($row['total_count'] ?? 0);
        $net      = $gross - $refunded;

        // This month (UTC), for the trend card.
        $month_start = (int) strtotime(gmdate('Y-m-01 00:00:00') . ' UTC');
        $month_cents = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount - refunded_amount), 0) FROM `{$table}`
             WHERE status = 'succeeded' AND created_ts >= %d",
            $month_start
        ));

        return [
            'total_count'     => $count,
            'gross_cents'     => $gross,
            'refunded_cents'  => $refunded,
            'net_cents'       => $net,
            'month_cents'     => $month_cents,
            'donor_count'     => (int) ($row['donor_count'] ?? 0),
            'recurring_count' => (int) ($row['recurring_count'] ?? 0),
            'avg_cents'       => $count > 0 ? (int) round($net / $count) : 0,
        ];
    }

    /**
     * Distinct campaigns present in the local data, for the filter dropdown.
     */
    public static function get_campaign_options(): array
    {
        global $wpdb;
        $table = self::table();

        $rows = $wpdb->get_results(
            "SELECT campaign_id, MAX(description) AS title, COUNT(*) AS cnt
             FROM `{$table}`
             WHERE campaign_id <> ''
             GROUP BY campaign_id
             ORDER BY cnt DESC
             LIMIT 50",
            ARRAY_A
        ) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $out[$r['campaign_id']] = $r['title'] !== '' ? $r['title'] : $r['campaign_id'];
        }
        return $out;
    }

    /**
     * Net donations per month for the last N months — Chart.js friendly.
     */
    public static function get_monthly_series(int $months = 6): array
    {
        global $wpdb;
        $table = self::table();

        $labels = [];
        $data   = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $start = (int) strtotime(gmdate('Y-m-01 00:00:00', strtotime("-{$i} months")) . ' UTC');
            $end   = (int) strtotime('+1 month', $start);

            $cents = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(amount - refunded_amount), 0) FROM `{$table}`
                 WHERE status = 'succeeded' AND created_ts >= %d AND created_ts < %d",
                $start,
                $end
            ));

            $labels[] = gmdate('M Y', $start);
            $data[]   = round($cents / 100, 2);
        }

        return ['labels' => $labels, 'data' => $data];
    }

    // ════════════════════════════════════════════════════
    // HELPERS
    // ════════════════════════════════════════════════════

    /**
     * Cents → display string. Zeffy amounts are ALWAYS in cents.
     */
    public static function money(int $cents, string $currency = 'usd', bool $with_code = false): string
    {
        $symbol = ($currency === 'cad') ? 'CA$' : '$';
        $out    = $symbol . number_format($cents / 100, 2);
        return $with_code ? $out . ' ' . strtoupper($currency) : $out;
    }

    /**
     * Unix timestamp → site-readable date. Falls back to the plugin's
     * existing Asia/Dhaka convention used elsewhere in the admin.
     */
    public static function format_date(int $ts, string $format = 'M j, Y g:i A'): string
    {
        if ($ts <= 0) return '—';

        try {
            $dt = new DateTime('@' . $ts);
            $dt->setTimezone(new DateTimeZone('Asia/Dhaka'));
            return $dt->format($format);
        } catch (Exception $e) {
            return '—';
        }
    }

    /**
     * Best-effort donor display name.
     */
    public static function donor_name(array $row): string
    {
        if (!empty($row['is_corporate']) && !empty($row['company_name'])) {
            return $row['company_name'];
        }

        $name = trim(($row['buyer_first_name'] ?? '') . ' ' . ($row['buyer_last_name'] ?? ''));
        return $name !== '' ? $name : 'Anonymous';
    }

    // ════════════════════════════════════════════════════
    // ANALYTICS  (chart data sources)
    // ════════════════════════════════════════════════════

    /**
     * Convert a "last N months" window into a unix timestamp.
     * 0 months means "all time".
     */
    private static function window_start(int $months): int
    {
        if ($months <= 0) return 0;
        return (int) strtotime("-{$months} months");
    }

    /**
     * Top donors by net contribution over a rolling window.
     *
     * Grouped by email because that is the only stable donor identifier
     * Zeffy gives us on the payment object. Donations with no email
     * (rare, but possible on manual entries) are excluded rather than
     * lumped together into a misleading "Anonymous" bar.
     *
     * @return array{labels:string[], data:float[], meta:array[]}
     */
    public static function get_top_donors(int $months = 3, int $limit = 10): array
    {
        global $wpdb;
        $table = self::table();
        $since = self::window_start($months);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT
                buyer_email,
                MAX(buyer_first_name)  AS first_name,
                MAX(buyer_last_name)   AS last_name,
                MAX(company_name)      AS company_name,
                MAX(is_corporate)      AS is_corporate,
                MAX(is_recurring)      AS is_recurring,
                COUNT(*)               AS gift_count,
                SUM(amount - refunded_amount) AS net_cents
             FROM `{$table}`
             WHERE status = 'succeeded'
               AND buyer_email <> ''
               AND created_ts >= %d
             GROUP BY buyer_email
             ORDER BY net_cents DESC
             LIMIT %d",
            $since,
            max(1, $limit)
        ), ARRAY_A) ?: [];

        $labels = [];
        $data   = [];
        $meta   = [];

        foreach ($rows as $r) {
            $name = self::donor_name([
                'is_corporate'     => $r['is_corporate'],
                'company_name'     => $r['company_name'],
                'buyer_first_name' => $r['first_name'],
                'buyer_last_name'  => $r['last_name'],
            ]);

            $labels[] = $name;
            $data[]   = round((int) $r['net_cents'] / 100, 2);
            $meta[]   = [
                'email'     => $r['buyer_email'],
                'gifts'     => (int) $r['gift_count'],
                'recurring' => (int) $r['is_recurring'] === 1,
            ];
        }

        return ['labels' => $labels, 'data' => $data, 'meta' => $meta];
    }

    /**
     * Net raised per campaign. Everything beyond $limit is folded
     * into an "Other" slice so the doughnut stays readable.
     */
    public static function get_campaign_breakdown(int $months = 12, int $limit = 6): array
    {
        global $wpdb;
        $table = self::table();
        $since = self::window_start($months);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT
                campaign_id,
                MAX(description)              AS title,
                SUM(amount - refunded_amount) AS net_cents
             FROM `{$table}`
             WHERE status = 'succeeded' AND created_ts >= %d
             GROUP BY campaign_id
             ORDER BY net_cents DESC",
            $since
        ), ARRAY_A) ?: [];

        $labels = [];
        $data   = [];
        $other  = 0;

        foreach ($rows as $i => $r) {
            $cents = (int) $r['net_cents'];

            if ($i < $limit) {
                $title    = $r['title'] !== '' ? $r['title'] : 'Untitled campaign';
                $labels[] = mb_strimwidth($title, 0, 34, '…');
                $data[]   = round($cents / 100, 2);
            } else {
                $other += $cents;
            }
        }

        if ($other > 0) {
            $labels[] = 'Other campaigns';
            $data[]   = round($other / 100, 2);
        }

        return ['labels' => $labels, 'data' => $data];
    }

    /**
     * Recurring vs one-time, by money and by gift count.
     */
    public static function get_type_split(int $months = 12): array
    {
        global $wpdb;
        $table = self::table();
        $since = self::window_start($months);

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN is_recurring = 1 THEN amount - refunded_amount ELSE 0 END), 0) AS rec_cents,
                COALESCE(SUM(CASE WHEN is_recurring = 0 THEN amount - refunded_amount ELSE 0 END), 0) AS one_cents,
                COALESCE(SUM(is_recurring), 0)      AS rec_count,
                COALESCE(SUM(1 - is_recurring), 0)  AS one_count
             FROM `{$table}`
             WHERE status = 'succeeded' AND created_ts >= %d",
            $since
        ), ARRAY_A) ?: [];

        return [
            'labels' => ['Recurring', 'One-time'],
            'data'   => [
                round((int) ($row['rec_cents'] ?? 0) / 100, 2),
                round((int) ($row['one_cents'] ?? 0) / 100, 2),
            ],
            'counts' => [
                (int) ($row['rec_count'] ?? 0),
                (int) ($row['one_count'] ?? 0),
            ],
        ];
    }

    /**
     * How many gifts land in each amount bracket.
     *
     * Buckets are fixed rather than computed so the chart stays
     * comparable month over month.
     */
    public static function get_donation_size_buckets(int $months = 12): array
    {
        global $wpdb;
        $table = self::table();
        $since = self::window_start($months);

        // [label, min cents, max cents (null = no ceiling)]
        $buckets = [
            ['Under $10',  0,     999],
            ['$10 – $24',  1000,  2499],
            ['$25 – $49',  2500,  4999],
            ['$50 – $99',  5000,  9999],
            ['$100 – $249', 10000, 24999],
            ['$250+',      25000, null],
        ];

        $labels = [];
        $data   = [];

        foreach ($buckets as [$label, $min, $max]) {
            if ($max === null) {
                $count = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$table}`
                     WHERE status = 'succeeded' AND created_ts >= %d
                       AND (amount - refunded_amount) >= %d",
                    $since,
                    $min
                ));
            } else {
                $count = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$table}`
                     WHERE status = 'succeeded' AND created_ts >= %d
                       AND (amount - refunded_amount) BETWEEN %d AND %d",
                    $since,
                    $min,
                    $max
                ));
            }

            $labels[] = $label;
            $data[]   = $count;
        }

        return ['labels' => $labels, 'data' => $data];
    }

    /**
     * New vs returning donors per month.
     *
     * "New" means the donor's very first successful gift (across all
     * time, not just the window) falls inside that month. The two
     * lookups are cheap and the grouping is done in PHP so the logic
     * stays readable — donation volumes here are in the thousands,
     * not millions.
     */
    public static function get_new_vs_returning(int $months = 6): array
    {
        global $wpdb;
        $table = self::table();

        $window_start = (int) strtotime(gmdate('Y-m-01 00:00:00', strtotime('-' . ($months - 1) . ' months')) . ' UTC');

        // First-ever gift date for every donor.
        $first_rows = $wpdb->get_results(
            "SELECT buyer_email, MIN(created_ts) AS first_ts
             FROM `{$table}`
             WHERE status = 'succeeded' AND buyer_email <> ''
             GROUP BY buyer_email",
            ARRAY_A
        ) ?: [];

        $first_seen = [];
        foreach ($first_rows as $r) {
            $first_seen[$r['buyer_email']] = (int) $r['first_ts'];
        }

        // Donors active inside the window.
        $active_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT buyer_email, created_ts
             FROM `{$table}`
             WHERE status = 'succeeded' AND buyer_email <> '' AND created_ts >= %d",
            $window_start
        ), ARRAY_A) ?: [];

        $labels = [];
        $new    = [];
        $ret    = [];
        $index  = [];

        for ($i = $months - 1; $i >= 0; $i--) {
            $start = (int) strtotime(gmdate('Y-m-01 00:00:00', strtotime("-{$i} months")) . ' UTC');
            $key   = gmdate('Y-m', $start);

            $index[$key] = count($labels);
            $labels[]    = gmdate('M Y', $start);
            $new[]       = 0;
            $ret[]       = 0;
        }

        // A donor is counted once per month, in one bucket or the other.
        $seen_this_month = [];

        foreach ($active_rows as $r) {
            $key = gmdate('Y-m', (int) $r['created_ts']);
            if (!isset($index[$key])) continue;

            $email = $r['buyer_email'];
            $slot  = $key . '|' . $email;
            if (isset($seen_this_month[$slot])) continue;
            $seen_this_month[$slot] = true;

            $first     = $first_seen[$email] ?? 0;
            $is_new    = $first > 0 && gmdate('Y-m', $first) === $key;
            $position  = $index[$key];

            if ($is_new) {
                $new[$position]++;
            } else {
                $ret[$position]++;
            }
        }

        return ['labels' => $labels, 'new' => $new, 'returning' => $ret];
    }

    public static function get_month_donors(int $limit = 10): array
    {
        global $wpdb;
        $table = self::table();
        $start = (int) strtotime(gmdate('Y-m-01 00:00:00') . ' UTC');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT
                buyer_email,
                MAX(buyer_first_name) AS first_name,
                MAX(buyer_last_name)  AS last_name,
                MAX(company_name)     AS company_name,
                MAX(is_corporate)     AS is_corporate,
                MAX(is_recurring)     AS is_recurring,
                MAX(created_ts)       AS last_ts,
                COUNT(*)              AS donation_count,
                SUM(amount - refunded_amount) AS net_cents
             FROM `{$table}`
             WHERE status = 'succeeded'
               AND buyer_email <> ''
               AND created_ts >= %d
             GROUP BY buyer_email
             ORDER BY net_cents DESC
             LIMIT %d",
            $start,
            max(1, $limit)
        ), ARRAY_A) ?: [];

        // Month totals are computed separately so they stay accurate
        // even when more donors exist than the chart displays.
        $totals = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COALESCE(SUM(amount - refunded_amount), 0)  AS net_cents,
                COUNT(DISTINCT NULLIF(buyer_email, ''))     AS donor_count
             FROM `{$table}`
             WHERE status = 'succeeded' AND created_ts >= %d",
            $start
        ), ARRAY_A) ?: [];

        $labels = [];
        $data   = [];
        $meta   = [];

        foreach ($rows as $r) {
            $labels[] = self::donor_name([
                'is_corporate'     => $r['is_corporate'],
                'company_name'     => $r['company_name'],
                'buyer_first_name' => $r['first_name'],
                'buyer_last_name'  => $r['last_name'],
            ]);
            $data[] = round((int) $r['net_cents'] / 100, 2);
            $meta[] = [
                'email'     => $r['buyer_email'],
                'gifts'     => (int) $r['donation_count'],
                'recurring' => (int) $r['is_recurring'] === 1,
                'last'      => self::format_date((int) $r['last_ts'], 'M j'),
            ];
        }

        return [
            'labels' => $labels,
            'data'   => $data,
            'meta'   => $meta,
            'total'  => round((int) ($totals['net_cents'] ?? 0) / 100, 2),
            'donors' => (int) ($totals['donor_count'] ?? 0),
            'month'  => gmdate('F Y'),
        ];
    }


    /**
     * Everything the analytics section needs, in one call.
     */
    public static function get_analytics(): array
    {
        return [
            'monthly'        => self::get_monthly_series(6),
            'month_donors'   => self::get_month_donors(10),
            'top_donors'     => self::get_top_donors(3, 8),
            'type_split'     => self::get_type_split(12),
            'campaigns'      => self::get_campaign_breakdown(12, 6),
            'donation_sizes' => self::get_donation_size_buckets(12),
            'donor_mix'      => self::get_new_vs_returning(6),
        ];
    }


    public static function drop_table(): void
    {
        global $wpdb;
        $table = self::table();
        $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
        delete_option('iqu_zeffy_db_version');
    }
}