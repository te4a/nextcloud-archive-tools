#!/usr/bin/env python3
"""Build a deterministic runtime package; keep development source in the repository."""
from __future__ import annotations

import argparse
import gzip
import hashlib
import io
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import tarfile
import uuid
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
APP = ROOT / 'app'
STAGES = ROOT / '.release-staging'
DIST = ROOT / 'dist'
RUNTIME = ('appinfo', 'lib', 'templates', 'img', 'css', 'js', 'l10n', 'bin')
REPOSITORY = 'https://github.com/te4a/nextcloud-archive-tools'
SIGNATURE = 'appinfo/signature.json'
# Official static 7zzs executables, corresponding source and unmodified notices.
SEVEN_ZIP_FILES = {
    'bin/7zz-linux-x64': '0b58a64e71ebdf53751cd954cad1cc552c6a11ef51336bee6acafbdc54c28c1f',
    'bin/7zz-linux-arm64': '265e45b5847392c0cd97a84acb6b3f9933c3e43d698fb5a2979f5e9a80c05758',
    'bin/7z2606-src.tar.xz': 'a4e374c8f864f51df48c2cfd0e1413ec3b2ea030deae852d6a655402ebb61ac4',
    'bin/License.txt': '1790374e5352329cedb46ee3808930a88e9ca2f08b82b10fcf5cf605d2c301b1',
    'bin/copying.txt': '1e7e6bae5a5bde32f1ae5a7c37a082d1ab03cf89354f7f936ac40be9e39a6531',
    'bin/unRarLicense.txt': '17bd9fa4399092c777536fff045b41df76ec9d2ac4c9b8e7345d3b8b6ccc7976',
}
SEVEN_ZIP_NOTICE = 'bin/README.md'
EXECUTABLES = {'bin/7zz-linux-x64', 'bin/7zz-linux-arm64'}


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


def plain(path: Path) -> None:
    current = Path(os.path.abspath(path))
    require(current.is_relative_to(ROOT), 'Package paths must stay inside this checkout.')
    while current.is_relative_to(ROOT):
        if current.exists() or current.is_symlink():
            info = current.lstat()
            require(not stat.S_ISLNK(info.st_mode) and not (
                getattr(info, 'st_file_attributes', 0) & getattr(stat, 'FILE_ATTRIBUTE_REPARSE_POINT', 0)
            ), 'Package inputs must not contain links or junctions.')
        if current == ROOT:
            break
        current = current.parent


def digest(content: bytes) -> str:
    return hashlib.sha256(content).hexdigest()


def identity() -> tuple[str, str]:
    plain(APP / 'appinfo/info.xml')
    info = ET.parse(APP / 'appinfo/info.xml').getroot()
    app_id, version = info.findtext('id'), info.findtext('version')
    require(app_id == 'archive_tools' and isinstance(version, str)
            and re.fullmatch(r'\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?', version) is not None,
            'The application id or semantic version is invalid.')
    require(json.loads((ROOT / 'package.json').read_text(encoding='utf-8-sig'))['version'] == version,
            'PHP and frontend package versions differ.')
    return app_id, version


def git_value(arguments: list[str], *, required: bool = False) -> str:
    result = subprocess.run(['git', *arguments], cwd=ROOT, capture_output=True, text=True, timeout=10)
    require(not required or result.returncode == 0, 'The source revision or working-tree status could not be verified.')
    return result.stdout.strip() if result.returncode == 0 else ''


def runtime_files() -> dict[str, bytes]:
    result = {}
    for directory in RUNTIME:
        base = APP / directory
        plain(base)
        require(base.is_dir(), 'A runtime application directory is missing.')
        for path in sorted(base.rglob('*')):
            plain(path)
            require(path.is_dir() or path.is_file(), 'A runtime input is a special file.')
            if path.is_file():
                relative = path.relative_to(APP).as_posix()
                require(not any(part in ('.git', 'node_modules', '__pycache__') for part in path.relative_to(APP).parts)
                        and not path.name.startswith('.env') and path.name != '.test-secrets.json'
                        and path.suffix.lower() not in ('.key', '.pem', '.p12', '.pfx', '.pyc'),
                        'A private or development file appeared in the runtime tree.')
                if relative != SIGNATURE:
                    result[relative] = path.read_bytes()
    require({name for name in result if name.startswith('bin/')} == set(SEVEN_ZIP_FILES) | {SEVEN_ZIP_NOTICE},
            'The runtime package must contain the complete 7-Zip distribution.')
    for name, expected in SEVEN_ZIP_FILES.items():
        require(digest(result[name]) == expected, f'The bundled 7-Zip file differs: {name}')
    for required in ('appinfo/info.xml', 'js/main.mjs', 'js/files.mjs', 'css/archive-tools.css', 'js/THIRD_PARTY_LICENSES.md'):
        require(required in result, 'Build the application frontend before packaging.')
    for metadata in ('LICENSE', 'CHANGELOG.md'):
        plain(ROOT / metadata)
        result[metadata] = (ROOT / metadata).read_bytes()
    require(sum(map(len, result.values())) <= 268435456, 'Runtime inputs exceed the byte budget.')
    return result


def prepare() -> tuple[Path, dict]:
    app_id, version = identity()
    files = runtime_files()
    commit = git_value(['rev-parse', '--verify', 'HEAD'])
    commit = commit if re.fullmatch(r'[a-f0-9]{40,64}', commit) else None
    status = subprocess.run(['git', 'status', '--porcelain'], cwd=ROOT, capture_output=True, text=True, timeout=10)
    dirty = status.returncode != 0 or bool(status.stdout.strip())
    revision = commit or 'uncommitted development candidate'
    source = (f'# Corresponding source\n\nArchive Tools {version}\n\n'
              f'Repository: {REPOSITORY}\n\nRevision: {revision}\n\n'
              f'Release tag: v{version}\n\nSource state: {"uncommitted changes" if dirty else "clean revision"}\n\n'
              'Public distribution requires that the matching source revision and release tag '
              'are available in this repository. Development candidates may contain uncommitted changes.\n')
    files['SOURCE.md'] = source.encode()
    files['README.md'] = (f'# Archive Tools {version}\n\n'
                          'Native archive browsing, shared read-only connections and queued extraction for Nextcloud.\n\n'
                          f'Installation and supported environments: {REPOSITORY}/blob/v{version}/docs/INSTALL.md\n\n'
                          f'User documentation: {REPOSITORY}/blob/v{version}/README.md\n\n'
                          'Licensed under AGPL-3.0-or-later. See LICENSE and SOURCE.md.\n').encode()
    plain(STAGES)
    token = uuid.uuid4().hex
    stage_parent = STAGES / token
    stage = stage_parent / app_id
    stage.mkdir(parents=True)
    for relative, content in files.items():
        target = stage / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(content)
    metadata = {'format': 1, 'profile': 'runtime', 'appId': app_id, 'version': version,
                'sourceCommit': commit, 'sourceDirty': dirty,
                'files': {key: digest(value) for key, value in sorted(files.items())}}
    (stage_parent / 'manifest.json').write_text(json.dumps(metadata, indent=2) + '\n', encoding='utf-8')
    return stage, metadata


def read_stage(path: Path) -> tuple[Path, dict]:
    plain(STAGES)
    require(STAGES.resolve() == STAGES.absolute(), 'The staging root must be a plain checkout directory.')
    plain(path)
    stage = path.resolve()
    require(stage.name == 'archive_tools' and stage.parent.parent == STAGES.resolve()
            and re.fullmatch(r'[a-f0-9]{32}', stage.parent.name) is not None,
            'A package stage must be the exact generated directory inside .release-staging.')
    plain(stage.parent / 'manifest.json')
    metadata = json.loads((stage.parent / 'manifest.json').read_text(encoding='utf-8'))
    require(metadata.get('format') == 1 and metadata.get('profile') == 'runtime'
            and (metadata.get('appId'), metadata.get('version')) == identity(),
            'The stage identity differs from this checkout.')
    return stage, metadata


def atomic_write(path: Path, content: str) -> None:
    plain(path)
    temporary = path.parent / ('.' + path.name + '.' + uuid.uuid4().hex + '.tmp')
    try:
        with temporary.open('x', encoding='utf-8', newline='\n') as target:
            target.write(content)
        temporary.replace(path)
    finally:
        if temporary.is_file():
            temporary.unlink()


def pack(stage: Path, metadata: dict, require_signature: bool) -> dict:
    files = {}
    for path in sorted(stage.rglob('*')):
        plain(path)
        require(path.is_file() or path.is_dir(), 'The stage contains a special entry.')
        if path.is_file():
            files[path.relative_to(stage).as_posix()] = path.read_bytes()
    hashes = {key: digest(value) for key, value in files.items() if key != SIGNATURE}
    require(hashes == metadata['files'], 'The staged file set or contents changed after preparation.')
    current = runtime_files()
    require(set(hashes) == set(current) | {'README.md', 'SOURCE.md'},
            'The stage contains files outside the runtime distribution profile.')
    require(all(key in files and content == files[key] for key, content in current.items()),
            'The tested runtime source changed after preparation.')
    if require_signature:
        require(SIGNATURE in files, 'Sign the prepared application tree before building a release package.')
        require(metadata['sourceCommit'] and not metadata['sourceDirty']
                and git_value(['rev-parse', '--verify', 'HEAD'], required=True) == metadata['sourceCommit']
                and not git_value(['status', '--porcelain'], required=True),
                'A public release requires the exact reviewed clean source commit.')
        signature = json.loads(files[SIGNATURE])
        require(isinstance(signature.get('hashes'), dict) and signature.get('certificate')
                and signature.get('signature'), 'The code-signing envelope is incomplete.')
        signed_hashes = {key.lstrip('/'): value for key, value in signature['hashes'].items()}
        expected = {key: hashlib.sha512(value).hexdigest() for key, value in files.items() if key != SIGNATURE}
        require(signed_hashes == expected, 'The signing envelope does not cover the exact staged files.')
    epoch = int(os.environ.get('SOURCE_DATE_EPOCH', '0'))
    require(0 <= epoch <= 2147483647, 'SOURCE_DATE_EPOCH is outside the supported range.')
    plain(DIST)
    DIST.mkdir(exist_ok=True)
    output = DIST / f"{metadata['appId']}-{metadata['version']}.tar.gz"
    temporary = DIST / ('.' + output.name + '.' + uuid.uuid4().hex + '.tmp')
    try:
        with temporary.open('xb') as raw, gzip.GzipFile(filename='', mode='wb', fileobj=raw, mtime=0) as compressed:
            with tarfile.open(fileobj=compressed, mode='w', format=tarfile.PAX_FORMAT) as archive:
                directories = {metadata['appId']}
                for relative in files:
                    path = Path(relative).parent
                    while path != Path('.'):
                        directories.add(metadata['appId'] + '/' + path.as_posix())
                        path = path.parent
                for directory in sorted(directories):
                    item = tarfile.TarInfo(directory + '/')
                    item.type, item.mode, item.mtime = tarfile.DIRTYPE, 0o755, epoch
                    archive.addfile(item)
                for relative, content in sorted(files.items()):
                    item = tarfile.TarInfo(metadata['appId'] + '/' + relative)
                    item.size = len(content)
                    item.mode = 0o755 if relative in EXECUTABLES else 0o644
                    item.mtime = epoch
                    archive.addfile(item, io.BytesIO(content))
        require(temporary.stat().st_size <= 20000000, 'The archive exceeds the App Store upload budget.')
        temporary.replace(output)
    finally:
        if temporary.is_file():
            temporary.unlink()
    checksum = digest(output.read_bytes())
    atomic_write(Path(str(output) + '.sha256'), checksum + '  ' + output.name + '\n')
    report = {'profile': 'runtime', 'version': metadata['version'], 'package': str(output),
              'sha256': checksum, 'files': len(files), 'stage': str(stage),
              'signaturePresent': SIGNATURE in files, 'releaseEnvelopeChecked': require_signature,
              'sourceCommit': metadata['sourceCommit'], 'sourceDirty': metadata['sourceDirty']}
    atomic_write(Path(str(output) + '.build.json'), json.dumps(report, indent=2) + '\n')
    return report


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--prepare', action='store_true', help='Prepare and retain a stage for signing; do not create the tarball.')
    parser.add_argument('--stage', type=Path, help='Package a previously prepared stage, including its generated signature.json.')
    parser.add_argument('--require-signature', action='store_true', help='Require exact signed file hashes and a clean source commit; core integrity verification is a separate release gate.')
    args = parser.parse_args()
    require(not (args.prepare and args.stage), '--prepare and --stage cannot be combined.')
    stage, metadata = read_stage(args.stage) if args.stage else prepare()
    result = {'stage': str(stage), 'version': metadata['version'], 'status': 'prepared'} if args.prepare else pack(stage, metadata, args.require_signature)
    print(json.dumps(result, ensure_ascii=True))


if __name__ == '__main__':
    main()
