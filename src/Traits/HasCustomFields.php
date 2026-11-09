<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use KenPrz\CustomFields\CustomFieldManager;
use KenPrz\CustomFields\Events\CustomFieldValueCreated;
use KenPrz\CustomFields\Events\CustomFieldValueDeleted;
use KenPrz\CustomFields\Events\CustomFieldValueUpdated;
use KenPrz\CustomFields\Exceptions\CustomFieldException;
use KenPrz\CustomFields\FieldValidator;
use KenPrz\CustomFields\Models\CustomField;
use KenPrz\CustomFields\Models\CustomFieldValue;

/**
 * Adds custom fields to an Eloquent model.
 *
 * Values are read and written through the methods below, never through
 * mass assignment, and every write is validated first.
 *
 * @mixin Model
 */
trait HasCustomFields
{
    public static function bootHasCustomFields(): void
    {
        static::deleted(function (Model $model): void {
            if (! config('custom-fields.delete_values_with_model')) {
                return;
            }

            // Soft deleted models keep their values so a restore brings them back.
            if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                return;
            }

            /** @var Model&self $model */
            $model->customFieldValues()->delete();
        });
    }

    /**
     * Every stored value row for this model, including rows for fields that
     * are deleted or belong to another scope. Eager load it to avoid N+1
     * queries: Model::with('customFieldValues') or Model::withCustomFields().
     *
     * @return MorphMany<CustomFieldValue, $this>
     */
    public function customFieldValues(): MorphMany
    {
        /** @var class-string<CustomFieldValue> $model */
        $model = config('custom-fields.models.value');

        return $this->morphMany($model, 'entity');
    }

    /**
     * The scope this model's fields come from. Defaults to the manager's
     * current scope. Override it when the model knows its own tenant:
     *
     *     public function customFieldScope(): ?Model { return $this->property; }
     */
    public function customFieldScope(): ?Model
    {
        return app(CustomFieldManager::class)->currentScope();
    }

    /**
     * Field definitions visible to this model, active or not, keyed by key.
     *
     * @return Collection<string, CustomField>
     */
    public function customFields(): Collection
    {
        return $this->customFieldManager()->fieldsFor($this);
    }

    /**
     * The stored value for a field, or $default when nothing (or null) is
     * stored. Values come back normalized: the same shape the field type
     * stores, for example "2026-11-09" for a date or "12.50" for a decimal.
     *
     * @throws CustomFieldException in strict mode when the key doesn't exist
     */
    public function getCustomField(string $key, mixed $default = null): mixed
    {
        $field = $this->customFields()->get($key);

        if ($field === null) {
            if (config('custom-fields.strict')) {
                throw CustomFieldException::unknownField($this->getMorphClass(), $key);
            }

            return $default;
        }

        return $this->storedCustomFieldValue($field)->value ?? $default;
    }

    /**
     * Every stored value, keyed by field key, in field order. Fields without
     * a stored value are left out. Stored nulls are included.
     *
     * @return array<string, mixed>
     */
    public function getCustomFieldValues(): array
    {
        $values = [];

        foreach ($this->customFields() as $key => $field) {
            $stored = $this->storedCustomFieldValue($field);

            if ($stored !== null) {
                $values[$key] = $stored->value;
            }
        }

        return $values;
    }

    /**
     * Whether a non-null value is stored for the field. Never throws.
     */
    public function hasCustomField(string $key): bool
    {
        $field = $this->customFields()->get($key);

        return $field !== null && $this->storedCustomFieldValue($field)?->value !== null;
    }

    /**
     * Validate and store one value. Same rules as syncCustomFields().
     *
     * @throws ValidationException
     */
    public function setCustomField(string $key, mixed $value): static
    {
        return $this->syncCustomFields([$key => $value]);
    }

    /**
     * Validate and store several values at once. Only the given keys change;
     * other stored values are left alone. Nothing is written unless every
     * value passes.
     *
     * @param  array<string, mixed>  $values
     *
     * @throws ValidationException
     */
    public function syncCustomFields(array $values): static
    {
        $this->assertSavedForCustomFields();

        $fields = $this->customFields();
        $normalized = app(FieldValidator::class)->validate($fields, $values, true, (bool) config('custom-fields.strict'));

        $this->customFieldValues()->getRelated()->getConnection()->transaction(function () use ($fields, $normalized) {
            foreach ($fields->only(array_keys($normalized)) as $key => $field) {
                $this->writeCustomFieldValue($field, $normalized[$key]);
            }
        });

        $this->unsetRelation('customFieldValues');

        return $this;
    }

    /**
     * Delete the stored value for a field. Fails for required and inactive
     * fields, and for unknown keys in strict mode.
     *
     * @throws ValidationException
     */
    public function forgetCustomField(string $key): static
    {
        $this->assertSavedForCustomFields();

        $fields = $this->customFields();
        $normalized = app(FieldValidator::class)->validate($fields, [$key => null], true, (bool) config('custom-fields.strict'));

        foreach ($fields->only(array_keys($normalized)) as $field) {
            $stored = $this->customFieldValues()->where('custom_field_id', $field->getKey())->first();

            if ($stored !== null) {
                $stored->delete();
                $this->unsetRelation('customFieldValues');
                app(CustomFieldManager::class)->dispatch(new CustomFieldValueDeleted($stored, $field, $this));
            }
        }

        return $this;
    }

    /**
     * Eager load stored values.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWithCustomFields(Builder $query): void
    {
        $query->with('customFieldValues');
    }

    protected function customFieldManager(): CustomFieldManager
    {
        return app(CustomFieldManager::class)->forScope($this->customFieldScope());
    }

    protected function writeCustomFieldValue(CustomField $field, mixed $value): void
    {
        $stored = $this->customFieldValues()->firstOrCreate(
            ['custom_field_id' => $field->getKey()],
            ['value' => $value],
        );

        if ($stored->wasRecentlyCreated) {
            app(CustomFieldManager::class)->dispatch(new CustomFieldValueCreated($stored, $field, $this));

            return;
        }

        $old = $stored->value;
        $stored->value = $value;

        if ($stored->isDirty('value')) {
            $stored->save();
            app(CustomFieldManager::class)->dispatch(new CustomFieldValueUpdated($stored, $field, $this, $old));
        }
    }

    private function storedCustomFieldValue(CustomField $field): ?CustomFieldValue
    {
        return $this->customFieldValues->first(
            fn (CustomFieldValue $value) => (string) $value->custom_field_id === (string) $field->getKey()
        );
    }

    private function assertSavedForCustomFields(): void
    {
        if (! $this->exists) {
            throw CustomFieldException::unsavedModel($this->getMorphClass());
        }
    }
}
