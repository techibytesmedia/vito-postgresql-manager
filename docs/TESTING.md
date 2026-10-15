# Reproducible tests

Fetch Vito's pinned source into `.work/` and install its composer.lock dependencies
with PHP 8.4+. Linux needs all Vito extensions; Windows host tests can install with
`--no-dev --no-scripts --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix`.
Do not use those exceptions for a production Linux deployment.

Run the host suite separately against both official releases (CI uses this matrix):

| Vito | Official release commit |
| --- | --- |
| 4.1.0 | `c72f3d039cdc707e69b9659e30fa7727547a1175` |
| 4.1.1 | `b65680dedceaa77c9f1d13ad2a8d2e67d8d2f692` |

```sh
python -m unittest discover -s tests -p 'test_*.py' -v
python tools/test_archive.py dist/development
php tests/host.php PATH_TO_PINNED_VITO dist/development.zip
```

The host suite boots the actual Vito application, migrates a fresh SQLite file,
extracts the generated source archive through Vito's ExtractPlugin, discovers and
installs/enables it through Vito's actual actions, and exercises native form
generation, policies, scope tampering, confirmations, deduplication and lifecycle.
It uses no SSH for these checks and is not evidence of live cluster functionality.
Compatibility checks reject untested/missing versions before creating metadata,
check Vito's error logs and failed-install retry, and reject an unsupported
release on enable even when metadata already exists.
The script prints the isolated database path. The disposable Vito tree may be
removed after testing; no database outside it is used.

For the failed-install recovery regression, obtain the 0.1.1 source archive at
commit `1c371f2202a43810966db71a5a5011d7937471fa`. Use a disposable **Vito 4.1.0**
checkout and a fresh 12-character hexadecimal run ID for each run. Run both commands
as separate PHP processes against the same checkout and run ID:

```sh
php tests/recovery.php PATH_TO_VITO_4_1_0 PATH_TO_0_1_1_ZIP 011012000410 rejected
php tests/recovery.php PATH_TO_VITO_4_1_0 dist/development.zip 011012000410 recovered
```

The first command runs the real 0.1.1 code and verifies its rejection leaves a
plugin row with no metadata. The second tests **Check for updates → Update →
Install → Enable** using Vito's real installer/updater/lifecycle actions. Only
GitHub release metadata and the archive download are replaced with local fixture
inputs. This test is not a public GitHub network download or a browser UI test.

For real Linux integration, run the provided disposable lab from a root shell in
WSL/Linux. It downloads a checksummed Ubuntu Base 24.04.4 filesystem into
`/tmp/vpgm-lab*`, installs packages only inside that chroot, and runs its services
in a separate PID/mount namespace. It does not install packages or restart
services on the outer host. Uses x86_64; ARM64 requires the corresponding base
image or a separate ARM64 environment.

```sh
mkdir -p .work
ssh-keygen -t rsa -b 3072 -m PEM -N '' -f .work/lab-key
sudo bash tests/integration/lab.sh
```

Keep the lab terminal/process running. SSH is on port 22287 with public-key-only
authentication; PostgreSQL 18/main is on 55487; MariaDB uses a Unix socket.
Run the integration suite from a second shell (PHP 8.4 on the control host):

```sh
php tests/host.php PATH_TO_PINNED_VITO dist/development.zip --integration
```

The control host must reach the lab's IP from `.work/lab-ip` on port 22287.
The suite creates disposable databases and users with a random `vpgm_` prefix.
It exercises Vito's real SSH and SFTP implementation without SSH fakes. Stop
the lab process afterwards: its PID namespace shuts down the test services.
Inspect and delete only the explicitly resolved `/tmp/vpgm-lab*` root after mounts
have disappeared. Do not reuse these scripts against a real managed server.

All verification claims must distinguish SQL/SSH integration, real installer
extraction/lifecycle, browser UI rendering and published GitHub URL installation.

For the 0.1.1 connection-string and schema-search-path regressions, see
[SECURITY.md](SECURITY.md). That live fixture uses its own freshly initialized
cluster in an offline sandbox and includes negative controls against 0.1.0.
