# Security policy

Archive Tools processes untrusted archives and exposes shared files through Nextcloud. Reports involving unauthorized file access, archive path validation, child processes, credentials, or destructive cleanup should be handled privately.

## Report a vulnerability

Use [GitHub private vulnerability reporting](https://github.com/te4a/nextcloud-archive-tools/security/advisories/new). Include the application and Nextcloud versions, PHP version, operating system, reproduction steps, and a minimal synthetic archive when possible. Describe the expected and observed access permissions.

Keep passwords, access tokens, Nextcloud configuration files, personal archives and unrelated users' data out of public issues. The `occ archive_tools:diagnose --output=json` report provides a limited diagnostic summary suitable for an initial report; inspect it before sharing it.

## Supported releases

The 0.5 release is being prepared. Its beta and release-candidate builds are intended for isolated testing. Stable support begins when a stable version and its tested compatibility matrix are published.

The maintainer coordinates reproduction, fixes, regression tests, and disclosure through the private report. A release that addresses a vulnerability will identify affected versions and upgrade instructions.
