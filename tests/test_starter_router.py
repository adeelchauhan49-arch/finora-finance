import http.client
import shutil
import socket
import subprocess
import time
import unittest
from pathlib import Path


class StarterRouterTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.root = Path(__file__).resolve().parents[1]
        cls.php = shutil.which('php') or r'C:\xampp\php\php.exe'
        if not Path(cls.php).is_file():
            raise unittest.SkipTest('PHP CLI is not installed.')

        with socket.socket() as probe:
            probe.bind(('127.0.0.1', 0))
            cls.port = probe.getsockname()[1]

        cls.server = subprocess.Popen(
            [cls.php, '-S', f'127.0.0.1:{cls.port}', '-t', str(cls.root), str(cls.root / 'router.php')],
            cwd=cls.root,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
        deadline = time.monotonic() + 10
        while time.monotonic() < deadline:
            if cls.server.poll() is not None:
                raise RuntimeError('PHP built-in server exited before tests started.')
            try:
                with socket.create_connection(('127.0.0.1', cls.port), timeout=0.25):
                    break
            except OSError:
                time.sleep(0.1)
        else:
            cls.server.terminate()
            cls.server.wait(timeout=5)
            raise RuntimeError('PHP built-in server did not start in time.')

    @classmethod
    def tearDownClass(cls):
        if getattr(cls, 'server', None) and cls.server.poll() is None:
            cls.server.terminate()
            cls.server.wait(timeout=5)

    def request_status(self, path, read_body=False):
        connection = http.client.HTTPConnection('127.0.0.1', self.port, timeout=5)
        connection.request('GET', path)
        response = connection.getresponse()
        status = response.status
        body = response.read() if read_body else b''
        connection.close()
        return status, body

    def test_private_paths_are_forbidden_without_reading_responses(self):
        protected_paths = (
            '/.env',
            '/.env.example',
            '/.env.backup',
            '/.git/config',
            '/database/premium_upgrade.sql',
            '/composer.json',
            '/composer.lock',
            '/SETUP.md',
            '/tests/test_settings.py',
            '/vendor/autoload.php',
            '/starter.py',
            '/%2eenv',
            '/%64atabase/premium_upgrade.sql',
        )
        for path in protected_paths:
            with self.subTest(path=path):
                status, _ = self.request_status(path)
                self.assertEqual(403, status)

    def test_static_and_authenticated_api_routes_continue_to_work(self):
        root_status, root_body = self.request_status('/', read_body=True)
        self.assertEqual(200, root_status)
        self.assertIn(b'Finora', root_body)

        index_status, index_body = self.request_status('/index.html', read_body=True)
        self.assertEqual(200, index_status)
        self.assertIn(b'Finora', index_body)

        session_status, session_body = self.request_status('/auth/session.php', read_body=True)
        self.assertEqual(200, session_status)
        self.assertIn(b'"ok":true', session_body)

    def test_starter_always_supplies_the_router_script(self):
        starter = self.root.joinpath('starter.py').read_text(encoding='utf-8')
        self.assertIn('project_root / "router.php"', starter)
        self.assertIn('str(router_script)', starter)
        self.assertNotIn('stdout=subprocess.PIPE', starter)


if __name__ == '__main__':
    unittest.main()
