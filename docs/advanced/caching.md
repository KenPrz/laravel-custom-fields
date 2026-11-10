# Caching

The package loads field definitions at most once per model and scope per request. The same
applies per queue job and per Octane request.

To also cache definitions across requests, turn on the cache:

```php
// config/custom-fields.php
'cache' => [
    'enabled' => true,
    'store' => null,    // null = the default cache store
    'ttl' => 3600,      // seconds
],
```

Any Laravel cache store works. Redis isn't required.

## Clearing the cache

The package clears the cache for every model and scope when you save, delete or restore a
field, through the package or through Eloquent.

If you change the fields table some other way, such as raw SQL or `DB::table()`, clear the
cache yourself:

```php
CustomFields::flush();
```

## Values

The package never caches values. It always reads them from the database, so they can't be
stale.
