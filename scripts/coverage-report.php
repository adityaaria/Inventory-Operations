<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Same disposable-database guard as quality-check.php; writes Clover/JUnit reports for SonarQube.
Tests\Support\TestDatabase::reset();
try {
    // PCOV instruments only one directory (app/ by default); widen it so templates are measured too.
    passthru('php -d pcov.directory=' . escapeshellarg(dirname(__DIR__)) . ' vendor/bin/phpunit --coverage-clover var/coverage/clover.xml --log-junit var/coverage/junit.xml', $exitCode);
} finally {
    Tests\Support\TestDatabase::reset();
}
// Make report paths relative to the project root so the scanner can map them from any checkout location.
$root = dirname(__DIR__) . '/';
foreach (['var/coverage/clover.xml', 'var/coverage/junit.xml'] as $report) {
    $path = $root . $report;
    if (is_file($path)) { file_put_contents($path, str_replace(['"' . $root, "'" . $root], ['"', "'"], (string) file_get_contents($path))); }
}
exit($exitCode);
