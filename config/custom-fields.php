<?php

declare(strict_types=1);
use KenPrz\CustomFields\Models\CustomField;
use KenPrz\CustomFields\Models\CustomFieldValue;

return [

    /*
    |--------------------------------------------------------------------------
    | Table names
    |--------------------------------------------------------------------------
    |
    | Set these before running the migrations. Changing them afterwards will
    | not rename existing tables.
    |
    */

    'tables' => [
        'fields' => 'custom_fields',
        'values' => 'custom_field_values',
    ],

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | Swap in your own models here. They must extend the package models.
    |
    */

    'models' => [
        'field' => CustomField::class,
        'value' => CustomFieldValue::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Run migrations
    |--------------------------------------------------------------------------
    |
    | When true, the package migrations run with `php artisan migrate`.
    | Set this to false if you publish the migrations to edit them.
    |
    */

    'run_migrations' => true,

    /*
    |--------------------------------------------------------------------------
    | Strict mode
    |--------------------------------------------------------------------------
    |
    | When true, writing or reading a field key that doesn't exist throws.
    | When false, unknown keys are ignored on write and read as the default.
    |
    */

    'strict' => true,

    /*
    |--------------------------------------------------------------------------
    | Scope resolver
    |--------------------------------------------------------------------------
    |
    | A class implementing KenPrz\CustomFields\Contracts\ScopeResolver that
    | returns the current tenant (or property, organization...). Leave null
    | when you don't need scoped fields or pass the scope yourself.
    |
    */

    'scope_resolver' => null,

    /*
    |--------------------------------------------------------------------------
    | Delete values with the model
    |--------------------------------------------------------------------------
    |
    | When a model using HasCustomFields is deleted, delete its stored values
    | too. Soft deleted models keep their values until force deleted. Turn
    | this off if you want to clean up yourself.
    |
    */

    'delete_values_with_model' => true,

];
