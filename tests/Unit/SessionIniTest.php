<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Session;
use PHPUnit\Framework\TestCase;

/**
 * S11: PHP 8.4 deprecates the session.sid_length / session.sid_bits_per_character settings, and the application's
 * error handler turns every deprecation into an exception, so setting them there would break every request.
 */
final class SessionIniTest extends TestCase
{
    public function testSafeSettingsApplyOnEveryPhpVersion(): void
    {
        foreach ([80200, 80399, 80400, 80500] as $version) {
            $settings = Session::iniSettings(720, $version);

            self::assertSame('1', $settings['session.use_strict_mode'], (string) $version);
            self::assertSame('1', $settings['session.use_only_cookies'], (string) $version);
            self::assertSame('0', $settings['session.use_trans_sid'], (string) $version);
            self::assertSame('43200', $settings['session.gc_maxlifetime'], (string) $version);
        }
    }

    public function testLongSessionIdsAreRequestedBeforePhp84(): void
    {
        foreach ([80200, 80312, 80399] as $version) {
            $settings = Session::iniSettings(720, $version);

            self::assertSame('48', $settings['session.sid_length'], (string) $version);
            self::assertSame('6', $settings['session.sid_bits_per_character'], (string) $version);
        }
    }

    public function testDeprecatedSettingsAreNotTouchedOnPhp84AndLater(): void
    {
        foreach ([80400, 80401, 80500, 90000] as $version) {
            $settings = Session::iniSettings(720, $version);

            self::assertArrayNotHasKey('session.sid_length', $settings, (string) $version);
            self::assertArrayNotHasKey('session.sid_bits_per_character', $settings, (string) $version);
        }
    }

    public function testEverySettingIsAnIniEntryThisPhpKnows(): void
    {
        foreach (array_keys(Session::iniSettings(720)) as $name) {
            self::assertNotFalse(ini_get($name), "$name is not a known ini setting");
        }
    }
}
