<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use KenPrz\CustomFields\CustomFieldManager;

/**
 * A field definition attached to a model type, optionally limited to one scope.
 *
 * @property int $id
 * @property string $model_type
 * @property string|null $scope_type
 * @property string|null $scope_id
 * @property string $scope_key
 * @property string $key
 * @property string $label
 * @property string|null $description
 * @property string $type
 * @property array<string, mixed> $settings
 * @property array<int, mixed> $validation_rules
 * @property bool $is_required
 * @property bool $is_active
 * @property bool $is_searchable
 * @property bool $is_filterable
 * @property int $sort_order
 */
class CustomField extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'model_type',
        'key',
        'label',
        'description',
        'type',
        'settings',
        'validation_rules',
        'is_required',
        'is_active',
        'is_searchable',
        'is_filterable',
        'sort_order',
    ];

    protected $attributes = [
        'settings' => '[]',
        'validation_rules' => '[]',
        'is_required' => false,
        'is_active' => true,
        'is_searchable' => false,
        'is_filterable' => false,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'validation_rules' => 'array',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'is_searchable' => 'boolean',
            'is_filterable' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (CustomField $field): void {
            $field->scope_key = static::scopeKeyFor($field->scope_type, $field->scope_id);
        });

        static::saved(fn () => app(CustomFieldManager::class)->flush());
        // restore() saves, so restored fields are covered by saved.
        static::deleted(fn () => app(CustomFieldManager::class)->flush());

        // The foreign key cascades too, but SQLite only enforces it when foreign keys are on.
        static::forceDeleting(function (CustomField $field): void {
            $field->values()->delete();
        });
    }

    /**
     * The scope_key for a scope: '' for global, "{morph class}:{key}" otherwise.
     */
    public static function scopeKeyFor(Model|string|null $scope, int|string|null $id = null): string
    {
        if ($scope instanceof Model) {
            return $scope->getMorphClass().':'.$scope->getKey();
        }

        return $scope === null ? '' : $scope.':'.$id;
    }

    public function getTable(): string
    {
        return config('custom-fields.tables.fields', 'custom_fields');
    }

    /**
     * @return HasMany<CustomFieldValue, $this>
     */
    public function values(): HasMany
    {
        /** @var class-string<CustomFieldValue> $model */
        $model = config('custom-fields.models.value');

        return $this->hasMany($model, 'custom_field_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function scope(): MorphTo
    {
        return $this->morphTo();
    }
}
