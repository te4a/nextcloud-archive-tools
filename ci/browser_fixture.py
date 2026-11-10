"""Seed and describe one disposable browser account through the guarded CI transport."""
from __future__ import annotations

import base64
import io
import json
from pathlib import Path
import zipfile


def create_fixture(runner) -> Path:
    runner.docker.assert_test_stack()
    user = 'at_ci_browser_' + runner.config['token'][:12]
    runner.docker.add_user(user)
    try:
        runner.occ('user:setting', user, 'core', 'lang', 'en')
        client = runner.helper.Client(runner.config['baseUrl'], user, runner.password, runner.report)
        client.csrf()
        for folder in ('CI Archives', 'CI Destination'):
            runner.check('browser fixture directory ' + folder, client.dav('MKCOL', folder)[0] == 201)
        output = io.BytesIO()
        with zipfile.ZipFile(output, 'w', zipfile.ZIP_DEFLATED) as archive:
            archive.writestr('Nested Юникод/', b'')
            archive.writestr('Nested Юникод/notes.txt', b'nested browser fixture\n')
            archive.writestr('Знаки %2F #?/', b'')
            archive.writestr('Знаки %2F #?/literal.txt', b'literal percent path\n')
            archive.writestr('README.txt', b'Archive Tools browser fixture\n')
        plain = 'CI Archives/Browser.zip'
        runner.check('browser ZIP uploaded', client.dav('PUT', plain, data=output.getvalue())[0] == 201)
        protected = json.loads(runner.docker.run(['exec', '-T', '--user', 'www-data', 'nextcloud',
            'php', '/opt/archive-tools-tests/format-fixtures.php', '7z-encrypted']))
        encrypted = 'CI Archives/Protected.7z'
        runner.check('browser protected 7z uploaded', client.dav('PUT', encrypted, data=base64.b64decode(protected['base64']))[0] == 201)
        value = {'schemaVersion': 1, 'project': runner.config['project'], 'token': runner.config['token'],
            'baseUrl': runner.config['baseUrl'], 'user': user, 'password': runner.password,
            'archivePassword': protected['password'], 'sourceId': client.file_id(plain),
            'encryptedSourceId': client.file_id(encrypted), 'folderId': client.file_id('CI Archives'),
            'destinationId': client.file_id('CI Destination'), 'outputDirectory': str(runner.output),
            'ciConfig': str(runner.config_path)}
        manifest = runner.config_path.parent / 'browser-fixture.json'
        manifest.write_text(json.dumps(value) + '\n', encoding='utf-8')
        manifest.chmod(0o600)
        return manifest
    except BaseException:
        runner.docker.delete_user(user)
        raise
