#!/usr/bin/env python3
"""Real concurrent HTTP/native jobs and SIGKILL recovery on the local stack."""
from __future__ import annotations

import base64
from concurrent.futures import ThreadPoolExecutor
import hashlib
import json
from pathlib import Path
import runpy
import subprocess
import sys
import threading
import time
import uuid

ROOT = Path(__file__).resolve().parents[1]
features = runpy.run_path(str(ROOT / 'tests' / 'features.py'))
h = features['helpers']


class ConcurrencyReport(h['Report']):
    def write(self, status, error=None):
        self.data['status'] = status
        self.data['elapsedSeconds'] = round(time.monotonic() - self.started, 2)
        if error is not None:
            self.data['error'] = self.redact(str(error))
        directory = h['report_directory']()
        directory.mkdir(exist_ok=True)
        serialized = self.redact(json.dumps(self.data, ensure_ascii=False, indent=2))
        (directory / (self.data['runId'] + '.json')).write_text(serialized + '\n', encoding='utf-8')
        (directory / 'latest-concurrency.json').write_text(serialized + '\n', encoding='utf-8')
        print(json.dumps({'status': status, 'checks': len(self.data['checks']), 'error': self.data.get('error')}, ensure_ascii=True))


def run():
    run_id = time.strftime('%Y%m%d%H%M%S', time.gmtime()) + '_' + uuid.uuid4().hex[:6]
    report = ConcurrencyReport('concurrency-' + run_id)
    secret = h['read_environment']()['ARCHIVE_TOOLS_ADMIN_PASSWORD']
    report.secrets.append(secret)
    docker = h['Docker'](report)
    docker.assert_test_stack()
    users, clients, workers, job_ids = [], {}, [], []
    complete = False
    failure = None
    bob = None
    try:
        for role in ('alice', 'bob'):
            user = 'at_' + run_id + '_' + role
            docker.add_user(user)
            users.append(user)
            client = h['Client'](h['test_base_url'](), user, secret, report)
            client.csrf()
            if client.api('GET', '/jobs')[0] != 200:
                raise h['TestFailure']('Cannot initialize the owned browser session.')
            client.auth = ''
            clients[role] = client
        alice, bob = clients['alice'], clients['bob']
        report.data['fixtureUsers'] = {role: client.user for role, client in clients.items()}
        report.data['jobIds'] = job_ids
        report.data['workerReadiness'] = []
        report.data['clockSimulation'] = 'Only dead fixture worker file-lock keys expire early; heartbeat ages after actual SIGKILL.'
        report.data['authentication'] = 'Cookie sessions initialized sequentially. Concurrent Basic-auth login-token invalidation caused a core SQLite database-locked error before archive business logic in the initial probe.'
        archive_password = hashlib.sha256((secret + '::' + bob.user).encode()).hexdigest()
        report.secrets.append(archive_password)

        def helper(action, *args, timeout=60):
            return json.loads(docker.run(['exec', '-T', '--interactive=false', '--user', 'www-data', 'nextcloud',
                                         'php', '/opt/archive-tools-tests/concurrency-helper.php', action,
                                         bob.user, *map(str, args)], timeout=timeout))

        fixture = helper('fixture')
        source_bytes = base64.b64decode(fixture['zipBase64'])
        report.check('single member fixture stays below the 128 MiB limit', fixture['bytes'] == 32 * 1024 * 1024)
        report.check('own source folder created', alice.dav('MKCOL', 'Source')[0] == 201)
        report.check('bounded encrypted source uploaded', alice.dav('PUT', 'Source/concurrency.zip', data=source_bytes)[0] == 201)
        source_id = alice.file_id('Source/concurrency.zip')
        alice.ocs('POST', payload={'path': '/Source', 'shareType': 0, 'shareWith': bob.user, 'permissions': 1})
        report.check('own destination tree created', bob.dav('MKCOL', 'Concurrency')[0] == 201)
        destinations = {}
        for name in ('Dedup', 'Parallel A', 'Parallel B', 'Cancel', 'Crash'):
            if bob.dav('MKCOL', 'Concurrency/' + name)[0] != 201:
                raise h['TestFailure']('Cannot create an owned concurrency destination.')
            destinations[name] = bob.file_id('Concurrency/' + name)
        status, index = bob.api('POST', f'/archives/{source_id}/inspect', {'password': archive_password})
        report.check('one-file protected source is inspected', status == 200 and index['fileCount'] == 1)

        def payload(destination):
            return {'destinationId': destinations[destination], 'folderName': 'Output',
                    'password': archive_password, 'expectedEtag': index['sourceEtag']}

        def schedule(destination):
            status, job = bob.api('POST', f'/archives/{source_id}/extract', payload(destination))
            report.check(destination + ': native task queued', status == 202 and job['status'] == 'queued')
            job_ids.append(int(job['id']))
            return int(job['id'])

        def launch(job_id):
            nonce = uuid.uuid4().hex
            command = docker.prefix + ['exec', '-T', '--interactive=false', '--user', 'www-data', 'nextcloud', 'php',
                                       '/opt/archive-tools-tests/concurrency-helper.php', 'run', bob.user, str(job_id), nonce]
            process = subprocess.Popen(command, cwd=ROOT, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                       stdin=subprocess.DEVNULL, text=True, encoding='utf-8', errors='replace')
            worker = {'process': process, 'pid': None, 'jobId': job_id, 'nonce': nonce}
            workers.append(worker)
            deadline = time.monotonic() + 25
            last = None
            while time.monotonic() < deadline:
                last = helper('worker-status', job_id, nonce)
                marker = last['marker']
                if marker is not None:
                    worker['pid'] = marker['pid']
                    if marker['phase'] == 'executing' and last['pidMatches'] and last['alive']:
                        status, durable = bob.api('GET', f'/jobs/{job_id}')
                        if status == 200 and durable['status'] == 'running':
                            report.data['workerReadiness'].append({'jobId': job_id, 'pid': marker['pid'],
                                'phase': marker['phase'], 'pidNonceVerified': True, 'durableStatus': durable['status']})
                            return worker
                    if marker['phase'] in ('failed', 'stopped', 'finished'):
                        stdout, stderr = process.communicate(timeout=10)
                        raise h['TestFailure']('The owned native worker stopped before readiness: '
                                              + report.redact(json.dumps(last) + (stderr + stdout)[-1000:]))
                if process.poll() is not None:
                    stdout, stderr = process.communicate(timeout=10)
                    raise h['TestFailure']('The native worker exited before claiming the job: '
                                          + report.redact(json.dumps(last) + (stderr + stdout)[-1000:]))
                time.sleep(0.04)
            raise h['TestFailure']('Native worker readiness timed out: ' + report.redact(json.dumps(last)))

        def wait_for(predicate, description, timeout=25):
            deadline = time.monotonic() + timeout
            latest = None
            while time.monotonic() < deadline:
                latest = predicate()
                if latest:
                    return latest
                time.sleep(0.025)
            raise h['TestFailure'](description)

        def mid_file(job_id):
            _, job = bob.api('GET', f'/jobs/{job_id}')
            return job if (job['status'] == 'running' and job['filesDone'] == 0
                           and 0 < job['bytesDone'] < job['bytesTotal']) else None

        def finish(worker, description):
            stdout, stderr = worker['process'].communicate(timeout=45)
            report.check(description, worker['process'].returncode == 0, report.redact((stderr + stdout)[-1500:]))

        # Separate sessions and an explicit barrier send overlapping requests.
        request_clients = []
        for _ in range(2):
            client = h['Client'](h['test_base_url'](), bob.user, secret, report)
            client.csrf()
            if client.api('GET', '/jobs')[0] != 200:
                raise h['TestFailure']('Cannot initialize an independent request session.')
            client.auth = ''
            request_clients.append(client)
        barrier = threading.Barrier(3)

        def simultaneous(client):
            barrier.wait(timeout=10)
            started = time.monotonic()
            response = client.api('POST', f'/archives/{source_id}/extract', payload('Dedup'))
            return started, time.monotonic(), response

        with ThreadPoolExecutor(max_workers=2) as executor:
            pending = [executor.submit(simultaneous, client) for client in request_clients]
            barrier.wait(timeout=10)
            results = [future.result(timeout=70) for future in pending]
        report.check('duplicate HTTP requests actually overlap', results[0][0] < results[1][1] and results[1][0] < results[0][1])
        report.check('both simultaneous duplicate requests are accepted', all(result[2][0] == 202 for result in results))
        dedup_ids = {int(result[2][1]['id']) for result in results}
        report.check('simultaneous duplicate requests return one durable job', len(dedup_ids) == 1)
        dedup_id = dedup_ids.pop()
        job_ids.append(dedup_id)
        report.check('simultaneous duplicates publish one native queue record', helper('state', dedup_id)['nativeQueueCount'] == 1)
        status, cancelled = bob.api('DELETE', f'/jobs/{dedup_id}')
        report.check('deduplicated queued task can be cancelled', status == 200 and cancelled['status'] == 'cancelled')
        features['execute_job'](docker, dedup_id, allow_missing=True)
        report.check('cancelled dedup task creates no output', bob.dav('PROPFIND', 'Concurrency/Dedup/Output')[0] == 404)

        # These jobs share an encrypted source and write different parents.
        parallel = [(name, schedule(name)) for name in ('Parallel A', 'Parallel B')]
        parallel_workers = [launch(job_id) for _, job_id in parallel]

        def both_mid_file():
            states = [mid_file(job_id) for _, job_id in parallel]
            return states if all(states) else None

        wait_for(both_mid_file, 'Two native workers did not run concurrently.')
        report.check('two independent native workers overlap inside their single files', True)
        for worker in parallel_workers:
            finish(worker, 'independent native worker exits normally')
        for name, job_id in parallel:
            _, job = bob.api('GET', f'/jobs/{job_id}')
            report.check(name + ': concurrent extraction succeeds with exact progress', job['status'] == 'succeeded'
                         and job['filesDone'] == 1 and job['bytesDone'] == fixture['bytes'])
            status, data = bob.dav('GET', 'Concurrency/' + name + '/Output/large.bin')
            report.check(name + ': concurrent output has the original digest', status == 200
                         and len(data) == fixture['bytes'] and hashlib.sha256(data).hexdigest() == fixture['sha256'])
            del data
            report.check(name + ': completed output fixture removed', bob.dav('DELETE', 'Concurrency/' + name + '/Output')[0] == 204)

        cancel_id = schedule('Cancel')
        cancel_worker = launch(cancel_id)
        observed = wait_for(lambda: mid_file(cancel_id), 'The single-file worker did not expose progress.')
        report.check('cancellation observes bytes before the only file completes', observed['filesDone'] == 0 and observed['bytesDone'] > 0)
        status, cancellation = bob.api('DELETE', f'/jobs/{cancel_id}')
        report.check('HTTP cancellation is accepted during the large file', status == 200 and cancellation['cancelRequested'])
        finish(cancel_worker, 'single-file cancellation worker exits normally')
        state = helper('state', cancel_id)
        report.check('single-file cancellation reaches a terminal cancelled state', state['job']['status'] == 'cancelled')
        report.check('single-file cancellation clears its encrypted credential', state['secretCleared'])
        report.check('single-file cancellation removes partial output', bob.dav('PROPFIND', 'Concurrency/Cancel/Output')[0] == 404)

        crash_id = schedule('Crash')
        crash_worker = launch(crash_id)
        wait_for(lambda: mid_file(crash_id), 'The worker to be killed did not start its only file.')
        before_crash = helper('state', crash_id)
        report.check('running worker retains only encrypted credentials', before_crash['secretEncrypted'])
        report.check('native queued job is removed before execution', before_crash['nativeQueueCount'] == 0)
        report.check('verified owned PHP worker receives real SIGKILL', helper('kill', crash_id, crash_worker['pid'], crash_worker['nonce'])['killed'])
        crash_worker['process'].communicate(timeout=20)
        report.check('SIGKILL terminates the actual native worker process', crash_worker['process'].returncode != 0)
        after_crash = helper('state', crash_id)
        target_id = after_crash['job']['targetId']
        report.check('abrupt termination leaves durable running state and output identity', after_crash['job']['status'] == 'running' and target_id is not None)
        lock_expiry = helper('expire-own-locks', crash_id, crash_worker['pid'], crash_worker['nonce'])
        report.data['crashLockExpiry'] = lock_expiry
        report.check('only dead fixture file locks are expired', lock_expiry['expiredOwnLocks'] > 0
                     and lock_expiry.get('deadWorkerVerified') is True
                     and lock_expiry['lockBackend'] in ('database', 'redis')
                     and (lock_expiry['lockBackend'] != 'redis' or lock_expiry.get('unrelatedCacheKeyPreserved') is True))
        status, partial = bob.dav('GET', 'Concurrency/Crash/Output/large.bin')
        report.check('actual partially written single file survives the process crash', status == 200 and 0 < len(partial) < fixture['bytes'])
        partial_digest = hashlib.sha256(partial).hexdigest()
        partial_size = len(partial)
        del partial
        note = b'user edits after the crashed worker\n'
        report.check('user can add a review note after the dead locks expire', bob.dav('PUT', 'Concurrency/Crash/Output/review.txt', data=note)[0] == 201)
        report.check('only the dead worker heartbeat is aged for recovery', helper('age-crashed', crash_id, crash_worker['pid'], crash_worker['nonce'])['aged'])
        status, recovered = bob.api('GET', f'/jobs/{crash_id}')
        report.check('HTTP recovery marks the crashed task as failed', status == 200 and recovered['status'] == 'failed')
        report.check('recovery keeps the partial output identity for review', recovered['targetId'] == target_id)
        state = helper('state', crash_id)
        report.check('crash recovery clears credentials and dedup key', state['secretCleared'])
        report.check('crash recovery does not resurrect the removed native queue record', state['nativeQueueCount'] == 0)
        report.check('recovery preserves user edits in partial output', bob.dav('GET', 'Concurrency/Crash/Output/review.txt') == (200, note))
        status, partial = bob.dav('GET', 'Concurrency/Crash/Output/large.bin')
        report.check('recovery preserves the exact interrupted file bytes', status == 200 and len(partial) == partial_size
                     and hashlib.sha256(partial).hexdigest() == partial_digest)
        del partial
        report.check('concurrency and crash leave the original encrypted ZIP unchanged', alice.dav('GET', 'Source/concurrency.zip') == (200, source_bytes))
        complete = True
    except Exception as error:
        failure = error
    finally:
        # Cancel only this run's jobs; never terminate an unrelated process.
        if not complete and bob is not None:
            for job_id in job_ids:
                try: bob.api('DELETE', f'/jobs/{job_id}')
                except Exception: pass
        for worker in workers:
            if worker['process'].poll() is None:
                try: worker['process'].communicate(timeout=8)
                except subprocess.TimeoutExpired:
                    if worker['pid'] is not None:
                        try: helper('kill', worker['jobId'], worker['pid'], worker['nonce'])
                        except Exception: pass
                    try: worker['process'].communicate(timeout=8)
                    except subprocess.TimeoutExpired: pass
            if worker['process'].poll() is not None:
                try: helper('worker-cleanup', worker['jobId'], worker['nonce'])
                except Exception as error:
                    complete = False
                    failure = failure or error
        if complete:
            try:
                for user in reversed(users):
                    for attempt in range(3):
                        try:
                            docker.delete_user(user)
                            break
                        except h['TestFailure'] as error:
                            if 'database is locked' not in str(error) or attempt == 2: raise
                            time.sleep(0.3 * (attempt + 1))
            except Exception as error:
                complete = False
                failure = error
        report.data['fixturesKept'] = not complete
    report.write('passed' if complete else 'failed', failure)
    return 0 if complete else 1


if __name__ == '__main__':
    if hasattr(sys.stdout, 'reconfigure'): sys.stdout.reconfigure(encoding='utf-8')
    sys.exit(run())
