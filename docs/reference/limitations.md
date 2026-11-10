# Limitations

## Overridden model methods

The trait overrides `getAttribute()`, `setAttribute()`, `isFillable()`, `fillableFromArray()`,
`attributesToArray()` and `save()`. If your model or another trait overrides one of them too,
call the trait's version. If you don't, attribute access doesn't fully work.

A custom `save()` still gets validation, and values are still written through model events.
They are not written in the same transaction as the model.

## First fill per request

The first fill per request runs one small query for the model's field keys. Set
`$customFields` on the model to skip it.

## Type changes

You can't change the type of a field that has values. See
[Managing fields](/guide/managing-fields#type-changes).

## Queries use the request scope

Query scopes use the scope resolver, not each record's `customFieldScope()`. See
[Multi-tenancy](/advanced/multi-tenancy#queries).

## Large-scale filtering and sorting

There is no index on the values. Range and text filters scan every value of the field. See
[Querying](/guide/querying#performance).

There is no `orderByCustomField()`. Sorting EAV data can't use an index on any database. If you
must sort by a value across large tables, use a real column.

## Object key order

MySQL and PostgreSQL can reorder the keys of objects stored as values in `json` fields. Field
settings, such as the order of select options, keep the order you gave.

## Text search on SQLite

Text search on SQLite ignores case for ASCII letters only.

## One connection

The package tables use the default database connection. To move them:

1. Point `models.field` and `models.value` at your own subclasses that set `$connection`.
2. Run the migration on that connection.

## Changing the morph map

Changing the morph map after values are stored orphans those values. The `model_type` and
`entity_type` columns keep the old names.

## Messages

Messages for unknown and inactive fields are in English. You can't translate them yet.
