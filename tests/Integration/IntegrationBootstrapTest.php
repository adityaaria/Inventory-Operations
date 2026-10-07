<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

final class IntegrationBootstrapTest extends TestCase
{
    public function testIntegrationSuiteIsConfigured(): void
    {
        $pdo = \Tests\Support\TestDatabase::connect();
        self::assertStringEndsWith('_test', (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
        self::assertSame(0, (int) $pdo->getAttribute(\PDO::ATTR_EMULATE_PREPARES));
    }
}
