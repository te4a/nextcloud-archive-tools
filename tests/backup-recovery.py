#!/usr/bin/env python3
"""Offline coherent SQLite backup/restore in an isolated disposable instance.

This proves config+data+SQLite recovery with unchanged app/core code. It is not
a PostgreSQL restore, remote/object-storage backup, or rolling-upgrade test.
"""
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
HELPER = '/opt/archive-tools-tests/backup-recovery.php'
PROJECT = 'archive-tools-dev-backup-copy'


def require(condition: bool, message: str) -> None:
    if not condition:
        raise H['TestFailure'](message)


def run(report) -> None:
    report.secrets.extend(H['read_environment']().values())
    report.secrets.append(L['PUBLIC_PASSWORD'])
    docker = V['SafeDocker'](report)
    docker.assert_test_stack()  # Only reads the main service/context identity.
    current = docker.run(['ps', '--quiet', 'nextcloud'])
    image_tag = docker.run(['inspect', '--format', '{{.Config.Image}}', current], compose=False)
    require(re.fullmatch(r'archive-tools-nextcloud-dev:[A-Za-z0-9_.-]+', image_tag) is not None,
            'The existing local custom fixture image is required.')
    image = docker.run(['image', 'inspect', '--format', '{{.Id}}', image_tag], compose=False)
    require(re.fullmatch(r'sha256:[a-f0-9]{64}', image) is not None, 'The immutable local fixture image is unavailable.')
    token = uuid.uuid4().hex
    data_volume = 'archive-tools-dev-backup-data-' + token
    backup_volume = 'archive-tools-dev-backup-snapshot-' + token
    instance_name = 'archive-tools-dev-lifecycle-' + token
    container = ''

    def add_checks(result: dict) -> dict:
        require(result.get('status') == 'passed', 'The private backup fixture failed: ' + json.dumps(result))
        for check in result.get('checks', []):
            report.check(check['name'], check['passed'])
        return result

    def execute(*arguments: str, timeout: int = 120) -> str:
        L['fixture_identity'](docker, container, data_volume, token, image)
        return docker.run(['exec', '--user', 'www-data', container, *arguments], compose=False, timeout=timeout)

    def occ(*arguments: str, timeout: int = 120) -> str:
        return execute('php', '/var/www/html/occ', '--no-warnings', *arguments, timeout=timeout)

    def phase(action: str) -> dict:
        return add_checks(V['SEED']['json_object'](execute('php', HELPER, action)))

    def create_volume(name: str, role: str) -> None:
        require(not docker.run(['volume', 'ls', '--filter', 'name=^' + name + '$', '--format', '{{.Name}}'], compose=False),
                'The unique private backup volume already exists.')
        require(docker.run(['volume', 'create', '--label', 'com.docker.compose.project=' + PROJECT,
                            '--label', 'com.archive-tools.backup-fixture=' + token,
                            '--label', 'com.archive-tools.backup-role=' + role, name], compose=False) == name,
                'Docker created an unexpected fixture volume.')

    def start_instance(initial: bool) -> None:
        nonlocal container
        require(not docker.run(['ps', '--quiet', '--filter', 'volume=' + data_volume], compose=False),
                'The private data volume already has a running consumer.')
        initialization = (('set -eu; cp -a /usr/src/nextcloud/. /var/www/html/; '
                           'find /var/www/html -path /var/www/html/custom_apps/archive_tools -prune '
                           '-o -exec chown www-data:www-data {} +; ') if initial else '')
        initialization += 'touch /tmp/archive-tools-backup-runtime-ready; exec sleep 900'
        container = docker.run(['run', '--detach', '--rm', '--pull', 'never', '--name', instance_name,
                                '--label', 'com.docker.compose.project=' + L['PROJECT'],
                                '--label', 'com.docker.compose.service=fixture',
                                '--label', 'com.archive-tools.lifecycle-fixture=' + token,
                                '--network', 'none', '--env', 'ARCHIVE_TOOLS_LIFECYCLE_FIXTURE=' + token,
                                '--env', 'ARCHIVE_TOOLS_BACKUP_FIXTURE=' + token,
                                '--mount', 'type=volume,source=' + data_volume + ',target=/var/www/html,volume-nocopy',
                                '--mount', 'type=bind,source=' + str((ROOT / 'app').resolve()) + ',target=/var/www/html/custom_apps/archive_tools,readonly',
                                '--mount', 'type=bind,source=' + str((ROOT / 'tests').resolve()) + ',target=/opt/archive-tools-tests,readonly',
                                '--entrypoint', 'sh', image, '-c', initialization], compose=False, timeout=30)
        require(re.fullmatch(r'[a-f0-9]{64}', container) is not None, 'Docker returned an unexpected private instance id.')
        deadline = time.monotonic() + 60
        while time.monotonic() < deadline:
            if execute('php', '-r', "echo is_file('/tmp/archive-tools-backup-runtime-ready') ? 'ready' : '';", timeout=10) == 'ready':
                return
            time.sleep(.2)
        raise H['TestFailure']('The private backup instance did not become ready.')

    def stop_instance() -> None:
        nonlocal container
        if container:
            L['fixture_identity'](docker, container, data_volume, token, image)
            docker.run(['stop', '--time', '1', container], compose=False, timeout=15)
            container = ''
        require(not docker.run(['ps', '--quiet', '--filter', 'volume=' + data_volume], compose=False),
                'The private data volume is not offline.')

    def offline_copy(action: str) -> dict:
        require(action in ('offline-capture', 'offline-restore'), 'Unsupported private copy action.')
        require(not container and not docker.run(['ps', '--quiet', '--filter', 'volume=' + data_volume], compose=False),
                'Coherent snapshots require the private instance to be stopped.')
        readonly_data = action == 'offline-capture'
        name = 'archive-tools-dev-backup-copy-' + uuid.uuid4().hex
        helper = ''

        def guard() -> None:
            info = json.loads(docker.run(['inspect', helper], compose=False))[0]
            labels = info['Config'].get('Labels') or {}
            host = info['HostConfig']
            require(info.get('Name') == '/' + name and info.get('Image') == image
                    and labels.get('com.docker.compose.project') == PROJECT
                    and labels.get('com.docker.compose.service') == 'copy'
                    and labels.get('com.archive-tools.backup-fixture') == token
                    and info['Config'].get('User') == '0'
                    and info['Config'].get('Entrypoint') == ['php']
                    and info['Config'].get('Cmd') == ['-r', 'sleep(120);']
                    and host.get('NetworkMode') == 'none' and not host.get('PortBindings')
                    and host.get('AutoRemove') is True and host.get('ReadonlyRootfs') is True
                    and host.get('Privileged') is False,
                    'The offline snapshot helper identity or isolation changed.')
            mounts = info['Mounts']
            for target, volume, writable in [('/var/www/html', data_volume, not readonly_data),
                                             ('/snapshot', backup_volume, readonly_data)]:
                found = [m for m in mounts if m.get('Destination') == target]
                require(len(found) == 1 and found[0].get('Type') == 'volume'
                        and found[0].get('Name') == volume and found[0].get('RW') is writable,
                        'The snapshot helper does not use the exact expected volume/access mode.')
            tests = [m for m in mounts if m.get('Destination') == '/opt/archive-tools-tests']
            require(len(mounts) == 3 and len(tests) == 1 and tests[0].get('Type') == 'bind' and tests[0].get('RW') is False
                    and V['normalize_bind'](tests[0]['Source']) == V['normalize_bind'](str((ROOT / 'tests').resolve())),
                    'The snapshot helper has an unexpected host or data mount.')
            require(docker.run(['ps', '--no-trunc', '--quiet', '--filter', 'volume=' + data_volume], compose=False) == helper,
                    'Another running container could write the supposedly offline fixture volume.')

        try:
            helper = docker.run(['run', '--detach', '--rm', '--pull', 'never', '--name', name,
                                 '--label', 'com.docker.compose.project=' + PROJECT,
                                 '--label', 'com.docker.compose.service=copy',
                                 '--label', 'com.archive-tools.backup-fixture=' + token,
                                 '--user', '0', '--network', 'none', '--read-only', '--cap-drop', 'ALL',
                                 '--cap-add', 'CHOWN', '--cap-add', 'DAC_OVERRIDE', '--cap-add', 'FOWNER',
                                 '--security-opt', 'no-new-privileges:true',
                                 '--env', 'ARCHIVE_TOOLS_BACKUP_FIXTURE=' + token,
                                 '--env', 'ARCHIVE_TOOLS_BACKUP_ROLE=copy',
                                 '--mount', 'type=volume,source=' + data_volume + ',target=/var/www/html,volume-nocopy' + (',readonly' if readonly_data else ''),
                                 '--mount', 'type=volume,source=' + backup_volume + ',target=/snapshot,volume-nocopy' + ('' if readonly_data else ',readonly'),
                                 '--mount', 'type=bind,source=' + str((ROOT / 'tests').resolve()) + ',target=/opt/archive-tools-tests,readonly',
                                 '--entrypoint', 'php', image, '-r', 'sleep(120);'], compose=False, timeout=30)
            require(re.fullmatch(r'[a-f0-9]{64}', helper) is not None, 'Docker did not return one offline helper id.')
            guard()
            result = add_checks(V['SEED']['json_object'](docker.run(['exec', helper, 'php', HELPER, action], compose=False, timeout=90)))
            report.check('offline copy has no other running data-volume consumer', True)
            return result
        finally:
            if helper:
                guard()
                docker.run(['stop', '--time', '1', helper], compose=False, timeout=15)

    create_volume(data_volume, 'instance-data')
    create_volume(backup_volume, 'coherent-snapshot')
    report.data.update({'retainedFixtureVolume': data_volume, 'retainedBackupVolume': backup_volume,
                        'restoreScope': 'offline SQLite config+data snapshot; same core and app code',
                        'postgresqlRestoreTested': False})
    try:
        start_instance(True)
        occ('maintenance:install', '--database', 'sqlite', '--database-name', 'nextcloud',
            '--admin-user', 'fixture_admin', '--admin-pass', L['PUBLIC_PASSWORD'], '--data-dir', '/var/www/html/data', timeout=180)
        occ('app:enable', 'archive_tools')
        add_checks(V['SEED']['json_object'](execute('php', '/opt/archive-tools-tests/application-lifecycle.php', 'seed')))
        phase('baseline')
        stop_instance()
        captured = offline_copy('offline-capture')
        report.data['snapshot'] = {'entries': captured['entryCount'], 'treeSha256': captured['treeSha256']}
        start_instance(False)
        result = V['SEED']['json_object'](occ('archive_tools:purge', '--force', '--output', 'json'))
        report.check('the private instance is actually purged after the coherent snapshot', result.get('purged') is True)
        phase('purged')
        phase('rotate-key')
        phase('wrong-key')
        stop_instance()
        restored = offline_copy('offline-restore')
        report.check('restored snapshot file set hashes ownership and modes equal the captured snapshot', restored['treeSha256'] == captured['treeSha256'])
        start_instance(False)
        restored_state = phase('restored')
        native_id = restored_state['nativeJobId']
        require(isinstance(native_id, str) and native_id.isdigit(), 'The restored native job identity is invalid.')
        occ('background-job:execute', '--force-execute', native_id)
        phase('extracted')
        report.data['status'] = 'passed'
    finally:
        stop_instance()
        report.data['disposableInstanceStopped'] = True
        report.data['mainStackRecreated'] = False


def main() -> int:
    report = H['Report']('backup-recovery-' + uuid.uuid4().hex)
    try:
        run(report)
        result = 0
    except BaseException as error:
        report.data['status'] = 'failed'
        report.data['error'] = report.redact(str(error))
        result = 1
    report.data['elapsedSeconds'] = round(time.monotonic() - report.started, 2)
    destination = ROOT / '.test-results' / 'latest-backup-recovery.json'
    destination.parent.mkdir(exist_ok=True)
    destination.write_text(report.redact(json.dumps(report.data, indent=2)) + '\n', encoding='utf-8')
    print(report.redact(json.dumps(report.data, indent=2)))
    return result


if __name__ == '__main__':
    raise SystemExit(main())
