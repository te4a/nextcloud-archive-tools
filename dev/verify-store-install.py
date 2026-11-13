#!/usr/bin/env python3
"""Install a signed Archive Tools package through a disposable Nextcloud App Store."""
from __future__ import annotations

import argparse
import base64
import binascii
import json
import os
from pathlib import Path, PurePosixPath
import re
import secrets
import shutil
import sys
import tarfile
import time
import urllib.parse
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
sys.dont_write_bytecode = True
sys.path.insert(0, str(ROOT / 'ci'))
from process import run_process

APP_ID = 'archive_tools'
APP_PATH = '/var/www/html/custom_apps/archive_tools'
STORE_PATH = '/tmp/archive-tools-mock-store'
STORE_URL = 'http://127.0.0.1:18081'
LABEL = 'com.archive-tools.store-smoke'
IMAGE = re.compile(r'nextcloud:(34|35)(?:\.\d+\.\d+)?-apache(?:@sha256:[0-9a-f]{64})?')
BUNDLES = ('7zz-linux-x64', '7zz-linux-arm64')
PRIVATE_PEM = re.compile(rb'(?m)^-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----[ \t]*\r?$')
PUBLIC_CERTIFICATE = re.compile(r'-----BEGIN CERTIFICATE-----\r?\n[A-Za-z0-9+/=\r\n]+-----END CERTIFICATE-----')

PHP_PROBE = r"""declare(strict_types=1);
$app = '/var/www/html/custom_apps/archive_tools';
spl_autoload_register(static function (string $class) use ($app): void {
    $prefix = 'OCA\\ArchiveTools\\Archive\\';
    if (str_starts_with($class, $prefix)) {
        $name = substr($class, strlen($prefix));
        if (preg_match('/^[A-Za-z][A-Za-z0-9]*$/D', $name)) {
            require_once $app . '/lib/Archive/' . $name . '.php';
        }
    }
});
$reader = new \OCA\ArchiveTools\Archive\ArchiveReader();
$formats = $reader->supportedFormats();
if (!in_array('7z', $formats, true) || !in_array('rar', $formats, true)) {
    throw new RuntimeException('The installed archive reader does not advertise both formats.');
}
$name = match (php_uname('m')) {
    'x86_64' => '7zz-linux-x64',
    'aarch64' => '7zz-linux-arm64',
    default => throw new RuntimeException('Unsupported test architecture.'),
};
$binary = $app . '/bin/' . $name;
clearstatcache(true, $binary);
if (!is_executable($binary)) {
    throw new RuntimeException('The installed bundled decoder is still not executable.');
}
echo 'PASS bundled decoder is executable: ' . $name . PHP_EOL;

$input = '/tmp/archive-tools-store-hello.txt';
$sevenZip = '/tmp/archive-tools-store-fixture.7z';
$contents = "Archive Tools 7z smoke test\n";
file_put_contents($input, $contents);
$process = proc_open(
    [$binary, 'a', '-t7z', '-bd', '-bso0', '-bse2', '--', $sevenZip, $input],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes,
);
if (!is_resource($process) || proc_close($process) !== 0) {
    throw new RuntimeException('The bundled decoder could not create the public 7z fixture.');
}
foreach ([
    ['7z', $sevenZip, basename($input), $contents],
    ['RAR 4', '/tmp/archive-tools-store-fixture.rar', 'test.txt', "test text document\r\n"],
    ['RAR 5', '/tmp/archive-tools-store-fixture-rar5.rar', 'helloworld.txt', "hello libarchive test suite!\n"],
] as [$format, $path, $member, $expected]) {
    $index = $reader->inspect($path);
    if ($index['fileCount'] !== 1 || !isset($index['entries'][$member])) {
        throw new RuntimeException($format . ' fixture metadata differs.');
    }
    $stream = $reader->stream($path, $index['entries'][$member]['originalName']);
    try {
        if (stream_get_contents($stream) !== $expected) {
            throw new RuntimeException($format . ' fixture member bytes differ.');
        }
    } finally {
        fclose($stream);
    }
    echo 'PASS ' . $format . ' metadata and member bytes' . PHP_EOL;
}
"""


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


def local_endpoint(value: str) -> bool:
    if value.startswith(('npipe://', 'unix://')):
        return True
    endpoint = urllib.parse.urlsplit(value)
    return endpoint.scheme in ('tcp', 'http', 'https') and endpoint.hostname in ('127.0.0.1', 'localhost', '::1')


def package_catalog(package: Path, signature_file: Path) -> tuple[list[dict], str]:
    require(package.is_file() and package.stat().st_size <= 20_000_000,
            'The package must be a local tar.gz within the App Store size limit.')
    require(signature_file.is_file() and signature_file.stat().st_size <= 16384,
            'The detached signature must be a small Base64 text file.')
    signature = ''.join(signature_file.read_text(encoding='utf-8-sig').split())
    require(128 <= len(base64.b64decode(signature, validate=True)) <= 1024,
            'The detached archive signature has an unexpected length.')

    seen = set()
    metadata = {}
    decoder_modes = {}
    expanded = 0
    with tarfile.open(package, 'r:gz') as archive:
        for index, entry in enumerate(archive):
            path = PurePosixPath(entry.name)
            require(index < 10000 and path.parts and path.parts[0] == APP_ID
                    and entry.name.rstrip('/') == path.as_posix() and '..' not in path.parts
                    and '\\' not in entry.name and ':' not in entry.name and '\0' not in entry.name,
                    'The package contains an unsafe path or too many entries.')
            key = path.as_posix().casefold()
            require(key not in seen and (entry.isdir() or entry.isfile()) and not entry.sparse,
                    'The package contains a link, a special file or a duplicate.')
            seen.add(key)
            for part in path.parts:
                name = part.lower()
                require(not name.startswith(('.env', '.test-secrets')) and not name.endswith(('.key', '.p12', '.pfx', '.pkcs12')),
                        'The package contains a probable secret file.')
            if not entry.isfile():
                continue
            expanded += entry.size
            require(expanded <= 80_000_000, 'The expanded package exceeds the smoke test limit.')
            content = archive.extractfile(entry).read()
            require(PRIVATE_PEM.search(content) is None, 'The package contains a private PEM key.')
            if path.parent.as_posix() == f'{APP_ID}/bin' and path.name in BUNDLES:
                require(content.startswith(b'\x7fELF'), 'A bundled decoder is not an ELF binary.')
                decoder_modes[path.name] = entry.mode
            if path.as_posix() in (f'{APP_ID}/appinfo/info.xml', f'{APP_ID}/appinfo/signature.json'):
                require(entry.size < 512_000, 'The package metadata exceeds the smoke test size limit.')
                metadata[path.name] = content

    require(decoder_modes == dict.fromkeys(BUNDLES, 0o755),
            'The package must contain both bundled decoders with mode 0755.')
    require('info.xml' in metadata and 'signature.json' in metadata,
            'The package must contain its native integrity signature and metadata.')
    manifest = ET.fromstring(metadata['info.xml'])
    version = manifest.findtext('version', '')
    require(manifest.findtext('id') == APP_ID and re.fullmatch(r'\d+\.\d+\.\d+(?:-[a-zA-Z0-9.-]+)?', version),
            'The package metadata does not identify an Archive Tools release.')
    cert = json.loads(metadata['signature.json'])['certificate'].strip()
    require(PUBLIC_CERTIFICATE.fullmatch(cert), 'The package does not have a public PEM certificate.')
    release = {'version': version, 'download': STORE_URL + '/package.tar.gz',
               'signature': signature, 'isNightly': False}
    for dependency, target in [('nextcloud', 'rawPlatformVersionSpec'), ('php', 'rawPhpVersionSpec')]:
        node = manifest.find('dependencies/' + dependency)
        if node is None:
            raise RuntimeError(f'The package has no {dependency} dependency declaration.')
        release[target] = ' '.join(op + node.attrib[attribute]
                                   for op, attribute in [('>=', 'min-version'), ('<=', 'max-version')]
                                   if attribute in node.attrib) or '*'
    return [{'id': APP_ID, 'certificate': cert, 'releases': [release]}], version


class Docker:
    def __init__(self) -> None:
        self.binary = shutil.which('docker')
        require(self.binary is not None, 'Docker is unavailable.')
        override = os.environ.get('DOCKER_HOST')
        require(not override or local_endpoint(override), 'Remote Docker hosts are not allowed.')
        context = self.run('inspect Docker context', 'context', 'inspect', '--format', '{{json .Endpoints.docker.Host}}')
        require(local_endpoint(json.loads(context)), 'The active Docker context is not local.')

    def run(self, phase: str, *args: str, timeout: int = 120, log: bool = False) -> str:
        result = run_process([self.binary, *args], cwd=ROOT, timeout=timeout)
        if result.returncode != 0:
            detail = (result.stderr + result.stdout).strip()[:1400] if log else ''
            raise RuntimeError(phase + ' failed' + (f': {detail}' if detail else '.'))
        return result.stdout.strip()

    def execute(self, container: str, phase: str, *args: str, timeout: int = 120, log: bool = False) -> str:
        return self.run(phase, 'exec', '--user', 'www-data', '--workdir', '/var/www/html',
                        container, *args, timeout=timeout, log=log)

    def occ(self, container: str, *args: str, log: bool = True) -> str:
        return self.execute(container, args[0], 'php', 'occ', *args, log=log)

    def write_public_bytes(self, container: str, path: str, data: bytes) -> None:
        code = 'exit(file_put_contents($argv[1], base64_decode($argv[2], true)) === false ? 1 : 0);'
        self.execute(container, 'write public mock-store input',
                     'php', '-r', code, path, base64.b64encode(data).decode('ascii'))


def wait_ready(probe, phase: str, seconds: int = 120) -> None:
    deadline = time.monotonic() + seconds
    while time.monotonic() < deadline:
        try:
            probe()
            return
        except RuntimeError:
            time.sleep(1)
    raise RuntimeError(phase + ' did not become ready.')


def read_uuencode(name: str) -> bytes:
    lines = (ROOT / 'tests' / 'fixtures' / name).read_bytes().splitlines()
    require(lines[0].startswith(b'begin ') and lines[-1] == b'end', 'The public RAR fixture is invalid.')
    return b''.join(binascii.a2b_uu(line) for line in lines[1:-1])


def decoder_modes(docker: Docker, container: str) -> dict[str, str]:
    paths = [f'{APP_PATH}/bin/{binary}' for binary in BUNDLES]
    modes = docker.execute(container, 'read decoder modes', 'stat', '-c', '%a', *paths).splitlines()
    require(len(modes) == len(BUNDLES) and all(re.fullmatch(r'[0-7]{3}', mode) for mode in modes),
            'Could not determine installed executable modes.')
    return dict(zip(BUNDLES, modes))


def verify(args) -> None:
    catalog, version = package_catalog(args.package, args.signature)
    docker = Docker()
    token = secrets.token_hex(16)
    name = 'archive-tools-store-smoke-' + token
    container = None
    try:
        platform_option = ['--platform', args.platform] if args.platform else []
        container = docker.run('create disposable Nextcloud container', 'create', '--pull', 'never',
                               '--network', 'none', '--name', name, '--label', f'{LABEL}={token}',
                               *platform_option, args.image, log=True)
        require(re.fullmatch(r'[0-9a-f]{64}', container), 'Docker did not return a container ID.')
        docker.run('start disposable Nextcloud container', 'start', container)
        wait_ready(lambda: docker.execute(container, 'read Nextcloud setup status',
                                          'curl', '-fsS', '--max-time', '2', 'http://127.0.0.1/status.php', timeout=7),
                   'Nextcloud setup')
        docker.occ(container, 'maintenance:install', '--database', 'sqlite', '--admin-user', 'store-smoke-admin',
                   '--admin-pass', secrets.token_hex(24), log=False)
        no_system_decoder = (
            'foreach (["/usr/bin/7zz", "/usr/local/bin/7zz", "/usr/bin/7z", "/usr/local/bin/7z"] as $path) {'
            'if (file_exists($path) || is_link($path)) { exit(1); }}'
        )
        docker.execute(container, 'check absence of system 7-Zip', 'php', '-r', no_system_decoder)
        docker.execute(container, 'check PATH for system 7-Zip', 'sh', '-c',
                       '! command -v 7zz >/dev/null && ! command -v 7z >/dev/null && ! command -v 7za >/dev/null')
        print('PASS clean Nextcloud without system 7-Zip', flush=True)

        docker.execute(container, 'create mock App Store directory', 'mkdir', STORE_PATH)
        docker.write_public_bytes(container, STORE_PATH + '/apps.json', json.dumps(catalog).encode())
        docker.run('copy signed package into mock App Store', 'cp', str(args.package.resolve()),
                   container + ':' + STORE_PATH + '/package.tar.gz')
        docker.run('start loopback mock App Store', 'exec', '--detach', '--user', 'www-data', container,
                   'php', '-S', '127.0.0.1:18081', '-t', STORE_PATH)
        wait_ready(lambda: docker.execute(container, 'read mock App Store catalog',
                                          'curl', '-fsS', '--max-time', '2', '-o', '/dev/null',
                                          STORE_URL + '/apps.json', timeout=7), 'Mock App Store')
        docker.occ(container, 'config:system:set', 'appstoreurl', '--value=' + STORE_URL)
        docker.occ(container, 'config:system:set', 'allow_local_remote_servers', '--type=boolean', '--value=true')
        install = docker.occ(container, 'app:install', '--allow-unstable', APP_ID)
        require(f'{APP_ID} {version} installed' in install and f'{APP_ID} enabled' in install,
                'Nextcloud did not report the exact release installed and enabled.')
        print(f'PASS native App Store installer installed and enabled {APP_ID} {version}', flush=True)

        before = decoder_modes(docker, container)
        print('Decoder modes after native installation: ' + json.dumps(before), flush=True)
        report = docker.occ(container, 'integrity:check-app', APP_ID, '--verbose')
        require('No errors found' in report and 'skipping' not in report.lower(),
                'Nextcloud skipped or failed its native app signature check.')
        print('PASS native integrity check before first decoder use', flush=True)

        for name, target in [('test_read_format_rar_subblock.rar.uu', 'fixture.rar'),
                             ('test_read_format_rar5_stored.rar.uu', 'fixture-rar5.rar')]:
            docker.write_public_bytes(container, '/tmp/archive-tools-store-' + target, read_uuencode(name))
        results = docker.execute(container, 'read installed 7z and RAR fixtures', 'php', '-r', PHP_PROBE, log=True)
        print(results, flush=True)
        first_line = results.splitlines()[0]
        selected = first_line.removeprefix('PASS bundled decoder is executable: ')
        require(selected in BUNDLES, 'The reader did not identify a bundled executable.')
        if args.platform:
            require(selected == {'linux/amd64': BUNDLES[0], 'linux/arm64': BUNDLES[1]}[args.platform],
                    'The selected bundled executable does not match the requested platform.')
        after = decoder_modes(docker, container)
        expected = {**before, selected: '755'}
        require(after == expected, 'Only the selected bundled decoder may gain execute permissions.')
        print('Decoder modes after reading fixtures: ' + json.dumps(after), flush=True)
        report = docker.occ(container, 'integrity:check-app', APP_ID, '--verbose')
        require('No errors found' in report and 'skipping' not in report.lower(),
                'Nextcloud skipped or failed its post-run native app signature check.')
        print('PASS native integrity check after decoder use', flush=True)
    finally:
        if container and re.fullmatch(r'[0-9a-f]{64}', container):
            owner = docker.run('verify disposable container ownership', 'inspect',
                               '--format', '{{index .Config.Labels "' + LABEL + '"}}', container)
            require(owner == token, 'Refusing to remove an unowned Docker container.')
            docker.run('remove disposable container and its anonymous volume', 'rm', '--force', '--volumes', container)


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--image', required=True, help='Local official nextcloud:34/35 Apache image reference.')
    parser.add_argument('--platform', choices=('linux/amd64', 'linux/arm64'))
    parser.add_argument('--package', required=True, type=Path, help='Final signed tar.gz package.')
    parser.add_argument('--signature', required=True, type=Path, help='Detached Base64 SHA-512 signature file.')
    args = parser.parse_args()
    if IMAGE.fullmatch(args.image) is None:
        parser.error('--image must name an official Nextcloud 34 or 35 Apache image.')
    try:
        verify(args)
    except (RuntimeError, OSError, ValueError, KeyError, ET.ParseError, tarfile.TarError) as error:
        parser.exit(1, f'FAIL {error}\n')


if __name__ == '__main__':
    main()
