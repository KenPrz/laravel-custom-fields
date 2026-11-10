# Managing fields

Use the `CustomFields` facade to create and change field definitions.

## Create a field

```php
use KenPrz\CustomFields\Facades\CustomFields;

$field = CustomFields::create([
    'model' => Reservation::class,       // required: model class or morph alias
    'key' => 'guest_age',                // required: a-z, 0-9 and _, starts with a letter, max 64
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

An invalid definition throws a `ValidationException`. In a controller, Laravel turns it into a
normal 422 response. The package rejects:

- unknown attributes
- unknown types
- unknown validation rules
- bad settings
- duplicate keys

To create a field for one tenant only, see [Multi-tenancy](/advanced/multi-tenancy). To create
a field from the terminal, see the [Artisan command](/advanced/artisan-command).

## Check before you create

`check()` runs every check that `create()` runs, but saves nothing. `keyError()` tells you why a
key can't be used, or returns `null`.

```php
CustomFields::check([...]);                                   // throws ValidationException
CustomFields::keyError(Reservation::class, 'guest_age');      // "The key is already taken." or null
```

Use them for live checks in an admin form.

## Change a field

```php
$field = CustomFields::findByKey(Reservation::class, 'guest_age');

CustomFields::update($field, ['label' => 'Age at check-in', 'validation_rules' => ['min:21']]);
CustomFields::deactivate($field);   // rejects new values, stored values stay readable
CustomFields::activate($field);
CustomFields::reorder(Reservation::class, ['guest_age', 'guest_classification']);
```

## Delete a field

```php
CustomFields::delete($field);       // soft delete, values are kept
CustomFields::restore($field);      // undo the soft delete
```

::: danger
`forceDelete()` deletes the field **and all its values**. You can't undo it.

```php
CustomFields::forceDelete($field);
```
:::

## Rules for keys and types

### Keys can't shadow the model

A field's key can't be any of these on the model:

- a column of the model's table
- a cast
- an accessor or mutator
- an appended attribute
- a relation or any other method

If it were, `$model->key` would be ambiguous. The package rejects such keys with a validation
error on `key`.

### Keys stay reserved

A soft-deleted field keeps its key, so you can restore it later. You can't create a new field
with the same key until you force delete the old one.

### Type changes

You can change a field's type only while the field has no stored values. After that, the update
fails with a validation error on `type`. To change the type:

1. Create a new field with the new type.
2. Copy the values over in your own code.
3. Delete the old field.

This rule makes sure that a type change can never corrupt stored data.

### Settings and rules

You can change settings and rules at any time. The change affects new writes only. For example,
if you remove a select option, records that already hold it keep it.

## All methods

See [Manager methods](/reference/manager).
