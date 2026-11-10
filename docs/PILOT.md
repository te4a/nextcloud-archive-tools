# Pilot and stable acceptance

Use a test Nextcloud installation with current backups. The automated matrix establishes compatibility; a pilot checks representative archives and client behaviour before promoting a candidate to stable.

Record the candidate version, Nextcloud/PHP/database versions, archive format and approximate size for each finding. Report ordinary bugs in Issues and vulnerabilities through private reporting. Use harmless reproduction files and omit archive passwords and private server configuration.

## Manual scenarios

1. Open representative ZIP, TAR, compressed TAR, 7z and RAR files inside Files. Navigate nested directories, search, reload a nested location and return to the original folder. Verify non-Latin names and names containing percent signs, `#` or `?`.
2. Try a protected archive with a wrong password, the correct password and cancellation. Verify downloaded bytes against the original content.
3. Extract selected files and a whole archive to new folders. Watch progress, leave the page and return. Cancel a larger task and confirm existing files and user edits are preserved.
4. Share an ordinary folder with another user or group. Connect its archive, then verify the recipient can browse and download it. Revoke access and confirm subsequent reads fail. Repeat after moving the shared parent folder.
5. Through the WebDAV clients intended for real use, list and read a connected folder. Confirm uploads, edits and deletions inside the connection are refused. Reconnect after a client restart.
6. Check the administrator-wide opening setting, idle disconnection, pinned connections and history retention. Verify these through a second account.
7. Disable and re-enable the app. Verify user files, settings and pinned connections remain. Test upgrade and backup restoration on a disposable copy of the installation.

## Promotion decision

The final signed artifact must pass the declared compatibility matrix, package installation/integrity checks and the relevant pilot scenarios. Access-control failures, password disclosure, loss of original files or unrelated user edits block promotion. Fixes require a new candidate and the affected regressions; changes to the final package require re-signing and package verification.

Team folders, public links, federation, external storage with personal credentials, multipart/SFX archives and archive editing remain outside the first stable release scope. Keep these boundaries visible in the release notes.
