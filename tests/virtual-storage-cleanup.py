#!/usr/bin/env python3
"""Real cache cleanup/reaper regression in a separate disposable SQLite instance."""
from __future__ import annotations

import json
import re
import runpy
import time
import uuid
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
L = runpy.run_path(str(ROOT / 'tests' / 'application-lifecycle.py'))
V, H = L['V'], L['H']
HELPER = '/opt/archive-tools-tests/virtual-storage-cleanup.php'


def require(condition: bool, message: str) -> None:
    if not condition:
        raise H['TestFailure'](message)


def run(report) -> None:
    report.secrets.extend(H['read_environment']().values())
    report.secrets.append(L['PUBLIC_PASSWORD'])
    docker = V['SafeDocker'](report)
    docker.assert_test_stack()  # Read-only main context/image discovery.
    current = docker.run(['ps', '--quiet', 'nextcloud'])
    image_tag = docker.run(['inspect', '--format', '{{.Config.Image}}', current], compose=False)
    require(re.fullmatch(r'archive-tools-nextcloud-dev:[A-Za-z0-9_.-]+', image_tag) is not None,
            'The existing local custom Nextcloud image is required.')
    image = docker.run(['image', 'inspect', '--format', '{{.Id}}', image_tag], compose=False)
    require(re.fullmatch(r'sha256:[a-f0-9]{64}', image) is not None, 'The local fixture image is unavailable.')
    token = uuid.uuid4().hex
    volume = 'archive-tools-dev-virtual-cache-' + token
    name = 'archive-tools-dev-lifecycle-' + token
    require(not docker.run(['volume', 'ls', '--filter', 'name=^' + volume + '$', '--format', '{{.Name}}'], compose=False),
            'The unique private fixture volume already exists.')
    require(docker.run(['volume', 'create', '--label', 'com.docker.compose.project=' + L['PROJECT'],
                        '--label', 'com.archive-tools.lifecycle-fixture=' + token,
                        '--label', 'com.archive-tools.fixture-role=virtual-storage-cleanup', volume], compose=False) == volume,
            'Docker returned an unexpected private fixture volume.')
    report.data['retainedFixtureVolume'] = volume
    report.data['testScope'] = 'whole-app reaper only in private SQLite instance; no main cleanup'
    container = ''

    def execute(*args: str, timeout: int = 120) -> str:
        L['fixture_identity'](docker, container, volume, token, image)
        return docker.run(['exec', '--user', 'www-data', container, *args], compose=False, timeout=timeout)

    def phase(action: str) -> None:
        result = V['SEED']['json_object'](execute('php', HELPER, action))
        require(result.get('status') == 'passed', 'The private virtual-storage fixture failed: ' + json.dumps(result))
        for check in result['checks']:
            report.check(check['name'], check['passed'])
        if 'pass' in result:
            report.data.setdefault('reaperPasses', {})[action] = result['pass']

    try:
        initialization = ('set -eu; cp -a /usr/src/nextcloud/. /var/www/html/; '
                          'find /var/www/html -path /var/www/html/custom_apps/archive_tools -prune '
                          '-o -exec chown www-data:www-data {} +; '
                          'touch /tmp/archive-tools-virtual-cache-ready; exec sleep 900')
        container = docker.run(['run', '--detach', '--rm', '--pull', 'never', '--name', name,
                                '--label', 'com.docker.compose.project=' + L['PROJECT'],
                                '--label', 'com.docker.compose.service=fixture',
                                '--label', 'com.archive-tools.lifecycle-fixture=' + token,
                                '--network', 'none', '--env', 'ARCHIVE_TOOLS_LIFECYCLE_FIXTURE=' + token,
                                '--mount', 'type=volume,source=' + volume + ',target=/var/www/html,volume-nocopy',
                                '--mount', 'type=bind,source=' + str((ROOT / 'app').resolve()) + ',target=/var/www/html/custom_apps/archive_tools,readonly',
                                '--mount', 'type=bind,source=' + str((ROOT / 'tests').resolve()) + ',target=/opt/archive-tools-tests,readonly',
                                '--entrypoint', 'sh', image, '-c', initialization], compose=False, timeout=30)
        require(re.fullmatch(r'[a-f0-9]{64}', container) is not None, 'Docker did not return one private instance id.')
        deadline = time.monotonic() + 60
        while time.monotonic() < deadline:
            if execute('php', '-r', "echo is_file('/tmp/archive-tools-virtual-cache-ready') ? 'ready' : '';", timeout=10) == 'ready':
                break
            time.sleep(.2)
        else:
            raise H['TestFailure']('The private virtual-cache instance did not become ready.')
        execute('php', '/var/www/html/occ', '--no-warnings', 'maintenance:install', '--database', 'sqlite',
                '--database-name', 'nextcloud', '--admin-user', 'fixture_admin', '--admin-pass', L['PUBLIC_PASSWORD'],
                '--data-dir', '/var/www/html/data', timeout=180)
        execute('php', '/var/www/html/occ', '--no-warnings', 'app:enable', 'archive_tools')
        phase('seed')
        for _ in range(3):
            # Separate PHP invocations model fresh HTTP request/service graphs.
            phase('connect')
            phase('disconnect')
        for action in ['live', 'active-connection', 'seed-orphans', 'first-page', 'second-page',
                       'third-page', 'lease-expired', 'remove-live', 'remove-all']:
            phase(action)
        report.data['status'] = 'passed'
    finally:
        if container:
            L['fixture_identity'](docker, container, volume, token, image)
            docker.run(['stop', '--time', '1', container], compose=False, timeout=15)
            report.data['disposableInstanceStopped'] = True
        report.data['mainStackRecreated'] = False


def main() -> int:
    report = H['Report']('virtual-storage-cleanup-' + uuid.uuid4().hex)
    try:
        run(report)
        result = 0
    except BaseException as error:
        report.data['status'] = 'failed'
        report.data['error'] = report.redact(str(error))
        result = 1
    report.data['elapsedSeconds'] = round(time.monotonic() - report.started, 2)
    destination = ROOT / '.test-results' / 'latest-virtual-storage-cleanup.json'
    destination.parent.mkdir(exist_ok=True)
    destination.write_text(report.redact(json.dumps(report.data, indent=2)) + '\n', encoding='utf-8')
    print(report.redact(json.dumps({'status': report.data['status'], 'checks': len(report.data['checks']),
                                  'error': report.data.get('error'), 'elapsedSeconds': report.data['elapsedSeconds'],
                                  'mainStackRecreated': report.data.get('mainStackRecreated'),
                                  'reaperPasses': report.data.get('reaperPasses')}, indent=2)))
    return result


if __name__ == '__main__':
    raise SystemExit(main())
