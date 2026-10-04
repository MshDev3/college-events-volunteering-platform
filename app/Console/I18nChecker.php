<?php

declare(strict_types=1);

namespace App\Console;

/**
 * Verifies the translation catalogue:
 *   1. every key exists in both ar and en (same structure, including plural forms);
 *   2. every literal t('ns.key') / tc('ns.key') used in app/ and views/ exists.
 * Exit code 0 = clean, 1 = problems found.
 */
final class I18nChecker
{
    public function __construct(private readonly string $localesPath, private readonly string $basePath)
    {
    }

    /** @param callable(string): void $out */
    public function run(callable $out): int
    {
        $catalogues = [];
        foreach (['ar', 'en'] as $locale) {
            foreach (glob("{$this->localesPath}/$locale/*.json") ?: [] as $file) {
                $data = json_decode((string) file_get_contents($file), true);
                if (!is_array($data)) {
                    $out("Invalid JSON: $file");

                    return 1;
                }
                foreach ($this->flatten($data, basename($file, '.json')) as $key) {
                    $catalogues[$locale][$key] = true;
                }
            }
        }

        $problems = 0;
        foreach (['ar' => 'en', 'en' => 'ar'] as $from => $to) {
            foreach (array_keys($catalogues[$from] ?? []) as $key) {
                if (!isset($catalogues[$to][$key]) && !$this->isOptionalPluralForm($key, $to)) {
                    $out("Missing in $to: $key");
                    $problems++;
                }
            }
        }

        $known = array_keys(($catalogues['ar'] ?? []) + ($catalogues['en'] ?? []));
        $prefixes = [];
        foreach ($known as $key) {
            $parts = explode('.', $key);
            while (count($parts) > 1) {
                array_pop($parts);
                $prefixes[implode('.', $parts)] = true;
            }
        }
        $known = array_flip($known);

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->basePath . '/app'));
        $files = iterator_to_array($iterator);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->basePath . '/views'));
        $files = array_merge($files, iterator_to_array($iterator));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php' || $file->getRealPath() === __FILE__) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            // Only complete literal keys: t('ns.key') / t('ns.key', [...]). Concatenated prefixes are dynamic.
            preg_match_all("/\\b(?:t|tc)\\(\\s*'([a-z_]+\\.[A-Za-z0-9_.]+)'\\s*[,)]/", $source, $m);
            foreach ($m[1] as $key) {
                if (!isset($known[$key]) && !isset($prefixes[$key])) {
                    $out('Unknown key used in ' . str_replace($this->basePath . DIRECTORY_SEPARATOR, '', $file->getPathname()) . ": $key");
                    $problems++;
                }
            }
        }

        $out($problems === 0 ? 'i18n OK: ' . count($catalogues['ar'] ?? []) . ' keys in ar, ' . count($catalogues['en'] ?? []) . ' in en.' : "$problems problem(s).");

        return $problems === 0 ? 0 : 1;
    }

    /** Arabic has zero/two/few/many plural forms that English does not need. */
    private function isOptionalPluralForm(string $key, string $locale): bool
    {
        return $locale === 'en' && preg_match('/\.(zero|two|few|many)$/', $key) === 1;
    }

    /** @param array<string, mixed> $data @return list<string> */
    private function flatten(array $data, string $prefix): array
    {
        $keys = [];
        foreach ($data as $key => $value) {
            $full = $prefix . '.' . $key;
            if (is_array($value) && !array_is_list($value)) {
                $keys = array_merge($keys, $this->flatten($value, $full));
            } else {
                $keys[] = $full;
            }
        }

        return $keys;
    }
}
