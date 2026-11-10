"""Real owned-process regressions; no Docker, network, or existing resources."""
from __future__ import annotations

import ctypes
import json
import os
from pathlib import Path
import signal
import subprocess
import sys
import tempfile
import time
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parent))
import process as process_runner
from process import run_process


def alive(pid: int) -> bool:
    if os.name == 'nt':
        kernel = ctypes.WinDLL('kernel32', use_last_error=True)
        kernel.OpenProcess.restype = ctypes.c_void_p
        handle = kernel.OpenProcess(0x1000, False, pid)  # QUERY_LIMITED_INFORMATION
        if not handle:
            return False
        try:
            status = ctypes.c_ulong()
            return bool(kernel.GetExitCodeProcess(ctypes.c_void_p(handle), ctypes.byref(status))) and status.value == 259
        finally:
            kernel.CloseHandle(ctypes.c_void_p(handle))
    try:
        # An orphan zombie has stopped executing; some container init processes
        # do not immediately reap it, so kill(pid, 0) alone is insufficient.
        stat = Path('/proc') / str(pid) / 'stat'
        if stat.is_file() and stat.read_text().rsplit(')', 1)[1].split()[0] == 'Z':
            return False
        os.kill(pid, 0)
        return True
    except (ProcessLookupError, FileNotFoundError):
        return False


class ProcessTests(unittest.TestCase):
    def test_success_and_nonzero_capture_both_streams(self):
        for status in (0, 7):
            with self.subTest(status=status):
                result = run_process([sys.executable, '-c',
                    'import os, sys; os.write(1, b"stdout\\xff"); os.write(2, b"stderr"); sys.exit(' + str(status) + ')'],
                    cwd=Path(__file__).resolve().parent, timeout=10)
                self.assertEqual(result.returncode, status)
                self.assertEqual(result.stdout, 'stdout\ufffd')
                self.assertEqual(result.stderr, 'stderr')

    def test_timeout_stops_parent_and_grandchild_holding_pipes(self):
        with tempfile.TemporaryDirectory(prefix='archive-tools-process-test-') as temporary:
            directory = Path(temporary)
            child_code = (
                'import json, os, subprocess, sys, time; '
                'child = subprocess.Popen([sys.executable, "-c", "import time; time.sleep(60)"]); '
                'open(sys.argv[1], "w").write(json.dumps([os.getpid(), child.pid])); '
                'print("private-output-marker", flush=True); time.sleep(60)'
            )
            started = time.monotonic()
            with self.assertRaises(subprocess.TimeoutExpired) as caught:
                run_process([sys.executable, '-c', child_code, str(directory / 'owned.json'), 'private-argv-marker'],
                            cwd=directory, timeout=2)
            self.assertLess(time.monotonic() - started, 12)
            self.assertNotIn('private-', str(caught.exception))
            self.assertIsNone(caught.exception.output)
            self.assertIsNone(caught.exception.stderr)
            pids = json.loads((directory / 'owned.json').read_text())
            deadline = time.monotonic() + 3
            while any(alive(pid) for pid in pids) and time.monotonic() < deadline:
                time.sleep(0.025)
            self.assertFalse(any(alive(pid) for pid in pids), 'Owned timeout processes must stop before returning.')

    def test_unconfirmed_tree_stop_is_bounded_and_sanitized(self):
        with tempfile.TemporaryDirectory(prefix='archive-tools-process-test-') as temporary:
            directory = Path(temporary)
            marker = directory / 'owned.json'
            child_code = (
                'import json, os, subprocess, sys, time; '
                'child = subprocess.Popen([sys.executable, "-c", "import time; time.sleep(60)"]); '
                'open(sys.argv[1], "w").write(json.dumps([os.getpid(), child.pid])); '
                'print("private-output-marker", flush=True); time.sleep(60)'
            )
            stop_name = '_stop_windows_tree' if os.name == 'nt' else '_stop_posix_group'
            started = time.monotonic()
            try:
                with patch.object(process_runner, stop_name, return_value=False):
                    with self.assertRaisesRegex(RuntimeError, 'termination could not be confirmed') as caught:
                        run_process([sys.executable, '-c', child_code, str(marker), 'private-argv-marker'],
                                    cwd=directory, timeout=2)
                self.assertLess(time.monotonic() - started, 10)
                self.assertNotIn('private-', str(caught.exception))
            finally:
                # The deliberately failed tree stopper must not leave this
                # test's grandchild running. These PIDs come only from its file.
                if marker.is_file():
                    for pid in reversed(json.loads(marker.read_text())):
                        try:
                            if alive(pid):
                                os.kill(pid, signal.SIGTERM)
                        except OSError:
                            # Windows reports access denied for an already
                            # exited PID as well as for inaccessible processes.
                            if alive(pid):
                                raise


if __name__ == '__main__':
    unittest.main()
