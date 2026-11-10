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

The selected repository is `te4a/nextcloud-archive-tools`, app ID `archive_tools`, and maintainer `te4a`. Before publishing, verify ownership and availability of the app ID, repository URLs, issue tracker and private vulnerability reporting.

Keep signing keys outside published source and artifacts. Obtain an app certificate, sign the final application tree, then create and sign the release archive. Validate and test the exact artifact that will be uploaded. Rebuild and re-sign after any content change.

## Publishing references

- [Filesystem extension mechanisms](https://docs.nextcloud.com/server/stable/developer_manual/basics/storage/filesystem.html)
- [App Store rules](https://docs.nextcloud.com/server/stable/developer_manual/app_publishing_maintenance/publishing.html)
- [Metadata and upload procedure](https://nextcloudappstore.readthedocs.io/en/latest/developer.html)
- [Code signing](https://docs.nextcloud.com/server/stable/developer_manual/app_publishing_maintenance/code_signing.html)
