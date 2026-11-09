<?php

declare(strict_types=1);

namespace KenPrz\CustomFields\Contracts;

use KenPrz\CustomFields\Models\CustomField;

/**
 * Describes how one kind of custom field validates and stores its values.
 *
 * Register your own with CustomFields::registerType('name', MyType::class).
 */
interface FieldType
{
    /**
     * Laravel validation rules for a single value of this type.
     *
     * The field's own `validation_rules` are added after these, and
     * `required`/`nullable` are handled by the package.
     *
     * @return array<int, mixed>
     */
    public function rules(CustomField $field): array;

    /**
     * Convert a value that passed validation into the value that is stored.
     *
     * Never receives null. The result must be JSON encodable, and is what
     * getCustomField() returns later.
     */
    public function normalize(mixed $value, CustomField $field): mixed;

    /**
     * Settings a new field of this type starts with. Settings given on
     * create are merged over these.
     *
     * @return array<string, mixed>
     */
    public function defaultSettings(): array;

    /**
     * Laravel validation rules for this type's settings, keyed by setting name.
     *
     * @return array<string, mixed>
     */
    public function settingsRules(): array;
}
