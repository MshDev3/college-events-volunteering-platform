<?php

declare(strict_types=1);

// Boot the real application container (config, translator, ...) so helpers like t() work in tests.
require dirname(__DIR__) . '/app/bootstrap.php';
