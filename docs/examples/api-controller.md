# API controller

A complete CRUD controller for a model with custom fields.

## Request

```php
use Illuminate\Foundation\Http\FormRequest;

class SaveUserRequest extends FormRequest
{
    public function rules(): array
    {
        $user = $this->route('user') ?? new User;

        return [
            'name' => ['required', 'string'],
            'email' => ['required', 'email'],
            ...$user->customFieldRules(),
        ];
    }
}
```

## Controller

```php
class UserController
{
    public function index()
    {
        return User::withCustomFields()
            ->when(request('tax_bracket'), fn ($q, $value) => $q->whereCustomField('tax_bracket', $value))
            ->paginate();
    }

    public function store(SaveUserRequest $request)
    {
        return User::create($request->validated());       // 201 includes the custom fields
    }

    public function show(User $user)
    {
        return $user->load('customFieldValues');
    }

    public function update(SaveUserRequest $request, User $user)
    {
        $user->update($request->validated());

        return $user->load('customFieldValues');
    }

    public function destroy(User $user)
    {
        $user->delete();                                  // deletes the values too

        return response()->noContent();
    }
}
```

## Behavior

- A missing required field or an invalid value returns a normal 422, next to the errors for
  your columns.
- Keys without a rule are left out of `validated()`, so they never reach the model.
- `load('customFieldValues')` loads the values, so the response includes the custom fields.

## Test

```php
public function test_it_stores_custom_fields(): void
{
    CustomFields::create([
        'model' => User::class,
        'key' => 'tax_bracket',
        'label' => 'Tax bracket',
        'type' => 'select',
        'settings' => ['options' => ['a' => 'A', 'b' => 'B']],
    ]);

    $this->postJson('/users', ['name' => 'Ken', 'email' => 'ken@example.com', 'tax_bracket' => 'b'])
        ->assertCreated()
        ->assertJsonPath('tax_bracket', 'b');

    $this->postJson('/users', ['name' => 'Ken', 'email' => 'ken@example.com', 'tax_bracket' => 'z'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('tax_bracket');
}
```
