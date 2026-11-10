# Tenancy packages

The package connects to any tenancy setup through a scope resolver. A resolver returns the
current tenant model, or `null`.

```php
// config/custom-fields.php
'scope_resolver' => App\CustomFields\CurrentTenant::class,
```

## Team on the user

```php
use Illuminate\Database\Eloquent\Model;
use KenPrz\CustomFields\Contracts\ScopeResolver;

class CurrentTenant implements ScopeResolver
{
    public function resolve(): ?Model
    {
        return auth()->user()?->currentTeam;
    }
}
```

## stancl/tenancy

The `tenant()` helper returns the current tenant model, or `null` in the central app.

```php
class CurrentTenant implements ScopeResolver
{
    public function resolve(): ?Model
    {
        return tenant();
    }
}
```

Tenant ids in stancl/tenancy are often strings. This works, because `scope_id` is a string
column.

::: tip
If each tenant has its own database, every tenant already has its own `custom_fields` table.
Then you may not need a resolver at all: global fields are already per tenant.
:::

## spatie/laravel-multitenancy

```php
use Spatie\Multitenancy\Models\Tenant;

class CurrentTenant implements ScopeResolver
{
    public function resolve(): ?Model
    {
        return Tenant::current();
    }
}
```

## Tenant from the route

```php
class CurrentTenant implements ScopeResolver
{
    public function resolve(): ?Model
    {
        $hotel = request()->route('hotel');

        return $hotel instanceof Model ? $hotel : null;
    }
}
```

## Queue jobs and commands

A resolver that reads the request or the logged-in user returns `null` in queue jobs and
commands. In those, pass the scope yourself:

```php
CustomFields::forScope($hotel)->fieldsFor(Reservation::class);
```

Or override `customFieldScope()` on the model, so each record knows its own tenant. See
[Multi-tenancy](/advanced/multi-tenancy#per-record-scope).
