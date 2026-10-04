<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\DbPrivilegeChecker;
use PHPUnit\Framework\TestCase;

final class DbPrivilegeCheckerTest extends TestCase
{
    public function testDataOnlyAccountPasses(): void
    {
        self::assertSame([], DbPrivilegeChecker::excessPrivileges([
            "GRANT USAGE ON *.* TO `tvtc_app`@`localhost` IDENTIFIED BY PASSWORD '*ABC'",
            'GRANT SELECT, INSERT, UPDATE, DELETE ON `tvtc_portal`.* TO `tvtc_app`@`localhost`',
        ]));
    }

    public function testRootAndDdlGrantsAreReported(): void
    {
        $root = DbPrivilegeChecker::excessPrivileges(['GRANT ALL PRIVILEGES ON *.* TO `root`@`localhost` WITH GRANT OPTION']);
        self::assertCount(2, $root);
        self::assertStringContainsString('Server-wide', implode(' ', $root));

        $ddl = DbPrivilegeChecker::excessPrivileges(['GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP ON `tvtc_portal`.* TO `x`@`localhost`']);
        self::assertSame(['More than data access on `tvtc_portal`.*: CREATE, DROP'], $ddl);

        self::assertSame(['Has a PROXY grant'], DbPrivilegeChecker::excessPrivileges(["GRANT PROXY ON ''@'%' TO 'x'@'localhost'"]));
    }
}
