#!/usr/bin/env python3
"""서비스를 마운트하지 않는 Linux 격리 검증 실행기. 비밀번호는 TTY에서만 입력."""
import argparse
import base64
import getpass
import io
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import sys
import tarfile
import tempfile

COMMIT = '2182a90a98a2d123f0262bf2623ae1ead6b73f5e'
SERVICE_HEAD = '50918f16d7ad033ab86c84e598fd3bf2aab168d0'
FORBIDDEN = Path('/tmp/yutiv-ses-test.1XBbpE')


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def git(service, *args):
    return subprocess.check_output(['git', '-c', 'core.fsmonitor=false', '-C', str(service), *args],
                                   env={'PATH': '/usr/bin:/bin', 'GIT_OPTIONAL_LOCKS': '0'},
                                   stderr=subprocess.DEVNULL)


def snapshot(service):
    return {'head': git(service, 'rev-parse', 'HEAD').decode().strip(),
            'status': git(service, 'status', '--porcelain=v1', '--untracked-files=all').decode()}


def outside(path, service):
    path, service = Path(path).resolve(), Path(service).resolve()
    require(not (path == service or service in path.parents or path in service.parents),
            '작업공간과 서비스 경로가 겹칩니다.')
    forbidden = FORBIDDEN.resolve()
    require(not (path == forbidden or forbidden in path.parents), '과거 테스트 경로는 사용할 수 없습니다.')


def regular_tree(root):
    require(root.is_dir() and not root.is_symlink(), '독립적인 일반 디렉터리가 아닙니다.')
    for parent, dirs, files in os.walk(root, followlinks=False):
        for name in dirs + files:
            p = Path(parent) / name
            s = p.lstat()
            require(not stat.S_ISLNK(s.st_mode), '심볼릭 링크가 발견됐습니다.')
            require(stat.S_ISDIR(s.st_mode) or (stat.S_ISREG(s.st_mode) and s.st_nlink == 1),
                    '하드 링크 또는 특수 파일이 발견됐습니다.')


def extract_archive(data, destination):
    # git archive에도 링크가 있을 수 있으므로 추출 전에 모든 항목을 검사합니다.
    with tarfile.open(fileobj=io.BytesIO(data), mode='r:') as archive:
        for m in archive.getmembers():
            require(m.isfile() or m.isdir(), '소스 아카이브 링크/특수 파일은 허용하지 않습니다.')
            p = (destination / m.name).resolve()
            require(destination in p.parents or p == destination, '아카이브 경로 이탈입니다.')
            require(not Path(m.name).is_absolute(), '아카이브 절대 경로입니다.')
        for m in archive.getmembers():
            p = destination / m.name
            if m.isdir():
                p.mkdir(parents=True, exist_ok=True)
            else:
                p.parent.mkdir(parents=True, exist_ok=True)
                with archive.extractfile(m) as source, open(p, 'wb') as target:
                    shutil.copyfileobj(source, target)
                p.chmod(0o600 | (m.mode & 0o100))


def redact(text, password):
    from urllib.parse import quote
    variants = {password, json.dumps(password)[1:-1], quote(password, safe=''),
                password.replace("'", "\\'"), base64.b64encode(password.encode()).decode()}
    for value in sorted(variants, key=len, reverse=True):
        if value:
            text = text.replace(value, '[REDACTED]')
    return text


def php_ini(php, target):
    # 서비스 php.ini나 자격정보를 복사하지 않습니다. 필요한 확장만 새 ini에 선언합니다.
    info = json.loads(subprocess.check_output([str(php), '-n', '-r',
        'echo json_encode([ini_get("extension_dir"),get_loaded_extensions()]);'],
        env={'PATH': '/usr/bin:/bin'}, stderr=subprocess.DEVNULL))
    extensions = ['pdo', 'mysqlnd', 'pdo_mysql', 'bcmath', 'ctype', 'curl', 'dom', 'fileinfo',
                  'iconv', 'mbstring', 'tokenizer', 'xml', 'xmlreader', 'xmlwriter', 'simplexml', 'zip', 'intl']
    lines = ['memory_limit=1G', 'date.timezone=UTC', 'display_errors=0', 'log_errors=1',
             'error_log=' + str(target.parent / 'logs/php.log'),
             'auto_prepend_file=' + str(target.parent / 'guard.php')]
    builtins = {e.lower() for e in info[1]}
    for ext in extensions:
        if ext not in builtins:
            file = Path(info[0]) / (ext + '.so')
            require(file.is_file(), '필수 PHP 확장 파일이 없습니다: ' + ext)
            lines.append('extension=' + str(file))
    target.write_text('\n'.join(lines) + '\n')


def prepare(args, workspace, password):
    root = workspace / 'repo'
    root.mkdir()
    extract_archive(git(args.service, 'archive', '--format=tar', COMMIT), root)
    require((root / 'composer.lock').read_bytes() == (args.service / 'composer.lock').read_bytes(),
            '루트 composer.lock이 서비스와 다릅니다. vendor를 재사용할 수 없습니다.')
    vendor = args.service / 'vendor'
    require(vendor.is_dir(), '서비스 vendor가 없습니다.')
    regular_tree(vendor)
    shutil.copytree(vendor, root / 'vendor', copy_function=shutil.copyfile)
    regular_tree(root)
    require((root / 'vendor/phpunit/phpunit/phpunit').is_file(),
            'vendor에 PHPUnit 개발 의존성이 없습니다. 별도의 동일 lock vendor 준비가 필요합니다.')
    # 추적된 런타임 파일도 재사용하지 않습니다. 삭제 범위는 이번 mkdtemp 사본뿐입니다.
    for relative in ('storage', 'bootstrap/cache'):
        p = root / relative
        if p.exists():
            shutil.rmtree(p)
        p.mkdir(parents=True)
    for p in root.glob('.env*'):
        if p.is_file():
            p.unlink()
    for relative in ('storage/framework/cache/data', 'storage/framework/sessions',
                     'storage/framework/views', 'storage/framework/testing', 'storage/logs'):
        (root / relative).mkdir(parents=True, exist_ok=True)
    for kind in ('modules', 'plugins', 'templates', 'lang-packs'):
        p = root / kind
        if p.exists():
            for child in p.iterdir():
                if child.is_dir() and child.name not in ('_bundled',):
                    shutil.rmtree(child)
    env = {'APP_ENV': 'testing', 'APP_KEY': 'base64:' + base64.b64encode(os.urandom(32)).decode(),
           'APP_URL': 'http://127.0.0.1', 'APP_DEBUG': 'false', 'DB_CONNECTION': 'mysql',
           'DB_DATABASE': args.database, 'DB_HOST': '127.0.0.1', 'DB_PORT': '3306',
           'DB_USERNAME': args.username, 'DB_PASSWORD': password, 'DB_PREFIX': 'iv_',
           'DB_SOCKET': '', 'DB_URL': '', 'DATABASE_URL': '', 'CACHE_STORE': 'array',
           'MAIL_MAILER': 'array', 'QUEUE_CONNECTION': 'sync', 'SESSION_DRIVER': 'array',
           'INSTALLER_COMPLETED': 'false', 'BCRYPT_ROUNDS': '4', 'PULSE_ENABLED': 'false',
           'NIGHTWATCH_ENABLED': 'false', 'YUTIV_PERSONAL_STORE_CONCURRENCY': '1',
           'YUTIV_ISOLATED_ROOT': str(root), 'YUTIV_ISOLATED_DB': args.database,
           'YUTIV_ISOLATED_USER': args.username, 'PHPRC': str(workspace / 'php.ini'),
           'PHP_INI_SCAN_DIR': '', 'PATH': '/usr/bin:/bin', 'HOME': str(workspace / 'home'),
           'TMPDIR': str(workspace / 'tmp')}
    for side in ('WRITE', 'READ'):
        for suffix in ('HOST', 'PORT', 'DATABASE', 'USERNAME', 'PASSWORD'):
            env['DB_' + side + '_' + suffix] = env['DB_' + suffix]
    # 비밀번호는 파일에 쓰지 않습니다. 상속된 환경이 Dotenv보다 우선합니다.
    safe = {k: v for k, v in env.items() if 'PASSWORD' not in k}
    (root / '.env.testing').write_text('\n'.join(k + '=' + v for k, v in safe.items()) + '\n')
    # provider의 .env 존재 검사만 만족시키는 독립 sentinel입니다.
    (root / '.env').write_text('APP_ENV=testing\nDB_WRITE_DATABASE=isolated_sentinel_never_connect\n')
    here = Path(__file__).resolve().parent
    for name in ('guard.php', 'sandbox.py'):
        shutil.copyfile(here / name, workspace / name)
    app = root / 'bootstrap/app.php'
    app.rename(root / 'bootstrap/app.validation-source.php')
    app.write_text('<?php\n$app = require __DIR__."/app.validation-source.php";\n'
                   '\\YutivValidationGuard::attach($app);\nreturn $app;\n')
    (workspace / 'probe.php').write_text('<?php\nrequire getenv("YUTIV_ISOLATED_ROOT")."/vendor/autoload.php";\n'
        '$app=require getenv("YUTIV_ISOLATED_ROOT")."/bootstrap/app.php";\n'
        '$app->loadEnvironmentFrom(".env.testing");\n'
        '$app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();\n'
        'echo "guard-probe-ok\\n";\n')
    for directory in ('home', 'tmp'):
        (workspace / directory).mkdir()
    php_ini(args.php, workspace / 'php.ini')
    return root, env


def sandbox_command(args, workspace):
    command = ['bwrap', '--die-with-parent', '--new-session', '--unshare-user', '--uid', '0', '--gid', '0', '--unshare-pid',
               '--unshare-net', '--unshare-ipc', '--unshare-uts', '--cap-add', 'CAP_NET_ADMIN',
               '--clearenv', '--ro-bind', '/usr', '/usr']
    for p in ('/bin', '/sbin', '/lib', '/lib64'):
        if Path(p).exists():
            command += ['--ro-bind', p, p]
    command += ['--proc', '/proc', '--dev', '/dev', '--tmpfs', '/tmp', '--dir', '/run',
                '--dir', '/etc', '--bind', str(workspace), str(workspace),
                '--ro-bind', str(args.mysql_socket), '/run/test-mysql.sock',
                '--chdir', str(workspace / 'repo'), '--setenv', 'PATH', '/usr/bin:/bin',
                '/usr/bin/python3', str(workspace / 'sandbox.py'), str(workspace), str(args.php)]
    return command


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--service', type=Path, default=Path('/var/www/yutiv-g7'))
    parser.add_argument('--php', type=Path, default=Path('/usr/bin/php8.3'))
    parser.add_argument('--mysql-socket', type=Path, required=True)
    parser.add_argument('--database', required=True)
    parser.add_argument('--username', required=True)
    args = parser.parse_args()
    workspace, before, password, code = None, None, '', 1
    try:
        require(sys.platform.startswith('linux'), 'Linux에서만 실행할 수 있습니다.')
        require(os.geteuid() != 0, 'root 실행을 거부합니다. 일반 검증 사용자로 실행하세요.')
        require(sys.stdin.isatty(), '대화형 TTY가 필요합니다.')
        for exe in ('git', 'bwrap', 'socat', 'ip'):
            require(shutil.which(exe) is not None, '필수 실행 파일이 없습니다: ' + exe)
        args.service = args.service.resolve(strict=True)
        require(args.service == Path('/var/www/yutiv-g7'), '서비스의 실제 경로가 지정 경로와 다릅니다.')
        args.php = args.php.resolve(strict=True)
        require(str(args.php).startswith('/usr/'), 'PHP 실행 파일은 /usr 아래여야 합니다.')
        args.mysql_socket = args.mysql_socket.resolve(strict=True)
        require(stat.S_ISSOCK(args.mysql_socket.stat().st_mode), 'MySQL Unix socket이 아닙니다.')
        require(re.fullmatch(r'yutiv_isolated_[a-z0-9]{8,40}', args.database),
                '전용 DB 이름은 yutiv_isolated_ 뒤에 영소문자/숫자 8~40자여야 합니다.')
        require(re.fullmatch(r'[a-zA-Z0-9_]{1,32}', args.username), '테스트 계정 이름이 올바르지 않습니다.')
        before = snapshot(args.service)
        require(before['head'] == SERVICE_HEAD and before['status'] == '', '서비스 HEAD/깨끗한 상태가 전제와 다릅니다.')
        require(git(args.service, 'rev-parse', COMMIT + '^{commit}').decode().strip() == COMMIT,
                '검증 커밋이 로컬에 없습니다.')
        print('테스트 DB의 기존 테이블/데이터가 migrate:fresh로 삭제될 수 있습니다.', flush=True)
        require(input('삭제를 허용하는 전용 DB 이름을 다시 입력: ').strip() == args.database,
                '테스트 DB 초기화 동의가 없습니다.')
        password = getpass.getpass('테스트 계정 비밀번호(표시 안 함): ')
        require(password and not any(c in password for c in '\r\n\x00'), '비밀번호 입력이 올바르지 않습니다.')
        os.umask(0o077)
        workspace = Path(tempfile.mkdtemp(prefix='yutiv-validation-', dir='/tmp')).resolve()
        outside(workspace, args.service)
        (workspace / 'logs').mkdir()
        print('단계=사본 준비 | 로그=' + str(workspace / 'logs'), flush=True)
        root, env = prepare(args, workspace, password)
        env['YUTIV_HOST_NET_NS'] = os.readlink('/proc/self/ns/net')
        regular_tree(workspace)
        # bwrap stdin으로만 전달하며 argv/임시 비밀번호 파일에 쓰지 않습니다.
        process = subprocess.run(sandbox_command(args, workspace), input=json.dumps(env).encode(),
                                 stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                                 env={'PATH': '/usr/bin:/bin'})
        output = redact(process.stdout.decode(errors='replace'), password)
        (workspace / 'sandbox-summary.log').write_text(output)
        for line in output.splitlines():
            if line.startswith('단계='):
                print(line, flush=True)
        code = process.returncode
        if code:
            print('단계=실패 | 요약=격리 실행이 중단됐습니다. 상세 로그를 확인하세요.', flush=True)
    except (RuntimeError, OSError, subprocess.SubprocessError, ValueError, EOFError) as error:
        # OS/DB 예외 원문에는 인증정보가 들어갈 수 있으므로 알려진 정책 오류만 표시합니다.
        print('단계=중단 | 요약=' + (str(error) if isinstance(error, RuntimeError) else type(error).__name__), flush=True)
        code = 1
    except KeyboardInterrupt:
        code = 130
        print('단계=사용자 중단', flush=True)
    finally:
        if workspace:
            for p in workspace.rglob('*'):
                if p.is_file() and p.suffix in ('.log', '.xml'):
                    p.write_text(redact(p.read_text(errors='replace'), password))
        if before:
            try:
                after = snapshot(args.service)
                same = before == after
                if workspace:
                    (workspace / 'service-git-before-after.json').write_text(
                        json.dumps({'before': before, 'after': after, 'git_unchanged': same}, indent=2))
                print('단계=서비스 Git 비교 | 결과=' + ('통과' if same else '실패'), flush=True)
                if not same:
                    code = 1
            except Exception:
                print('단계=서비스 Git 비교 | 결과=실패', flush=True)
                code = 1
        print('종료 코드=' + str(code) + ' | 로그=' + (str(workspace) if workspace else '작업공간 생성 전'), flush=True)
    return code


if __name__ == '__main__':
    sys.exit(main())
