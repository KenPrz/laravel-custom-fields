# Field types

| Type | Accepts | Stored and returned as | Settings |
|---|---|---|---|
| `text` | string | string | `max_length` (255) |
| `textarea` | string | string | `max_length` (65535) |
| `email` | email address | string | `max_length` (255) |
| `url` | absolute URL | string | `max_length` (2048) |
| `phone` | digits, spaces, `( ) . -`, leading `+` | trimmed string | |
| `number` | int, float, numeric string | int or float | |
| `integer` | int or integer string | int | |
| `decimal` | number with at most `places` decimals | string padded to `places`, for example `"12.50"` | `places` (2) |
| `boolean` | `true`, `false`, `1`, `0`, `"1"`, `"0"` | bool | |
| `date` | anything Laravel's `date` rule accepts | `"Y-m-d"` | |
| `datetime` | anything Laravel's `date` rule accepts | UTC ISO 8601, for example `"2026-11-09T14:30:00Z"` | |
| `time` | `"H:i"` or `"H:i:s"` | `"H:i:s"` | |
| `select` / `radio` | one option key | string | `options` (required) |
| `multiselect` / `checkbox` | a list of option keys | list of strings, duplicates removed | `options` (required) |
| `json` | array, int, float, bool, or a string of JSON text | decoded value | |

## Settings

Pass settings when you create the field. They merge over the type's defaults.

```php
CustomFields::create([
    'model' => Reservation::class,
    'key' => 'deposit',
    'label' => 'Deposit',
    'type' => 'decimal',
    'settings' => ['places' => 2],
]);

CustomFields::create([
    'model' => Reservation::class,
    'key' => 'amenities',
    'label' => 'Amenities',
    'type' => 'multiselect',
    'settings' => ['options' => ['parking' => 'Parking', 'breakfast' => 'Breakfast']],
]);
```

## Notes

- **Options** are a map of stored value to label: `['vip' => 'VIP', 'walk_in' => 'Walk-in']`.
  The stored value is always the key, as a string.
- **Decimal** values are stored as strings, so money keeps its exact value. They still compare
  as numbers in queries.
- **Datetime** values without a timezone are read in your app timezone, then converted to UTC.
- **JSON** fields treat strings as JSON text. `'{"a":1}'` is stored as `['a' => 1]`, and
  `'hello'` is rejected. To store a plain string, use a text field.
- **Phone** checks only the characters. It doesn't know real numbering plans. For that,
  [register your own type](/advanced/custom-field-types) backed by a library such as
  libphonenumber.
