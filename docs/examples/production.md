# Production scenarios

These examples use a hotel app. Each hotel (`Property`) adds its own fields to reservations.

## Setup

Make each reservation know its hotel:

```php
class Reservation extends Model
{
    use HasCustomFields;

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function customFieldScope(): ?Model
    {
        return $this->property;
    }
}
```

Then `$reservation->vip_reference` reads the field of that reservation's hotel.

## 1. A hotel adds a field from its settings page

```php
class HotelFieldController
{
    public function store(Request $request, Property $hotel)
    {
        Gate::authorize('manage-fields', $hotel);

        $field = CustomFields::forScope($hotel)->create([
            'model' => Reservation::class,
            'key' => $request->input('key'),
            'label' => $request->input('label'),
            'type' => 'select',
            'settings' => ['options' => ['vip' => 'VIP', 'corporate' => 'Corporate']],
            'is_required' => $request->boolean('required'),
        ]);

        return response()->json($field, 201);
    }
}
```

A bad type, a duplicate key or a key that clashes with a column returns a normal 422. You don't
need a try/catch.

**Result:** the new field shows only on that hotel's reservations.

## 2. Check the key while the admin types

```php
Route::get('/hotels/{hotel}/fields/check-key', fn (Request $request, Property $hotel) => [
    'error' => CustomFields::forScope($hotel)->keyError(Reservation::class, $request->query('key')),
]);
```

**Result:** the form shows "already in use" before the admin submits.

## 3. The front desk saves a reservation

```php
class SaveReservationRequest extends FormRequest
{
    public function rules(): array
    {
        $reservation = $this->route('reservation')
            ?? new Reservation(['property_id' => $this->property_id]);

        return [
            'property_id' => ['required', 'exists:properties,id'],
            'check_in' => ['required', 'date'],
            ...$reservation->customFieldRules(),
        ];
    }
}
```

```php
// controller
Reservation::create($request->validated());
$reservation->update($request->validated());
```

**Result:** a missing required custom field returns a 422 next to the errors for `check_in`.

## 4. Reports and filters

```php
Reservation::where('property_id', $hotel->id)
    ->whereCustomField('guest_classification', 'vip')
    ->whereCustomField('guest_age', '>=', 18)
    ->withCustomFields()           // 2 queries in total, not one per record
    ->paginate(50);
```

The query scopes use the [scope resolver](/advanced/multi-tenancy#scope-resolver) to find
hotel-specific fields. Global fields work without one.

**Result:** on 100,000 reservations each filter takes about 50–480 ms in the
[benchmark](/advanced/performance).

## 5. Retire a field without losing data

```php
$hotelFields = CustomFields::forScope($hotel);
$field = $hotelFields->findByKey(Reservation::class, 'vip_reference');

$hotelFields->deactivate($field);   // rejects new values, old ones stay readable
$hotelFields->delete($field);       // soft delete, restore() brings it back
```

::: danger
`forceDelete($field)` removes the field **and every value**. Use it only when you are sure.
:::

**Result:** old reservations still show `vip_reference`, and new saves reject it.

## 6. A platform field for every hotel

Fields that your code depends on belong in a migration:

```bash
php artisan make:custom-field loyalty_number --model=Reservation --type=text
php artisan migrate
```

**Result:** every environment gets the same global field.

## 7. A one-off field from the terminal

```bash
php artisan make:custom-field late_checkout --mode=db --scope=Property:5 --type=boolean
```

**Result:** hotel 5 gets the field right away. Production asks for confirmation first.

## 8. Field order on forms

```php
CustomFields::forScope($hotel)->reorder(Reservation::class, ['guest_classification', 'guest_age']);
```

**Result:** `customFieldDefinitions()` and the [form schema](/integrations/forms) return the
fields in that order.

## 9. Bulk import

```php
DB::transaction(function () use ($rows) {
    foreach ($rows as $row) {
        Reservation::create($row);   // validates custom fields, rolls back on the first error
    }
});
```

A `ValidationException` on any row rolls back the whole import. Writes cost about 1.5–4 ms per
record, so 10,000 rows take about 15–40 seconds. Put large imports in a queue job.
