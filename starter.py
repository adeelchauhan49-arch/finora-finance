"""Standalone starter for the Finance-AI project.

This script checks that the project layout is present, confirms PHP is available,
then launches the app via PHP's built-in web server on a local address. It keeps
running until interrupted so it behaves like a simple project starter.
"""

from __future__ import annotations

import argparse
import os
import shutil
import socket
import subprocess
import sys
import time
import webbrowser
from pathlib import Path


def find_php() -> str | None:
    php_on_path = shutil.which("php")
    if php_on_path:
        return php_on_path

    xampp_php = Path(r"C:\xampp\php\php.exe")
    if xampp_php.is_file():
        return str(xampp_php)

    return None


def wait_for_port(host: str, port: int, timeout: float = 15.0) -> bool:
    deadline = time.time() + timeout
    while time.time() < deadline:
        try:
            with socket.create_connection((host, port), timeout=1):
                return True
        except OSError:
            time.sleep(0.25)
    return False


def ensure_project_layout(project_root: Path) -> None:
    required_items = [
        project_root / "index.html",
        project_root / "includes" / "db.php",
        project_root / "assets",
        project_root / "router.php",
    ]
    missing = [str(item) for item in required_items if not item.exists()]
    if missing:
        raise FileNotFoundError(
            "Project layout looks incomplete. Missing: " + ", ".join(missing)
        )


def start_php_server(project_root: Path, php_path: str, host: str, port: int) -> subprocess.Popen:
    router_script = project_root / "router.php"
    cmd = [php_path, "-S", f"{host}:{port}", "-t", str(project_root), str(router_script)]
    print(f"Starting app server: {' '.join(cmd)}")
    return subprocess.Popen(cmd, cwd=str(project_root))


def print_banner(url: str) -> None:
    print("\n" + "=" * 60)
    print("Finance-AI is running")
    print(f"Open: {url}")
    print("Press Ctrl+C to stop the server.")
    print("=" * 60 + "\n")


def maybe_open_browser(url: str) -> None:
    try:
        webbrowser.open(url, new=2)
    except Exception:
        pass


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Standalone starter for the Finance-AI project."
    )
    parser.add_argument("--host", default="127.0.0.1", help="Host address to bind the PHP server to.")
    parser.add_argument("--port", type=int, default=8000, help="Port to run the app on.")
    parser.add_argument("--no-browser", action="store_true", help="Do not open a browser automatically.")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    project_root = Path(__file__).resolve().parent

    print("Checking project files...")
    try:
        ensure_project_layout(project_root)
    except FileNotFoundError as exc:
        print(f"Error: {exc}", file=sys.stderr)
        return 1

    php_path = find_php()
    if not php_path:
        print("Error: PHP is not installed or not on PATH.", file=sys.stderr)
        print("Install PHP and try again, or run the project through XAMPP/WAMP.", file=sys.stderr)
        return 1

    print("PHP found at:", php_path)
    print(f"Project root: {project_root}")

    try:
        proc = start_php_server(project_root, php_path, args.host, args.port)
    except OSError as exc:
        print(f"Failed to start PHP server: {exc}", file=sys.stderr)
        return 1

    url = f"http://{args.host}:{args.port}"

    if not wait_for_port(args.host, args.port, timeout=10):
        print(f"The PHP server did not start listening on {url}.", file=sys.stderr)
        proc.terminate()
        try:
            proc.wait(timeout=5)
        except subprocess.TimeoutExpired:
            proc.kill()
        return 1

    print_banner(url)
    if not args.no_browser:
        maybe_open_browser(url)

    try:
        proc.wait()
    except KeyboardInterrupt:
        print("\nStopping server...")
        proc.terminate()
        try:
            proc.wait(timeout=5)
        except subprocess.TimeoutExpired:
            proc.kill()
        print("Server stopped.")
        return 0

    return proc.returncode


if __name__ == "__main__":
    raise SystemExit(main())
