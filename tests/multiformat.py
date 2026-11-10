#!/usr/bin/env python3
"""Exercise every supported archive reader through Files APIs and shared WebDAV."""
from __future__ import annotations

import base64
import bz2
import gzip
import io
import json
import lzma
from pathlib import Path
import runpy
import sys
import tarfile
import time
import uuid

ROOT = Path(__file__).resolve().parents[1]
f = runpy.run_path(str(ROOT / 'tests' / 'features.py'))
h = f['helpers']


class FormatReport(h['Report']):
    def write(self, status, error=None):
        self.data['status'] = status
        self.data['elapsedSeconds'] = round(time.monotonic() - self.started, 2)
        if error is not None:
            self.data['error'] = self.redact(str(error))
        out = h['report_directory']()
        out.mkdir(exist_ok=True)
        value = self.redact(json.dumps(self.data, ensure_ascii=False, indent=2)) + '\n'
        (out / (self.data['runId'] + '.json')).write_text(value, encoding='utf-8')
        (out / 'latest-multiformat.json').write_text(value, encoding='utf-8')
        print(json.dumps({'status': status, 'checks': len(self.data['checks']), 'error': self.data.get('error')}, ensure_ascii=True))


def tar_bytes(files):
    output = io.BytesIO()
    with tarfile.open(fileobj=output, mode='w', format=tarfile.PAX_FORMAT) as archive:
        for name, value in files.items():
            entry = tarfile.TarInfo(name)
            entry.size = len(value)
            entry.mtime = 1700000000
            archive.addfile(entry, io.BytesIO(value))
    return output.getvalue()


def run():
    report = FormatReport('multiformat-' + time.strftime('%Y%m%d%H%M%S', time.gmtime()) + '_' + uuid.uuid4().hex[:6])
    secret = h['read_environment']()['ARCHIVE_TOOLS_ADMIN_PASSWORD']
    report.secrets.append(secret)
    docker = h['Docker'](report)
    docker.assert_test_stack()
    users = []
    completed = False
    try:
        clients = []
        for role in ('owner', 'reader'):
            user = 'at_' + report.data['runId'].replace('-', '_') + '_' + role
            docker.add_user(user)
            users.append(user)
            client = h['Client'](h['test_base_url'](), user, secret, report)
            client.csrf()
            clients.append(client)
        owner, reader = clients
        report.data['fixtureUsers'] = {'owner': owner.user, 'reader': reader.user}
        report.check('shared archive source folder created', owner.dav('MKCOL', 'Formats')[0] == 201)
        owner.ocs('POST', payload={'path': '/Formats', 'shareType': 0, 'shareWith': reader.user, 'permissions': 1})
        destination = reader.file_id('')
        source_files = {'one.txt': b'one\n', 'nested/два.txt': 'два\n'.encode(), 'Знаки %2F #?/100%.txt': b'complex name\n'}
        raw = tar_bytes(source_files)
        fixtures = [('tar', raw, source_files, None), ('tar.gz', gzip.compress(raw), source_files, None),
                    ('tar.bz2', bz2.compress(raw), source_files, None), ('tar.xz', lzma.compress(raw), source_files, None)]
        for action, extension in [('7z', '7z'), ('7z-encrypted', '7z'), ('rar', 'rar'), ('rar5', 'rar')]:
            value = json.loads(docker.run(['exec', '-T', '--user', 'www-data', 'nextcloud', 'php',
                                           '/opt/archive-tools-tests/format-fixtures.php', action]))
            files = {name: base64.b64decode(content) for name, content in value['files'].items()}
            fixtures.append((extension, base64.b64decode(value['base64']), files, value.get('password')))
        for number, (extension, archive, files, password) in enumerate(fixtures):
            label = str(number) + '-' + extension
            if password:
                report.secrets.append(password)
            source_path = 'Formats/sample-' + str(number) + '.' + extension
            report.check(label + ' source uploaded', owner.dav('PUT', source_path, data=archive)[0] == 201)
            source = owner.file_id(source_path)
            if password:
                status, locked = reader.api('POST', f'/archives/{source}/inspect', {})
                report.check(label + ' encrypted headers request a password', status == 422 and locked.get('code') == 'password_required')
                status, locked = reader.api('POST', f'/archives/{source}/inspect', {'password': 'incorrect-format-test'})
                report.check(label + ' wrong header password is rejected', status == 422 and locked.get('code') == 'password_required')
            payload = {'password': password} if password else {}
            status, info = reader.api('POST', f'/archives/{source}/inspect', payload)
            report.check(label + ' shared archive inspected', status == 200 and info.get('fileCount') == len(files))
            report.check(label + ' format detected from bytes', info['format'] == extension)
            report.check(label + ' all file paths retained', set(files) <= {entry['path'] for entry in info['entries']})
            member, expected = next(iter(files.items()))
            body = dict(payload, member=member, expectedEtag=info['sourceEtag'])
            status, content = reader.request('POST', h['API'] + f'/archives/{source}/download', data=json.dumps(body).encode(),
                                             headers={'Content-Type': 'application/json', 'requesttoken': reader.token})
            report.check(label + ' member streamed with exact bytes', status == 200 and content == expected)
            status, job = reader.api('POST', f'/archives/{source}/extract', dict(payload, destinationId=destination,
                                      folderName='Result-' + str(number), expectedEtag=info['sourceEtag']))
            report.check(label + ' native extraction queued', status == 202)
            f['execute_job'](docker, int(job['id']))
            status, job = reader.api('GET', f'/jobs/{job["id"]}')
            report.check(label + ' native extraction succeeded', status == 200 and job['status'] == 'succeeded' and job['filesDone'] == len(files))
            for name, expected in files.items():
                report.check(label + ' extracted bytes ' + name, reader.dav('GET', 'Result-' + str(number) + '/' + name) == (200, expected))
            status, mount = owner.api('POST', '/mounts', dict(payload, sourceId=source, name='Mounted-' + str(number), expectedEtag=info['sourceEtag']))
            report.check(label + ' connected as folder', status in (200, 201))
            mount_id = int(mount['id'])
            for name, expected in files.items():
                report.check(label + ' shared mounted DAV bytes ' + name, reader.dav('GET', 'Formats/Mounted-' + str(number) + '/' + name) == (200, expected))
            report.check(label + ' mounted archive is read only', reader.dav('PUT', 'Formats/Mounted-' + str(number) + '/injected.txt', data=b'no')[0] in (403, 405, 423))
            report.check(label + ' disconnected', owner.api('DELETE', f'/mounts/{mount_id}')[0] in (200, 204))
            report.check(label + ' source retained', owner.dav('GET', source_path) == (200, archive))
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
