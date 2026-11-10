# Reading and writing values

## Write values

The usual way is through attributes:

```php
$user->tax_bracket = 'b';
$user->save();

$user->update(['tax_bracket' => 'b']);
User::create(['name' => 'Ken', 'tax_bracket' => 'b']);
```

To write values without saving the model, use the explicit methods:

```php
$user->setCustomField('tax_bracket', 'b');

$user->syncCustomFields([
    'tax_bracket' => 'b',
    'nickname' => 'Ken',
]);

$user->forgetCustomField('nickname');
```

The explicit methods need a saved model. On a new model they throw `CustomFieldException`.
Attribute assignment works on new models too.

### Write rules

Both ways follow the same rules:

- The package validates every value before it writes anything. If one value fails, nothing is
  saved, and a `ValidationException` lists every error at once.
- The field type normalizes each value before it is stored. `'30'` on an integer field is
  stored as `30`.
- An empty string is stored as `null`, like Laravel's `ConvertEmptyStringsToNull` middleware
  does for requests. An empty array is stored as `[]`.
- A value that equals the stored one doesn't touch the database or fire an event.
- If two requests write the first value for the same record and field at the same time, the
  unique index catches the second insert, and it becomes an update. The last write wins.

## Read values

```php
$user->tax_bracket;                       // cast value, null when not set
$user->listCustomFields();                // ['tax_bracket' => 'b', ...], as stored
$user->getCustomField('birthday');        // "1990-05-01", as stored
$user->getCustomField('missing', 'n/a');  // "n/a"
$user->hasCustomField('tax_bracket');     // true
```

Stored values are in the normalized form of their type. For example, a date is `"2026-11-09"`
and a decimal is `"12.50"`. Attribute access applies casts on top of that.

- **Inactive fields** still return their stored values.
- **Soft-deleted fields** are hidden until you restore them.

## Eager loading

Eager load values when you show a list:

```php
$users = User::withCustomFields()->get();   // or $loadCustomFields = true on the model

foreach ($users as $user) {
    $user->tax_bracket;   // no extra query per record
}
```

This takes three queries in total, for any number of records:

1. the records
2. their values
3. the field definitions (loaded once per model and scope per request)

If the model sets `$loadCustomFields = true`, use `withoutCustomFields()` to skip the values
for one query:

```php
User::withoutCustomFields()->pluck('email');
```
