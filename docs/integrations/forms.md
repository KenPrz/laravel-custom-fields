# Building forms

The package has no form builder. Instead, give your form the field definitions, and render one
input per field. This page shows:

1. a schema endpoint that tells clients which fields to show
2. a map from field type to input
3. forms in React and Blade
4. an admin form that creates fields

## 1. Schema endpoint

```php
// routes/api.php
use App\Models\Property;
use App\Models\Reservation;

Route::get('/hotels/{hotel}/reservation-form', function (Property $hotel) {
    $reservation = new Reservation(['property_id' => $hotel->id]);
    $rules = $reservation->customFieldRules();

    return $reservation->customFieldDefinitions()     // ordered by sort_order
        ->only(array_keys($rules))                    // active fields in $customFields
        ->map(fn ($field) => [
            'key' => $field->key,
            'label' => $field->label,
            'type' => $field->type,
            'required' => $field->is_required,
            'description' => $field->description,
            'options' => $field->settings['options'] ?? null,
            'max_length' => $field->settings['max_length'] ?? null,
            'places' => $field->settings['places'] ?? null,
        ])
        ->values();
});
```

The endpoint filters on the keys of `customFieldRules()`. So the form shows exactly the fields
that the backend accepts.

This example needs `customFieldScope()` on `Reservation`. See
[Multi-tenancy](/advanced/multi-tenancy#per-record-scope).

Response:

```json
[
  {
    "key": "guest_classification",
    "label": "Guest type",
    "type": "select",
    "required": true,
    "description": null,
    "options": { "vip": "VIP", "corporate": "Corporate" },
    "max_length": null,
    "places": null
  },
  {
    "key": "guest_age",
    "label": "Guest age",
    "type": "integer",
    "required": false,
    "description": "Age at check-in",
    "options": null,
    "max_length": null,
    "places": null
  }
]
```

## 2. Type to input

| Field type | Input | Value to send |
|---|---|---|
| `text`, `email`, `url`, `phone` | `<input type="text\|email\|url\|tel" maxlength>` | string |
| `textarea` | `<textarea maxlength>` | string |
| `integer` | `<input type="number" step="1">` | number or numeric string |
| `number` | `<input type="number" step="any">` | number or numeric string |
| `decimal` | `<input type="number">` with `step` = 10<sup>-places</sup> | number or numeric string |
| `boolean` | checkbox | `true`/`false` or `1`/`0` |
| `date` | `<input type="date">` | `Y-m-d` |
| `datetime` | `<input type="datetime-local">` | date and time string |
| `time` | `<input type="time">` | `H:i` |
| `select`, `radio` | `<select>` or radio buttons from `options` | one option key |
| `multiselect`, `checkbox` | checkbox group or multi-select | array of option keys |
| `json` | code editor or hidden input | array or JSON text |

::: tip
- Send the option **key** (`vip`), not the label.
- An empty string counts as `null`. A blank optional input clears the value. A blank required
  input returns a 422.
:::

## 3. Render the form

### React

```tsx
type Field = {
  key: string
  label: string
  type: string
  required: boolean
  options: Record<string, string> | null
  max_length: number | null
  places: number | null
}

function CustomFieldInput({ field, value, onChange }: {
  field: Field
  value: any
  onChange: (key: string, value: any) => void
}) {
  const common = {
    name: field.key,
    id: field.key,
    required: field.required,
    value: value ?? '',
    onChange: (e: any) => onChange(field.key, e.target.value),
  }

  switch (field.type) {
    case 'textarea':
      return <textarea {...common} maxLength={field.max_length ?? undefined} />
    case 'select':
    case 'radio':
      return (
        <select {...common}>
          <option value="" />
          {Object.entries(field.options ?? {}).map(([v, label]) => (
            <option key={v} value={v}>{label}</option>
          ))}
        </select>
      )
    case 'multiselect':
    case 'checkbox': {
      const selected: string[] = value ?? []
      return Object.entries(field.options ?? {}).map(([v, label]) => (
        <label key={v}>
          <input
            type="checkbox"
            checked={selected.includes(v)}
            onChange={(e) => onChange(field.key, e.target.checked
              ? [...selected, v]
              : selected.filter((x) => x !== v))}
          />
          {label}
        </label>
      ))
    }
    case 'boolean':
      return <input type="checkbox" name={field.key} checked={!!value}
        onChange={(e) => onChange(field.key, e.target.checked)} />
    case 'integer':
      return <input type="number" step="1" {...common} />
    case 'number':
      return <input type="number" step="any" {...common} />
    case 'decimal':
      return <input type="number" step={10 ** -(field.places ?? 2)} {...common} />
    case 'date':
    case 'time':
    case 'email':
    case 'url':
      return <input type={field.type} {...common} />
    case 'datetime':
      return <input type="datetime-local" {...common} />
    case 'phone':
      return <input type="tel" {...common} />
    default:
      return <input type="text" {...common} maxLength={field.max_length ?? undefined} />
  }
}
```

Send the values flat, next to the normal columns:

```json
{
  "property_id": 5,
  "check_in": "2026-12-01",
  "guest_classification": "vip",
  "guest_age": 34
}
```

Errors come back with the same keys (`errors.guest_age`). Show each error under the input with
that `name`.

### Blade

```blade
@foreach ($fields as $f)
    <label for="{{ $f['key'] }}">
        {{ $f['label'] }} @if ($f['required']) * @endif
    </label>

    @if (in_array($f['type'], ['select', 'radio']))
        <select name="{{ $f['key'] }}" id="{{ $f['key'] }}">
            <option value=""></option>
            @foreach ($f['options'] as $value => $label)
                <option value="{{ $value }}" @selected(old($f['key'], $reservation->{$f['key']}) == $value)>
                    {{ $label }}
                </option>
            @endforeach
        </select>
    @elseif ($f['type'] === 'boolean')
        <input type="hidden" name="{{ $f['key'] }}" value="0">
        <input type="checkbox" name="{{ $f['key'] }}" id="{{ $f['key'] }}" value="1"
            @checked(old($f['key'], $reservation->{$f['key']}))>
    @elseif ($f['type'] === 'textarea')
        <textarea name="{{ $f['key'] }}" id="{{ $f['key'] }}">{{ old($f['key'], $reservation->{$f['key']}) }}</textarea>
    @else
        <input name="{{ $f['key'] }}" id="{{ $f['key'] }}"
            value="{{ old($f['key'], $reservation->{$f['key']}) }}">
    @endif

    @if ($f['description']) <p class="hint">{{ $f['description'] }}</p> @endif
    @error($f['key']) <p class="error">{{ $message }}</p> @enderror
@endforeach
```

`$reservation->{$f['key']}` works because custom fields are attributes. A `date` field returns
Carbon. For a date input, format it: `$reservation->{$f['key']}?->format('Y-m-d')`.

### Prefill an edit form

```php
return [
    'reservation' => $reservation->only(['id', 'property_id', 'check_in']),
    'values' => $reservation->listCustomFields(),   // ['guest_classification' => 'vip', ...]
    'fields' => $schema,                            // from the schema endpoint
];
```

`listCustomFields()` returns the stored values without casts, so it fits JSON clients. A date
comes back as `"2026-12-01"`, not as Carbon.

## 4. Admin form: create a field

List the types for a type picker:

```php
CustomFields::types()->names();   // ['text', 'textarea', ..., plus your own types]
```

Check the key while the admin types:

```php
Route::get('/hotels/{hotel}/fields/check-key', function (Request $request, Property $hotel) {
    return [
        'error' => CustomFields::forScope($hotel)->keyError(Reservation::class, $request->query('key')),
    ];
});
```

Preview the field before you save it. `check()` runs every check that `create()` runs and saves
nothing:

```php
CustomFields::forScope($hotel)->check($request->all() + ['model' => Reservation::class]);
```

Create it:

```php
Route::post('/hotels/{hotel}/fields', function (Request $request, Property $hotel) {
    Gate::authorize('manage-fields', $hotel);

    return CustomFields::forScope($hotel)->create([
        'model' => Reservation::class,
        'key' => $request->input('key'),
        'label' => $request->input('label'),
        'type' => $request->input('type'),
        'settings' => $request->input('settings', []),
        'is_required' => $request->boolean('required'),
    ]);
});
```

A bad definition returns a 422 with errors keyed by `key`, `type`, `settings.options` and so
on.

::: warning
Authorize field management. Anyone who can create fields can add validation rules that query
your database. See [Validation](/guide/validation).
:::
