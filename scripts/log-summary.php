<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$window = (int) ($argv[1] ?? '900');
if ($window < 1 || $window > 86400) { fwrite(STDERR, "Window must be 1..86400 seconds.\n"); exit(1); }
$since = time() - $window;
$errors = 0;
$malformed = 0;
foreach (glob(dirname(__DIR__) . '/var/log/app.log*') ?: [] as $path) {
    if (str_ends_with($path, '.lock') || !is_file($path)) { continue; }
    $source = fopen($path, 'rb');
    if ($source === false) { $malformed++; continue; }
    while (($line = fgets($source)) !== false) {
        $row = json_decode($line, true);
        if (!is_array($row) || !isset($row['timestamp'], $row['level'])) { $malformed++; continue; }
        $stamp = strtotime((string) $row['timestamp']);
        if ($stamp === false) { $malformed++; continue; }
        if ($stamp >= $since && in_array($row['level'], ['error', 'critical'], true)) { $errors++; }
    }
    fclose($source);
}
echo json_encode(['window_seconds' => $window, 'errors' => $errors, 'malformed_lines' => $malformed], JSON_THROW_ON_ERROR) . PHP_EOL;
