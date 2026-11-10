# Querying

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

The scopes combine with normal Eloquent queries:

```php
Reservation::where('property_id', $hotel->id)
    ->whereCustomField('guest_classification', 'vip')
    ->whereCustomField('guest_age', '>=', 18)
    ->withCustomFields()
    ->paginate(50);
```

## How values compare

- The package validates and normalizes the comparison value with the field type first. `'30'`
  finds an integer field that holds `30`. A value that doesn't fit the type throws a
  `ValidationException`.
- `number`, `integer` and `decimal` fields compare as numbers.
- `date`, `datetime` and `time` fields compare in time order.
- Other strings compare byte by byte on every database. In byte order, uppercase letters come
  before lowercase letters.
- `=` and `!=` on other types compare the whole stored value. So they work for booleans, lists
  and JSON too.
- `!=` and the comparison operators skip records that have no value for the field.
- Ordering operators (`>`, `<` and so on) on booleans, lists or JSON throw
  `InvalidArgumentException`.

## Scope

Queries can't use a record's `customFieldScope()`, because no single record is known yet. They
use the configured [scope resolver](/advanced/multi-tenancy#scope-resolver). With no resolver,
queries see global fields only.

`CustomFields::forScope()` returns a copy of the manager, so calling it doesn't change the scope
of queries. To query one tenant's fields without a resolver, bind a scoped manager for the
request:

```php
use KenPrz\CustomFields\CustomFieldManager;

app()->instance(CustomFieldManager::class, CustomFields::forScope($hotel));

Reservation::whereCustomField('vip_reference', 'A-100')->get();
```

## Security

The package checks operators against a fixed list. Every value goes to the database as a query
binding. Values never become part of the SQL.

## Performance

Each filter is a `WHERE EXISTS` subquery on the values table. The subquery uses the unique
(field, entity type, entity id) index, so equality filters on tables of normal size are fast.

Comparisons that cast the stored JSON (numbers, dates, text order, substring search) can't use
an index on the value. They scan every value of that field. If you need fast sorting or range
filters on one field across very large tables, use a real column for it.

See [Performance](/advanced/performance) for measured numbers.
