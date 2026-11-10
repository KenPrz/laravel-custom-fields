# Introduction

Laravel Custom Fields adds user-defined fields to Eloquent models. An admin defines a field at
runtime. Your code then reads and writes it like any other attribute. The package stores the
values in its own two tables, so your tables never change.

```php
$reservation->guest_classification;               // "vip"
$reservation->update(['guest_age' => 34]);
Reservation::whereCustomField('guest_age', '>=', 18)->get();
```

## Features

- Custom fields act like attributes: `$user->tax_bracket`, `fill()`, `create()`, `update()`,
  `toArray()`, with Eloquent casts. Real columns always win.
- Two tables (`custom_fields`, `custom_field_values`). Your tables never change.
- 17 built-in field types, plus your own.
- `php artisan make:custom-field`: a step-by-step form that writes a migration or creates the
  field right away.
- Every write goes through Laravel's validator: type rules, select options, and any rules
  you attach to the field.
- Global fields and per-tenant (scoped) fields, with tenant isolation on every read and write.
- Query scopes to filter by custom field values on MySQL, PostgreSQL and SQLite.
- Events for every field and value change.
- Optional caching of field definitions.

## When to use it

Use a custom field when the people who run the app, not the developers, decide which data to
keep. Examples:

- A hotel adds "VIP reference" to its reservations.
- A customer adds "Cost center" to invoices.
- A team adds "T-shirt size" to members.

Use a real column when your own code depends on the value, or when you must sort or index on
it across very large tables. See [Limitations](/reference/limitations).

## How it works

| Table | Holds |
|---|---|
| `custom_fields` | Field definitions: model, scope, key, label, type, settings, rules, flags, order |
| `custom_field_values` | One JSON value per field per model record |

The `HasCustomFields` trait connects a model to these tables. When you read
`$model->some_key`, the trait checks the model's own attributes first. If no attribute has that
name, the trait looks for a custom field with that key.

## Next steps

1. [Install the package](/guide/installation).
2. [Add the trait to a model](/guide/model-integration).
3. [Create your first field](/guide/managing-fields).
