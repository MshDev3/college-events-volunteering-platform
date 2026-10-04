<?php

declare(strict_types=1);

namespace Tests\Unit;

use Database\Demo\DemoSeeder;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/database/demo/DemoSeeder.php';

final class DemoSeederPasswordTest extends TestCase
{
    public function testWeakDemoPasswordsAreRejected(): void
    {
        foreach (['Short@1a', 'alllowercase-123', 'NoDigitsHere!!', 'NoSymbols12345'] as $weak) {
            self::assertFalse(DemoSeeder::isStrong($weak), $weak);
        }
        self::assertTrue(DemoSeeder::isStrong('Str0ng!Demo-Admin'));
    }

    public function testGeneratedPasswordsAreStrongAndUnique(): void
    {
        $a = DemoSeeder::randomPassword();
        self::assertTrue(DemoSeeder::isStrong($a));
        self::assertNotSame($a, DemoSeeder::randomPassword());
    }
}
