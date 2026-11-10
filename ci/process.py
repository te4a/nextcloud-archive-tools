"""Bounded execution and cleanup of one process tree owned by a CI invocation."""
from __future__ import annotations

import os
from pathlib import Path
import signal
import subprocess


_DRAIN_SECONDS = 2
_STOP_SECONDS = 15


def _close_pipes(process: subprocess.Popen[str]) -> None:
    # Windows communicate() uses daemon reader threads. If a descendant kept a
    # pipe open, closing its TextIOWrapper here could block on that thread's
    # read lock. Leave those references to the readers on this failed-stop path.
    if os.name == 'nt':
        return
    for pipe in (process.stdout, process.stderr):
        if pipe is not None:
            pipe.close()


def _stop_windows_tree(process: subprocess.Popen[str]) -> bool:
    # Keep the parent alive until taskkill has traversed its descendants. Killing
    # docker.exe first can strand docker-compose.exe and its inherited pipes.
    taskkill = Path(os.environ.get('SystemRoot', r'C:\Windows')) / 'System32' / 'taskkill.exe'
    stopper = None
    try:
        stopper = subprocess.Popen(
            [str(taskkill), '/PID', str(process.pid), '/T', '/F'],
            stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL, creationflags=subprocess.CREATE_NO_WINDOW,
        )
        return stopper.wait(timeout=_STOP_SECONDS) == 0
    except (OSError, subprocess.TimeoutExpired):
        if stopper is not None:
            stopper.kill()
            try:
                stopper.wait(timeout=_DRAIN_SECONDS)
            except subprocess.TimeoutExpired:
                pass
        return False


def _stop_posix_group(process: subprocess.Popen[str]) -> bool:
    # start_new_session gives this child its own group, including grandchildren.
    # SIGKILL immediately follows TERM: this is an interrupted CI command, and
    # mutations must stop before the caller begins its restore/cleanup path.
    try:
        os.killpg(process.pid, signal.SIGTERM)
    except ProcessLookupError:
        return True
    except OSError:
        return False
    try:
        os.killpg(process.pid, signal.SIGKILL)
    except ProcessLookupError:
        pass
    except OSError:
        return False
    return True


def _stop_and_drain(process: subprocess.Popen[str]) -> bool:
    confirmed = _stop_windows_tree(process) if os.name == 'nt' else _stop_posix_group(process)
    if not confirmed:
        # Still stop the exact child we started. This does not establish that
        # descendants stopped, so the caller receives a distinct cleanup error.
        try:
            process.kill()
        except OSError:
            pass
    try:
        process.communicate(timeout=_DRAIN_SECONDS)
    except (OSError, subprocess.TimeoutExpired):
        confirmed = False
        _close_pipes(process)
    return confirmed


def run_process(command: list[str], *, cwd: Path, env: dict | None = None,
                timeout: float) -> subprocess.CompletedProcess[str]:
    """Capture a child command, stopping its owned tree before a timeout escapes.

    Timeout and cleanup errors deliberately omit argv, environment, and captured
    output; callers may publish these exceptions in sanitized CI reports.
    """
    try:
        process = subprocess.Popen(
            command, cwd=cwd, env=env, stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            text=True, encoding='utf-8', errors='replace',
            creationflags=subprocess.CREATE_NO_WINDOW if os.name == 'nt' else 0,
            start_new_session=os.name != 'nt',
        )
    except OSError:
        raise RuntimeError('Unable to start the owned child process.') from None
    try:
        stdout, stderr = process.communicate(timeout=timeout)
    except BaseException as error:
        if not _stop_and_drain(process):
            raise RuntimeError('Owned child process tree termination could not be confirmed.') from None
        if isinstance(error, subprocess.TimeoutExpired):
            raise subprocess.TimeoutExpired('owned child process', timeout) from None
        raise
    return subprocess.CompletedProcess(command, process.returncode, stdout, stderr)
