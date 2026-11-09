<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Tests\Unit;

use InvalidArgumentException;
use KenPrz\CustomFields\Exceptions\CustomFieldException;
use KenPrz\CustomFields\Support\ValueQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ValueQueryTest extends TestCase
{
    #[Test]
    public function it_rejects_operators_outside_the_whitelist(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ValueQuery::number('sqlite', '"value"', '; drop table x', 1);
    }

    /**
     * @return array<string, array{callable(): mixed}>
     */
    public static function builders(): array
    {
        return [
            'equals' => [fn () => ValueQuery::equals('sqlsrv', 'v', '=', 1)],
            'number' => [fn () => ValueQuery::number('sqlsrv', 'v', '=', 1)],
            'text' => [fn () => ValueQuery::text('sqlsrv', 'v', '=', 'a')],
            'like' => [fn () => ValueQuery::like('sqlsrv', 'v', 'a')],
            'list' => [fn () => ValueQuery::listContains('sqlsrv', 'v', 'a')],
        ];
    }

    #[Test]
    #[DataProvider('builders')]
    public function unsupported_drivers_throw(callable $build): void
    {
        $this->expectException(CustomFieldException::class);
        $this->expectExceptionMessage('not supported on the [sqlsrv] driver');

        $build();
    }

    #[Test]
    public function values_only_ever_appear_as_bindings(): void
    {
        foreach (['mysql', 'pgsql', 'sqlite'] as $driver) {
            foreach ([
                ValueQuery::equals($driver, 'col', '!=', "x' OR 1=1"),
                ValueQuery::number($driver, 'col', '>=', '5'),
                ValueQuery::text($driver, 'col', '<', "x' OR 1=1"),
                ValueQuery::like($driver, 'col', "x' OR 1=1"),
                ValueQuery::listContains($driver, 'col', "x' OR 1=1"),
            ] as [$sql, $bindings]) {
                $this->assertStringNotContainsString('OR 1=1', $sql, $driver);
                $this->assertSame(substr_count($sql, '?'), count($bindings), $driver.': '.$sql);
            }
        }
    }

    #[Test]
    public function like_escapes_wildcards(): void
    {
        [, $bindings] = ValueQuery::like('sqlite', 'col', '50%_off!');

        $this->assertSame(['%50!%!_off!!%'], $bindings);
    }
}
