# Installing Archive Tools

Archive Tools runs inside an existing Nextcloud installation. Use a test instance for a beta or release candidate and keep the normal Nextcloud backup and recovery procedure available before an application upgrade.

## PHP environment

The candidate targets Nextcloud 34–35, PHP 8.5, a 64-bit runtime and the `intl` extension. ZIP uses `zip`; TAR.GZ uses `zlib`. Plain TAR uses PHP directly.

For 7z, RAR, TAR.BZ2 and TAR.XZ, install full 7-Zip and `util-linux` with `/usr/bin/prlimit`. PHP must allow the process functions used by the archive reader. The app checks the fixed executable paths `/usr/bin/7zz`, `/usr/local/bin/7zz`, `/usr/bin/7z` and `/usr/local/bin/7z`.

The official full Linux 7-Zip build includes the RAR decoder. Some distribution builds can list a RAR archive without decoding it. Verify the capabilities with the diagnostic command after installation.

## Official Docker image

The repository's `dev/Dockerfile` adds the pinned full 7-Zip build to `nextcloud:34.0.5-apache`. It targets x86-64. Keep the base Nextcloud version consistent with the installed instance; a lower major version must not use an upgraded Nextcloud data volume.

For an existing Compose installation with `app` and `cron`, use the same derived image for both:

```yaml
services:
  app:
    image: nextcloud-with-archive-tools:34.0.5
    build:
      context: .
      dockerfile: Dockerfile.nextcloud
  cron:
    image: nextcloud-with-archive-tools:34.0.5
```

Copy the example Dockerfile to `Dockerfile.nextcloud` in that project's build context. Preserve the existing data volumes, database and Redis configuration, ports, and cron entrypoint. Build the derived image and recreate the existing PHP services through the deployment's normal update procedure.

Adding binaries to a running container is temporary; the derived image makes them available after recreation. No Archive Tools worker service is required.

## Application package

Extract `archive_tools-VERSION.tar.gz` into a writable directory listed in Nextcloud's `apps_paths`. The archive has one `archive_tools` root, containing `appinfo/info.xml`.

For the official Docker image, application activation typically uses:

```sh
docker exec --user www-data nextcloud-app php /var/www/html/occ app:enable archive_tools
docker exec --user www-data nextcloud-app php /var/www/html/occ upgrade --no-interaction
docker exec --user www-data nextcloud-app php /var/www/html/occ archive_tools:diagnose --output=json
```

Adapt the container name to the installation. Keep the code readable by the PHP user. Nextcloud's existing cron must run with the same application code, configuration, data and archive binaries.

If CLI activation leaves stale web routes, restart the PHP service through the deployment's normal procedure and check the page again.

## Verify installation

The diagnostic report should identify the expected database, supported archive formats and a recent cron run. Confirm a small archive opens inside Files. Test extraction to a new folder and a read-only connection. If shared folders are used, verify access through a recipient's account and confirm that revoking the share blocks subsequent reads.

A beta release registered in the App Store can be installed individually with `occ app:install --allow-unstable archive_tools`. That command requires a published compatible release. The current local candidate is not itself an App Store publication.

## Resource limits and task history

Persistent metadata has application limits in addition to each archive's entry/size limits. The defaults are:

| Resource | Per creator / task owner | Whole application |
| --- | ---: | ---: |
| Connected archives | 16 | 128 |
| Queued and running tasks | 4 | 32 |
| Retained task records, including completed tasks | 200 | 2,000 |
| Stored indexes, plans and encrypted credentials combined | 16 MiB | 128 MiB |

The final check and creation run in one serialized database transaction. Reaching a limit rejects the request without creating a new connection or task. Ordinary users cannot change these ceilings. An administrator can override individual positive integer values in the Nextcloud system configuration:

```php
'archive_tools_limits' => [
    'connections_per_owner' => 16,
    'connections_global' => 128,
    'active_jobs_per_owner' => 4,
    'active_jobs_global' => 32,
    'history_per_owner' => 200,
    'history_global' => 2000,
    'metadata_bytes_per_owner' => 16777216,
    'metadata_bytes_global' => 134217728,
],
```

Zero does not disable these limits. Passwords are limited to 4,095 bytes before archive processing or credential encryption. Connection limits count the creator even when other users can access a shared connection.

Completed task records retain retry selections until the configured history retention removes them. Setting retention to zero preserves that history; it still consumes the metadata/history allowance. To free it intentionally without removing connections or user files, an administrator can preview one owner's terminal records:

```sh
php occ archive_tools:clear-history --owner USER_ID --output=json
```

Review the row count, stored bytes and number of output references. Add repeated `--id TASK_ID` options to select specific records; add `--execute` to forget the selected terminal metadata. Each invocation handles at most 200 records. Queued/running jobs, other owners, notifications, source archives and extracted files are preserved. Forgotten records lose their retry selections and output references in Archive Tools history.

Disconnecting an archive removes its own virtual file-cache records. Cron also recovers older orphaned virtual caches in bounded passes, checking exact storage identities, current connections and active read leases. This recovery does not delete source archives or extracted files.

## Disable and remove

`occ app:disable archive_tools` hides the app and its connections while retaining its configuration and records for re-enable. Source archives and extracted user files stay in Nextcloud.

Nextcloud 34 uses the same app uninstall repair hook during an ordinary disable. Archive Tools therefore keeps destructive metadata cleanup out of that hook. The candidate provides an explicit `archive_tools:purge` workflow; use its report and help output before requesting cleanup. Its destructive operation is allowed only after active work is quiescent and the lifecycle barrier is held.

A completed purge removes application metadata and credentials and disables the app, while preserving user files. The standard app removal command or deployment then removes the code. Keep the corresponding backup if a downgrade or recovery is required; restore configuration, database and data together.

Inspect the cleanup plan first, while the app is still enabled:

```sh
php occ archive_tools:purge --output=json
```

For an intentional permanent removal, stop users and background work from starting new Archive Tools operations, wait for current tasks and downloads to finish, then run `php occ archive_tools:purge --force --output=json`. An active operation makes the command refuse cleanup. After a successful purge, remove the code through the normal app removal procedure.

Temporary decoder inputs and file-name lists follow a one-hour retention rule. Cleanup checks ownership and file identity and never follows a source archive link. Fresh or future-dated resources are retained. Each pass scans at most 5,000 directory entries and removes at most 200 resources; the command reports partial or failed cleanup. A successful metadata purge does not mean that every recent temporary resource has been removed.
