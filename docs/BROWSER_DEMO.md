# Local browser demo

The browser demo uses the pinned Vito checkout in `.work/`, a separate persistent
`storage/vpgm-browser.sqlite` application database, and the disposable Ubuntu lab.
No production Vito instance or VPS is used. Only loopback web/WebSocket listeners
are started. The lab SSH port is 22287; PostgreSQL is 18/main on 55487.

Open **http://127.0.0.1:8787/servers/1/features** while the demo processes run.
The local-only Vito login is `demo@vito.local` / `VitoLocalDemo!2026`.
These credentials are fixtures for this disposable demo, not deployment defaults.

## Start again

1. Start `tests/integration/lab.sh` in the root WSL shell, following TESTING.md.
   Leave that process running. A cold lab downloads and provisions its packages.
2. From this repository with PHP 8.4 and Python installed:

   ```powershell
   python tools/test_archive.py dist/browser-demo
   php tools/setup_browser_demo.php
   ```

   Use a fresh archive output name if that directory already exists and pass its
   ZIP path as the optional setup argument, or retain the existing
   `dist/browser-demo.zip` when no plugin code changed. The setup helper
   updates only the pinned `.work` checkout, installs/enables the plugin, seeds the
   local login/project/server/provider, and preserves its existing demo metadata.
3. In the disposable Vito directory, install and build the **stock** frontend once:

   ```powershell
   npm ci --no-audit --no-fund
   npm run build
   ```

   The source archive's bundled assets were stale (`route is not defined`).
   Building the unmodified frontend fixes this development checkout. The plugin
   requires no core frontend patch or custom component build for installation.
4. Run these in separate terminals, keeping them open:

   ```powershell
   # From the disposable Vito directory
   php artisan serve --host=127.0.0.1 --port=8787 --no-reload
   php artisan ws:serve --host=127.0.0.1 --port=8788
   ```

   ```powershell
   # From this plugin repository
   ./tools/browser_demo_worker.ps1
   ```

   The demo worker invokes the same plugin command every three seconds for quick
   feedback. Production uses the normal once-per-minute Vito scheduler. The demo
   ticker deliberately runs only this plugin's queue, without enabling unrelated
   Vito maintenance tasks against the lab.

## Try it

- Open **Actions → Discover installed clusters**, submit, and reload Features.
- Open **Adopt an existing cluster**, select `18/main :55487`, submit, then reload.
- Create an application login, then a database with that owner.
- Queued operations require a Features-page reload before Status and dropdowns
  show their latest results. Closing/reopening a dialog alone does not fetch data.
- Select the `PostgreSQL lab backups` Local storage provider for manual backup.
- Restore a completed archive into a fresh database name and type the exact
  server/cluster/name confirmation displayed in the form.

The browser verification created `browser_demo_owner`, `browser_demo`, and
`browser_demo_restored`. One application-owned row was seeded using
`php tools/browser_demo_data.php seed` to make the backup/restore check meaningful.
`php tools/browser_demo_data.php check` compares the source/restored row and
confirms MariaDB is still Vito's default database service.

Stop the demo terminals with Ctrl+C when finished. Stopping the lab namespace
stops its services. Its filesystem is under `/tmp/vpgm-lab*`; Vito's separate demo
application database remains in `.work` for inspection. After a cold lab reset,
refresh the cluster inventory before using old resource selections.
