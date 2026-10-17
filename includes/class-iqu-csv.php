<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_CSV
 *
 * One writer for every CSV the plugin produces (registrations, coupons, waitlist, Zeffy donations,
 * billing reports, import sample / results).
 *
 * - Spreadsheet formula injection: values typed by the public (a name, an email, a Zeffy donor or
 *   tribute name) can start with = + - @ TAB or CR, which Excel / Sheets run as a formula when the
 *   file is opened. Such a cell gets a leading apostrophe. A plain number ("-12.50", "40") is left
 *   alone so amounts stay numbers.
 * - fputcsv() is always called with an explicit $escape of '' (RFC 4180 quoting): PHP 8.4 deprecates
 *   relying on its default.
 */
final class IQU_CSV
{
    /** A cell that a spreadsheet will not run as a formula. */
    public static function safe($value): string
    {
        $v = is_scalar($value) ? (string) $value : '';
        if ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false && !preg_match('/^-?\d+(\.\d+)?$/', $v)) return "'" . $v;
        return $v;
    }

    /** Writes one row, every cell made safe. */
    public static function put($handle, array $cells): void
    {
        fputcsv($handle, array_map([self::class, 'safe'], array_values($cells)), ',', '"', '');
    }
}
