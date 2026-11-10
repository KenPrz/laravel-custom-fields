# Form requests

Spread `customFieldRules()` into your rules. Custom fields sit next to your columns, with no
prefix:

```php
use Illuminate\Foundation\Http\FormRequest;

class SaveUserRequest extends FormRequest
{
    public function rules(): array
    {
        $user = $this->route('user') ?? new User;

        return [
            'name' => ['required', 'string'],
            ...$user->customFieldRules(),
        ];
    }
}
```

```php
class UserController
{
    public function store(SaveUserRequest $request)
    {
        return User::create($request->validated());
    }

    public function update(SaveUserRequest $request, User $user)
    {
        $user->update($request->validated());

        return $user->load('customFieldValues');
    }
}
```

## How the rules behave

- For a **new** model the rules are complete, so the client must send the required custom
  fields.
- For an **existing** model the rules start with `sometimes`, so a partial update checks only
  what the client sent.
- Only active fields are included. If the model sets `$customFields`, only those keys are
  included.
- Keys without a rule are left out of `validated()`, so they never reach the model.

## Tenant-specific rules

The rules come from the record's own scope. When the scope depends on the input, build the
model with that input first:

```php
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
```

This works when `Reservation` overrides `customFieldScope()`. See
[Multi-tenancy](/advanced/multi-tenancy#per-record-scope).

## Error response

A missing required field or an invalid value returns a normal 422 response, next to the errors
for your columns:

```json
{
  "message": "The Guest age field must be at least 18.",
  "errors": {
    "guest_age": ["The Guest age field must be at least 18."]
  }
}
```
