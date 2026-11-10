# Installation

## Requirements

- PHP 8.3 or newer
- Laravel 12.6 or newer, or Laravel 13
- MySQL 8, PostgreSQL or SQLite. See [Database compatibility](/reference/databases).

## Install

```bash
composer require kenprz/laravel-custom-fields
php artisan migrate
```

Package discovery registers the service provider. If you turned discovery off, add the provider
to `bootstrap/providers.php`:

```php
return [
    App\Providers\AppServiceProvider::class,
    KenPrz\CustomFields\CustomFieldsServiceProvider::class,
];
```

## The migration

`php artisan migrate` runs the package migration. It creates two tables:

| Table | Holds |
|---|---|
| `custom_fields` | Field definitions |
| `custom_field_values` | One JSON value per field per model record |

The values table has a foreign key to the fields table. It also has a unique index on
(field, record), so a record can never have two values for one field.

### Rename the tables

Publish the config and change `tables` **before** you migrate:

```bash
php artisan vendor:publish --tag=custom-fields-config
```

### Edit the migration

1. Publish the migration:

   ```bash
   php artisan vendor:publish --tag=custom-fields-migrations
   ```

2. Stop the package copy from running too:

   ```php
   // config/custom-fields.php
   'run_migrations' => false,
   ```

### Key types

- **Scopes (tenants)** can have any primary key: integers, UUIDs, ULIDs or strings such as
  slugs. `scope_id` is a string column.
- **Records with custom fields** use Laravel's default morph key type (big integers). If those
  models use UUIDs or ULIDs, call `Schema::morphUsingUuids()` or `Schema::morphUsingUlids()` in
  a service provider's `register()` method before you migrate.

::: danger
Rolling back the migration drops both tables and every stored value.
:::
