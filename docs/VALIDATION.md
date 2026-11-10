# Validation — 0.5.0-beta.1

Validated source: [`c78930fb0fde1906009c49b95e360114ef374abf`](https://github.com/te4a/nextcloud-archive-tools/commit/c78930fb0fde1906009c49b95e360114ef374abf). All five jobs in [GitHub Actions run 42904674131](https://github.com/te4a/nextcloud-archive-tools/actions/runs/42904674131) completed successfully. Downloaded artifact checksums matched GitHub metadata; their summaries and HTTP assertion reports were checked.

All lanes reported the same application source SHA-256:

```text
8eef3142a3b6330fa28668cdb42b7abebe49a34390c8f88e85e557ef465bbfd9
```

## CI matrix

Versions below are the actual reported versions. Each lane ran on Ubuntu 24.04 with 64-bit PHP and local data storage.

| Nextcloud | PHP | Database | Redis | Scope | PHP checks | HTTP checks |
| --- | --- | --- | --- | --- | ---: | ---: |
| 34.0.5.1 | 8.5.12 | PostgreSQL 17.11 | 7.4.12 | Full | 252 | 545 |
| 35.0.2.2 | 8.5.12 | PostgreSQL 17.11 | 7.4.12 | Full | 252 | 545 |
| 34.0.5.1 | 8.5.12 | SQLite 3.46.1 | None | Critical | 252 | 370 |
| 34.0.5.1 | 8.5.12 | MariaDB 11.8.6 | 7.4.12 | Critical | 252 | 370 |
| 35.0.2.2 | 8.5.12 | MySQL 8.4.8 | 7.4.12 | Critical | 252 | 370 |

Every lane included resource-admission and optional-dependency checks. Critical lanes omitted the repeated HTTP multiformat and concurrency suites; they retained the PHP concurrency checks and all 58 admission HTTP assertions.

Each PostgreSQL lane also passed **3 browser scenarios** and **5 native-cron assertions**. Browser coverage included native Files archive navigation, nested and special-character paths, reload/back navigation, protected archives, streamed downloads, selected extraction through the native picker, and pinning. Cron coverage queued a real task and ran `cron.php` without forced background-job execution. Disposable project cleanup succeeded in all five lanes.

## Additional local acceptance

The main Nextcloud 34/PostgreSQL/Redis gate passed **795 checks**: 236 PHP, 14 UI-helper and 545 HTTP assertions. PHP syntax checks passed for **85 files**, both language catalogs contained **330 messages**, and type checking and a clean frontend build passed.

Separate guarded acceptance runs passed **61 application-lifecycle**, **40 backup-recovery** and **148 virtual-storage/cache-cleanup** checks. Recovery covered an offline SQLite snapshot of configuration, database and data restored onto the same application/core code. PostgreSQL restoration, point-in-time recovery and cross-version recovery remain unverified; see the [backup verification boundary](RELEASE.md#backup-verification-boundary).

## Runtime artifact and signing

`archive_tools-0.5.0-beta.1.tar.gz` contains **82 runtime files** and passed **90 package/smoke checks**. It was built from the clean source commit above. Its SHA-256 is:

```text
9aa9ba8e8eecfa9fd565dae5e6a2bb8e46d2aad4644409f3e87e9b9229f65991
```

This artifact is **unsigned**. At validation time, [certificate request #1434](https://github.com/nextcloud/app-certificate-requests/pull/1434) had passed its DCO check; certificate issuance remained pending. These results validate a beta candidate, not a signed App Store or stable release.
