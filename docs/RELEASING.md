# Source-archive release procedure

The permanent GitHub installation source is
`https://github.com/techibytesmedia/vito-postgresql-manager`. The GitLab source
mirror is `https://gitlab.com/techibytesmedia/vito-postgresql-manager`.
`release.json` records this identity and the expected namespace:
`App\Vito\Plugins\Techibytesmedia\VitoPostgresqlManager`.

1. Verify the actual Vito commit on the intended installation. This repository
   targets 4.1.1 at `8598e4dc0bce12d2b2f915766a100b445b7d9e22`; version-string matching
   is a minimum guard, not proof that another commit has identical APIs.
2. Run tests in [TESTING.md](TESTING.md), including the managed-host integration.
   Test ARM64 separately before claiming it. Record exact versions/results.
3. Prepare a fresh source archive using the configured GitHub identity:

   ```sh
   python tools/prepare_release.py techibytesmedia vito-postgresql-manager --output dist/release-source
   ```

   The tool implements Vito's hyphen/underscore case normalization, rewrites
   namespaces/imports consistently, includes runtime files, writes release.json,
   and generates a wrapper-directory ZIP for testing. It never publishes.
4. Review `release.json` and every namespace. Commit the runtime files at the
   repository root, with `Plugin.php` at the root. Keep the owner/repo casing and
   normalized namespace stable for the lifetime of existing installations. Push
   the same source commit to the GitHub and GitLab `main` branches. Build output,
   local state and credentials remain ignored; neither source push creates a
   GitHub release. `tools/test_archive.py` uses the identity from `release.json`.
5. Install the prepared ZIP using Vito's real ExtractPlugin/InstallPlugin path
   on an isolated instance before tagging. Test disable, re-enable, uninstall,
   reinstall and the explicit upgrade procedure. Verify Plugin.php is at the
   extracted root and there are no vendor/node_modules/runtime omissions.
6. When release publication is authorized, tag the reviewed source and publish a
   **normal GitHub release** from it.
   The installer requests unauthenticated latest-release metadata and its
   `zipball_url`. Separate attached assets are not the installation source.
   A GitLab release does not satisfy Vito's GitHub URL installer.
7. Test **Admin → Plugins → Install** using the real public repository URL.
   This network download step remains a release gate until a real repository and
   publication are authorized. Do not equate an archive extraction test with it.
8. Optionally add the `vitodeploy-plugin` topic for community discovery.

No release is published by the local build scripts or CI. `.work/`, `.ai/`, temp
keys, dependency directories and test databases must never enter the published
source. The generated source archive contains no credentials or development state.
