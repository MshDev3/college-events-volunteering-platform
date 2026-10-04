<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Csrf;
use App\Core\Database;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Domain\Role;
use App\Http\Controllers\Account\ProfileController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Repositories\UserRepository;
use App\Services\Auth\AuthService;
use App\Services\Auth\EmailChangeService;
use App\Services\Auth\PasswordHasher;
use App\Services\Auth\PasswordResetService;
use App\Services\UserService;
use PDO;

/**
 * S3: stack traces are written to storage/logs. With PHP's default "zend.exception_ignore_args=Off" they carry the
 * first characters of every argument, which for login and reset means the user's password or reset token.
 */
final class SecretsInTracesTest extends IntegrationTestCase
{
    private const SECRET = 'Zq9-uniqueSecretPW!';

    private string|false $previousIgnoreArgs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousIgnoreArgs = ini_get('zend.exception_ignore_args');
    }

    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', (string) $this->previousIgnoreArgs);
        parent::tearDown();
    }

    /** A database whose every statement fails, so the exception is raised deep inside the call being tested. */
    private function failingDatabase(): Database
    {
        // Thrown from a callback so the trace is that of the real call chain (a prebuilt exception keeps its creation trace).
        $fail = static function (): never {
            throw new \RuntimeException('database is down');
        };
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback($fail);
        $pdo->method('beginTransaction')->willReturnCallback($fail);

        return new Database($pdo);
    }

    private function traceOf(callable $fn): string
    {
        // The default PHP setting, which is what an exception carries when the application does not change it.
        ini_set('zend.exception_ignore_args', '0');
        try {
            $fn();
        } catch (\RuntimeException $e) {
            return $e->getTraceAsString();
        }
        self::fail('the call was expected to fail');
    }

    public function testLoginPasswordIsNotInTheStackTrace(): void
    {
        $auth = new AuthService(
            app(UserRepository::class),
            app(PasswordHasher::class),
            app(\App\Services\Auth\RememberMeService::class),
            new RateLimiter($this->failingDatabase()),
            app(\App\Core\Session::class),
            app(Csrf::class),
            app(\App\Services\Auth\CurrentUser::class),
            app(\App\Core\Config::class),
        );

        $trace = $this->traceOf(fn () => $auth->attempt('student@test.local', self::SECRET, Role::STUDENT, false, new Request('POST', '/login', [], [], [], [], ['REMOTE_ADDR' => '198.51.100.1'])));

        self::assertStringContainsString('AuthService->attempt(', $trace, 'precondition: the login call is part of the trace');
        self::assertStringNotContainsString(substr(self::SECRET, 0, 15), $trace);
    }

    public function testResetPasswordAndTokenAreNotInTheStackTrace(): void
    {
        $reset = new PasswordResetService(
            $this->failingDatabase(),
            app(UserRepository::class),
            app(PasswordHasher::class),
            app(\App\Services\Auth\RememberMeService::class),
            app(RateLimiter::class),
            app(\App\Services\EmailService::class),
            app(\App\Services\NotificationService::class),
            app(\App\Core\Config::class),
            app(\App\Services\MailQueue::class),
        );
        $token = 'f00dfeedc0ffee1234567890abcdef';

        $trace = $this->traceOf(fn () => $reset->reset($token, self::SECRET));

        self::assertStringContainsString('PasswordResetService->reset(', $trace, 'precondition: the reset call is part of the trace');
        self::assertStringNotContainsString(substr(self::SECRET, 0, 15), $trace);
        self::assertStringNotContainsString(substr($token, 0, 15), $trace);
    }

    /** @return iterable<string, array{class-string, string, string}> */
    public static function secretParameters(): iterable
    {
        yield 'login password' => [AuthService::class, 'attempt', 'password'];
        yield 'hash input' => [PasswordHasher::class, 'hash', 'password'];
        yield 'verify password' => [PasswordHasher::class, 'verify', 'password'];
        yield 'verify stored hash' => [PasswordHasher::class, 'verify', 'hash'];
        yield 'rehash check' => [PasswordHasher::class, 'needsRehash', 'hash'];
        yield 'dummy verify' => [PasswordHasher::class, 'burnTime', 'password'];
        yield 'reset token (check)' => [PasswordResetService::class, 'isValid', 'token'];
        yield 'reset token' => [PasswordResetService::class, 'reset', 'token'];
        yield 'reset new password' => [PasswordResetService::class, 'reset', 'newPassword'];
        yield 'current password check' => [UserService::class, 'verifyCurrentPassword', 'password'];
        yield 'email change token (show)' => [EmailChangeService::class, 'newEmailFor', 'token'];
        yield 'email change token' => [EmailChangeService::class, 'confirm', 'token'];
        yield 'stored hash on create' => [UserRepository::class, 'create', 'passwordHash'];
        yield 'csrf token' => [Csrf::class, 'verify', 'token'];
        yield 'reset page token' => [PasswordResetController::class, 'resetForm', 'token'];
        yield 'reset submit token' => [PasswordResetController::class, 'reset', 'token'];
        yield 'confirm page token' => [ProfileController::class, 'confirmEmailForm', 'token'];
        yield 'confirm submit token' => [ProfileController::class, 'confirmEmail', 'token'];
    }

    /** @param class-string $class */
    #[\PHPUnit\Framework\Attributes\DataProvider('secretParameters')]
    public function testSecretParametersAreMarkedSensitive(string $class, string $method, string $parameter): void
    {
        $found = null;
        foreach ((new \ReflectionMethod($class, $method))->getParameters() as $p) {
            if ($p->getName() === $parameter) {
                $found = $p;
            }
        }

        self::assertNotNull($found, "$class::$method has no \$$parameter parameter");
        self::assertNotEmpty($found->getAttributes(\SensitiveParameter::class), "$class::$method(\$$parameter) must be #[\\SensitiveParameter]");
    }

    public function testApplicationDoesNotPutArgumentsIntoExceptionTraces(): void
    {
        // The bootstrap switches arguments off for every exception created afterwards (set before setUp() restores it).
        self::assertSame('1', $this->previousIgnoreArgs, 'zend.exception_ignore_args must be on once the application has booted');
    }
}
