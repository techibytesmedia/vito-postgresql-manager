# Verification ledger

Security follow-up on October 15, 2026: version 0.1.1 passed 26 Linux unit tests
and six real PostgreSQL security regressions in an offline, resource-limited
sandbox. Both issues were reproduced against 0.1.0 and the patched SQL, backup,
restore and grant paths passed. See [SECURITY.md](SECURITY.md) for exact scope,
negative controls and isolation limits.

Target source: Vito 4.1.1, commit
`8598e4dc0bce12d2b2f915766a100b445b7d9e22`.

Verified locally on October 14, 2026 (America/Los_Angeles):

| Check | Result |
| --- | --- |
| Python worker/packaging regressions | 23 passed on Linux/Python 3.12; 22 passed, one POSIX symlink test skipped on Windows/Python 3.14 |
| PHP syntax | 37 PHP files passed on PHP 8.4.12 |
| Actual Vito extraction/discovery/install/enable/disable/uninstall/reinstall/migrate | Passed using generated wrapper-directory source archive and fresh Vito SQLite database |
| Actual Vito native controls, policies, ID tampering, provider scope, execution-time authorization, deduplication, DST slots | 42 host assertions passed |
| Follow-up native menu availability regression | 59 host assertions passed, including enabled state for all 17 action items |
| Browser UI on the local Vito instance | Login, Features navigation, all action-menu entries, discovery, adoption, application login creation, inline missing-name validation, database creation, backup and confirmed fresh-target restore passed |
| Release namespace rewriting | Another 42 host assertions passed after rewriting to a disposable example owner/repository namespace; installer discovered, loaded and managed it successfully |
| Final GitHub namespace | 59 host assertions passed for `Techibytesmedia\\VitoPostgresqlManager` in a separate disposable pinned Vito checkout; 23 Linux Python tests and 39 PHP syntax checks passed |
| Full real SSH/SFTP coexistence scenario | 92 combined host/live assertions passed |
| Managed environment | Ubuntu Base 24.04.4, x86_64, disposable WSL PID/mount namespace and chroot |
| PostgreSQL | 18.6-1.pgdg24.04+2, cluster 18/main, test port 55487, local peer administration and SCRAM application authentication |
| MariaDB | 10.11.14-MariaDB-0ubuntu0.24.04.1, native Vito database/login creation and native backup handler passed |
| Control environment | Actual pinned Vito 4.1.1 / Laravel 13.34.0, Windows PHP 8.4.12; no core runtime source patches |

The live run checked repeated adoption, identical names on both engines, real
application login/reset with punctuation-heavy passwords, nonadministrative role
flags, existing and future schema/table/sequence grants for two object creators,
role-dependency refusal, quoted identifiers/command-injection payloads, custom
backup and checksum-verified fresh-target restore, existing-target refusal,
offline/start/stop/restart, cron/timezone/retention, manual-backup preservation,
password absence from operation records/server logs, and actual Vito uninstall
without PostgreSQL data, MariaDB management, or backup metadata loss.

Subprocess failures/timeouts and symlink/path guards have regression tests using
controlled subprocess substitutes. Those tests are not evidence of a real SSH
timeout, disk-full event, provider outage, or power failure.

The browser follow-up found and fixed an availability bug: Vito disables menu
items when `active()` is false. It also clarified that queued results require a
Features-page reload because Vito supplies native dialog definitions with that
page response. The stock checkout's bundled frontend assets were stale; an
unmodified frontend build restored the local application. No Vito core source or
plugin-specific frontend component was patched.

The UI run created `browser_demo_owner`, `browser_demo`, and
`browser_demo_restored`, then verified the restored application row matched its
source and MariaDB remained the default engine. The demo uses shared file-cache
locks across separate web and worker processes. See BROWSER_DEMO.md for startup
instructions. Browser evidence is saved locally under `.work/browser-proof/`.

The published 0.1.0 GitHub latest-release download and real Vito URL installer
were subsequently verified on the separate disposable Vito 4.1.1 instance:
download, installation, enable and all 17 action registrations passed.

Not exercised: ARM64, S3 network transfers and a Linux control application runtime.

The permanent identity is now `techibytesmedia/vito-postgresql-manager` on GitHub,
with the same source mirrored to GitLab. The intended deployment uses Local
storage; S3 network verification is not required for that deployment and remains
an unverified optional integration.

Deployment checks remaining: ARM64 functional exercise and confirmation of the
live Vito commit. The target
server reports `arm64`; this confirms its architecture, not plugin functionality.
