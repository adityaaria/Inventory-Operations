<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Request;
use App\Support\CsvImport;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class CsvImportCoverageTest extends TestCase
{
    public function testParserRejectsStreamWithoutHeaderLine(): void
    {
        $parse = new \ReflectionMethod(CsvImport::class, 'parse');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('CSV header is required.');

        $parse->invoke(null, '');
    }

    #[RunInSeparateProcess]
    public function testParserFailsClosedWhenTemporaryStreamCannotBeOpened(): void
    {
        $warnings = [];
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });
        stream_wrapper_unregister('php');

        try {
            CsvImport::rowsFromRequest(new Request('POST', '/products/import', [], ['csv_data' => "sku,name\nA,B"], []));
            self::fail('Expected the parser to reject an unavailable temporary stream.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('CSV content could not be parsed.', $exception->getMessage());
        } finally {
            stream_wrapper_restore('php');
            restore_error_handler();
        }

        self::assertNotEmpty($warnings);
    }
}
