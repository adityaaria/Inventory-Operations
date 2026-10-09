<?php

declare(strict_types=1);

// Kept for shell one-liners and older tooling; application code calls Config::fromEnvironment() directly.
return App\Support\Config::fromEnvironment();
