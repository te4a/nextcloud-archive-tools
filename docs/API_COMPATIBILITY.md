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
| `OC\Files\Storage\Common` | `ArchiveStorage`, `MetadataOnlyStorage` | Retain the documented storage backend base. The cleanup adapter has no user-file access or writable filesystem operations. |
| `OC\Files\Cache\Storage::getGlobalCache()` | `VirtualStorageCleanup` | Clear the process-local storage identity map after removing an exact app-owned virtual storage. This concrete cache bridge remains version-sensitive. |
| Core `storages` metadata schema | `VirtualStorageCleanup` | Enumerate only strict `archive_tools:<id>:<24hex>` identities. Use native cache removal and the public user mount-cache API. Delete the exact matching storage record. |
| `ICache::searchQuery()`, `ICache::remove()` | `VirtualStorageCleanup` | Find leftover cache entries in bounded batches using the storage's own search filter. Remove them through the native cache API, including related metadata. |
| `IFileAccess::getByFileId()` | `MountRepository` | Use the public exact file-ID lookup to detect a deleted source. Look up the string identity in the core storage registry only for a cache path that could be in home trash. |
| `IMountPoint`, `IMovableMount`, `IStorageFactory` | `ArchiveMountPoint` | The archive mount implements the public interfaces and asks the core factory to apply its storage wrappers. Archive Tools supplies the lazy storage identity and maps each recipient's share path to the shared archive contents. |
| `OC\Files\Cache\Wrapper\CacheWrapper` | `AccessCache` | Retain the native cache decorator for the stable root/storage identity, access checks, and compatibility with the native scanner. The scanner and storage base depend on methods beyond `ICache`. |
| `OC\Files\Cache\FailedCache` | `AccessCache` | Retain the native inaccessible-cache behavior. Its denied root is a server-provided `ICacheEntry`, which Nextcloud marks `Consumable`. |
| `OC\Files\Storage\Wrapper\Wrapper` | `ExtractionLockStorage` | Retain the native storage decorator so writes can use already reserved destination paths while preserving locks against other requests. |
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

Public `Node::unlock()` can balance a file and its ancestor locks, but the failing
`Folder::newFile(name, stream)` operation may throw before returning a file node.
Creating an empty node first and then writing content would change create/write
event timing and metadata behavior. That is not a drop-in compatibility change.
`OCP\Files\Lock\ILockManager` addresses collaborative `files_lock`/DAV lock
contexts; it is not a replacement for transactional `OCP\Lock\ILockingProvider`
locks used here.

## Questions for Nextcloud maintainers

- Can apps enumerate and retire their own unmounted storage identities through a
  supported API? Retirement needs to remove the metadata and mount projections
  and invalidate the process-local identity mapping. For source deletion, a
  public way to resolve a cached file's storage type or trash status would remove
  the remaining home-trash lookup against the same registry.
- Is there a supported cache decorator for a native storage whose access can
  change during a request? It needs to preserve the storage/root identity,
  hide cached member entries after access is lost, and work with the native
  scanner and availability checks.
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

The virtual-storage cleanup suite passed 158 checks on each of Nextcloud 34.0.5
and 35.0.2 with SQLite. It covers removing 201 cache entries without a root or
cached user mount, removing their extended metadata, and getting a fresh
storage ID in the same PHP process. It also checks that active connections,
source archives, and extracted files survive orphan cleanup.

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
preconditions, and lock cleanup after failed or interrupted writes. Residual
concrete cache/storage/View dependencies remain an explicit maintenance
boundary; successful compatibility tests do not turn them into public API.

The architecture decision is **go for retaining documented native storage and
shared WebDAV**, with a public setup-manager replacement and bounded compatibility
tests. It is **not a claim of all-public implementation or App Store approval**.
Any future removal of the remaining adapters must preserve the acceptance
properties above before replacing the existing path.
