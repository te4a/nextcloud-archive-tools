# Contributing to Archive Tools

Archive Tools is licensed under AGPL-3.0-or-later. Contributions use the same licence. Report ordinary defects through [GitHub Issues](https://github.com/te4a/nextcloud-archive-tools/issues); report vulnerabilities through the process in [SECURITY.md](SECURITY.md).

## Development

Use 64-bit Linux PHP 8.5 with Nextcloud 34 or 35. The Windows development environment uses Docker Desktop and PowerShell. Frontend development requires Node.js 24 LTS or 26+ and pnpm 11.25.0.

```sh
pnpm install --frozen-lockfile --store-dir .pnpm-store
pnpm run typecheck
pnpm run test:ui
pnpm run build
```

On Windows, `dev/test.ps1 -Start` runs the complete local development gate. The runner creates isolated fixtures; a failed run may retain those fixtures for diagnosis. Its target is the dedicated local `archive-tools-dev` project.

Linux CI uses `python ci/run.py --help` for its supported environments and suites. Each run owns a generated Compose project and volumes. Generated configuration, credentials and logs under `.ci-runs` are private development data and are excluded from Git.

## Changes and verification

Preserve native Files integration, shared-folder permissions, read-only WebDAV behaviour, source archives and extracted user files. Changes to access checks, locking, lifecycle state or cleanup need regression coverage for their observable effects.

Test filesystem changes on the declared Nextcloud versions. A successful run on one database is not evidence for another database. Record the environment and exact package that passed verification.

Keep application code under `app`, tests under `tests`, and developer tooling under `dev` or `ci`. Do not commit `.env` files, generated diagnostics, private signing material, real user data or development volumes. Use synthetic archives for fixtures and document their provenance.

Update the changelog and relevant user documentation with changes to behaviour or compatibility. The runtime package must be built from the reviewed source and signed after its final content is fixed.
