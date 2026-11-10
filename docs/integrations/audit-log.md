# Audit log

Record every change to a custom field value with the value events.

## Migration

```php
Schema::create('custom_field_audits', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->nullable();
    $table->morphs('entity');
    $table->string('field_key');
    $table->json('old_value')->nullable();
    $table->json('new_value')->nullable();
    $table->timestamp('created_at');
});
```

## Listener

```php
namespace App\Listeners;

use Illuminate\Support\Facades\DB;
use KenPrz\CustomFields\Events\CustomFieldValueCreated;
use KenPrz\CustomFields\Events\CustomFieldValueDeleted;
use KenPrz\CustomFields\Events\CustomFieldValueUpdated;

class AuditCustomFieldValue
{
    public function handle(CustomFieldValueCreated|CustomFieldValueUpdated|CustomFieldValueDeleted $event): void
    {
        DB::table('custom_field_audits')->insert([
            'user_id' => auth()->id(),
            'entity_type' => $event->entity->getMorphClass(),
            'entity_id' => $event->entity->getKey(),
            'field_key' => $event->field->key,
            'old_value' => json_encode(match (true) {
                $event instanceof CustomFieldValueUpdated => $event->oldValue,
                $event instanceof CustomFieldValueDeleted => $event->value->value,
                default => null,
            }),
            'new_value' => json_encode(
                $event instanceof CustomFieldValueDeleted ? null : $event->value->value
            ),
            'created_at' => now(),
        ]);
    }
}
```

Laravel finds the listener through event discovery, because the `handle()` method type-hints
the events.

## Notes

- The events fire after the transaction commits. A failed save writes no audit row.
- Keep the listener synchronous to record `auth()->id()`. A queued listener has no logged-in
  user, so pass the user id another way.
- Deleting a record removes its values in bulk, without value events. Listen to the model's
  `deleted` event if you need to audit that too.
