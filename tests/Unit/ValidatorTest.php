<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Translator;
use App\Core\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    private Validator $v;

    protected function setUp(): void
    {
        $t = new Translator(dirname(__DIR__, 2) . '/locales', ['ar', 'en'], 'en');
        $t->setLocale('en');
        // unique/exists rules are exercised by the E2E suite; these tests need no database.
        $db = (new \ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        $this->v = new Validator($t, $db);
    }

    private function errors(array $input, array $rules): array
    {
        try {
            $this->v->validate($input, $rules);
        } catch (ValidationException $e) {
            return $e->errors;
        }

        return [];
    }

    /** L-9: "field[]=x" must be a validation error on every kind of field, never an array passed on. */
    public function testArrayInputIsRejectedForEveryRule(): void
    {
        $rules = [
            'title_ar' => 'required|string|max:200', 'capacity' => 'required|integer|min:1', 'hours' => 'nullable|numeric',
            'email' => 'required|email', 'start' => 'required|datetime', 'type' => 'required|in:A,B', 'note' => 'nullable|string|max:1000',
        ];
        $input = array_fill_keys(array_keys($rules), ['x']);
        $errors = $this->errors($input, $rules);

        self::assertSame(array_keys($rules), array_keys($errors));
        foreach ($errors as $field => $messages) {
            self::assertStringContainsString("value isn't valid", $messages[0], $field);
        }
    }

    public function testRequestAccessorsIgnoreArrays(): void
    {
        $request = new \App\Core\Request('POST', '/x', ['page' => ['1'], 'q' => ['a'], 'lang' => 'en'], ['next' => ['/admin'], 'name' => 'Ali'], [], [], []);
        self::assertNull($request->query('page'));
        self::assertSame('fallback', $request->query('q', 'fallback'));
        self::assertSame('en', $request->query('lang'));
        self::assertSame('', (string) $request->input('next', ''));
        self::assertSame(['lang' => 'en', 'name' => 'Ali'], $request->all(), 'arrays are not re-flashed as old input');
        self::assertSame(['next' => ['/admin']], $request->only(['next']), 'only() hands them to the validator, which rejects them');
    }

    public function testHugePageNumbersAreCapped(): void
    {
        [$page] = \App\Core\Paginator::params(new \App\Core\Request('GET', '/', ['page' => '9223372036854775807'], [], [], [], []));
        self::assertSame(\App\Core\Paginator::MAX_PAGE, $page);
        self::assertLessThan(PHP_INT_MAX, \App\Core\Paginator::offset($page, 50));
    }

    public function testNormalizesTypesAndTrims(): void
    {
        $data = $this->v->validate(
            ['email' => '  Sara@Example.COM ', 'capacity' => '30', 'phone' => '055 123-4567', 'remember' => ''],
            ['email' => 'required|email', 'capacity' => 'required|integer|min:1', 'phone' => 'required|phone', 'remember' => 'boolean'],
        );
        self::assertSame(['email' => 'sara@example.com', 'capacity' => 30, 'phone' => '0551234567', 'remember' => false], $data);
    }

    public function testSaudiPhoneDropsTrunkZeroAfterCountryCode(): void
    {
        $data = $this->v->validate(
            ['a' => '+966 011 000 0000', 'b' => '+966 50 000 0000', 'c' => '0114000000'],
            ['a' => 'phone', 'b' => 'phone', 'c' => 'phone'],
        );
        self::assertSame(['a' => '+966110000000', 'b' => '+966500000000', 'c' => '0114000000'], $data);
    }

    public function testRequiredAndEmail(): void
    {
        $errors = $this->errors(['email' => 'not-an-email'], ['email' => 'required|email', 'name' => 'required']);
        self::assertSame(['Enter a valid email address.'], $errors['email']);
        self::assertArrayHasKey('name', $errors);
    }

    public function testPasswordPolicyAndConfirmation(): void
    {
        self::assertArrayHasKey('password', $this->errors(['password' => 'abcdefgh', 'password_confirmation' => 'abcdefgh'], ['password' => 'required|password|confirmed']));
        self::assertArrayHasKey('password', $this->errors(['password' => 'abc12345', 'password_confirmation' => 'abc12346'], ['password' => 'required|password|confirmed']));
        self::assertSame([], $this->errors(['password' => 'abc12345', 'password_confirmation' => 'abc12345'], ['password' => 'required|password|confirmed']));
    }

    public function testDatetimeOrdering(): void
    {
        $rules = ['start' => 'required|datetime', 'end' => 'required|datetime|after:start'];
        self::assertArrayHasKey('end', $this->errors(['start' => '2026-10-01T10:00', 'end' => '2026-10-01T09:00'], $rules));
        self::assertArrayHasKey('start', $this->errors(['start' => '2026-02-30T10:00', 'end' => '2026-10-01T09:00'], $rules));
        self::assertSame([], $this->errors(['start' => '2026-10-01T10:00', 'end' => '2026-10-01T12:00'], $rules));
    }

    public function testNumericMinMaxAndIn(): void
    {
        self::assertArrayHasKey('capacity', $this->errors(['capacity' => '0'], ['capacity' => 'required|integer|min:1']));
        self::assertArrayHasKey('type', $this->errors(['type' => 'OTHER'], ['type' => 'required|in:SUGGESTION,COMPLAINT']));
        self::assertArrayHasKey('title', $this->errors(['title' => str_repeat('x', 201)], ['title' => 'required|max:200']));
    }

    public function testMultibyteLengthCountsCharacters(): void
    {
        self::assertSame([], $this->errors(['name' => str_repeat('ع', 120)], ['name' => 'required|max:120']));
    }

    /** S6: bytes that are not valid UTF-8 are a validation error, not a database error (HTTP 500) further on. */
    public function testInvalidUtf8IsRejected(): void
    {
        $rules = [
            'subject' => 'required|string|max:200', 'note' => 'nullable|string|max:1000', 'email' => 'required|email',
            'plain' => 'nullable', 'code' => ['nullable', 'regex:/^.*$/s'],
        ];
        $bad = ["a\xFFb", "caf\xC3(", "\xE0\x80\x80", "\xED\xA0\x80", "ab\xF8\x88\x80\x80\x80"]; // stray, truncated, overlong, surrogate, 5-byte

        foreach ($bad as $i => $value) {
            $errors = $this->errors(['subject' => $value, 'note' => $value, 'email' => 'a@b.test', 'plain' => $value, 'code' => $value], $rules);
            self::assertSame(['subject', 'note', 'plain', 'code'], array_keys($errors), "sample $i: every text field is refused, other fields are not");
            self::assertSame($this->errors(['subject' => ['x']], ['subject' => 'required|string'])['subject'][0], $errors['subject'][0] ?? null, 'the ordinary "value isn\'t valid" message');
        }
    }

    public function testValidMultibyteTextIsStillAccepted(): void
    {
        $text = 'ورشة عمل — تصميم 😀 café Ünïcödé';

        self::assertSame([], $this->errors(['subject' => $text, 'note' => $text, 'plain' => $text], ['subject' => 'required|string|max:200', 'note' => 'nullable|string', 'plain' => 'nullable']));
        self::assertSame($text, $this->v->validate(['subject' => "  $text  "], ['subject' => 'required|string'])['subject']);
    }
}
