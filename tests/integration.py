#!/usr/bin/env python3
"""Exercise Archive Tools only on the isolated archive-tools-dev compose stack."""

from __future__ import annotations

import argparse
import base64
import http.cookiejar
import io
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
import xml.etree.ElementTree as ET
import zipfile


PROJECT = "archive-tools-dev"
SERVICE = "nextcloud"
ROOT = Path(__file__).resolve().parents[1]
CI_TRANSPORT = None
CI_CONFIG = None
if os.environ.get('ARCHIVE_TOOLS_CI_CONFIG'):
    specification = importlib.util.spec_from_file_location('archive_tools_ci_transport', ROOT / 'ci' / 'transport.py')
    CI_TRANSPORT = importlib.util.module_from_spec(specification)
    specification.loader.exec_module(CI_TRANSPORT)
    CI_CONFIG = CI_TRANSPORT.load_config()
    PROJECT = CI_CONFIG['project']
DAV_NS = "DAV:"
OC_NS = "http://owncloud.org/ns"
API = "/index.php/apps/archive_tools"
SHARES = "/ocs/v2.php/apps/files_sharing/api/v1/shares"


class TestFailure(RuntimeError):
    pass


class Report:
    def __init__(self, run_id: str) -> None:
        self.started = time.monotonic()
        self.secrets: list[str] = []
        self.data: dict = {"runId": run_id, "project": PROJECT, "checks": []}

    def redact(self, value: str) -> str:
        for secret in self.secrets:
            if secret:
                value = value.replace(secret, "[redacted]")
        return value

    def check(self, name: str, condition: bool, detail: str = "") -> None:
        self.data["checks"].append({"name": name, "passed": bool(condition)})
        if not condition:
            raise TestFailure(name + (": " + detail if detail else ""))

    def write(self, status: str, error: Exception | None = None) -> Path:
        self.data["status"] = status
        self.data["elapsedSeconds"] = round(time.monotonic() - self.started, 2)
        if error is not None:
            self.data["error"] = self.redact(str(error))
        serialized = self.redact(json.dumps(self.data, ensure_ascii=False, indent=2))
        destination = report_directory()
        destination.mkdir(exist_ok=True)
        result = destination / ("integration-" + self.data["runId"] + ".json")
        result.write_text(serialized + "\n", encoding="utf-8")
        (destination / "latest-integration.json").write_text(serialized + "\n", encoding="utf-8")
        print(serialized)
        print("Result: " + str(result))
        return result


def read_environment() -> dict[str, str]:
    result: dict[str, str] = {}
    env_path = Path(CI_CONFIG['environmentFile']) if CI_CONFIG else ROOT / ".env"
    if not env_path.is_file():
        raise TestFailure("Create the local compose .env before running integration tests.")
    for line in env_path.read_text(encoding="utf-8-sig").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        value = value.strip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in "\"'":
            value = value[1:-1]
        result[key.strip()] = value
    return result


def test_base_url() -> str:
    return CI_CONFIG['baseUrl'] if CI_CONFIG else 'http://127.0.0.1:18080'


def report_directory() -> Path:
    return Path(CI_CONFIG['composeFile']).parent / 'reports' if CI_CONFIG else ROOT / '.test-results'


def validate_base_url(value: str) -> str:
    if CI_CONFIG:
        if value.rstrip('/') != CI_CONFIG['baseUrl']:
            raise TestFailure('HTTP tests must target their token-labelled disposable CI stack.')
        return CI_CONFIG['baseUrl']
    parsed = urllib.parse.urlsplit(value)
    if (parsed.scheme != "http" or parsed.hostname not in ("127.0.0.1", "localhost")
            or parsed.port != 18080 or parsed.username is not None
            or parsed.path not in ("", "/") or parsed.query or parsed.fragment):
        raise TestFailure("Tests are restricted to http://127.0.0.1:18080 or http://localhost:18080.")
    return value.rstrip("/")


def local_docker_endpoint(endpoint: str) -> bool:
    if endpoint.startswith(("npipe://", "unix://")):
        return True
    parsed = urllib.parse.urlsplit(endpoint)
    return parsed.scheme in ("tcp", "http", "https") and parsed.hostname in ("127.0.0.1", "localhost", "::1")


class Docker:
    def __init__(self, report: Report) -> None:
        self.report = report
        self.compose_file = Path(CI_CONFIG['composeFile']) if CI_CONFIG else ROOT / "compose.yaml"
        binary = shutil.which("docker")
        if binary is None:
            candidate = Path(os.environ.get("ProgramFiles", "C:/Program Files")) / "Docker/Docker/resources/bin/docker.exe"
            if candidate.is_file():
                binary = str(candidate)
        if binary is None:
            raise TestFailure("Docker Desktop CLI was not found.")
        self.binary = binary
        self.prefix = [binary, "compose", "--project-name", PROJECT, "--file", str(self.compose_file)]
        if CI_CONFIG:
            self.prefix += ['--env-file', CI_CONFIG['environmentFile']]

    def run(self, arguments: list[str], *, compose: bool = True, timeout: int = 120) -> str:
        command = (self.prefix if compose else [self.binary]) + arguments
        environment = None
        if CI_CONFIG:
            environment = dict(os.environ)
            environment.pop('ARCHIVE_TOOLS_ADMIN_PASSWORD', None)
        if CI_CONFIG:
            result = CI_TRANSPORT.run_process(command, cwd=ROOT, timeout=timeout, env=environment)
        else:
            result = subprocess.run(command, cwd=ROOT, capture_output=True, text=True,
                                    encoding="utf-8", errors="replace", timeout=timeout, env=environment)
        if result.returncode != 0:
            message = (result.stderr or result.stdout).strip()
            raise TestFailure("Docker command failed: " + self.report.redact(message[:1500]))
        return result.stdout.strip()

    def assert_test_stack(self) -> None:
        if CI_CONFIG:
            override = os.environ.get('DOCKER_HOST')
            if override and not local_docker_endpoint(override):
                raise TestFailure('Remote Docker hosts are not allowed for CI tests.')
            endpoint = json.loads(self.run(['context', 'inspect', '--format', '{{json .Endpoints.docker.Host}}'], compose=False))
            if not local_docker_endpoint(endpoint):
                raise TestFailure('The selected Docker context is not local.')
            container = self.run(['ps', '--quiet', SERVICE])
            if not re.fullmatch('[a-f0-9]{12,64}', container):
                raise TestFailure('Exactly one CI nextcloud container is required.')
            labels = json.loads(self.run(['inspect', '--format', '{{json .Config.Labels}}', container], compose=False))
            ports = json.loads(self.run(['inspect', '--format', '{{json .NetworkSettings.Ports}}', container], compose=False))
            mounts = json.loads(self.run(['inspect', '--format', '{{json .Mounts}}', container], compose=False))
            volume_labels = json.loads(self.run(['volume', 'inspect', '--format', '{{json .Labels}}', PROJECT + '_nextcloud'], compose=False))
            CI_TRANSPORT.assert_container(CI_CONFIG, labels, ports, mounts, volume_labels)
            self.report.check('isolated token-labelled CI compose stack', True)
            return
        if not self.compose_file.is_file() or not re.search(
                r"(?m)^name:\s*archive-tools-dev\s*$", self.compose_file.read_text(encoding="utf-8")):
            raise TestFailure("The expected archive-tools-dev compose project was not found.")
        override = os.environ.get("DOCKER_HOST")
        if override and not local_docker_endpoint(override):
            raise TestFailure("Remote Docker hosts are not allowed for this test.")
        endpoint = json.loads(self.run(["context", "inspect", "--format", "{{json .Endpoints.docker.Host}}"], compose=False))
        if not local_docker_endpoint(endpoint):
            raise TestFailure("The selected Docker context is not a local Docker Desktop endpoint.")
        container = self.run(["ps", "--quiet", SERVICE])
        if not container or "\n" in container:
            raise TestFailure("Start exactly one nextcloud service in archive-tools-dev before testing.")
        labels = json.loads(self.run(["inspect", "--format", "{{json .Config.Labels}}", container], compose=False))
        ports = json.loads(self.run(["inspect", "--format", "{{json .NetworkSettings.Ports}}", container], compose=False))
        if labels.get("com.docker.compose.project") != PROJECT or labels.get("com.docker.compose.service") != SERVICE:
            raise TestFailure("The running container does not belong to the dedicated test project.")
        bindings = ports.get("80/tcp") or []
        if not bindings or any(item.get("HostIp") != "127.0.0.1" or item.get("HostPort") != "18080" for item in bindings):
            raise TestFailure("The test HTTP port must be published only on 127.0.0.1:18080.")
        self.report.check("isolated local compose stack", True)

    def add_user(self, user: str) -> None:
        # Expand the password only inside the container. It never enters argv.
        self.run(["exec", "-T", "--user", "www-data", SERVICE, "sh", "-c",
                  'OC_PASS="$NEXTCLOUD_ADMIN_PASSWORD" php occ user:add --password-from-env --display-name "$1" "$1"',
                  "archive-tools-test", user])

    def delete_user(self, user: str) -> None:
        self.run(["exec", "-T", "--user", "www-data", SERVICE,
                  "php", "occ", "user:delete", user])

    def group(self, command: str, *arguments: str) -> None:
        self.run(["exec", "-T", "--user", "www-data", SERVICE,
                  "php", "occ", command, *arguments])


class LocalRedirect(urllib.request.HTTPRedirectHandler):
    def __init__(self, base: str) -> None:
        self.origin = urllib.parse.urlsplit(base)[:2]

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        if urllib.parse.urlsplit(newurl)[:2] != self.origin:
            raise TestFailure("The local server attempted a redirect outside the test origin.")
        return super().redirect_request(req, fp, code, msg, headers, newurl)


class Client:
    def __init__(self, base: str, user: str, password: str, report: Report) -> None:
        base = validate_base_url(base)
        self.base, self.user, self.report = base, user, report
        self.token: str | None = None
        self.auth = "Basic " + base64.b64encode((user + ":" + password).encode()).decode("ascii")
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), LocalRedirect(base))

    def request(self, method: str, path: str, *, data: bytes | None = None,
                headers: dict[str, str] | None = None) -> tuple[int, bytes]:
        if not path.startswith("/") or path.startswith("//"):
            raise TestFailure("Only paths on the local test origin are allowed.")
        request_headers = {"Authorization": self.auth, "Accept": "application/json"}
        if self.token:
            request_headers["requesttoken"] = self.token
        request_headers.update(headers or {})
        request = urllib.request.Request(self.base + path, data=data, headers=request_headers, method=method)
        try:
            with self.opener.open(request, timeout=60) as response:
                return response.status, response.read()
        except urllib.error.HTTPError as error:
            return error.code, error.read()

    def csrf(self) -> None:
        status, body = self.request("GET", "/index.php/csrftoken")
        if status != 200:
            raise TestFailure("Could not create the local test session for " + self.user + ": HTTP " + str(status))
        self.token = json.loads(body)["token"]
        self.report.secrets.append(self.token)

    def api(self, method: str, path: str, payload: dict | None = None) -> tuple[int, object]:
        body = None if payload is None else json.dumps(payload).encode("utf-8")
        status, response = self.request(method, API + path, data=body,
                                        headers={"Content-Type": "application/json"})
        try:
            return status, json.loads(response)
        except (ValueError, UnicodeDecodeError):
            raise TestFailure("Archive API returned a non-JSON response: HTTP " + str(status)) from None

    def ocs(self, method: str, path: str = "", payload: dict | None = None) -> dict:
        body = None if payload is None else urllib.parse.urlencode(payload).encode("utf-8")
        status, response = self.request(method, SHARES + path + "?format=json", data=body,
            headers={"OCS-APIRequest": "true", "Content-Type": "application/x-www-form-urlencoded"})
        try:
            result = json.loads(response)["ocs"]
        except (KeyError, ValueError, UnicodeDecodeError):
            raise TestFailure("The sharing API returned an invalid response: HTTP " + str(status)) from None
        if status not in (200, 201) or result["meta"]["statuscode"] not in (100, 200):
            raise TestFailure("The sharing API rejected a fixture operation: HTTP " + str(status)
                              + ", OCS " + str(result["meta"]["statuscode"]))
        return result

    def dav_path(self, path: str, user: str | None = None) -> str:
        return "/remote.php/dav/files/" + urllib.parse.quote(user or self.user, safe="") + "/" + urllib.parse.quote(path.lstrip("/"), safe="/")

    def dav(self, method: str, path: str, *, data: bytes | None = None,
            headers: dict[str, str] | None = None, user: str | None = None) -> tuple[int, bytes]:
        return self.request(method, self.dav_path(path, user), data=data, headers=headers)

    def properties(self, path: str, depth: int = 0) -> ET.Element:
        body = (f'<d:propfind xmlns:d="{DAV_NS}" xmlns:oc="{OC_NS}">'
                '<d:prop><oc:fileid/><d:quota-used-bytes/><d:resourcetype/></d:prop></d:propfind>').encode()
        status, response = self.dav("PROPFIND", path, data=body,
                                    headers={"Depth": str(depth), "Content-Type": "application/xml"})
        if status != 207:
            raise TestFailure("DAV properties for " + self.user + "/" + path + ": HTTP " + str(status))
        return ET.fromstring(response)

    def file_id(self, path: str) -> int:
        value = self.properties(path).findtext(".//{" + OC_NS + "}fileid")
        if value is None:
            raise TestFailure("DAV did not return a file id for fixture " + path)
        return int(value)

    def quota_used(self) -> int:
        value = self.properties("").findtext(".//{" + DAV_NS + "}quota-used-bytes")
        if value is None:
            raise TestFailure("DAV did not return home quota-used-bytes.")
        return int(value)

    def names(self, path: str) -> set[str]:
        names = set()
        for node in self.properties(path, 1).findall("{" + DAV_NS + "}response"):
            href = node.findtext("{" + DAV_NS + "}href", "")
            decoded = urllib.parse.unquote(urllib.parse.urlsplit(href).path).rstrip("/")
            names.add(decoded.rsplit("/", 1)[-1])
        return names


def fixture_zip() -> tuple[bytes, dict[str, bytes]]:
    files = {
        "readme.txt": b"Archive Tools shared integration fixture.\n",
        "2026": b"A numeric archive member name must remain a string path.\n",
        "nested/data.bin": bytes(range(256)) * 3,
        "кириллица/Привет.txt": "Общий архив доступен участникам папки.\n".encode("utf-8"),
    }
    output = io.BytesIO()
    with zipfile.ZipFile(output, "w", zipfile.ZIP_DEFLATED) as archive:
        for name, content in files.items():
            archive.writestr(name, content)
    return output.getvalue(), files


def run_tests(args: argparse.Namespace, report: Report) -> None:
    base = validate_base_url(args.base_url)
    report.data["baseUrl"] = base
    env = read_environment()
    password = os.environ.get("ARCHIVE_TOOLS_ADMIN_PASSWORD", env.get("ARCHIVE_TOOLS_ADMIN_PASSWORD", ""))
    if not password:
        raise TestFailure("ARCHIVE_TOOLS_ADMIN_PASSWORD is missing from the local compose environment.")
    report.secrets.append(password)
    docker = Docker(report)
    docker.assert_test_stack()
    admin = Client(base, "admin", password, report)
    status, body = admin.request("GET", "/status.php")
    report.check("local Nextcloud installed", status == 200 and json.loads(body).get("installed") is True)
    admin.csrf()

    created: list[str] = []
    clients: dict[str, Client] = {}
    original_settings: dict | None = None
    mount_id: int | None = None
    fixture_group: str | None = None
    completed = False
    cleanup_errors: list[str] = []
    try:
        for role in ("alice", "bob", "carol", "mallory"):
            user = "at_" + report.data["runId"] + "_" + role
            docker.add_user(user)
            created.append(user)
            client = Client(base, user, password, report)
            client.csrf()
            clients[role] = client
        report.data["fixtureUsers"] = {role: client.user for role, client in clients.items()}
        report.check("fresh isolated fixture accounts", len(created) == 4)
        alice, bob, carol, mallory = (clients[role] for role in ("alice", "bob", "carol", "mallory"))

        status, original = admin.api("GET", "/settings")
        report.check("administrator reads settings", status == 200 and isinstance(original, dict))
        original_settings = original
        invalid = dict(original_settings, idleTimeoutMinutes=-1)
        report.check("negative idle timeout rejected", admin.api("PUT", "/settings", invalid)[0] == 400)
        invalid = dict(original_settings, idleTimeoutMinutes=10081)
        report.check("excessive idle timeout rejected", admin.api("PUT", "/settings", invalid)[0] == 400)
        invalid = dict(original_settings, openByClick="true")
        report.check("non-boolean policy rejected", admin.api("PUT", "/settings", invalid)[0] == 400)
        for value in (-1, 3651, "30", False, 1.5):
            invalid = dict(original_settings, jobsRetentionDays=value, openByClick=not original_settings['openByClick'])
            report.check("invalid history retention rejected: " + repr(value), admin.api("PUT", "/settings", invalid)[0] == 400)
        report.check("invalid settings do not partially persist", admin.api("GET", "/settings") == (200, original_settings))
        retention = dict(original_settings, jobsRetentionDays=7)
        report.check("administrator saves history retention", admin.api("PUT", "/settings", retention) == (200, retention))
        legacy = {key: value for key, value in retention.items() if key != 'jobsRetentionDays'}
        report.check("legacy settings request preserves history retention", admin.api("PUT", "/settings", legacy) == (200, retention))
        report.check("history retention settings restored", admin.api("PUT", "/settings", original_settings) == (200, original_settings))
        valid = dict(original_settings, openByClick=not original_settings["openByClick"], idleTimeoutMinutes=0)
        report.check("administrator saves global settings", admin.api("PUT", "/settings", valid) == (200, valid))
        report.check("ordinary user cannot read admin settings", bob.api("GET", "/settings")[0] == 403)
        report.check("ordinary user cannot change global settings", bob.api("PUT", "/settings", valid)[0] == 403)
        report.check("administrator restores global settings", admin.api("PUT", "/settings", original_settings) == (200, original_settings))

        report.check("Alice creates shared fixture folder", alice.dav("MKCOL", "Team")[0] == 201)
        archive, files = fixture_zip()
        report.check("Alice uploads original ZIP", alice.dav("PUT", "Team/fixture.zip", data=archive,
                     headers={"Content-Type": "application/zip"})[0] == 201)
        source_id = alice.file_id("Team/fixture.zip")
        bob_share = alice.ocs("POST", payload={"path": "/Team", "shareType": 0, "shareWith": bob.user, "permissions": 31})["data"]["id"]
        alice.ocs("POST", payload={"path": "/Team", "shareType": 0, "shareWith": carol.user, "permissions": 1})
        report.check("Bob sees the same shared archive id", bob.file_id("Team/fixture.zip") == source_id)
        report.check("Carol sees the same shared archive id", carol.file_id("Team/fixture.zip") == source_id)

        no_pin_policy = dict(original_settings, allowPin=False)
        report.check("administrator disables archive pinning", admin.api("PUT", "/settings", no_pin_policy) == (200, no_pin_policy))
        try:
            status, _ = bob.api("POST", "/mounts", {"sourceId": source_id, "name": "Forbidden pinned archive", "pinned": True})
            report.check("global pinning policy rejects a pinned connection", status == 403, "HTTP " + str(status))
        finally:
            report.check("administrator restores pinning policy", admin.api("PUT", "/settings", original_settings) == (200, original_settings))

        status, _ = carol.api("POST", "/mounts", {"sourceId": source_id, "name": "Denied", "pinned": False})
        report.check("read-only participant cannot create shared connection", status == 403, "HTTP " + str(status))
        quota_before = alice.quota_used()
        mount_name = "Connected archive"
        status, mount = bob.api("POST", "/mounts", {"sourceId": source_id, "name": mount_name, "pinned": False})
        report.check("Bob creates shared archive connection", status == 201 and isinstance(mount, dict), "HTTP " + str(status))
        mount_id = int(mount["id"])
        report.data["connection"] = {"id": mount_id, "sourceId": source_id, "name": mount_name}

        for role in ("alice", "bob", "carol"):
            client = clients[role]
            report.check(role + " sees the shared virtual folder", mount_name in client.names("Team"))
            status, listing = client.api("GET", "/mounts")
            report.check(role + " sees the same connection id", status == 200 and any(int(item["id"]) == mount_id for item in listing))
            root_names = client.names("Team/" + mount_name)
            report.check(role + " lists archive root names including the numeric member",
                         {"readme.txt", "nested", "кириллица", "2026"}.issubset(root_names))
            report.check(role + " lists the nested archive directory",
                         "data.bin" in client.names("Team/" + mount_name + "/nested"))
            report.check(role + " lists the Unicode archive directory",
                         "Привет.txt" in client.names("Team/" + mount_name + "/кириллица"))
            for name, expected in files.items():
                status, content = client.dav("GET", "Team/" + mount_name + "/" + name)
                report.check(role + " reads exact bytes: " + name, status == 200 and content == expected, "HTTP " + str(status))
        quota_after = alice.quota_used()
        report.data["homeQuotaUsed"] = {"beforeMount": quota_before, "afterMount": quota_after}
        report.check("virtual contents do not consume home quota", quota_before == quota_after,
                     str(quota_before) + " -> " + str(quota_after))
        report.check("read-only participant cannot disconnect for everyone", carol.api("DELETE", "/mounts/" + str(mount_id))[0] == 403)
        status, mounts = mallory.api("GET", "/mounts")
        report.check("unrelated user cannot enumerate the connection", status == 200 and all(int(item["id"]) != mount_id for item in mounts))
        status, _ = mallory.dav("GET", "Team/" + mount_name + "/readme.txt")
        report.check("unrelated user cannot read through own DAV path", status in (403, 404))
        status, _ = mallory.dav("GET", "Team/" + mount_name + "/readme.txt", user=alice.user)
        report.check("unrelated user cannot read through guessed owner DAV path", status in (403, 404))

        alice.ocs("POST", payload={"path": "/Team", "shareType": 0, "shareWith": mallory.user, "permissions": 1})
        # The first request after receiving this share must resolve the existing
        # connection without warming source ids or the application's mount API.
        status, content = mallory.dav("GET", "Team/" + mount_name + "/readme.txt")
        report.check("new participant reads an existing connection on the first DAV request",
                     status == 200 and content == files["readme.txt"], "HTTP " + str(status))
        report.check("new participant sees the existing connection in the shared folder",
                     mount_name in mallory.names("Team"))

        status, _ = bob.dav("MOVE", "Team", headers={"Destination": base + bob.dav_path("Project"), "Overwrite": "F"})
        report.check("recipient can rename the local share alias", status in (201, 204), "HTTP " + str(status))
        status, content = bob.dav("GET", "Project/" + mount_name + "/readme.txt")
        report.check("connection survives recipient alias rename", status == 200 and content == files["readme.txt"])
        for role in ("alice", "carol"):
            status, content = clients[role].dav("GET", "Team/" + mount_name + "/readme.txt")
            report.check(role + " keeps the original shared path", status == 200 and content == files["readme.txt"])

        alice.ocs("DELETE", "/" + str(bob_share))
        status, _ = bob.dav("GET", "Project/" + mount_name + "/readme.txt")
        report.check("revocation blocks the recipient's cached DAV path", status in (403, 404), "HTTP " + str(status))
        status, mounts = bob.api("GET", "/mounts")
        report.check("revocation removes connection metadata from recipient view", status == 200 and all(int(item["id"]) != mount_id for item in mounts))
        status, content = carol.dav("GET", "Team/" + mount_name + "/readme.txt")
        report.check("revocation preserves other participants' access", status == 200 and content == files["readme.txt"])

        status, _ = alice.api("DELETE", "/mounts/" + str(mount_id))
        report.check("folder owner can disconnect the shared connection", status == 200)
        mount_id = None
        status, _ = carol.dav("GET", "Team/" + mount_name + "/readme.txt")
        report.check("disconnection removes virtual contents for recipients", status == 404)
        status, source = alice.dav("GET", "Team/fixture.zip")
        report.check("disconnection preserves the original archive bytes", status == 200 and source == archive)

        fixture_group = "at_" + report.data["runId"] + "_readers"
        docker.group("group:add", fixture_group)
        docker.group("group:adduser", fixture_group, bob.user)
        docker.group("group:adduser", fixture_group, carol.user)
        report.check("Alice creates a group-shared fixture folder", alice.dav("MKCOL", "GroupTeam")[0] == 201)
        report.check("Alice uploads the group archive", alice.dav("PUT", "GroupTeam/fixture.zip", data=archive)[0] == 201)
        group_source = alice.file_id("GroupTeam/fixture.zip")
        alice.ocs("POST", payload={"path": "/GroupTeam", "shareType": 1, "shareWith": fixture_group, "permissions": 1})
        status, group_mount = alice.api("POST", "/mounts", {"sourceId": group_source, "name": mount_name})
        report.check("owner connects an archive in a group-shared folder", status == 201)
        mount_id = int(group_mount["id"])
        for role in ("bob", "carol"):
            status, content = clients[role].dav("GET", "GroupTeam/" + mount_name + "/readme.txt")
            report.check(role + " reads the archive through a group share", status == 200 and content == files["readme.txt"])
        status, _ = mallory.dav("GET", "GroupTeam/" + mount_name + "/readme.txt")
        report.check("non-member cannot read the group-shared archive", status in (403, 404))
        docker.group("group:removeuser", fixture_group, carol.user)
        status, _ = carol.dav("GET", "GroupTeam/" + mount_name + "/readme.txt")
        report.check("removing group membership revokes archive access", status in (403, 404))
        status, content = bob.dav("GET", "GroupTeam/" + mount_name + "/readme.txt")
        report.check("remaining group member keeps archive access", status == 200 and content == files["readme.txt"])
        report.check("owner disconnects a group-shared archive", alice.api("DELETE", "/mounts/" + str(mount_id))[0] == 200)
        mount_id = None
        completed = True
    finally:
        if original_settings is not None:
            try:
                status, _ = admin.api("PUT", "/settings", original_settings)
                if status != 200:
                    cleanup_errors.append("Could not restore admin settings: HTTP " + str(status))
            except Exception as error:
                cleanup_errors.append("Could not restore admin settings: " + report.redact(str(error)))
        # Keep failed fixtures for diagnosis; never delete pre-existing users.
        if completed and not args.keep_fixtures:
            if mount_id is not None and "alice" in clients:
                try:
                    clients["alice"].api("DELETE", "/mounts/" + str(mount_id))
                except Exception as error:
                    cleanup_errors.append("Could not remove fixture connection: " + report.redact(str(error)))
            for user in reversed(created):
                try:
                    docker.delete_user(user)
                except Exception as error:
                    cleanup_errors.append("Could not remove fixture user " + user + ": " + report.redact(str(error)))
            if fixture_group is not None:
                try:
                    docker.group("group:delete", fixture_group)
                except Exception as error:
                    cleanup_errors.append("Could not remove fixture group: " + report.redact(str(error)))
        report.data["fixturesKept"] = bool(created) and (not completed or args.keep_fixtures or bool(cleanup_errors))
        if cleanup_errors:
            report.data["cleanupErrors"] = cleanup_errors
            if completed:
                raise TestFailure("Functional tests passed, but fixture cleanup did not complete.")


def main() -> int:
    if hasattr(sys.stdout, 'reconfigure'):
        sys.stdout.reconfigure(encoding='utf-8')
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base-url", default=test_base_url())
    parser.add_argument("--keep-fixtures", action="store_true", help="Keep successful fixture accounts for manual inspection.")
    args = parser.parse_args()
    run_id = time.strftime("%Y%m%d%H%M%S", time.gmtime()) + "_" + uuid.uuid4().hex[:6]
    report = Report(run_id)
    try:
        run_tests(args, report)
    except Exception as error:
        report.write("failed", error)
        return 1
    report.write("passed")
    return 0


if __name__ == "__main__":
    sys.exit(main())
