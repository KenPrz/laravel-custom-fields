# Custom field types

Implement `KenPrz\CustomFields\Contracts\FieldType`, or extend `KenPrz\CustomFields\Types\Type`
for empty defaults:

```php
use KenPrz\CustomFields\Models\CustomField;
use KenPrz\CustomFields\Types\Type;

class CurrencyFieldType extends Type
{
    // Laravel rules for one value. The package adds required/nullable and the field's own rules.
    public function rules(CustomField $field): array
    {
        return ['string', 'size:3', 'alpha'];
    }

    // Turn a valid value into the stored value (must be JSON encodable). Never receives null.
    public function normalize(mixed $value, CustomField $field): mixed
    {
        return strtoupper($value);
    }

    // Settings that a new field starts with.
    public function defaultSettings(): array
    {
        return [];
    }

    // Rules for this type's settings, keyed by setting name.
    public function settingsRules(): array
    {
        return [];
    }
}
```

Register the type in the `boot()` method of a service provider:

```php
use KenPrz\CustomFields\Facades\CustomFields;

public function boot(): void
{
    CustomFields::registerType('currency', CurrencyFieldType::class);
}
```

Then use it like a built-in type:

```php
CustomFields::create([
    'model' => Invoice::class,
    'key' => 'billing_currency',
    'label' => 'Billing currency',
    'type' => 'currency',
]);
```

## Rules

- Type names must start with a lowercase letter and contain only `a-z`, `0-9`, `_` and `-`
  (max 64 characters).
- Registering a built-in name, such as `phone`, replaces the built-in type.
- Custom types compare as text in queries. If they extend `NumberType`, they compare as
  numbers.

## Example: a phone type with libphonenumber

```php
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use KenPrz\CustomFields\Models\CustomField;
use KenPrz\CustomFields\Types\Type;

class E164PhoneType extends Type
{
    public function rules(CustomField $field): array
    {
        return ['string', function (string $attribute, mixed $value, \Closure $fail) {
            if (! is_string($value)) {
                return;   // the string rule already reports this
            }

            $util = PhoneNumberUtil::getInstance();
            try {
                if (! $util->isValidNumber($util->parse($value, 'US'))) {
                    $fail('The :attribute is not a valid phone number.');
                }
            } catch (\libphonenumber\NumberParseException) {
                $fail('The :attribute is not a valid phone number.');
            }
        }];
    }

    public function normalize(mixed $value, CustomField $field): mixed
    {
        $util = PhoneNumberUtil::getInstance();

        return $util->format($util->parse($value, 'US'), PhoneNumberFormat::E164);
    }
}
```

```php
CustomFields::registerType('phone', E164PhoneType::class);   // replaces the built-in phone type
```
