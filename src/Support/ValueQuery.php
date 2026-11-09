<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Support;

use InvalidArgumentException;
use KenPrz\CustomFields\Exceptions\CustomFieldException;

/**
 * Builds the SQL that compares a stored JSON value, per database driver.
 * Every value goes through a binding; only the column name and the
 * (whitelisted) operator are written into the SQL.
 *
 * @internal
 */
final class ValueQuery
{
    public const OPERATORS = ['=', '!=', '<>', '>', '>=', '<', '<='];

    /**
     * Compare the whole stored value with a JSON-encoded value. Used for
     * = and != on non-numeric types.
     *
     * @return array{string, list<mixed>}
     */
    public static function equals(string $driver, string $column, string $operator, mixed $value): array
    {
        $operator = $operator === '=' ? '=' : '<>';

        return [match ($driver) {
            'mysql' => "{$column} {$operator} CAST(? AS JSON)",
            'pgsql' => "{$column} {$operator} CAST(? AS jsonb)",
            'sqlite' => "json({$column}) {$operator} json(?)",
            default => throw self::unsupported($driver),
        }, [json_encode($value, JSON_THROW_ON_ERROR)]];
    }

    /**
     * Compare a stored number (int, float or decimal string) numerically.
     *
     * @return array{string, list<mixed>}
     */
    public static function number(string $driver, string $column, string $operator, int|float|string $value): array
    {
        $operator = self::operator($operator);

        return [match ($driver) {
            'mysql' => "CAST(JSON_UNQUOTE({$column}) AS DECIMAL(65,30)) {$operator} CAST(? AS DECIMAL(65,30))",
            'pgsql' => "CAST({$column} #>> '{}' AS numeric) {$operator} CAST(? AS numeric)",
            'sqlite' => "CAST(json_extract({$column}, '$') AS NUMERIC) {$operator} CAST(? AS NUMERIC)",
            default => throw self::unsupported($driver),
        }, [$value]];
    }

    /**
     * Compare a stored string as text, byte by byte on every driver (MySQL's
     * JSON_UNQUOTE returns utf8mb4_bin, SQLite is binary, Postgres gets
     * COLLATE "C"). Dates, datetimes and times are stored in sortable
     * formats, so this also orders them correctly.
     *
     * @return array{string, list<mixed>}
     */
    public static function text(string $driver, string $column, string $operator, string $value): array
    {
        $operator = self::operator($operator);

        return [match ($driver) {
            'mysql' => "JSON_UNQUOTE({$column}) {$operator} ?",
            'pgsql' => "({$column} #>> '{}') COLLATE \"C\" {$operator} ?",
            'sqlite' => "json_extract({$column}, '$') {$operator} ?",
            default => throw self::unsupported($driver),
        }, [$value]];
    }

    /**
     * Case-insensitive substring match on a stored string. SQLite only folds
     * ASCII letters.
     *
     * @return array{string, list<mixed>}
     */
    public static function like(string $driver, string $column, string $value): array
    {
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value).'%';

        return [match ($driver) {
            'mysql' => "LOWER(JSON_UNQUOTE({$column})) LIKE LOWER(?) ESCAPE '!'",
            'pgsql' => "({$column} #>> '{}') ILIKE ? ESCAPE '!'",
            'sqlite' => "json_extract({$column}, '$') LIKE ? ESCAPE '!'",
            default => throw self::unsupported($driver),
        }, [$pattern]];
    }

    /**
     * Whether a stored list contains a string element.
     *
     * @return array{string, list<mixed>}
     */
    public static function listContains(string $driver, string $column, string $value): array
    {
        return match ($driver) {
            'mysql' => ["JSON_CONTAINS({$column}, CAST(? AS JSON))", [json_encode($value, JSON_THROW_ON_ERROR)]],
            'pgsql' => ["{$column} @> CAST(? AS jsonb)", [json_encode([$value], JSON_THROW_ON_ERROR)]],
            'sqlite' => ["EXISTS (SELECT 1 FROM json_each({$column}) WHERE json_each.value = ?)", [$value]],
            default => throw self::unsupported($driver),
        };
    }

    private static function operator(string $operator): string
    {
        if (! in_array($operator, self::OPERATORS, true)) {
            throw new InvalidArgumentException("Unsupported custom field operator [{$operator}].");
        }

        return $operator === '!=' ? '<>' : $operator;
    }

    private static function unsupported(string $driver): CustomFieldException
    {
        return new CustomFieldException("Querying custom fields is not supported on the [{$driver}] driver.");
    }
}
