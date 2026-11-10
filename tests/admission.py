#!/usr/bin/env python3
"""Exercise admission and explicit recovery on generated accounts only."""
from __future__ import annotations

import json
from pathlib import Path
import runpy
import sys
import time
import uuid

ROOT = Path(__file__).resolve().parents[1]
features = runpy.run_path(str(ROOT / 'tests' / 'features.py'))
h = features['helpers']


class AdmissionReport(h['Report']):
    def write(self, status, error=None):
        self.data['status'] = status
        self.data['elapsedSeconds'] = round(time.monotonic() - self.started, 2)
        if error is not None:
            self.data['error'] = self.redact(str(error))
        directory = h['report_directory']()
        directory.mkdir(exist_ok=True)
        serialized = self.redact(json.dumps(self.data, ensure_ascii=False, indent=2)) + '\n'
        (directory / (self.data['runId'] + '.json')).write_text(serialized, encoding='utf-8')
        (directory / 'latest-admission.json').write_text(serialized, encoding='utf-8')
        print(json.dumps({'status': status, 'checks': len(self.data['checks']), 'error': self.data.get('error')}, ensure_ascii=True))


def run():
    token = uuid.uuid4().hex[:24]
    report = AdmissionReport('admission-' + token)
    password = h['read_environment']()['ARCHIVE_TOOLS_ADMIN_PASSWORD']
    report.secrets.append(password)
    docker = h['Docker'](report)
    docker.assert_test_stack()
    users, clients, mounts, jobs = [], {}, [], []
    failure = None
    completed = False

    def history(owner, execute=False, ids=()):
        arguments = ['exec', '-T', '--user', 'www-data', 'nextcloud', 'php', 'occ',
                     'archive_tools:clear-history', '--owner', owner, '--output', 'json']
        if execute:
            arguments.append('--execute')
        for identifier in ids:
            arguments += ['--id', str(identifier)]
        return json.loads(docker.run(arguments))

    try:
        for role in ('alice', 'bob'):
            owner = 'at_admission_' + token + '_' + role
            docker.add_user(owner)
            users.append(owner)
            client = h['Client'](h['test_base_url'](), owner, password, report)
            client.csrf()
            clients[role] = client
        alice, bob = clients['alice'], clients['bob']
        contents = b'Owned admission source and output must remain unchanged.\n'
        archive = features['zip_bytes']({'one.txt': contents})
        for client in (alice, bob):
            report.check('owned admission archive uploaded', client.dav('PUT', 'admission.zip', data=archive)[0] == 201)
        source = alice.file_id('admission.zip')
        other_source = bob.file_id('admission.zip')
        destination = alice.file_id('')
        code, inspected = alice.api('POST', f'/archives/{source}/inspect', {})
        report.check('owned archive inspected before admission tests', code == 200)
        etag = inspected['sourceEtag']

        for label, secret in [('ASCII', 'p' * 4096), ('UTF-8', '🙂' * 1024)]:
            report.secrets.append(secret)
            operations = [
                (f'/archives/{source}/inspect', {'password': secret}),
                (f'/archives/{source}/download', {'member': 'one.txt', 'password': secret, 'expectedEtag': etag}),
                (f'/archives/{source}/extract', {'folderName': 'Forbidden password output', 'password': secret, 'expectedEtag': etag}),
                ('/mounts', {'sourceId': source, 'name': 'Forbidden password connection', 'password': secret, 'expectedEtag': etag}),
            ]
            for path, payload in operations:
                code, response = alice.api('POST', path, payload)
                report.check(label + ': oversized password rejected before archive work', code == 422
                             and response.get('message') == 'The archive password exceeds the supported byte length.')
        code, _ = alice.api('POST', f'/archives/{source}/inspect', {'password': '🙂' * 1023 + 'abc'})
        report.check('exact 4095 byte password remains usable through HTTP', code == 200)
        report.check('password failures create no job or connection rows', alice.api('GET', '/jobs') == (200, []) and alice.api('GET', '/mounts') == (200, []))
        report.check('password failures preserve source and create no output', alice.dav('GET', 'admission.zip') == (200, archive)
                     and alice.dav('PROPFIND', 'Forbidden password output')[0] == 404)

        for index in range(16):
            code, mount = alice.api('POST', '/mounts', {'sourceId': source, 'name': 'Admission connection ' + str(index), 'expectedEtag': etag})
            report.check('owner connection allowance accepts its available slot', code == 201)
            mounts.append(int(mount['id']))
        code, limited = alice.api('POST', '/mounts', {'sourceId': source, 'name': 'Over connection ceiling', 'expectedEtag': etag})
        report.check('seventeenth owner connection is rejected with a safe quota error', code == 429 and limited.get('code') == 'resource_limit'
                     and 'Your archive connection limit' in limited.get('message', ''))
        code, listing = alice.api('GET', '/mounts')
        report.check('connection rejection creates no additional persisted row', code == 200 and len(listing) == 16)
        report.check('same source can still have different connection names', len({row['name'] for row in listing}) == 16)
        report.check('disconnect frees a connection slot', alice.api('DELETE', '/mounts/' + str(mounts.pop()))[0] == 200)
        code, replacement = alice.api('POST', '/mounts', {'sourceId': source, 'name': 'Replacement connection', 'expectedEtag': etag})
        report.check('new connection succeeds after explicit disconnect', code == 201)
        mounts.append(int(replacement['id']))

        code, first = alice.api('POST', f'/archives/{source}/extract', {'destinationId': destination, 'folderName': 'Preserved output', 'expectedEtag': etag})
        report.check('owned preservation extraction queued', code == 202)
        first_id = int(first['id'])
        jobs.append(first_id)
        features['execute_job'](docker, first_id)
        code, finished = alice.api('GET', f'/jobs/{first_id}')
        report.check('owned preservation extraction completed', code == 200 and finished['status'] == 'succeeded')
        report.check('preservation output contains the original bytes', alice.dav('GET', 'Preserved output/one.txt') == (200, contents))

        payloads = []
        for index in range(4):
            payload = {'destinationId': destination, 'folderName': 'Queued admission ' + str(index), 'expectedEtag': etag}
            code, queued = alice.api('POST', f'/archives/{source}/extract', payload)
            report.check('active task allowance accepts its available slot', code == 202 and queued['status'] == 'queued')
            jobs.append(int(queued['id']))
            payloads.append(payload)
        code, duplicate = alice.api('POST', f'/archives/{source}/extract', payloads[0])
        report.check('idempotent active retry still succeeds at the full admission ceiling', code == 202 and int(duplicate['id']) == jobs[1])
        code, limited = alice.api('POST', f'/archives/{source}/extract', {'folderName': 'Over active ceiling', 'expectedEtag': etag})
        report.check('fifth active owner task is rejected before queue insertion', code == 429 and limited.get('code') == 'resource_limit'
                     and 'Your active archive task limit' in limited.get('message', ''))
        code, listing = alice.api('GET', '/jobs')
        report.check('task rejection creates no additional persisted row', code == 200 and len(listing) == 5)

        code, foreign = bob.api('POST', f'/archives/{other_source}/extract', {'folderName': 'Other owner queued'})
        report.check('another owner retains their active allowance', code == 202)
        foreign_id = int(foreign['id'])
        jobs.append(foreign_id)
        for identifier in jobs[1:-1]:
            code, state = alice.api('DELETE', '/jobs/' + str(identifier))
            report.check('owned queued task cancellation preserves retry metadata', code == 200 and state['status'] == 'cancelled' and state['members'] == ['one.txt'])
        before = alice.api('GET', '/jobs')
        preview = history(alice.user)
        report.check('explicit history preview reports only owned terminal records', preview['selectedRows'] == 5
                     and preview['deletedRows'] == 0 and preview['rowsWithOutput'] == 1 and preview['metadataBytes'] > 0)
        report.check('history preview leaves API records and members unchanged', alice.api('GET', '/jobs') == before
                     and alice.api('GET', f'/jobs/{first_id}')[1]['members'] == ['one.txt'])
        removed = history(alice.user, True, [first_id, foreign_id])
        report.check('explicit selected history cleanup removes only the requested owner terminal row', removed['deletedRows'] == 1
                     and alice.api('GET', f'/jobs/{first_id}')[0] == 404 and bob.api('GET', f'/jobs/{foreign_id}')[0] == 200)
        report.check('selected history cleanup preserves source and completed output bytes', alice.dav('GET', 'admission.zip') == (200, archive)
                     and alice.dav('GET', 'Preserved output/one.txt') == (200, contents))
        removed = history(alice.user, True)
        report.check('explicit remaining terminal cleanup frees retained metadata', removed['deletedRows'] == 4 and alice.api('GET', '/jobs') == (200, []))
        report.check('history cleanup preserves another owners source and active job', bob.dav('GET', 'admission.zip') == (200, archive)
                     and bob.api('GET', f'/jobs/{foreign_id}')[1]['status'] == 'queued')
        report.check('history cleanup leaves real outputs and connection metadata intact', alice.dav('GET', 'Preserved output/one.txt') == (200, contents)
                     and len(alice.api('GET', '/mounts')[1]) == 16)
        completed = True
    except Exception as error:
        failure = error
    finally:
        try:
            for client in clients.values():
                code, rows = client.api('GET', '/jobs')
                if code == 200:
                    for job in rows:
                        if job['status'] in ('queued', 'running'):
                            client.api('DELETE', '/jobs/' + str(job['id']))
                history(client.user, True)
            if 'alice' in clients:
                for identifier in reversed(mounts):
                    code, _ = clients['alice'].api('DELETE', '/mounts/' + str(identifier))
                    if code not in (200, 404):
                        raise RuntimeError('An owned admission connection could not be removed.')
            for identifier in jobs:
                features['execute_job'](docker, identifier, allow_missing=True)
            for owner in reversed(users):
                docker.delete_user(owner)
        except Exception as error:
            completed = False
            failure = failure or error
    report.data['fixturesKept'] = not completed
    report.write('passed' if completed else 'failed', failure)
    return 0 if completed else 1


if __name__ == '__main__':
    if hasattr(sys.stdout, 'reconfigure'):
        sys.stdout.reconfigure(encoding='utf-8')
    sys.exit(run())
