# Events

All events are in the `KenPrz\CustomFields\Events` namespace.

| Event | Properties |
|---|---|
| `CustomFieldCreated` | `field` |
| `CustomFieldUpdated` | `field` |
| `CustomFieldActivated` | `field` |
| `CustomFieldDeactivated` | `field` |
| `CustomFieldDeleted` | `field`, `forced` |
| `CustomFieldValueCreated` | `value`, `field`, `entity` |
| `CustomFieldValueUpdated` | `value`, `field`, `entity`, `oldValue` |
| `CustomFieldValueDeleted` | `value`, `field`, `entity` |

## When events fire

- The package dispatches events after the surrounding database transaction commits. A
  rolled-back write fires nothing.
- Events fire for changes made through the facade, the trait and attribute saves.
- Changes made directly to the package tables, with Eloquent or the query builder, don't fire
  events.
- Turn off all events with `'events' => false` in the config.

## Queued listeners

The events use `SerializesModels`, so queued listeners get fresh models. The deleted events
carry the models as they were, because the package can't fetch them again.

## Listen to an event

```php
use Illuminate\Support\Facades\Event;
use KenPrz\CustomFields\Events\CustomFieldValueUpdated;

Event::listen(function (CustomFieldValueUpdated $event) {
    logger()->info('Custom field changed', [
        'field' => $event->field->key,
        'record' => $event->entity->getKey(),
        'from' => $event->oldValue,
        'to' => $event->value->value,
    ]);
});
```

See [Audit log](/integrations/audit-log) for a full example.

## Testing

Fake only the package events. `Event::fake()` with no arguments also fakes Eloquent model
events. The package listens to those, for example to delete values when you delete a record.

```php
use KenPrz\CustomFields\Events\CustomFieldValueCreated;

Event::fake([CustomFieldValueCreated::class]);

$user->update(['tax_bracket' => 'a']);

Event::assertDispatched(CustomFieldValueCreated::class);
```
