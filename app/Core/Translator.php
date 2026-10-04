<?php

declare(strict_types=1);

namespace App\Core;

/**
 * JSON-file translator. Files live in locales/{locale}/{namespace}.json;
 * keys are addressed as "namespace.path.to.key".
 *
 * Placeholders use ":name". Plural entries are objects keyed by CLDR category
 * (zero, one, two, few, many, other); Arabic uses all six, English one/other.
 */
final class Translator
{
    /** @var array<string, array<string, mixed>> locale => namespace => tree */
    private array $loaded = [];

    private string $locale;

    /** @param list<string> $locales */
    public function __construct(
        private readonly string $path,
        private readonly array $locales,
        private readonly string $fallback,
    ) {
        $this->locale = $fallback;
    }

    public function setLocale(string $locale): void
    {
        if (in_array($locale, $this->locales, true)) {
            $this->locale = $locale;
        }
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /** @return list<string> */
    public function locales(): array
    {
        return $this->locales;
    }

    public function supports(string $locale): bool
    {
        return in_array($locale, $this->locales, true);
    }

    /** @param array<string, string|int|float|null> $params */
    public function get(string $key, array $params = [], ?string $locale = null): string
    {
        $value = $this->lookup($key, $locale ?? $this->locale) ?? $this->lookup($key, $this->fallback);
        if (is_array($value)) {
            $value = $value['other'] ?? null;
        }
        if (!is_string($value)) {
            return $key;
        }

        return $this->replace($value, $params);
    }

    /** @param array<string, string|int|float|null> $params */
    public function choice(string $key, int|float $count, array $params = [], ?string $locale = null): string
    {
        $locale ??= $this->locale;
        $forms = $this->lookup($key, $locale) ?? $this->lookup($key, $this->fallback);
        $params['count'] ??= $this->formatNumber($count);
        if (is_string($forms)) {
            return $this->replace($forms, $params);
        }
        if (!is_array($forms)) {
            return $key;
        }
        $category = $this->pluralCategory($count, $locale);
        $form = $forms[$category] ?? $forms['other'] ?? reset($forms);

        return $this->replace((string) $form, $params);
    }

    /** Raw value (string or array), e.g. a list of month names. */
    public function raw(string $key, ?string $locale = null): mixed
    {
        return $this->lookup($key, $locale ?? $this->locale) ?? $this->lookup($key, $this->fallback);
    }

    public function has(string $key, ?string $locale = null): bool
    {
        return $this->lookup($key, $locale ?? $this->locale) !== null;
    }

    public function pluralCategory(int|float $n, string $locale): string
    {
        if ($locale === 'ar') {
            $i = (int) $n;
            $mod100 = $i % 100;

            return match (true) {
                $n != $i => 'other',
                $i === 0 => 'zero',
                $i === 1 => 'one',
                $i === 2 => 'two',
                $mod100 >= 3 && $mod100 <= 10 => 'few',
                $mod100 >= 11 && $mod100 <= 99 => 'many',
                default => 'other',
            };
        }

        return (float) $n === 1.0 ? 'one' : 'other';
    }

    private function formatNumber(int|float $n): string
    {
        return is_float($n) && floor($n) !== $n ? rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.') : (string) (int) $n;
    }

    /** @param array<string, string|int|float|null> $params */
    private function replace(string $text, array $params): string
    {
        if ($params === []) {
            return $text;
        }
        // Longest keys first so :name does not clobber :name_ar.
        uksort($params, static fn ($a, $b) => strlen((string) $b) <=> strlen((string) $a));
        foreach ($params as $name => $value) {
            $text = str_replace(':' . $name, (string) $value, $text);
        }

        return $text;
    }

    private function lookup(string $key, string $locale): mixed
    {
        [$namespace, $rest] = array_pad(explode('.', $key, 2), 2, null);
        if ($namespace === null || $rest === null) {
            return null;
        }
        $tree = $this->load($locale, $namespace);
        foreach (explode('.', $rest) as $segment) {
            if (!is_array($tree) || !array_key_exists($segment, $tree)) {
                return null;
            }
            $tree = $tree[$segment];
        }

        return $tree;
    }

    /** @return array<string, mixed> */
    private function load(string $locale, string $namespace): array
    {
        if (!isset($this->loaded[$locale][$namespace])) {
            $file = $this->path . DIRECTORY_SEPARATOR . $locale . DIRECTORY_SEPARATOR . $namespace . '.json';
            $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
            $this->loaded[$locale][$namespace] = is_array($data) ? $data : [];
        }

        return $this->loaded[$locale][$namespace];
    }
}
