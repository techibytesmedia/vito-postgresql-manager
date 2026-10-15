# Vito PostgreSQL Manager

A standalone native server feature for **Vito 4.1.0 and 4.1.1**. Exact official
release commits are recorded in [release.json](release.json). PostgreSQL is managed under
**Servers → Features → PostgreSQL Manager**. MariaDB remains on Vito's existing
Databases, Users, and Backups pages. No core patches or frontend build are needed.

**Use v0.1.1 or later.** Version 0.1.1 fixes database connection-string injection
and privileged schema search-path handling present in 0.1.0. See
[security fixes and regression coverage](docs/SECURITY.md).

Source repositories: [GitHub](https://github.com/techibytesmedia/vito-postgresql-manager)
and [GitLab](https://gitlab.com/techibytesmedia/vito-postgresql-manager). Vito's URL
installer uses the GitHub repository and requires a published normal release.
See [verification](docs/VERIFICATION.md) for exact checks performed and remaining
release gates. Other Vito releases are rejected until their APIs are verified.

## Requirements

- Control application: Vito 4.1.0 or 4.1.1, PHP 8.4+, its existing locked
  Laravel/SDK dependencies, and a shared cache supporting atomic locks.
- Vito's scheduler must run every minute. Background PHP commands must be allowed
  on the control host. No extra Redis worker configuration is required.
- Managed host: Debian-style PostgreSQL cluster tooling on Ubuntu 24.04,
  PostgreSQL 18 with matching `psql`, `pg_dump`, and `pg_restore`, Python 3.11+,
  a `postgres` Unix account and local peer authentication using
  `/var/run/postgresql`. Nonstandard socket/config layouts are rejected.
- Vito's configured SSH login must support SFTP and noninteractive sudo for
  temporary-file handling, running the worker as postgres, and `pg_ctlcluster`.
  The plugin does not request another SSH account or a postgres password.
- A Vito **Local** or **S3** storage provider for backups. A Local directory lives
  on the managed server and must already be writable by postgres. S3 reuses the
  existing encrypted Vito credentials and the control host's AWS SDK.
- Managed-host integration has been verified on x86_64. ARM64 functional testing
  remains pending; confirming `dpkg --print-architecture` alone is insufficient.

## Installation from GitHub

The source namespace is configured for `techibytesmedia/vito-postgresql-manager`.
All runtime files must be committed in the tagged source tree; an uploaded ZIP
asset alone does not work. See [the release procedure](docs/RELEASING.md).

1. In Vito open **Admin → Plugins → Install** and paste
   `https://github.com/techibytesmedia/vito-postgresql-manager`.
2. Install, then enable from **Installed**. Installation initially leaves it disabled.
3. Open the managed server's **Features → PostgreSQL Manager**.

The installer downloads the latest published normal GitHub release. A tag,
draft or prerelease alone does not satisfy that flow. To upgrade an installed plugin,
disable it, wait for running operations, update to 0.1.2, then re-enable.
**Vito 4.1.0 requires plugin 0.1.2 or later**; 0.1.1's version guard rejects it.

If 0.1.1 failed with “This build targets the reviewed Vito 4.1.1 APIs”, open
**Admin → Plugins → Check for updates**. In **Discovered**, use the plugin's
menu to **Update**, then **Install**. Finally enable it from **Installed**.
The failed installation leaves a plugin record, so pasting the repository URL
again can report “Plugin is already installed”. Updating that record avoids
deleting it or changing Vito's core files. The version check runs before metadata
migrations and does not contact the managed PostgreSQL server.

For local development use the generated source tree in
`app/Vito/Plugins/Techibytesmedia/VitoPostgresqlManager`, then use **Discover**, install,
and enable in Admin → Plugins. See [testing](docs/TESTING.md).

## Adoption and daily use

1. Submit **Discover installed clusters**. It runs on the next scheduler tick.
2. Reload the Features page and open **Status, inventory & operation history**, then **Adopt an existing
   cluster**. Select the discovered `18/main` and verify its actual port.
3. Repeated adoption is safe. It verifies cluster identity and administrative
   access and imports metadata. It never installs packages, initializes a cluster,
   resets a password, changes authentication, or alters database contents.
4. Use **Refresh cluster status & inventory** after outside changes. Forms read
   stored snapshots and perform no SSH while Features renders. Status shows bind
   addresses, local socket directories, HBA rules, installed locales, databases,
   login roles, operation results, backup history, and schedule configuration.
5. Choose an explicit cluster for every action. Resource dropdowns contain IDs
   and cluster labels; backend queries enforce project/server/cluster scope.

**Create application login** and **Reset login password** run immediately to avoid
persisting a password or verifier in the work queue. Passwords must be 12–1024
printable ASCII characters; punctuation/spaces are supported. Plaintext passwords
are never retained. Login creation sets NOSUPERUSER, NOCREATEDB, NOCREATEROLE,
NOREPLICATION, and NOBYPASSRLS. Administrative, system, and membership-bearing
roles cannot be changed through this plugin.

**Create PostgreSQL database** requires an application owner. UTF8 and LATIN1 are
supported with a compatible installed locale; use the exact locale from Status
(normally `C.utf8`). Creation uses template0. Names allow 1–63 UTF-8 bytes, including
quoted identifiers. System databases and roles are protected.

**Grant read or write access** works on databases explicitly marked for access
management. Newly created/restored databases are marked automatically; for an
imported database use **Enable access management for a database** and confirm its
identity first. Import alone preserves all existing grants.

- Read adds schema USAGE, table SELECT, and sequence SELECT.
- Write adds table INSERT/UPDATE/DELETE and sequence USAGE/UPDATE. It does not add DDL.
- Grants are additive, including defaults for objects created by the database
  owner and selected creator. Repeat for every additional object-creating login.
  Future schemas receive USAGE too. Existing grants, PUBLIC privileges, RLS, and
  security-definer function behavior remain in effect; choosing read never removes
  earlier write access. These are grant operations, not a full access audit.
- Ownership changes affect the **database only**. Existing schema/object ownership
  is preserved. Role deletion refuses ownership or grant dependencies instead of
  dropping objects or running REASSIGN/DROP OWNED.

**Show application connection details** reports the chosen database/login,
actual port, bind configuration, and local sockets. Choose a local address/socket
allowed by HBA for an application on the managed server. No password is revealed.
The plugin never opens a firewall or changes bind/HBA settings.

Deletion, ownership changes, restore, and service controls require project-owner
access (Vito's server delete policy); other mutations require server update access.
Disruptive operations require typing the exact server/cluster/resource identity.
Service controls invoke only `pg_ctlcluster VERSION CLUSTER ACTION`.

## Backups and restore

Manual and cron backups use PostgreSQL custom archives and the adopted major
version's own binaries. Metadata includes server, cluster, source database,
engine, format, major/server version, storage identity, size, and SHA-256.
They do not pass through Vito's default-database backup executor.

Configure a five-field cron expression, IANA timezone, storage provider and
retention count using **Configure scheduled backup & retention**. There is one
schedule per database. Set its state to disabled to stop future backups. Schedules
do not catch up missed minutes; a repeated DST clock minute is not run twice.
Retention applies only to successful backups made by that schedule, after another
successful backup, and never removes unrelated or manual artifacts.

Local backups use `PROVIDER_PATH/vito-postgresql/PROJECT/SERVER/CLUSTER/ARTIFACT.dump`.
S3 uses the same prefix under the configured provider path. Backups stage on the
managed server; S3 transfers also stage on the control host. Provision enough disk
space. S3 credentials stay on the control host. Provider path/bucket changes block
restore/retention until restored to the original configuration.

**Restore backup into a NEW database** accepts a completed plugin PostgreSQL backup
from the same managed server, a target adopted cluster of the same/newer major,
an application owner, locale/encoding, and a fresh database name. It validates
archive magic, dump version and checksum. Source ACLs/owners are not restored.
Only restore trusted archives: PostgreSQL restore executes SQL. A failed restore
can leave the newly created database for inspection; it is never silently deleted.
Existing target databases are never dropped or overwritten.

## Queue, lifecycle and updates

After queued work completes, reload Features before opening Status or the next
action. Vito generates these native dialog forms with the page response; closing
and reopening a dialog alone does not fetch new results or selection lists.

Long operations live in `vpgm_operations` and run one at a time using Vito's Laravel
scheduler and the `vito-pg:work` command. This durable plugin queue intentionally
does not serialize plugin classes into Redis/database jobs: uninstall can cancel
pending operations without leaving unloadable jobs. Status reports pending,
running, complete, failed, or cancelled. A global atomic lock also prevents
overlapping backups or conflicting service controls. Submissions during an active
operation receive a retry message. No automatic retry of an uncertain mutation.

Install creates versioned `vpgm_*` tables only. Enable applies missing metadata
migrations. Boot only registers native actions, commands and the scheduler event.
Disable stops new work and cancels pending operations; an already executing
operation may finish. Uninstall refuses while a worker holds the lifecycle lock,
removes code through Vito, and preserves all metadata and backups. No PostgreSQL
or MariaDB objects/packages are removed. Forced Vito uninstall bypasses this guard
and must not be used while work is active.

Vito's updater replaces files without calling install/update hooks. Disable, wait
for running work, update, then re-enable. Enable performs the versioned schema
migration. When available while enabled, `php artisan vito-pg:work --migrate`
also migrates and leaves new work disabled. Restart long-lived PHP processes after
updates. Preserve the final namespace for the lifetime of an installation.

## Troubleshooting

- Pending work: verify `php artisan schedule:list`, the normal Vito scheduler, and
  background-process support. For diagnosis run `php artisan vito-pg:work` from
  Vito's control application directory as its normal service user.
- Offline cluster: forms still work from metadata; start the selected cluster,
  then refresh. Changed port/owner/config paths require rediscovery and adoption.
- SSH failure: check Vito's own server connection and noninteractive sudo. Remote
  stderr/SQL errors are suppressed to prevent secret leaks; check the cluster's
  server logs using your established administrative access when necessary.
- Login dependencies: change relevant database/object ownership and explicit
  grants through your normal administration process; the plugin does no blanket
  cleanup. Database ownership alone does not transfer every object.
- Interrupted worker: after 65 minutes a later worker marks its audit record
  failed. Refresh inventory before retrying; do not assume the mutation rolled back.
- Temp files normally clean in finally blocks. After abrupt process/host death,
  a host administrator can inspect and remove old `/tmp/vito-pgm-*`,
  `/tmp/vpgm-export-*`, and control-host `vpgm-*` temp files after confirming no
  worker is active. Temporary request files contain SCRAM verifiers, not plaintext.
- Failed update: disabling does not require the schema to be current. Restore
  the matching source version; reinstall preserves metadata. Never downgrade to
  code older than the metadata schema. Back up Vito's application database before
  upgrades. Permanent metadata/artifact cleanup is deliberately a separate manual
  administrative operation.

See [architecture](docs/ARCHITECTURE.md), [testing](docs/TESTING.md), and
[release preparation](docs/RELEASING.md).

For the persistent local Vito browser demo, see [BROWSER_DEMO.md](docs/BROWSER_DEMO.md).
