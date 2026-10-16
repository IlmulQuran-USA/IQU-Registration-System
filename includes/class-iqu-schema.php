<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Schema
 *
 * Schema self-check for every IQU table, independent of the stored version numbers.
 *
 * Each table's CREATE TABLE statement (its owner's schemas()) is the single source of truth: the
 * columns and indexes it declares must exist. maybe_check() compares every table with it and, for
 * any table that is missing something, runs that table's own schema step (which also sets its
 * version option, but only once its own check passes); if something is still missing, dbDelta()
 * is run on that statement directly. The result is recorded in one option per plugin + billing
 * DB version (OPT_CHECKED, autoloaded), so on every later request the whole check costs a single
 * option read; it runs again only when a version changes.
 *
 * Backfills are not part of this: they keep their own one-time flags (IQU_Database::maybe_migrate_*),
 * so re-running a schema step never repeats one.
 */
final class IQU_Schema
{
    /** IQU_VERSION . '|' . billing DB version, set once every table passed the check. */
    public const OPT_CHECKED = 'iqu_schema_checked';

    /** The version pair the check is recorded for. */
    public static function stamp(): string
    {
        return IQU_VERSION . '|' . (class_exists('IQU_Billing_DB') ? IQU_Billing_DB::DB_VERSION : '-');
    }

    /**
     * Column and index names a CREATE TABLE statement (dbDelta format) declares.
     * @return array{columns: string[], indexes: string[]}  PRIMARY KEY is index "PRIMARY".
     */
    public static function expected(string $sql): array
    {
        $open = strpos($sql, '(');
        $close = strrpos($sql, ')');
        $cols = $idx = [];
        foreach (explode("\n", substr($sql, $open + 1, $close - $open - 1)) as $line) {
            $line = trim(rtrim(trim($line), ','));
            if ($line === '') continue;
            if (preg_match('/^PRIMARY\s+KEY\b/i', $line)) { $idx[] = 'PRIMARY'; continue; }
            if (preg_match('/^(?:UNIQUE\s+)?(?:KEY|INDEX)\s+`?(\w+)`?/i', $line, $m)) { $idx[] = $m[1]; continue; }
            if (preg_match('/^(?:FULLTEXT|SPATIAL|CONSTRAINT|FOREIGN)\b/i', $line)) continue;
            if (preg_match('/^`?(\w+)`?\s+\w/', $line, $m)) $cols[] = $m[1];
        }
        return ['columns' => $cols, 'indexes' => $idx];
    }

    /**
     * What a table lacks compared with its CREATE statement.
     * @return array  [] when complete; otherwise ['table' => 'missing'] or ['columns' => [...], 'indexes' => [...]].
     */
    public static function missing(string $table, string $sql): array
    {
        global $wpdb;
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return ['table' => 'missing'];
        $want = self::expected($sql);
        $cols = array_map(fn($c) => (string) $c['Field'], (array) $wpdb->get_results("SHOW COLUMNS FROM `{$table}`", ARRAY_A));
        $keys = array_map(fn($k) => (string) $k['Key_name'], (array) $wpdb->get_results("SHOW INDEX FROM `{$table}`", ARRAY_A));
        $out = array_filter([
            'columns' => array_values(array_diff($want['columns'], $cols)),
            'indexes' => array_values(array_diff($want['indexes'], array_unique($keys))),
        ]);
        return $out;
    }

    /** missing() for several tables: [table => what is missing], only the incomplete ones. */
    public static function missing_any(array $schemas): array
    {
        $out = [];
        foreach ($schemas as $table => $sql) {
            if ($m = self::missing($table, $sql)) $out[$table] = $m;
        }
        return $out;
    }

    /** Every IQU table: table => [CREATE statement, its schema step]. */
    private static function tables(): array
    {
        $out = [];
        $add = function (array $schemas, callable $step) use (&$out) {
            foreach ($schemas as $table => $sql) $out[$table] = [$sql, $step];
        };
        $add(IQU_Database::schemas(), [IQU_Database::class, 'schema_step']);
        if (class_exists('IQU_Coupon_DB')) $add(IQU_Coupon_DB::schemas(), [IQU_Coupon_DB::class, 'create_table']);
        if (class_exists('IQU_Zeffy_DB')) $add(IQU_Zeffy_DB::schemas(), [IQU_Zeffy_DB::class, 'create_table']);
        if (class_exists('IQU_Billing_DB')) $add(IQU_Billing_DB::schemas(), [IQU_Billing_DB::class, 'install']);
        return $out;
    }

    /**
     * On every load (init): nothing when this version pair was already checked. Otherwise every
     * table is compared with its CREATE statement; a table that lacks a column or index gets its
     * own schema step, then dbDelta() on its statement if still needed. Recorded only when every
     * table is complete, so a failed step is tried again on the next request.
     */
    public static function maybe_check(): void
    {
        $stamp = self::stamp();
        if (get_option(self::OPT_CHECKED) === $stamp) return;

        $ran = [];
        $bad = [];
        foreach (self::tables() as $table => [$sql, $step]) {
            if (!self::missing($table, $sql)) continue;
            $key = is_array($step) ? implode('::', $step) : 'step';
            if (!isset($ran[$key])) { call_user_func($step); $ran[$key] = true; }
            if (self::missing($table, $sql)) IQU_Database::apply_schema($table, $sql);
            if ($m = self::missing($table, $sql)) $bad[$table] = $m;
        }
        if ($bad) {
            error_log('[IQU] Schema check: still missing after the schema steps — ' . wp_json_encode($bad) . '; will retry.');
            return;
        }
        update_option(self::OPT_CHECKED, $stamp, true);
    }
}
