# Nextcloud API compatibility

This document records the integration boundaries for the Nextcloud 34–35
release target. It distinguishes documented filesystem extension mechanisms from
remaining implementation dependencies. A PHP namespace inventory alone is not
an App Store approval decision.

## Documented filesystem integration

The official developer manual for both
[34](https://github.com/nextcloud/documentation/blob/stable34/developer_manual/basics/storage/filesystem.rst#implementing-a-storage)
and
[35](https://github.com/nextcloud/documentation/blob/stable35/developer_manual/basics/storage/filesystem.rst#implementing-a-storage)
recommends implementing a storage backend by subclassing
`OC\Files\Storage\Common`. It also recommends registering
`OCP\Files\Config\IMountProvider` through `IMountProviderCollection` to publish
mounts. Archive Tools follows these mechanisms, declares the `filesystem` app
type, and exposes archive entries through native Files and WebDAV.

`IStorage::getScanner()` and `IScanner::scan()` are public contracts, as are
`IMountPoint::wrapStorage()`, `ICache`, `IUserMountCache`, and the typed mount-cache
invalidation event. Their use should not be classified as private merely because
Nextcloud supplies an implementation from `lib/private`.

The storage backend remains based on `Common`. Its cache/scanner lifecycle is
part of the native integration: the concrete base creates the persistent native
cache and scanner, and its scanner path checks the storage's compatibility with
`Common`. Replacing `extends Common` with `implements IStorage` would require
implementing that lifecycle, not just changing an import. No such rewrite is
needed to use the documented backend extension mechanism.

## Code checker and publishing policy

Nextcloud's former app code checker was made a no-op by
[upstream commit e618ea8](https://github.com/nextcloud/server/commit/e618ea83b5a54f566e1cc1fbfdfcce07be752108)
in 2021. The `v34.0.5` and `v35.0.0` source trees do not contain the old
`App/CodeChecker` implementation or the `app:check-code` command. Thus there is no
current core checker allowlist against which these dependencies can be certified.
Legacy mentions of the `appcodechecker` setting are not evidence of an active
34–35 API gate, and the `filesystem` type is not a blanket private-API exemption.

The [App Store publishing rule](https://docs.nextcloud.com/server/stable/developer_manual/app_publishing_maintenance/publishing.html#be-technically-sound)
still requires public Nextcloud API use. The documented `Common` extension is
relevant evidence for retaining native storage; the absence of the old checker
does not establish that every other internal class is supported or that an App
Store submission will be approved. Signing, upload validation, API maintenance,
and review are separate release concerns.

## Backend dependency decisions

| Integration | Application location | Decision |
| --- | --- | --- |
| `OC\Files\Storage\Common` | `ArchiveStorage`, `MetadataOnlyStorage`, `ExtractionLockStorage` | Use the documented storage backend base. Native file operations require core's storage type. The extraction adapter forwards operations through the original storage's public interfaces and uses `IMountPoint::wrapStorage()` to reuse the worker's exclusive reservations. |
| `ICache::searchQuery()`, `ICache::remove()`, `IUserMountCache::removeMount()` | `VirtualStorageCleanup` | Remove the cache data and user mount points recorded in the app's cache registry. New connections use distinct roots in one virtual storage. An upgrade preserves the cache location of each existing connection. |
| `IFileAccess::getByFileId()` | `MountRepository` | Detect permanent source deletion by file ID. Access checks hide the connection while the source is in the recycle bin. |
| `IMountPoint`, `IMovableMount`, `IStorageFactory` | `ArchiveMountPoint` | The archive mount implements the public interfaces and asks the core factory to apply its storage wrappers. Archive Tools supplies the lazy storage identity and maps each recipient's share path to the shared archive contents. |
| `OCP\Files\Cache\ICache` and `OCP\Files\Search` | `AccessCache` | Project the native cache through the public interfaces. Keep the storage and root IDs for mount registration, check access before exposing entries, and constrain searches to the connection's cache path. |
| `OC\Files\View` | `ExtractionService` | Retain the narrow interrupted-stream unlock compensation pending a verified public equivalent or an upstream fix; removing it can break rollback and leak file/ancestor locks. |
| `OC_Util::setupFS/tearDownFS` | `ExtractJob` | Replaced with injected public `OCP\Files\ISetupManager`, available and marked consumable since 34.0.0. |

The replacement for background jobs preserves the existing sequence: tear down,
select the job owner, set up that owner's filesystem, run the extraction, then
tear down and restore the previous user and filesystem in `finally`.
`ISetupManager` explicitly documents this multi-user background-job use case and
has the same relevant contract in
[34.0.5](https://github.com/nextcloud/server/blob/v34.0.5/lib/public/Files/ISetupManager.php)
and
[35.0.0](https://github.com/nextcloud/server/blob/v35.0.0/lib/public/Files/ISetupManager.php).

Public `Node::unlock()` balances a file and its ancestor locks once the caller
has a file node. `Folder::newFile(name, stream)` can throw before returning
that node. Creating an empty node first changes create/write event timing and
metadata behavior. Subsequent writes through `File::fopen()` also require update
permission. Keep native streamed creation and compensate inside the write's
exception handler. A size mismatch after the write returns goes directly to
rollback. `OCP\Files\Lock\ILockManager` addresses collaborative DAV lock
contexts. The extraction reservation uses transactional file locks.
If the recipient has no delete permission, rollback resolves the job's still
reserved output by its file ID under the verified destination in the owner's
files. It reuses the same locks and deletes only that job's output.

## Questions for Nextcloud maintainers

- Can apps retire their own storage registrations through a supported API?
  Archive Tools now removes virtual file metadata and mount projections through
  public APIs. The remaining permanent registration and obsolete beta registry
  records need a core API for removal.
- How should an app run a native `Folder::newFile(name, stream)` under an
  exclusive reservation on the destination while preserving other requests'
  locks? For stream or destination errors, the core operation needs to release
  its own file and ancestor locks even if it throws before returning a node.

References for these questions are the
[API rules](https://docs.nextcloud.com/server/stable/developer_manual/digging_deeper/api.html),
[storage backend](https://github.com/nextcloud/server/blob/v35.0.2/lib/private/Files/Storage/Common.php),
[storage registry](https://github.com/nextcloud/server/blob/v35.0.2/lib/private/Files/Cache/Storage.php),
and [native stream write](https://github.com/nextcloud/server/blob/v35.0.2/lib/private/Files/View.php).

## Files interface

The loading indicator uses the Files SDK's list header and filter registrations
to show status and suppress stale rows while Nextcloud loads the archive folder.
The documented Files Router handles navigation. Its current route getters
preserve the original folder and file focus when leaving the archive.
[Files client APIs](https://docs.nextcloud.com/server/stable/developer_manual/client_apis/files.html#router)

## Release acceptance

The prior NC34/PostgreSQL/Redis baseline comprised 617 application checks plus
90 package-smoke checks. Those results precede the public setup-manager change;
they do not by themselves validate NC35 or a new release artifact.

The new `tests/background-user-context.php` regression reuses one worker and its
real service graph for two generated owners. It verifies exact owner-specific
output, the previous session/filesystem after each job, restoration after a setup
exception, and cleanup of only its own users and queue/task rows. Existing queue
security and concurrency suites cover shared destinations, stream errors,
cancellation, rollback, and transactional locking.

The virtual-storage cleanup suite uses a disposable Nextcloud instance. It
exercises independent cache roots, cleanup after interrupted scans, active
leases and the guarded cleanup of identifiable beta caches.

The same application tree passed 263 PHP checks and 564 HTTP assertions on
each version with PostgreSQL and Redis. Native cron completed the queued
extraction on both versions. Four Chromium scenarios passed on each version
in separate browser runs against the same application tree.

Each extraction job now owns one explicit `OperationBudget` with a monotonic
300-second deadline. The same object covers indexing, all selected members,
native chunk reads and worker progress checks. Each external decoder receives
only the remaining time (also capped at its existing 300-second limit), so a solid
7z archive cannot obtain a fresh full timeout for every selected member. Decoder
children retain their 512 MiB memory bound and are killed/reaped on expiry.
Native operations check cooperatively between bounded reads/inflate iterations;
this does not claim to preempt a blocking filesystem call inside PHP or a remote
storage backend. Expiry follows the normal failure/rollback path, and a subsequent
job receives a fresh budget. Rollback and lock/resource release are allowed to
finish after expiry; the deadline stops further extraction instead of killing
the PHP worker during cleanup. There is no process-global current-job deadline.
Persistent connection/task admission is enforced separately by `AdmissionService`;
decoder concurrency remains governed by the PHP/server infrastructure.

Release gates must run these checks on both declared major versions and preserve
native PROPFIND/GET/Range behavior, shared recipient aliases, parent moves,
immediate access revocation including cached listings, source-version
preconditions, and lock cleanup after failed or interrupted writes. A failed
write into a create-only share must remove the partial output and allow an
immediate retry with the same folder name. The interrupted-stream unlock still
uses the internal `View` API until the core can balance the failed write itself.
