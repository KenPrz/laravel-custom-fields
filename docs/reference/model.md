# Model methods

The `HasCustomFields` trait adds these methods to your model.

## Values

| Method | Returns | Notes |
|---|---|---|
| `listCustomFields()` | `array<string, mixed>` | Every value keyed by field key, as stored (no casts), in field order |
| `getCustomField($key, $default = null)` | `mixed` | Stored value, no casts. Throws for unknown keys in strict mode. |
| `setCustomField($key, $value)` | `static` | Validate and write one value right away, without saving the model |
| `syncCustomFields(array $values)` | `static` | Validate and write several values right away. Only the given keys change. |
| `forgetCustomField($key)` | `static` | Delete one stored value |
| `hasCustomField($key)` | `bool` | True when a non-null value is stored. Never throws. |

## Definitions and rules

| Method | Returns | Notes |
|---|---|---|
| `customFieldDefinitions()` | `Collection<string, CustomField>` | Visible field definitions, active or not, keyed by key |
| `customFieldRules()` | `array` | Validation rules for a [form request](/guide/form-requests) |
| `customFieldScope()` | `?Model` | The tenant that this record's fields come from. Override it. |
| `customFieldValues()` | `MorphMany` | The raw value rows |

## Query scopes

| Scope | Example |
|---|---|
| `withCustomFields()` | `User::withCustomFields()->get()` |
| `withoutCustomFields()` | `User::withoutCustomFields()->get()` |
| `whereCustomField($key, $operator, $value)` | `->whereCustomField('age', '>=', 18)` |
| `whereCustomFieldIn($key, array $values)` | `->whereCustomFieldIn('tier', ['a', 'b'])` |
| `whereCustomFieldContains($key, $value)` | `->whereCustomFieldContains('tags', 'vip')` |
| `whereCustomFieldNull($key)` | `->whereCustomFieldNull('notes')` |
| `whereCustomFieldNotNull($key)` | `->whereCustomFieldNotNull('notes')` |

## Properties

| Property | Default | Works like |
|---|---|---|
| `$customFields` | every field | `$fillable`, for custom fields |
| `$customFieldCasts` | dates → Carbon | `$casts` |
| `$loadCustomFields` | `false` | `$with = ['customFieldValues']` |

## Overridden Eloquent methods

The trait overrides `getAttribute()`, `setAttribute()`, `isFillable()`, `fillableFromArray()`,
`attributesToArray()` and `save()`. If your model overrides one of these too, call the trait's
version. See [Limitations](/reference/limitations).
