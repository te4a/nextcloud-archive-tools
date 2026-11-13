#!/usr/bin/env python3
"""Validate an Archive Tools package; --run tests its isolated Docker volume.

Restricted to archive-tools-dev on 127.0.0.1:18080. Never removes main containers,
volumes, users or fixture data. Extracted artifacts and package volumes are retained.
"""
from __future__ import annotations

import argparse
from dataclasses import dataclass
from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import runpy
import stat
import subprocess
import sys
import tarfile
import time
import urllib.parse
import uuid
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
RESULTS = ROOT / '.test-results'
APP_TARGET = '/var/www/html/custom_apps/archive_tools'
DEMO_USER = 'archive_demo'
# Deliberately public fixture credentials, already used by dev/seed-demo.py.
DEMO_PASSWORD = 'ArchiveTools-Local-Preview-2026!'
BASE_URL = 'http://127.0.0.1:18080'
ALLOWED_DIRS = {'appinfo', 'lib', 'templates', 'img', 'css', 'js', 'l10n', 'src', 'bin'}
EXECUTABLES = {'bin/7zz-linux-x64', 'bin/7zz-linux-arm64'}
LEGACY_METADATA_FILES = {'README.md', 'LICENSE', 'package.json', 'pnpm-lock.yaml',
                  'pnpm-workspace.yaml', 'tsconfig.json', 'vite.config.mjs', 'Dockerfile.nextcloud'}
RUNTIME_METADATA_FILES = {'README.md', 'LICENSE', 'CHANGELOG.md', 'SOURCE.md'}
METADATA_FILES = LEGACY_METADATA_FILES | RUNTIME_METADATA_FILES
H = runpy.run_path(str(ROOT / 'tests' / 'integration.py'))
SEED = runpy.run_path(str(ROOT / 'dev' / 'seed-demo.py'))
HELPER_PROJECT = 'archive-tools-dev-package-smoke'
HELPER_SERVICE = 'package-copy'
COPY_TARGET = '/var/www/html'


@dataclass(frozen=True)
class VolumeOrigin:
    name: str
    token: str


ApplicationOrigin = Path | VolumeOrigin


class SafeDocker(H['Docker']):
    """Keep the Docker parent alive until its whole Windows plugin tree is killed."""

    @staticmethod
    def _text(value) -> str:
        if value is None:
            return ''
        return value.decode('utf-8', errors='replace') if isinstance(value, bytes) else str(value)

    def _stop_tree(self, process: subprocess.Popen) -> str:
        if os.name == 'nt':
            # docker.exe launches docker-compose.exe. Killing only docker.exe
            # leaves the plugin holding communicate() pipes and possibly running
            # the pending mutation. Terminate descendants while the parent exists.
            taskkill = Path(os.environ.get('SystemRoot', r'C:\Windows')) / 'System32' / 'taskkill.exe'
            try:
                stopped = subprocess.run(
                    [str(taskkill), '/PID', str(process.pid), '/T', '/F'],
                    stdin=subprocess.DEVNULL, capture_output=True, text=True,
                    encoding='utf-8', errors='replace', timeout=15,
                    creationflags=subprocess.CREATE_NO_WINDOW,
                )
                if stopped.returncode == 0:
                    return ''
                message = stopped.stderr or stopped.stdout
            except BaseException as error:
                message = type(error).__name__ + ': ' + str(error)
            self.report.data['dockerProcessTreeCleanupFailed'] = True
            if process.poll() is None:
                process.kill()
            return 'Process-tree termination was not confirmed: ' + self.report.redact(message.strip())[:1000]
        if process.poll() is None:
            process.kill()
        return ''

    def run(self, arguments: list[str], *, compose: bool = True, timeout: int = 120) -> str:
        command = (self.prefix if compose else [self.binary]) + arguments
        process = None
        stdout = stderr = ''
        try:
            process = subprocess.Popen(
                command, cwd=ROOT, stdin=subprocess.DEVNULL,
                stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                text=True, encoding='utf-8', errors='replace',
                creationflags=subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0,
            )
            stdout, stderr = process.communicate(timeout=timeout)
        except BaseException as error:
            cleanup = ''
            if process is not None:
                cleanup = self._stop_tree(process)
                try:
                    stdout, stderr = process.communicate(timeout=10)
                except BaseException as capture_error:
                    stdout = self._text(getattr(capture_error, 'output', None)) or self._text(getattr(error, 'output', None))
                    stderr = self._text(getattr(capture_error, 'stderr', None)) or self._text(getattr(error, 'stderr', None))
                    # A failed process-tree termination must never cause another
                    # unbounded communicate() while unwinding the restore path.
                    for pipe in (process.stdout, process.stderr):
                        if pipe is not None:
                            pipe.close()
            reason = 'Docker command timed out after ' + str(timeout) + ' seconds.' if isinstance(error, subprocess.TimeoutExpired) else 'Docker command failed: ' + type(error).__name__ + '.'
            detail = self.report.redact(self._text(stderr).strip() or self._text(stdout).strip() or str(error))[:3000]
            if cleanup:
                reason += ' ' + cleanup
            if detail:
                reason += ' ' + detail
            raise H['TestFailure'](reason) from None
        if process.returncode != 0:
            detail = self.report.redact((stderr or stdout).strip())[:3000]
            raise H['TestFailure']('Docker command failed with exit code ' + str(process.returncode) + (': ' + detail if detail else '.'))
        return stdout.strip()


def require(condition: bool, message: str) -> None:
    if not condition:
        raise H['TestFailure'](message)


def plain(path: Path) -> None:
    """Reject reparse points before trusting any existing ancestor or input."""
    current = path
    while True:
        if current.exists() or current.is_symlink():
            information = current.lstat()
            require(not stat.S_ISLNK(information.st_mode)
                    and not (getattr(information, 'st_file_attributes', 0)
                             & getattr(stat, 'FILE_ATTRIBUTE_REPARSE_POINT', 0)),
                    'Package verification inputs must not contain links or junctions.')
        if current == ROOT or current.parent == current:
            return
        current = current.parent


def digest(path: Path) -> str:
    hasher = hashlib.sha256()
    with path.open('rb') as source:
        for data in iter(lambda: source.read(1048576), b''):
            hasher.update(data)
    return hasher.hexdigest()


def unpack_package(package: Path, report) -> tuple[Path, Path]:
    plain(ROOT / 'app')
    plain(RESULTS)
    plain(package)
    require(package.is_file() and package.parent.resolve() == (ROOT / 'dist').resolve(),
            'Only a package directly inside this workspace dist directory is allowed.')
    version = SEED['manifest_version']()
    require(package.name == 'archive_tools-' + version + '.tar.gz', 'Unexpected package filename.')
    actual_hash = digest(package)
    checksum_file = Path(str(package) + '.sha256')
    plain(checksum_file)
    require(checksum_file.is_file(), 'The package SHA-256 sidecar is missing.')
    sidecar = checksum_file.read_text(encoding='utf-8-sig').strip().split()
    require(len(sidecar) == 2 and sidecar[0].lower() == actual_hash
            and sidecar[1].lstrip('*') == package.name, 'The package SHA-256 sidecar differs.')
    report.check('package checksum matches sidecar', True)

    RESULTS.mkdir(exist_ok=True)
    extraction = RESULTS / ('package-smoke-' + uuid.uuid4().hex)
    require(not extraction.exists(), 'The smoke extraction directory already exists.')
    # Public application code needs the same inherited Windows ACL as app/.
    # mode=0700 can add a private DACL that Docker Desktop cannot share.
    extraction.mkdir()
    seen: set[str] = set()
    seen_folded: set[str] = set()
    package_files: set[str] = set()
    expanded = 0
    with tarfile.open(package, 'r:gz') as archive:
        for number, entry in enumerate(archive):
            require(number < 10000, 'The package contains too many entries.')
            name = entry.name.rstrip('/') if entry.isdir() else entry.name
            parsed = PurePosixPath(name)
            require(name != '' and '\\' not in name and '\0' not in name
                    and not parsed.is_absolute() and parsed.parts[0] == 'archive_tools'
                    and all(part not in ('', '.', '..') and ':' not in part
                            and not part.endswith((' ', '.')) for part in parsed.parts),
                    'The package contains an unsafe path.')
            require(name == '/'.join(parsed.parts), 'The package contains a noncanonical path.')
            require(name not in seen and name.casefold() not in seen_folded,
                    'The package contains duplicate or ambiguous paths.')
            seen.add(name)
            seen_folded.add(name.casefold())
            require(entry.isdir() or entry.isfile(), 'The package contains a link or special entry.')
            require(not entry.pax_headers.get('GNU.sparse.map') and not getattr(entry, 'sparse', None),
                    'Sparse package entries are not allowed.')
            relative = PurePosixPath(*parsed.parts[1:])
            if len(parsed.parts) > 1:
                require(relative.parts[0] in ALLOWED_DIRS or str(relative) in METADATA_FILES,
                        'The package contains an unexpected top-level item.')
            require(not any(part in {'.git', '.env', '.test-secrets.json', 'node_modules',
                                     'vendor', 'tests', 'dev', '__pycache__'}
                            or part.startswith('.env.') for part in parsed.parts),
                    'The package contains development or secret material.')
            target = extraction.joinpath(*parsed.parts)
            require(target.resolve().is_relative_to(extraction.resolve()), 'Package path escaped extraction.')
            if entry.isdir():
                target.mkdir(parents=True, exist_ok=True)
                continue
            if relative.parts[0] == 'bin':
                expected_mode = 0o755 if str(relative) in EXECUTABLES else 0o644
                require(entry.mode == expected_mode, 'A bundled 7-Zip file has an unexpected mode.')
            require(0 <= entry.size <= 67108864 and entry.size <= 268435456 - expanded,
                    'The package exceeds its verification byte budget.')
            expanded += entry.size
            target.parent.mkdir(parents=True, exist_ok=True)
            source = archive.extractfile(entry)
            require(source is not None, 'A package file could not be read.')
            copied = 0
            with source, target.open('xb') as destination:
                while data := source.read(1048576):
                    copied += len(data)
                    require(copied <= entry.size, 'A package file exceeds its declared size.')
                    destination.write(data)
            require(copied == entry.size, 'A package file is incomplete.')
            package_files.add(str(relative))
    package_root = extraction / 'archive_tools'
    require(package_root.is_dir() and (package_root / 'appinfo' / 'info.xml').is_file(),
            'The package does not have one complete archive_tools root.')
    runtime_profile = 'SOURCE.md' in package_files
    metadata_files = RUNTIME_METADATA_FILES if runtime_profile else LEGACY_METADATA_FILES
    current_files = []
    for path in (ROOT / 'app').rglob('*'):
        plain(path)
        if path.is_file():
            relative = path.relative_to(ROOT / 'app').as_posix()
            if relative == 'appinfo/signature.json' or (runtime_profile and relative.startswith('src/')):
                continue
            require(relative in package_files and digest(path) == digest(package_root / relative),
                    'Packaged application files differ from the tested workspace app.')
            current_files.append(relative)
    signatures = {'appinfo/signature.json'} & package_files
    require(package_files == set(current_files) | metadata_files | signatures,
            'The package has missing or unexpected application files.')
    installed_manifest = ET.parse(package_root / 'appinfo' / 'info.xml').getroot()
    require(installed_manifest.findtext('version') == version, 'The package manifest version differs.')
    if runtime_profile:
        for name in ('LICENSE', 'CHANGELOG.md'):
            plain(ROOT / name)
            require(digest(package_root / name) == digest(ROOT / name),
                    'Packaged licence or release notes differ from reviewed source.')
        source_notice = (package_root / 'SOURCE.md').read_text(encoding='utf-8')
        readme = (package_root / 'README.md').read_text(encoding='utf-8')
        require('https://github.com/te4a/nextcloud-archive-tools' in source_notice
                and f'Release tag: v{version}' in source_notice
                and f'/blob/v{version}/docs/INSTALL.md' in readme
                and 'AGPL-3.0-or-later' in readme,
                'The runtime package is missing its matching source and installation notices.')
        report.check('runtime package includes reviewed licence, release notes and versioned source notices', True)
    else:
        plain(ROOT / 'dev' / 'Dockerfile')
        require(digest(package_root / 'Dockerfile.nextcloud') == digest(ROOT / 'dev' / 'Dockerfile'),
                'The packaged Nextcloud Dockerfile differs from the tested canonical Dockerfile.')
        report.check('packaged Nextcloud Dockerfile matches the tested source', True)
    report.check('one safe archive_tools root and no links or development material', True)
    report.check('every packaged application file is byte-identical to tested app', True)
    report.data['package'] = {'name': package.name, 'version': version, 'sha256': actual_hash,
                              'profile': 'runtime' if runtime_profile else 'legacy-development',
                              'signaturePresent': bool(signatures),
                              'applicationFiles': len(current_files), 'expandedBytes': expanded}
    report.data['retainedExtraction'] = str(extraction.relative_to(ROOT))
    override = extraction / 'compose.override.json'
    return package_root, override


def normalize_bind(value: str) -> str:
    value = value.replace('\\', '/').rstrip('/')
    value = re.sub(r'^/(?:run/desktop/mnt/host|host_mnt)/([A-Za-z])/', r'\1:/', value)
    return value.casefold()


def origin_matches(mount: dict, expected: ApplicationOrigin) -> bool:
    if mount.get('RW') is not False:
        return False
    if isinstance(expected, VolumeOrigin):
        return mount.get('Type') == 'volume' and mount.get('Name') == expected.name
    return (mount.get('Type') == 'bind'
            and normalize_bind(mount.get('Source', '')) == normalize_bind(str(expected.resolve())))


def assert_bind(docker, expected: ApplicationOrigin, report, *, record: bool = True) -> None:
    docker.assert_test_stack()
    container = docker.run(['ps', '--quiet', 'nextcloud'])
    mounts = json.loads(docker.run(['inspect', '--format', '{{json .Mounts}}', container], compose=False))
    app = [item for item in mounts if item.get('Destination') == APP_TARGET]
    require(len(app) == 1 and origin_matches(app[0], expected),
            'The running application does not use the exact expected read-only origin.')
    if isinstance(expected, VolumeOrigin):
        assert_package_volume(docker, expected)
    if record:
        report.check('expected read-only application ' + ('volume' if isinstance(expected, VolumeOrigin) else 'bind'), True)


def assert_stop_targets(docker, allowed_origins: tuple[ApplicationOrigin, ...], report, phase: str) -> None:
    """Validate stopped/Created targets too; inherited image labels are insufficient.

    A stopped container has no active NetworkSettings port map, so its immutable
    HostConfig bindings are checked. No operation depends on assert_test_stack()
    after stop, since that helper intentionally requires a running service.
    """
    override = os.environ.get('DOCKER_HOST')
    require(not override or H['local_docker_endpoint'](override), 'Remote Docker hosts are not allowed for this smoke task.')
    endpoint = json.loads(docker.run(['context', 'inspect', '--format', '{{json .Endpoints.docker.Host}}'], compose=False))
    require(H['local_docker_endpoint'](endpoint), 'The Docker context changed away from the local test stack.')
    containers = docker.run(['ps', '--all', '--quiet', 'nextcloud']).splitlines()
    require(bool(containers) and all(re.fullmatch('[a-f0-9]{12,64}', item) for item in containers),
            'No unambiguous local service target is available for the guarded stop.')
    for container in containers:
        labels = json.loads(docker.run(['inspect', '--format', '{{json .Config.Labels}}', container], compose=False))
        ports = json.loads(docker.run(['inspect', '--format', '{{json .HostConfig.PortBindings}}', container], compose=False))
        mounts = json.loads(docker.run(['inspect', '--format', '{{json .Mounts}}', container], compose=False))
        name = docker.run(['inspect', '--format', '{{.Name}}', container], compose=False).lstrip('/')
        require(labels.get('com.docker.compose.project') == 'archive-tools-dev'
                and labels.get('com.docker.compose.service') == 'nextcloud'
                and re.fullmatch(r'(?:[a-f0-9]{12,64}_)?archive-tools-dev-nextcloud-1', name) is not None,
                'An unrelated container inherited the project labels; guarded stop was refused.')
        bindings = ports.get('80/tcp') or []
        require(set(ports) == {'80/tcp'} and len(bindings) == 1
                and bindings[0].get('HostIp') == '127.0.0.1'
                and bindings[0].get('HostPort') == '18080',
                'A stop target does not have the isolated test HTTP binding.')
        app = [item for item in mounts if item.get('Destination') == APP_TARGET]
        require(len(app) == 1 and any(origin_matches(app[0], origin) for origin in allowed_origins),
                'A stop target does not use the expected read-only application origin.')
        data = [item for item in mounts if item.get('Destination') == '/var/www/html']
        require(len(data) == 1 and data[0].get('Type') == 'volume'
                and data[0].get('Name') == docker.main_data_volume and data[0].get('RW') is True,
                'A stop target does not use the original Nextcloud data volume.')
    report.check(phase + ': every stop target belongs to the expected local service and read-only origin', True)


def package_file_hashes(package_root: Path) -> dict[str, str]:
    result = {}
    for path in package_root.rglob('*'):
        plain(path)
        require(path.is_dir() or path.is_file(), 'The staged package contains a special entry.')
        if path.is_file():
            result[path.relative_to(package_root).as_posix()] = digest(path)
    require(0 < len(result) < 10000, 'The staged package file manifest is empty or too large.')
    return dict(sorted(result.items()))


def assert_package_volume(docker, origin: VolumeOrigin) -> None:
    require(re.fullmatch(r'[a-f0-9]{32}', origin.token) is not None
            and origin.name == 'archive-tools-dev-package-' + origin.token,
            'The package volume identity is invalid.')
    rows = json.loads(docker.run(['volume', 'inspect', origin.name], compose=False))
    require(len(rows) == 1 and rows[0].get('Name') == origin.name
            and rows[0].get('Driver') == 'local' and rows[0].get('Scope') == 'local'
            and not rows[0].get('Options')
            and (rows[0].get('Labels') or {}).get('com.archive-tools.package-smoke') == origin.token
            and (rows[0].get('Labels') or {}).get('com.docker.compose.project') == HELPER_PROJECT,
            'The retained package volume is not the exact locally owned smoke artifact.')


def assert_copy_helper(docker, container: str, origin: VolumeOrigin, image: str, manifest: dict) -> dict:
    rows = json.loads(docker.run(['inspect', container], compose=False))
    require(len(rows) == 1, 'The package-copy helper is ambiguous.')
    info = rows[0]
    labels = info.get('Config', {}).get('Labels') or {}
    host = info.get('HostConfig') or {}
    mounts = info.get('Mounts') or []
    require(info.get('Name') == '/archive-tools-dev-package-copy-' + origin.token
            and info.get('Image') == image and info.get('ImageManifestDescriptor') == manifest
            and info.get('State', {}).get('Running') is True
            and labels.get('com.docker.compose.project') == HELPER_PROJECT
            and labels.get('com.docker.compose.service') == HELPER_SERVICE
            and labels.get('com.archive-tools.package-smoke') == origin.token
            and host.get('NetworkMode') == 'none' and host.get('ReadonlyRootfs') is True
            and host.get('AutoRemove') is True and host.get('Privileged') is False
            and not host.get('PortBindings')
            and info.get('Config', {}).get('Entrypoint') == ['php']
            and info.get('Config', {}).get('Cmd') == ['-r', 'sleep(120);']
            and len(mounts) == 1 and mounts[0].get('Type') == 'volume'
            and mounts[0].get('Name') == origin.name and mounts[0].get('Destination') == COPY_TARGET
            and mounts[0].get('RW') is True,
            'The package-copy helper is not the exact isolated bounded smoke container.')
    return info


VERIFY_TREE_PHP = r'''
$root = $argv[1];
$expected = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
$actual = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
foreach ($iterator as $entry) {
    if ($entry->isLink()) { throw new RuntimeException('The copied package contains a link.'); }
    if ($entry->isDir()) { continue; }
    if (!$entry->isFile() || !$entry->isReadable()) { throw new RuntimeException('A copied package entry is not a readable regular file.'); }
    $relative = substr($entry->getPathname(), strlen($root) + 1);
    $actual[$relative] = hash_file('sha256', $entry->getPathname());
}
ksort($actual, SORT_STRING);
ksort($expected, SORT_STRING);
if ($actual !== $expected) { throw new RuntimeException('The copied package file set or SHA-256 hashes differ.'); }
echo json_encode(['files' => count($actual), 'verified' => true], JSON_THROW_ON_ERROR);
'''


def prepare_package_volume(docker, package_root: Path, override: Path, report) -> VolumeOrigin:
    """Stream files through the Docker API without a new Windows bind permission."""
    docker.assert_test_stack()
    token = uuid.uuid4().hex
    origin = VolumeOrigin('archive-tools-dev-package-' + token, token)
    helper = 'archive-tools-dev-package-copy-' + token
    current = docker.run(['ps', '--quiet', 'nextcloud'])
    image = docker.run(['inspect', '--format', '{{.Image}}', current], compose=False)
    image_tag = docker.run(['inspect', '--format', '{{.Config.Image}}', current], compose=False)
    selected_manifest = json.loads(docker.run(['inspect', '--format', '{{json .ImageManifestDescriptor}}', current], compose=False))
    require(re.fullmatch(r'sha256:[a-f0-9]{64}', image) is not None,
            'The current Nextcloud image identity is invalid.')
    require(re.fullmatch(r'archive-tools-nextcloud-dev:[A-Za-z0-9_.-]+', image_tag) is not None,
            'The current Nextcloud image is not the expected custom local test image.')
    # With Docker's containerd store, a running container can expose its config
    # digest in .Image while image inspect returns a runnable OCI index digest.
    # Resolve only the existing local image and verify the helper's selected
    # platform manifest against the current container before copying any files.
    runnable_image = docker.run(['image', 'inspect', '--format', '{{.Id}}', image_tag], compose=False)
    require(re.fullmatch(r'sha256:[a-f0-9]{64}', runnable_image) is not None,
            'The current custom test image is not available locally.')
    require(isinstance(selected_manifest, dict)
            and re.fullmatch(r'sha256:[a-f0-9]{64}', selected_manifest.get('digest', '')) is not None,
            'The current container selected image manifest is unavailable.')
    existing = docker.run(['volume', 'ls', '--filter', 'name=^' + origin.name + '$', '--format', '{{.Name}}'], compose=False)
    require(not existing, 'The unique package volume already exists.')
    expected = package_file_hashes(package_root)
    created = docker.run(['volume', 'create', '--driver', 'local',
                          '--label', 'com.docker.compose.project=' + HELPER_PROJECT,
                          '--label', 'com.archive-tools.package-smoke=' + token,
                          origin.name], compose=False)
    require(created == origin.name, 'Docker created an unexpected package volume.')
    report.data['retainedPackageVolume'] = origin.name
    assert_package_volume(docker, origin)
    container = ''
    primary_error = None
    try:
        container = docker.run(['run', '--detach', '--rm', '--pull', 'never', '--name', helper,
                                '--label', 'com.docker.compose.project=' + HELPER_PROJECT,
                                '--label', 'com.docker.compose.service=' + HELPER_SERVICE,
                                '--label', 'com.archive-tools.package-smoke=' + token,
                                '--network', 'none', '--read-only', '--cap-drop', 'ALL',
                                '--security-opt', 'no-new-privileges:true', '--pids-limit', '16',
                                '--memory', '64m', '--mount', 'type=volume,source=' + origin.name + ',target=' + COPY_TARGET + ',volume-nocopy',
                                '--entrypoint', 'php', runnable_image, '-r', 'sleep(120);'], compose=False, timeout=30)
        require(re.fullmatch(r'[a-f0-9]{64}', container) is not None, 'Docker did not return one package-copy helper id.')
        assert_copy_helper(docker, container, origin, runnable_image, selected_manifest)
        docker.run(['exec', container, 'php', '-r',
                    "if (count(scandir($argv[1])) !== 2) { throw new RuntimeException('The new package volume is not empty.'); }", COPY_TARGET], compose=False, timeout=10)
        # docker cp reads the validated host directory through the CLI/API. It
        # does not ask Docker Desktop to bind or share that Windows directory.
        assert_copy_helper(docker, container, origin, runnable_image, selected_manifest)
        docker.run(['cp', str(package_root.resolve()) + os.sep + '.', container + ':' + COPY_TARGET], compose=False, timeout=60)
        assert_copy_helper(docker, container, origin, runnable_image, selected_manifest)
        result = json.loads(docker.run(['exec', '--user', 'www-data', container, 'php', '-r', VERIFY_TREE_PHP,
                                      COPY_TARGET, json.dumps(expected, ensure_ascii=True, separators=(',', ':'))], compose=False, timeout=20))
        require(result == {'files': len(expected), 'verified': True}, 'The package volume verification result is invalid.')
        require(package_file_hashes(package_root) == expected, 'The staged package changed during its Docker API copy.')
        report.check('every package-volume file is readable by www-data and has its expected SHA-256', True)
        report.data['packageVolumeVerification'] = {
            'files': len(expected), 'manifestSha256': hashlib.sha256(json.dumps(expected, sort_keys=True).encode()).hexdigest(),
            'helperNetwork': 'none', 'helperLifetimeSeconds': 120, 'transport': 'docker cp'}
    except BaseException as error:
        primary_error = error
    finally:
        if container:
            try:
                assert_copy_helper(docker, container, origin, runnable_image, selected_manifest)
                docker.run(['stop', '--time', '1', container], compose=False, timeout=10)
                report.data['packageCopyHelperStopped'] = True
            except BaseException as stop_error:
                # The helper also terminates itself after 120 seconds and --rm
                # removes only that disposable container, never its named volume.
                report.data['packageCopyHelperStopped'] = False
                report.data['packageCopyHelperStopError'] = report.redact(str(stop_error))[:1000]
                if primary_error is None:
                    primary_error = stop_error
    if primary_error is not None:
        raise primary_error
    override.write_text(json.dumps({
        'services': {'nextcloud': {'volumes': [{'type': 'volume', 'source': 'package_smoke',
                                               'target': APP_TARGET, 'read_only': True, 'volume': {'nocopy': True}}]}},
        'volumes': {'package_smoke': {'external': True, 'name': origin.name}},
    }, indent=2) + '\n', encoding='utf-8')
    return origin


def status_object(text: str) -> dict:
    matches = re.findall(r'(?m)^\{[^\r\n]*\}\s*$', text)
    require(bool(matches), 'Nextcloud did not return a JSON status object.')
    return json.loads(matches[-1])


def occ(docker, *arguments: str) -> str:
    return docker.run(['exec', '-T', '--user', 'www-data', 'nextcloud', 'php', 'occ', *arguments])


def wait_ready(docker, expected_namespace: str) -> None:
    deadline = time.monotonic() + 120
    while time.monotonic() < deadline:
        try:
            status = status_object(occ(docker, 'status', '--output=json'))
        except Exception:
            time.sleep(1)
            continue
        if status.get('installed') is True and not status.get('maintenance'):
            require(str(status.get('version', '')).split('.')[0] in ('34', '35'),
                    'The smoke task supports Nextcloud 34 and 35.')
            require(SEED['runtime_identity'](docker)['namespace'] == expected_namespace,
                    'The smoke task switched to a different Nextcloud installation or volume.')
            return
        time.sleep(1)
    raise H['TestFailure']('The local Nextcloud stack did not become ready within 120 seconds.')


def app_enabled(docker, expected: bool, version: str) -> None:
    apps = json.loads(occ(docker, 'app:list', '--output=json'))
    section = apps.get('enabled' if expected else 'disabled', {})
    # Core versions differ in whether disabled versions include an installed
    # annotation. Accept either representation, with an exact version match.
    value = section.get('archive_tools')
    allowed = {version} if expected else {version, version + ' (installed ' + version + ')'}
    opposite = apps.get('disabled' if expected else 'enabled', {})
    require(value in allowed and 'archive_tools' not in opposite,
            'Archive Tools has an unexpected enabled state or version.')


def demo(report):
    client = H['Client'](BASE_URL, DEMO_USER, DEMO_PASSWORD, report)
    client.csrf()
    return client


def inspect_sources(client, fixtures: dict, report, phase: str) -> dict[int, dict]:
    results = {}
    for source in fixtures['files']:
        source_id = source['sourceId']
        payload = {'password': source['fixturePassword']} if source.get('fixturePassword') else {}
        code, info = client.api('POST', f'/archives/{source_id}/inspect', payload)
        require(code == 200 and isinstance(info, dict) and info.get('sourceId') == source_id
                and isinstance(info.get('entries'), list) and info.get('fileCount', 0) > 0,
                phase + ': a pre-existing demo archive could not be inspected.')
        require(info.get('format') == source['format'],
                phase + ': a demo archive format changed.')
        results[source_id] = info
        report.check(phase + ': existing archive ' + str(source_id) + ' inspected', True)
        if source.get('fixturePassword'):
            code, locked = client.api('POST', '/archives/' + str(source_id) + '/inspect', {})
            report.check(phase + ': encrypted headers still require a password',
                         code == 422 and isinstance(locked, dict) and locked.get('code') == 'password_required')
    return results


def safe_demo_path(value) -> bool:
    return (isinstance(value, str) and value != '' and not value.startswith('/')
            and '\\' not in value and '\0' not in value
            and all(part not in ('', '.', '..') for part in value.split('/')))


def load_demo_artifacts(docker, version: str, report) -> tuple[dict, dict, dict]:
    stack = SEED['runtime_identity'](docker)
    paths = SEED['artifact_paths'](stack)
    for path in paths.values():
        plain(path)
        require(path.is_file(), 'Current-volume demo artifacts are missing. Run dev/seed-demo.py --extract-sample after its release gate.')
    fixtures = json.loads(paths['formats'].read_text(encoding='utf-8-sig'))
    extraction = json.loads(paths['extraction'].read_text(encoding='utf-8-sig'))
    for artifact in (fixtures, extraction):
        require(artifact.get('schemaVersion') == 1 and artifact.get('stack', {}).get('namespace') == stack['namespace']
                and artifact.get('appVersion') == version and artifact.get('demoUser') == DEMO_USER,
                'Demo artifacts do not belong to the current Nextcloud volume and application version.')
    require(safe_demo_path(fixtures.get('folderPath')) and isinstance(fixtures.get('folderId'), int)
            and isinstance(fixtures.get('files'), list) and 4 <= len(fixtures['files']) <= 20,
            'The current demo format manifest is incomplete.')
    source_ids = set()
    for source in fixtures['files']:
        require(isinstance(source.get('sourceId'), int) and source['sourceId'] > 0 and source['sourceId'] not in source_ids
                and safe_demo_path(source.get('path')) and source['path'].startswith(fixtures['folderPath'] + '/')
                and source.get('format') in {'zip', 'tar', 'tar.gz', 'tar.bz2', 'tar.xz', '7z', 'rar'},
                'The demo source identity or path is invalid.')
        source_ids.add(source['sourceId'])
        if source.get('fixturePassword'):
            require(source['fixturePassword'] == SEED['ARCHIVE_PASSWORD'], 'The demo manifest contains a nonpublic credential.')
    require({'zip', 'tar', '7z', 'rar'} <= {source['format'] for source in fixtures['files']},
            'The demo does not cover the supported baseline archive families.')
    require(extraction.get('sourceId') in source_ids and extraction.get('status') == 'succeeded'
            and safe_demo_path(extraction.get('folderPath'))
            and extraction['folderPath'].startswith(fixtures['folderPath'] + '/')
            and isinstance(extraction.get('members'), list) and 0 < len(extraction['members']) <= 100,
            'Complete the current-volume demo sample extraction before package verification.')
    report.data['stack'] = stack
    report.data['demoArtifacts'] = {name: str(path.relative_to(ROOT)) for name, path in paths.items()}
    return fixtures, extraction, stack


def baseline_paths(client, fixtures: dict, extraction: dict, information: dict[int, dict]) -> list[str]:
    require(client.file_id(fixtures['folderPath']) == fixtures['folderId'], 'The demo folder identity differs on the current volume.')
    paths = []
    for source in fixtures['files']:
        require(client.file_id(source['path']) == source['sourceId']
                and information[source['sourceId']]['sourceName'] == source['name'],
                'A smoke fixture DAV path does not match its current-volume file id.')
        code, content = client.dav('GET', source['path'])
        require(code == 200 and hashlib.sha256(content).hexdigest() == source.get('archiveSha256'),
                'A demo source differs from its seeded artifact.')
        paths.append(source['path'])
    for member in extraction['members']:
        require(isinstance(member, dict) and safe_demo_path(member.get('path'))
                and isinstance(member.get('bytes'), int) and 0 <= member['bytes'] <= 8388608
                and isinstance(member.get('sha256'), str) and re.fullmatch('[a-f0-9]{64}', member['sha256']) is not None,
                'The demo extraction manifest contains an unsafe member.')
        path = extraction['folderPath'] + '/' + member['path']
        code, content = client.dav('GET', path)
        require(code == 200 and len(content) == member['bytes'] and hashlib.sha256(content).hexdigest() == member['sha256'],
                'A sample extraction member differs from its completed manifest.')
        paths.append(path)
    return paths


def dav_snapshot(client, paths: list[str]) -> dict[str, bytes]:
    result = {}
    for path in paths:
        code, content = client.dav('GET', path)
        require(code == 200 and len(content) <= 8388608, 'An existing smoke DAV fixture could not be read within its byte budget.')
        result[path] = content
    return result


def verify_dav(client, baseline: dict[str, bytes], report, phase: str) -> None:
    for path, expected in baseline.items():
        code, actual = client.dav('GET', path)
        report.check(phase + ': original/extracted DAV bytes preserved', code == 200 and actual == expected)


def verify_http_runtime(docker, client, origin: Path, fixtures: dict, report, phase: str, version: str) -> None:
    diagnostic = SEED['json_object'](occ(docker, 'archive_tools:diagnose', '--output=json'))
    formats, scripts = SEED['http_initial_state'](client)
    required_formats = {item['format'] for item in fixtures['files']}
    require(diagnostic.get('applicationVersion') == version
            and set(diagnostic.get('archiveFormats', [])) == set(formats)
            and required_formats <= set(formats),
            phase + ': the current HTTP initial state differs from the current PHP backends.')
    report.check(phase + ': HTTP archive formats match the current PHP backend capabilities', True)
    entries = [source for source in scripts if urllib.parse.urlsplit(source).path.endswith('/archive_tools/js/files.mjs')]
    require(len(entries) == 1, phase + ': Files did not load exactly one Archive Tools native init script.')
    url = urllib.parse.urlsplit(entries[0])
    base = urllib.parse.urlsplit(BASE_URL)
    require((not url.scheme or url.scheme == base.scheme) and (not url.netloc or url.netloc == base.netloc),
            'The native init script points outside the guarded local origin.')
    path = url.path + ('?' + url.query if url.query else '')
    code, content = client.request('GET', path, headers={'Cache-Control': 'no-cache'})
    require(code == 200 and hashlib.sha256(content).hexdigest() == digest(origin / 'js' / 'files.mjs'),
            phase + ': the native init script differs from the active read-only application source.')
    report.check(phase + ': HTTP native init script matches the active application source', True)
    report.data.setdefault('httpInitialStates', {})[phase] = {
        'archiveFormats': formats, 'phpVersion': diagnostic.get('phpVersion'),
        'applicationVersion': version, 'initSha256': hashlib.sha256(content).hexdigest()}


def pinned_mounts(client) -> list[dict]:
    code, rows = client.api('GET', '/mounts')
    require(code == 200 and isinstance(rows, list), 'Existing connection metadata could not be read.')
    keys = ('id', 'sourceId', 'parentId', 'name', 'createdBy', 'pinned')
    return sorted(({key: row[key] for key in keys} for row in rows if row.get('pinned')), key=lambda row: row['id'])


def run(args, report) -> None:
    version = SEED['manifest_version']()
    package = Path(args.package) if args.package else ROOT / 'dist' / ('archive_tools-' + version + '.tar.gz')
    package_root, override = unpack_package(package.resolve(), report)
    if args.require_signature:
        require((package_root / 'appinfo/signature.json').is_file(),
                'A release gate requires a signed package; unsigned integrity checks are skipped by Nextcloud.')
    if not args.run:
        report.data['status'] = 'prepared'
        report.data['note'] = 'No Docker commands or application state changes were performed. Use --run only after the release gate.'
        return
    secret = H['read_environment']().get('ARCHIVE_TOOLS_ADMIN_PASSWORD', '')
    require(bool(secret), 'The local compose administrator credential is missing.')
    report.secrets.extend([secret, DEMO_PASSWORD, SEED['ARCHIVE_PASSWORD']])
    docker = SafeDocker(report)
    original = ROOT / 'app'
    assert_bind(docker, original, report)
    container = docker.run(['ps', '--quiet', 'nextcloud'])
    mounts = json.loads(docker.run(['inspect', '--format', '{{json .Mounts}}', container], compose=False))
    data = [item for item in mounts if item.get('Destination') == '/var/www/html']
    resolved = json.loads(docker.run(['config', '--format', 'json']))
    configured_data = [item for item in resolved.get('services', {}).get('nextcloud', {}).get('volumes', [])
                       if item.get('target') == '/var/www/html']
    require(len(configured_data) == 1 and configured_data[0].get('type') == 'volume',
            'The base compose definition does not name exactly one Nextcloud data volume.')
    expected_data_name = resolved.get('volumes', {}).get(configured_data[0].get('source'), {}).get('name')
    require(len(data) == 1 and data[0].get('Type') == 'volume' and data[0].get('RW') is True
            and isinstance(expected_data_name, str) and bool(expected_data_name)
            and data[0].get('Name') == expected_data_name,
            'The running Nextcloud data volume does not match the exact base compose volume.')
    docker.main_data_volume = data[0]['Name']
    fixtures, extraction, stack = load_demo_artifacts(docker, version, report)
    compose_hash = digest(ROOT / 'compose.yaml')
    client = demo(report)
    app_enabled(docker, True, version)
    verify_http_runtime(docker, client, original, fixtures, report, 'baseline', version)
    information = inspect_sources(client, fixtures, report, 'baseline')
    paths = baseline_paths(client, fixtures, extraction, information)
    baseline = dav_snapshot(client, paths)
    pins = pinned_mounts(client)
    report.data['baselineDavFiles'] = len(baseline)
    report.data['baselinePinnedConnections'] = len(pins)
    report.data['baselineContentHashes'] = [hashlib.sha256(value).hexdigest() for value in baseline.values()]
    package_origin = prepare_package_volume(docker, package_root, override, report)
    original_prefix = docker.prefix.copy()
    package_prefix = original_prefix + ['--file', str(override)]
    attempted = False
    primary_error = None
    try:
        # Recheck the original origin immediately before the first mutation.
        assert_bind(docker, original, report, record=False)
        require(digest(ROOT / 'compose.yaml') == compose_hash, 'The compose definition changed during smoke preparation.')
        assert_stop_targets(docker, (original,), report, 'package switch')
        attempted = True
        # Docker Desktop can stall while replacing a live bind-backed service.
        # Stop only the guarded service before changing its application origin.
        docker.run(['stop', '--timeout', '10', 'nextcloud'], timeout=30)
        docker.prefix = package_prefix
        docker.run(['up', '-d', '--no-build', '--no-deps', 'nextcloud'], timeout=180)
        wait_ready(docker, stack['namespace'])
        assert_bind(docker, package_origin, report)
        occ(docker, 'app:enable', 'archive_tools')
        app_enabled(docker, True, version)
        report.check('packaged application version ' + version + ' enabled', True)
        if args.require_signature:
            integrity = occ(docker, 'integrity:check-app', 'archive_tools', '--output=json')
            require('skipping' not in integrity.lower() and 'not found' not in integrity.lower(),
                    'Nextcloud skipped package integrity verification.')
            report.check('Nextcloud verifies the exact packaged signature and certificate scope', True)
        packaged_client = demo(report)
        verify_http_runtime(docker, packaged_client, package_root, fixtures, report, 'package', version)
        inspect_sources(packaged_client, fixtures, report, 'package')
        verify_dav(packaged_client, baseline, report, 'package')
        report.check('packaged app retains pinned connection metadata', pinned_mounts(packaged_client) == pins)
        occ(docker, 'app:disable', 'archive_tools')
        app_enabled(docker, False, version)
        disabled_client = demo(report)
        verify_dav(disabled_client, baseline, report, 'disabled package')
        occ(docker, 'app:enable', 'archive_tools')
        app_enabled(docker, True, version)
        # The web SAPI may retain routes from the disabled app while CLI uses
        # a separate local cache. Verify re-enable with a fresh PHP runtime.
        assert_stop_targets(docker, (package_origin,), report, 're-enable package runtime')
        docker.run(['stop', '--timeout', '10', 'nextcloud'], timeout=30)
        docker.run(['up', '-d', '--no-build', '--no-deps', 'nextcloud'], timeout=180)
        wait_ready(docker, stack['namespace'])
        assert_bind(docker, package_origin, report)
        report.data['reenableFreshPhpRuntime'] = True
        reenabled_client = demo(report)
        verify_http_runtime(docker, reenabled_client, package_root, fixtures, report, 're-enabled package', version)
        inspect_sources(reenabled_client, fixtures, report, 're-enabled package')
        verify_dav(reenabled_client, baseline, report, 're-enabled package')
        report.check('disable/enable retains pinned connection metadata', pinned_mounts(reenabled_client) == pins)
    except BaseException as error:
        primary_error = error
    finally:
        if attempted:
            docker.prefix = original_prefix
            try:
                require(digest(ROOT / 'compose.yaml') == compose_hash, 'The base compose definition changed; automatic restore needs review.')
                assert_stop_targets(docker, (original, package_origin), report, 'workspace restore')
                docker.run(['stop', '--timeout', '10', 'nextcloud'], timeout=30)
                # Only this already-validated project/service is recreated. No down,
                # remove, volume deletion, build or force-recreate operation is used.
                docker.run(['up', '-d', '--no-build', '--no-deps', 'nextcloud'], timeout=180)
                wait_ready(docker, stack['namespace'])
                assert_bind(docker, original, report)
                occ(docker, 'app:enable', 'archive_tools')
                app_enabled(docker, True, version)
                restored = demo(report)
                verify_http_runtime(docker, restored, original, fixtures, report, 'restored workspace', version)
                verify_dav(restored, baseline, report, 'restored workspace')
                report.check('restored workspace retains pinned connection metadata', pinned_mounts(restored) == pins)
                report.data['workspaceBindRestored'] = True
            except BaseException as restore_error:
                report.data['workspaceBindRestored'] = False
                report.data['restoreError'] = report.redact(str(restore_error))[:4500]
                if primary_error is None:
                    primary_error = restore_error
    if primary_error is not None:
        raise primary_error
    report.data['status'] = 'passed'
    report.data['cronNote'] = 'The coordinating release task restores the local cron daemon after the smoke test.'


def main() -> int:
    if hasattr(sys.stdout, 'reconfigure'):
        sys.stdout.reconfigure(encoding='utf-8')
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--package', default=None, help='Package in dist; defaults to the current app manifest version.')
    parser.add_argument('--run', action='store_true', help='Temporarily run the validated package from a retained Docker volume; requires the coordinating release-gate signal.')
    parser.add_argument('--require-signature', action='store_true', help='Fail on unsigned packages and verify the installed package through Nextcloud during --run.')
    args = parser.parse_args()
    report = H['Report']('package-smoke-' + uuid.uuid4().hex)
    report.data['startedAtUtc'] = datetime.now(timezone.utc).isoformat()
    result = 0
    try:
        run(args, report)
    except BaseException as error:
        report.data['status'] = 'failed'
        report.data['error'] = report.redact(str(error))[:4500]
        result = 1
    report.data['elapsedSeconds'] = round(time.monotonic() - report.started, 2)
    RESULTS.mkdir(exist_ok=True)
    (RESULTS / 'package-smoke.json').write_text(report.redact(json.dumps(report.data, ensure_ascii=False, indent=2)) + '\n', encoding='utf-8')
    print(json.dumps({'status': report.data['status'], 'checks': len(report.data['checks']),
                      'workspaceBindRestored': report.data.get('workspaceBindRestored'),
                      'error': report.data.get('error')}, ensure_ascii=True))
    return result


if __name__ == '__main__':
    sys.exit(main())
