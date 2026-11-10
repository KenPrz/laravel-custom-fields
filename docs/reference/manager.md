# Manager methods

Call these on the `CustomFields` facade (`KenPrz\CustomFields\Facades\CustomFields`). They work
in the current scope. See [Multi-tenancy](/advanced/multi-tenancy).

## Fields

| Method | Returns | Notes |
|---|---|---|
| `create(array $attributes)` | `CustomField` | Creates the field in the current scope |
| `update(CustomField $field, array $attributes)` | `CustomField` | Same attributes as create, except `model` |
| `find($id)` | `?CustomField` | Null when missing, deleted or in another scope |
| `findByKey($model, $key)` | `?CustomField` | The field that the current scope sees for that key |
| `fieldsFor($model)` | `Collection<string, CustomField>` | Every visible field, active or not, keyed by key |
| `activate(CustomField $field)` | `CustomField` | The field accepts values again |
| `deactivate(CustomField $field)` | `CustomField` | Rejects new values. Stored values stay readable. |
| `reorder($model, array $keys)` | `void` | Listed keys first, in order. The others keep their order after them. |
| `delete(CustomField $field)` | `void` | Soft delete. Values are kept. |
| `restore(CustomField $field)` | `CustomField` | Undo a soft delete |
| `forceDelete(CustomField $field)` | `void` | Deletes the field **and all its values** |
| `dropIfExists($model, $key)` | `void` | Force delete a field by key, including a soft-deleted one. For migrations. |

## Checks and validation

| Method | Returns | Notes |
|---|---|---|
| `check(array $attributes)` | `array` | Every check that `create()` runs, without saving |
| `keyError($model, $key)` | `?string` | Why a key can't be used, or null |
| `rules($model, bool $partial = false)` | `array` | Validation rules keyed by field key |
| `validate($model, array $input, bool $partial = false)` | `array` | Validated, normalized values |

## Scope, types and cache

| Method | Returns | Notes |
|---|---|---|
| `forScope(?Model $scope)` | `CustomFieldManager` | A copy that works in the given scope |
| `global()` | `CustomFieldManager` | A copy that sees global fields only |
| `currentScope()` | `?Model` | The scope this manager works in |
| `registerType($name, $type)` | `void` | See [Custom field types](/advanced/custom-field-types) |
| `types()` | `FieldTypeRegistry` | `types()->names()` lists every registered type |
| `flush()` | `void` | Forget loaded and cached definitions |

`$model` can be a model instance, a model class or a morph alias.

## Field attributes

| Attribute | Type | Default |
|---|---|---|
| `model` | class name or morph alias | required |
| `key` | `a-z`, `0-9`, `_`, starts with a letter, max 64 | required |
| `label` | string | required |
| `type` | registered type name | required |
| `description` | string | `null` |
| `settings` | array, merged over the type defaults | `[]` |
| `validation_rules` | list of Laravel rules | `[]` |
| `is_required` | bool | `false` |
| `is_active` | bool | `true` |
| `is_searchable` | bool, for your own use | `false` |
| `is_filterable` | bool, for your own use | `false` |
| `sort_order` | int | `0` |
