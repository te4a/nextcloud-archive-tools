"""Seed and describe one disposable browser account through the guarded CI transport."""
from __future__ import annotations

import base64
import io
import json
from pathlib import Path
import struct
import zipfile
import zlib


def preview_png() -> bytes:
    def chunk(kind: bytes, data: bytes) -> bytes:
        return struct.pack('>I', len(data)) + kind + data + struct.pack('>I', zlib.crc32(kind + data))

    return (b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', 1, 1, 8, 2, 0, 0, 0))
            + chunk(b'IDAT', zlib.compress(b'\x00\x2b\x81\xd6')) + chunk(b'IEND', b''))


def preview_pdf() -> bytes:
    result = bytearray(b'%PDF-1.4\n')
    offsets = [0]
    drawing = b'10 10 30 30 re f\n'
    for index, body in enumerate((
        b'<< /Type /Catalog /Pages 2 0 R >>',
        b'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        b'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] /Contents 4 0 R >>',
        f'<< /Length {len(drawing)} >>\nstream\n'.encode() + drawing + b'endstream',
    ), start=1):
        offsets.append(len(result))
        result.extend(f'{index} 0 obj\n'.encode() + body + b'\nendobj\n')
    xref = len(result)
    result.extend(b'xref\n0 5\n0000000000 65535 f \n')
    for offset in offsets[1:]:
        result.extend(f'{offset:010} 00000 n \n'.encode())
    result.extend(f'trailer\n<< /Size 5 /Root 1 0 R >>\nstartxref\n{xref}\n%%EOF\n'.encode())
    return bytes(result)


# Four seconds of 8×8 color frames encoded as VP9 with WebM seeking metadata.
PREVIEW_WEBM = base64.b64decode(
    'GkXfo59ChoEBQveBAULygQRC84EIQoKEd2VibUKHgQJChYECGFOAZwEAAAAAAAKqEU2bdLpNu4tTq4QVSalmU6yBoU27'
    'i1OrhBZUrmtTrIHWTbuMU6uEElTDZ1OsggEnTbuMU6uEHFO7a1OsggKU7AEAAAAAAABZAAAAAAAAAAAAAAAAAAAAAAAA'
    'AAAAAAAAAAAAAAAA'
    'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAVSalmsCrX'
    'sYMPQkBNgIxMYXZmNjEuMS4xMDBXQYxMYXZmNjEuMS4xMDBEiYhAr0AAAAAAABZUrmvMrgEAAAAAAABD14EBc8WIowwK'
    'LwrgvxOcgQAitZyDdW5kiIEAhoVWX1ZQOYOBASPjg4QdzWUA4JSwgQi6gQiagQJVsIhVt4ECVbiBAhJUw2f+c3OfY8CA'
    'Z8iZRaOHRU5DT0RFUkSHjExhdmY2MS4xLjEwMHNz2WPAi2PFiKMMCi8K4L8TZ8ikRaOHRU5DT0RFUkSHl0xhdmM2MS4z'
    'LjEwMCBsaWJ2cHgtdnA5Z8ihRaOIRFVSQVRJT05Eh5MwMDowMDowNC4wMDAwMDAwMDAAH0O2dUDk54EAo6mBAACAgkmD'
    'QgAAcAB2ADgkHBhKAAAgIAARv///XHX////+vlP//7PgAKOTgQH0AIYAQJKcAFAAAAMgAABLQKOegQPoAIYAQJKcAE7g'
    'AAMAAAABTH7khTf8K///c4AAo5OBBdwAhgBAkpwAUAAAAyAAAEtAo5+BB9AAhgBAkpwATUAAAyAAAAC5//2QH///8ID/'
    '9IAAo5OBCcQAhgBAkpwAUAAAAyAAAEtAo5+BC7gAhgBAkpwATuAAAyAAAAC5/+Fl//7x///+XwAAo5OBDawAhgBAkpwA'
    'UAAAAyAAAEtAHFO7a5G7j7OBALeK94EB8YIBqvCBAw=='
)


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
            archive.writestr('Preview #%.png', preview_png())
            archive.writestr('Preview #%.pdf', preview_pdf())
            archive.writestr('Preview.webm', PREVIEW_WEBM)
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
