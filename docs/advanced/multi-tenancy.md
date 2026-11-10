# Multi-tenancy and scopes

A field can be **global** or **scoped**:

- Every record of the model sees a global field.
- Only records of one model instance see a scoped field. The instance can be a tenant, a
  property, an organization, an account or anything else.

The package doesn't assume a tenant column.

```php
// Only reservations of $hotel see this field.
CustomFields::forScope($hotel)->create([
    'model' => Reservation::class,
    'key' => 'vip_reference',
    'label' => 'VIP Reference',
    'type' => 'text',
]);
```

## Visibility rules

- With no scope, only global fields are visible.
- With a scope, global fields plus that scope's fields are visible.
- If a scoped field and a global field have the same key, the scoped field wins inside that
  scope.
- The package never returns fields or values of other scopes. Updating, deactivating or
  deleting a field of another scope throws `CustomFieldException`.
- Two scopes can use the same key independently.

## Where the scope comes from

The manager finds the scope in this order:

1. `CustomFields::forScope($scope)`, or `CustomFields::global()` for global fields only.
2. The configured `scope_resolver`.
3. No scope.

`forScope()` and `global()` return a copy of the manager. They don't change the scope of other
calls.

```php
CustomFields::forScope($hotel)->fieldsFor(Reservation::class);   // hotel + global fields
CustomFields::global()->fieldsFor(Reservation::class);           // global fields only
```

## Scope resolver

A resolver connects your existing tenancy setup:

```php
use Illuminate\Database\Eloquent\Model;
use KenPrz\CustomFields\Contracts\ScopeResolver;

class CurrentTenant implements ScopeResolver
{
    public function resolve(): ?Model
    {
        return auth()->user()?->team;
    }
}
```

```php
// config/custom-fields.php
'scope_resolver' => App\CustomFields\CurrentTenant::class,
```

The container builds the resolver, so you can type-hint dependencies in its constructor. See
[Tenancy packages](/integrations/tenancy) for ready-made resolvers.

## Per-record scope

When a record knows its own tenant, override `customFieldScope()`:

```php
class Reservation extends Model
{
    use HasCustomFields;

    public function customFieldScope(): ?Model
    {
        return $this->property;
    }
}
```

These then use the record's scope:

- attribute access (`$reservation->vip_reference`)
- saves
- `customFieldRules()`
- the explicit methods, such as `setCustomField()`

## Queries

Queries (`whereCustomField()` and the other query scopes) can't use `customFieldScope()`,
because no single record is known yet. They use the scope resolver. With no resolver, they see
global fields only.

To query one tenant's fields without a resolver, bind a scoped manager for the request:

```php
use KenPrz\CustomFields\CustomFieldManager;

app()->instance(CustomFieldManager::class, CustomFields::forScope($hotel));

Reservation::whereCustomField('vip_reference', 'A-100')->get();
```

## Key types

Scopes can have any primary key: integers, UUIDs, ULIDs or strings such as slugs.
