#!/usr/bin/env python3
"""Exercise real readonly archive mounts through the isolated local WebDAV API."""
from __future__ import annotations

import io
import json
from pathlib import Path
import runpy
import sys
import time
import uuid
import zipfile

ROOT = Path(__file__).resolve().parents[1]
h = runpy.run_path(str(ROOT / 'tests' / 'integration.py'))


class ReadonlyReport(h['Report']):
    def write(self, status, error=None):
        self.data['status'] = status
        self.data['elapsedSeconds'] = round(time.monotonic() - self.started, 2)
        if error is not None:
            self.data['error'] = self.redact(str(error))
        directory = h['report_directory']()
        directory.mkdir(exist_ok=True)
        serialized = self.redact(json.dumps(self.data, ensure_ascii=False, indent=2))
        (directory / (self.data['runId'] + '.json')).write_text(serialized + '\n', encoding='utf-8')
        (directory / 'latest-webdav-readonly.json').write_text(serialized + '\n', encoding='utf-8')
        print(json.dumps({'status': status, 'checks': len(self.data['checks']), 'error': self.data.get('error')}, ensure_ascii=True))


def run():
    run_id = time.strftime('%Y%m%d%H%M%S', time.gmtime()) + '_' + uuid.uuid4().hex[:6]
    report = ReadonlyReport('webdav-readonly-' + run_id)
    secret = h['read_environment']()['ARCHIVE_TOOLS_ADMIN_PASSWORD']
    report.secrets.append(secret)
    docker = h['Docker'](report)
    docker.assert_test_stack()
    users, clients = [], {}
    completed = False
    mount_id = None
    error = None
    try:
        for role in ('alice', 'bob'):
            user = 'at_' + run_id + '_' + role
            docker.add_user(user)
            users.append(user)
            client = h['Client'](h['test_base_url'](), user, secret, report)
            client.csrf()
            clients[role] = client
        report.data['fixtureUsers'] = {role: client.user for role, client in clients.items()}
        alice, bob = clients['alice'], clients['bob']
        files = {'readme.txt': b'original archive member\n', 'nested/data.bin': bytes(range(256))}
        buffer = io.BytesIO()
        with zipfile.ZipFile(buffer, 'w', zipfile.ZIP_DEFLATED) as archive:
            for name, content in files.items():
                archive.writestr(name, content)
        source_bytes = buffer.getvalue()
        report.check('fixture folder created', alice.dav('MKCOL', 'Team')[0] == 201)
        report.check('source archive uploaded', alice.dav('PUT', 'Team/fixture.zip', data=source_bytes)[0] == 201)
        source_id = alice.file_id('Team/fixture.zip')
        alice.ocs('POST', payload={'path': '/Team', 'shareType': 0, 'shareWith': bob.user, 'permissions': 31})
        report.check('recipient can write ordinary shared files', bob.dav('PUT', 'Team/writable-control.txt', data=b'share is writable')[0] == 201)
        status, mount = bob.api('POST', '/mounts', {'sourceId': source_id, 'name': 'Readonly archive', 'pinned': False})
        report.check('writable participant connects one shared archive', status == 201)
        mount_id = int(mount['id'])
        report.data['connection'] = {'id': mount_id, 'sourceId': source_id}
        report.data['denialStatuses'] = {}
        archive_path = 'Team/Readonly archive'
        for role, client in clients.items():
            report.check(role + ': mounted archive appears in shared parent PROPFIND', 'Readonly archive' in client.names('Team'))
            report.check(role + ': archive root PROPFIND lists members', {'readme.txt', 'nested'}.issubset(client.names(archive_path)))
            report.check(role + ': nested PROPFIND lists members', 'data.bin' in client.names(archive_path + '/nested'))
            for name, content in files.items():
                report.check(role + ': GET exact bytes before mutation: ' + name,
                             client.dav('GET', archive_path + '/' + name) == (200, content))
            destination = 'Team/moved-' + role + '.txt'
            operations = (
                ('PUT new member', 'PUT', archive_path + '/new-' + role + '.txt', b'new forbidden content', None),
                ('PUT existing member', 'PUT', archive_path + '/readme.txt', b'overwritten forbidden content', None),
                ('MKCOL new directory', 'MKCOL', archive_path + '/new-folder-' + role, None, None),
                ('DELETE existing member', 'DELETE', archive_path + '/readme.txt', None, None),
                ('DELETE existing directory', 'DELETE', archive_path + '/nested', None, None),
                ('MOVE member out of archive', 'MOVE', archive_path + '/readme.txt', None,
                 {'Destination': client.base + client.dav_path(destination), 'Overwrite': 'F'}),
            )
            for label, method, path, data, headers in operations:
                status, _ = client.dav(method, path, data=data, headers=headers)
                report.data['denialStatuses'][role + ': ' + label] = status
                report.check(role + ': denies ' + label, status in (403, 405, 409), 'HTTP ' + str(status))
            report.check(role + ': failed MOVE creates no destination', client.dav('GET', destination)[0] == 404)
            report.check(role + ': forbidden member was not created', client.dav('GET', archive_path + '/new-' + role + '.txt')[0] == 404)
            report.check(role + ': forbidden directory was not created', client.dav('PROPFIND', archive_path + '/new-folder-' + role)[0] == 404)
        # Check both participants again after every attempted modification.
        for role, client in clients.items():
            for name, content in files.items():
                report.check(role + ': original member preserved: ' + name,
                             client.dav('GET', archive_path + '/' + name) == (200, content))
            report.check(role + ': source ZIP preserved byte for byte', client.dav('GET', 'Team/fixture.zip') == (200, source_bytes))
        report.check('owner disconnects test mount', alice.api('DELETE', '/mounts/' + str(mount_id))[0] == 200)
        mount_id = None
        completed = True
    except Exception as failure:
        error = failure
    finally:
        if completed:
            for user in reversed(users):
                docker.delete_user(user)
        report.data['fixturesKept'] = not completed
    report.write('passed' if completed else 'failed', error)
    return 0 if completed else 1


if __name__ == '__main__':
    if hasattr(sys.stdout, 'reconfigure'):
        sys.stdout.reconfigure(encoding='utf-8')
    sys.exit(run())
