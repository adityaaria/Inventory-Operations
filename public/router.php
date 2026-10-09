<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = __DIR__ . (is_string($path) ? $path : '/');

if (is_file($file)) {
    return false;
}

// The built-in server resets the included-files list per request, so require_once still runs on every request.
require_once __DIR__ . '/index.php';
