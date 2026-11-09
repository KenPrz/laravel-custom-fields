# Laravel Custom Fields

User-defined custom fields for Eloquent models. Add a trait to a model, define fields at runtime, and store values without changing the model's table.

> Work in progress. Not ready for production use yet.

## Requirements

- PHP 8.3+
- Laravel 12 or 13

## Installation

```bash
composer require kenprz/laravel-custom-fields
```

Publish the config:

```bash
php artisan vendor:publish --tag=custom-fields-config
```

## Migrations

The package migrations run with your normal `php artisan migrate`. They create two
tables and never touch your existing tables:

| Table | Holds |
|---|---|
| `custom_fields` | Field definitions: which model, which scope, key, label, type, settings |
| `custom_field_values` | One JSON value per field per model record |

To rename the tables, publish the config and change `tables` **before** you migrate:

```bash
php artisan vendor:publish --tag=custom-fields-config
```

To edit the migration itself, publish it and turn off the package copy so it doesn't run twice:

```bash
php artisan vendor:publish --tag=custom-fields-migrations
```

```php
// config/custom-fields.php
'run_migrations' => false,
```

### Key types

`scope_id` and `entity_id` use Laravel's default morph key type (big integers). If your
models use UUID or ULID keys, call `Schema::morphUsingUuids()` or `Schema::morphUsingUlids()`
before the migration runs, for example in a service provider's `register()` method.

### Deleting fields

- **Soft delete** (`$field->delete()`): the field is hidden and its values are kept. The key
  stays reserved, so you can restore the field later. You can't create a new field with the
  same key until the old one is force deleted.
- **Force delete** (`$field->forceDelete()`): the field **and all its stored values** are removed.

### Stored values

Values are JSON encoded, so strings, integers, floats, booleans, arrays and `null` come back
with the same PHP type. MySQL and Postgres may reorder the keys of stored objects, so don't
rely on key order.

## Testing

```bash
composer test
composer analyse
composer lint
```

The suite runs on in-memory SQLite by default. To run it against MySQL or Postgres, set the
usual `DB_*` variables:

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=testing \
DB_USERNAME=postgres DB_PASSWORD=secret composer test
```

## License

MIT. See [LICENSE](LICENSE).
