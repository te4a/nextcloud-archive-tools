# Changelog

## 0.5.0-beta.1

Development candidate; public release preparation is in progress.

- Use the public Nextcloud filesystem setup manager for background jobs.
- Add a regression for processing multiple users through one worker and restoring the previous filesystem context.
- Validate publishing metadata against a pinned official App Store schema.
- Identify the maintainer as te4a and declare AGPL-3.0-or-later explicitly.
- Prepare isolated Linux CI and native Files browser scenarios.

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
