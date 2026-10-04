<?php

declare(strict_types=1);

/** Front controller: every HTTP request enters here. */

$app = require dirname(__DIR__) . '/app/bootstrap.php';

$app->handle(App\Core\Request::fromGlobals())->send();
$app->terminate();
