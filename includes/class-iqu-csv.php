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
 * - 3.7.6: Excel in a region whose list separator is ";" (much of Europe and Asia) starts a new cell at every
 *   ";" outside quotes, and a quoted cell protects only the first cell of a line. So each part after a ";"
 *   gets the same guard ("Sara;=1+1" → "Sara;'=1+1"), and a cell holding ";" is quoted as well.
 * - Rows are written as fputcsv() writes them (RFC 4180, no escape character), plus that ";" quoting.
 */
final class IQU_CSV
{
    /** A cell that a spreadsheet will not run as a formula (also when ";" is the list separator). */
    public static function safe($value): string
    {
        $v = is_scalar($value) ? (string) $value : '';
        $num = fn(string $s) => (bool) preg_match('/^-?\d+(\.\d+)?$/', $s);
        if ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false && !$num($v)) $v = "'" . $v;
        if (strpos($v, ';') !== false) {
            $v = (string) preg_replace_callback('/;([=+\-@\t\r][^;]*)/', fn($m) => ';' . ($num($m[1]) ? '' : "'") . $m[1], $v);
        }
        return $v;
    }

    /**
     * Writes one row, every cell made safe. Quoted exactly as fputcsv() quotes (RFC 4180, no escape character: a
     * cell holding , " CR LF tab or a space is quoted, quotes doubled, "\n" at the end) — and, since 3.7.6, also a
     * cell holding ";": Excel in a region whose list separator is ";" would split such a cell there and could run
     * the part after it as a formula ("Sara;=1+1"). Quoted, it stays one cell. A spreadsheet reads the same values.
     */
    public static function put($handle, array $cells): void
    {
        $line = [];
        foreach (array_values($cells) as $cell) {
            $v = self::safe($cell);
            $line[] = strpbrk($v, ",\"\r\n\t ;") !== false ? '"' . str_replace('"', '""', $v) . '"' : $v;
        }
        fwrite($handle, implode(',', $line) . "\n");
    }
}
