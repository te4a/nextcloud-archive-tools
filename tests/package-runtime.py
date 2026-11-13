#!/usr/bin/env python3
"""Exercise runtime packaging boundaries without Docker or release credentials."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import tarfile
import unittest
import uuid
from unittest.mock import patch

WORKSPACE = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('runtime_package', WORKSPACE / 'dev/package.py')
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)


class RuntimePackageTests(unittest.TestCase):
    def setUp(self):
        # Retained, tiny fixtures stay inside the workspace; no recursive removal.
        self.root = WORKSPACE / '.test-results' / ('package-boundary-' + uuid.uuid4().hex)
        self.root.mkdir(parents=True)
        self.app = self.root / 'app'
        for directory in builder.RUNTIME:
            (self.app / directory).mkdir(parents=True)
        (self.app / 'appinfo/info.xml').write_text('<info><id>archive_tools</id><version>0.5.0-beta.1</version></info>')
        for name in ('js/main.mjs', 'js/files.mjs', 'css/archive-tools.css', 'js/THIRD_PARTY_LICENSES.md'):
            (self.app / name).write_text('public runtime')
        seven_zip = {}
        for name in builder.SEVEN_ZIP_FILES:
            content = ('Fixture for ' + name).encode()
            (self.app / name).write_bytes(content)
            seven_zip[name] = hashlib.sha256(content).hexdigest()
        (self.app / builder.SEVEN_ZIP_NOTICE).write_text('Distribution notice')
        (self.app / 'src').mkdir()
        (self.app / 'src/development.ts').write_text('must stay outside package')
        for name in ('LICENSE', 'CHANGELOG.md'):
            (self.root / name).write_text('public metadata')
        (self.root / 'package.json').write_text('{"version":"0.5.0-beta.1"}')
        self.patches = [patch.object(builder, 'ROOT', self.root), patch.object(builder, 'APP', self.app),
                        patch.object(builder, 'STAGES', self.root / '.release-staging'),
                        patch.object(builder, 'DIST', self.root / 'dist'),
                        patch.object(builder, 'SEVEN_ZIP_FILES', seven_zip)]
        for item in self.patches:
            item.start()
            self.addCleanup(item.stop)

    def test_reproducible_bytes_and_minimal_safe_distribution(self):
        stage, metadata = builder.prepare()
        first = builder.pack(stage, metadata, False)
        original = Path(first['package']).read_bytes()
        other, other_metadata = builder.prepare()
        second = builder.pack(other, other_metadata, False)
        self.assertEqual(original, Path(second['package']).read_bytes())
        with tarfile.open(second['package']) as archive:
            names = archive.getnames()
            self.assertFalse(any('/src/' in name or name.endswith('package.json') for name in names))
            self.assertIn('archive_tools/SOURCE.md', names)
            for member in archive:
                self.assertTrue(member.isfile() or member.isdir())
                self.assertEqual(member.uid, 0)
                self.assertEqual(member.gid, 0)
                executable = member.name.removeprefix('archive_tools/') in builder.EXECUTABLES
                self.assertEqual(member.mode, 0o755 if member.isdir() or executable else 0o644)
            self.assertIn('archive_tools/bin/7z2606-src.tar.xz', names)

    def test_missing_or_changed_decoder_is_rejected(self):
        target = self.app / 'bin/7zz-linux-arm64'
        target.unlink()
        with self.assertRaisesRegex(RuntimeError, 'complete 7-Zip distribution'):
            builder.prepare()
        target.write_bytes(b'Unexpected executable')
        with self.assertRaisesRegex(RuntimeError, '7-Zip file differs'):
            builder.prepare()

    def test_staged_tampering_is_rejected(self):
        stage, metadata = builder.prepare()
        (stage / 'js/main.mjs').write_text('changed after review')
        with self.assertRaisesRegex(RuntimeError, 'changed after preparation'):
            builder.pack(stage, metadata, False)

    def test_new_runtime_source_invalidates_prepared_stage(self):
        stage, metadata = builder.prepare()
        (self.app / 'js/main.mjs').write_text('new source')
        with self.assertRaisesRegex(RuntimeError, 'source changed'):
            builder.pack(stage, metadata, False)

    def test_unexpected_stage_file_rejected_even_with_rewritten_manifest(self):
        stage, metadata = builder.prepare()
        (stage / 'private.key').write_bytes(b'private fixture')
        metadata['files']['private.key'] = hashlib.sha256(b'private fixture').hexdigest()
        with self.assertRaisesRegex(RuntimeError, 'outside the runtime'):
            builder.pack(stage, metadata, False)

    def test_private_runtime_inputs_are_rejected(self):
        for filename in ('.env', 'signing.key', 'certificate.pem', '.test-secrets.json'):
            with self.subTest(filename=filename):
                target = self.app / 'lib' / filename
                target.write_text('private fixture')
                try:
                    with self.assertRaisesRegex(RuntimeError, 'private or development'):
                        builder.prepare()
                finally:
                    target.unlink()

    def test_version_mismatch_is_rejected(self):
        (self.root / 'package.json').write_text('{"version":"0.4.1"}')
        with self.assertRaisesRegex(RuntimeError, 'versions differ'):
            builder.prepare()

    def test_signature_required_fails_closed(self):
        stage, metadata = builder.prepare()
        with self.assertRaisesRegex(RuntimeError, 'Sign the prepared'):
            builder.pack(stage, metadata, True)

    def test_unsigned_source_cannot_be_promoted_with_dummy_signature(self):
        stage, metadata = builder.prepare()
        metadata['sourceDirty'] = True
        (stage / 'appinfo/signature.json').write_text(json.dumps({'hashes': {}, 'certificate': 'dummy', 'signature': 'dummy'}))
        with self.assertRaisesRegex(RuntimeError, 'reviewed clean source commit'):
            builder.pack(stage, metadata, True)

    def test_arbitrary_stage_directory_is_rejected(self):
        with self.assertRaisesRegex(RuntimeError, 'exact generated directory'):
            builder.read_stage(self.app)

    def test_relative_stage_cannot_escape_through_symlink(self):
        outside = self.root.parent / ('package-outside-' + uuid.uuid4().hex)
        outside.mkdir()
        try:
            (self.root / '.release-staging').symlink_to(outside, target_is_directory=True)
        except OSError:
            self.skipTest('Creating symbolic links is unavailable on this host; Linux CI exercises it.')
        with self.assertRaisesRegex(RuntimeError, 'links or junctions'):
            builder.read_stage(Path(os.path.relpath(self.root / '.release-staging' / ('a' * 32) / 'archive_tools')))

    def test_checksum_sidecar_never_follows_symlink(self):
        stage, metadata = builder.prepare()
        builder.DIST.mkdir()
        target = self.root / 'unrelated.txt'
        target.write_text('keep these bytes')
        sidecar = builder.DIST / 'archive_tools-0.5.0-beta.1.tar.gz.sha256'
        try:
            sidecar.symlink_to(target)
        except OSError:
            self.skipTest('Creating symbolic links is unavailable on this host; Linux CI exercises it.')
        with self.assertRaisesRegex(RuntimeError, 'links or junctions'):
            builder.pack(stage, metadata, False)
        self.assertEqual(target.read_text(), 'keep these bytes')

    def test_absolute_outside_input_is_rejected(self):
        with self.assertRaisesRegex(RuntimeError, 'inside this checkout'):
            builder.plain(self.root.parent / 'outside')


if __name__ == '__main__':
    unittest.main(verbosity=2)
