<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Support\LogRetention;
use App\Support\JsonFileLogger;
use PHPUnit\Framework\TestCase;
final class LogRetentionTest extends TestCase
{
    private string $directory;
    protected function setUp(): void { $this->directory = sys_get_temp_dir() . '/inventory-log-' . bin2hex(random_bytes(8)); mkdir($this->directory); }
    protected function tearDown(): void { foreach (glob($this->directory . '/*') ?: [] as $file) unlink($file); rmdir($this->directory); }
    public function testRetainsLatestArchivesWithoutLosingNewEntry(): void
    {
        $path = $this->directory . '/app.log';
        $logger = new LogRetention($path, 8, 2);
        foreach (['first', 'second', 'third', 'fourth'] as $line) $logger->append($line . "\n");
        self::assertSame("fourth\n", file_get_contents($path));
        self::assertSame("third\n", file_get_contents($path . '.1'));
        self::assertSame("second\n", file_get_contents($path . '.2'));
        self::assertFileDoesNotExist($path . '.3');
    }
    public function testStructuredRecordsRemainParseable(): void
    {
        $path = $this->directory . '/app.log';
        (new JsonFileLogger($path))->log('error', 'failure', ['operation' => 'receipt']);
        $entry = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('error', $entry['level']);
        self::assertSame('receipt', $entry['context']['operation']);
    }
    public function testInvalidRetentionRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LogRetention($this->directory . '/app.log', 0, 2);
    }
}
