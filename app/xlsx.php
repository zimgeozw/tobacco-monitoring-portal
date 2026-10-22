<?php
/**
 * Minimal streaming XLSX writer (no dependencies; needs the PHP zip extension).
 * Rows are written to temp files so large exports (100k+ rows) use little memory.
 * Sheets get a frozen, bold header row and Excel AutoFilter dropdowns.
 */
declare(strict_types=1);

final class XlsxWriter
{
    private array $sheets = [];
    private string $tmpDir;
    public string $creator = 'Zimgeo';

    public function __construct()
    {
        $this->tmpDir = STORAGE . '/cache/xlsx_' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0755, true);
    }

    /** @param array $cols [[header, width, type: 's'|'n'|'n2'|'n3'|'link'], ...] */
    public function addSheet(string $name, array $cols, bool $autofilter = true): int
    {
        $i = count($this->sheets);
        $fh = fopen($this->tmpDir . "/rows$i.xml", 'wb');
        $this->sheets[] = ['name' => mb_substr(preg_replace('#[\\\\/?*\[\]:]#', ' ', $name), 0, 31), 'cols' => $cols, 'fh' => $fh, 'rows' => 0, 'af' => $autofilter];
        $this->writeRow($i, array_column($cols, 0), true);
        return $i;
    }

    public function addRow(int $sheet, array $values): void
    {
        $this->writeRow($sheet, $values, false);
    }

    private static function colName(int $n): string
    {
        $s = '';
        for ($n++; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + ($n - 1) % 26) . $s;
        }
        return $s;
    }

    private static function x(string $s): string
    {
        $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $s) ?? '';
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function writeRow(int $si, array $values, bool $header): void
    {
        $sh = &$this->sheets[$si];
        $r = ++$sh['rows'];
        $xml = '<row r="' . $r . '">';
        foreach (array_values($values) as $c => $v) {
            $ref = self::colName($c) . $r;
            $type = $sh['cols'][$c][2] ?? 's';
            if ($header) {
                $xml .= '<c r="' . $ref . '" t="inlineStr" s="1"><is><t>' . self::x((string)$v) . '</t></is></c>';
            } elseif ($v === null || $v === '') {
                continue;
            } elseif ($type[0] === 'n' && is_numeric($v)) {
                $style = ['n' => 0, 'n2' => 2, 'n3' => 3, 'n0' => 4][$type] ?? 0;
                $xml .= '<c r="' . $ref . '"' . ($style ? ' s="' . $style . '"' : '') . '><v>' . (0 + $v) . '</v></c>';
            } elseif ($type === 'link') {
                $xml .= '<c r="' . $ref . '" t="str" s="5"><f>HYPERLINK("' . self::x(str_replace('"', '', (string)$v)) . '","Open map")</f></c>';
            } else {
                $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . self::x((string)$v) . '</t></is></c>';
            }
        }
        fwrite($sh['fh'], $xml . '</row>');
    }

    public function save(string $path): void
    {
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $n = count($this->sheets);
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
        $wbSheets = '';
        $wbRels = '';
        for ($i = 0; $i < $n; $i++) {
            $ct .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $wbSheets .= '<sheet name="' . self::x($this->sheets[$i]['name']) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
            $wbRels .= '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
        }
        $zip->addFromString('[Content_Types].xml', $ct . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>');
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>Tobacco Crop Monitoring Export</dc:title><dc:creator>' . self::x($this->creator) . '</dc:creator><cp:lastModifiedBy>Zimgeo</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified></cp:coreProperties>');
        $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>Zimgeo Tobacco Monitoring Portal</Application><Company>Zimgeo</Company></Properties>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $wbSheets . '</sheets>' . $this->definedNames() . '</workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $wbRels
            . '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="0.000"/></numFmts>'
            . '<fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><u/><sz val="11"/><color rgb="FF0563C1"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF14532D"/><bgColor indexed="64"/></patternFill></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="6"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');

        for ($i = 0; $i < $n; $i++) {
            $sh = $this->sheets[$i];
            fclose($sh['fh']);
            $lastCol = self::colName(count($sh['cols']) - 1);
            $cols = '<cols>';
            foreach ($sh['cols'] as $c => $def) {
                $cols .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . ($def[1] ?? 14) . '" customWidth="1"/>';
            }
            $head = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<dimension ref="A1:' . $lastCol . $sh['rows'] . '"/>'
                . '<sheetViews><sheetView workbookViewId="0"' . ($i === 0 ? ' tabSelected="1"' : '') . '><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
                . '<sheetFormatPr defaultRowHeight="15"/>' . $cols . '</cols><sheetData>';
            $tail = '</sheetData>' . ($sh['af'] && $sh['rows'] > 1 ? '<autoFilter ref="A1:' . $lastCol . $sh['rows'] . '"/>' : '') . '</worksheet>';
            $full = $this->tmpDir . "/sheet$i.xml";
            $out = fopen($full, 'wb');
            fwrite($out, $head);
            $in = fopen($this->tmpDir . "/rows$i.xml", 'rb');
            stream_copy_to_stream($in, $out);
            fclose($in);
            fwrite($out, $tail);
            fclose($out);
            $zip->addFile($full, 'xl/worksheets/sheet' . ($i + 1) . '.xml');
        }
        $zip->close();
    }

    private function definedNames(): string
    {
        $x = '';
        foreach ($this->sheets as $i => $sh) {
            if ($sh['af'] && $sh['rows'] > 1) {
                $x .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">\'' . self::x(str_replace("'", "''", $sh['name'])) . '\'!$A$1:$' . self::colName(count($sh['cols']) - 1) . '$' . $sh['rows'] . '</definedName>';
            }
        }
        return $x ? "<definedNames>$x</definedNames>" : '';
    }

    public function cleanup(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }
}
