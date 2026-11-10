# Configuration

Publish the config file to change the defaults:

```bash
php artisan vendor:publish --tag=custom-fields-config
```

This writes `config/custom-fields.php`.

| Key | Default | What it does |
|---|---|---|
| `tables.fields` | `custom_fields` | Name of the field definitions table. Set before you migrate. |
| `tables.values` | `custom_field_values` | Name of the values table. Set before you migrate. |
| `models.field` | `CustomField::class` | Field model. Your class must extend the package model. |
| `models.value` | `CustomFieldValue::class` | Value model. Your class must extend the package model. |
| `run_migrations` | `true` | Run the package migration with `php artisan migrate`. |
| `strict` | `true` | Throw on unknown field keys. See [Validation](/guide/validation#unknown-keys). |
| `scope_resolver` | `null` | Class that returns the current tenant. See [Multi-tenancy](/advanced/multi-tenancy). |
| `delete_values_with_model` | `true` | Delete a model's values when the model is deleted. |
| `events` | `true` | Dispatch the package [events](/advanced/events). |
| `cache.enabled` | `false` | Cache field definitions across requests. |
| `cache.store` | `null` | Cache store name. `null` uses the default store. |
| `cache.ttl` | `3600` | Seconds to keep cached definitions. |

## Your own models

To add relations or methods to the package models, extend them and point the config at your
classes:

```php
namespace App\Models;

use KenPrz\CustomFields\Models\CustomField as BaseCustomField;

class CustomField extends BaseCustomField
{
    protected $connection = 'tenant';
}
```

```php
// config/custom-fields.php
'models' => [
    'field' => App\Models\CustomField::class,
    'value' => App\Models\CustomFieldValue::class,
],
```
