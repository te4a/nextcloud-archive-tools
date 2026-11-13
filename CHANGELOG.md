# Changelog

## 0.5.0-beta.4

- Show a loading indicator inside Nextcloud Files while an archive or one of its folders opens. Hide the previous folder's rows until the new listing is ready.
- Use public Nextcloud mount interfaces for archive connections in shared folders and for read-only access over WebDAV.
- Use public cache APIs to check whether an archive source still exists and to clean up leftover file metadata when a virtual archive storage is removed.

## 0.5.0-beta.3

- Load ZIP contents one folder at a time in Nextcloud Files. Opening a ZIP or navigating to another folder returns only that folder's immediate children.
- Add an administrator setting for browsing larger ZIP archives and extracting selections within the standard extraction limits.
- Restore missing Nextcloud background job entries for queued extractions while preserving existing job reservations.

## 0.5.0-beta.2

- Include 7-Zip 26.06 for Linux x86-64 and ARM64 so 7z, RAR, TAR.BZ2 and TAR.XZ work on compatible Nextcloud servers without a system 7-Zip installation.

## 0.5.0-beta.1

Development candidate; public release preparation is in progress.

- Use the public Nextcloud filesystem setup manager for background jobs.
- Add a regression for processing multiple users through one worker and restoring the previous filesystem context.
- Validate publishing metadata against a pinned official App Store schema.
- Identify the maintainer as te4a and declare AGPL-3.0-or-later explicitly.
- Prepare isolated Linux CI and native Files browser scenarios.
- Share one monotonic time budget across every member of a queued extraction.
- Preserve settings and connections on disable; provide an explicit guarded metadata purge and fresh reinstall workflow.
- Recover expired temporary decoder inputs and file-name lists without deleting linked source archives or fresh resources.
- Build a minimal reproducible runtime package with corresponding-source notices and bundled dependency licences.
- Bound persistent connection/task metadata and queued work, including concurrent submissions; provide explicit terminal-history cleanup for administrators.
- Remove virtual cache metadata on disconnect and recover old orphaned virtual storages through bounded cron passes.

## 0.4.1

Local pilot package for Nextcloud 34.0.5 and PHP 8.5.12.

- Extend compatibility metadata to Nextcloud 34 and 35.
- Bound mounted-resource ETags to Nextcloud's 40-byte metadata field.
- Enforce Nextcloud's 250-byte UTF-8 filename limit after NFC normalization.
- Verify PostgreSQL 17.11 and Redis 7.4.12, including crash recovery and shared connections.
- Verify the installation package, disable/enable behaviour, original and extracted files, and a pinned archive connection.

## 0.4.0

Local development package verified on Nextcloud 35 with SQLite.

- Add 7z, RAR, TAR and compressed TAR readers alongside ZIP.
- Browse archives inside native Files, extract selected entries, and expose persistent connections through read-only WebDAV.
- Support ordinary shared folders, Russian and English interfaces, administrator-wide opening policy and configurable connection cleanup.
