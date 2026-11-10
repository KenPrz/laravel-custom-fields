# Performance

These numbers come from the included benchmark: 10 custom fields per record, a value for every
field, PHP 8.5, and databases in Docker on one laptop. Your numbers depend on your hardware.
Compare the shape, not the exact times.

## Reading

20,000 records and 200,000 values, loaded at once:

| | SQLite | MySQL 8.4 | PostgreSQL 17 |
|---|---|---|---|
| Plain Eloquent, same rows (baseline) | 3.9 s | 4.4 s | 3.9 s |
| `withCustomFields()->get()` | 4.6 s | 5.0 s | 5.2 s |
| `listCustomFields()` on every record | 1.9 s | 1.9 s | 2.2 s |
| `toArray()` on every record | 2.9 s | 2.8 s | 3.2 s |

- Eager loading always takes **2 queries** (records and values), plus 1 query per request for
  field definitions. The number of records or fields doesn't change this.
- Memory is the same as plain Eloquent: about 330 MB for these 220,000 models.
- For bigger sets, chunk the query. `chunkById(1000)` over 100,000 records ran in about 25 s,
  with flat memory (1 MB growth) and 2 queries per chunk.

```php
Reservation::withCustomFields()->chunkById(1000, function ($reservations) {
    foreach ($reservations as $reservation) {
        // ...
    }
});
```

## Filtering

100,000 records and 1,000,000 values. One query each, with exact results checked:

| | SQLite | MySQL 8.4 | PostgreSQL 17 |
|---|---|---|---|
| equality on a select | 0.16 s | 0.25 s | 0.06 s |
| numeric range | 0.18 s | 0.48 s | 0.09 s |
| date range | 0.18 s | 0.43 s | 0.05 s |
| list contains | 0.18 s | 0.46 s | 0.09 s |
| text contains | 0.20 s | 0.43 s | 0.06 s |
| two filters combined | 0.22 s | 0.44 s | 0.10 s |

Filter time grows linearly with the number of values for that field. See
[Querying](/guide/querying#performance) for why there is no index on the values.

## Writing

A write takes about 1.5–4 ms per record for 3 fields. That covers validation, one read of the
existing rows, and one update per changed value. The package doesn't write unchanged values.

A transaction around a bulk loop doesn't change much. Eloquent and the validator take the time,
not the commit.

## Run the benchmark

```bash
PERF_RECORDS=100000 composer bench
```

## Tips

- Set `$customFields` on the model. Then the first fill per request doesn't need a query for the
  field keys.
- Use `withCustomFields()` or `$loadCustomFields = true` for lists.
- Turn on the [cache](/advanced/caching) to skip the definitions query on each request.
