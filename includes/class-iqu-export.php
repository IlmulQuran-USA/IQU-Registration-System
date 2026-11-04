<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Export
 *
 * CSV or Excel (.xlsx) for every file the plugin hands out, and the one button that offers both.
 *
 * - open() / close() wrap each export's existing CSV writer. CSV: the stream is the browser, byte for byte
 *   what the export wrote before. Excel: the same CSV goes to a temporary stream and close() turns it into
 *   an .xlsx (IQU_Xlsx) — so both files always have the same columns, rows and values.
 * - choice(): one button ("Export", "Download", "Import"); clicking it shows two choices, CSV and
 *   Excel (.xlsx). A <details> menu, so it works without JavaScript; admin.js closes it on Escape, a click
 *   elsewhere, or a choice. The choice is sent as format=csv / format=xlsx (format()).
 */
final class IQU_Export
{
    public const CSV  = 'csv';
    public const XLSX = 'xlsx';
    public const CHOICES = [self::CSV => 'CSV', self::XLSX => 'Excel (.xlsx)'];

    /** Excel downloads in progress: stream id => file name. */
    private static $pending = [];

    /** The format a request asked for: 'xlsx', or 'csv' (the default, as before). */
    public static function format(?array $from = null): string
    {
        $v = ($from ?? $_REQUEST)['format'] ?? '';
        return is_string($v) && strtolower($v) === self::XLSX ? self::XLSX : self::CSV;
    }

    /**
     * Starts a download named $filename (given as name.csv; Excel gets name.xlsx). Returns the stream the export
     * writes its CSV to. Excel on a server without ZipArchive: an error page instead, nothing downloaded.
     */
    public static function open(string $filename, ?string $format = null)
    {
        $format = $format ?? self::format();
        if ($format === self::XLSX) {
            if (!IQU_Xlsx::available()) wp_die(esc_html(IQU_Xlsx::MSG_NO_ZIP), 'Excel not available', ['response' => 500, 'back_link' => true]);
            $h = fopen('php://temp', 'w+b');
            self::$pending[(int) $h] = preg_replace('/\.csv$/i', '', $filename) . '.xlsx';
            return $h;
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        return fopen('php://output', 'w');
    }

    /** Ends a download started by open(): CSV is closed; Excel is built from the CSV written and sent. */
    public static function close($h): void
    {
        $id = (int) $h;
        if (!isset(self::$pending[$id])) {
            fclose($h);
            return;
        }
        $name = self::$pending[$id];
        unset(self::$pending[$id]);
        $rows = self::csv_rows($h);
        fclose($h);
        $tmp = tempnam(get_temp_dir(), 'iqu-xlsx');
        if ($tmp === false || !IQU_Xlsx::write($rows, $tmp)) {
            if ($tmp) @unlink($tmp);
            wp_die('The Excel file could not be made. Please try CSV.', 'Excel not available', ['response' => 500, 'back_link' => true]);
        }
        header('Content-Type: ' . IQU_Xlsx::MIME);
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
    }

    /** The rows of a CSV stream written by IQU_CSV / fputcsv (RFC 4180, no escape character), without the BOM. */
    public static function csv_rows($h): array
    {
        rewind($h);
        // The BOM is skipped before parsing: a quoted first cell ("IQU no") must be read without its quotes.
        if (fread($h, 3) !== "\xEF\xBB\xBF") rewind($h);
        $rows = [];
        while (($cells = fgetcsv($h, 0, ',', '"', '')) !== false) {
            $rows[] = array_map(fn($c) => (string) $c, $cells);
        }
        return $rows;
    }

    /**
     * One button with two choices, CSV and Excel (.xlsx).
     *
     * @param array $a label   the button's text ("Export", "Download", "Import")
     *                 class   the button's classes (the look the old button had)
     *                 icon    HTML before the text (already escaped: a dashicon or IQU_Billing_Page::icon())
     *                 href    links: each choice is this URL plus format=csv / format=xlsx
     *                 submit  true: each choice is a submit button name="format" (for the form it is in)
     *                 attrs   extra attributes on each choice (e.g. data-iqu-export, data-csv-for)
     *                 align   'end' (default: the list lines up with the button's right edge) or 'start'
     *                 id      id of the menu (optional)
     */
    public static function choice(array $a): string
    {
        $wrap = 'iqu-fmt' . (($a['align'] ?? 'end') === 'start' ? ' iqu-fmt--start' : '');
        $html = '<details class="' . esc_attr($wrap) . '"' . (!empty($a['id']) ? ' id="' . esc_attr((string) $a['id']) . '"' : '') . '>'
            . '<summary class="' . esc_attr((string) ($a['class'] ?? 'iqu-export-btn')) . '">' . ($a['icon'] ?? '')
            . '<span class="iqu-fmt-label">' . esc_html((string) ($a['label'] ?? 'Export')) . '</span><span class="iqu-fmt-caret" aria-hidden="true"></span></summary>'
            . '<div class="iqu-fmt-list">';
        $attrs = '';
        foreach ((array) ($a['attrs'] ?? []) as $k => $v) $attrs .= ' ' . esc_attr((string) $k) . '="' . esc_attr((string) $v) . '"';
        foreach (self::CHOICES as $fmt => $text) {
            if (isset($a['href'])) {
                $html .= '<a class="iqu-fmt-item" href="' . esc_url(add_query_arg('format', $fmt, (string) $a['href'])) . '"' . $attrs . '>' . esc_html($text) . '</a>';
            } elseif (!empty($a['submit'])) {
                $html .= '<button type="submit" class="iqu-fmt-item" name="format" value="' . esc_attr($fmt) . '"' . $attrs . '>' . esc_html($text) . '</button>';
            } else {
                $html .= '<button type="button" class="iqu-fmt-item" data-format="' . esc_attr($fmt) . '"' . $attrs . '>' . esc_html($text) . '</button>';
            }
        }
        return $html . '</div></details>';
    }
}
