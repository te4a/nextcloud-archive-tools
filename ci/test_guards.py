"""Safety regressions run without Docker; CI must never target a retained dev stack."""
from __future__ import annotations

import copy
import importlib.util
import json
import os
from pathlib import Path
import sys
import unittest
from unittest.mock import patch
from unittest.mock import Mock

sys.path.insert(0, str(Path(__file__).resolve().parent))
import run as runner
import transport


class GuardTests(unittest.TestCase):
    def setUp(self):
        self.path, self.config, _ = runner.create_config('34', 'pgsql', 18081)
        self.directory = self.path.parent
        self.compose_path = self.directory / 'compose.json'
        self.compose = json.loads(self.compose_path.read_text(encoding='utf-8'))

    def tearDown(self):
        # Exactly this test's three files, no recursive workspace cleanup.
        for name in ('config.json', 'compose.json', '.env'):
            (self.directory / name).unlink(missing_ok=True)
        self.directory.rmdir()

    def save(self, config=None, compose=None):
        if config is not None: runner.write_json(self.path, config)
        if compose is not None: runner.write_json(self.compose_path, compose)

    def test_valid_disposable_matrix_is_accepted(self):
        self.assertEqual(transport.load_config(str(self.path))['project'], self.config['project'])
        self.assertNotEqual(self.config['project'], 'archive-tools-dev')

    def test_development_project_cannot_be_selected(self):
        config = dict(self.config, project='archive-tools-dev')
        self.save(config=config)
        with self.assertRaises(ValueError): transport.load_config(str(self.path))

    def test_development_port_cannot_be_selected(self):
        config = dict(self.config, baseUrl='http://127.0.0.1:18080')
        self.save(config=config)
        with self.assertRaises(ValueError): transport.load_config(str(self.path))
        with self.assertRaises(ValueError): runner.create_config('34', 'sqlite', 18080)

    def test_remote_origin_and_url_credentials_are_rejected(self):
        for url in ('https://example.org:18081', 'http://127.0.0.2:18081', 'http://admin@127.0.0.1:18081',
                    'http://127.0.0.1:18081/nextcloud', 'http://127.0.0.1:18081?target=dev'):
            with self.subTest(url=url):
                self.save(config=dict(self.config, baseUrl=url))
                with self.assertRaises(ValueError): transport.load_config(str(self.path))

    def test_compose_outside_own_run_is_rejected(self):
        self.save(config=dict(self.config, composeFile=str(transport.ROOT / 'compose.yaml')))
        with self.assertRaises(ValueError): transport.load_config(str(self.path))

    def test_database_and_cache_never_publish_ports(self):
        self.compose['services']['database']['ports'] = ['127.0.0.1:5432:5432']
        self.save(compose=self.compose)
        with self.assertRaises(ValueError): transport.load_config(str(self.path))

    def test_cleanup_cannot_name_a_retained_volume(self):
        self.compose['volumes']['nextcloud']['name'] = 'archive-tools-dev_nextcloud34pgsql'
        self.save(compose=self.compose)
        with self.assertRaises(ValueError): transport.load_config(str(self.path))

    def container(self):
        project, token = self.config['project'], self.config['token']
        labels = {'com.docker.compose.project': project, 'com.docker.compose.service': 'nextcloud', transport.LABEL: token}
        ports = {'80/tcp': [{'HostIp': '127.0.0.1', 'HostPort': '18081'}]}
        mounts = [{'Destination': '/var/www/html', 'Type': 'volume', 'Name': project + '_nextcloud'},
            {'Destination': '/var/www/html/custom_apps/archive_tools', 'Type': 'bind', 'RW': False, 'Source': str(transport.ROOT / 'app')}]
        volume_labels = {transport.LABEL: token, 'com.docker.compose.project': project}
        return [labels, ports, mounts, volume_labels]

    def test_real_container_identity_must_match_all_boundaries(self):
        transport.assert_container(self.config, *self.container())
        changes = [
            lambda args: args[0].update({transport.LABEL: '0' * 32}),
            lambda args: args[1]['80/tcp'][0].update(HostIp='0.0.0.0'),
            lambda args: args[2][0].update(Name='archive-tools-dev_nextcloud34pgsql'),
            lambda args: args[2][1].update(RW=True),
            lambda args: args[2][1].update(Source=str(transport.ROOT / 'tests')),
            lambda args: args[3].update({transport.LABEL: '0' * 32}),
        ]
        for change in changes:
            args = self.container()
            change(args)
            with self.assertRaises(ValueError): transport.assert_container(self.config, *args)

    def test_existing_development_transport_remains_default(self):
        with patch.dict(os.environ, {}, clear=False):
            os.environ.pop('ARCHIVE_TOOLS_CI_CONFIG', None)
            spec = importlib.util.spec_from_file_location('ci_guard_dev_helper', transport.ROOT / 'tests' / 'integration.py')
            helper = importlib.util.module_from_spec(spec)
            spec.loader.exec_module(helper)
        self.assertEqual(helper.PROJECT, 'archive-tools-dev')
        self.assertEqual(helper.test_base_url(), 'http://127.0.0.1:18080')
        self.assertEqual(helper.report_directory(), transport.ROOT / '.test-results')
        with self.assertRaises(helper.TestFailure): helper.validate_base_url(self.config['baseUrl'])

    def test_opt_in_helper_refuses_other_origins(self):
        with patch.dict(os.environ, {'ARCHIVE_TOOLS_CI_CONFIG': str(self.path)}):
            spec = importlib.util.spec_from_file_location('ci_guard_ci_helper', transport.ROOT / 'tests' / 'integration.py')
            helper = importlib.util.module_from_spec(spec)
            spec.loader.exec_module(helper)
        self.assertEqual(helper.test_base_url(), self.config['baseUrl'])
        self.assertEqual(helper.PROJECT, self.config['project'])
        self.assertEqual(helper.report_directory(), self.directory / 'reports')
        with self.assertRaises(helper.TestFailure): helper.validate_base_url('http://127.0.0.1:18080')

    def test_prestart_failure_cleanup_does_not_contact_docker(self):
        instance = runner.Runner.__new__(runner.Runner)
        instance.created = False
        instance.config_path, instance.config = self.path, self.config
        instance.docker = Mock()
        instance.close()
        instance.docker.run.assert_not_called()
        self.assertFalse((self.directory / '.env').exists())


if __name__ == '__main__':
    unittest.main()
