# Laravel Custom Fields

User-defined custom fields for Eloquent models. Add a trait to a model, define fields at
runtime, and store validated values, without adding columns to the model's table.

```php
CustomFields::create([
    'model' => Reservation::class,
    'key' => 'guest_classification',
    'label' => 'Guest Classification',
    'type' => 'select',
    'settings' => ['options' => ['vip' => 'VIP', 'corporate' => 'Corporate']],
]);

$reservation->setCustomField('guest_classification', 'vip');
$reservation->getCustomField('guest_classification'); // "vip"

Reservation::whereCustomField('guest_classification', 'vip')->get();
```

## Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Migrations](#migrations)
- [Model integration](#model-integration)
- [Creating and managing fields](#creating-and-managing-fields)
- [Field types](#field-types)
- [Assigning values](#assigning-values)
- [Retrieving values](#retrieving-values)
- [Validation](#validation)
- [Multi-tenancy and scopes](#multi-tenancy-and-scopes)
- [Querying](#querying)
- [Custom field types](#custom-field-types)
- [Events](#events)
- [Caching](#caching)
- [Database compatibility](#database-compatibility)
- [Testing](#testing)
- [Limitations](#limitations)
- [Upgrading](#upgrading)
- [Example: a form request and controller](#example-a-form-request-and-controller)

## Features

- Two tables (`custom_fields`, `custom_field_values`). Your tables are never changed.
- 17 built-in field types, plus your own.
- Every write goes through Laravel's validator: type rules, select options, and any rules
  you attach to the field.
- Global fields and per-tenant (scoped) fields, with tenant isolation on every read and write.
- Query scopes for filtering by custom field values on MySQL, PostgreSQL and SQLite.
- Events for every field and value change.
- Optional caching of field definitions.

## Requirements

- PHP 8.3 or newer
- Laravel 12 or 13
- MySQL 8, PostgreSQL or SQLite. See [Database compatibility](#database-compatibility).

## Installation

```bash
composer require kenprz/laravel-custom-fields
php artisan migrate
```

The service provider registers itself through package discovery. If you turned discovery off,
add `KenPrz\CustomFields\CustomFieldsServiceProvider::class` to `bootstrap/providers.php`.

## Configuration

Publish the config file to change the defaults:

```bash
php artisan vendor:publish --tag=custom-fields-config
```

| Key | Default | What it does |
|---|---|---|
| `tables.fields` | `custom_fields` | Name of the field definitions table. Set before migrating. |
| `tables.values` | `custom_field_values` | Name of the values table. Set before migrating. |
| `models.field` | `CustomField::class` | Field model. Your class must extend the package model. |
| `models.value` | `CustomFieldValue::class` | Value model. Your class must extend the package model. |
| `run_migrations` | `true` | Run the package migration with `php artisan migrate`. |
| `strict` | `true` | Throw on unknown field keys. See [Validation](#validation). |
| `scope_resolver` | `null` | Class that returns the current tenant. See [Multi-tenancy](#multi-tenancy-and-scopes). |
| `delete_values_with_model` | `true` | Delete a model's values when the model is deleted. |
| `events` | `true` | Dispatch the package [events](#events). |
| `cache.enabled` | `false` | Cache field definitions across requests. |
| `cache.store` | `null` | Cache store name. `null` uses the default store. |
| `cache.ttl` | `3600` | Seconds to keep cached definitions. |

## Migrations

The package migration runs with your normal `php artisan migrate`. It creates two tables:

| Table | Holds |
|---|---|
| `custom_fields` | Field definitions: model, scope, key, label, type, settings, rules, flags, order |
| `custom_field_values` | One JSON value per field per model record |

The values table has a foreign key to the fields table and a unique index on
(field, record), so a record can never have two values for one field.

**To rename the tables**, publish the config and change `tables` before you migrate.

**To edit the migration**, publish it and stop the package copy from running too:

```bash
php artisan vendor:publish --tag=custom-fields-migrations
```

```php
// config/custom-fields.php
'run_migrations' => false,
```

**UUID or ULID keys.** `scope_id` and `entity_id` use Laravel's default morph key type
(big integers). If your models use UUIDs or ULIDs, call `Schema::morphUsingUuids()` or
`Schema::morphUsingUlids()` in a service provider's `register()` method before migrating.

**Rolling back** drops both tables and every stored value.

## Model integration

Add the trait to any Eloquent model:

```php
use Illuminate\Database\Eloquent\Model;
use KenPrz\CustomFields\Traits\HasCustomFields;

class Reservation extends Model
{
    use HasCustomFields;
}
```

The trait adds:

| Method | Returns | Notes |
|---|---|---|
| `customFields()` | `Collection<string, CustomField>` | Visible field definitions, active or not, keyed by key, in `sort_order` |
| `customFieldValues()` | `MorphMany` | Raw value rows. Eager load this. |
| `getCustomField($key, $default = null)` | `mixed` | Stored value, or `$default` when nothing or null is stored |
| `getCustomFieldValues()` | `array<string, mixed>` | Every stored value keyed by field key, in field order. Includes stored nulls. |
| `hasCustomField($key)` | `bool` | True when a non-null value is stored. Never throws. |
| `setCustomField($key, $value)` | `static` | Validate and store one value |
| `syncCustomFields(array $values)` | `static` | Validate and store several values. Only the given keys change. |
| `forgetCustomField($key)` | `static` | Delete one stored value |
| `customFieldScope()` | `?Model` | The tenant this record's fields come from. Override it. |

It also adds query scopes: `withCustomFields()`, `whereCustomField()`, `whereCustomFieldIn()`,
`whereCustomFieldContains()`, `whereCustomFieldNull()` and `whereCustomFieldNotNull()`.

Values can only be written through these methods. Mass assignment (`create()`, `fill()`)
never touches custom fields.

**Deleting a record** deletes its stored values. For models using `SoftDeletes`, values are
kept until the model is force deleted, so a restore brings them back. This is a bulk delete,
so it doesn't fire value events. Set `delete_values_with_model` to `false` to clean up yourself.

## Creating and managing fields

Field definitions are managed through the `CustomFields` facade.

```php
use KenPrz\CustomFields\Facades\CustomFields;

$field = CustomFields::create([
    'model' => Reservation::class,       // required: model class or morph alias
    'key' => 'guest_age',                // required: a-z, 0-9 and _, starting with a letter, max 64
    'label' => 'Guest age',              // required
    'type' => 'integer',                 // required: a registered type name
    'description' => 'Age at check-in',  // optional
    'settings' => [],                    // optional: merged over the type's defaults
    'validation_rules' => ['min:18'],    // optional: extra Laravel rules
    'is_required' => true,               // optional, default false
    'is_active' => true,                 // optional, default true
    'is_searchable' => false,            // optional, stored for your own use
    'is_filterable' => false,            // optional, stored for your own use
    'sort_order' => 0,                   // optional, default 0
]);
```

Invalid definitions throw a `ValidationException`, so in a controller they come back as a
normal 422 response. Unknown attributes, unknown types, unknown validation rules, bad
settings and duplicate keys are all rejected.

| Method | Returns | Notes |
|---|---|---|
| `create(array $attributes)` | `CustomField` | Creates the field in the current scope |
| `update(CustomField $field, array $attributes)` | `CustomField` | Same attributes as create, except `model` |
| `find($id)` | `?CustomField` | Null when missing, deleted or in another scope |
| `findByKey($model, $key)` | `?CustomField` | The field the current scope sees for that key |
| `fieldsFor($model)` | `Collection<string, CustomField>` | Every visible field, active or not, keyed by key |
| `activate(CustomField $field)` | `CustomField` | The field accepts values again |
| `deactivate(CustomField $field)` | `CustomField` | Rejects new values. Stored values stay readable. |
| `reorder($model, array $keys)` | `void` | Listed keys first, in order. Others keep their order after them. |
| `delete(CustomField $field)` | `void` | Soft delete. Values are kept. |
| `restore(CustomField $field)` | `CustomField` | Undo a soft delete |
| `forceDelete(CustomField $field)` | `void` | Deletes the field **and all its values** |
| `rules($model, bool $partial = false)` | `array` | Validation rules keyed by field key |
| `validate($model, array $input, bool $partial = false)` | `array` | Validated, normalized values |
| `registerType($name, $type)` | `void` | See [Custom field types](#custom-field-types) |
| `forScope(?Model $scope)` / `global()` | `CustomFieldManager` | See [Multi-tenancy](#multi-tenancy-and-scopes) |
| `flush()` | `void` | Forget loaded and cached definitions |

**Keys stay reserved.** A soft-deleted field keeps its key, so you can restore it later.
You can't create a new field with the same key until the old one is force deleted.

**Changing a field's type** is only allowed while the field has no stored values. Once values
exist, the update is rejected with a validation error on `type`. To change the type, create a
new field, copy the values over in your own code, then delete the old field. This rule exists
so that a type change can never silently corrupt stored data.

**Changing settings or rules** is allowed at any time and only affects new writes. For example,
if you remove a select option, records that already hold it keep it.

## Field types

| Type | Accepts | Stored and returned as | Settings |
|---|---|---|---|
| `text` | string | string | `max_length` (255) |
| `textarea` | string | string | `max_length` (65535) |
| `email` | email address | string | `max_length` (255) |
| `url` | absolute URL | string | `max_length` (2048) |
| `phone` | digits, spaces, `( ) . -`, leading `+` | trimmed string | |
| `number` | int, float, numeric string | int or float | |
| `integer` | int or integer string | int | |
| `decimal` | number with at most `places` decimals | string padded to `places`, e.g. `"12.50"` | `places` (2) |
| `boolean` | `true`, `false`, `1`, `0`, `"1"`, `"0"` | bool | |
| `date` | anything Laravel's `date` rule accepts | `"Y-m-d"` | |
| `datetime` | anything Laravel's `date` rule accepts | UTC ISO 8601, e.g. `"2026-11-09T14:30:00Z"` | |
| `time` | `"H:i"` or `"H:i:s"` | `"H:i:s"` | |
| `select` / `radio` | one option key | string | `options` (required) |
| `multiselect` / `checkbox` | a list of option keys | list of strings, duplicates removed | `options` (required) |
| `json` | array, int, float, bool, or a string of JSON text | decoded value | |

Notes:

- `options` is a map of stored value to label: `['vip' => 'VIP', 'walk_in' => 'Walk-in']`.
  The stored value is always the key, as a string.
- `decimal` is stored as a string so money keeps its exact value. It still compares
  numerically in queries.
- A `datetime` without a timezone is read in your app timezone, then converted to UTC.
- `json` treats strings as JSON text: `'{"a":1}'` is stored as `['a' => 1]`, and `'hello'`
  is rejected. To store a plain string, use a text field.
- `phone` only checks the characters used. It doesn't know real numbering plans. For that,
  register your own type backed by a library such as libphonenumber.

## Assigning values

```php
$reservation->setCustomField('guest_age', 30);

$reservation->syncCustomFields([
    'guest_classification' => 'vip',
    'internal_reference' => 'REF-001',
]);

$reservation->forgetCustomField('internal_reference');
```

- The record must be saved first. Writing to an unsaved model throws `CustomFieldException`.
- `syncCustomFields()` only changes the keys you pass. Other stored values are left alone.
- Every value is validated before anything is written. If one value fails, nothing is saved
  and a `ValidationException` lists every error at once.
- All writes in one call run in a single database transaction.
- Values are normalized by their type before they're stored, so `'30'` on an integer field is
  stored as `30`.
- An empty string is stored as `null`, the same as Laravel's `ConvertEmptyStringsToNull`
  middleware does for requests. An empty array is stored as `[]`.
- Writing a value that equals the stored one doesn't touch the database or fire an event.
- If two requests write the first value for the same record and field at the same time, the
  unique index catches the second insert and it becomes an update. The last write wins.

## Retrieving values

```php
$reservation->getCustomField('guest_age');        // 30
$reservation->getCustomField('missing', 'n/a');   // "n/a"
$reservation->getCustomFieldValues();             // ['guest_age' => 30, ...]
$reservation->hasCustomField('guest_age');        // true
```

Values come back in the normalized form the type stores, not as objects. A date is
`"2026-11-09"`, not a Carbon instance. A decimal is `"12.50"`, not a float.

Values are read from the `customFieldValues` relation, so eager load it for lists:

```php
$reservations = Reservation::withCustomFields()->get();

foreach ($reservations as $reservation) {
    $reservation->getCustomFieldValues(); // no extra queries per record
}
```

That's three queries in total, however many records there are: the records, their values,
and the field definitions (loaded once per model and scope per request).

**Inactive fields** still return their stored values. **Soft-deleted fields** are hidden
until restored.

## Validation

Each value is checked against, in order:

1. `required` (when `is_required`) or `nullable`
2. The type's rules, such as `integer` or the select options
3. The field's own `validation_rules`

`validation_rules` takes any rule Laravel understands as a string: built-in rules
(`'min:18'`, `'regex:/^[A-Z]+$/'`, `'exists:rooms,number'`), rules registered with
`Validator::extend()`, and the class name of a rule object that implements
`Illuminate\Contracts\Validation\ValidationRule`. Unknown rules are rejected when the field is
saved, not when a value is written.

Validation errors are a standard `ValidationException`, keyed by field key, using field labels:

```
guest_age: The Guest age field must be at least 18.
```

Other checks:

- **Unknown keys.** With `strict` on (the default), writing an unknown key fails validation,
  and reading or querying one throws `CustomFieldException`. With `strict` off, unknown keys
  are ignored on write, read as the default, and match nothing in queries.
- **Inactive fields** reject writes, including `forgetCustomField()`.
- **Required fields** reject `null`, `''`, `[]` and `forgetCustomField()`.
- **Partial updates.** `syncCustomFields()` only checks the keys you pass, so a required field
  you didn't pass isn't reported as missing. To demand every required field, for example on a
  create form, validate the full input first:

```php
$values = CustomFields::validate(Reservation::class, $request->input('custom_fields', []));
```

Treat field definitions as configuration, not user input: anyone who can edit a field's
`validation_rules` can add rules such as `exists:` that query your database. Put field
management behind your own authorization (policies, gates or middleware).

## Multi-tenancy and scopes

A field can be **global** (every record of the model sees it) or **scoped** to one model
instance: a tenant, property, organization, account or anything else. The package doesn't
assume any tenant column.

```php
// Only reservations of $hotel see this field.
CustomFields::forScope($hotel)->create([
    'model' => Reservation::class,
    'key' => 'vip_reference',
    'label' => 'VIP Reference',
    'type' => 'text',
]);
```

Rules:

- With no scope, only global fields are visible.
- With a scope, global fields plus that scope's fields are visible.
- If a scoped field and a global field share a key, the scoped one wins inside that scope.
- Fields and values of other scopes are never returned, and their fields can't be updated,
  deactivated or deleted from another scope (`CustomFieldException`).
- Two scopes can use the same key independently.

**Where the scope comes from**, in order:

1. `CustomFields::forScope($scope)`, or `CustomFields::global()` for global only.
2. Otherwise, the configured `scope_resolver`.
3. Otherwise, no scope.

A resolver connects your existing tenancy setup:

```php
use Illuminate\Database\Eloquent\Model;
use KenPrz\CustomFields\Contracts\ScopeResolver;

class CurrentTenant implements ScopeResolver
{
    public function resolve(): ?Model
    {
        return auth()->user()?->team;
    }
}
```

```php
// config/custom-fields.php
'scope_resolver' => App\CustomFields\CurrentTenant::class,
```

**Per-record scope.** When a record knows its own tenant, override `customFieldScope()`. Reads
and writes on that record then use it:

```php
class Reservation extends Model
{
    use HasCustomFields;

    public function customFieldScope(): ?Model
    {
        return $this->property;
    }
}
```

Queries (`whereCustomField()` and friends) can't use `customFieldScope()`, because no single
record is known yet. They use the `forScope()` scope or the resolver instead.

## Querying

```php
Reservation::whereCustomField('guest_classification', 'vip');      // equals
Reservation::whereCustomField('guest_age', '>=', 18);              // = != <> > >= < <=
Reservation::whereCustomFieldIn('guest_classification', ['vip', 'corporate']);
Reservation::whereCustomFieldContains('amenities', 'parking');     // list has the option
Reservation::whereCustomFieldContains('notes', 'late arrival');    // substring, case-insensitive
Reservation::whereCustomFieldNull('notes');                        // missing or null
Reservation::whereCustomFieldNotNull('notes');
Reservation::whereCustomField('notes', null);                      // same as whereCustomFieldNull
```

How values compare:

- The comparison value is validated and normalized with the field type first. `'30'` finds
  an integer field holding `30`, and a value that doesn't fit the type throws a
  `ValidationException`.
- `number`, `integer` and `decimal` fields compare numerically.
- `date`, `datetime` and `time` fields compare in time order.
- Other strings compare byte by byte on every database. In byte order uppercase letters
  come before lowercase ones.
- `=` and `!=` on other types compare the whole stored value, so they work for booleans,
  lists and JSON too.
- `!=` and the comparison operators skip records that have no value for the field.
- Ordering operators (`>`, `<` and so on) on booleans, lists or JSON throw
  `InvalidArgumentException`.

**Security.** Operators are checked against a fixed list, and every value is passed as a
query binding. Values never become part of the SQL.

**Performance.** Each filter is a `WHERE EXISTS` subquery on the values table. The subquery
uses the unique (field, entity type, entity id) index, so equality filters on reasonably sized
tables are fine. Comparisons that cast the stored JSON (numbers, dates, text ordering,
substring search) can't use an index on the value itself, so they scan every value of that
field. If you need fast sorting or range filters on one field across very large tables,
consider a real column for it.

## Custom field types

Implement `KenPrz\CustomFields\Contracts\FieldType`, or extend `KenPrz\CustomFields\Types\Type`
for empty defaults:

```php
use KenPrz\CustomFields\Models\CustomField;
use KenPrz\CustomFields\Types\Type;

class CurrencyFieldType extends Type
{
    // Laravel rules for one value. required/nullable and the field's own rules are added for you.
    public function rules(CustomField $field): array
    {
        return ['string', 'size:3', 'alpha'];
    }

    // Turn a valid value into what gets stored (must be JSON encodable). Never receives null.
    public function normalize(mixed $value, CustomField $field): mixed
    {
        return strtoupper($value);
    }

    // Settings a new field starts with.
    public function defaultSettings(): array
    {
        return [];
    }

    // Rules for this type's settings, keyed by setting name.
    public function settingsRules(): array
    {
        return [];
    }
}
```

Register it in a service provider's `boot()` method:

```php
CustomFields::registerType('currency', CurrencyFieldType::class);
```

Registering a built-in name (such as `phone`) replaces the built-in type. Custom types compare
as text in queries. They compare numerically if they extend `NumberType`.

## Events

All events are in `KenPrz\CustomFields\Events` and are dispatched after the surrounding
database transaction commits. A rolled-back write fires nothing.

| Event | Properties |
|---|---|
| `CustomFieldCreated` | `field` |
| `CustomFieldUpdated` | `field` |
| `CustomFieldActivated` | `field` |
| `CustomFieldDeactivated` | `field` |
| `CustomFieldDeleted` | `field`, `forced` |
| `CustomFieldValueCreated` | `value`, `field`, `entity` |
| `CustomFieldValueUpdated` | `value`, `field`, `entity`, `oldValue` |
| `CustomFieldValueDeleted` | `value`, `field`, `entity` |

Events fire for changes made through the facade and the trait. Changes you make directly with
Eloquent or the query builder don't fire them. Turn all events off with `'events' => false`.

## Caching

Field definitions are loaded at most once per model and scope per request (or per queue job or
Octane request). To also cache them across requests, turn on the cache:

```php
'cache' => ['enabled' => true, 'store' => null, 'ttl' => 3600],
```

Saving, deleting or restoring a field through the package or through Eloquent clears the
cache for every model and scope. If you change the fields table some other way, such as raw
SQL or `DB::table()`, call `CustomFields::flush()`.

Values are never cached. They're always read from the database, so they can't go stale.
Redis isn't required: any Laravel cache store works.

## Database compatibility

| Database | Status |
|---|---|
| MySQL 8 | Supported. Tested on 8.4. |
| PostgreSQL | Supported. Tested on 17. Values are stored as `jsonb`. |
| SQLite | Supported. Tested on the version bundled with PHP. Needs the JSON functions (built in since 3.38). |
| MariaDB | Not supported. Reading and writing may work, but the query scopes throw. |
| SQL Server | Not supported. |

The test suite runs against all three supported databases in CI.

## Testing

```bash
composer test      # PHPUnit
composer analyse   # Larastan, level 8
composer lint      # Pint
```

The suite runs on in-memory SQLite by default. To run it against MySQL or PostgreSQL, set the
usual `DB_*` variables:

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=testing \
DB_USERNAME=postgres DB_PASSWORD=secret composer test
```

## Limitations

- **Type changes** are blocked once a field has values. See
  [Creating and managing fields](#creating-and-managing-fields).
- **Queries use the request scope**, not each record's `customFieldScope()`.
- **Large-scale filtering and sorting.** There's no index on the values themselves.
  See [Querying](#querying).
- **No sorting** by custom field values (`orderByCustomField`). Sorting on EAV data can't
  use an index on any database.
- **Object key order.** MySQL and PostgreSQL may reorder the keys of stored JSON objects.
- **Text search** in SQLite only ignores case for ASCII letters.
- **One connection.** The package tables use the default database connection. To move them,
  point `models.field` and `models.value` at your own subclasses that set `$connection`, and
  run the migration on that connection.
- **Changing the morph map** after values are stored orphans those values, because
  `model_type` and `entity_type` hold the old names.
- **Messages** for unknown and inactive fields are in English and aren't translatable yet.

## Upgrading

This is the first release. Breaking changes will only ship in a new major version, with steps
in [CHANGELOG.md](CHANGELOG.md).

## Example: a form request and controller

```php
use Illuminate\Foundation\Http\FormRequest;
use KenPrz\CustomFields\Facades\CustomFields;

class UpdateReservationRequest extends FormRequest
{
    public function rules(): array
    {
        $custom = collect(CustomFields::rules(Reservation::class, partial: true))
            ->mapWithKeys(fn ($rules, $key) => ["custom_fields.{$key}" => $rules]);

        return [
            'code' => ['required', 'string'],
            'custom_fields' => ['array'],
            ...$custom,
        ];
    }
}
```

```php
class ReservationController
{
    public function update(UpdateReservationRequest $request, Reservation $reservation)
    {
        DB::transaction(function () use ($request, $reservation) {
            $reservation->update($request->safe()->only('code'));
            $reservation->syncCustomFields($request->input('custom_fields', []));
        });

        return $reservation->load('customFieldValues');
    }
}
```

`syncCustomFields()` validates again on its own, so the form request rules are only there to
return every error in one response alongside your other fields.

## License

MIT. See [LICENSE](LICENSE).
