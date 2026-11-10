# Model integration

Add the trait to any Eloquent model. Nothing else is required.

```php
use Illuminate\Database\Eloquent\Model;
use KenPrz\CustomFields\Traits\HasCustomFields;

class User extends Model
{
    use HasCustomFields;
}
```

Custom fields now behave like attributes:

```php
$user = User::create(['name' => 'Ken', 'tax_bracket' => 'b']);   // no $fillable entry needed

$user->tax_bracket;                 // "b"
$user->tax_bracket = 'a';
$user->save();                      // validated, then saved with the model
$user->update(['tax_bracket' => 'b']);
$user->toArray();                   // includes tax_bracket when values are loaded
isset($user->tax_bracket);          // true
```

## Optional model properties

```php
use Illuminate\Database\Eloquent\Casts\AsCollection;

class User extends Model
{
    use HasCustomFields;

    // Which custom fields act as attributes. [] or not set = every field of the model.
    protected $customFields = ['tax_bracket', 'birthday', 'preferences'];

    // Casts for custom field attributes, written like $casts.
    protected $customFieldCasts = [
        'birthday' => 'immutable_date',
        'tax_bracket' => TaxBracket::class,          // backed enum
        'preferences' => AsCollection::class,
    ];

    // Eager load custom field values on every query, like $with.
    protected $loadCustomFields = true;
}
```

| Property | Default | Works like |
|---|---|---|
| `$customFields` | every field | `$fillable`, for custom fields |
| `$customFieldCasts` | dates → Carbon | `$casts` |
| `$loadCustomFields` | `false` | `$with = ['customFieldValues']` |

## How attribute access works

### Real attributes win

A column, cast, accessor, appended attribute or relation always wins over a custom field. The
package also refuses to create a field whose key is already one of those. See
[Managing fields](/guide/managing-fields#keys-can-t-shadow-the-model).

### Mass assignment

Custom field attributes are always fillable, whatever `$fillable` and `$guarded` say. This is
safe for two reasons:

- The package validates every value against its field definition on save.
- `$customFields` limits which keys count.

All other attributes keep your normal mass-assignment rules.

### Saving

1. The package validates the assigned values before any SQL runs.
2. Eloquent saves the model.
3. The package writes the values in the same transaction.

A validation error throws `ValidationException`. Then neither the model nor the values are
saved. `saveQuietly()` writes custom fields too.

### Required fields

`save()` does not enforce required fields. This keeps seeders and factories working when an
admin adds a required field. Enforce required fields in your
[form requests](/guide/form-requests).

### Casts

- `date` fields come back as Carbon, formatted `Y-m-d` in arrays.
- `datetime` fields come back as Carbon.
- Entries in `$customFieldCasts` replace those defaults. They use Eloquent's own casting: enums,
  `AsCollection`, `decimal:2`, custom cast classes and so on.

You can assign cast values back. `$user->tax_bracket = TaxBracket::A` stores `"a"`.

### Serialization

- `toArray()` and JSON include custom field attributes when their values are loaded (eager
  loaded, or just created). They never run a query to load them.
- `$hidden` and `$visible` apply to custom fields like any attribute.
- The raw `customFieldValues` relation is hidden.

### Strict Eloquent

`Model::preventAccessingMissingAttributes()` still throws for keys that are neither attributes
nor custom fields. Unset custom fields read as `null`.

### Deleting a record

Deleting a record deletes its stored values. For models that use `SoftDeletes`, the package
keeps the values until you force delete the model. This is a bulk delete, so it doesn't fire
value events. Set `delete_values_with_model` to `false` to clean up yourself.

## Methods

See [Model methods](/reference/model) for the full list.
