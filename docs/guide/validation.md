# Validation

The package checks each value against these rules, in this order:

1. `required` (when `is_required` is true) or `nullable`
2. The type's rules, such as `integer` or the select options
3. The field's own `validation_rules`

## Field rules

`validation_rules` takes any rule that Laravel understands as a string:

- built-in rules: `'min:18'`, `'regex:/^[A-Z]+$/'`, `'exists:rooms,number'`
- rules registered with `Validator::extend()`
- the class name of a rule object that implements
  `Illuminate\Contracts\Validation\ValidationRule`

```php
CustomFields::create([
    'model' => Reservation::class,
    'key' => 'room_number',
    'label' => 'Room number',
    'type' => 'text',
    'validation_rules' => ['exists:rooms,number', App\Rules\NotUnderMaintenance::class],
]);
```

The package rejects unknown rules when you save the field, not when you write a value.

## Errors

Validation errors are a standard `ValidationException`. The errors are keyed by field key and
use the field labels:

```
guest_age: The Guest age field must be at least 18.
```

## Other checks

### Unknown keys

- With `strict` on (the default), writing an unknown key fails validation. Reading or querying
  an unknown key throws `CustomFieldException`.
- With `strict` off, the package ignores unknown keys on write. Reads return the default, and
  queries match nothing.

### Inactive fields

Inactive fields reject writes, including `forgetCustomField()`.

### Required fields

Required fields reject `null`, `''`, `[]` and `forgetCustomField()`.

### Partial updates

Saves and `syncCustomFields()` check only the keys you pass. A required field that you didn't
pass isn't reported as missing. To demand every required field, use the rules of a new model,
in a [form request](/guide/form-requests) or directly:

```php
Validator::make($input, (new User)->customFieldRules())->validate();
```

## Validate without a model

```php
$clean = CustomFields::validate(Reservation::class, $input);           // all required fields
$clean = CustomFields::validate(Reservation::class, $input, partial: true);
$rules = CustomFields::rules(Reservation::class);
```

`validate()` returns the normalized values.

::: warning Security
Put field management behind your own authorization (policies, gates or middleware). Anyone who
can edit a field's `validation_rules` can add rules such as `exists:` that query your database.
Treat field definitions as configuration, not user input.
:::
