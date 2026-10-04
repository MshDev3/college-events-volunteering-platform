<?php

declare(strict_types=1);

namespace App\Console\Concerns;

/** Hidden password prompts for CLI commands (passwords are never accepted as command-line arguments). */
trait ReadsSecrets
{
    private function ask(string $prompt): string
    {
        fwrite(STDOUT, $prompt);

        return trim((string) fgets(STDIN));
    }

    /** Reads a line without echoing it (Windows: PowerShell secure prompt; elsewhere: stty). */
    private function askHidden(string $prompt): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $label = (string) preg_replace('/[^A-Za-z0-9 ,()-]/', '', rtrim($prompt, ': '));
            $script = "\$p = Read-Host -AsSecureString -Prompt '$label'; "
                . '[Runtime.InteropServices.Marshal]::PtrToStringBSTR([Runtime.InteropServices.Marshal]::SecureStringToBSTR($p))';
            $value = shell_exec('powershell -NoProfile -Command ' . escapeshellarg($script));

            return rtrim((string) $value, "\r\n");
        }

        fwrite(STDOUT, $prompt);
        shell_exec('stty -echo');
        $value = (string) fgets(STDIN);
        shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);

        return rtrim($value, "\r\n");
    }

    /** One line from STDIN (for --…-stdin options), without the line ending. */
    private function readStdinLine(): string
    {
        return rtrim((string) fgets(STDIN), "\r\n");
    }

    /** @param list<string> $options @return array<string, string> */
    private function parseOptions(array $options): array
    {
        $parsed = [];
        foreach ($options as $option) {
            if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $option, $m) === 1) {
                $parsed[$m[1]] = $m[2] ?? '1';
            }
        }

        return $parsed;
    }
}
