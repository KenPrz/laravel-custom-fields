<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Traits;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use KenPrz\CustomFields\Contracts\FieldType;
use KenPrz\CustomFields\CustomFieldManager;
use KenPrz\CustomFields\Events\CustomFieldValueCreated;
use KenPrz\CustomFields\Events\CustomFieldValueDeleted;
use KenPrz\CustomFields\Events\CustomFieldValueUpdated;
use KenPrz\CustomFields\Exceptions\CustomFieldException;
use KenPrz\CustomFields\FieldTypeRegistry;
use KenPrz\CustomFields\FieldValidator;
use KenPrz\CustomFields\Models\CustomField;
use KenPrz\CustomFields\Models\CustomFieldValue;
use KenPrz\CustomFields\Support\ValueQuery;
use KenPrz\CustomFields\Types\MultiSelectType;
use KenPrz\CustomFields\Types\NumberType;

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

    /**
     * Filter by a custom field value, like where(): pass ($key, $value) or
     * ($key, $operator, $value). Operators: = != <> > >= < <=.
     *
     * Numbers compare numerically. Dates, datetimes and times compare in
     * time order. Other strings compare as text. A null value checks for a
     * missing or null value. The value is validated and normalized with the
     * field type first, so '30' finds an integer field holding 30.
     *
     * Uses the manager's current scope (see ScopeResolver), not
     * customFieldScope(), because no single record is known yet.
     *
     * @param  Builder<static>  $query
     *
     * @throws ValidationException when the value doesn't fit the field type
     */
    public function scopeWhereCustomField(Builder $query, string $key, mixed $operator = null, mixed $value = null): void
    {
        if (func_num_args() === 3) {
            [$operator, $value] = ['=', $operator];
        }

        if (! is_string($operator) || ! in_array($operator, ValueQuery::OPERATORS, true)) {
            throw new InvalidArgumentException('Unsupported custom field operator ['.var_export($operator, true).'].');
        }

        if ($value === null) {
            match ($operator) {
                '=' => $this->scopeWhereCustomFieldNull($query, $key),
                '!=', '<>' => $this->scopeWhereCustomFieldNotNull($query, $key),
                default => throw new InvalidArgumentException("Cannot compare [{$key}] with null using [{$operator}]."),
            };

            return;
        }

        $this->whereCustomFieldValue($query, $key, function (string $driver, string $column, CustomField $field, FieldType $type) use ($key, $operator, $value) {
            $normalized = $this->normalizeCustomFieldQueryValue($key, $field, $type, $value);

            if ($type instanceof NumberType) {
                return ValueQuery::number($driver, $column, $operator, $normalized);
            }

            if (in_array($operator, ['=', '!=', '<>'], true)) {
                return ValueQuery::equals($driver, $column, $operator, $normalized);
            }

            if (! is_string($normalized)) {
                throw new InvalidArgumentException("Cannot compare [{$key}] with [{$operator}].");
            }

            return ValueQuery::text($driver, $column, $operator, $normalized);
        });
    }

    /**
     * Filter by a custom field holding any of the given values.
     *
     * @param  Builder<static>  $query
     * @param  array<int, mixed>  $values
     */
    public function scopeWhereCustomFieldIn(Builder $query, string $key, array $values): void
    {
        if ($values === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $this->whereCustomFieldValue($query, $key, function (string $driver, string $column, CustomField $field, FieldType $type) use ($key, $values) {
            $parts = array_map(function (mixed $value) use ($driver, $column, $key, $field, $type) {
                $normalized = $this->normalizeCustomFieldQueryValue($key, $field, $type, $value);

                return $type instanceof NumberType
                    ? ValueQuery::number($driver, $column, '=', $normalized)
                    : ValueQuery::equals($driver, $column, '=', $normalized);
            }, array_values($values));

            return [
                '('.implode(' OR ', array_column($parts, 0)).')',
                array_merge(...array_column($parts, 1)),
            ];
        });
    }

    /**
     * Filter by a custom field containing a value. For multiselect and
     * checkbox fields this means the list has the option. For other fields
     * it's a case-insensitive substring match on the stored text.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWhereCustomFieldContains(Builder $query, string $key, string $value): void
    {
        $this->whereCustomFieldValue($query, $key, fn (string $driver, string $column, CustomField $field, FieldType $type) => $type instanceof MultiSelectType
            ? ValueQuery::listContains($driver, $column, $value)
            : ValueQuery::like($driver, $column, $value));
    }

    /**
     * Records with no value, or a null value, for the field.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWhereCustomFieldNull(Builder $query, string $key): void
    {
        $this->whereCustomFieldValue($query, $key, fn (string $driver, string $column) => ["{$column} IS NOT NULL", []], exists: false);
    }

    /**
     * Records with a non-null value for the field.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWhereCustomFieldNotNull(Builder $query, string $key): void
    {
        $this->whereCustomFieldValue($query, $key, fn (string $driver, string $column) => ["{$column} IS NOT NULL", []]);
    }

    /**
     * @param  Builder<static>  $query
     * @param  Closure(string, string, CustomField, FieldType): array{string, list<mixed>}  $constraint
     */
    private function whereCustomFieldValue(Builder $query, string $key, Closure $constraint, bool $exists = true): void
    {
        $field = app(CustomFieldManager::class)->fieldsFor($this)->get($key);

        if ($field === null) {
            if (config('custom-fields.strict')) {
                throw CustomFieldException::unknownField($this->getMorphClass(), $key);
            }

            $query->whereRaw('1 = 0');

            return;
        }

        $type = app(FieldTypeRegistry::class)->get($field->type);

        $query->{$exists ? 'whereHas' : 'whereDoesntHave'}('customFieldValues', function (Builder $values) use ($field, $type, $constraint) {
            $column = $values->getQuery()->getGrammar()->wrap($values->getModel()->qualifyColumn('value'));
            [$sql, $bindings] = $constraint($values->getModel()->getConnection()->getDriverName(), $column, $field, $type);

            // Not a literal string only because of the grammar-wrapped column name.
            // Operators are whitelisted and every value is bound.
            $sql = new Expression($sql); // @phpstan-ignore argument.type

            $values->where('custom_field_id', $field->getKey())->whereRaw($sql, $bindings);
        });
    }

    private function normalizeCustomFieldQueryValue(string $key, CustomField $field, FieldType $type, mixed $value): mixed
    {
        Validator::make([$key => $value], [$key => $type->rules($field)], [], [$key => $field->label])->validate();

        return $type->normalize($value, $field);
    }

    protected function customFieldManager(): CustomFieldManager
    {
        return app(CustomFieldManager::class)->forScope($this->customFieldScope());
    }

    protected function writeCustomFieldValue(CustomField $field, mixed $value): void
    {
        $existing = fn () => $this->customFieldValues()->where('custom_field_id', $field->getKey());
        $stored = $existing()->first();

        if ($stored === null) {
            try {
                // Savepoint, so a failed insert doesn't abort the surrounding Postgres transaction.
                $stored = $existing()->withSavepointIfNeeded(fn () => $this->customFieldValues()->create([
                    'custom_field_id' => $field->getKey(),
                    'value' => $value,
                ]));

                app(CustomFieldManager::class)->dispatch(new CustomFieldValueCreated($stored, $field, $this));

                return;
            } catch (UniqueConstraintViolationException) {
                // Another request inserted the row first. A locking read sees it even
                // under MySQL's repeatable read, where a plain select would not.
                $stored = $existing()->lockForUpdate()->firstOrFail();
            }
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
