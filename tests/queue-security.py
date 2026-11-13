#!/usr/bin/env python3
"""Validate encrypted credentials, native notifications and cancellation locally."""
from __future__ import annotations

import base64
import hashlib
import json
from pathlib import Path
import runpy
import subprocess
import sys
import time
import uuid
import zipfile

ROOT = Path(__file__).resolve().parents[1]
f = runpy.run_path(str(ROOT / 'tests' / 'features.py'))
h = f['helpers']


class QueueReport(h['Report']):
    def write(self, status, error=None):
        self.data['status'] = status
        self.data['elapsedSeconds'] = round(time.monotonic() - self.started, 2)
        if error is not None:
            self.data['error'] = self.redact(str(error))
        out = h['report_directory']()
        out.mkdir(exist_ok=True)
        serialized = self.redact(json.dumps(self.data, ensure_ascii=False, indent=2))
        (out / (self.data['runId'] + '.json')).write_text(serialized + '\n', encoding='utf-8')
        (out / 'latest-queue-security.json').write_text(serialized + '\n', encoding='utf-8')
        print(json.dumps({'status': status, 'checks': len(self.data['checks']), 'error': self.data.get('error')}, ensure_ascii=True))


def run():
    run = time.strftime('%Y%m%d%H%M%S', time.gmtime()) + '_' + uuid.uuid4().hex[:6]
    report = QueueReport('queue-security-' + run)
    admin_secret = h['read_environment']()['ARCHIVE_TOOLS_ADMIN_PASSWORD']
    report.secrets.append(admin_secret)
    docker = h['Docker'](report)
    docker.assert_test_stack()
    users, clients = [], {}
    complete = False
    worker = None
    active_job = None
    failure = None
    try:
        for role in ('alice', 'bob'):
            user = 'at_' + run + '_' + role
            docker.add_user(user)
            users.append(user)
            client = h['Client'](h['test_base_url'](), user, admin_secret, report)
            client.csrf()
            clients[role] = client
        report.data['fixtureUsers'] = {role: client.user for role, client in clients.items()}
        alice, bob = clients['alice'], clients['bob']
        archive_secret = hashlib.sha256((admin_secret + '::' + bob.user).encode()).hexdigest()
        report.secrets.append(archive_secret)

        def helper(action, *args):
            return json.loads(docker.run(['exec', '-T', '--user', 'www-data', 'nextcloud',
                                          'php', '/opt/archive-tools-tests/queue-security-helper.php', action, bob.user, *map(str, args)]))

        fixture = base64.b64decode(helper('fixture')['zipBase64'])
        report.check('encrypted source folder created', alice.dav('MKCOL', 'Protected')[0] == 201)
        report.check('AES source uploaded', alice.dav('PUT', 'Protected/protected.zip', data=fixture)[0] == 201)
        source = alice.file_id('Protected/protected.zip')
        alice.ocs('POST', payload={'path': '/Protected', 'shareType': 0, 'shareWith': bob.user, 'permissions': 1})
        destination = bob.file_id('')
        status, info = bob.api('POST', f'/archives/{source}/inspect', {})
        report.check('protected metadata is readable without disclosing its password', status == 200 and info['encrypted'] is True and 'password' not in info)
        report.check('wrong inspect password is rejected', bob.api('POST', f'/archives/{source}/inspect', {'password': 'incorrect'})[0] == 422)
        status, info = bob.api('POST', f'/archives/{source}/inspect', {'password': archive_secret})
        report.check('correct inspect password succeeds', status == 200 and info['encrypted'] is True)
        status, listing = bob.api('POST', f'/archives/{source}/browse-zip', {})
        report.check('protected ZIP folder listing excludes credentials', status == 200 and listing['encrypted'] is True
                     and 'password' not in listing and {item['path'] for item in listing['entries']} == {'private', 'public.txt'})
        status, body = bob.api('POST', f'/archives/{source}/browse-zip', {'path': 'private', 'password': 'incorrect'})
        report.check('ZIP folder listing reports a wrong password', status == 422 and body['code'] == 'password_required')
        status, listing = bob.api('POST', f'/archives/{source}/browse-zip', {'path': 'private', 'password': archive_secret})
        report.check('correct ZIP password lists selected folder', status == 200 and listing['folder']['path'] == 'private'
                     and 'private/secret.bin' in {item['path'] for item in listing['entries']})

        def download(password):
            return bob.request('POST', h['API'] + f'/archives/{source}/download',
                               data=json.dumps({'member': 'private/secret.bin', 'password': password}).encode(),
                               headers={'Content-Type': 'application/json', 'requesttoken': bob.token})

        report.check('protected download rejects wrong password', download('incorrect')[0] == 422)
        status, body = download(archive_secret)
        report.check('protected download returns exact bytes', status == 200 and body == b'authenticated content\0\xff')
        payload = {'destinationId': destination, 'folderName': 'Protected output'}
        report.check('encrypted extraction requires a password', bob.api('POST', f'/archives/{source}/extract', payload)[0] == 422)
        report.check('encrypted extraction rejects wrong password', bob.api('POST', f'/archives/{source}/extract', dict(payload, password='incorrect'))[0] == 422)
        status, job = bob.api('POST', f'/archives/{source}/extract', dict(payload, password=archive_secret))
        report.check('encrypted task is queued', status == 202 and job['status'] == 'queued')
        job_id = int(job['id'])
        report.check('job DTO excludes plaintext and secret fields', archive_secret not in json.dumps(job) and 'secret' not in job and 'password' not in job)
        credentials = helper('credentials', job_id)
        report.check('queued credential is encrypted with the server crypto', credentials['encryptedSecretMatches'] and credentials['plaintextAbsentFromJob'])
        report.check('core queue arguments contain only the job id', credentials['coreArgumentsContainOnlyJobId'] and credentials['plaintextAbsentFromCoreArguments'])
        reconciliation = helper('reconcile-queue', job_id)
        report.check('queue reconciliation preserves the existing entry age and reservation', reconciliation['existingQueueMetadataPreserved'])
        report.check('queue reconciliation restores a missing native entry', reconciliation['missingNativeJobRestored'])
        f['execute_job'](docker, job_id)
        status, result = bob.api('GET', f'/jobs/{job_id}')
        report.check('encrypted extraction completes successfully', status == 200 and result['status'] == 'succeeded' and result['filesDone'] == 2)
        report.check('encrypted extracted content matches original bytes', bob.dav('GET', 'Protected output/private/secret.bin') == (200, b'authenticated content\0\xff'))
        report.check('finished job erases its stored credential', helper('credentials', job_id)['secretCleared'])
        notification = helper('notification', job_id)
        report.check('native completion notification is persisted', notification['persisted'] and notification['subject'] == 'extraction.succeeded')
        report.check('native notification prepares a Russian subject', isinstance(notification['localizedSubject'], str) and 'Архив' in notification['localizedSubject'])
        report.check('archive password is absent from the server log', helper('logs')['plaintextAbsentFromLog'])

        # Source and destination can share a parent; use Cyrillic DAV paths too.
        report.check('own Unicode source folder created', bob.dav('MKCOL', 'Личные архивы')[0] == 201)
        report.check('own Unicode source uploaded', bob.dav('PUT', 'Личные архивы/Защищённый.zip', data=fixture)[0] == 201)
        own_source = bob.file_id('Личные архивы/Защищённый.zip')
        status, own_job = bob.api('POST', f'/archives/{own_source}/extract', {
            'destinationId': bob.file_id('Личные архивы'), 'folderName': 'Результат проверки', 'password': archive_secret})
        report.check('same-parent Unicode extraction is queued', status == 202)
        f['execute_job'](docker, int(own_job['id']))
        _, own_result = bob.api('GET', f'/jobs/{own_job["id"]}')
        report.check('same-parent Unicode extraction succeeds', own_result['status'] == 'succeeded')
        report.check('same-parent extracted bytes match', bob.dav('GET', 'Личные архивы/Результат проверки/private/secret.bin') == (200, b'authenticated content\0\xff'))

        report.check('writable shared destination created', alice.dav('MKCOL', 'Writable destination')[0] == 201)
        alice.ocs('POST', payload={'path': '/Writable destination', 'shareType': 0, 'shareWith': bob.user, 'permissions': 31})
        status, shared_job = bob.api('POST', f'/archives/{source}/extract', {
            'destinationId': bob.file_id('Writable destination'), 'folderName': 'Shared output', 'password': archive_secret})
        report.check('writable shared destination accepts extraction', status == 202)
        f['execute_job'](docker, int(shared_job['id']))
        _, shared_result = bob.api('GET', f'/jobs/{shared_job["id"]}')
        report.check('shared destination extraction succeeds', shared_result['status'] == 'succeeded')
        for actor in (alice, bob):
            report.check('shared extracted bytes available to ' + ('owner' if actor is alice else 'recipient'),
                         actor.dav('GET', 'Writable destination/Shared output/private/secret.bin') == (200, b'authenticated content\0\xff'))

        corrupt = bytearray(f['zip_bytes']({'broken.txt': b'checksum payload'}, zipfile.ZIP_STORED))
        corrupt[corrupt.find(b'checksum payload')] ^= 1
        bob.dav('PUT', 'Личные архивы/Повреждённый.zip', data=bytes(corrupt))
        status, broken = bob.api('POST', f'/archives/{bob.file_id("Личные архивы/Повреждённый.zip")}/extract', {
            'destinationId': bob.file_id('Личные архивы'), 'folderName': 'Незавершённый'})
        report.check('nested corruption fixture queued', status == 202)
        f['execute_job'](docker, int(broken['id']))
        _, broken = bob.api('GET', f'/jobs/{broken["id"]}')
        report.check('nested corrupted extraction fails', broken['status'] == 'failed')
        report.check('nested corrupted output is removed completely', bob.dav('PROPFIND', 'Личные архивы/Незавершённый')[0] == 404)

        # A stopped worker must not destroy a folder edited after interruption.
        status, interrupted = bob.api('POST', f'/archives/{source}/extract', {
            'destinationId': destination, 'folderName': 'Interrupted', 'password': archive_secret})
        report.check('recovery fixture queued', status == 202)
        report.check('partial review folder created', bob.dav('MKCOL', 'Interrupted')[0] == 201)
        bob.dav('PUT', 'Interrupted/keep.txt', data=b'keep user review data')
        target_id = bob.file_id('Interrupted')
        recovered = helper('mark-interrupted', int(interrupted['id']), target_id)
        report.check('stale running task becomes an explicit failure', recovered['job']['status'] == 'failed')
        report.check('recovery clears credentials and preserves output identity', recovered['secretCleared'] and recovered['job']['targetId'] == target_id)
        report.check('recovery preserves files in the review folder', bob.dav('GET', 'Interrupted/keep.txt') == (200, b'keep user review data'))
        f['execute_job'](docker, int(interrupted['id']), allow_missing=True)

        # Native worker runs concurrently; cancellation is an actual HTTP request.
        long_zip = base64.b64decode(helper('long-fixture')['zipBase64'])
        alice.dav('PUT', 'Protected/long.zip', data=long_zip)
        long_source = alice.file_id('Protected/long.zip')
        for label, parent, target_id in (
                ('nested destination', 'Личные архивы/', bob.file_id('Личные архивы')),
                ('root destination', '', destination)):
            status, active = bob.api('POST', f'/archives/{long_source}/extract', {'destinationId': target_id, 'folderName': 'Running cancellation'})
            report.check(label + ': running-cancellation fixture queued', status == 202)
            active_job = int(active['id'])
            native_id = docker.run(['exec', '-T', '--user', 'www-data', 'nextcloud', 'php', '/opt/archive-tools-tests/job-driver.php', str(active_job)])
            command = docker.prefix + ['exec', '-T', '--user', 'www-data', 'nextcloud', 'php', 'occ', 'background-job:execute', '--force-execute', native_id]
            worker = subprocess.Popen(command, cwd=ROOT, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            deadline = time.monotonic() + 40
            running = None
            while time.monotonic() < deadline:
                _, running = bob.api('GET', f'/jobs/{active_job}')
                if running['status'] == 'running' and running['filesDone'] > 0:
                    break
                if running['status'] not in ('queued', 'running'):
                    break
                time.sleep(0.05)
            report.check(label + ': native worker reached an actual running state', running is not None and running['status'] == 'running' and running['filesDone'] > 0)
            report.check(label + ': concurrent DAV cannot edit partial output', bob.dav('PUT', parent + 'Running cancellation/foreign.txt', data=b'foreign edit')[0] == 423)
            status, cancelled = bob.api('DELETE', f'/jobs/{active_job}')
            report.check(label + ': HTTP cancellation requests cooperative stop', status == 200 and cancelled['cancelRequested'])
            worker.communicate(timeout=60)
            report.check(label + ': cancelled native worker exits normally', worker.returncode == 0)
            worker = None
            _, cancelled = bob.api('GET', f'/jobs/{active_job}')
            report.check(label + ': running extraction reaches cancelled state', cancelled['status'] == 'cancelled')
            report.check(label + ': running cancellation removes the whole partial output', bob.dav('PROPFIND', parent + 'Running cancellation')[0] == 404)
            active_job = None
        complete = True
    except Exception as error:
        failure = error
    finally:
        if active_job is not None and 'bob' in clients:
            try: clients['bob'].api('DELETE', f'/jobs/{active_job}')
            except Exception: pass
        if worker is not None:
            try: worker.communicate(timeout=15)
            except subprocess.TimeoutExpired: pass
        if complete:
            for user in reversed(users): docker.delete_user(user)
        report.data['fixturesKept'] = not complete
    report.write('passed' if complete else 'failed', failure)
    return 0 if complete else 1


if __name__ == '__main__':
    if hasattr(sys.stdout, 'reconfigure'): sys.stdout.reconfigure(encoding='utf-8')
    sys.exit(run())
