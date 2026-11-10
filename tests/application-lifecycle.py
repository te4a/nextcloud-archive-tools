#!/usr/bin/env python3
"""Real lifecycle regression in a separate, networkless disposable Nextcloud."""
from __future__ import annotations

import json
import re
import runpy
import subprocess
import sys
import time
import uuid
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
V = runpy.run_path(str(ROOT / 'dev' / 'verify-package.py'))
H = V['H']
PROJECT = 'archive-tools-dev-lifecycle-fixture'
PUBLIC_PASSWORD = 'ArchiveTools-Lifecycle-Fixture-2026!'
HELPER = '/opt/archive-tools-tests/application-lifecycle.php'


def require(condition: bool, message: str) -> None:
    if not condition:
        raise H['TestFailure'](message)


def fixture_identity(docker, container: str, volume: str, token: str, image: str) -> None:
    rows = json.loads(docker.run(['inspect', container], compose=False))
    require(len(rows) == 1, 'The disposable lifecycle instance is ambiguous.')
    info = rows[0]
    labels = info['Config'].get('Labels') or {}
    host = info.get('HostConfig') or {}
    mounts = info.get('Mounts') or []
    require(info.get('Name') == '/archive-tools-dev-lifecycle-' + token
            and info.get('Image') == image
            and labels.get('com.docker.compose.project') == PROJECT
            and labels.get('com.docker.compose.service') == 'fixture'
            and labels.get('com.archive-tools.lifecycle-fixture') == token
            and host.get('NetworkMode') == 'none' and not host.get('PortBindings')
            and host.get('AutoRemove') is True and host.get('Privileged') is False,
            'The disposable fixture identity or network isolation changed.')
    data = [m for m in mounts if m.get('Destination') == '/var/www/html']
    require(len(data) == 1 and data[0].get('Type') == 'volume' and data[0].get('Name') == volume,
            'The disposable instance does not use its unique fixture data volume.')
    for target, source in [('/var/www/html/custom_apps/archive_tools', ROOT / 'app'),
                           ('/opt/archive-tools-tests', ROOT / 'tests')]:
        found = [m for m in mounts if m.get('Destination') == target]
        require(len(found) == 1 and found[0].get('Type') == 'bind' and found[0].get('RW') is False
                and V['normalize_bind'](found[0].get('Source', '')) == V['normalize_bind'](str(source.resolve())),
                'The disposable fixture has an unexpected code origin.')
    require(len(mounts) == 3, 'The disposable fixture contains an unexpected mount.')


def run(report) -> None:
    report.secrets.append(PUBLIC_PASSWORD)
    report.secrets.extend(H['read_environment']().values())
    docker = V['SafeDocker'](report)
    docker.assert_test_stack()  # Read-only validation of the local Docker context.
    manifest = (ROOT / 'app' / 'appinfo' / 'info.xml').read_text(encoding='utf-8')
    require('OCA\\ArchiveTools\\Repair\\InstallApplicationState' in manifest
            and 'OCA\\ArchiveTools\\Repair\\RepairApplicationState' in manifest,
            'Register the explicit install and upgrade lifecycle repair hooks before this regression.')
    source = docker.run(['ps', '--quiet', 'nextcloud'])
    image_tag = docker.run(['inspect', '--format', '{{.Config.Image}}', source], compose=False)
    require(re.fullmatch(r'archive-tools-nextcloud-dev:[A-Za-z0-9_.-]+', image_tag) is not None,
            'Only the existing local custom Nextcloud image may be used.')
    image = docker.run(['image', 'inspect', '--format', '{{.Id}}', image_tag], compose=False)
    require(re.fullmatch(r'sha256:[a-f0-9]{64}', image) is not None, 'The local immutable fixture image is missing.')
    token = uuid.uuid4().hex
    volume = 'archive-tools-dev-lifecycle-' + token
    name = volume
    require(not docker.run(['volume', 'ls', '--filter', 'name=^' + volume + '$', '--format', '{{.Name}}'], compose=False),
            'The unique fixture volume already exists.')
    require(docker.run(['volume', 'create', '--label', 'com.docker.compose.project=' + PROJECT,
                        '--label', 'com.archive-tools.lifecycle-fixture=' + token, volume], compose=False) == volume,
            'Docker returned an unexpected fixture volume.')
    report.data['retainedFixtureVolume'] = volume
    container = ''
    holder = None

    def execute(*args: str, timeout: int = 120) -> str:
        fixture_identity(docker, container, volume, token, image)
        return docker.run(['exec', '--user', 'www-data', container, *args], compose=False, timeout=timeout)

    def occ(*args: str, timeout: int = 120) -> str:
        return execute('php', '/var/www/html/occ', '--no-warnings', *args, timeout=timeout)

    def phase(action: str) -> None:
        result = V['SEED']['json_object'](execute('php', HELPER, action))
        require(result.get('status') == 'passed', 'The private lifecycle fixture failed: ' + json.dumps(result))
        for check in result['checks']:
            report.check(check['name'], check['passed'])

    try:
        # The image's VOLUME is replaced by our unique named fixture volume.
        # Both read-only host paths are already shared by the development stack.
        initialization = ('set -eu; cp -a /usr/src/nextcloud/. /var/www/html/; '
                          'find /var/www/html -path /var/www/html/custom_apps/archive_tools -prune '
                          '-o -exec chown www-data:www-data {} +; '
                          'touch /tmp/archive-tools-lifecycle-runtime-ready; exec sleep 900')
        container = docker.run(['run', '--detach', '--rm', '--pull', 'never', '--name', name,
                                '--label', 'com.docker.compose.project=' + PROJECT,
                                '--label', 'com.docker.compose.service=fixture',
                                '--label', 'com.archive-tools.lifecycle-fixture=' + token,
                                '--network', 'none', '--env', 'ARCHIVE_TOOLS_LIFECYCLE_FIXTURE=' + token,
                                '--mount', 'type=volume,source=' + volume + ',target=/var/www/html,volume-nocopy',
                                '--mount', 'type=bind,source=' + str((ROOT / 'app').resolve()) + ',target=/var/www/html/custom_apps/archive_tools,readonly',
                                '--mount', 'type=bind,source=' + str((ROOT / 'tests').resolve()) + ',target=/opt/archive-tools-tests,readonly',
                                '--entrypoint', 'sh', image, '-c', initialization], compose=False, timeout=30)
        require(re.fullmatch(r'[a-f0-9]{64}', container) is not None, 'Docker did not return one disposable fixture id.')
        deadline = time.monotonic() + 60
        while time.monotonic() < deadline:
            ready = execute('php', '-r', "echo is_file('/tmp/archive-tools-lifecycle-runtime-ready') ? 'ready' : '';", timeout=10)
            if ready == 'ready':
                break
            time.sleep(.2)
        else:
            raise H['TestFailure']('The bounded disposable fixture did not initialize.')
        occ('maintenance:install', '--database', 'sqlite', '--database-name', 'nextcloud',
            '--admin-user', 'fixture_admin', '--admin-pass', PUBLIC_PASSWORD,
            '--data-dir', '/var/www/html/data', timeout=180)
        occ('app:enable', 'archive_tools')
        report.check('separate SQLite instance installs the current app with no network or published ports', True)
        phase('seed')
        phase('missing')
        before = V['SEED']['json_object'](occ('archive_tools:purge', '--output', 'json'))
        report.check('purge CLI is read-only unless --force is explicit', before.get('purged') is False and before['result']['connections'] == 1)
        occ('app:disable', 'archive_tools')
        occ('app:enable', 'archive_tools')
        phase('preserved')
        phase('repair')
        phase('busy')
        phase('cron-temporary')

        holder = subprocess.Popen([docker.binary, 'exec', '--user', 'www-data', container, 'php', HELPER, 'hold'],
                                  cwd=ROOT, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                  text=True, encoding='utf-8', errors='replace',
                                  creationflags=subprocess.CREATE_NO_WINDOW if sys.platform == 'win32' else 0)
        for _ in range(25):
            if execute('php', '-r', "echo is_file('/tmp/archive-tools-lifecycle-holder-ready') ? 'ready' : '';", timeout=10) == 'ready':
                break
            require(holder.poll() is None, 'The lifecycle barrier holder ended before readiness.')
            time.sleep(.1)
        else:
            raise H['TestFailure']('The private lifecycle barrier holder did not start.')
        rejected = False
        try:
            occ('archive_tools:purge', '--force', '--output', 'json', timeout=20)
        except H['TestFailure'] as error:
            rejected = 'Archive Tools is in use.' in str(error)
        report.check('a real concurrent shared lifecycle lease rejects force purge', rejected)
        stdout, stderr = holder.communicate(timeout=15)
        require(holder.returncode == 0, 'The private lifecycle holder failed: ' + report.redact(stderr or stdout))
        holder = None
        holder = subprocess.Popen([docker.binary, 'exec', '--user', 'www-data', container, 'php', HELPER, 'hold-exclusive'],
                                  cwd=ROOT, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                  text=True, encoding='utf-8', errors='replace',
                                  creationflags=subprocess.CREATE_NO_WINDOW if sys.platform == 'win32' else 0)
        for _ in range(25):
            if execute('php', '-r', "echo is_file('/tmp/archive-tools-lifecycle-holder-ready') ? 'ready' : '';", timeout=10) == 'ready':
                break
            require(holder.poll() is None, 'The exclusive lifecycle holder ended before readiness.')
            time.sleep(.1)
        else:
            raise H['TestFailure']('The exclusive lifecycle holder did not start.')
        phase('controllers-busy')
        stdout, stderr = holder.communicate(timeout=15)
        require(holder.returncode == 0, 'The exclusive lifecycle holder failed: ' + report.redact(stderr or stdout))
        holder = None
        result = V['SEED']['json_object'](occ('archive_tools:purge', '--force', '--output', 'json'))
        report.check('explicit force purge completes through the real native command', result.get('purged') is True and result['result'].get('connections') == 1)
        temporary = result['result']['temporaryResources']
        report.check('force purge reports removal of expired decoder resources', temporary['removedInputs'] >= 1 and temporary['removedNameLists'] >= 1)
        report.check('force purge explicitly reports retained fresh resources', temporary['retainedFreshInputs'] >= 1 and temporary['retainedFreshNameLists'] >= 1)
        report.data['purgeTemporaryResources'] = temporary
        phase('purged')
        occ('app:enable', 'archive_tools')
        phase('reinstalled')
        report.data['status'] = 'passed'
    finally:
        if holder is not None:
            try:
                holder.communicate(timeout=10)
            except subprocess.TimeoutExpired:
                docker._stop_tree(holder)
        if container:
            fixture_identity(docker, container, volume, token, image)
            docker.run(['stop', '--time', '1', container], compose=False, timeout=15)
            report.data['disposableContainerStopped'] = True
        report.data['mainStackRecreated'] = False


def main() -> int:
    report = H['Report']('application-lifecycle-' + uuid.uuid4().hex)
    try:
        run(report)
        result = 0
    except BaseException as error:
        report.data['status'] = 'failed'
        report.data['error'] = report.redact(str(error))
        result = 1
    report.data['elapsedSeconds'] = round(time.monotonic() - report.started, 2)
    destination = ROOT / '.test-results' / 'latest-application-lifecycle.json'
    destination.parent.mkdir(exist_ok=True)
    destination.write_text(report.redact(json.dumps(report.data, indent=2)) + '\n', encoding='utf-8')
    print(report.redact(json.dumps(report.data, indent=2)))
    return result


if __name__ == '__main__':
    raise SystemExit(main())
