# API resources

Custom fields are attributes, so a model's `toArray()` includes them when their values are
loaded. A plain resource or a model returned from a controller includes them too.

```php
return Reservation::withCustomFields()->paginate(50);
```

## Group custom fields in a resource

To return custom fields under their own key, use `listCustomFields()`:

```php
use Illuminate\Http\Resources\Json\JsonResource;

class ReservationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'check_in' => $this->check_in,
            'custom_fields' => $this->listCustomFields(),
        ];
    }
}
```

```json
{
  "id": 12,
  "check_in": "2026-12-01",
  "custom_fields": {
    "guest_classification": "vip",
    "guest_age": 34
  }
}
```

Load values before you build the resource, or each record runs its own query:

```php
return ReservationResource::collection(
    Reservation::withCustomFields()->paginate(50)
);
```

## Hide a field from the API

`$hidden` and `$visible` apply to custom fields like any attribute:

```php
class Reservation extends Model
{
    use HasCustomFields;

    protected $hidden = ['internal_notes'];
}
```

`listCustomFields()` returns every value. Filter it in the resource if you need to hide a key
there.
