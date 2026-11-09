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

];
