<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Request;
use App\Support\CsvImport;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CsvImportTest extends TestCase
{
    public function testParsesCsvDataFromPostBody(): void
    {
        $rows = CsvImport::rowsFromRequest(new Request('POST', '/products/import', [], [
            'csv_data' => "sku,name\nSKU-IMPORT,Imported Product\n",
        ], []));

        self::assertSame([
            ['sku' => 'SKU-IMPORT', 'name' => 'Imported Product'],
        ], $rows);
    }

    public function testRejectsEmptyImportPayload(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CSV content or file is required.');

        CsvImport::rowsFromRequest(new Request('POST', '/products/import', [], [], []));
    }
}
