# Testing

## Test your app

Custom fields are normal rows in the database. Create them in your test, like any other data:

```php
use Illuminate\Foundation\Testing\RefreshDatabase;
use KenPrz\CustomFields\Facades\CustomFields;

class ReservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_vip_reservations_can_be_filtered(): void
    {
        CustomFields::create([
            'model' => Reservation::class,
            'key' => 'tier',
            'label' => 'Tier',
            'type' => 'select',
            'settings' => ['options' => ['vip' => 'VIP', 'standard' => 'Standard']],
        ]);

        Reservation::factory()->create(['tier' => 'vip']);
        Reservation::factory()->create(['tier' => 'standard']);

        $this->assertCount(1, Reservation::whereCustomField('tier', 'vip')->get());
    }
}
```

- Factories can set custom fields like attributes.
- `save()` doesn't enforce required fields, so your factories keep working when an admin adds
  a required field.
- To fake events, fake only the package events. See [Events](/advanced/events#testing).

## Test the package

```bash
composer test      # PHPUnit
composer analyse   # Larastan, level 8
composer lint      # Pint
composer bench     # large-dataset benchmarks
```

The suite runs on in-memory SQLite by default. To run it on MySQL or PostgreSQL, set the usual
`DB_*` variables:

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=testing \
DB_USERNAME=postgres DB_PASSWORD=secret composer test
```

### Mutation testing

Mutation testing uses [Infection](https://infection.github.io). It isn't a dev dependency,
because it needs a newer `symfony/console` than the oldest supported Laravel 12. Install it when
you want it. It needs pcov or Xdebug:

```bash
composer require --dev infection/infection
vendor/bin/infection --threads=max
```

## What CI checks

On every push:

- the full suite on PHP 8.3–8.5 × Laravel 12/13, with lowest and newest dependencies
- the full suite and the benchmarks on MySQL 8.4 and PostgreSQL 17
- 100% line coverage across the three databases
- mutation testing: Infection makes about 1,000 small changes to the code, and the tests must
  catch at least 99% of them
