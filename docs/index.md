---
layout: home

hero:
  name: Laravel Custom Fields
  text: Custom fields for Eloquent models
  tagline: Define fields at runtime. Use them like normal attributes. No new columns.
  actions:
    - theme: brand
      text: Get started
      link: /guide/introduction
    - theme: alt
      text: Examples
      link: /examples/production

features:
  - title: Works like an attribute
    details: $user->tax_bracket, fill(), create(), update() and toArray() all work. Real columns always win.
  - title: Validated on every write
    details: Type rules, select options and your own Laravel rules run before any SQL.
  - title: Multi-tenant
    details: Global fields and per-tenant fields, with tenant isolation on every read and write.
  - title: Queryable
    details: Filter by custom field values on MySQL, PostgreSQL and SQLite.
---

```php
class User extends Model
{
    use HasCustomFields;
}

CustomFields::create([
    'model' => User::class,
    'key' => 'tax_bracket',
    'label' => 'Tax bracket',
    'type' => 'select',
    'settings' => ['options' => ['a' => 'A', 'b' => 'B']],
]);

$user = User::create(['name' => 'Ken', 'tax_bracket' => 'b']);
$user->tax_bracket;   // "b"

User::whereCustomField('tax_bracket', 'b')->get();
```
