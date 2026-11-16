#!/usr/bin/env python3
"""Exercise configurable archive lifecycle only in the isolated local stack."""
from __future__ import annotations

import json
import subprocess
import sys
import time
import urllib.parse
import uuid
import xml.etree.ElementTree as ET
from pathlib import Path

from integration import DAV_NS, Client, Docker, Report, TestFailure, fixture_zip, read_environment, test_base_url, report_directory


def main() -> int:
    if hasattr(sys.stdout, 'reconfigure'):
        sys.stdout.reconfigure(encoding='utf-8')
    report = Report(time.strftime('%Y%m%d%H%M%S', time.gmtime()) + '_' + uuid.uuid4().hex[:6])
    report.data['suite'] = 'lifecycle'
    docker = Docker(report)
    created: list[str] = []
    original: dict | None = None
    mount_id: int | None = None
    completed = False
    holder: subprocess.Popen | None = None
    try:
        docker.assert_test_stack()
        password = read_environment()['ARCHIVE_TOOLS_ADMIN_PASSWORD']
        report.secrets.append(password)
        admin = Client(test_base_url(), 'admin', password, report)
        admin.csrf()
        status, original = admin.api('GET', '/settings')
        report.check('administrator can configure lifecycle', status == 200)
        policy = dict(original, idleTimeoutMinutes=1, allowPin=True)
        report.check('one-minute shared inactivity policy is saved', admin.api('PUT', '/settings', policy)[0] == 200)
        clients: dict[str, Client] = {}
        for role in ('alice', 'bob', 'carol'):
            user = 'at_' + report.data['runId'] + '_' + role
            docker.add_user(user)
            created.append(user)
            clients[role] = Client(test_base_url(), user, password, report)
            clients[role].csrf()
        alice, bob, carol = (clients[role] for role in ('alice', 'bob', 'carol'))
        report.data['fixtureUsers'] = {key: value.user for key, value in clients.items()}

        def helper(command: str, connection: int | None = None) -> object:
            arguments = ['exec', '-T', '--user', 'www-data', 'nextcloud', 'php', '/opt/archive-tools-tests/lifecycle-helper.php', command]
            if connection is not None:
                arguments.append(str(connection))
            return json.loads(docker.run(arguments))

        archive, files = fixture_zip()
        report.check('create lifecycle fixture folder', alice.dav('MKCOL', 'Team')[0] == 201)
        report.check('upload lifecycle ZIP', alice.dav('PUT', 'Team/fixture.zip', data=archive)[0] == 201)
        source_id = alice.file_id('Team/fixture.zip')
        bob_share = alice.ocs('POST', payload={'path': '/Team', 'shareType': 0, 'shareWith': bob.user, 'permissions': 31})['data']['id']
        alice.ocs('POST', payload={'path': '/Team', 'shareType': 0, 'shareWith': carol.user, 'permissions': 1})
        status, connection = bob.api('POST', '/mounts', {'sourceId': source_id, 'name': 'Connected'})
        report.check('create shared lifecycle connection', status == 201)
        mount_id = int(connection['id'])
        report.check('connection API includes management and viewer path', connection['canManage'] is True and connection['path'] == 'Team/Connected')
        report.check('readonly member cannot pin common connection', carol.api('PATCH', '/mounts/' + str(mount_id), {'pinned': True})[0] == 403)
        report.check('pin type is validated', bob.api('PATCH', '/mounts/' + str(mount_id), {'pinned': 'true'})[0] == 400)

        helper('old', mount_id)
        old = helper('state', mount_id)['lastAccess']
        alice.names('Team')
        report.check('listing only the parent does not renew an archive', helper('state', mount_id)['lastAccess'] == old)
        alice.names('Team/Connected')
        report.check('cached archive directory browsing renews activity', helper('state', mount_id)['lastAccess'] > old)
        report.check('recent activity keeps the common connection', mount_id not in helper('cleanup')['removed'])

        helper('old', mount_id)
        status, data = carol.dav('GET', 'Team/Connected/readme.txt')
        report.check('any participant can renew through a file read', status == 200 and data == files['readme.txt'] and helper('state', mount_id)['lastAccess'] > old)

        report.check('manager can pin common connection', bob.api('PATCH', '/mounts/' + str(mount_id), {'pinned': True})[0] == 200)
        helper('old', mount_id)
        report.check('pinned connection is exempt from idle cleanup', mount_id not in helper('cleanup')['removed'])
        alice.ocs('DELETE', '/' + str(bob_share))
        report.check('creator revocation does not remove a still-shared pinned connection', mount_id not in helper('cleanup')['removed'])
        status, data = carol.dav('GET', 'Team/Connected/readme.txt')
        report.check('other participant still reads after creator revocation', status == 200 and data == files['readme.txt'])
        report.check('folder owner can unpin after creator left', alice.api('PATCH', '/mounts/' + str(mount_id), {'pinned': False})[0] == 200)

        disabled = dict(policy, idleTimeoutMinutes=0)
        admin.api('PUT', '/settings', disabled)
        helper('old', mount_id)
        report.check('zero disables automatic idle disconnection', mount_id not in helper('cleanup')['removed'])
        admin.api('PUT', '/settings', policy)

        command = docker.prefix + ['exec', '-T', '--user', 'www-data', 'nextcloud', 'php', '/opt/archive-tools-tests/lifecycle-helper.php', 'hold-stream', str(mount_id), carol.user, 'Team/Connected/readme.txt']
        holder = subprocess.Popen(command, cwd=Path(__file__).resolve().parents[1], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, encoding='utf-8')
        report.check('held read stream opens with a lifetime lease', holder.stdout.readline().strip() == 'READY')
        helper('old', mount_id)
        report.check('stream lease is recorded', helper('state', mount_id)['active'] is True)
        report.check('idle cleanup cannot disconnect an active stream', mount_id not in helper('cleanup')['removed'])
        report.check('manual disconnect cannot race an active stream', alice.api('DELETE', '/mounts/' + str(mount_id))[0] == 409)
        output, errors = holder.communicate(timeout=15)
        report.check('held stream closes without errors', holder.returncode == 0 and 'CLOSED' in output, errors[:300])
        holder = None
        report.check('closing the stream releases its lease', helper('state', mount_id)['active'] is False)
        report.check('inactive unpinned archive disconnects after last stream closes', mount_id in helper('cleanup')['removed'])
        mount_id = None
        report.check('automatic disconnect leaves the source archive intact', alice.dav('GET', 'Team/fixture.zip') == (200, archive))

        report.check('disable idle cleanup for the trash fixture', admin.api('PUT', '/settings', disabled)[0] == 200)
        trash_mounts = {}
        for name, pinned in (('Regular source', False), ('Pinned source', True)):
            status, connection = alice.api('POST', '/mounts', {'sourceId': source_id, 'name': name, 'pinned': pinned})
            report.check(f'create {name} connection for the trash fixture', status == 201)
            trash_mounts[name] = int(connection['id'])

        editor_share = alice.ocs('POST', payload={'path': '/Team', 'shareType': 0, 'shareWith': bob.user,
                                                'permissions': 31})['data']['id']
        managed_name = 'Disconnect while hidden'
        status, managed = bob.api('POST', '/mounts', {'sourceId': bob.file_id('Team/fixture.zip'),
                                                    'name': managed_name, 'pinned': True})
        report.check('share editor creates a pinned connection that the owner can manage', status == 201)
        managed_id = int(managed['id'])

        occ = ['exec', '-T', '--user', 'www-data', 'nextcloud', 'php', 'occ']
        jobs = json.loads(docker.run(occ + ['background-job:list', '--class',
                                            r'OCA\ArchiveTools\BackgroundJob\CleanupJob', '--output=json']))
        report.check('the native archive cleanup job is registered', len(jobs) == 1)
        run_cleanup = occ + ['background-job:execute', '--force-execute', str(jobs[0]['id'])]
        trash_url = f'/remote.php/dav/trashbin/{alice.user}'

        def trashed_source() -> str:
            nc_ns = 'http://nextcloud.org/ns'
            body = (f'<d:propfind xmlns:d="{DAV_NS}" xmlns:nc="{nc_ns}">'
                    '<d:prop><nc:trashbin-filename/></d:prop></d:propfind>').encode()
            status, result = alice.request('PROPFIND', trash_url + '/trash', data=body,
                                           headers={'Depth': '1', 'Content-Type': 'application/xml'})
            report.check('list owner trash through WebDAV', status == 207)
            matches = [urllib.parse.urlsplit(item.findtext(f'{{{DAV_NS}}}href', '')).path
                       for item in ET.fromstring(result).findall(f'{{{DAV_NS}}}response')
                       if item.findtext(f'.//{{{nc_ns}}}trashbin-filename') == 'fixture.zip']
            report.check('find the original archive in the owner trash',
                         len(matches) == 1 and matches[0].startswith(trash_url + '/trash/'))
            return matches[0]

        def check_trash_mounts_readable(stage: str) -> None:
            for role, client in (('owner', alice), ('recipient', carol)):
                report.check(f'{stage}: both archive folders are listed for the {role}',
                             set(trash_mounts).issubset(client.names('Team')))
                for name in trash_mounts:
                    report.check(f'{stage}: the {role} can read {name} through WebDAV',
                                 client.dav('GET', f'Team/{name}/readme.txt') == (200, files['readme.txt']))

        check_trash_mounts_readable('before trash')
        report.check('move original source into Nextcloud trash', alice.dav('DELETE', 'Team/fixture.zip')[0] == 204)
        entry = trashed_source()
        docker.run(run_cleanup)
        for name, connection_id in trash_mounts.items():
            report.check(f'native cleanup retains {name} while the source is in trash',
                         helper('state', connection_id) is not None)
        for role, client in (('owner', alice), ('recipient', carol)):
            report.check(f'while in trash both archive folders are hidden for the {role}',
                         set(trash_mounts).isdisjoint(client.names('Team')))
            for name in trash_mounts:
                report.check(f'while in trash the {role} cannot read {name} through WebDAV',
                             client.dav('GET', f'Team/{name}/readme.txt')[0] in (403, 404))

        code, visible = alice.api('GET', '/mounts')
        owners_row = next((row for row in visible if row['id'] == managed_id), None) if code == 200 else None
        report.check('the current parent owner sees a management record without source metadata',
                     owners_row == {'id': managed_id, 'parentId': alice.file_id('Team'), 'name': managed_name,
                                    'path': f'Team/{managed_name}', 'pinned': True, 'available': False,
                                    'state': 'source_unavailable', 'canManage': True})
        for role, client in (('read-only recipient', carol), ('share editor and original creator', bob)):
            code, listed = client.api('GET', '/mounts')
            report.check(f'the {role} cannot see hidden management metadata',
                         code == 200 and all(row['id'] != managed_id for row in listed))
            report.check(f'the {role} cannot disconnect the hidden archive',
                         client.api('DELETE', f'/mounts/{managed_id}')[0] == 404)
        alice.ocs('DELETE', f'/{editor_share}')
        report.check('the creator cannot disconnect after the share was revoked',
                     bob.api('DELETE', f'/mounts/{managed_id}')[0] == 404)
        report.check('the current parent owner can disconnect a hidden pinned archive',
                     alice.api('DELETE', f'/mounts/{managed_id}') == (200, {'disconnected': True})
                     and helper('state', managed_id) is None)
        report.check('the source is still in the trash after a manual disconnect', trashed_source() == entry)
        report.check('manual disconnect leaves the other hidden connections intact',
                     all(helper('state', connection_id) is not None for connection_id in trash_mounts.values()))
        report.check('upload a replacement archive for the released name', alice.dav('PUT', 'Team/replacement.zip',
                                                                                   data=archive)[0] == 201)
        code, replacement = alice.api('POST', '/mounts', {'sourceId': alice.file_id('Team/replacement.zip'),
                                                        'name': managed_name})
        report.check('the released name can be reused while the original is still in the trash', code == 201)
        report.check('disconnect replacement fixture', alice.api('DELETE', f'/mounts/{replacement["id"]}')[0] == 200)

        destination = alice.base + trash_url + '/restore/' + entry.rsplit('/', 1)[-1]
        status, _ = alice.request('MOVE', entry, headers={'Destination': destination})
        report.check('restore the original archive through Nextcloud trash', status in (201, 204))
        report.check('restored archive retains its file ID and content',
                     alice.file_id('Team/fixture.zip') == source_id
                     and alice.dav('GET', 'Team/fixture.zip') == (200, archive))
        check_trash_mounts_readable('after restore')

        report.check('move the restored archive back into trash', alice.dav('DELETE', 'Team/fixture.zip')[0] == 204)
        report.check('permanently delete the original archive from trash',
                     alice.request('DELETE', trashed_source())[0] == 204)
        docker.run(run_cleanup)
        for name, connection_id in trash_mounts.items():
            report.check(f'native cleanup removes {name} after permanent source deletion',
                         helper('state', connection_id) is None)
        completed = True
    except Exception as error:
        report.data['error'] = report.redact(str(error))
    finally:
        if holder is not None:
            try:
                holder.communicate(timeout=15)
            except subprocess.TimeoutExpired:
                holder.terminate()
        if original is not None:
            try:
                if admin.api('PUT', '/settings', original)[0] != 200:
                    raise TestFailure('The original lifecycle settings could not be restored.')
            except Exception as error:
                report.data['restoreError'] = report.redact(str(error))
                completed = False
        if completed:
            for user in reversed(created):
                try:
                    docker.delete_user(user)
                except Exception as error:
                    report.data.setdefault('cleanupErrors', []).append(report.redact(str(error)))
            if report.data.get('cleanupErrors'):
                completed = False
        report.data['fixturesKept'] = not completed and bool(created)
        destination = report_directory()
        destination.mkdir(exist_ok=True)
        report.data['status'] = 'passed' if completed else 'failed'
        report.data['elapsedSeconds'] = round(time.monotonic() - report.started, 2)
        serialized = report.redact(json.dumps(report.data, ensure_ascii=False, indent=2))
        (destination / 'latest-lifecycle.json').write_text(serialized + '\n', encoding='utf-8')
        (destination / ('lifecycle-' + report.data['runId'] + '.json')).write_text(serialized + '\n', encoding='utf-8')
        print(json.dumps({
            'suite': 'lifecycle', 'status': report.data['status'],
            'checks': len(report.data['checks']), 'error': report.data.get('error'),
            'restoreError': report.data.get('restoreError'),
        }, ensure_ascii=True))
    return 0 if completed else 1


if __name__ == '__main__':
    sys.exit(main())
