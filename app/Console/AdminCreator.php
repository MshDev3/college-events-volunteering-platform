<?php

declare(strict_types=1);

namespace App\Console;

use App\Console\Concerns\ReadsSecrets;
use App\Core\Exceptions\BusinessRuleException;
use App\Core\Exceptions\ValidationException;
use App\Core\Translator;
use App\Services\UserService;

/**
 * `php bin/console admin:create [--name="Full Name"] [--email=admin@college.edu] [--password-stdin]`
 *
 * Creates the first administrator from the command line (there is deliberately no web page for this).
 * The password is never accepted as an argument (it would end up in shell history and process lists):
 * it is typed twice with hidden input, or, for automated installs, read from STDIN with --password-stdin.
 * All validation (email, uniqueness, password policy) and the refusal when an active admin already
 * exists live in UserService::createInitialAdmin().
 */
final class AdminCreator
{
    use ReadsSecrets;

    public function __construct(private readonly UserService $users, private readonly Translator $translator)
    {
    }

    /**
     * @param list<string> $options raw CLI options
     * @param callable(string): void $out
     * @return int exit code
     */
    public function run(array $options, callable $out): int
    {
        $this->translator->setLocale('en');
        $opt = $this->parseOptions($options);
        $interactive = stream_isatty(STDIN);

        if (!isset($opt['password-stdin']) && !$interactive) {
            $out('Refusing to continue: no terminal to type the password in. Use --password-stdin to pipe it.');

            return 1;
        }

        $name = $opt['name'] ?? ($interactive ? $this->ask('Full name: ') : '');
        $email = $opt['email'] ?? ($interactive ? $this->ask('Email: ') : '');

        if (isset($opt['password-stdin'])) {
            $password = $this->readStdinLine();
            $confirmation = $password;
        } else {
            $password = $this->askHidden('Password (min 8 characters, letters and digits): ');
            $confirmation = $this->askHidden('Repeat password: ');
        }

        try {
            $user = $this->users->createInitialAdmin([
                'full_name' => $name,
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $confirmation,
            ]);
        } catch (ValidationException $e) {
            $out('Admin account NOT created:');
            foreach ($e->errors as $field => $messages) {
                $out("  - $field: " . implode(' ', $messages));
            }

            return 1;
        } catch (BusinessRuleException $e) {
            $out('Admin account NOT created: ' . $this->translator->get($e->key, $e->params));

            return 1;
        }

        $out("Administrator created: {$user->email} (id {$user->id}). Log in at " . absolute_url('/login') . ' and choose "Admin".');

        return 0;
    }
}
