<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Facades;

use Illuminate\Support\Facades\Facade;
use KenPrz\CustomFields\CustomFieldManager;

/**
 * @method static CustomFieldManager forScope(?\Illuminate\Database\Eloquent\Model $scope)
 * @method static CustomFieldManager global()
 * @method static \Illuminate\Database\Eloquent\Model|null currentScope()
 * @method static \Illuminate\Support\Collection<string, \KenPrz\CustomFields\Models\CustomField> fieldsFor(\Illuminate\Database\Eloquent\Model|string $model)
 * @method static \KenPrz\CustomFields\Models\CustomField|null findByKey(\Illuminate\Database\Eloquent\Model|string $model, string $key)
 * @method static \KenPrz\CustomFields\Models\CustomField|null find(int|string $id)
 * @method static \KenPrz\CustomFields\Models\CustomField create(array<string, mixed> $attributes)
 * @method static \KenPrz\CustomFields\Models\CustomField update(\KenPrz\CustomFields\Models\CustomField $field, array<string, mixed> $attributes)
 * @method static \KenPrz\CustomFields\Models\CustomField activate(\KenPrz\CustomFields\Models\CustomField $field)
 * @method static \KenPrz\CustomFields\Models\CustomField deactivate(\KenPrz\CustomFields\Models\CustomField $field)
 * @method static void reorder(\Illuminate\Database\Eloquent\Model|string $model, list<string> $keys)
 * @method static void delete(\KenPrz\CustomFields\Models\CustomField $field)
 * @method static \KenPrz\CustomFields\Models\CustomField restore(\KenPrz\CustomFields\Models\CustomField $field)
 * @method static void forceDelete(\KenPrz\CustomFields\Models\CustomField $field)
 * @method static array<string, array<int, mixed>> rules(\Illuminate\Database\Eloquent\Model|string $model, bool $partial = false)
 * @method static array<string, mixed> validate(\Illuminate\Database\Eloquent\Model|string $model, array<string, mixed> $input, bool $partial = false)
 * @method static void registerType(string $name, \KenPrz\CustomFields\Contracts\FieldType|string $type)
 * @method static \KenPrz\CustomFields\FieldTypeRegistry types()
 * @method static void flush()
 *
 * @see CustomFieldManager
 */
class CustomFields extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CustomFieldManager::class;
    }
}
