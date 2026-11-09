# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- `custom_fields` and `custom_field_values` tables, with configurable names.
- `CustomField` and `CustomFieldValue` models, swappable through config.
- `run_migrations` option to turn off the package migrations after publishing them.
