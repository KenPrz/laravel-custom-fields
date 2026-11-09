<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Tests\Performance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use KenPrz\CustomFields\Facades\CustomFields;
use KenPrz\CustomFields\Models\CustomFieldValue;
use KenPrz\CustomFields\Tests\Fixtures\Reservation;
use KenPrz\CustomFields\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Benchmarks on a large dataset. Excluded from the normal run:
 *
 *     PERF_RECORDS=100000 vendor/bin/phpunit --group performance
 *
 * Asserts results and query counts exactly. Timings are printed, and only
 * checked against generous budgets so the suite still means something on
 * slow CI machines.
 */
#[Group('performance')]
class LargeDatasetTest extends TestCase
{
    use DatabaseMigrations;

    private const FIELDS = [
        'tier' => 'select', 'amenities' => 'multiselect', 'age' => 'integer', 'price' => 'decimal',
        'notes' => 'text', 'arrival' => 'date', 'vip' => 'boolean', 'score' => 'number',
        'meta' => 'json', 'email' => 'email',
    ];

    private int $records;

    /** @var array<string, int> */
    private array $fieldIds = [];

    /** @var list<array{string, string}> */
    private static array $report = [];

    public static function tearDownAfterClass(): void
    {
        if (self::$report !== []) {
            fwrite(STDOUT, "\n\n".str_pad('Benchmark', 58).'Result'."\n".str_repeat('-', 90)."\n");
            foreach (self::$report as [$name, $result]) {
                fwrite(STDOUT, str_pad($name, 58).$result."\n");
            }
        }

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->records = (int) (getenv('PERF_RECORDS') ?: 10000);
        $this->seedDataset();
    }

    #[Test]
    public function eager_loading_every_record_uses_three_queries(): void
    {
        $limit = min($this->records, 20000);

        // Baseline: the same rows as plain Eloquent models, without the package in the way.
        [, , $baseMs, $baseMb] = $this->measure(fn () => [
            Model::unguarded(fn () => (new class extends Model
            {
                protected $table = 'reservations';
            })->newQuery()->limit($limit)->get()),
            CustomFieldValue::query()->where('entity_id', '<=', $limit)->get(),
        ]);
        $this->report('baseline: plain Eloquent, same rows', $baseMs, 2, $baseMb);

        [$reservations, $queries, $ms, $mb] = $this->measure(fn () => Reservation::withCustomFields()->limit($limit)->get());

        $this->assertCount($limit, $reservations);
        $this->assertSame(2, $queries, 'records + values (definitions load on first read)');
        $this->assertTrue($reservations->every(fn ($r) => $r->relationLoaded('customFieldValues')));
        $this->report("eager load {$limit} records x ".count(self::FIELDS).' fields', $ms, $queries, $mb);

        [$values, $queries, $ms] = $this->measure(fn () => $reservations->map(fn (Reservation $r) => $r->listCustomFields()));
        $this->assertSame(1, $queries, 'field definitions, once for the whole list');
        $this->assertCount(count(self::FIELDS), $values->first());
        $this->assertSame($this->expected(1), $values->first());
        $this->report("listCustomFields() on {$limit} records", $ms, $queries);

        [$tiers, $queries, $ms] = $this->measure(fn () => $reservations->map(fn (Reservation $r) => [$r->tier, $r->arrival, $r->age]));
        $this->assertSame(1, $queries, "the model's field keys, once for the whole list");
        $this->assertSame('2026-01-02', $tiers->first()[1]->toDateString());
        $this->report("attribute reads with casts, 3 x {$limit}", $ms, $queries);

        [$array, $queries, $ms] = $this->measure(fn () => $reservations->toArray());
        $this->assertSame(0, $queries);
        $this->assertSame('gold', $array[0]['tier']);
        $this->report("toArray() on {$limit} records", $ms, $queries);
    }

    #[Test]
    public function chunking_through_everything_keeps_queries_per_chunk_constant(): void
    {
        $seen = 0;
        $sum = 0;

        [, $queries, $ms, $mb] = $this->measure(function () use (&$seen, &$sum) {
            Reservation::withCustomFields()->chunkById(1000, function ($chunk) use (&$seen, &$sum) {
                foreach ($chunk as $reservation) {
                    $sum += $reservation->age;
                    $seen++;
                }
            });
        });

        $chunks = (int) ceil($this->records / 1000);
        $this->assertSame($this->records, $seen);
        $this->assertSame(array_sum(array_map(fn ($i) => 18 + $i % 60, range(1, $this->records))), $sum);
        // Records + values per chunk, chunkById's final empty page, definitions and field keys once.
        $this->assertLessThanOrEqual($chunks * 2 + 3, $queries);
        $this->report("chunkById(1000) over {$this->records} records", $ms, $queries, $mb);
    }

    #[Test]
    public function filters_return_exact_results_on_a_large_table(): void
    {
        $n = $this->records;
        $ids = range(1, $n);

        $cases = [
            'whereCustomField equality (select)' => [
                fn () => Reservation::whereCustomField('tier', 'gold'),
                count(array_filter($ids, fn ($i) => $i % 3 === 1)),
            ],
            'whereCustomField numeric range (integer)' => [
                fn () => Reservation::whereCustomField('age', '>=', 70),
                count(array_filter($ids, fn ($i) => 18 + $i % 60 >= 70)),
            ],
            'whereCustomField decimal > (numeric cast)' => [
                fn () => Reservation::whereCustomField('price', '>', '900.00'),
                count(array_filter($ids, fn ($i) => ($i % 1000) + 0.5 > 900)),
            ],
            'whereCustomField date >= (text order)' => [
                fn () => Reservation::whereCustomField('arrival', '>=', '2026-01-20'),
                count(array_filter($ids, fn ($i) => $i % 28 + 1 >= 20)),
            ],
            'whereCustomFieldIn (3 options)' => [
                fn () => Reservation::whereCustomFieldIn('tier', ['gold', 'silver']),
                count(array_filter($ids, fn ($i) => $i % 3 !== 0)),
            ],
            'whereCustomFieldContains list' => [
                fn () => Reservation::whereCustomFieldContains('amenities', 'pool'),
                count(array_filter($ids, fn ($i) => $i % 2 === 0)),
            ],
            'whereCustomFieldContains text (LIKE)' => [
                fn () => Reservation::whereCustomFieldContains('notes', 'note 7'),
                count(array_filter($ids, fn ($i) => str_contains("Note {$i}", 'Note 7'))),
            ],
            'whereCustomFieldNull' => [
                fn () => Reservation::whereCustomFieldNull('vip'),
                count(array_filter($ids, fn ($i) => $i % 5 === 0)),
            ],
            'two filters combined' => [
                fn () => Reservation::whereCustomField('tier', 'gold')->whereCustomField('vip', true),
                count(array_filter($ids, fn ($i) => $i % 3 === 1 && $i % 5 !== 0 && $i % 2 === 0)),
            ],
        ];

        CustomFields::fieldsFor(Reservation::class); // definitions load once per request; keep them out of the timings

        foreach ($cases as $name => [$query, $expected]) {
            [$count, $queries, $ms] = $this->measure(fn () => $query()->count());

            $this->assertSame($expected, $count, $name);
            $this->assertSame(1, $queries, $name);
            $this->assertLessThan(30000, $ms, "{$name} took {$ms} ms");
            $this->report("{$name} ({$count} of {$n})", $ms, $queries);
        }
    }

    #[Test]
    public function the_database_uses_the_indexes(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $this->markTestSkipped('Query plans are checked on MySQL and Postgres.');
        }

        $explain = function ($query) use ($driver): string {
            $sql = $query->toSql();
            $bindings = $query->getBindings();
            $rows = DB::select(($driver === 'pgsql' ? 'EXPLAIN ' : 'EXPLAIN FORMAT=TREE ').$sql, $bindings);

            return implode("\n", array_map(fn ($row) => implode(' ', (array) $row), $rows));
        };

        $plans = [
            // The query Reservation::withCustomFields() runs for 500 records.
            'eager load values' => CustomFieldValue::query()
                ->where('entity_type', (new Reservation)->getMorphClass())->whereIntegerInRaw('entity_id', range(1, 500)),
            'whereCustomField equality' => Reservation::whereCustomField('tier', 'gold'),
            'whereCustomField numeric range' => Reservation::whereCustomField('age', '>=', 70),
        ];

        foreach ($plans as $name => $query) {
            $plan = $explain($query);
            fwrite(STDOUT, "\n--- [{$driver}] {$name}\n{$plan}\n");

            // Eager loading must never scan the values table. Filters are printed, not asserted:
            // a planner may rightly prefer a scan when a filter matches a large share of rows.
            if ($name === 'eager load values') {
                $this->assertStringContainsString('custom_field_values_entity_type_entity_id_index', $plan);
                $this->assertDoesNotMatchRegularExpression('/Seq Scan on custom_field_values|Table scan on custom_field_values/', $plan);
            }
        }
    }

    #[Test]
    public function writing_values_through_the_package_scales_linearly(): void
    {
        $batch = min($this->records, 2000);
        $reservations = Reservation::query()->limit($batch)->get();

        [, $queries, $ms] = $this->measure(function () use ($reservations) {
            foreach ($reservations as $reservation) {
                $reservation->syncCustomFields(['tier' => 'silver', 'age' => 33, 'notes' => 'updated']);
            }
        });

        // Per record: one read of the existing rows, then one update per value that changed.
        // Unchanged values (already silver, already 33) are skipped. Plus definitions once.
        $changed = array_sum(array_map(
            fn ($i) => ($this->expected($i)['tier'] !== 'silver') + ($this->expected($i)['age'] !== 33) + 1,
            range(1, $batch),
        ));
        $this->assertSame($batch + $changed + 1, $queries);
        $this->assertSame($batch, Reservation::whereCustomField('notes', 'updated')->count());
        $this->report("syncCustomFields() 3 fields on {$batch} records", $ms, null, null, perRecord: $batch);

        // The same writes inside one transaction: one durable commit instead of one per record.
        [, , $ms] = $this->measure(fn () => DB::transaction(function () use ($reservations) {
            foreach ($reservations as $reservation) {
                $reservation->syncCustomFields(['tier' => 'gold', 'age' => 44, 'notes' => 'bulk']);
            }
        }));

        $this->assertSame($batch, Reservation::whereCustomField('notes', 'bulk')->count());
        $this->report('same, inside one DB::transaction()', $ms, null, null, perRecord: $batch);
    }

    private function seedDataset(): void
    {
        foreach (self::FIELDS as $key => $type) {
            $settings = match ($type) {
                'select' => ['options' => ['gold' => 'Gold', 'silver' => 'Silver', 'bronze' => 'Bronze']],
                'multiselect' => ['options' => ['pool' => 'Pool', 'wifi' => 'Wi-Fi', 'parking' => 'Parking']],
                default => [],
            };
            $this->fieldIds[$key] = CustomFields::create(['model' => Reservation::class, 'key' => $key, 'label' => $key, 'type' => $type, 'settings' => $settings])->id;
        }

        $morph = (new Reservation)->getMorphClass();

        foreach (array_chunk(range(1, $this->records), 1000) as $chunk) {
            DB::table('reservations')->insert(array_map(fn ($i) => ['id' => $i, 'code' => "R{$i}"], $chunk));

            $rows = [];
            foreach ($chunk as $i) {
                foreach ($this->expected($i) as $key => $value) {
                    $rows[] = [
                        'custom_field_id' => $this->fieldIds[$key],
                        'entity_type' => $morph,
                        'entity_id' => $i,
                        'value' => $value === null ? null : json_encode($value),
                    ];
                }
            }

            foreach (array_chunk($rows, 2000) as $part) {
                DB::table('custom_field_values')->insert($part);
            }
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("select setval('reservations_id_seq', (select max(id) from reservations))");
            DB::statement('analyze');
        } elseif (DB::getDriverName() === 'mysql') {
            DB::statement('analyze table reservations, custom_field_values');
        }

        CustomFields::flush();
    }

    /**
     * The values seeded for record $i, as getCustomField() returns them.
     *
     * @return array<string, mixed>
     */
    private function expected(int $i): array
    {
        return [
            'tier' => ['bronze', 'gold', 'silver'][$i % 3],
            'amenities' => $i % 2 === 0 ? ['pool', 'wifi'] : ['parking'],
            'age' => 18 + $i % 60,
            'price' => number_format(($i % 1000) + 0.5, 2, '.', ''),
            'notes' => "Note {$i}",
            'arrival' => sprintf('2026-01-%02d', $i % 28 + 1),
            'vip' => $i % 5 === 0 ? null : $i % 2 === 0,
            'score' => $i % 7 + 0.25,
            'meta' => ['source' => $i % 2 ? 'web' : 'phone'],
            'email' => "guest{$i}@example.com",
        ];
    }

    /**
     * @return array{mixed, int, float, float}
     */
    private function measure(callable $callback): array
    {
        gc_collect_cycles();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $memory = memory_get_usage();
        $start = hrtime(true);

        $result = $callback();

        $ms = round((hrtime(true) - $start) / 1e6, 1);
        $mb = round((memory_get_usage() - $memory) / 1048576, 1);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$result, $queries, $ms, $mb];
    }

    private function report(string $name, float $ms, ?int $queries, ?float $mb = null, ?int $perRecord = null): void
    {
        $parts = [sprintf('%9.1f ms', $ms)];
        $parts[] = $queries === null ? '' : "{$queries} queries";
        if ($mb !== null) {
            $parts[] = "{$mb} MB";
        }
        if ($perRecord !== null) {
            $parts[] = sprintf('%.2f ms/record', $ms / $perRecord);
        }

        self::$report[] = ['['.DB::getDriverName().'] '.$name, implode('  ', array_filter($parts))];
    }
}
