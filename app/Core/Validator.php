<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Exceptions\ValidationException;
use DateTimeImmutable;

/**
 * Declarative input validation with translated messages.
 *
 *   $data = $validator->validate($request->all(), [
 *       'email' => 'required|email|max:190|unique:users,email',
 *       'capacity' => 'required|integer|min:1|max:10000',
 *       'student_id' => ['nullable', 'regex:/^\d{6,12}$/'],
 *   ]);
 *
 * The unique rule's table/column names come from code, never from user input.
 */
final class Validator
{
    public const DATETIME_FORMAT = 'Y-m-d\TH:i';

    public function __construct(
        private readonly Translator $translator,
        private readonly Database $db,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, string|list<string>> $rules
     * @return array<string, mixed> Validated, normalized values (only fields listed in $rules)
     * @throws ValidationException
     */
    public function validate(array $input, array $rules): array
    {
        $errors = [];
        $data = [];

        foreach ($rules as $field => $fieldRules) {
            $fieldRules = is_string($fieldRules) ? explode('|', $fieldRules) : $fieldRules;
            $value = $input[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
                // Not valid UTF-8 (stray, truncated or overlong bytes): refuse it here. The database runs in strict
                // mode and rejects such text with an error that used to surface as a 500, and json_encode() of it
                // (audit log, notifications) fails.
                if (!mb_check_encoding($value, 'UTF-8')) {
                    $errors[$field][] = $this->message('string', $field);
                    continue;
                }
            }
            $isEmpty = $value === null || $value === '' || $value === [];
            $numeric = in_array('integer', $fieldRules, true) || in_array('numeric', $fieldRules, true);

            if ($isEmpty) {
                if (in_array('required', $fieldRules, true)) {
                    $errors[$field][] = $this->message('required', $field);
                } elseif (in_array('boolean', $fieldRules, true)) {
                    $data[$field] = false;
                } else {
                    $data[$field] = null;
                }
                continue;
            }

            // Every field is a single value: "title_ar[]=x" is invalid input, never an array passed on
            // to the database (which used to end in a 500).
            if (!is_scalar($value)) {
                $errors[$field][] = $this->message('string', $field);
                continue;
            }

            foreach ($fieldRules as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $error = $this->check($name, $arg, $value, $field, $input, $numeric);
                if ($error !== null) {
                    $errors[$field][] = $error;
                    break; // one message per field keeps forms readable
                }
            }

            $data[$field] = $this->normalize($value, $fieldRules);
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $data;
    }

    /** @param array<string, mixed> $input */
    private function check(string $rule, ?string $arg, mixed $value, string $field, array $input, bool $numeric): ?string
    {
        $str = is_scalar($value) ? (string) $value : '';

        $ok = match ($rule) {
            'required', 'nullable', 'boolean' => true,
            'string' => is_string($value),
            'email' => filter_var($str, FILTER_VALIDATE_EMAIL) !== false && mb_strlen($str) <= 190,
            'integer' => filter_var($str, FILTER_VALIDATE_INT) !== false,
            'numeric' => is_numeric($str),
            'min' => $numeric ? (float) $str >= (float) $arg : mb_strlen($str) >= (int) $arg,
            'max' => $numeric ? (float) $str <= (float) $arg : mb_strlen($str) <= (int) $arg,
            'in' => in_array($str, explode(',', (string) $arg), true),
            'regex' => preg_match((string) $arg, $str) === 1,
            'confirmed' => $str === (string) ($input[$field . '_confirmation'] ?? ''),
            'different' => $str !== (string) ($input[(string) $arg] ?? ''),
            'date' => $this->parseDate($str, 'Y-m-d') !== null,
            'datetime' => $this->parseDate($str, self::DATETIME_FORMAT) !== null,
            'after' => $this->isAfter($str, (string) ($input[(string) $arg] ?? '')),
            'after_now' => ($d = $this->parseDate($str, self::DATETIME_FORMAT)) !== null && $d > new DateTimeImmutable(),
            'phone' => preg_match('/^\+?[0-9]{9,15}$/', preg_replace('/[\s-]/', '', $str) ?? '') === 1,
            'password' => mb_strlen($str) >= 8 && preg_match('/\p{L}/u', $str) === 1 && preg_match('/\d/', $str) === 1,
            'unique' => $this->isUnique($str, (string) $arg),
            default => throw new \InvalidArgumentException("Unknown validation rule [$rule]."),
        };

        if ($ok) {
            return null;
        }

        $key = match ($rule) {
            'min', 'max' => $rule . '.' . ($numeric ? 'numeric' : 'string'),
            default => $rule,
        };

        $params = ['value' => (string) $arg];
        if ($rule === 'after') {
            $params['other'] = $this->attribute((string) $arg);
        }

        return $this->message($key, $field, $params);
    }

    /** @param list<string> $rules */
    private function normalize(mixed $value, array $rules): mixed
    {
        if (in_array('integer', $rules, true)) {
            return (int) $value;
        }
        if (in_array('numeric', $rules, true)) {
            return (float) $value;
        }
        if (in_array('boolean', $rules, true)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }
        if (in_array('email', $rules, true)) {
            return mb_strtolower((string) $value);
        }
        if (in_array('phone', $rules, true)) {
            // Saudi numbers: the domestic trunk 0 is dropped after the country code (+966 011… → +96611…).
            return preg_replace(['/[\s-]/', '/^\+9660/'], ['', '+966'], (string) $value);
        }

        return $value;
    }

    public function parseDate(string $value, string $format): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
        if ($date === false || $date->format($format) !== $value) {
            return null;
        }

        return $date;
    }

    private function isAfter(string $value, string $other): bool
    {
        $a = $this->parseDate($value, self::DATETIME_FORMAT) ?? $this->parseDate($value, 'Y-m-d');
        $b = $this->parseDate($other, self::DATETIME_FORMAT) ?? $this->parseDate($other, 'Y-m-d');

        return $a !== null && ($b === null || $a > $b);
    }

    /** "unique:table,column[,ignoreId]" */
    private function isUnique(string $value, string $arg): bool
    {
        [$table, $column, $ignore] = array_pad(explode(',', $arg), 3, null);
        $sql = "SELECT COUNT(*) FROM `$table` WHERE `$column` = ?";
        $params = [$value];
        if ($ignore !== null && $ignore !== '') {
            $sql .= ' AND id <> ?';
            $params[] = (int) $ignore;
        }

        return (int) $this->db->value($sql, $params) === 0;
    }

    /** @param array<string, string> $params */
    private function message(string $key, string $field, array $params = []): string
    {
        return $this->translator->get('validation.' . $key, ['attribute' => $this->attribute($field)] + $params);
    }

    private function attribute(string $field): string
    {
        $key = 'validation.attributes.' . $field;
        $label = $this->translator->get($key);

        return $label === $key ? str_replace('_', ' ', $field) : $label;
    }
}
