<?php

namespace App\Services\Reports;

/**
 * Minimal, dependency-free XLSX writer (Office Open XML with inline strings, STORE-compressed zip).
 * Enough for institutional tabular exports: multiple sheets, header row, numeric/text cells.
 * No ext-zip requirement — the container format is written by hand.
 */
class XlsxWriter
{
    /** @var array<int, array{name:string, rows:array<int, array<int, mixed>>}> */
    protected array $sheets = [];

    /** @param array<int, array<int, mixed>> $rows first row is treated as the header */
    public function addSheet(string $name, array $rows): void
    {
        $this->sheets[] = ['name' => $this->uniqueSheetName($name), 'rows' => $rows];
    }

    public function toString(): string
    {
        if ($this->sheets === []) {
            $this->addSheet('Sheet1', [['No data']]);
        }
        $files = [
            '[Content_Types].xml' => $this->contentTypes(),
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => $this->workbook(),
            'xl/_rels/workbook.xml.rels' => $this->workbookRels(),
            'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
        ];
        foreach ($this->sheets as $i => $sheet) {
            $files['xl/worksheets/sheet'.($i + 1).'.xml'] = $this->worksheet($sheet['rows']);
        }

        return $this->zip($files);
    }

    protected function contentTypes(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        foreach ($this->sheets as $i => $s) {
            $xml .= '<Override PartName="/xl/worksheets/sheet'.($i + 1).'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return $xml.'</Types>';
    }

    protected function workbook(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        foreach ($this->sheets as $i => $s) {
            $xml .= '<sheet name="'.$this->esc($s['name']).'" sheetId="'.($i + 1).'" r:id="rId'.($i + 1).'"/>';
        }

        return $xml.'</sheets></workbook>';
    }

    protected function workbookRels(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($this->sheets as $i => $s) {
            $xml .= '<Relationship Id="rId'.($i + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.($i + 1).'.xml"/>';
        }
        $xml .= '<Relationship Id="rId'.(count($this->sheets) + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        return $xml.'</Relationships>';
    }

    protected function worksheet(array $rows): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach (array_values($rows) as $r => $row) {
            $xml .= '<row r="'.($r + 1).'">';
            foreach (array_values($row) as $c => $value) {
                $ref = $this->col($c).($r + 1);
                $style = $r === 0 ? ' s="1"' : '';
                if ($value === null || $value === '') {
                    continue;
                }
                if (is_bool($value)) {
                    $value = $value ? 'Yes' : 'No';
                }
                if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value) && ! preg_match('/^0\d/', $value) && strlen($value) < 16)) {
                    $xml .= '<c r="'.$ref.'"'.$style.'><v>'.(0 + $value).'</v></c>';
                } else {
                    $xml .= '<c r="'.$ref.'" t="inlineStr"'.$style.'><is><t xml:space="preserve">'.$this->esc((string) $value).'</t></is></c>';
                }
            }
            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    protected function col(int $index): string
    {
        $s = '';
        $index++;
        while ($index > 0) {
            $m = ($index - 1) % 26;
            $s = chr(65 + $m).$s;
            $index = intdiv($index - 1, 26);
        }

        return $s;
    }

    protected function esc(string $v): string
    {
        // Strip control characters XML 1.0 forbids
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $v) ?? $v;

        return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    protected function uniqueSheetName(string $name): string
    {
        $base = mb_substr(preg_replace('/[\[\]\*\?\/\\\\:]/', '-', $name) ?: 'Sheet', 0, 28) ?: 'Sheet';
        $candidate = $base;
        $i = 2;
        $taken = array_map(fn ($s) => strtolower($s['name']), $this->sheets);
        while (in_array(strtolower($candidate), $taken, true)) {
            $candidate = mb_substr($base, 0, 28).' '.$i++;
        }

        return $candidate;
    }

    /** @param array<string, string> $files path => content */
    protected function zip(array $files): string
    {
        $local = '';
        $central = '';
        $offset = 0;
        $dosTime = $this->dosTime();
        foreach ($files as $name => $content) {
            $crc = crc32($content);
            $size = strlen($content);
            $header = "\x50\x4b\x03\x04".pack('vvvvvVVVvv', 20, 0x0800, 0, $dosTime['time'], $dosTime['date'], $crc, $size, $size, strlen($name), 0).$name;
            $local .= $header.$content;
            $central .= "\x50\x4b\x01\x02".pack('vvvvvvVVVvvvvvVV', 20, 20, 0x0800, 0, $dosTime['time'], $dosTime['date'], $crc, $size, $size, strlen($name), 0, 0, 0, 0, 0, $offset).$name;
            $offset += strlen($header) + $size;
        }
        $end = "\x50\x4b\x05\x06".pack('vvvvVVv', 0, 0, count($files), count($files), strlen($central), $offset, 0);

        return $local.$central.$end;
    }

    protected function dosTime(): array
    {
        $t = getdate();

        return ['time' => ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2), 'date' => (($t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday']];
    }
}
