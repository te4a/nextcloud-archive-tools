#!/usr/bin/env python3
"""Exercise viewer, native extraction jobs and ACLs on the local development stack."""
from __future__ import annotations

import io
import json
from pathlib import Path
import runpy
import sys
import time
import urllib.parse
import uuid
import zipfile

ROOT = Path(__file__).resolve().parents[1]
helpers = runpy.run_path(str(ROOT / 'tests' / 'integration.py'))
Client, Docker, Report = (helpers[name] for name in ('Client', 'Docker', 'Report'))


class FeatureReport(Report):
    def write(self, status: str, error: Exception | None = None) -> Path:
        self.data['status'] = status
        self.data['elapsedSeconds'] = round(time.monotonic() - self.started, 2)
        if error is not None:
            self.data['error'] = self.redact(str(error))
        serialized = self.redact(json.dumps(self.data, ensure_ascii=False, indent=2))
        directory = helpers['report_directory']()
        directory.mkdir(exist_ok=True)
        output = directory / (self.data['runId'] + '.json')
        output.write_text(serialized + '\n', encoding='utf-8')
        (directory / 'latest-features.json').write_text(serialized + '\n', encoding='utf-8')
        print(json.dumps({'status': status, 'checks': len(self.data['checks']), 'error': self.data.get('error')}, ensure_ascii=True))
        return output


def zip_bytes(files: dict[str, bytes], compression=zipfile.ZIP_DEFLATED) -> bytes:
    target = io.BytesIO()
    with zipfile.ZipFile(target, 'w', compression) as archive:
        for name, data in files.items():
            archive.writestr(name, data)
    return target.getvalue()


def execute_job(docker, job_id: int, allow_missing=False) -> None:
    native_id = docker.run(['exec', '-T', '--user', 'www-data', 'nextcloud',
                            'php', '/opt/archive-tools-tests/job-driver.php', str(job_id)])
    if native_id == '0' and allow_missing:
        return
    if not native_id.isdecimal() or int(native_id) < 1:
        raise RuntimeError('The scheduled extraction is missing from the native job queue.')
    docker.run(['exec', '-T', '--user', 'www-data', 'nextcloud', 'php', 'occ',
                'background-job:execute', '--force-execute', native_id])


def run():
    run_id = time.strftime('%Y%m%d%H%M%S', time.gmtime()) + '_' + uuid.uuid4().hex[:6]
    report = FeatureReport('features-' + run_id)
    password = helpers['read_environment']()['ARCHIVE_TOOLS_ADMIN_PASSWORD']
    report.secrets.append(password)
    docker = Docker(report)
    docker.assert_test_stack()
    base = helpers['test_base_url']()
    users, clients = [], {}
    completed = False
    try:
        for role in ('alice', 'bob', 'outsider'):
            user = 'at_' + run_id + '_' + role
            docker.add_user(user)
            users.append(user)
            clients[role] = Client(base, user, password, report)
            clients[role].csrf()
        alice, bob, outsider = (clients[x] for x in ('alice', 'bob', 'outsider'))
        report.data['fixtureUsers'] = {role: client.user for role, client in clients.items()}
        report.check('shared source folder created', alice.dav('MKCOL', 'Source')[0] == 201)
        files = {'one.txt': b'one\n', 'nested/two.txt': b'two\n', 'nested/three.bin': bytes(range(256)),
                 'кириллица/имя.txt': 'Общий архив\n'.encode()}
        original = zip_bytes(files)
        report.check('source ZIP uploaded', alice.dav('PUT', 'Source/source.zip', data=original)[0] == 201)
        source = alice.file_id('Source/source.zip')
        alice.ocs('POST', payload={'path': '/Source', 'shareType': 0, 'shareWith': bob.user, 'permissions': 1})
        status, info = bob.api('POST', f'/archives/{source}/inspect', {})
        report.check('shared archive inspected without persistent mount', status == 200 and info['fileCount'] == len(files))
        paths = {entry['path'] for entry in info['entries']}
        report.check('viewer exposes normalized Unicode and nested paths', set(files) <= paths)
        report.check('viewer response excludes passwords and raw archive member names',
                     all('originalName' not in entry for entry in info['entries']) and 'password' not in info)
        known = {'knownEtag': info['sourceEtag']}
        status, cached = bob.api('POST', f'/archives/{source}/inspect', known)
        report.check('unchanged archive returns only the current ETag',
                     status == 200 and cached == {'unchanged': True, 'sourceEtag': info['sourceEtag']})
        status, refreshed = bob.api('POST', f'/archives/{source}/inspect', {'knownEtag': 'stale'})
        report.check('a stale ETag returns the full current index', status == 200 and refreshed == info)
        report.check('conditional inspect still requires access',
                     outsider.api('POST', f'/archives/{source}/inspect', known)[0] in (403, 404))
        browse = f'/archives/{source}/browse-zip'
        status, root = bob.api('POST', browse, {})
        report.check('lazy ZIP root contains immediate children only',
                     status == 200 and root['folder'] is None and
                     {entry['path'] for entry in root['entries']} == {'one.txt', 'nested', 'кириллица'})
        status, nested = bob.api('POST', browse, {'path': 'nested', 'expectedEtag': root['sourceEtag']})
        report.check('lazy ZIP folder contains only its own direct children',
                     status == 200 and {entry['path'] for entry in nested['entries']} == {'nested/two.txt', 'nested/three.bin'})
        report.check('lazy ZIP keeps the same ID across a folder and its parent',
                     nested['folder']['renderIndex'] == next(entry['renderIndex'] for entry in root['entries'] if entry['path'] == 'nested'))
        report.check('lazy ZIP does not expose raw archive paths',
                     all('originalName' not in entry and 'crc' not in entry for entry in root['entries'] + nested['entries']))
        status, changed = bob.api('POST', browse, {'path': 'nested', 'expectedEtag': 'stale'})
        report.check('lazy ZIP detects a stale source before listing', status == 409 and changed['code'] == 'archive_changed')
        report.check('lazy ZIP inaccessible to an unrelated user', outsider.api('POST', browse, {})[0] == 404)
        report.check('lazy ZIP reports absent directories', bob.api('POST', browse, {'path': 'missing'})[0] == 404)
        report.check('lazy ZIP refuses a file as a directory', bob.api('POST', browse, {'path': 'one.txt'})[0] == 404)
        report.check('unrelated user cannot inspect the archive', outsider.api('POST', f'/archives/{source}/inspect', {})[0] in (403, 404))
        status, data = bob.request('POST', helpers['API'] + f'/archives/{source}/download',
                                   data=json.dumps({'member': 'nested/two.txt'}).encode(),
                                   headers={'Content-Type': 'application/json', 'requesttoken': bob.token})
        report.check('single member downloaded with exact bytes', status == 200 and data == files['nested/two.txt'])
        status, data = bob.request('POST', helpers['API'] + f'/archives/{source}/download',
                                   data=urllib.parse.urlencode({'member': 'nested/two.txt', 'password': '',
                                                               'expectedEtag': info['sourceEtag']}).encode('utf-8'),
                                   headers={'Content-Type': 'application/x-www-form-urlencoded', 'requesttoken': bob.token})
        report.check('HTML form download accepts an empty password for an unencrypted archive',
                     status == 200 and data == files['nested/two.txt'])
        report.check('read-only shared folder cannot be extraction destination',
                     bob.api('POST', f'/archives/{source}/extract', {'folderName': 'Denied'})[0] == 403)
        destination = bob.file_id('')
        payload = {'destinationId': destination, 'folderName': 'Selected', 'members': ['nested']}
        status, job = bob.api('POST', f'/archives/{source}/extract', payload)
        report.check('extraction returns a native queued job', status == 202 and job['status'] == 'queued')
        job_id = int(job['id'])
        second_status, duplicate = bob.api('POST', f'/archives/{source}/extract', payload)
        report.check('duplicate queued extraction is deduplicated', second_status == 202 and int(duplicate['id']) == job_id)
        report.check('another user cannot inspect job status', outsider.api('GET', f'/jobs/{job_id}')[0] in (403, 404))
        report.check('another user cannot cancel the job', outsider.api('DELETE', f'/jobs/{job_id}')[0] in (403, 404))
        execute_job(docker, job_id)
        status, finished = bob.api('GET', f'/jobs/{job_id}')
        report.check('selected extraction completed with progress', status == 200 and finished['status'] == 'succeeded'
                     and finished['filesDone'] == 2 and finished['bytesDone'] == sum(len(files[k]) for k in files if k.startswith('nested/')))
        for name in ('nested/two.txt', 'nested/three.bin'):
            status, content = bob.dav('GET', 'Selected/' + name)
            report.check('selected member bytes: ' + name, status == 200 and content == files[name])
        report.check('unselected member is absent', bob.dav('GET', 'Selected/one.txt')[0] == 404)
        report.check('extracted files have writable destination permissions', bob.dav('PUT', 'Selected/nested/two.txt', data=b'edited')[0] == 204)
        report.check('extracted files can be deleted', bob.dav('DELETE', 'Selected/nested/two.txt')[0] == 204)
        report.check('existing destination is not overwritten', bob.api('POST', f'/archives/{source}/extract', payload)[0] == 409)
        bad_status, _ = bob.api('POST', f'/archives/{source}/extract', {'destinationId': destination, 'folderName': 'Escape', 'members': ['../one.txt']})
        report.check('invalid selection is rejected before scheduling', bad_status in (400, 422))
        status, cancel_job = bob.api('POST', f'/archives/{source}/extract', {'destinationId': destination, 'folderName': 'Cancelled'})
        report.check('second task queued', status == 202)
        cancelled_id = int(cancel_job['id'])
        status, cancelled = bob.api('DELETE', f'/jobs/{cancelled_id}')
        report.check('queued extraction can be cancelled', status == 200 and cancelled['status'] == 'cancelled')
        execute_job(docker, cancelled_id, allow_missing=True)
        report.check('cancelled extraction creates no destination', bob.dav('PROPFIND', 'Cancelled')[0] == 404)
        status, whole = bob.api('POST', f'/archives/{source}/extract', {'destinationId': destination, 'folderName': 'Whole'})
        report.check('full extraction queued', status == 202)
        execute_job(docker, int(whole['id']))
        status, whole = bob.api('GET', f'/jobs/{whole["id"]}')
        report.check('full extraction succeeds', status == 200 and whole['status'] == 'succeeded' and whole['filesDone'] == len(files))
        for name, expected in files.items():
            status, content = bob.dav('GET', 'Whole/' + name)
            report.check('full extraction bytes: ' + name, status == 200 and content == expected)
        report.check('original ZIP preserved after extraction', alice.dav('GET', 'Source/source.zip') == (200, original))
        # A structurally valid ZIP with a deliberately wrong payload checksum.
        corrupt = bytearray(zip_bytes({'broken.txt': b'checksum payload'}, zipfile.ZIP_STORED))
        offset = corrupt.find(b'checksum payload')
        corrupt[offset] ^= 1
        alice.dav('PUT', 'Source/corrupt.zip', data=bytes(corrupt))
        corrupt_source = alice.file_id('Source/corrupt.zip')
        status, broken = bob.api('POST', f'/archives/{corrupt_source}/extract', {'destinationId': destination, 'folderName': 'Broken'})
        report.check('corrupt payload is handled as a task or preflight error', status in (202, 422))
        if status == 202:
            execute_job(docker, int(broken['id']))
            _, broken = bob.api('GET', f'/jobs/{broken["id"]}')
            report.check('corrupt payload never reports success', broken['status'] == 'failed')
        report.check('failed extraction leaves no partial folder', bob.dav('PROPFIND', 'Broken')[0] == 404)
        status, stale = bob.api('POST', f'/archives/{source}/extract', {'destinationId': destination, 'folderName': 'Stale'})
        report.check('source-change fixture queued', status == 202)
        alice.dav('PUT', 'Source/source.zip', data=zip_bytes({'new.txt': b'replacement'}))
        execute_job(docker, int(stale['id']))
        _, stale = bob.api('GET', f'/jobs/{stale["id"]}')
        report.check('source replacement fails safely', stale['status'] == 'failed')
        report.check('source replacement creates no partial folder', bob.dav('PROPFIND', 'Stale')[0] == 404)
        completed = True
        report.write('passed')
    except Exception as error:
        report.write('failed', error)
        raise
    finally:
        if completed:
            for user in reversed(users):
                docker.delete_user(user)


if __name__ == '__main__':
    if hasattr(sys.stdout, 'reconfigure'):
        sys.stdout.reconfigure(encoding='utf-8')
    run()
