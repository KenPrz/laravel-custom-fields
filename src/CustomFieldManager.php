<?php

declare(strict_types=1);

namespace KenPrz\CustomFields;

use ArrayObject;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use KenPrz\CustomFields\Contracts\FieldType;
use KenPrz\CustomFields\Contracts\ScopeResolver;
use KenPrz\CustomFields\Exceptions\CustomFieldException;
use KenPrz\CustomFields\Models\CustomField;

/**
 * Creates, finds and changes field definitions. Reached through the
 * CustomFields facade.
 *
 * Every call works in a scope: the one set with forScope(), or else the one
 * from the configured ScopeResolver, or else none. In a scope you see the
 * global fields plus that scope's fields. A scoped field replaces a global
 * field with the same key. Fields of other scopes are never visible.
 */
class CustomFieldManager
{
    private const CREATABLE = [
        'model', 'key', 'label', 'description', 'type', 'settings', 'validation_rules',
        'is_required', 'is_active', 'is_searchable', 'is_filterable', 'sort_order',
    ];

    private ?Model $scope = null;

    private bool $hasScope = false;

    /**
     * @param  ArrayObject<string, Collection<string, CustomField>>  $loaded  field lists loaded in this request, shared with scoped copies
     */
    public function __construct(
        private readonly FieldTypeRegistry $types,
        private readonly FieldValidator $fieldValidator,
        private readonly ValidatorFactory $validator,
        private readonly Container $container,
        private readonly ArrayObject $loaded = new ArrayObject,
    ) {}

    /**
     * A copy of the manager that works in the given scope. Pass null to only
     * see global fields, ignoring the scope resolver.
     */
    public function forScope(?Model $scope): static
    {
        $manager = clone $this;
        $manager->scope = $scope;
        $manager->hasScope = true;

        return $manager;
    }

    /**
     * A copy of the manager that only sees global fields.
     */
    public function global(): static
    {
        return $this->forScope(null);
    }

    /**
     * The scope this manager works in, or null for global only.
     */
    public function currentScope(): ?Model
    {
        if ($this->hasScope) {
            return $this->scope;
        }

        /** @var class-string<ScopeResolver>|null $resolver */
        $resolver = config('custom-fields.scope_resolver');

        return $resolver === null ? null : $this->container->make($resolver)->resolve();
    }

    /**
     * Every field visible for the model in the current scope, active or not,
     * keyed by field key and ordered by sort_order.
     *
     * @param  Model|class-string<Model>|string  $model  a model, model class or morph alias
     * @return Collection<string, CustomField>
     */
    public function fieldsFor(Model|string $model): Collection
    {
        $morph = $this->morphClass($model);
        $scopeKey = CustomField::scopeKeyFor($this->currentScope());

        return $this->loaded[$morph.'|'.$scopeKey] ??= $this->loadFields($morph, $scopeKey);
    }

    /**
     * The field with this key for the model in the current scope, or null.
     *
     * @param  Model|class-string<Model>|string  $model
     */
    public function findByKey(Model|string $model, string $key): ?CustomField
    {
        return $this->fieldsFor($model)->get($key);
    }

    /**
     * The field with this id, or null when it doesn't exist, is deleted, or
     * belongs to another scope.
     */
    public function find(int|string $id): ?CustomField
    {
        $field = $this->fieldModel()::query()->find($id);

        return $field !== null && $this->isVisible($field) ? $field : null;
    }

    /**
     * Create a field in the current scope.
     *
     * @param  array<string, mixed>  $attributes  `model`, `key`, `label` and `type` are required
     *
     * @throws ValidationException when the definition is invalid
     */
    public function create(array $attributes): CustomField
    {
        $data = $this->validateDefinition($attributes);

        $model = $this->fieldModel();
        $field = new $model;
        $field->model_type = $this->morphClass($attributes['model']);
        $field->fill($data);
        $field->scope()->associate($this->currentScope());

        $this->assertKeyIsFree($field);
        $field->save();

        $this->dispatch(new Events\CustomFieldCreated($field));

        return $field;
    }

    /**
     * Update a field. `model` can't be changed, and `type` can only be
     * changed while the field has no stored values.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException when the change is invalid
     */
    public function update(CustomField $field, array $attributes): CustomField
    {
        $this->assertVisible($field);

        $field->fill($this->validateDefinition($attributes, $field));

        if ($field->isDirty('type') && $field->values()->exists()) {
            throw ValidationException::withMessages([
                'type' => 'The type cannot be changed because the field already has stored values.',
            ]);
        }

        $this->assertKeyIsFree($field);
        $field->save();

        if ($field->wasChanged()) {
            $this->dispatch(new Events\CustomFieldUpdated($field));
        }

        if ($field->wasChanged('is_active')) {
            $this->dispatch($field->is_active ? new Events\CustomFieldActivated($field) : new Events\CustomFieldDeactivated($field));
        }

        return $field;
    }

    /**
     * Let the field accept values again.
     */
    public function activate(CustomField $field): CustomField
    {
        return $this->update($field, ['is_active' => true]);
    }

    /**
     * Stop the field from accepting values. Stored values are kept and can
     * still be read.
     */
    public function deactivate(CustomField $field): CustomField
    {
        return $this->update($field, ['is_active' => false]);
    }

    /**
     * Set sort_order from a list of keys. Fields not in the list keep their
     * relative order after the listed ones.
     *
     * @param  Model|class-string<Model>|string  $model
     * @param  list<string>  $keys
     */
    public function reorder(Model|string $model, array $keys): void
    {
        $fields = $this->fieldsFor($model);

        foreach ($keys as $key) {
            if (! $fields->has($key)) {
                throw CustomFieldException::unknownField($this->morphClass($model), $key);
            }
        }

        // Stable sort: listed keys first in the given order, the rest keep their current order.
        $positions = array_flip(array_values(array_unique($keys)));
        $ordered = $fields->sortBy(fn (CustomField $field) => $positions[$field->key] ?? count($positions))->values();

        $this->fieldModel()::query()->getConnection()->transaction(function () use ($ordered) {
            foreach ($ordered as $position => $field) {
                $field->sort_order = $position;
                $field->save();
            }
        });
    }

    /**
     * Soft delete a field. Its values are kept, and its key stays taken until
     * the field is force deleted.
     */
    public function delete(CustomField $field): void
    {
        $this->assertVisible($field);

        $field->delete();

        $this->dispatch(new Events\CustomFieldDeleted($field, forced: false));
    }

    /**
     * Bring back a soft deleted field.
     */
    public function restore(CustomField $field): CustomField
    {
        $this->assertVisible($field);

        $field->restore();

        return $field;
    }

    /**
     * Delete a field and every value stored for it.
     */
    public function forceDelete(CustomField $field): void
    {
        $this->assertVisible($field);

        $field->forceDelete();

        $this->dispatch(new Events\CustomFieldDeleted($field, forced: true));
    }

    /**
     * Validation rules for the model's active fields, keyed by field key.
     * Handy in form requests, e.g. prefix the keys with "custom_fields.".
     *
     * @param  Model|class-string<Model>|string  $model
     * @return array<string, array<int, mixed>>
     */
    public function rules(Model|string $model, bool $partial = false): array
    {
        return $this->fieldValidator->rules($this->fieldsFor($model), $partial);
    }

    /**
     * Validate input for the model's fields and return the normalized values
     * that would be stored, keyed by field key.
     *
     * @param  Model|class-string<Model>|string  $model
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validate(Model|string $model, array $input, bool $partial = false): array
    {
        return $this->fieldValidator->validate(
            $this->fieldsFor($model),
            $input,
            $partial,
            (bool) config('custom-fields.strict'),
        );
    }

    /**
     * Register a field type, or replace a built-in one.
     *
     * @param  FieldType|class-string<FieldType>  $type
     */
    public function registerType(string $name, FieldType|string $type): void
    {
        $this->types->register($name, $type);
    }

    public function types(): FieldTypeRegistry
    {
        return $this->types;
    }

    /**
     * Forget field lists loaded during this request, and the cached ones when
     * caching is on. Called automatically whenever a field model is saved,
     * deleted or restored. Call it yourself after changing the fields table
     * without Eloquent.
     */
    public function flush(): void
    {
        $this->loaded->exchangeArray([]);

        if (config('custom-fields.cache.enabled')) {
            // A new version makes every cached field list unreachable at once, on any cache store.
            $this->cache()->forever('custom-fields:version', bin2hex(random_bytes(8)));
        }
    }

    /**
     * Dispatch a package event unless events are turned off in config.
     *
     * @internal
     */
    public function dispatch(object $event): void
    {
        if (config('custom-fields.events')) {
            $this->container->make('events')->dispatch($event);
        }
    }

    /**
     * The morph class stored in model_type for a model, model class or morph alias.
     *
     * @param  Model|class-string<Model>|string  $model
     */
    public function morphClass(Model|string $model): string
    {
        if ($model instanceof Model) {
            return $model->getMorphClass();
        }

        if (is_subclass_of($model, Model::class)) {
            return (new $model)->getMorphClass();
        }

        if (Relation::getMorphedModel($model) !== null) {
            return $model;
        }

        throw CustomFieldException::invalidModel($model);
    }

    /**
     * @return Collection<string, CustomField>
     */
    private function loadFields(string $morph, string $scopeKey): Collection
    {
        $query = $this->fieldModel()::query()
            ->where('model_type', $morph)
            ->whereIn('scope_key', array_unique(['', $scopeKey]));

        if (config('custom-fields.cache.enabled')) {
            // Raw attribute arrays, not models: caches may refuse to unserialize objects.
            $rows = $this->cache()->remember(
                'custom-fields:'.$this->cacheVersion().':'.sha1($morph.'|'.$scopeKey),
                (int) config('custom-fields.cache.ttl'),
                fn () => $query->get()->map(fn (CustomField $field) => $field->getAttributes())->all(),
            );

            $fields = $this->fieldModel()::hydrate($rows);
        } else {
            $fields = $query->get();
        }

        // Global fields first, so keyBy() lets a scoped field replace a global one.
        // toBase(): an Eloquent collection's only()/except() match primary keys, not field keys.
        return $fields->toBase()
            ->sortBy(fn (CustomField $field) => $field->scope_key === '' ? 0 : 1)
            ->keyBy('key')
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validateDefinition(array $attributes, ?CustomField $field = null): array
    {
        $creating = $field === null;
        $required = $creating ? 'required' : 'sometimes';

        $this->validator->make($attributes, [
            'model' => $creating ? ['required', 'string'] : ['prohibited'],
            'key' => [$required, 'string', 'regex:/^[a-z][a-z0-9_]{0,63}$/'],
            'label' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => [$required, 'string', Rule::in($this->types->names())],
            'settings' => ['sometimes', 'array'],
            'validation_rules' => ['sometimes', 'array', 'list'],
            'validation_rules.*' => ['string'],
            'is_required' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'is_searchable' => ['sometimes', 'boolean'],
            'is_filterable' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer'],
        ], [
            'key.regex' => 'The key must start with a letter and contain only lowercase letters, numbers and underscores.',
        ])->after(function ($validator) use ($attributes) {
            foreach (array_diff(array_keys($attributes), self::CREATABLE) as $unknown) {
                $validator->errors()->add((string) $unknown, "Unknown attribute [{$unknown}].");
            }

            foreach ($this->fieldValidator->unknownRules((array) ($attributes['validation_rules'] ?? [])) as $rule) {
                $validator->errors()->add('validation_rules', "Unknown validation rule [{$rule}].");
            }
        })->validate();

        $type = $this->types->get($attributes['type'] ?? $field?->type);
        $settings = [...$type->defaultSettings(), ...($attributes['settings'] ?? $field->settings ?? [])];

        $errors = $this->validator->make($settings, $type->settingsRules())->errors();

        if ($errors->isNotEmpty()) {
            throw ValidationException::withMessages(
                collect($errors->toArray())->mapWithKeys(fn ($messages, $key) => ["settings.{$key}" => $messages])->all()
            );
        }

        unset($attributes['model']);

        return [...$attributes, 'settings' => $settings];
    }

    private function assertKeyIsFree(CustomField $field): void
    {
        $taken = $this->fieldModel()::withTrashed()
            ->where('model_type', $field->model_type)
            ->where('scope_key', CustomField::scopeKeyFor($field->scope_type, $field->scope_id))
            ->where('key', $field->key)
            ->when($field->exists, fn ($query) => $query->whereKeyNot($field->getKey()))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['key' => "The key [{$field->key}] is already in use."]);
        }
    }

    private function isVisible(CustomField $field): bool
    {
        $scopeKey = CustomField::scopeKeyFor($field->scope_type, $field->scope_id);

        return in_array($scopeKey, ['', CustomField::scopeKeyFor($this->currentScope())], true);
    }

    private function assertVisible(CustomField $field): void
    {
        if (! $this->isVisible($field)) {
            throw CustomFieldException::outOfScope($field->key);
        }
    }

    private function cache(): CacheRepository
    {
        return $this->container->make('cache')->store(config('custom-fields.cache.store'));
    }

    private function cacheVersion(): string
    {
        return (string) $this->cache()->rememberForever('custom-fields:version', fn () => bin2hex(random_bytes(8)));
    }

    /**
     * @return class-string<CustomField>
     */
    private function fieldModel(): string
    {
        return config('custom-fields.models.field');
    }
}
