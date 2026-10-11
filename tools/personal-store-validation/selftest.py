"""DB/서비스 연결 없이 실행하는 검증 도구의 거부 경로 회귀 테스트."""
import io
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import tarfile
import tempfile
import unittest
from unittest import mock
from types import SimpleNamespace

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('validation_runner', HERE / 'run.py')
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)


class ValidationToolTest(unittest.TestCase):
    def test_python_sources_compile_without_writing_cache(self):
        for name in ('run.py', 'sandbox.py', 'selftest.py'):
            compile((HERE / name).read_text(encoding='utf-8'), name, 'exec')

    def test_overlap_and_old_workspace_are_rejected(self):
        with tempfile.TemporaryDirectory() as base:
            service = Path(base) / 'service'
            for path in (service, service / 'child', Path(base)):
                with self.assertRaises(RuntimeError):
                    runner.outside(path, service)
            runner.outside(Path(base) / 'fresh', service)
            with self.assertRaises(RuntimeError):
                runner.outside(runner.FORBIDDEN / 'repo', service)

    def test_archive_escape_links_and_special_files_are_rejected(self):
        for name, kind in (('../escape', tarfile.REGTYPE), ('/escape', tarfile.REGTYPE),
                           ('link', tarfile.SYMTYPE), ('hard', tarfile.LNKTYPE), ('device', tarfile.CHRTYPE)):
            data = io.BytesIO()
            with tarfile.open(fileobj=data, mode='w') as archive:
                entry = tarfile.TarInfo(name)
                entry.type = kind
                entry.linkname = 'outside'
                archive.addfile(entry)
            with tempfile.TemporaryDirectory() as base:
                with self.assertRaises(RuntimeError):
                    runner.extract_archive(data.getvalue(), Path(base).resolve())

    def test_normal_archive_is_independent_regular_file(self):
        data = io.BytesIO()
        with tarfile.open(fileobj=data, mode='w') as archive:
            entry = tarfile.TarInfo('directory/file')
            entry.size = 3
            archive.addfile(entry, io.BytesIO(b'abc'))
        with tempfile.TemporaryDirectory() as base:
            root = Path(base).resolve()
            runner.extract_archive(data.getvalue(), root)
            runner.regular_tree(root)
            self.assertEqual(b'abc', (root / 'directory/file').read_bytes())

    def test_hardlinks_are_rejected_and_copyfile_breaks_link(self):
        with tempfile.TemporaryDirectory() as base:
            root = Path(base)
            source = root / 'source'
            source.write_text('data')
            os.link(source, root / 'hard')
            with self.assertRaises(RuntimeError):
                runner.regular_tree(root)
            shutil.copyfile(source, root / 'copy')
            self.assertEqual(1, (root / 'copy').stat().st_nlink)

    def test_password_raw_json_url_and_base64_are_redacted(self):
        from urllib.parse import quote
        import base64
        password = "secret'\\token%"
        for value in (password, json.dumps(password)[1:-1], quote(password, safe=''),
                      base64.b64encode(password.encode()).decode()):
            self.assertNotIn(value, runner.redact(value, password))

    def test_no_business_code_or_service_commands_in_execution_plan(self):
        source = (HERE / 'sandbox.py').read_text(encoding='utf-8')
        self.assertIn("'YUTIV_HOST_NET_NS'", source)
        self.assertIn('--fail-on-skipped', source)
        self.assertNotIn('module:install', source)
        self.assertNotIn('module:activate', source)
        self.assertNotIn('schedule:run', source)

    def test_prepare_copies_vendor_and_never_copies_service_env_or_password_to_files(self):
        with tempfile.TemporaryDirectory() as base:
            service, workspace = Path(base) / 'service', Path(base) / 'workspace'
            (service / 'vendor/phpunit/phpunit').mkdir(parents=True)
            (service / 'vendor/phpunit/phpunit/phpunit').write_text('fake PHPUnit')
            (service / 'composer.lock').write_text('same-lock')
            (service / '.env').write_text('SERVICE_SECRET=never-copy-this')
            workspace.mkdir()
            (workspace / 'logs').mkdir()
            data = io.BytesIO()
            with tarfile.open(fileobj=data, mode='w') as archive:
                for name, content in [('composer.lock', 'same-lock'),
                                      ('bootstrap/app.php', '<?php return null;'),
                                      ('storage/old.log', 'old-runtime'),
                                      ('bootstrap/cache/config.php', 'old-cache')]:
                    entry = tarfile.TarInfo(name)
                    raw = content.encode()
                    entry.size = len(raw)
                    archive.addfile(entry, io.BytesIO(raw))
            args = SimpleNamespace(service=service, database='yutiv_isolated_20261011a',
                                   username='test', php=Path('unused'))
            password = 'synthetic-password-only'
            with mock.patch.object(runner, 'git', return_value=data.getvalue()), \
                    mock.patch.object(runner, 'php_ini'):
                root, env = runner.prepare(args, workspace, password)
            self.assertEqual(password, env['DB_WRITE_PASSWORD'])
            self.assertEqual(env['DB_READ_DATABASE'], env['DB_WRITE_DATABASE'])
            self.assertEqual('1', env['YUTIV_PERSONAL_STORE_CONCURRENCY'])
            for p in workspace.rglob('*'):
                if p.is_file():
                    self.assertNotIn(password, p.read_text(encoding='utf-8'))
                    self.assertNotIn('never-copy-this', p.read_text(encoding='utf-8'))
            self.assertFalse((root / 'storage/old.log').exists())
            self.assertFalse((root / 'bootstrap/cache/config.php').exists())
            self.assertEqual(1, (root / 'vendor/phpunit/phpunit/phpunit').stat().st_nlink)
            self.assertNotEqual((root / 'vendor/phpunit/phpunit/phpunit').stat().st_ino,
                                (service / 'vendor/phpunit/phpunit/phpunit').stat().st_ino)

    def test_prepare_rejects_root_lock_mismatch(self):
        with tempfile.TemporaryDirectory() as base:
            service, workspace = Path(base) / 'service', Path(base) / 'workspace'
            service.mkdir()
            workspace.mkdir()
            (service / 'composer.lock').write_text('service-lock')
            data = io.BytesIO()
            with tarfile.open(fileobj=data, mode='w') as archive:
                entry = tarfile.TarInfo('composer.lock')
                entry.size = 6
                archive.addfile(entry, io.BytesIO(b'target'))
            with mock.patch.object(runner, 'git', return_value=data.getvalue()):
                with self.assertRaisesRegex(RuntimeError, 'composer.lock'):
                    runner.prepare(SimpleNamespace(service=service), workspace, 'fake')

    def test_php_grant_allowlist_rejects_service_global_roles_wildcards(self):
        php = os.environ.get('VALIDATION_TEST_PHP') or shutil.which('php8.3') or shutil.which('php')
        self.assertIsNotNone(php, 'PHP를 지정하세요: VALIDATION_TEST_PHP')
        source = (HERE / 'guard.php').read_text(encoding='utf-8')
        source = source.removeprefix('<?php').replace('YutivValidationGuard::initial();', '')
        allowed = "GRANT ALL PRIVILEGES ON `yutiv\\_isolated\\_20261011a`.* TO 'test'@'localhost'"
        usage = "GRANT USAGE ON *.* TO 'test'@'localhost'"
        cases = [([usage, allowed], 0), ([allowed], 0), ([usage], 78),
                 ([usage, "GRANT ALL PRIVILEGES ON `yutiv_g7`.* TO 'test'@'localhost'"], 78),
                 ([usage, "GRANT ALL PRIVILEGES ON *.* TO 'test'@'localhost'"], 78),
                 ([usage, "GRANT 'role'@'%' TO 'test'@'localhost'"], 78),
                 ([usage, allowed.replace('\\_', '_')], 78),
                 ([usage, allowed + ' WITH GRANT OPTION'], 78),
                 ([usage, allowed, "GRANT SELECT ON `other`.* TO 'test'@'localhost'"], 78)]
        for grants, expected in cases:
            payload = json.dumps(grants)
            # 가상 권한 문자열로만 검사하며 DB에는 연결하지 않습니다.
            import base64
            code = source + '\nputenv("YUTIV_ISOLATED_DB=yutiv_isolated_20261011a");\n' + \
                'YutivValidationGuard::grants(json_decode(base64_decode("' + \
                base64.b64encode(payload.encode()).decode() + '"), true));'
            result = subprocess.run([php, '-r', code], capture_output=True)
            self.assertEqual(expected, result.returncode, result.stderr.decode(errors='replace'))


if __name__ == '__main__':
    unittest.main()
