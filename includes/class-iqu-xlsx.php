<?php
if (!defined('ABSPATH')) exit;

/**
 * Class IQU_Xlsx
 *
 * Excel (.xlsx) files without a library. An .xlsx file is a zip of a few XML files; it is written and read
 * with PHP's own ZipArchive (the "zip" extension) and XMLReader. available() says whether this server has both.
 *
 * write(): one sheet. A plain number ("40", "-12.50") is a number cell ("12.50" keeps its two decimals);
 *   everything else is a text cell, which a spreadsheet never runs as a formula. Phone-length digit strings
 *   stay text, so they are never shown as 1.47E+10.
 * read(): the first sheet, as the rows a CSV of it would give: every cell as text, a date as YYYY-MM-DD
 *   (a time as HH:MM), TRUE / FALSE, a formula as its last calculated value. Rows are keyed by their
 *   spreadsheet row number (1 = the first row), so messages can name the row the admin sees.
 *
 * Reading is limited (a 1 MB upload never honestly needs more): 20 MB of XML per part, 1000 columns,
 * and at most $max_rows non-empty rows after the first. External entities are never loaded.
 */
final class IQU_Xlsx
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    public const MSG_NO_ZIP = 'Excel (.xlsx) files need the PHP "zip" feature (ZipArchive), which this server does not have. Please use CSV, or ask your host to turn on the PHP zip extension.';
    public const MSG_NOT_XLSX = 'This file could not be read as an Excel (.xlsx) workbook. Save it again from Excel or Google Sheets as .xlsx, or use CSV.';

    private const MAX_PART = 20971520;
    private const MAX_COLS = 1000;
    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** ZipArchive and XMLReader are both on this server. */
    public static function available(): bool
    {
        return class_exists('ZipArchive') && class_exists('XMLReader');
    }

    // ------------------------------------------------------------
    // Writing
    // ------------------------------------------------------------

    /** Writes $rows (lists of cells) as a one-sheet workbook to $path. False when it could not be written. */
    public static function write(array $rows, string $path): bool
    {
        if (!self::available()) return false;
        $z = new ZipArchive();
        if ($z->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return false;
        $head = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $z->addFromString('[Content_Types].xml', $head
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>');
        $z->addFromString('_rels/.rels', $head
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $z->addFromString('xl/workbook.xml', $head
            . '<workbook xmlns="' . self::NS_MAIN . '" xmlns:r="' . self::NS_REL . '">'
            . '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $z->addFromString('xl/_rels/workbook.xml.rels', $head
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');
        // Style 0: General. Style 1: "0.00" (built-in format 2), for amounts written with two decimals.
        $z->addFromString('xl/styles.xml', $head
            . '<styleSheet xmlns="' . self::NS_MAIN . '">'
            . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="2" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>');
        $z->addFromString('xl/worksheets/sheet1.xml', self::sheet_xml($rows));
        return $z->close();
    }

    /** The worksheet part for $rows. */
    public static function sheet_xml(array $rows): string
    {
        $out = ['<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<worksheet xmlns="' . self::NS_MAIN . '"><sheetData>'];
        $r = 0;
        foreach ($rows as $cells) {
            $r++;
            $line = '<row r="' . $r . '">';
            $c = 0;
            foreach ((array) $cells as $value) {
                $line .= self::cell(self::col($c) . $r, $value);
                $c++;
            }
            $out[] = $line . '</row>';
        }
        $out[] = '</sheetData></worksheet>';
        return implode('', $out);
    }

    /** One cell: a number when the text is a plain number, otherwise text ('' when the cell is empty). */
    public static function cell(string $ref, $value): string
    {
        $v = self::text($value);
        if ($v === '') return '';
        if (self::is_number($v)) {
            return '<c r="' . $ref . '"' . (preg_match('/\.\d\d$/', $v) ? ' s="1"' : '') . '><v>' . $v . '</v></c>';
        }
        return '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></is></c>';
    }

    /**
     * Written as a number: an optional minus, at most 10 digits before the point (no leading zero), at most
     * 10 after. Longer digit strings (phone numbers, ids) stay text so they are shown as typed.
     */
    public static function is_number(string $v): bool
    {
        return (bool) preg_match('/^-?(0|[1-9]\d{0,9})(\.\d{1,10})?$/', $v);
    }

    /**
     * The text of a cell as it goes into the sheet: valid UTF-8, no characters XML cannot hold, line breaks
     * as \n, at most 32,767 characters (Excel's limit). The apostrophes IQU_CSV::safe() puts before a
     * formula-like value (at the start, and after a ";" since 3.7.6) are taken off again: a text cell is never
     * run as a formula. Only marks safe() itself would have added are taken off.
     */
    public static function text($value): string
    {
        $v = is_scalar($value) ? (string) $value : '';
        $lead  = fn(string $s) => strlen($s) > 1 && $s[0] === "'" ? substr($s, 1) : $s;
        $inner = fn(string $s) => (string) preg_replace("/;'([=+\\-@\\t\\r])/", ';$1', $s);
        foreach ([$inner($lead($v)), $lead($v), $inner($v)] as $orig) {
            if ($orig !== $v && IQU_CSV::safe($orig) === $v) { $v = $orig; break; }
        }
        if (!preg_match('//u', $v)) $v = function_exists('mb_convert_encoding') ? mb_convert_encoding($v, 'UTF-8', 'UTF-8') : wp_check_invalid_utf8($v, true);
        $v = str_replace(["\r\n", "\r"], "\n", $v);
        $v = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]|\x{FFFE}|\x{FFFF}/u', '', $v);
        if (function_exists('mb_strlen') && mb_strlen($v, 'UTF-8') > 32767) $v = mb_substr($v, 0, 32767, 'UTF-8');
        return $v;
    }

    /** Column letters: 0 → A, 25 → Z, 26 → AA. */
    public static function col(int $i): string
    {
        $s = '';
        for ($n = $i + 1; $n > 0; $n = intdiv($n - 1, 26)) $s = chr(65 + ($n - 1) % 26) . $s;
        return $s;
    }

    // ------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------

    /**
     * The first sheet of the workbook at $path.
     *
     * @return array ['rows' => [row number => list of cell texts]] or ['error' => message]
     */
    public static function read(string $path, int $max_rows = 0): array
    {
        if (!self::available()) return ['error' => self::MSG_NO_ZIP];
        $z = new ZipArchive();
        if ($z->open($path) !== true) return ['error' => self::MSG_NOT_XLSX];
        try {
            [$sheet, $d1904] = self::first_sheet($z);
            if ($sheet === '') return ['error' => self::MSG_NOT_XLSX];
            $shared = self::shared_strings($z);
            $dates  = self::date_styles($z);
            if ($shared === null || $dates === null) return ['error' => self::MSG_NOT_XLSX];
            $rows = self::sheet_rows($z, $sheet, $shared, $dates, $d1904, $max_rows);
            return $rows === null ? ['error' => self::MSG_NOT_XLSX] : ['rows' => $rows];
        } finally {
            $z->close();
        }
    }

    /** One part of the zip as a string; '' when it is missing, null when it is too large. */
    private static function part(ZipArchive $z, string $name): ?string
    {
        $st = $z->statName($name);
        if ($st === false) return '';
        if ((int) $st['size'] > self::MAX_PART) return null;
        $s = $z->getFromName($name);
        return $s === false ? '' : $s;
    }

    private static function reader(string $xml): ?XMLReader
    {
        $r = new XMLReader();
        // No DTD loading, no entity substitution, no network: only the document itself is read.
        return @$r->XML($xml, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT) ? $r : null;
    }

    /** [path of the first sheet inside the zip, uses the 1904 date system]. */
    private static function first_sheet(ZipArchive $z): array
    {
        $wb = self::part($z, 'xl/workbook.xml');
        if ($wb === null || $wb === '' || !($r = self::reader($wb))) return ['', false];
        $rid = ''; $d1904 = false;
        while (@$r->read()) {
            if ($r->nodeType !== XMLReader::ELEMENT) continue;
            if ($r->localName === 'workbookPr') {
                $v = strtolower((string) $r->getAttribute('date1904'));
                $d1904 = $v === '1' || $v === 'true';
            } elseif ($r->localName === 'sheet' && $rid === '') {
                while ($r->moveToNextAttribute()) {
                    if ($r->localName === 'id' && $r->prefix !== '') { $rid = $r->value; break; }
                }
                $r->moveToElement();
            }
        }
        $r->close();
        $target = '';
        $rels = self::part($z, 'xl/_rels/workbook.xml.rels');
        if ($rid !== '' && $rels && ($r = self::reader($rels))) {
            while (@$r->read()) {
                if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'Relationship' && $r->getAttribute('Id') === $rid) {
                    $target = (string) $r->getAttribute('Target');
                    break;
                }
            }
            $r->close();
        }
        if ($target !== '') {
            $path = $target[0] === '/' ? ltrim($target, '/') : 'xl/' . $target;
            $path = preg_replace('#(^|/)\./#', '$1', $path);
            if ($z->statName($path) !== false) return [$path, $d1904];
        }
        return [$z->statName('xl/worksheets/sheet1.xml') !== false ? 'xl/worksheets/sheet1.xml' : '', $d1904];
    }

    /** The shared strings table (index → text); [] when the workbook has none; null when unreadable. */
    private static function shared_strings(ZipArchive $z): ?array
    {
        $xml = self::part($z, 'xl/sharedStrings.xml');
        if ($xml === null) return null;
        if ($xml === '') return [];
        if (!($r = self::reader($xml))) return null;
        $out = []; $buf = null; $skip = 0;
        while (@$r->read()) {
            if ($r->nodeType === XMLReader::ELEMENT) {
                if ($r->localName === 'si') {
                    if ($r->isEmptyElement) { $out[] = ''; continue; }
                    $buf = '';
                } elseif ($r->localName === 'rPh' && !$r->isEmptyElement) {
                    $skip++; // phonetic guide text is not part of the value
                } elseif ($r->localName === 't' && $buf !== null && $skip === 0) {
                    $buf .= $r->readString();
                }
            } elseif ($r->nodeType === XMLReader::END_ELEMENT) {
                if ($r->localName === 'rPh') $skip = max(0, $skip - 1);
                elseif ($r->localName === 'si' && $buf !== null) { $out[] = self::unescape($buf); $buf = null; }
            }
        }
        $r->close();
        return $out;
    }

    /** Which cell styles are dates or times: style index → 'date' | 'time' (missing = a plain number). Null when unreadable. */
    private static function date_styles(ZipArchive $z): ?array
    {
        $xml = self::part($z, 'xl/styles.xml');
        if ($xml === null) return null;
        if ($xml === '' || !($r = self::reader($xml))) return [];
        $custom = []; $out = []; $in = false; $i = 0;
        while (@$r->read()) {
            if ($r->nodeType === XMLReader::ELEMENT) {
                if ($r->localName === 'numFmt') {
                    $custom[(int) $r->getAttribute('numFmtId')] = (string) $r->getAttribute('formatCode');
                } elseif ($r->localName === 'cellXfs') {
                    $in = !$r->isEmptyElement;
                } elseif ($r->localName === 'xf' && $in) {
                    $kind = self::format_kind((int) $r->getAttribute('numFmtId'), $custom);
                    if ($kind !== '') $out[$i] = $kind;
                    $i++;
                }
            } elseif ($r->nodeType === XMLReader::END_ELEMENT && $r->localName === 'cellXfs') {
                $in = false;
            }
        }
        $r->close();
        return $out;
    }

    /** 'date', 'time' or '' for a number format id (built-in or custom). */
    private static function format_kind(int $id, array $custom): string
    {
        if (($id >= 14 && $id <= 17) || $id === 22 || ($id >= 27 && $id <= 36) || ($id >= 50 && $id <= 58)) return 'date';
        if (($id >= 18 && $id <= 21) || ($id >= 45 && $id <= 47)) return 'time';
        if (!isset($custom[$id])) return '';
        // Quoted text, escaped characters and [colour] / [$-409] parts are not date letters.
        $code = strtolower((string) preg_replace(['/"[^"]*"/', '/\\\\./', '/\[(?!h\]|m\]|s\])[^\]]*\]/i'], '', $custom[$id]));
        if (preg_match('/[dy]/', $code) || (preg_match('/m/', $code) && !preg_match('/[hs]/', $code))) return 'date';
        if (preg_match('/[hs]/', $code)) return 'time';
        return '';
    }

    /** Rows of the sheet part: row number → list of cell texts; null when unreadable. */
    private static function sheet_rows(ZipArchive $z, string $name, array $shared, array $dates, bool $d1904, int $max_rows): ?array
    {
        $xml = self::part($z, $name);
        if ($xml === null || $xml === '' || !($r = self::reader($xml))) return null;
        $rows = []; $cur = null; $rnum = 0; $next_col = 0; $filled = 0;
        while (@$r->read()) {
            if ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'row') {
                $rnum = max($rnum + 1, (int) $r->getAttribute('r'));
                $next_col = 0;
                if ($r->isEmptyElement) continue;
                $cur = [];
            } elseif ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'c' && $cur !== null) {
                $ref = (string) $r->getAttribute('r');
                $ci  = preg_match('/^([A-Z]{1,3})\d*$/i', $ref, $m) ? self::col_index($m[1]) : $next_col;
                $next_col = $ci + 1;
                $type  = (string) $r->getAttribute('t');
                $style = (int) $r->getAttribute('s');
                $v = ''; $is = '';
                if (!$r->isEmptyElement) {
                    $skip = 0;
                    while (@$r->read()) {
                        if ($r->nodeType === XMLReader::END_ELEMENT && $r->localName === 'c') break;
                        if ($r->nodeType === XMLReader::ELEMENT) {
                            if ($r->localName === 'v') $v = $r->readString();
                            elseif ($r->localName === 'rPh' && !$r->isEmptyElement) $skip++;
                            elseif ($r->localName === 't' && $skip === 0) $is .= $r->readString();
                        } elseif ($r->nodeType === XMLReader::END_ELEMENT && $r->localName === 'rPh') {
                            $skip = max(0, $skip - 1);
                        }
                    }
                }
                if ($ci < self::MAX_COLS) $cur[$ci] = self::value($type, $v, $is, $dates[$style] ?? '', $shared, $d1904);
            } elseif ($r->nodeType === XMLReader::END_ELEMENT && $r->localName === 'row' && $cur !== null) {
                $line = $cur ? array_replace(array_fill(0, max(array_keys($cur)) + 1, ''), $cur) : [];
                $cur = null;
                if (count(array_filter($line, fn($c) => trim($c) !== '')) === 0) continue;
                $rows[$rnum] = $line;
                // the header plus more than $max_rows: enough for the caller to say the file is too long
                if ($max_rows > 0 && ++$filled > $max_rows + 1) break;
            }
        }
        $r->close();
        ksort($rows);
        return $rows;
    }

    /** A cell's text from its type, raw value and style. */
    private static function value(string $type, string $v, string $is, string $kind, array $shared, bool $d1904): string
    {
        switch ($type) {
            case 's':         return (string) ($shared[(int) $v] ?? '');
            case 'inlineStr': return self::unescape($is);
            case 'str':
            case 'e':         return self::unescape($v);
            case 'b':         return $v === '1' ? 'TRUE' : 'FALSE';
            case 'd':         return preg_replace('/T00:00(:00(\.0+)?)?Z?$/', '', $v);
        }
        if ($v === '' || !is_numeric($v)) return $v;
        if ($kind !== '') return self::serial($v, $kind, $d1904);
        if (preg_match('/^-?\d+$/', $v)) return $v;
        $f = (float) $v;
        return abs($f) < 1e15 && floor($f) == $f ? sprintf('%.0f', $f) : (string) preg_replace('/\.?0+(e|$)/i', '$1', sprintf('%.15G', $f));
    }

    /** A date serial as YYYY-MM-DD (HH:MM when it has a time of day), or a time as HH:MM. */
    private static function serial(string $v, string $kind, bool $d1904): string
    {
        $n = (float) $v;
        $days = (int) floor($n);
        $secs = (int) round(($n - $days) * 86400);
        if ($secs >= 86400) { $days++; $secs -= 86400; }
        $time = sprintf('%02d:%02d', intdiv($secs, 3600), intdiv($secs % 3600, 60)) . ($secs % 60 ? sprintf(':%02d', $secs % 60) : '');
        if ($kind === 'time') return $time;
        // 1900 system: serial 1 = 1 January 1900, and Excel counts a 29 February 1900 that never was.
        if (!$d1904 && $days < 60) $days++;
        $d = (new DateTimeImmutable($d1904 ? '1904-01-01' : '1899-12-30', new DateTimeZone('UTC')))->modify(($days >= 0 ? '+' : '') . $days . ' days');
        return $d->format('Y-m-d') . ($secs ? ' ' . $time : '');
    }

    /** "AB" → 27 (0-based). */
    private static function col_index(string $letters): int
    {
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $ch) $n = $n * 26 + (ord($ch) - 64);
        return $n - 1;
    }

    /** Excel writes characters XML cannot hold as _xHHHH_ (and a literal "_x" as _x005F_x). */
    private static function unescape(string $s): string
    {
        if (strpos($s, '_x') === false) return $s;
        return (string) preg_replace_callback('/_x([0-9A-Fa-f]{4})_/', function ($m) {
            $cp = hexdec($m[1]);
            return function_exists('mb_chr') ? (string) mb_chr($cp, 'UTF-8') : html_entity_decode('&#' . $cp . ';', ENT_QUOTES, 'UTF-8');
        }, $s);
    }
}
