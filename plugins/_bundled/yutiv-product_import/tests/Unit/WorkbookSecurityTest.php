<?php

namespace Plugins\Yutiv\ProductImport\Tests\Unit;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;
use Plugins\Yutiv\ProductImport\Support\Workbook;
use Tests\TestCase;
use ZipArchive;

class WorkbookSecurityTest extends TestCase
{
    protected array $requiredExtensions = ['sirsoft-ecommerce', 'yutiv-product_import'];

    public function createApplication()
    {
        $app = require dirname(__DIR__, 5).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    private function file(): string
    {
        $p = tempnam(sys_get_temp_dir(), 'workbook-test-');
        file_put_contents($p, (new Workbook)->write(['상품' => [Workbook::PRODUCT, ['0001', '상품', '01', '100', '1', '', '', '']], '옵션' => [Workbook::OPTION]]));

        return $p;
    }

    private function mutation(callable $edit): string
    {
        $p = $this->file();
        $z = new ZipArchive;
        $z->open($p);
        $edit($z);
        $z->close();

        return $p;
    }

    private function rejected(string $path): void
    {
        try {
            (new Workbook)->read($path);
            $this->fail('Unsafe workbook was accepted');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        } finally {
            unlink($path);
        }
    }

    public function test_code_strings_and_formula_like_literals_roundtrip_without_evaluation(): void
    {
        $p = $this->file();
        try {
            $r = (new Workbook)->read($p);
            $this->assertSame('0001', $r['상품'][2][0]);
            $this->assertSame('01', $r['상품'][2][2]);
            $this->assertSame([], $r['formula_errors']);
        } finally {
            unlink($p);
        }
    }

    public function test_formula_is_rejected_with_original_row_column_even_with_cached_result(): void
    {
        $p = $this->mutation(function ($z) {
            $x = $z->getFromName('xl/worksheets/sheet1.xml');
            $x = str_replace('<c r="D2" s="1" t="inlineStr"><is><t xml:space="preserve">100</t></is></c>', '<c r="D2"><f>1+1</f><v>2</v></c>', $x);
            $z->addFromString('xl/worksheets/sheet1.xml', $x);
        });
        try {
            $r = (new Workbook)->read($p);
            $this->assertSame(2, $r['formula_errors'][0]['row']);
            $this->assertSame('판매가', $r['formula_errors'][0]['column']);
        } finally {
            unlink($p);
        }
    }

    public function test_numeric_identifiers_are_flagged_instead_of_losing_zero_prefixes(): void
    {
        $p = $this->mutation(function ($z) {
            $x = $z->getFromName('xl/worksheets/sheet1.xml');
            $z->addFromString('xl/worksheets/sheet1.xml', str_replace('<c r="A2" s="1" t="inlineStr"><is><t xml:space="preserve">0001</t></is></c>', '<c r="A2"><v>1</v></c>', $x));
        });
        try {
            $r = (new Workbook)->read($p);
            $this->assertSame('관리코드', $r['formula_errors'][0]['column']);
            $this->assertStringContainsString('문자열', $r['formula_errors'][0]['message']);
        } finally {
            unlink($p);
        }
    }

    public function test_corrupt_file_is_rejected(): void
    {
        $p = tempnam(sys_get_temp_dir(), 'broken-');
        file_put_contents($p, 'not a workbook');
        $this->rejected($p);
    }

    public function test_macro_and_external_relationship_are_rejected(): void
    {
        $this->rejected($this->mutation(fn ($z) => $z->addFromString('xl/vbaProject.bin', 'macro')));
        $this->rejected($this->mutation(fn ($z) => $z->addFromString('xl/_rels/workbook.xml.rels', '<Relationships><Relationship Id="x" TargetMode="External" Target="https://example.org"/></Relationships>')));
    }

    public function test_xml_entities_and_zip_traversal_are_rejected(): void
    {
        $this->rejected($this->mutation(fn ($z) => $z->addFromString('xl/workbook.xml', '<!DOCTYPE x [<!ENTITY y SYSTEM "file:///etc/passwd">]><x>&y;</x>')));
        $this->rejected($this->mutation(fn ($z) => $z->addFromString('../evil.xml', 'x')));
    }

    public function test_row_limit_and_uncompressed_member_limit_are_enforced(): void
    {
        $this->rejected($this->mutation(function ($z) {
            $x = $z->getFromName('xl/worksheets/sheet1.xml');
            $z->addFromString('xl/worksheets/sheet1.xml', str_replace('</sheetData>', '<row r="502"/></sheetData>', $x));
        }));
        $this->rejected($this->mutation(fn ($z) => $z->addFromString('large.xml', str_repeat('x', 16 * 1024 * 1024 + 1))));
    }

    public function test_file_size_limit_is_enforced(): void
    {
        $p = tempnam(sys_get_temp_dir(), 'large-file-');
        $f = fopen($p, 'wb');
        ftruncate($f, 10 * 1024 * 1024 + 1);
        fclose($f);
        $this->rejected($p);
    }

    public function test_output_is_explicit_text_for_user_supplied_formula_prefixes(): void
    {
        $bytes = (new Workbook)->write(['결과' => [['=1+1', '+SUM(A1)', '-1+1', '@cmd', '000123']]]);
        $p = tempnam(sys_get_temp_dir(), 'safe-output-');
        file_put_contents($p, $bytes);
        try {
            $z = new ZipArchive;
            $z->open($p);
            $xml = $z->getFromName('xl/worksheets/sheet1.xml');
            $z->close();
            $this->assertSame(5, substr_count($xml,'t="inlineStr"'));
            $this->assertStringNotContainsString('<f>',$xml);
        } finally {
            unlink($p);
        }
    }
}
