#!/usr/bin/env python3
"""Seed public demo data only in the guarded local archive-tools-dev stack."""
from __future__ import annotations

import argparse
import base64
from datetime import datetime, timezone
import gzip
import hashlib
from html.parser import HTMLParser
import json
from pathlib import Path
import re
import runpy
import sys
import time
import uuid
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
RESULTS = ROOT / '.test-results'
BASE_URL = 'http://127.0.0.1:18080'
DEMO_USER = 'archive_demo'
# Deliberately public local fixture credentials; never use the admin password.
DEMO_PASSWORD = 'ArchiveTools-Local-Preview-2026!'
ARCHIVE_PASSWORD = 'format-test'
M = runpy.run_path(str(ROOT / 'tests' / 'multiformat.py'))
H = M['h']


def require(condition: bool, message: str) -> None:
    if not condition:
        raise H['TestFailure'](message)


def manifest_version() -> str:
    manifest = ET.parse(ROOT / 'app' / 'appinfo' / 'info.xml').getroot()
    version = manifest.findtext('version', '')
    require(manifest.findtext('id') == 'archive_tools'
            and re.fullmatch(r'[0-9]+(?:\.[0-9]+){1,3}(?:[-.][A-Za-z0-9]+)*', version) is not None,
            'The workspace application manifest has an invalid id or version.')
    return version


def json_object(value: str) -> dict:
    for match in re.finditer(r'\{', value):
        try:
            decoded, _ = json.JSONDecoder().raw_decode(value[match.start():])
        except ValueError:
            continue
        if isinstance(decoded, dict):
            return decoded
    raise H['TestFailure']('The local command did not return a JSON object.')


def occ(docker, *arguments: str) -> str:
    return docker.run(['exec', '-T', '--interactive=false', '--user', 'www-data', 'nextcloud', 'php', 'occ', *arguments])


def runtime_identity(docker) -> dict:
    status = json_object(occ(docker, 'status', '--output=json'))
    version = str(status.get('version', ''))
    major = version.split('.')[0]
    require(status.get('installed') is True and not status.get('maintenance') and major in ('34', '35'),
            'The demo requires a ready local Nextcloud 34 or 35 installation.')
    instance = occ(docker, 'config:system:get', 'instanceid').strip()
    require(re.fullmatch(r'[A-Za-z0-9_-]{4,128}', instance) is not None,
            'The local installation identity is unavailable.')
    fingerprint = hashlib.sha256(instance.encode('utf-8')).hexdigest()[:12]
    return {'namespace': 'nc' + major + '-' + fingerprint, 'nextcloudMajor': int(major),
            'nextcloudVersion': version, 'instanceHash': fingerprint}


def artifact_paths(stack: dict) -> dict[str, Path]:
    namespace = stack.get('namespace', '')
    require(re.fullmatch(r'nc(?:34|35)-[a-f0-9]{12}', namespace) is not None,
            'The demo artifact namespace is invalid.')
    return {'formats': RESULTS / ('demo-formats-' + namespace + '.json'),
            'extraction': RESULTS / ('demo-extraction-' + namespace + '.json')}


class InitialStateParser(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.formats = None
        self.scripts = []

    def handle_starttag(self, tag, attrs):
        values = dict(attrs)
        if tag == 'input' and values.get('id') == 'initial-state-archive_tools-archiveFormats':
            self.formats = values.get('value')
        if tag == 'script' and values.get('src'):
            self.scripts.append(values['src'])


def http_initial_state(client) -> tuple[list[str], list[str]]:
    code, body = client.request('GET', '/index.php/apps/files/?archive_tools_smoke=' + uuid.uuid4().hex,
                                headers={'Accept': 'text/html', 'Cache-Control': 'no-cache'})
    require(code == 200, 'The current Files page could not be loaded.')
    parser = InitialStateParser()
    parser.feed(body.decode('utf-8'))
    require(isinstance(parser.formats, str), 'The current Files page has no archive format initial state.')
    try:
        formats = json.loads(base64.b64decode(parser.formats, validate=True))
    except (ValueError, UnicodeDecodeError):
        raise H['TestFailure']('The archive format initial state is not valid base64 JSON.') from None
    require(isinstance(formats, list) and all(isinstance(value, str) for value in formats)
            and len(formats) == len(set(formats)), 'The current archive format initial state is invalid.')
    return formats, parser.scripts


def members_manifest(files: dict[str, bytes]) -> list[dict]:
    return [{'path': name, 'bytes': len(value), 'sha256': hashlib.sha256(value).hexdigest()}
            for name, value in sorted(files.items())]


def write_artifact(path: Path, value: dict, alias: str) -> None:
    RESULTS.mkdir(exist_ok=True)
    serialized = json.dumps(value, ensure_ascii=False, indent=2) + '\n'
    path.write_text(serialized, encoding='utf-8')
    current = RESULTS / alias
    if current.is_file():
        try:
            previous = json.loads(current.read_text(encoding='utf-8-sig'))
        except (ValueError, UnicodeDecodeError):
            previous = {}
        if previous.get('stack', {}).get('namespace') != value['stack']['namespace']:
            backup = RESULTS / (current.stem + '-previous-' + uuid.uuid4().hex + '.json')
            backup.write_bytes(current.read_bytes())
    current.write_text(serialized, encoding='utf-8')


def ensure_folder(client, path: str) -> None:
    current = ''
    for part in path.split('/'):
        require(part not in ('', '.', '..') and '\\' not in part, 'The demo destination path is invalid.')
        current = (current + '/' + part).lstrip('/')
        code, _ = client.dav('MKCOL', current)
        require(code in (201, 405), 'Could not create the local demo fixture directory.')
        require(client.properties(current).find('.//{DAV:}collection') is not None,
                'A demo directory path is occupied by a regular file.')


def create_demo_if_missing(docker, report) -> None:
    found = False
    for offset in range(0, 10000, 500):
        raw = occ(docker, 'user:list', '--output=json', '--limit=500', '--offset=' + str(offset))
        try:
            existing = json.loads(raw)
        except ValueError:
            existing = json_object(raw)
        require(isinstance(existing, dict) or existing == [], 'The local user lookup returned an unexpected response.')
        if DEMO_USER in existing:
            found = True
            break
        if len(existing) < 500:
            break
    else:
        raise H['TestFailure']('The local demo account lookup exceeded its bounded user budget.')
    if not found:
        docker.run(['exec', '-T', '--interactive=false', '--user', 'www-data', '--env', 'OC_PASS=' + DEMO_PASSWORD,
                    'nextcloud', 'php', 'occ', 'user:add', '--password-from-env', '--display-name', 'Archive Tools Demo', DEMO_USER])
        occ(docker, 'user:setting', DEMO_USER, 'core', 'lang', 'ru')
        report.check('public local demo account created', True)
    else:
        report.check('existing local demo account retained without password reset', True)


def extraction_bytes_match(client, extraction: dict) -> bool:
    for member in extraction['members']:
        code, content = client.dav('GET', extraction['folderPath'] + '/' + member['path'])
        if code != 200 or len(content) != member['bytes'] or hashlib.sha256(content).hexdigest() != member['sha256']:
            return False
    return True


def extract_sample(docker, client, extraction: dict, paths: dict[str, Path]) -> dict:
    if paths['extraction'].is_file():
        previous = json.loads(paths['extraction'].read_text(encoding='utf-8-sig'))
        if (previous.get('stack', {}).get('namespace') == extraction['stack']['namespace']
                and previous.get('sourceId') == extraction['sourceId'] and previous.get('status') == 'succeeded'
                and previous.get('folderPath') == extraction['folderPath'] and extraction_bytes_match(client, extraction)):
            return previous
    code, _ = client.dav('PROPFIND', extraction['folderPath'])
    require(code == 404, 'The sample extraction folder already exists without a matching completed manifest; it will not be overwritten.')
    payload = {'destinationId': extraction['destinationId'], 'folderName': extraction['folderName'],
               'expectedEtag': extraction['sourceEtag'], 'password': extraction['fixturePassword']}
    code, job = client.api('POST', '/archives/' + str(extraction['sourceId']) + '/extract', payload)
    require(code == 202 and isinstance(job.get('id'), int), 'The local sample extraction could not be queued.')
    pending = dict(extraction, id=job['id'], status=job['status'])
    write_artifact(paths['extraction'], pending, 'demo-extraction.json')
    try:
        M['f']['execute_job'](docker, job['id'], allow_missing=True)
    except H['TestFailure']:
        code, current = client.api('GET', '/jobs/' + str(job['id']))
        if code != 200 or current.get('status') not in ('running', 'succeeded'):
            raise
    deadline = time.monotonic() + 120
    while time.monotonic() < deadline:
        code, current = client.api('GET', '/jobs/' + str(job['id']))
        require(code == 200, 'The local sample task became unavailable.')
        if current['status'] == 'succeeded':
            result = dict(extraction, **{key: value for key, value in current.items() if key not in ('members', 'sourceId', 'sourceName', 'destinationId', 'folderName')})
            require(extraction_bytes_match(client, result), 'The completed demo extraction bytes differ from the fixture.')
            return result
        require(current['status'] in ('queued', 'running'), 'The sample extraction failed: ' + str(current.get('message', current['status'])))
        time.sleep(0.2)
    raise H['TestFailure']('The sample extraction did not finish within 120 seconds.')


def run(args, report) -> dict:
    docker = H['Docker'](report)
    docker.assert_test_stack()
    stack = runtime_identity(docker)
    version = manifest_version()
    paths = artifact_paths(stack)
    create_demo_if_missing(docker, report)
    client = H['Client'](BASE_URL, DEMO_USER, DEMO_PASSWORD, report)
    client.csrf()
    code, _ = client.api('GET', '/jobs')
    require(code == 200, 'The existing local demo account does not accept its public fixture password; its password was not reset.')
    formats, _ = http_initial_state(client)
    require({'zip', 'tar', 'tar.gz', '7z', 'rar'} <= set(formats), 'The current HTTP runtime does not advertise all demo archive backends.')
    folder = 'Архивы/Новые форматы ' + version
    ensure_folder(client, folder)
    files = {'Прочитайте.txt': 'Просмотр архива внутри Файлов Nextcloud.\n'.encode(),
             'Вложенная папка/пример.txt': 'Поддерживаются общие папки и WebDAV.\n'.encode()}
    raw = M['tar_bytes'](files)
    fixtures = [('Пример.zip', 'zip', M['f']['zip_bytes'](files), files, None),
                ('Пример.tar', 'tar', raw, files, None), ('Пример.tar.gz', 'tar.gz', gzip.compress(raw), files, None)]
    for action, filename in [('7z', 'Пример.7z'), ('7z-encrypted', 'Защищённые имена.7z'), ('rar', 'Пример.rar'), ('rar5', 'Пример RAR5.rar')]:
        value = json.loads(docker.run(['exec', '-T', '--interactive=false', '--user', 'www-data', 'nextcloud',
                                       'php', '/opt/archive-tools-tests/format-fixtures.php', action]))
        fixtures.append((filename, 'rar' if action.startswith('rar') else '7z', base64.b64decode(value['base64']),
                         {name: base64.b64decode(content) for name, content in value['files'].items()}, value.get('password')))
    previous = json.loads(paths['formats'].read_text(encoding='utf-8-sig')) if paths['formats'].is_file() else {}
    known = {item['path']: item for item in previous.get('files', [])} if previous.get('stack', {}).get('namespace') == stack['namespace'] else {}
    result = {'schemaVersion': 1, 'stack': stack, 'appVersion': version, 'demoUser': DEMO_USER,
              'createdAtUtc': datetime.now(timezone.utc).isoformat(), 'folderPath': folder,
              'folderId': client.file_id(folder), 'httpArchiveFormats': formats, 'files': []}
    for filename, archive_format, value, expected, password in fixtures:
        path = folder + '/' + filename
        existing, content = client.dav('GET', path)
        if existing == 200:
            old = known.get(path)
            require(old is not None and hashlib.sha256(content).hexdigest() == old.get('archiveSha256')
                    and old.get('members') == members_manifest(expected),
                    'A demo source path already contains data not owned by its current manifest; it will not be overwritten.')
            value = content
        else:
            require(existing == 404, 'The existing demo source could not be checked safely.')
            require(client.dav('PUT', path, data=value)[0] == 201, 'Could not create the local demo archive.')
        source = client.file_id(path)
        code, info = client.api('POST', '/archives/' + str(source) + '/inspect', {'password': password} if password else {})
        require(code == 200 and info.get('format') == archive_format, 'A seeded archive did not match its expected reader.')
        item = {'name': filename, 'path': path, 'format': archive_format, 'sourceId': source,
                'sourceEtag': info['sourceEtag'], 'encrypted': bool(password),
                'archiveSha256': hashlib.sha256(value).hexdigest(), 'members': members_manifest(expected)}
        if password:
            item['fixturePassword'] = password
        result['files'].append(item)
    encrypted = next(item for item in result['files'] if item.get('fixturePassword'))
    extraction = {'schemaVersion': 1, 'stack': stack, 'appVersion': version, 'demoUser': DEMO_USER,
                  'status': 'planned', 'sourceId': encrypted['sourceId'], 'sourceName': encrypted['name'],
                  'sourcePath': encrypted['path'], 'sourceEtag': encrypted['sourceEtag'],
                  'fixturePassword': encrypted['fixturePassword'], 'destinationId': result['folderId'],
                  'destinationPath': folder, 'folderName': 'Извлечено из 7z', 'folderPath': folder + '/Извлечено из 7z',
                  'members': encrypted['members']}
    write_artifact(paths['formats'], result, 'demo-formats.json')
    if args.extract_sample:
        extraction = extract_sample(docker, client, extraction, paths)
    elif paths['extraction'].is_file():
        previous_sample = json.loads(paths['extraction'].read_text(encoding='utf-8-sig'))
        if (previous_sample.get('stack', {}).get('namespace') == stack['namespace']
                and previous_sample.get('status') == 'succeeded' and previous_sample.get('sourceId') == extraction['sourceId']
                and previous_sample.get('folderPath') == extraction['folderPath'] and extraction_bytes_match(client, extraction)):
            extraction = previous_sample
    write_artifact(paths['extraction'], extraction, 'demo-extraction.json')
    result['artifacts'] = {name: str(path.relative_to(ROOT)) for name, path in paths.items()}
    result['sampleExtraction'] = extraction
    return result


def main() -> int:
    if hasattr(sys.stdout, 'reconfigure'):
        sys.stdout.reconfigure(encoding='utf-8')
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--extract-sample', action='store_true', help='Execute and verify the seeded sample extraction through the native queue.')
    args = parser.parse_args()
    report = H['Report']('demo-' + uuid.uuid4().hex)
    report.secrets.extend([DEMO_PASSWORD, ARCHIVE_PASSWORD])
    try:
        result = run(args, report)
    except Exception as error:
        print(json.dumps({'status': 'failed', 'error': report.redact(str(error))}, ensure_ascii=True))
        return 1
    print(json.dumps({'status': 'passed', **result}, ensure_ascii=True))
    return 0


if __name__ == '__main__':
    sys.exit(main())
