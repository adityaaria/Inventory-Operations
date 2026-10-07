<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Request;
use App\Support\CsvImport;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CsvImportTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->tempFiles = [];
    }

    public function testParsesCsvDataFromPostBody(): void
    {
        $rows = CsvImport::rowsFromRequest(new Request('POST', '/products/import', [], [
            'csv_data' => "sku,name\nSKU-IMPORT,Imported Product\n",
        ], []));

        self::assertSame([
            ['sku' => 'SKU-IMPORT', 'name' => 'Imported Product'],
        ], $rows);
    }

    public function testRejectsArrayContentBeforeStringCoercion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CSV content must be text.');
        CsvImport::rowsFromRequest(new Request('POST', '/products/import', [], ['csv_data' => ['bad']], []));
    }

    public function testRejectsNestedMultipleUploadMetadata(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Upload one CSV file at a time.');
        CsvImport::rowsFromRequest(new Request('POST', '/products/import', [], [], [], ['csv_file' => ['error' => [UPLOAD_ERR_OK], 'size' => [10], 'tmp_name' => ['/tmp/file']]]));
    }

    public function testRejectsEmptyImportPayload(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CSV content or file is required.');

        CsvImport::rowsFromRequest(new Request('POST', '/products/import', [], [], []));
    }

    public function testParsesCsvFromUploadedFile(): void
    {
        $path = $this->createCsvFile("sku,name\nSKU-002,Widget B\n");

        $rows = CsvImport::rowsFromRequest(new Request('POST', '/products/import', [], [], [], [
            'csv_file' => ['error' => UPLOAD_ERR_OK, 'size' => filesize($path), 'tmp_name' => $path],
        ]));

        self::assertSame([
            ['sku' => 'SKU-002', 'name' => 'Widget B'],
        ], $rows);
    }

    public function testRejectsUploadErrorOtherThanOk(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CSV upload failed.');

        CsvImport::rowsFromRequest(new Request('POST', '/products/import', [], [], [], [
            'csv_file' => ['error' => UPLOAD_ERR_PARTIAL, 'size' => 10, 'tmp_name' => '/tmp/whatever'],
        ]));
    }

    public function testRejectsFileLargerThanOneMegabyte(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CSV file must be 1 MB or smaller.');

        CsvImport::rowsFromRequest(new Request('POST', '/products/import', [], [], [], [
            'csv_file' => ['error' => UPLOAD_ERR_OK, 'size' => 1048577, 'tmp_name' => '/tmp/whatever'],
        ]));
    }

    public function testRejectsUnreadableTemporaryPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CSV upload could not be read.');

        CsvImport::rowsFromRequest(new Request('POST', '/products/import', [], [], [], [
            'csv_file' => ['error' => UPLOAD_ERR_OK, 'size' => 10, 'tmp_name' => '/tmp/does-not-exist-' . uniqid()],
        ]));
    }

    public function testRejectsEmptyUploadedFileContent(): void
    {
        $path = $this->createCsvFile('   ');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CSV content or file is required.');

        CsvImport::rowsFromRequest(new Request('POST', '/products/import', [], [], [], [
            'csv_file' => ['error' => UPLOAD_ERR_OK, 'size' => filesize($path), 'tmp_name' => $path],
        ]));
    }

    public function testRejectsMissingHeaderRow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CSV must contain at least one data row.');

        CsvImport::rowsFromRequest(new Request('POST', '/products/import', [], [
            'csv_data' => "sku,name\n",
        ], []));
    }

    public function testRejectsHeaderWithEmptyColumn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CSV header contains an empty column.');

        CsvImport::rowsFromRequest(new Request('POST', '/products/import', [], [
            'csv_data' => "sku,\nSKU-001,Widget A\n",
        ], []));
    }

    public function testSkipsBlankDataRows(): void
    {
        $rows = CsvImport::rowsFromRequest(new Request('POST', '/products/import', [], [
            'csv_data' => "sku,name\nSKU-001,Widget A\n,\nSKU-002,Widget B\n",
        ], []));

        self::assertCount(2, $rows);
        self::assertSame('SKU-001', $rows[0]['sku']);
        self::assertSame('SKU-002', $rows[1]['sku']);
    }

    private function createCsvFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'csv-import-test-');
        if ($path === false) {
            self::fail('Unable to create temporary CSV file for test.');
        }
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
