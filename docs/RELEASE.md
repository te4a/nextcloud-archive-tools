# Stable release preparation

Archive Tools targets a first public stable release after beta and release-candidate testing. The intended sequence is `0.5.0-beta.1`, `0.5.0-rc.1`, then `0.5.0`. A version suffix identifies a prerelease; it does not relax App Store publishing requirements.

## Release scope

The release preserves native Files browsing, ordinary user and group shares, read-only WebDAV connections, protected archives, queued extraction, global opening policy, and configurable idle cleanup. Target formats are ZIP, TAR, TAR.GZ, TAR.BZ2, TAR.XZ, 7z and RAR.

The initial target is Nextcloud 34–35, PHP 8.5 on a 64-bit Linux server, and local data storage. The supplied Dockerfile targets x86-64. Team folders, public links, federation, external storage with personal credentials, multipart/SFX archives and archive editing are outside the first release scope.

## Required evidence

| Gate | Acceptance |
| --- | --- |
| API compatibility | Documented storage integration retained; remaining concrete implementation dependencies are identified and tested on both declared Nextcloud versions. |
| Access and data safety | Shared ACL changes, source changes, malformed archives, cancellation, process termination and rollback pass regressions. Original files and unrelated user changes are preserved. |
| Resource limits | External-process limits and the whole extraction-operation budget are tested, including solid archives and termination cleanup. |
| Application lifecycle | Fresh installation, upgrade, disable/enable, explicit metadata purge, reinstall and backup recovery have defined tested behaviour. |
| CI | Declared Nextcloud versions and databases pass their required suites; browser scenarios run in an isolated test environment. |
| Distribution | Metadata validates, runtime contents are reviewed, the final package is signed, and corresponding source is available at its version tag. |
| Pilot | Real archive and WebDAV use has no unresolved access-control, secret-disclosure or data-loss defect. Remaining limitations are documented. |

The 0.4.1 baseline passed 617 application checks and 90 package checks on Nextcloud 34/PostgreSQL/Redis. That baseline predates the 0.5 changes and is not a completed 0.5 release gate.

## Publishing identity

The public repository is [te4a/nextcloud-archive-tools](https://github.com/te4a/nextcloud-archive-tools), app ID `archive_tools`, and maintainer `te4a`. Issues and private vulnerability reporting are enabled. App Store registration and a Nextcloud-issued signing certificate remain separate prerequisites.

Keep signing keys outside published source and artifacts. Obtain an app certificate, sign the final application tree, then create and sign the release archive. Validate and test the exact artifact that will be uploaded. Rebuild and re-sign after any content change.

## Building and signing the exact artifact

The runtime package contains compiled assets, PHP, translations, licence texts, release notes and versioned source links. Development dependencies and test fixtures stay in the source repository. Vite generates the bundled dependency notices at `app/js/THIRD_PARTY_LICENSES.md` during the normal build.

With all changes committed and the release gates complete:

```sh
python3 dev/package.py --prepare
```

Retain the returned stage path. Sign that exact directory in an environment with the matching Nextcloud installation and the maintainer's certificate:

```sh
php occ integrity:sign-app --path="$STAGE" --privateKey="$PRIVATE_KEY" --certificate="$CERTIFICATE"
test -s "$STAGE/appinfo/signature.json"
php occ integrity:check-app archive_tools --path="$STAGE" --output=json
python3 dev/package.py --stage "$STAGE" --require-signature
```

The builder rejects changed stage files, unexpected contents, linked inputs, dirty source and incomplete signature coverage. It does not establish certificate trust itself; the native Nextcloud integrity check does that. Nextcloud skips an unsigned app during its integrity check, so signature presence must be checked explicitly.

On the guarded local development stand, verify the final tarball with `python dev/verify-package.py --run --require-signature`. That tests installation, disable/enable and file preservation and requires Nextcloud to accept the exact packaged signature. The smoke test restores the workspace application origin; restore the stand's native cron after it completes.

Publish the corresponding source commit at `vVERSION`. Upload the exact tested tarball and its checksum as release assets. The App Store also needs a separate signature over the archive bytes:

```sh
openssl dgst -sha512 -sign "$PRIVATE_KEY" "$ARCHIVE" | openssl base64 -A
```

Keys, credentials, raw CI logs and local test reports are excluded from release artifacts. Packaging is reproducible for identical input bytes and `SOURCE_DATE_EPOCH`; the default timestamp is zero. A source or metadata change requires a new stage, signature and package verification.

## Backup verification boundary

The isolated backup regression uses a stopped SQLite instance and the same application/core image before and after recovery. It snapshots configuration and data together, including the SQLite database, and restores their exact contents and file metadata. It verifies encrypted credentials against a deliberately changed core secret, restores connections/settings/source and output identities, and executes the recovered native queue task.

This is evidence for an offline SQLite snapshot. It does not validate PostgreSQL backup restoration, point-in-time recovery or restoring onto different application/core versions. A deployment backup must also retain the matching application code; follow the [Nextcloud backup procedure](https://docs.nextcloud.com/server/stable/admin_manual/maintenance/backup.html).

## Publishing references

- [Filesystem extension mechanisms](https://docs.nextcloud.com/server/stable/developer_manual/basics/storage/filesystem.html)
- [App Store rules](https://docs.nextcloud.com/server/stable/developer_manual/app_publishing_maintenance/publishing.html)
- [Metadata and upload procedure](https://nextcloudappstore.readthedocs.io/en/latest/developer.html)
- [Code signing](https://docs.nextcloud.com/server/stable/developer_manual/app_publishing_maintenance/code_signing.html)
