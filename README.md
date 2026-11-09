# Laravel Custom Fields

User-defined custom fields for Eloquent models. Add a trait to a model, define fields at runtime, and store values without changing the model's table.

> Work in progress. Not ready for production use yet.

## Requirements

- PHP 8.3+
- Laravel 12 or 13

## Installation

```bash
composer require kenprz/laravel-custom-fields
```

Publish the config:

```bash
php artisan vendor:publish --tag=custom-fields-config
```

## Testing

```bash
composer test
composer analyse
composer lint
```

## License

MIT. See [LICENSE](LICENSE).
