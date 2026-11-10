#!/usr/bin/env python3
"""Verify archive snapshot preconditions over the isolated Nextcloud HTTP API."""
from __future__ import annotations

import base64
import hashlib
import io
import json
from pathlib import Path
import runpy
import sys
import time
import urllib.parse
import uuid
import xml.etree.ElementTree as ET
import zipfile

ROOT = Path(__file__).resolve().parents[1]
features = runpy.run_path(str(ROOT / 'tests' / 'features.py'))
helpers = features['helpers']
CHANGED_MESSAGE = 'The archive changed. Open it again before continuing.'


class EtagReport(helpers['Report']):
    def write(self, status: str, error: Exception | None = None) -> None:
        self.data['status'] = status
        self.data['elapsedSeconds'] = round(time.monotonic() - self.started, 2)
        if error is not None:
            self.data['error'] = self.redact(str(error))
        directory = helpers['report_directory']()
        directory.mkdir(exist_ok=True)
        serialized = self.redact(json.dumps(self.data, ensure_ascii=False, indent=2))
        (directory / (self.data['runId'] + '.json')).write_text(serialized + '\n', encoding='utf-8')
        (directory / 'latest-expected-etag.json').write_text(serialized + '\n', encoding='utf-8')
        print(json.dumps({'status': status, 'checks': len(self.data['checks']), 'error': self.data.get('error')}, ensure_ascii=True))


def delete_fixture_user(docker, user: str) -> None:
    # A successful deletion can race SQLite cleanup hooks from the local cron.
    # Repeating user:delete is safe even if the first attempt removed the user.
    for attempt in range(3):
        try:
            docker.delete_user(user)
            return
        except helpers['TestFailure'] as error:
            if 'database is locked' not in str(error).lower() or attempt == 2:
                raise
            time.sleep((0.3, 0.6)[attempt])


def run() -> int:
    run_id = time.strftime('%Y%m%d%H%M%S', time.gmtime()) + '_' + uuid.uuid4().hex[:6]
    report = EtagReport('expected-etag-' + run_id)
    admin_password = helpers['read_environment']()['ARCHIVE_TOOLS_ADMIN_PASSWORD']
    report.secrets.append(admin_password)
    docker = helpers['Docker'](report)
    docker.assert_test_stack()
    users, clients, mounts = [], {}, []
    complete = False
    failure = None
    try:
        for role in ('alice', 'bob', 'outsider'):
            user = 'at_' + run_id + '_' + role
            docker.add_user(user)
            users.append(user)
            client = helpers['Client'](helpers['test_base_url'](), user, admin_password, report)
            client.csrf()
            clients[role] = client
        alice, bob, outsider = (clients[role] for role in ('alice', 'bob', 'outsider'))
        report.data['fixtureUsers'] = {role: client.user for role, client in clients.items()}
        report.data['jobIds'] = []
        report.data['connectionIds'] = []
        report.data['snapshotVersions'] = {}
        # Scanner::REUSE_ETAG can retain a cached validator while storage_mtime
        # is unchanged, even when file size differs. Use the ordinary DAV mtime
        # header so each replacement has a distinct native storage version.
        fixture_mtime = int(time.time()) - 60
        original_headers = {'X-OC-Mtime': str(fixture_mtime)}
        replacement_headers = {'X-OC-Mtime': str(fixture_mtime + 2)}

        def download(client, source_id: int, payload: dict) -> tuple[int, bytes]:
            # The password and expected ETag travel only in the POST body.
            return client.request('POST', helpers['API'] + f'/archives/{source_id}/download',
                                  data=json.dumps(payload).encode('utf-8'),
                                  headers={'Content-Type': 'application/json'})

        def listings(client) -> tuple[set[int], set[int]]:
            job_status, jobs = client.api('GET', '/jobs')
            mount_status, connections = client.api('GET', '/mounts')
            if job_status != 200 or mount_status != 200:
                raise helpers['TestFailure']('Cannot enumerate the fixture user\'s own archive tasks and connections.')
            return {int(job['id']) for job in jobs}, {int(mount['id']) for mount in connections}

        def expect_changed(name: str, response: tuple[int, object]) -> None:
            status, payload = response
            if isinstance(payload, bytes):
                try:
                    payload = json.loads(payload)
                except (ValueError, UnicodeDecodeError):
                    payload = None
            report.check(name, status == 409 and isinstance(payload, dict)
                         and payload.get('message') == CHANGED_MESSAGE, 'HTTP ' + str(status))

        def projection_etags(client, mount_path: str) -> dict[str, str]:
            """Read real persisted DAV validators for files, directories and root."""
            validators = {}
            root_path = urllib.parse.unquote(client.dav_path(mount_path)).rstrip('/')
            body = b'<d:propfind xmlns:d="DAV:"><d:prop><d:getetag/><d:resourcetype/></d:prop></d:propfind>'
            for suffix in ('', '/nested', '/empty'):
                code, response = client.dav('PROPFIND', mount_path + suffix, data=body,
                                           headers={'Depth': '1', 'Content-Type': 'application/xml'})
                if code != 207:
                    raise helpers['TestFailure']('Cannot enumerate mounted ETags: HTTP ' + str(code))
                for item in ET.fromstring(response).findall('{DAV:}response'):
                    path = urllib.parse.unquote(urllib.parse.urlsplit(item.findtext('{DAV:}href', '')).path).rstrip('/')
                    if path != root_path and not path.startswith(root_path + '/'):
                        raise helpers['TestFailure']('Mounted ETag response escaped its fixture root.')
                    raw = item.findtext('.//{DAV:}getetag', '')
                    if raw.startswith('W/'):
                        raw = raw[2:]
                    value = raw.strip('"')
                    if not value or len(value.encode('utf-8')) > 40:
                        raise helpers['TestFailure']('A mounted DAV ETag exceeds the native filecache validator limit.')
                    validators[path[len(root_path):].lstrip('/')] = value
            return validators

        report.check('shared source folder created', alice.dav('MKCOL', 'Source')[0] == 201)
        old_bytes = b'old archive member\n'
        new_bytes = b'replaced archive member with different contents\n'
        old_zip = features['zip_bytes']({'same.txt': old_bytes})
        new_zip = features['zip_bytes']({'same.txt': new_bytes})
        report.check('original snapshot uploaded', alice.dav('PUT', 'Source/source.zip', data=old_zip, headers=original_headers)[0] == 201)
        source_id = alice.file_id('Source/source.zip')
        alice.ocs('POST', payload={'path': '/Source', 'shareType': 0, 'shareWith': bob.user, 'permissions': 31})
        status, original = bob.api('POST', f'/archives/{source_id}/inspect', {})
        report.check('original snapshot inspected', status == 200 and isinstance(original.get('sourceEtag'), str))
        old_etag = original['sourceEtag']
        destination_id = bob.file_id('')
        before = listings(bob)
        report.check('same source path overwritten', alice.dav('PUT', 'Source/source.zip', data=new_zip, headers=replacement_headers)[0] == 204)
        report.check('replacement keeps the canonical source id', alice.file_id('Source/source.zip') == source_id)
        status, current = bob.api('POST', f'/archives/{source_id}/inspect', {})
        report.data['snapshotVersions']['plain'] = {'originalEtag': old_etag, 'replacementEtag': current.get('sourceEtag'),
                                                   'originalSize': len(old_zip), 'replacementSize': len(new_zip)}
        report.check('replacement has a new archive ETag', status == 200 and current['sourceEtag'] != old_etag)
        current_etag = current['sourceEtag']

        expect_changed('stale download refuses replaced bytes', download(bob, source_id, {'member': 'same.txt', 'expectedEtag': old_etag}))
        expect_changed('stale version is checked before member validation', download(bob, source_id, {'member': 'missing.txt', 'expectedEtag': old_etag}))
        expect_changed('stale extraction is rejected before queueing', bob.api('POST', f'/archives/{source_id}/extract', {
            'destinationId': destination_id, 'folderName': 'Stale output', 'expectedEtag': old_etag}))
        expect_changed('stale connection is rejected before publication', bob.api('POST', '/mounts', {
            'sourceId': source_id, 'name': 'Stale connection', 'expectedEtag': old_etag}))
        report.check('stale actions create no task or connection', listings(bob) == before)
        report.check('stale actions create no extraction folder', bob.dav('PROPFIND', 'Stale output')[0] == 404)
        report.check('stale actions create no connected folder', bob.dav('PROPFIND', 'Source/Stale connection')[0] == 404)
        report.check('stale actions preserve the replacement source bytes', alice.dav('GET', 'Source/source.zip') == (200, new_zip))

        for action in ('download', 'extract', 'connect'):
            if action == 'download':
                response = download(outsider, source_id, {'member': 'same.txt', 'expectedEtag': old_etag})
            elif action == 'extract':
                response = outsider.api('POST', f'/archives/{source_id}/extract', {
                    'destinationId': destination_id, 'folderName': 'Outsider output', 'expectedEtag': old_etag})
            else:
                response = outsider.api('POST', '/mounts', {
                    'sourceId': source_id, 'name': 'Outsider connection', 'expectedEtag': old_etag})
            report.check(action + ': source ACL is checked before snapshot version', response[0] == 404, 'HTTP ' + str(response[0]))
        report.check('unauthorized actions create no own task or connection', listings(outsider) == (set(), set()))

        expect_changed('explicit empty ETag rejects download', download(bob, source_id, {'member': 'same.txt', 'expectedEtag': ''}))
        expect_changed('explicit empty ETag rejects extraction', bob.api('POST', f'/archives/{source_id}/extract', {
            'destinationId': destination_id, 'folderName': 'Empty ETag output', 'expectedEtag': ''}))
        expect_changed('explicit empty ETag rejects connection', bob.api('POST', '/mounts', {
            'sourceId': source_id, 'name': 'Empty ETag connection', 'expectedEtag': ''}))
        report.check('empty ETag creates no task or connection', listings(bob) == before)

        for label, precondition in (('fresh ETag', {'expectedEtag': current_etag}), ('omitted ETag', {})):
            report.check(label + ': download returns current bytes',
                         download(bob, source_id, dict(precondition, member='same.txt')) == (200, new_bytes))
            folder_name = 'Fresh output' if precondition else 'Compatible output'
            status, job = bob.api('POST', f'/archives/{source_id}/extract', dict(
                precondition, destinationId=destination_id, folderName=folder_name))
            report.check(label + ': extraction is queued', status == 202 and job['status'] == 'queued')
            job_id = int(job['id'])
            report.data['jobIds'].append(job_id)
            features['execute_job'](docker, job_id)
            status, result = bob.api('GET', f'/jobs/{job_id}')
            report.check(label + ': native extraction succeeds', status == 200 and result['status'] == 'succeeded')
            report.check(label + ': extracted bytes match current archive', bob.dav('GET', folder_name + '/same.txt') == (200, new_bytes))

            mount_name = 'Fresh connection' if precondition else 'Compatible connection'
            status, mount = bob.api('POST', '/mounts', dict(precondition, sourceId=source_id, name=mount_name))
            report.check(label + ': connection is published', status == 201)
            mount_id = int(mount['id'])
            mounts.append(mount_id)
            report.data['connectionIds'].append(mount_id)
            report.check(label + ': connected archive exposes current bytes', bob.dav('GET', 'Source/' + mount_name + '/same.txt') == (200, new_bytes))
            report.check(label + ': fixture connection disconnected', bob.api('DELETE', f'/mounts/{mount_id}')[0] == 200)
            mounts.remove(mount_id)

        # PostgreSQL enforces filecache.etag varchar(40), while SQLite silently
        # accepts longer values. Exercise the real scanner and persisted DAV
        # metadata, including empty resources and equal payloads at distinct paths.
        projection_files = {'same-a.txt': b'equal member bytes\n', 'nested/same-b.txt': b'equal member bytes\n',
                            'empty-file.txt': b'', 'empty/': b''}
        projection_zip = features['zip_bytes'](projection_files)
        for filename in ('validators.zip', 'validators-copy.zip'):
            report.check('validator fixture uploaded: ' + filename,
                         alice.dav('PUT', 'Source/' + filename, data=projection_zip, headers=original_headers)[0] == 201)
        validator_source = alice.file_id('Source/validators.zip')
        copy_source = alice.file_id('Source/validators-copy.zip')
        status, validator_info = bob.api('POST', f'/archives/{validator_source}/inspect', {})
        report.check('validator snapshot inspected', status == 200)
        expected_paths = {'', 'same-a.txt', 'nested', 'nested/same-b.txt', 'empty-file.txt', 'empty'}
        validator_mounts = []
        validator_maps = []
        for label, source in (('original', validator_source), ('copy', copy_source)):
            mount_name = 'Validators ' + label
            status, mount = bob.api('POST', '/mounts', {'sourceId': source, 'name': mount_name})
            report.check(label + ': native scanner persists bounded validator metadata', status == 201)
            mount_id = int(mount['id'])
            mounts.append(mount_id)
            validator_mounts.append((mount_id, mount_name))
            values = projection_etags(bob, 'Source/' + mount_name)
            report.check(label + ': file folder root and empty resource ETags fit filecache', set(values) == expected_paths)
            report.check(label + ': equal payloads at different entry paths have different ETags',
                         values['same-a.txt'] != values['nested/same-b.txt'])
            report.check(label + ': repeated DAV reads keep stable validators', values == projection_etags(bob, 'Source/' + mount_name))
            validator_maps.append(values)
        report.check('identical entries in different sources retain distinct storage validators',
                     all(validator_maps[0][path] != validator_maps[1][path] for path in expected_paths))
        changed_projection = dict(projection_files, **{'same-a.txt': b'changed validator member\n'})
        report.check('validator source overwritten without changing its identity',
                     alice.dav('PUT', 'Source/validators.zip', data=features['zip_bytes'](changed_projection),
                               headers=replacement_headers)[0] == 204
                     and alice.file_id('Source/validators.zip') == validator_source)
        status, changed_info = bob.api('POST', f'/archives/{validator_source}/inspect', {})
        report.data['snapshotVersions']['projection'] = {'originalEtag': validator_info['sourceEtag'],
                                                        'replacementEtag': changed_info.get('sourceEtag')}
        report.check('changed validator source invalidates its archive snapshot',
                     status == 200 and changed_info['sourceEtag'] != validator_info['sourceEtag'])
        report.check('old validator projection refuses the replaced source',
                     bob.dav('GET', 'Source/Validators original/same-a.txt')[0] in (404, 503))
        old_mount_id, old_mount_name = validator_mounts[0]
        report.check('stale validator fixture disconnected', bob.api('DELETE', f'/mounts/{old_mount_id}')[0] == 200)
        mounts.remove(old_mount_id)
        status, refreshed = bob.api('POST', '/mounts', {'sourceId': validator_source, 'name': old_mount_name})
        report.check('replacement validator projection is published', status == 201)
        refreshed_id = int(refreshed['id'])
        mounts.append(refreshed_id)
        refreshed_values = projection_etags(bob, 'Source/' + old_mount_name)
        report.check('replacement gives fresh bounded file and folder validators',
                     set(refreshed_values) == expected_paths
                     and all(refreshed_values[path] != validator_maps[0][path] for path in expected_paths))
        report.check('replacement projection returns the new member bytes',
                     bob.dav('GET', 'Source/' + old_mount_name + '/same-a.txt') == (200, changed_projection['same-a.txt']))
        for mount_id in (validator_mounts[1][0], refreshed_id):
            report.check('validator fixture disconnected', bob.api('DELETE', f'/mounts/{mount_id}')[0] == 200)
            mounts.remove(mount_id)

        archive_password = hashlib.sha256((admin_password + '::' + bob.user).encode()).hexdigest()
        report.secrets.append(archive_password)

        def encrypted_fixture(comment: bytes = b'') -> bytes:
            payload = json.loads(docker.run(['exec', '-T', '--user', 'www-data', 'nextcloud',
                                            'php', '/opt/archive-tools-tests/queue-security-helper.php', 'fixture', bob.user]))
            raw = base64.b64decode(payload['zipBase64'])
            if comment:
                buffer = io.BytesIO(raw)
                with zipfile.ZipFile(buffer, 'a') as archive:
                    archive.comment = comment
                return buffer.getvalue()
            return raw

        report.check('protected source folder created', bob.dav('MKCOL', 'Protected')[0] == 201)
        protected_zip = encrypted_fixture()
        report.check('AES protected archive uploaded', bob.dav('PUT', 'Protected/protected.zip', data=protected_zip,
                                                            headers=original_headers)[0] == 201)
        protected_id = bob.file_id('Protected/protected.zip')
        status, protected = bob.api('POST', f'/archives/{protected_id}/inspect', {})
        report.check('protected snapshot metadata inspected', status == 200 and protected['encrypted'] is True)
        protected_etag = protected['sourceEtag']
        protected_payload = {'member': 'private/secret.bin', 'expectedEtag': protected_etag, 'password': archive_password}
        report.check('AES download accepts matching version and password in POST body',
                     download(bob, protected_id, protected_payload) == (200, b'authenticated content\0\xff'))
        report.check('AES download with matching version rejects wrong password',
                     download(bob, protected_id, dict(protected_payload, password='incorrect'))[0] == 422)
        protected_before = listings(bob)
        # Local storage derives its ETag from mtime/inode/device/size, not file
        # contents. A fresh AES salt changes bytes but keeps the same ZIP size;
        # two writes in one second can therefore retain the same native ETag.
        # A ZIP comment changes the size without touching encrypted members.
        protected_replacement = encrypted_fixture(b'expected-etag replacement snapshot')
        report.check('protected archive replaced at the same path with a different size',
                     len(protected_replacement) != len(protected_zip)
                     and bob.dav('PUT', 'Protected/protected.zip', data=protected_replacement,
                                 headers=replacement_headers)[0] == 204)
        report.check('protected replacement preserves its source id', bob.file_id('Protected/protected.zip') == protected_id)
        status, replaced_protected = bob.api('POST', f'/archives/{protected_id}/inspect', {})
        report.data['snapshotVersions']['protected'] = {'originalEtag': protected_etag,
                                                       'replacementEtag': replaced_protected.get('sourceEtag'),
                                                       'originalSize': len(protected_zip),
                                                       'replacementSize': len(protected_replacement)}
        report.check('protected replacement has a new archive ETag',
                     status == 200 and replaced_protected['sourceEtag'] != protected_etag)
        expect_changed('AES stale download rejects an old protected snapshot', download(bob, protected_id, protected_payload))
        expect_changed('AES stale version is checked before password probing', download(bob, protected_id, dict(protected_payload, password='incorrect')))
        report.check('AES replacement accepts its fresh version and password after stale requests',
                     download(bob, protected_id, dict(protected_payload, expectedEtag=replaced_protected['sourceEtag']))
                     == (200, b'authenticated content\0\xff'))
        report.check('protected download preconditions create no task or connection', listings(bob) == protected_before)
        complete = True
    except Exception as error:
        failure = error
    finally:
        if complete:
            try:
                for mount_id in reversed(mounts):
                    clients['bob'].api('DELETE', f'/mounts/{mount_id}')
                for user in reversed(users):
                    delete_fixture_user(docker, user)
            except Exception as error:
                complete = False
                failure = error
        report.data['fixturesKept'] = not complete
    report.write('passed' if complete else 'failed', failure)
    return 0 if complete else 1


if __name__ == '__main__':
    if hasattr(sys.stdout, 'reconfigure'):
        sys.stdout.reconfigure(encoding='utf-8')
    sys.exit(run())
