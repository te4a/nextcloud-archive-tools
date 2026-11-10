#!/usr/bin/env python3
"""Run existing release suites on a fresh, guarded, disposable Linux Docker stack.

Works on Linux and Docker Desktop. Never starts/stops archive-tools-dev or reads
its .env. Only generated token-labelled resources are removed in finally.
"""
from __future__ import annotations

import argparse
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import socket
import sys
import time
import zipfile
import xml.etree.ElementTree as ET

from transport import LABEL, ROOT, load_config
from process import run_process

NEXTCLOUD_IMAGES = {
    '34': 'nextcloud:34.0.5-apache@sha256:d8ad0b77c863611b9fab6e72a82a5d35fbcb218b660f9f20fec9941a50806281',
    '35': 'nextcloud:35.0.2-apache@sha256:786c102155d2717ba0f53a62b3fdd7a1354edc48e800b94dbe32294d9a5752a2',
}
POSTGRES = 'postgres:17.11-alpine@sha256:3540c0362c809df084ffac0ae69fe794515f9de92692f02178bcdce0ef0302ac'
REDIS = 'redis:7.4.12-alpine@sha256:a58a2b51fdb121a55861f2586cbde33f174c0f33d5a277b182d52dce45ed9cd4'
MARIADB = 'mariadb:11.8.6@sha256:78a5047d3ba33975f183f183c2464cc7f1eab13ec8667e57cc9a5821d6da7577'
MYSQL = 'mysql:8.4.8@sha256:2952e3be7807f06fc18de50b3ea1a632d5c70d63482ff7d7376fe3aa8999babf'
UNIT_SUITES = [
    ('metadata.php', r'Metadata validation: (\d+) checks passed\.', 6),
    ('zip-reader.php', r'All (\d+) ZIP reader tests passed\.', 21),
    ('tar-reader.php', r'All (\d+) TAR reader tests passed\.', 19),
    ('sevenzip-reader.php', r'SevenZip reader tests: (\d+) passed, 0 failed\.', 33),
    ('format-readers.php', r'All (\d+) additional format reader tests passed\.', 4),
    ('job-retention.php', r'All (\d+) job retention tests passed\.', 23),
    ('extraction-plan.php', r'All (\d+) extraction plan tests passed\.', 12),
    ('background-user-context.php', r'All (\d+) background user context tests passed\.', 27),
    ('operation-budget.php', r'All (\d+) operation budget tests passed\.', 10),
]
HTTP_SUITES = [
    ('integration', 'integration.py', 82), ('lifecycle', 'lifecycle.py', 30),
    ('features', 'features.py', 38), ('webdav-readonly', 'webdav-readonly.py', 40),
    ('expected-etag', 'expected-etag.py', 70), ('queue-security', 'queue-security.py', 52),
    ('multiformat', 'multiformat.py', 128), ('concurrency', 'concurrency.py', 47),
]


def write_json(path: Path, value: dict) -> None:
    path.write_text(json.dumps(value, indent=2) + '\n', encoding='utf-8')


def source_fingerprint() -> str:
    digest = hashlib.sha256()
    app = ROOT / 'app'
    for path in sorted(app.rglob('*')):
        if path.is_symlink(): raise RuntimeError('CI application inputs must not contain symbolic links.')
        if path.is_file():
            digest.update(path.relative_to(app).as_posix().encode('utf-8') + b'\0')
            digest.update(path.read_bytes())
    return digest.hexdigest()


def create_config(nextcloud: str, database: str, port: int) -> tuple[Path, dict, str]:
    if nextcloud not in NEXTCLOUD_IMAGES or database not in ('pgsql', 'sqlite', 'mariadb', 'mysql') or not 18081 <= port <= 18999:
        raise ValueError('Unsupported CI matrix or port. Development port 18080 is deliberately unavailable.')
    runs = ROOT / '.ci-runs'
    if runs.is_symlink() or (runs.exists() and runs.resolve() != runs.absolute()):
        raise ValueError('CI run directory must not be a link.')
    runs.mkdir(exist_ok=True)
    token = secrets.token_hex(16)
    directory = runs / token
    directory.mkdir(mode=0o700)
    password = secrets.token_urlsafe(32)
    environment = directory / '.env'
    environment.write_text('ARCHIVE_TOOLS_ADMIN_PASSWORD=' + password + '\n', encoding='utf-8')
    environment.chmod(0o600)
    project = 'archive-tools-ci-' + token[:12]
    labels = {LABEL: token}
    app_environment = {
        'NEXTCLOUD_ADMIN_USER': 'admin', 'NEXTCLOUD_ADMIN_PASSWORD': '${ARCHIVE_TOOLS_ADMIN_PASSWORD:?CI credentials missing}',
        'NEXTCLOUD_TRUSTED_DOMAINS': 'localhost 127.0.0.1', 'PHP_MEMORY_LIMIT': '512M', 'PHP_UPLOAD_LIMIT': '1G',
    }
    volumes = {'nextcloud': {'labels': labels}}
    services = {'nextcloud': {
        'image': 'archive-tools-ci-nextcloud:' + nextcloud,
        'build': {'context': str(ROOT), 'dockerfile': 'ci/Dockerfile', 'args': {'NEXTCLOUD_IMAGE': NEXTCLOUD_IMAGES[nextcloud]}},
        'labels': labels, 'ports': [f'127.0.0.1:{port}:80'], 'environment': app_environment,
        'volumes': ['nextcloud:/var/www/html',
            {'type': 'bind', 'source': str(ROOT / 'app'), 'target': '/var/www/html/custom_apps/archive_tools', 'read_only': True},
            {'type': 'bind', 'source': str(ROOT / 'tests'), 'target': '/opt/archive-tools-tests', 'read_only': True},
            {'type': 'bind', 'source': str(ROOT / 'ci'), 'target': '/opt/archive-tools-ci', 'read_only': True},
            {'type': 'bind', 'source': str(ROOT / 'dev' / 'php.ini'), 'target': '/usr/local/etc/php/conf.d/zz-archive-tools-dev.ini', 'read_only': True}],
    }}
    depends = {}
    if database == 'sqlite':
        app_environment['SQLITE_DATABASE'] = 'nextcloud'
    if database != 'sqlite':
        volumes['database'] = {'labels': labels}
        if database == 'pgsql':
            app_environment.update(POSTGRES_HOST='database', POSTGRES_DB='nextcloud', POSTGRES_USER='nextcloud', POSTGRES_PASSWORD='${ARCHIVE_TOOLS_ADMIN_PASSWORD}')
            services['database'] = {'image': POSTGRES, 'environment': {
                'POSTGRES_DB': 'nextcloud', 'POSTGRES_USER': 'nextcloud', 'POSTGRES_PASSWORD': '${ARCHIVE_TOOLS_ADMIN_PASSWORD}'},
                'volumes': ['database:/var/lib/postgresql/data'],
                'healthcheck': {'test': ['CMD-SHELL', 'pg_isready -U nextcloud -d nextcloud'], 'interval': '2s', 'timeout': '3s', 'retries': 45}}
        else:
            app_environment.update(MYSQL_HOST='database', MYSQL_DATABASE='nextcloud', MYSQL_USER='nextcloud', MYSQL_PASSWORD='${ARCHIVE_TOOLS_ADMIN_PASSWORD}')
            services['database'] = {'image': MARIADB if database == 'mariadb' else MYSQL,
                'environment': {'MYSQL_DATABASE': 'nextcloud', 'MYSQL_USER': 'nextcloud', 'MYSQL_PASSWORD': '${ARCHIVE_TOOLS_ADMIN_PASSWORD}',
                    'MYSQL_ROOT_PASSWORD': '${ARCHIVE_TOOLS_ADMIN_PASSWORD}'}, 'volumes': ['database:/var/lib/mysql'],
                'healthcheck': {'test': ['CMD', 'healthcheck.sh', '--connect', '--innodb_initialized'] if database == 'mariadb' else
                    ['CMD-SHELL', 'mysqladmin ping -h 127.0.0.1 -uroot -p"$$MYSQL_ROOT_PASSWORD" --silent'],
                    'interval': '2s', 'timeout': '3s', 'retries': 60}}
        services['database']['labels'] = labels
        depends['database'] = {'condition': 'service_healthy'}
        volumes['redis'] = {'labels': labels}
        services['redis'] = {'image': REDIS, 'labels': labels, 'volumes': ['redis:/data'],
            'healthcheck': {'test': ['CMD', 'redis-cli', 'ping'], 'interval': '2s', 'timeout': '3s', 'retries': 30}}
        app_environment['REDIS_HOST'] = 'redis'
        depends['redis'] = {'condition': 'service_healthy'}
    if depends:
        services['nextcloud']['depends_on'] = depends
    write_json(directory / 'compose.json', {'name': project, 'services': services, 'volumes': volumes})
    config = {'schemaVersion': 1, 'token': token, 'project': project, 'nextcloud': nextcloud, 'database': database,
        'baseUrl': f'http://127.0.0.1:{port}', 'composeFile': str(directory / 'compose.json'), 'environmentFile': str(environment)}
    write_json(directory / 'config.json', config)
    load_config(str(directory / 'config.json'))
    return directory / 'config.json', config, password


class Runner:
    def __init__(self, config_path: Path, config: dict, password: str, output: Path):
        self.config_path, self.config, self.password, self.output = config_path, config, password, output
        self.environment = dict(os.environ, ARCHIVE_TOOLS_CI_CONFIG=str(config_path), PYTHONIOENCODING='utf-8')
        # Credentials come from the isolated .env, never the developer shell.
        self.environment.pop('ARCHIVE_TOOLS_ADMIN_PASSWORD', None)
        spec = importlib.util.spec_from_file_location('archive_tools_ci_helpers', ROOT / 'tests' / 'integration.py')
        previous = os.environ.get('ARCHIVE_TOOLS_CI_CONFIG')
        os.environ['ARCHIVE_TOOLS_CI_CONFIG'] = str(config_path)
        try:
            helper = importlib.util.module_from_spec(spec)
            spec.loader.exec_module(helper)
        finally:
            if previous is None: os.environ.pop('ARCHIVE_TOOLS_CI_CONFIG', None)
            else: os.environ['ARCHIVE_TOOLS_CI_CONFIG'] = previous
        self.helper = helper
        self.report = helper.Report('ci-' + config['token'][:12])
        self.report.secrets.append(password)
        self.docker = helper.Docker(self.report)
        self.created = False
        self.summary = {'schemaVersion': 1, 'nextcloudMajor': config['nextcloud'], 'database': config['database'],
            'checks': [], 'unitChecks': {}, 'httpSuites': {}}
        self.summary['applicationVersion'] = ET.parse(ROOT / 'app' / 'appinfo' / 'info.xml').getroot().findtext('version')
        self.source_fingerprint = source_fingerprint()
        self.summary['applicationSourceSha256'] = self.source_fingerprint

    def check(self, name: str, passed: bool) -> None:
        self.summary['checks'].append({'name': name, 'passed': bool(passed)})
        if not passed: raise RuntimeError(name + ' failed.')
        print('PASS ' + name, flush=True)

    def command(self, command: list[str], name: str, timeout=600) -> str:
        result = run_process(command, cwd=ROOT, env=self.environment, timeout=timeout)
        text = self.report.redact(result.stdout + result.stderr)
        (self.config_path.parent / (name + '.log')).write_text(text, encoding='utf-8')
        if result.returncode:
            # Logs stay private; public reports contain assertion names only.
            raise RuntimeError(name + ' failed; inspect the private run log locally.')
        return text

    def occ(self, *arguments: str) -> str:
        return self.docker.run(['exec', '-T', '--user', 'www-data', 'nextcloud', 'php', 'occ', *arguments])

    def assert_local_docker(self) -> None:
        override = os.environ.get('DOCKER_HOST')
        if override and not self.helper.local_docker_endpoint(override): raise RuntimeError('Remote Docker is forbidden.')
        endpoint = json.loads(self.docker.run(['context', 'inspect', '--format', '{{json .Endpoints.docker.Host}}'], compose=False))
        if not self.helper.local_docker_endpoint(endpoint): raise RuntimeError('The Docker context is not local.')

    def start(self) -> None:
        self.assert_local_docker()
        with socket.socket() as probe:
            probe.bind(('127.0.0.1', int(self.config['baseUrl'].rsplit(':', 1)[1])))
        self.created = True
        self.command(self.docker.prefix + ['up', '-d', '--build'], 'compose-start', 1200)
        deadline = time.monotonic() + 180
        while time.monotonic() < deadline:
            try:
                status = json.loads(self.occ('status', '--output=json'))
                if status.get('installed'):
                    self.check('expected Nextcloud major installed', str(status.get('version', '')).startswith(self.config['nextcloud'] + '.'))
                    break
            except Exception:
                pass
            time.sleep(2)
        else: raise RuntimeError('The disposable Nextcloud installation did not become ready.')
        self.docker.assert_test_stack()
        self.occ('app:enable', 'archive_tools')
        self.occ('upgrade', '--no-interaction')
        enabled = json.loads(self.occ('app:list', '--output=json')).get('enabled', {})
        if 'firstrunwizard' in enabled:
            self.occ('app:disable', 'firstrunwizard')
        self.occ('config:system:set', 'appstoreenabled', '--type=boolean', '--value=false')
        self.occ('background:cron')
        php = self.docker.run(['exec', '-T', '--user', 'www-data', 'nextcloud', 'php', '-r', 'if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 5) { exit(1); } echo PHP_VERSION;'])
        self.summary['phpVersion'] = php
        self.check('PHP 8.5 runtime', php.startswith('8.5.'))
        db = self.occ('config:system:get', 'dbtype').strip()
        self.check('expected database engine', db == ('mysql' if self.config['database'] in ('mysql', 'mariadb') else self.config['database']))
        probe = r'''define('OC_CONSOLE', true); require '/var/www/html/lib/base.php';
$config = \OCP\Server::get(\OCP\IConfig::class); $db = \OCP\Server::get(\OCP\IDBConnection::class);
$type = $config->getSystemValue('dbtype');
echo json_encode(['databaseVersion' => $db->executeQuery($type === 'sqlite' ? 'SELECT sqlite_version()' : 'SELECT VERSION()')->fetchOne(),
 'lockingProvider' => get_class(\OCP\Server::get(\OCP\Lock\ILockingProvider::class)), 'memcacheLocking' => $config->getSystemValue('memcache.locking', '')]);'''
        identity = json.loads(self.docker.run(['exec', '-T', '--user', 'www-data', 'nextcloud', 'php', '-r', probe]))
        self.summary.update(identity)
        if self.config['database'] != 'sqlite':
            self.check('Redis-backed file locking', identity['lockingProvider'] == 'OC\\Lock\\MemcacheLockingProvider'
                and identity['memcacheLocking'].lstrip('\\') == 'OC\\Memcache\\Redis')
            server = self.docker.run(['exec', '-T', 'redis', 'redis-cli', 'INFO', 'server'])
            match = re.search(r'^redis_version:([^\r\n]+)', server, re.M)
            self.check('Redis runtime version', match is not None and match[1] == '7.4.12')
            self.summary['redisVersion'] = match[1]
        self.summary['nextcloudVersion'] = status['version']

    def backend(self, suite: str) -> None:
        lint = self.docker.run(['exec', '-T', '--user', 'www-data', 'nextcloud', 'sh', '-c',
            'find /var/www/html/custom_apps/archive_tools /opt/archive-tools-tests -type f -name "*.php" -print0 | xargs -0 -r -n1 php -l'])
        self.check('PHP syntax', lint.count('No syntax errors detected') >= 50)
        for file, pattern, minimum in UNIT_SUITES:
            result = self.docker.run(['exec', '-T', '--user', 'www-data', '--env', 'ARCHIVE_TOOLS_APP_PATH=/var/www/html/custom_apps/archive_tools',
                'nextcloud', 'php', '/opt/archive-tools-tests/' + file])
            match = re.search(pattern, result)
            self.check(file, match is not None and int(match[1]) >= minimum)
            self.summary['unitChecks'][file] = int(match[1])
        for file, minimum in [('job-dto.php', 2), ('mount-projection.php', 2)]:
            result = self.docker.run(['exec', '-T', '--user', 'www-data', '--env', 'ARCHIVE_TOOLS_APP_PATH=/var/www/html/custom_apps/archive_tools',
                'nextcloud', 'php', '/opt/archive-tools-tests/' + file])
            self.check(file, len(re.findall(r'^PASS ', result, re.M)) >= minimum)
            self.summary['unitChecks'][file] = len(re.findall(r'^PASS ', result, re.M))
        result = self.docker.run(['exec', '-T', '--user', 'root', '--env', 'ARCHIVE_TOOLS_APP_PATH=/var/www/html/custom_apps/archive_tools',
            'nextcloud', 'php', '/opt/archive-tools-tests/temporary-resource-cleanup.php'])
        match = re.search(r'All (\d+) temporary resource cleanup tests passed\.', result)
        self.check('temporary-resource-cleanup.php', match is not None and int(match[1]) >= 22)
        self.summary['unitChecks']['temporary-resource-cleanup.php'] = int(match[1])
        for mode, disabled in [('process-disabled', 'proc_open,proc_get_status,proc_terminate,proc_close'), ('links-disabled', 'link,symlink')]:
            result = self.docker.run(['exec', '-T', '--user', 'www-data', 'nextcloud', 'php', '-d', 'disable_functions=' + disabled,
                '/opt/archive-tools-ci/dependency-absence.php', mode])
            self.check('optional dependencies: ' + mode, 'All 8 optional dependency tests passed.' in result)
            self.summary['unitChecks']['optional-dependencies-' + mode] = 8
        for name, file, minimum in HTTP_SUITES:
            if suite == 'critical' and name in ('multiformat', 'concurrency'): continue
            report_file = self.config_path.parent / 'reports' / ('latest-' + name + '.json')
            old = json.loads(report_file.read_text(encoding='utf-8-sig')).get('runId') if report_file.exists() else None
            self.command([sys.executable, str(ROOT / 'tests' / file)], name, 900)
            report = json.loads(report_file.read_text(encoding='utf-8-sig'))
            self.check(name + ' isolated report', report.get('project') == self.config['project'])
            self.check(name + ' fresh report', bool(report.get('runId')) and report['runId'] != old)
            self.check(name + ' assertions', report.get('status') == 'passed' and len(report.get('checks', [])) >= minimum
                and all(c.get('passed') for c in report['checks']))
            self.summary['httpSuites'][name] = {'status': report['status'], 'checks': len(report['checks'])}
            write_json(self.output / (name + '.json'), {'status': report['status'], 'checks': report['checks'], 'elapsedSeconds': report.get('elapsedSeconds')})

    def browser(self) -> None:
        from browser_fixture import create_fixture
        manifest = create_fixture(self)
        try:
            browser_environment = dict(self.environment, ARCHIVE_TOOLS_BROWSER_FIXTURE=str(manifest))
            node = shutil.which('node')
            if not node: raise RuntimeError('Node.js is required for browser E2E.')
            cli = ROOT / 'ci' / 'browser' / 'node_modules' / '@playwright' / 'test' / 'cli.js'
            if not cli.is_file(): raise RuntimeError('Install locked ci/browser dependencies first.')
            result = run_process([node, str(cli), 'test', '--config=' + str(ROOT / 'ci' / 'browser' / 'playwright.config.mjs')],
                cwd=ROOT / 'ci' / 'browser', env=browser_environment, timeout=360)
            (self.config_path.parent / 'browser.log').write_text(self.report.redact(result.stdout + result.stderr), encoding='utf-8')
            report = self.config_path.parent / 'browser-results.json'
            stats = json.loads(report.read_text(encoding='utf-8')).get('stats', {}) if report.is_file() else {}
            self.summary['browser'] = {key: stats.get(key, 0) for key in ('expected', 'unexpected', 'skipped', 'flaky')}
            self.check('native Files browser E2E', result.returncode == 0 and stats.get('expected', 0) >= 3
                and all(stats.get(key, 0) == 0 for key in ('unexpected', 'skipped', 'flaky')))
        finally:
            fixture = json.loads(manifest.read_text(encoding='utf-8'))
            self.docker.delete_user(fixture['user'])
            manifest.unlink(missing_ok=True)

    def native_cron(self) -> None:
        """Prove the actual scheduler runs an API-created task, without force-execute."""
        user = 'at_ci_cron_' + self.config['token'][:12]
        self.docker.add_user(user)
        try:
            client = self.helper.Client(self.config['baseUrl'], user, self.password, self.report)
            client.csrf()
            expected = b'Archive Tools native cron fixture\n'
            source = io.BytesIO()
            with zipfile.ZipFile(source, 'w', zipfile.ZIP_DEFLATED) as archive:
                archive.writestr('cron.txt', expected)
            self.check('native cron fixture uploaded', client.dav('PUT', 'cron.zip', data=source.getvalue())[0] == 201)
            source_id = client.file_id('cron.zip')
            code, info = client.api('POST', f'/archives/{source_id}/inspect', {})
            self.check('native cron fixture inspected', code == 200)
            code, job = client.api('POST', f'/archives/{source_id}/extract', {
                'destinationId': client.file_id(''), 'folderName': 'CI Cron Output', 'expectedEtag': info['sourceEtag']})
            self.check('native cron task queued through API', code == 202 and job['status'] == 'queued')
            for _ in range(2):
                self.docker.run(['exec', '-T', '--user', 'www-data', 'nextcloud', 'php', '-f', '/var/www/html/cron.php'], timeout=240)
                code, state = client.api('GET', '/jobs/' + str(job['id']))
                if code == 200 and state['status'] not in ('queued', 'running'): break
            self.check('real cron.php completes the queued task', code == 200 and state['status'] == 'succeeded'
                and state['filesDone'] == 1 and state['bytesDone'] == len(expected))
            self.check('native cron output matches original bytes', client.dav('GET', 'CI Cron Output/cron.txt') == (200, expected))
            self.summary['nativeCron'] = 'passed: actual cron.php, no force-execute'
        finally:
            self.docker.delete_user(user)

    def close(self) -> None:
        # Revalidate the generated configuration before deleting its own resources.
        load_config(str(self.config_path))
        if not self.created:
            Path(self.config['environmentFile']).unlink(missing_ok=True)
            return
        self.assert_local_docker()
        ids = self.docker.run(['ps', '--all', '--quiet'])
        for identifier in ids.splitlines():
            labels = json.loads(self.docker.run(['inspect', '--format', '{{json .Config.Labels}}', identifier], compose=False))
            if labels.get(LABEL) != self.config['token'] or labels.get('com.docker.compose.project') != self.config['project']:
                raise RuntimeError('CI cleanup refused a foreign container.')
        self.command(self.docker.prefix + ['down', '--volumes'], 'compose-cleanup', 180)
        Path(self.config['environmentFile']).unlink(missing_ok=True)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--nextcloud', choices=['34', '35'], default='34')
    parser.add_argument('--database', choices=['pgsql', 'sqlite', 'mariadb', 'mysql'], default='pgsql')
    parser.add_argument('--suite', choices=['full', 'critical'], default='full')
    parser.add_argument('--port', type=int, default=18081)
    parser.add_argument('--browser', action='store_true')
    parser.add_argument('--browser-only', action='store_true', help='Explicit browser development run; skips backend assertions and never counts as a full gate.')
    parser.add_argument('--prepare-only', action='store_true', help='Generate and validate private configuration without starting Docker.')
    arguments = parser.parse_args()
    config_path, config, password = create_config(arguments.nextcloud, arguments.database, arguments.port)
    if arguments.prepare_only:
        Path(config['environmentFile']).unlink()
        print('Prepared guarded configuration for NC' + arguments.nextcloud + '/' + arguments.database + '; Docker was not invoked.')
        return 0
    scope = 'browser-only' if arguments.browser_only else arguments.suite
    output = ROOT / '.test-results' / 'ci-public' / ('nc' + arguments.nextcloud + '-' + arguments.database + '-' + scope)
    output.mkdir(parents=True, exist_ok=True)
    runner = Runner(config_path, config, password, output)
    runner.summary['scope'] = scope
    failed = False
    try:
        runner.start()
        if not arguments.browser_only:
            runner.backend(arguments.suite)
            if arguments.suite == 'full': runner.native_cron()
        if arguments.browser or arguments.browser_only: runner.browser()
        runner.check('application tree unchanged during validation', source_fingerprint() == runner.source_fingerprint)
        runner.summary['status'] = 'passed'
    except BaseException as error:
        failed = True
        runner.summary['status'] = 'failed'
        runner.summary['error'] = runner.report.redact(str(error))[:1500]
        print('FAIL ' + runner.summary['error'], file=sys.stderr)
    finally:
        try: runner.close()
        except BaseException as error:
            failed = True
            runner.summary['status'] = 'failed'
            runner.summary['cleanupError'] = runner.report.redact(str(error))[:1500]
        write_json(output / 'summary.json', runner.summary)
    return 1 if failed else 0


if __name__ == '__main__':
    raise SystemExit(main())
