"""Opt-in transport for disposable CI stacks; never accepts the development project."""
from __future__ import annotations

import json
import importlib.util
import os
from pathlib import Path
import re
import urllib.parse

ROOT = Path(__file__).resolve().parents[1]
LABEL = 'com.archive-tools.ci-token'

_process_spec = importlib.util.spec_from_file_location('archive_tools_ci_process', ROOT / 'ci' / 'process.py')
_process_module = importlib.util.module_from_spec(_process_spec)
_process_spec.loader.exec_module(_process_module)
run_process = _process_module.run_process


def load_config(filename: str | None = None) -> dict | None:
    filename = filename if filename is not None else os.environ.get('ARCHIVE_TOOLS_CI_CONFIG')
    if not filename:
        return None
    original = Path(filename).absolute()
    path = original.resolve(strict=True)
    runs = ROOT / '.ci-runs'
    if (runs.is_symlink() or runs.resolve() != runs.absolute() or original != path
            or path.name != 'config.json' or path.parent.parent != runs or path.is_symlink()):
        raise ValueError('CI configuration must be in this checkout .ci-runs/<token>/config.json.')
    data = json.loads(path.read_text(encoding='utf-8'))
    token = data.get('token', '')
    if (data.get('schemaVersion') != 1 or not re.fullmatch('[a-f0-9]{32}', token)
            or path.parent.name != token or data.get('project') != 'archive-tools-ci-' + token[:12]):
        raise ValueError('Invalid disposable CI project identity.')
    parsed = urllib.parse.urlsplit(data.get('baseUrl', ''))
    if (parsed.scheme != 'http' or parsed.hostname != '127.0.0.1'
            or parsed.username is not None or parsed.password is not None
            or not 18081 <= (parsed.port or 0) <= 18999 or parsed.path or parsed.query or parsed.fragment):
        raise ValueError('CI requires an explicit loopback-only port between 18081 and 18999.')
    for field, name in [('composeFile', 'compose.json'), ('environmentFile', '.env')]:
        original_candidate = Path(data.get(field, '')).absolute()
        candidate = original_candidate.resolve(strict=True)
        if candidate != path.parent / name or original_candidate != candidate:
            raise ValueError('CI files must stay in their owning run directory.')
    compose = json.loads(Path(data['composeFile']).read_text(encoding='utf-8'))
    service = compose.get('services', {}).get('nextcloud', {})
    if (compose.get('name') != data['project'] or service.get('labels', {}).get(LABEL) != token
            or service.get('ports') != [f"127.0.0.1:{parsed.port}:80"]):
        raise ValueError('Generated Compose identity or loopback port does not match CI configuration.')
    for name, definition in compose.get('volumes', {}).items():
        if name not in ('nextcloud', 'database', 'redis') or definition != {'labels': {LABEL: token}}:
            raise ValueError('CI volume definitions must be private, unnamed and token-labelled.')
    if 'nextcloud' not in compose.get('volumes', {}):
        raise ValueError('CI requires its private Nextcloud volume.')
    for name, definition in compose.get('services', {}).items():
        if name not in ('nextcloud', 'database', 'redis') or definition.get('labels', {}).get(LABEL) != token:
            raise ValueError('CI services must carry the owning run token.')
        if name != 'nextcloud' and definition.get('ports'):
            raise ValueError('CI database/cache must not publish host ports.')
    if data.get('nextcloud') not in ('34', '35') or data.get('database') not in ('pgsql', 'sqlite', 'mariadb', 'mysql'):
        raise ValueError('Unknown CI matrix configuration.')
    return data


def assert_container(config: dict, labels: dict, ports: dict, mounts: list[dict], volume_labels: dict) -> None:
    project, token = config['project'], config['token']
    expected_port = str(urllib.parse.urlsplit(config['baseUrl']).port)
    if (labels.get('com.docker.compose.project') != project
            or labels.get('com.docker.compose.service') != 'nextcloud' or labels.get(LABEL) != token):
        raise ValueError('Container does not belong to this disposable CI run.')
    bindings = ports.get('80/tcp') or []
    if len(bindings) != 1 or bindings[0].get('HostIp') != '127.0.0.1' or bindings[0].get('HostPort') != expected_port:
        raise ValueError('CI HTTP port is not the configured loopback-only binding.')
    data = [m for m in mounts if m.get('Destination') == '/var/www/html']
    code = [m for m in mounts if m.get('Destination') == '/var/www/html/custom_apps/archive_tools']
    if (len(data) != 1 or data[0].get('Type') != 'volume' or data[0].get('Name') != project + '_nextcloud'
            or volume_labels.get(LABEL) != token or volume_labels.get('com.docker.compose.project') != project):
        raise ValueError('CI must use its own token-labelled data volume.')
    source = str(code[0].get('Source', '')).replace('\\', '/') if len(code) == 1 else ''
    source = re.sub(r'^/(?:run/desktop/mnt/host|host_mnt)/([a-z])/', r'\1:/', source)
    expected = str(ROOT / 'app').replace('\\', '/')
    matches = source.lower() == expected.lower() if os.name == 'nt' else source == expected
    if len(code) != 1 or code[0].get('RW') is not False or not matches:
        raise ValueError('CI application must be this checkout mounted read-only.')
