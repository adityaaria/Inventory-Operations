<?php

declare(strict_types=1);

// Kept for shell one-liners and older tooling; application code calls ApplicationBootstrap::boot() directly.
require_once dirname(__DIR__) . '/vendor/autoload.php';

return App\Support\ApplicationBootstrap::boot(dirname(__DIR__));
