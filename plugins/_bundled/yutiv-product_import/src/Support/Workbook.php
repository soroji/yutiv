<?php

namespace Plugins\Yutiv\ProductImport\Support;

use DOMDocument;
use DOMXPath;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/** Bounded OOXML table codec. No formula engine, external relations or ZIP extraction. */
final class Workbook
{
    public const PRODUCT = ['관리코드', '상품명', '카테고리 코드', '판매가', '재고', '상세설명', '대표 이미지 URL', '추가 이미지 URL'];

    public const OPTION = ['상품 관리코드', '옵션 식별자', '옵션명1', '옵션값1', '옵션명2', '옵션값2', '옵션명3', '옵션값3', '추가금액', '재고'];

    public const MAX_PRODUCTS = 500;

    public const MAX_OPTIONS = 2000;

    public static function reject(string $message): never
    {
        throw ValidationException::withMessages(['file' => __($message)]);
    }

    private function xml(string $text): DOMDocument
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $text)) {
            self::reject('외부 엔티티가 있는 엑셀은 사용할 수 없습니다.');
        }
        $d = new DOMDocument;
        $old = libxml_use_internal_errors(true);
        try {
            $ok = $d->loadXML($text, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }
        if (! $ok) {
            self::reject('손상된 엑셀입니다. 양식을 다시 다운로드하세요.');
        }

        return $d;
    }

    public function read(string $path): array
    {
        if (filesize($path) > 10 * 1024 * 1024) {
            self::reject('파일은 10MB 이하로 올려주세요.');
        }
        $z = new ZipArchive;
        if ($z->open($path, ZipArchive::CHECKCONS) !== true) {
            self::reject('정상적인 .xlsx 파일을 올려주세요.');
        }
        try {
            if ($z->numFiles > 1000) {
                self::reject('엑셀 내부 파일 수 제한을 초과했습니다.');
            }
            $size = 0;
            $names = [];
            for ($i = 0; $i < $z->numFiles; $i++) {
                $s = $z->statIndex($i);
                $name = $s['name'];
                $size += $s['size'];
                if (isset($names[$name]) || str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\')) {
                    self::reject('잘못된 엑셀 압축 경로입니다.');
                }
                $names[$name] = true;
                if ($size > 50 * 1024 * 1024 || $s['size'] > 16 * 1024 * 1024) {
                    self::reject('압축 해제 크기 제한(전체 50MB, 파일 16MB)을 초과했습니다.');
                }
                if (preg_match('/vba|activex|externalLinks|embeddings/i', $name)) {
                    self::reject('매크로·외부 연결·내장 파일이 있는 엑셀은 사용할 수 없습니다.');
                }
            }
            $get = function (string $name) use ($z) {
                $s = $z->getFromName($name, 16 * 1024 * 1024 + 1);
                if ($s === false) {
                    self::reject('필수 엑셀 구성 파일이 없습니다.');
                }
                if (strlen($s) > 16 * 1024 * 1024) {
                    self::reject('엑셀 구성 파일 크기 제한을 초과했습니다.');
                }

                return $s;
            };
            $types = $get('[Content_Types].xml');
            $this->xml($types);
            if (! str_contains($types, 'spreadsheetml.sheet.main+xml') || preg_match('/macroEnabled|vbaProject/i', $types)) {
                self::reject('매크로 없는 .xlsx만 지원합니다.');
            }
            $wb = new DOMXPath($this->xml($get('xl/workbook.xml')));
            $rel = new DOMXPath($this->xml($get('xl/_rels/workbook.xml.rels')));
            $targets = [];
            foreach ($rel->query('//*[local-name()="Relationship"]') as $r) {
                if ($r->getAttribute('TargetMode') === 'External') {
                    self::reject('외부 연결을 제거하세요.');
                }
                $targets[$r->getAttribute('Id')] = $r->getAttribute('Target');
            }
            $strings = [];
            if ($z->locateName('xl/sharedStrings.xml') !== false) {
                $xp = new DOMXPath($this->xml($get('xl/sharedStrings.xml')));
                foreach ($xp->query('//*[local-name()="si"]') as $si) {
                    $text = '';
                    foreach ($xp->query('.//*[local-name()="t"]', $si) as $t) {
                        $text .= $t->textContent;
                    }
                    if (strlen($text) > 65535 || count($strings) > 50000) {
                        self::reject('셀 데이터 크기 제한을 초과했습니다.');
                    }
                    $strings[] = $text;
                }
            }
            $out = [];
            $formulaErrors = [];
            foreach ($wb->query('//*[local-name()="sheet"]') as $sheet) {
                $name = $sheet->getAttribute('name');
                if (! in_array($name, ['상품', '옵션'], true)) {
                    continue;
                }
                if (isset($out[$name])) {
                    self::reject('입력 시트 이름이 중복되었습니다.');
                }
                $id = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
                $target = $targets[$id] ?? '';
                $target = str_starts_with($target, '/xl/') ? substr($target, 1) : 'xl/'.$target;
                if (! preg_match('~^xl/worksheets/[a-zA-Z0-9_.-]+\.xml$~', $target)) {
                    self::reject('지원하지 않는 시트 경로입니다.');
                }
                $xp = new DOMXPath($this->xml($get($target)));
                $rows = [];
                $limit = $name === '상품' ? self::MAX_PRODUCTS : self::MAX_OPTIONS;
                foreach ($xp->query('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
                    $rn = (int) $row->getAttribute('r');
                    if ($rn < 1 || $rn > $limit + 1 || isset($rows[$rn])) {
                        self::reject('행 수 제한을 초과했거나 행 번호가 잘못되었습니다.');
                    }
                    $cells = [];
                    foreach ($xp->query('./*[local-name()="c"]', $row) as $c) {
                        if (! preg_match('/^([A-Z]{1,2})([0-9]+)$/', $c->getAttribute('r'), $m) || (int) $m[2] !== $rn) {
                            self::reject('셀 주소가 잘못되었습니다.');
                        }
                        $cn = 0;
                        foreach (str_split($m[1]) as $letter) {
                            $cn = $cn * 26 + ord($letter) - 64;
                        }
                        if ($cn > count($name === '상품' ? self::PRODUCT : self::OPTION)) {
                            self::reject('양식에 없는 입력 열을 제거하세요.');
                        }
                        $f = $xp->query('./*[local-name()="f"]', $c)->length;
                        if ($f) {
                            $formulaErrors[] = ['sheet' => $name, 'row' => $rn, 'column' => ($name === '상품' ? self::PRODUCT : self::OPTION)[$cn - 1], 'message' => '수식은 허용되지 않습니다.', 'fix' => '수식을 제거하고 값을 직접 입력하세요.'];
                        }
                        $v = $xp->query('./*[local-name()="v"]', $c)->item(0)?->textContent ?? '';
                        $type = $c->getAttribute('t');
                        $identifierColumns = $name === '상품' ? [1, 3] : [1, 2];
                        if ($rn > 1 && in_array($cn, $identifierColumns, true) && $v !== '' && ! in_array($type, ['s', 'inlineStr'], true)) {
                            $formulaErrors[] = ['sheet' => $name, 'row' => $rn, 'column' => ($name === '상품' ? self::PRODUCT : self::OPTION)[$cn - 1], 'message' => '코드·식별자는 문자열로 입력해야 합니다.', 'fix' => '셀 서식을 텍스트로 바꾼 후 앞자리 0을 포함해 다시 입력하세요.'];
                        }
                        if ($type === 's') {
                            if (! ctype_digit($v) || ! array_key_exists((int) $v, $strings)) {
                                self::reject('문자열 셀이 손상되었습니다.');
                            } $v = $strings[(int) $v];
                        } elseif ($type === 'inlineStr') {
                            $v = '';
                            foreach ($xp->query('./*[local-name()="is"]//*[local-name()="t"]', $c) as $t) {
                                $v .= $t->textContent;
                            }
                        } elseif (in_array($type, ['e', 'b', 'd'], true)) {
                            self::reject('입력 셀은 문자열 또는 숫자로 입력하세요.');
                        }
                        if (strlen($v) > 65535 || isset($cells[$cn - 1])) {
                            self::reject('셀 데이터 크기 또는 중복 제한을 초과했습니다.');
                        }
                        $cells[$cn - 1] = trim($v);
                    }
                    $rows[$rn] = $cells;
                }
                $headers = $name === '상품' ? self::PRODUCT : self::OPTION;
                if (array_values($rows[1] ?? []) !== $headers) {
                    self::reject('상품·옵션 시트의 열 이름과 순서를 양식대로 유지하세요.');
                }
                unset($rows[1]);
                $out[$name] = array_filter($rows, fn ($r) => count(array_filter($r, fn ($v) => $v !== '')) > 0);
            }
            if (! isset($out['상품'],$out['옵션'])) {
                self::reject('상품·옵션 시트가 필요합니다.');
            }

            return $out + ['formula_errors' => $formulaErrors];
        } finally {
            $z->close();
        }
    }

    /** Every cell is an explicit inline string: preserves zero prefixes and never executes input. */
    public function write(array $sheets): string
    {
        $path = tempnam(sys_get_temp_dir(), 'yutiv-xlsx-');
        $z = new ZipArchive;
        if ($z->open($path, ZipArchive::OVERWRITE) !== true) {
            self::reject('결과 파일을 만들 수 없습니다.');
        }
        $esc = fn ($s) => htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $types = '';
        $wb = '';
        $rels = '';
        $i = 0;
        try {
            foreach ($sheets as $name => $rows) {
                $i++;
                $xml = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols><col min="1" max="32" style="1" width="24" customWidth="1"/></cols><sheetData>';
                foreach ($rows as $rn => $row) {
                    $number = $rn + 1;
                    $xml .= '<row r="'.$number.'">';
                    foreach (array_values($row) as $cn => $value) {
                        $col = '';
                        $n = $cn + 1;
                        while ($n) {
                            $n--;
                            $col = chr(65 + $n % 26).$col;
                            $n = intdiv($n, 26);
                        }
                        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) $value);
                        $xml .= '<c r="'.$col.$number.'" s="1" t="inlineStr"><is><t xml:space="preserve">'.$esc($value).'</t></is></c>';
                    }
                    $xml .= '</row>';
                }
                $z->addFromString('xl/worksheets/sheet'.$i.'.xml', $xml.'</sheetData></worksheet>');
                $types .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
                $wb .= '<sheet name="'.$esc($name).'" sheetId="'.$i.'" r:id="rId'.$i.'"/>';
                $rels .= '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
            }
            $z->addFromString('xl/styles.xml', '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
            $rels .= '<Relationship Id="styles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
            $z->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'.$types.'</Types>');
            $z->addFromString('_rels/.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            $z->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$wb.'</sheets></workbook>');
            $z->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>');
            $z->close();
            $bytes = file_get_contents($path);

            return $bytes;
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
