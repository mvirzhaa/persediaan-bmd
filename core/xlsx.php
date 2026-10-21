<?php
/**
 * Pembaca & penulis XLSX minimalis (PHP native: ZipArchive + XML).
 * Tidak membutuhkan Composer/PhpSpreadsheet.
 */

function xlsx_col($n)
{
    $s = '';
    while ($n > 0) { $m = ($n - 1) % 26; $s = chr(65 + $m) . $s; $n = intdiv($n - 1, 26); }
    return $s;
}

function xlsx_col_index($letters)
{
    $n = 0;
    foreach (str_split(strtoupper($letters)) as $c) $n = $n * 26 + (ord($c) - 64);
    return $n;
}

/**
 * Baca sheet pertama (atau sheet bernama $sheetName) menjadi array [baris => [kolom => nilai]] (1-based).
 */
function xlsx_read($path, $sheetName = null)
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('Ekstensi PHP zip belum aktif. Aktifkan "extension=zip" di php.ini.');
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('File bukan XLSX yang valid.');
    $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $rns = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    $shared = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $ss = simplexml_load_string($ssXml);
        foreach ($ss->children($ns)->si as $si) {
            $txt = '';
            $c = $si->children($ns);
            if (isset($c->t)) $txt = (string)$c->t;
            foreach ($c->r as $r) $txt .= (string)$r->children($ns)->t;
            $shared[] = $txt;
        }
    }
    $wb = simplexml_load_string($zip->getFromName('xl/workbook.xml'));
    $rels = simplexml_load_string($zip->getFromName('xl/_rels/workbook.xml.rels'));
    $relMap = [];
    foreach ($rels->Relationship as $r) $relMap[(string)$r->attributes()['Id']] = (string)$r->attributes()['Target'];
    $target = null;
    foreach ($wb->children($ns)->sheets->sheet as $sh) {
        $rid = (string)$sh->attributes($rns)['id'];
        if ($sheetName === null || strcasecmp((string)$sh->attributes()['name'], $sheetName) === 0) { $target = $relMap[$rid] ?? null; break; }
    }
    if (!$target) throw new RuntimeException('Sheet tidak ditemukan.');
    $target = ltrim($target, '/');
    if (strpos($target, 'xl/') !== 0) $target = 'xl/' . $target;
    $xml = $zip->getFromName($target);
    $zip->close();
    if ($xml === false) throw new RuntimeException('Isi sheet tidak dapat dibaca.');

    $sx = simplexml_load_string($xml);
    $out = [];
    foreach ($sx->children($ns)->sheetData->row as $rowEl) {
        $ri = (int)$rowEl->attributes()['r'];
        foreach ($rowEl->children($ns)->c as $c) {
            $ca = $c->attributes();
            $ref = (string)$ca['r'];
            if (!preg_match('/^([A-Z]+)(\d+)$/', $ref, $m)) continue;
            $ci = xlsx_col_index($m[1]);
            $t = (string)$ca['t'];
            $ch = $c->children($ns);
            if ($t === 's') $v = $shared[(int)$ch->v] ?? '';
            elseif ($t === 'inlineStr') {
                if (!isset($ch->is)) continue;
                $v = (string)$ch->is->children($ns)->t;
                if ($v === '') foreach ($ch->is->children($ns)->r as $r) $v .= (string)$r->children($ns)->t;
            }
            elseif ($t === 'b') $v = ((string)$ch->v) === '1';
            elseif ($t === 'str' || $t === 'e') $v = (string)$ch->v;
            else { $raw = (string)$ch->v; $v = $raw === '' ? null : (is_numeric($raw) ? $raw + 0 : $raw); }
            if ($v !== null && $v !== '') $out[$ri][$ci] = $v;
        }
    }
    return $out;
}

/**
 * Penulis XLSX sederhana dengan gaya bawaan.
 * Gaya (index):
 *  0 normal, 1 tebal, 2 header tabel, 3 teks ber-border, 4 rupiah ber-border, 5 rupiah tebal ber-border,
 *  6 judul besar, 7 teks tebal ber-border, 8 angka umum ber-border, 9 sub judul tengah tebal,
 *  10 teks wrap ber-border, 11 catatan miring, 12 teks tebal ber-border berlatar, 13 rupiah tebal berlatar,
 *  14 teks tengah ber-border, 15 normal tengah, 16 angka umum tebal berlatar
 */
class XlsxWriter
{
    private $sheets = [];
    private $cur = -1;

    public function addSheet($name)
    {
        $name = preg_replace('/[\[\]\*\?\/\\\\:]/', '-', $name);
        $name = mb_substr($name, 0, 31);
        $this->sheets[] = ['name' => $name, 'rows' => [], 'merges' => [], 'cols' => [], 'validations' => [], 'landscape' => false, 'freeze' => null];
        $this->cur = count($this->sheets) - 1;
        return $this;
    }

    /** Set sel. $col & $row 1-based */
    public function set($row, $col, $value, $style = 0)
    {
        $this->sheets[$this->cur]['rows'][$row][$col] = [$value, $style];
        return $this;
    }

    public function row($row, array $values, $style = 0, $startCol = 1)
    {
        foreach ($values as $i => $v) {
            if (is_array($v)) $this->set($row, $startCol + $i, $v[0], $v[1]);
            else $this->set($row, $startCol + $i, $v, $style);
        }
        return $this;
    }

    public function merge($r1, $c1, $r2, $c2)
    {
        $this->sheets[$this->cur]['merges'][] = xlsx_col($c1) . $r1 . ':' . xlsx_col($c2) . $r2;
        return $this;
    }

    public function width($col, $w)
    {
        $this->sheets[$this->cur]['cols'][$col] = $w;
        return $this;
    }

    public function landscape($on = true)
    {
        $this->sheets[$this->cur]['landscape'] = $on;
        return $this;
    }

    public function freeze($row, $col = 0)
    {
        $this->sheets[$this->cur]['freeze'] = [$row, $col];
        return $this;
    }

    /** Validasi daftar (dropdown) */
    public function listValidation($sqref, $formula)
    {
        $this->sheets[$this->cur]['validations'][] = [$sqref, $formula];
        return $this;
    }

    private static function x($s)
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$s);
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function sheetXml($sh)
    {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $x .= '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>';
        if ($sh['freeze']) {
            list($fr, $fc) = $sh['freeze'];
            $cell = xlsx_col($fc + 1) . ($fr + 1);
            $x .= '<sheetViews><sheetView workbookViewId="0"><pane ' . ($fc ? 'xSplit="' . $fc . '" ' : '') . 'ySplit="' . $fr . '" topLeftCell="' . $cell . '" activePane="bottomRight" state="frozen"/></sheetView></sheetViews>';
        }
        if ($sh['cols']) {
            ksort($sh['cols']);
            $x .= '<cols>';
            foreach ($sh['cols'] as $c => $w) $x .= '<col min="' . $c . '" max="' . $c . '" width="' . $w . '" customWidth="1"/>';
            $x .= '</cols>';
        }
        $x .= '<sheetData>';
        ksort($sh['rows']);
        foreach ($sh['rows'] as $r => $cells) {
            ksort($cells);
            $x .= '<row r="' . $r . '">';
            foreach ($cells as $c => $cell) {
                list($v, $s) = $cell;
                $ref = xlsx_col($c) . $r;
                if ($v === null || $v === '') {
                    $x .= '<c r="' . $ref . '" s="' . $s . '"/>';
                } elseif (is_int($v) || is_float($v)) {
                    $x .= '<c r="' . $ref . '" s="' . $s . '"><v>' . (is_float($v) ? rtrim(rtrim(sprintf('%.6F', $v), '0'), '.') : $v) . '</v></c>';
                } elseif (is_string($v) && strpos($v, '=') === 0) {
                    $x .= '<c r="' . $ref . '" s="' . $s . '"><f>' . self::x(substr($v, 1)) . '</f></c>';
                } else {
                    $x .= '<c r="' . $ref . '" s="' . $s . '" t="inlineStr"><is><t xml:space="preserve">' . self::x($v) . '</t></is></c>';
                }
            }
            $x .= '</row>';
        }
        $x .= '</sheetData>';
        if ($sh['merges']) {
            $x .= '<mergeCells count="' . count($sh['merges']) . '">';
            foreach ($sh['merges'] as $m) $x .= '<mergeCell ref="' . $m . '"/>';
            $x .= '</mergeCells>';
        }
        if ($sh['validations']) {
            $x .= '<dataValidations count="' . count($sh['validations']) . '">';
            foreach ($sh['validations'] as $v) {
                $x .= '<dataValidation type="list" allowBlank="1" showInputMessage="1" showErrorMessage="1" sqref="' . $v[0] . '"><formula1>' . self::x($v[1]) . '</formula1></dataValidation>';
            }
            $x .= '</dataValidations>';
        }
        $x .= '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>';
        $x .= '<pageSetup paperSize="9" orientation="' . ($sh['landscape'] ? 'landscape' : 'portrait') . '" fitToWidth="1" fitToHeight="0"/>';
        $x .= '</worksheet>';
        return $x;
    }

    private function stylesXml()
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00;-#,##0.00;&quot;-&quot;"/></numFmts>'
            . '<fonts count="5">'
            . '<font><sz val="10"/><name val="Arial"/></font>'
            . '<font><b/><sz val="10"/><name val="Arial"/></font>'
            . '<font><b/><sz val="13"/><name val="Arial"/></font>'
            . '<font><i/><sz val="9"/><color rgb="FF666666"/><name val="Arial"/></font>'
            . '<font><b/><sz val="11"/><name val="Arial"/></font>'
            . '</fonts>'
            . '<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE8EEF6"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF5F7FA"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color rgb="FF999999"/></left><right style="thin"><color rgb="FF999999"/></right><top style="thin"><color rgb="FF999999"/></top><bottom style="thin"><color rgb="FF999999"/></bottom><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="17">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' // 0
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>' // 1
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>' // 2
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="top"/></xf>' // 3
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment vertical="top"/></xf>' // 4
            . '<xf numFmtId="164" fontId="1" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyBorder="1"/>' // 5
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center"/></xf>' // 6
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>' // 7
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="top"/></xf>' // 8
            . '<xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center"/></xf>' // 9
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>' // 10
            . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>' // 11
            . '<xf numFmtId="0" fontId="1" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>' // 12
            . '<xf numFmtId="164" fontId="1" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"/>' // 13
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="top"/></xf>' // 14
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="center"/></xf>' // 15
            . '<xf numFmtId="0" fontId="1" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right"/></xf>' // 16
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    public function save($path)
    {
        if (!class_exists('ZipArchive')) throw new RuntimeException('Ekstensi PHP zip belum aktif. Aktifkan "extension=zip" di php.ini.');
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Tidak dapat membuat file XLSX.');
        $n = count($this->sheets);
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
        for ($i = 1; $i <= $n; $i++) $ct .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $ct .= '</Types>';
        $zip->addFromString('[Content_Types].xml', $ct);
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>');
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:creator>' . self::x(APP_NAME) . '</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created></cp:coreProperties>');
        $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>' . self::x(APP_NAME) . '</Application></Properties>');
        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($this->sheets as $i => $sh) {
            $k = $i + 1;
            $wb .= '<sheet name="' . self::x($sh['name']) . '" sheetId="' . $k . '" r:id="rId' . $k . '"/>';
            $rels .= '<Relationship Id="rId' . $k . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $k . '.xml"/>';
            $zip->addFromString('xl/worksheets/sheet' . $k . '.xml', $this->sheetXml($sh));
        }
        $wb .= '</sheets></workbook>';
        $rels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $zip->addFromString('xl/workbook.xml', $wb);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        $zip->close();
    }

    /** Kirim ke browser sebagai unduhan */
    public function download($filename)
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $this->save($tmp);
        if (ob_get_length()) ob_end_clean();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: max-age=0');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }
}
