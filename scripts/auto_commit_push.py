#!/usr/bin/env python3
import os
import signal
import subprocess
import sys
import time
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent
PID_FILE = REPO_ROOT / 'scripts' / '.auto_commit_push.pid'
POLL_INTERVAL = 5


def run(cmd, cwd=None, check=False):
    return subprocess.run(cmd, cwd=cwd, text=True, capture_output=True, check=check)


def ensure_git_repo():
    result = run(['git', 'rev-parse', '--is-inside-work-tree'], cwd=REPO_ROOT)
    if result.returncode != 0:
        raise RuntimeError(result.stderr.strip() or 'Not a git repository')


def get_branch():
    result = run(['git', 'branch', '--show-current'], cwd=REPO_ROOT)
    if result.returncode != 0:
        return 'main'
    branch = result.stdout.strip()
    return branch or 'main'


def get_status():
    result = run(['git', 'status', '--porcelain'], cwd=REPO_ROOT)
    if result.returncode != 0:
        raise RuntimeError(result.stderr.strip() or 'Unable to read git status')
    return result.stdout


def should_skip(status_text):
    if not status_text.strip():
        return True
    skipped_markers = ['.env', '.env.local', '.env.production', '.env.example']
    for line in status_text.splitlines():
        if any(marker in line for marker in skipped_markers):
            return True
    return False


def commit_and_push(branch):
    run(['git', 'add', '-A'], cwd=REPO_ROOT, check=False)
    status_text = get_status()
    if should_skip(status_text):
        return 'skipped'
    if not status_text.strip():
        return 'clean'

    commit_message = f'chore: auto-commit {time.strftime("%Y-%m-%d %H:%M:%S")}'
    commit_result = run(['git', 'commit', '-m', commit_message], cwd=REPO_ROOT, check=False)
    if commit_result.returncode != 0:
        if 'nothing to commit' in commit_result.stdout.lower() or 'nothing to commit' in commit_result.stderr.lower():
            return 'clean'
        raise RuntimeError(commit_result.stderr.strip() or commit_result.stdout.strip())

    push_result = run(['git', 'push', 'origin', branch], cwd=REPO_ROOT, check=False)
    if push_result.returncode != 0:
        raise RuntimeError(push_result.stderr.strip() or push_result.stdout.strip())
    return 'pushed'


def install_pid_file():
    PID_FILE.parent.mkdir(parents=True, exist_ok=True)
    PID_FILE.write_text(str(os.getpid()))


def remove_pid_file():
    try:
        PID_FILE.unlink(missing_ok=True)
    except Exception:
        pass


def handle_signal(signum, _frame):
    remove_pid_file()
    raise SystemExit(0)


if __name__ == '__main__':
    signal.signal(signal.SIGINT, handle_signal)
    signal.signal(signal.SIGTERM, handle_signal)

    if PID_FILE.exists():
        try:
            existing_pid = int(PID_FILE.read_text().strip())
            os.kill(existing_pid, 0)
            print(f'Auto commit watcher already running with pid {existing_pid}')
            sys.exit(0)
        except Exception:
            pass

    install_pid_file()
    ensure_git_repo()

    print(f'Auto commit watcher started for {REPO_ROOT}')
    try:
        while True:
            try:
                branch = get_branch()
                status = get_status()
                if not should_skip(status) and status.strip():
                    result = commit_and_push(branch)
                    print(f'[{time.strftime("%Y-%m-%d %H:%M:%S")}] {result}')
            except Exception as exc:
                print(f'[{time.strftime("%Y-%m-%d %H:%M:%S")}] error: {exc}')
            time.sleep(POLL_INTERVAL)
    finally:
        remove_pid_file()
