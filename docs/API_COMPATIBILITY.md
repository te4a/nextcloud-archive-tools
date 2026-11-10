# Nextcloud API compatibility

This document records the backend integration boundaries for the Nextcloud 34–35
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
in 2021. The exact `v34.0.5` and `v35.0.0` source trees do not contain the old
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
| Core `storages` / `filecache` metadata schema | `VirtualStorageCleanup` | Enumerate only strict `archive_tools:<id>:<24hex>` identities. Use native cache removal and the public user mount-cache API; delete the exact matching storage record. Test disconnect, orphan cleanup and source/output preservation on supported versions. |
| `OC\Files\Mount\MountPoint` | `ArchiveMountPoint` | Retain the concrete mount implementation behind public `IMountPoint`/`IMovableMount` contracts. Its constructor and lazy storage/cache identity behavior remain version-sensitive. |
| `OC\Files\Cache\Wrapper\CacheWrapper` | `AccessCache` | Retain the native cache decorator. Its stable root/storage identity and per-operation access gate prevent stale shared listings from disclosing names. |
| `OC\Files\Cache\FailedCache` | `AccessCache` | Retain the inaccessible-cache behavior together with the decorator; there is no equivalent public concrete cache wrapper to substitute by import. |
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
the PHP worker during cleanup. There is no process-global current-job deadline or
new global/per-user admission limit.

Release gates must run these checks on both declared major versions and preserve
native PROPFIND/GET/Range behavior, shared recipient aliases, parent moves,
immediate access revocation including cached listings, source-version
preconditions, and lock cleanup after failed or interrupted writes. Residual
concrete mount/cache/storage/View dependencies remain an explicit maintenance
boundary; successful compatibility tests do not turn them into public API.

The architecture decision is **go for retaining documented native storage and
shared WebDAV**, with a public setup-manager replacement and bounded compatibility
tests. It is **not a claim of all-public implementation or App Store approval**.
Any future removal of the remaining adapters must preserve the acceptance
properties above before replacing the existing path.
