<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Refuses the application database before any destructive test setup.
Tests\Support\TestDatabase::reset();
try {
    $exitCode = 0;
    foreach (['vendor/bin/phpunit', 'vendor/bin/phpstan analyse --memory-limit=256M', 'node --test tests/JavaScript/*.test.js'] as $command) {
        passthru($command, $exitCode);
        if ($exitCode !== 0) { break; }
    }
} finally {
    // Leave the disposable test service seeded, including when checks fail.
    Tests\Support\TestDatabase::reset();
}
exit($exitCode);
