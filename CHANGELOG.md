# Changelog

## 0.1.2

- Support official Vito 4.1.0 and 4.1.1 releases with an explicit compatibility list.
- Include detected and supported versions in compatibility errors; apply the
  requirement check on enable even when plugin metadata already exists.
- Test installation, native forms, policies and lifecycle against both releases
  with their own locked dependencies in CI.
- Add a two-request regression for recovering a rejected 0.1.1 installation through
  Vito's Check for updates, Update, Install and Enable actions.
- Record exact supported release commits in generated source metadata.

## 0.1.1

- Security: quote database names inside fixed libpq connection parameters for SQL,
  backup and restore, preventing names from overriding the selected endpoint.
- Security: restrict privileged sessions to `pg_catalog` at connection startup
  and before administrative SQL, preventing application-schema function hijacking.
- Add live PostgreSQL negative controls for both 0.1.0 issues, plus regression
  coverage for special names, backup/restore and grants under a hostile search path.
- Derive generated release metadata from the plugin version instead of a fixed value.

## 0.1.0

- Native PostgreSQL Manager feature and explicit existing-cluster adoption.
- Isolated metadata, database/login management, additive access grants and controls.
- PostgreSQL custom backups, confirmed fresh-target restore and cron retention.
- Durable operation queue, lifecycle guards, source-archive packaging and test lab.
