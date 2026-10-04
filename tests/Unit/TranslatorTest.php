<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Translator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TranslatorTest extends TestCase
{
    private Translator $t;

    protected function setUp(): void
    {
        $this->t = new Translator(dirname(__DIR__, 2) . '/locales', ['ar', 'en'], 'ar');
    }

    /** @return iterable<array{int, string}> */
    public static function arabicPlurals(): iterable
    {
        yield [0, 'zero'];
        yield [1, 'one'];
        yield [2, 'two'];
        yield [3, 'few'];
        yield [10, 'few'];
        yield [11, 'many'];
        yield [99, 'many'];
        yield [100, 'other'];
        yield [103, 'few'];
    }

    #[DataProvider('arabicPlurals')]
    public function testArabicPluralCategories(int $n, string $expected): void
    {
        self::assertSame($expected, $this->t->pluralCategory($n, 'ar'));
    }

    public function testEnglishPlurals(): void
    {
        self::assertSame('one', $this->t->pluralCategory(1, 'en'));
        self::assertSame('other', $this->t->pluralCategory(0, 'en'));
        self::assertSame('other', $this->t->pluralCategory(2, 'en'));
    }

    public function testChoiceRendersCountInBothLanguages(): void
    {
        self::assertSame('ساعتان', $this->t->choice('volunteering.hours_count', 2, [], 'ar'));
        self::assertSame('5 ساعات', $this->t->choice('volunteering.hours_count', 5, [], 'ar'));
        self::assertSame('2.5 hours', $this->t->choice('volunteering.hours_count', 2.5, [], 'en'));
        self::assertSame('1 hour', $this->t->choice('volunteering.hours_count', 1, [], 'en'));
    }

    public function testPlaceholdersAndLocaleSwitch(): void
    {
        $this->t->setLocale('en');
        self::assertSame('Welcome back, Sara.', $this->t->get('auth.flash.welcome', ['name' => 'Sara']));
        $this->t->setLocale('ar');
        self::assertSame('أهلًا سارة، تم تسجيل دخولك.', $this->t->get('auth.flash.welcome', ['name' => 'سارة']));
    }

    public function testUnknownKeyReturnsKeyAndUnsupportedLocaleIgnored(): void
    {
        self::assertSame('common.nope', $this->t->get('common.nope'));
        $this->t->setLocale('fr');
        self::assertSame('ar', $this->t->locale());
    }
}
