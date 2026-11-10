# Database compatibility

| Database | Status |
|---|---|
| MySQL 8 | Supported. Tested on 8.4. |
| PostgreSQL | Supported. Tested on 17. Values are stored as `jsonb`. |
| SQLite | Supported. Tested on the version bundled with PHP. Needs the JSON functions (built in since 3.38). |
| MariaDB | Not supported. Reading and writing can work, but the query scopes throw. |
| SQL Server | Not supported. |

CI runs the full test suite against all three supported databases.

## Differences between databases

- **Text search** (`whereCustomFieldContains` on text) ignores case. On SQLite, it ignores case
  for ASCII letters only.
- **Object key order.** MySQL and PostgreSQL can reorder the keys of objects stored in `json`
  fields. Field settings, such as the order of select options, keep the order you gave.
- **Text order.** Strings compare byte by byte on every database, so results are the same
  everywhere.
