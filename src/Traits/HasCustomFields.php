<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Traits;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Attributes\Scope;
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
use UnitEnum;

/**
 * Adds custom fields to an Eloquent model.
 *
 * Custom fields behave like attributes: read them with $model->key, assign
 * them, fill() them and save(). Real columns, casts, accessors and relations
 * always win over a custom field with the same key.
 *
 * Optional properties on the model:
 *
 *     protected $customFields = ['tax_bracket'];        // allow-list, [] = every field
 *     protected $customFieldCasts = ['birthday' => 'immutable_date'];
 *     protected $loadCustomFields = true;               // eager load values on every query
 *
 * @mixin Model
 */
trait HasCustomFields
{
    /**
     * Values assigned as attributes and not saved yet, keyed by field key.
     *
     * @var array<string, mixed>
     */
    private array $pendingCustomFields = [];

    /**
     * The pending values after validation and normalization, or null when
     * they still need validating.
     *
     * @var array<string, mixed>|null
     */
    private ?array $validatedCustomFields = null;

    /**
     * Whether the save in progress inserts the model. wasRecentlyCreated can't
     * tell: it stays true for the life of the instance.
     */
    private bool $insertingWithCustomFields = false;

    /**
     * The loaded value rows keyed by field id, with the collection they were
     * built from. Rebuilt whenever the relation is replaced or reloaded.
     *
     * @var array{0: \Illuminate\Database\Eloquent\Collection<int, CustomFieldValue>, 1: array<string, CustomFieldValue>}|null
     */
    private ?array $customFieldValueIndex = null;

    public static function bootHasCustomFields(): void
    {
        // Covers models that override save() themselves. HasCustomFields::save() does the same
        // work and also runs inside a transaction and without events (saveQuietly).
        static::saving(function (self $model): void {
            $model->insertingWithCustomFields = ! $model->exists;
            $model->validatePendingCustomFields();
        });
        static::saved(fn (self $model) => $model->writePendingCustomFields());

        static::deleted(function (self $model): void {
            if (! config('custom-fields.delete_values_with_model')) {
                return;
            }

            // Soft deleted models keep their values so a restore brings them back.
            if (method_exists($model, 'isForceDeleting') && ! $model->isForceDeleting()) {
                return;
            }

            $model->customFieldValues()->delete();
        });
    }

    public function initializeHasCustomFields(): void
    {
        // The raw rows are an implementation detail; the values are serialized as attributes.
        $this->hidden[] = 'customFieldValues';

        if (property_exists($this, 'loadCustomFields') && $this->loadCustomFields) {
            $this->with = array_values(array_unique([...$this->with, 'customFieldValues']));
        }
    }

    /**
     * Every stored value row for this model, including rows for fields that
     * are deleted or belong to another scope. Eager load it to avoid N+1
     * queries: Model::withCustomFields(), Model::with('customFieldValues'),
     * or `protected $loadCustomFields = true`.
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
    public function customFieldDefinitions(): Collection
    {
        return $this->customFieldManager()->fieldsFor($this);
    }

    /**
     * Every stored value keyed by field key, in field order, as stored (no
     * casts). Unsaved assignments are included. Fields without a value are
     * left out; stored nulls are included.
     *
     * @return array<string, mixed>
     */
    public function listCustomFields(): array
    {
        return $this->collectCustomFieldValues($this->customFieldDefinitions());
    }

    /**
     * Validation rules for this model's custom field attributes, keyed by
     * field key, ready to spread into a form request's rules(). A new model
     * gets full rules (required fields must be sent); a saved model gets
     * partial rules (only sent fields are checked).
     *
     * @return array<string, array<int, mixed>>
     */
    public function customFieldRules(): array
    {
        $fields = $this->customFieldDefinitions()
            ->filter(fn (CustomField $field, string $key) => $this->isCustomFieldAttribute($key));

        return app(FieldValidator::class)->rules($fields, partial: $this->exists);
    }

    /**
     * The stored value for a field, or $default when nothing (or null) is
     * stored. No casts are applied; use $model->key for the cast value.
     *
     * @throws CustomFieldException in strict mode when the key doesn't exist
     */
    public function getCustomField(string $key, mixed $default = null): mixed
    {
        $field = $this->customFieldDefinitions()->get($key);

        if ($field === null) {
            if (config('custom-fields.strict')) {
                throw CustomFieldException::unknownField($this->getMorphClass(), $key);
            }

            return $default;
        }

        return $this->storedCustomFieldValue($field)->value ?? $default;
    }

    /**
     * Whether a non-null value is stored for the field. Never throws.
     */
    public function hasCustomField(string $key): bool
    {
        $field = $this->customFieldDefinitions()->get($key);

        return $field !== null && $this->storedCustomFieldValue($field)?->value !== null;
    }

    /**
     * Validate and store one value right away. Same rules as syncCustomFields().
     *
     * @throws ValidationException
     */
    public function setCustomField(string $key, mixed $value): static
    {
        return $this->syncCustomFields([$key => $value]);
    }

    /**
     * Validate and store several values right away, without saving the model.
     * Only the given keys change. Nothing is written unless every value passes.
     *
     * @param  array<string, mixed>  $values
     *
     * @throws ValidationException
     */
    public function syncCustomFields(array $values): static
    {
        $this->assertSavedForCustomFields();

        $fields = $this->customFieldDefinitions();
        $normalized = $this->validateCustomFieldInput($fields, $values);

        $this->writeCustomFieldValues($fields, $normalized, inserted: false);
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

        $fields = $this->customFieldDefinitions();
        $normalized = $this->validateCustomFieldInput($fields, [$key => null]);

        foreach ($fields->only(array_keys($normalized)) as $field) {
            $stored = $this->customFieldValues()->where('custom_field_id', $field->getKey())->first();

            if ($stored !== null) {
                $stored->delete();
                $this->unsetRelation('customFieldValues');
                app(CustomFieldManager::class)->dispatch(new CustomFieldValueDeleted($stored, $field, $this));
            }
        }

        unset($this->pendingCustomFields[$key]);

        return $this;
    }

    /**
     * Read a custom field like an attribute when nothing else on the model
     * answers to the key.
     *
     * @param  string  $key
     */
    public function getAttribute($key): mixed
    {
        if ($this->answersAsCustomField($key)) {
            $field = $this->customFieldDefinitions()->get($key);
            $value = array_key_exists($key, $this->pendingCustomFields)
                ? $this->pendingCustomFields[$key]
                : ($field !== null && $this->exists ? $this->storedCustomFieldValue($field)?->value : null);

            return $this->castCustomFieldValue($key, $value, $field?->type);
        }

        return parent::getAttribute($key);
    }

    /**
     * Assign a custom field like an attribute. The value is validated and
     * written when the model is saved.
     */
    public function setAttribute($key, $value): mixed
    {
        if (! $this->hasAttribute($key)
            && ! $this->hasSetMutator($key)
            && ! $this->hasAttributeSetMutator($key)
            && $this->isCustomFieldAttribute($key)) {
            $this->pendingCustomFields[$key] = $this->uncastCustomFieldValue($value);
            $this->validatedCustomFields = null;

            return $this;
        }

        return parent::setAttribute($key, $value);
    }

    /**
     * Custom field attributes are always fillable, whatever $fillable and
     * $guarded say. Their values are validated against the field
     * definitions on save, and $customFields limits which keys count.
     *
     * @param  string  $key
     */
    public function isFillable($key): bool
    {
        return $this->isCustomFieldAttribute($key) || parent::isFillable($key);
    }

    /**
     * @param  array<array-key, mixed>  $attributes
     * @return array<array-key, mixed>
     */
    protected function fillableFromArray(array $attributes): array
    {
        $fillable = parent::fillableFromArray($attributes);
        $dropped = array_diff_key($attributes, $fillable);

        // Only look up field keys when $fillable dropped something: models are built with
        // fill([]) on every hydration, and that must not cost a query.
        return $dropped === []
            ? $fillable
            : $fillable + array_intersect_key($dropped, array_flip($this->customFieldAttributeKeys()));
    }

    /**
     * Columns, then custom field attributes when their values are loaded
     * (or assigned). Respects $hidden and $visible. Never loads values.
     *
     * @return array<string, mixed>
     */
    public function attributesToArray(): array
    {
        $attributes = parent::attributesToArray();

        if (! $this->relationLoaded('customFieldValues') && $this->pendingCustomFields === []) {
            return $attributes;
        }

        $definitions = $this->customFieldDefinitions();
        $allowed = $this->customFieldAttributeKeys();
        $custom = [];

        $values = $this->collectCustomFieldValues($definitions);

        foreach ($definitions as $key => $field) {
            if (array_key_exists($key, $values) && ! array_key_exists($key, $attributes) && in_array($key, $allowed, true)) {
                $custom[$key] = $this->castCustomFieldValue($key, $values[$key], $field->type, serialize: true);
            }
        }

        return $attributes + $this->getArrayableItems($custom);
    }

    /**
     * Save the model and any assigned custom fields in one transaction. The
     * custom fields are validated before anything is written.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws ValidationException
     */
    public function save(array $options = []): bool
    {
        if ($this->pendingCustomFields === []) {
            return parent::save($options);
        }

        $this->validatePendingCustomFields();
        $this->insertingWithCustomFields = ! $this->exists;

        return $this->customFieldTransaction(function () use ($options) {
            if (! parent::save($options)) {
                return false;
            }

            $this->writePendingCustomFields();

            return true;
        });
    }

    /**
     * Validate assigned custom fields against the definitions visible to
     * this model. Runs automatically on save.
     *
     * @throws ValidationException
     */
    public function validatePendingCustomFields(): void
    {
        if ($this->pendingCustomFields === [] || $this->validatedCustomFields !== null) {
            return;
        }

        $this->validatedCustomFields = $this->validateCustomFieldInput($this->customFieldDefinitions(), $this->pendingCustomFields);
    }

    /**
     * Write assigned custom fields. Runs automatically after save.
     */
    public function writePendingCustomFields(): void
    {
        if ($this->pendingCustomFields === []) {
            return;
        }

        // Always validated by now: by save(), or by the saving event for models with their own save().
        $fields = $this->customFieldDefinitions();
        $values = $this->validatedCustomFields ?? [];

        $this->pendingCustomFields = [];
        $this->validatedCustomFields = null;

        $inserted = $this->insertingWithCustomFields;
        $written = $this->writeCustomFieldValues($fields, $values, $inserted);

        // A model inserted by this save has no other values, so the written rows are the
        // complete relation and the response can include them without another query.
        if ($inserted) {
            $this->setRelation('customFieldValues', $this->customFieldValues()->getRelated()->newCollection($written));
        } else {
            $this->unsetRelation('customFieldValues');
        }
    }

    /**
     * Eager load stored values.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function withCustomFields(Builder $query): void
    {
        $query->with('customFieldValues');
    }

    /**
     * Skip eager loading values, for models with $loadCustomFields = true.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function withoutCustomFields(Builder $query): void
    {
        $query->without('customFieldValues');
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
    #[Scope]
    protected function whereCustomField(Builder $query, string $key, mixed $operator = null, mixed $value = null): void
    {
        if (func_num_args() === 3) {
            [$operator, $value] = ['=', $operator];
        }

        if (! is_string($operator) || ! in_array($operator, ValueQuery::OPERATORS, true)) {
            throw new InvalidArgumentException('Unsupported custom field operator ['.var_export($operator, true).'].');
        }

        if ($value === null) {
            match ($operator) {
                '=' => $this->whereCustomFieldNull($query, $key),
                '!=', '<>' => $this->whereCustomFieldNotNull($query, $key),
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
    #[Scope]
    protected function whereCustomFieldIn(Builder $query, string $key, array $values): void
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
            }, $values);

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
    #[Scope]
    protected function whereCustomFieldContains(Builder $query, string $key, string $value): void
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
    #[Scope]
    protected function whereCustomFieldNull(Builder $query, string $key): void
    {
        $this->whereCustomFieldValue($query, $key, fn (string $driver, string $column) => ["{$column} IS NOT NULL", []], exists: false);
    }

    /**
     * Records with a non-null value for the field.
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function whereCustomFieldNotNull(Builder $query, string $key): void
    {
        $this->whereCustomFieldValue($query, $key, fn (string $driver, string $column) => ["{$column} IS NOT NULL", []]);
    }

    protected function customFieldManager(): CustomFieldManager
    {
        return app(CustomFieldManager::class)->forScope($this->customFieldScope());
    }

    /**
     * Whether a key is a custom field attribute on this model: listed in
     * $customFields, or, when that's empty, the key of any field defined for
     * this model in any scope. Scope is checked later, on read and on save.
     */
    protected function isCustomFieldAttribute(string $key): bool
    {
        return in_array($key, $this->customFieldAttributeKeys(), true);
    }

    /**
     * The keys that act as attributes: $customFields, or every field key
     * defined for the model when that's empty.
     *
     * @return list<string>
     */
    protected function customFieldAttributeKeys(): array
    {
        $allowed = [];

        if (property_exists($this, 'customFields')) {
            foreach ((array) $this->customFields as $key) {
                $allowed[] = (string) $key;
            }
        }

        return $allowed !== [] ? $allowed : app(CustomFieldManager::class)->keysFor($this);
    }

    /**
     * The cast for a custom field attribute: $customFieldCasts, else a
     * default for the field type (dates become Carbon), else none.
     */
    protected function customFieldCast(string $key, ?string $type): ?string
    {
        $casts = property_exists($this, 'customFieldCasts') ? (array) $this->customFieldCasts : [];

        if (array_key_exists($key, $casts)) {
            return $casts[$key];
        }

        return match ($type) {
            'date' => 'date:Y-m-d',
            'datetime' => 'datetime',
            default => null,
        };
    }

    /**
     * Write normalized values in one transaction. The existing rows are read
     * in one query up front, or not at all when this save just inserted the
     * model, so each field costs a single insert or update.
     *
     * @param  Collection<string, CustomField>  $fields
     * @param  array<string, mixed>  $values
     * @return list<CustomFieldValue>
     */
    private function writeCustomFieldValues(Collection $fields, array $values, bool $inserted): array
    {
        $fields = $fields->only(array_keys($values));

        return $this->customFieldTransaction(function () use ($fields, $values, $inserted) {
            $existing = $inserted || $fields->isEmpty()
                ? collect()
                : $this->customFieldValues()
                    ->whereIn('custom_field_id', $fields->map(fn (CustomField $field) => $field->getKey())->values()->all())
                    ->get()
                    ->keyBy('custom_field_id');

            $written = [];

            foreach ($fields as $key => $field) {
                $written[] = $this->writeCustomFieldValue($field, $values[$key], $existing->get((string) $field->getKey()));
            }

            return $written;
        });
    }

    protected function writeCustomFieldValue(CustomField $field, mixed $value, ?CustomFieldValue $stored): CustomFieldValue
    {
        $existing = fn () => $this->customFieldValues()->where('custom_field_id', $field->getKey());

        if ($stored === null) {
            try {
                // Savepoint, so a failed insert doesn't abort the surrounding Postgres transaction.
                $stored = $existing()->withSavepointIfNeeded(fn () => $this->customFieldValues()->create([
                    'custom_field_id' => $field->getKey(),
                    'value' => $value,
                ]));

                app(CustomFieldManager::class)->dispatch(new CustomFieldValueCreated($stored, $field, $this));

                return $stored;
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

        return $stored;
    }

    private function answersAsCustomField(mixed $key): bool
    {
        return is_string($key)
            && $key !== ''
            && $key !== $this->getKeyName()
            && ! $this->hasAttribute($key)
            && ! method_exists(Model::class, $key)
            && ! $this->isRelation($key)
            && ! $this->relationLoaded($key)
            && $this->isCustomFieldAttribute($key);
    }

    /**
     * @param  Collection<string, CustomField>  $definitions
     * @return array<string, mixed>
     */
    private function collectCustomFieldValues(Collection $definitions): array
    {
        $values = [];

        foreach ($definitions as $key => $field) {
            if (array_key_exists($key, $this->pendingCustomFields)) {
                $values[$key] = $this->pendingCustomFields[$key];
            } elseif ($this->exists && ($stored = $this->storedCustomFieldValue($field)) !== null) {
                $values[$key] = $stored->value;
            }
        }

        return $values;
    }

    /**
     * Apply the field's cast with Eloquent's own casting, on a copy of this
     * model so date formats, timezones and custom cast classes behave exactly
     * like they do for columns.
     */
    private function castCustomFieldValue(string $key, mixed $value, ?string $type, bool $serialize = false): mixed
    {
        $cast = $value === null ? null : $this->customFieldCast($key, $type);

        if ($cast === null) {
            return $value;
        }

        $caster = clone $this;
        $caster->pendingCustomFields = [];
        $caster->customFieldValueIndex = null;
        $caster->setRelations([]);
        $caster->setAppends([]);
        $caster->setHidden([]);
        $caster->setVisible([]);
        $caster->setRawAttributes([$key => is_array($value) ? json_encode($value) : $value]);
        $caster->casts = [$key => $cast];

        return $serialize ? $caster->attributesToArray()[$key] : $caster->getAttributeValue($key);
    }

    /**
     * Turn cast values (enums, collections) back into plain values for
     * validation. Dates are left alone; the date types parse them.
     */
    private function uncastCustomFieldValue(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof Arrayable => $value->toArray(),
            default => $value,
        };
    }

    /**
     * @param  Collection<string, CustomField>  $fields
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validateCustomFieldInput(Collection $fields, array $input): array
    {
        return app(FieldValidator::class)->validate($fields, $input, true, (bool) config('custom-fields.strict'));
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function customFieldTransaction(Closure $callback): mixed
    {
        return $this->customFieldValues()->getRelated()->getConnection()->transaction($callback);
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

    private function storedCustomFieldValue(CustomField $field): ?CustomFieldValue
    {
        // getRelationValue() skips the __get/getAttribute chain, which this hot path can't afford.
        $values = $this->getRelationValue('customFieldValues');

        // Holding the collection itself (not its id) means a reloaded relation can never
        // be mistaken for the one the index was built from.
        if ($this->customFieldValueIndex === null || $this->customFieldValueIndex[0] !== $values) {
            $index = [];

            foreach ($values as $value) {
                $index[$value->custom_field_id] = $value;
            }

            $this->customFieldValueIndex = [$values, $index];
        }

        return $this->customFieldValueIndex[1][$field->getKey()] ?? null;
    }

    private function assertSavedForCustomFields(): void
    {
        if (! $this->exists) {
            throw CustomFieldException::unsavedModel($this->getMorphClass());
        }
    }
}
