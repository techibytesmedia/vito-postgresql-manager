# Security fixes in 0.1.1

Upgrade 0.1.0 before production use. This is a focused remediation of two issues,
not a full independent penetration test or a guarantee that no other issues exist.

## Literal database names

PostgreSQL command-line tools interpret a raw `--dbname` containing `=` or a
connection URI as connection parameters. In 0.1.0, a database named
`dbname=postgres` redirected an administrative query to `postgres` instead of
that literal database. Other connection parameters could also override the
intended target.

The worker now constructs the complete libpq keyword/value string itself,
quotes single quotes and backslashes using libpq's grammar, and fixes the Unix
socket, adopted port, administrative login, startup options and password-file
policy. The quoted database value is a literal, not another connection string.
`psql`, `pg_dump` and `pg_restore` all use this same connection builder.

## Privileged schema resolution

The 0.1.0 administrative SQL session inherited database/role search-path defaults.
An ordinary application owner could create a matching function in its schema
and have an administrative inventory query invoke it as `postgres`.

Version 0.1.1 sets `search_path=pg_catalog` in the connection startup options and
resets it before every administrative SQL request. Application schemas are
excluded from administrative function and catalog resolution. Source objects
and application grants remain unchanged.

## Verification

On October 15, 2026, the patched worker passed 26 Linux unit tests and six real
PostgreSQL 18.6 regressions. The live fixture:

- Reproduced the wrong-database result with the unchanged 0.1.0 worker.
- Reproduced an application-owned function reporting its caller as `postgres`
  under 0.1.0; the fixture never changed privileges or touched another resource.
- Verified eight database-name cases, including connection strings, URI forms,
  quotes, backslashes, spaces, Unicode and shell-looking text, retain the exact
  database name, port and login under 0.1.1.
- Verified administrative schema queries and additive grants ignore a hostile
  database search path and the shadow function.
- Created a custom archive from a specially named source, restored its actual
  row into a specially named new database, and rejected an existing target.

The run used a fresh cluster and dummy application logins in private mount, PID
and network namespaces. Root/toolchain/source were read-only; only a 512 MiB
scratch filesystem was writable. No external network, host homes, credentials,
shared sockets or existing services were exposed. The process ran without root
or capabilities and with an empty allowlisted environment. Limits were 180s
wall clock, 120s CPU per process, one CPU core, 768 MiB aggregate memory, 96 processes, 256
open files and 128 MiB per file. Scratch contents were discarded after the run.

The fixture is `tests/security_regression.py`. Run it only in an equivalent
isolated environment with PostgreSQL 18 binaries, a private `/scratch`, a
`postgres` Unix identity and `/var/run/postgresql` mapped to `/scratch/socket`:

```sh
python3 -B -m unittest discover -s tests -p 'test_*.py' -v
VPGM_SECURITY_SANDBOX=1 VPGM_BASELINE_AGENT=/source/.baseline/agent.py \
  python3 -B tests/security_regression.py
```

The baseline is a read-only copy of `runtime/agent.py` from tag `v0.1.0`. Without
it, the two negative-control tests are skipped. The remaining four tests still
exercise the patched PostgreSQL methods. Never point this fixture at a live
cluster. The six live tests are separate from CI's ordinary unit/host suite.

ARM64 functional verification and S3 transfers remain unverified. Local storage
was used. These tests exercise the PostgreSQL worker, not a production VPS.

## Upgrade

Disable the plugin, wait for active operations, apply the 0.1.1 update in Vito,
then re-enable it. Keep the existing namespace. There is no metadata schema
change, and these fixes do not alter database contents or existing grants.
