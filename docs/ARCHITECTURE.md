# Implementation notes

Runtime uses host-provided Laravel, phpseclib and AWS SDK dependencies. All PHP
classes live below the installer-derived plugin namespace. Root Plugin.php and
runtime/agent.py are in the source archive; no Composer/npm execution is assumed.

`Actions/FeatureAction.php` builds only controls verified in Vito's dynamic-field
renderer: alert, text, password and select. It never registers arbitrary React
components or replaces core routes/controllers. The standard controller provides
login/project checks; each handler repeats server authorization. Worker execution
rechecks the submitter's current policy and provider ownership/project scope.
Every resource query includes the current project/server and explicit cluster.
Cross-cluster restore selects a source backup on that server by plugin record ID.

Models use seven `vpgm_*` tables plus settings. No writes go to Vito's ordinary
database/database-user/backup tables. Foreign identifiers are checked on use;
records survive server deletion for recoverability but cannot target a different
project/server. Synchronization only changes local metadata and never PostgreSQL
grants or data. Offline refresh preserves previous resource inventory.

Operations are durable database records rather than Laravel serialized jobs.
The worker is registered as a background scheduled command. Both request handlers
and the worker use an atomic lifecycle lock; cache must be shared across control
processes. Disable changes the enabled flag without waiting for running work;
uninstall must obtain that lock before Vito can remove code. No separate daemon or
external panel is introduced. The one-operation-per-minute queue trades throughput
for predictable lifecycle behavior in the initial version.

Remote requests use fresh private directories and SFTP files. The worker runs as
postgres through Vito's configured SSH account. All subprocesses use argument
arrays; SQL identifiers and literals have separate quoting. PostgreSQL credentials
are never supplied in process arguments. SCRAM conversion happens in PHP; password
operations run synchronously and never serialize secrets. PostgreSQL statement
logging is suppressed in the credential-changing session. Existing external audit
extensions/proxies must also honor the deployment's credential redaction policy.
No arbitrary SQL action is exposed. Raw subprocess/provider errors are not
reported to Vito logs or persisted in operation history.

Primary reviewed sources:

- [Official template](https://github.com/vitodeploy/plugin-template)
- [Pinned plugin APIs](https://github.com/vitodeploy/vito/tree/8598e4dc0bce12d2b2f915766a100b445b7d9e22/app/Plugins)
- [Installer and namespace normalization](https://github.com/vitodeploy/vito/blob/8598e4dc0bce12d2b2f915766a100b445b7d9e22/app/Actions/Plugins/Github/InstallGithubPlugin.php)
- [SSH helper logging/transfer behavior](https://github.com/vitodeploy/vito/blob/8598e4dc0bce12d2b2f915766a100b445b7d9e22/app/Helpers/SSH.php)
- [PostgreSQL default privileges](https://www.postgresql.org/docs/18/sql-alterdefaultprivileges.html)
