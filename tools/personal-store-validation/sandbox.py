#!/usr/bin/env python3
"""네트워크 namespace 안에서만 실행되는 순차 테스트 실행기."""
import json
import os
from pathlib import Path
import socket
import subprocess
import sys
import time
import xml.etree.ElementTree as ET


def scrub(text, password):
    from urllib.parse import quote
    for value in sorted({password, json.dumps(password)[1:-1], quote(password, safe=''),
                         password.replace("'", "\\'")}, key=len, reverse=True):
        if value:
            text = text.replace(value, '[REDACTED]')
    return text


def main():
    workspace, php = Path(sys.argv[1]), sys.argv[2]
    env = json.load(sys.stdin)
    os.environ.clear()
    os.environ.update(env)
    root, logs = workspace / 'repo', workspace / 'logs'
    relay = None
    try:
        if os.readlink('/proc/self/ns/net') == env['YUTIV_HOST_NET_NS']:
            raise RuntimeError('network namespace')
        subprocess.run(['ip', 'link', 'set', 'lo', 'up'], check=True,
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        routes = subprocess.check_output(['ip', '-j', 'route', 'show'])
        if json.loads(routes) or Path('/var/www/yutiv-g7').exists():
            raise RuntimeError('isolation')
        # 외부 route/interface가 없고, 이 TCP 포트만 host Unix socket으로 전달합니다.
        relay_log = open(logs / 'mysql-relay.log', 'wb')
        relay = subprocess.Popen(['socat', 'TCP4-LISTEN:3306,bind=127.0.0.1,reuseaddr,fork',
                                  'UNIX-CONNECT:/run/test-mysql.sock'], stdout=relay_log, stderr=relay_log)
        for _ in range(100):
            try:
                with socket.create_connection(('127.0.0.1', 3306), timeout=0.1):
                    break
            except OSError:
                time.sleep(0.05)
        else:
            raise RuntimeError('relay')
        # 부모 및 새 PHP 프로세스 각각 migration 전에 동일 가드를 통과해야 합니다.
        stages = [('parent-preflight', [str(workspace / 'probe.php')]),
                  ('child-preflight', [str(workspace / 'probe.php')])]
        personal = 'modules/_bundled/yutiv-personal_store/tests'
        ecommerce = 'modules/_bundled/sirsoft-ecommerce/tests/Unit/Services/'
        selections = [('personal-store-all', [personal]),
                      ('line-calculation', [ecommerce + 'RepeatedLineCalculationTest.php']),
                      ('line-shipping', [ecommerce + 'RepeatedLineProcessingTest.php']),
                      ('line-split', [ecommerce + 'RepeatedOrderLineTest.php']),
                      ('order-processing', [ecommerce + 'OrderProcessingServiceTest.php']),
                      ('cancel-refund', [ecommerce + 'OrderCancellationServiceTest.php'])]
        for name, paths in selections:
            stages.append((name, ['vendor/phpunit/phpunit/phpunit', '-c', 'phpunit.xml',
                '--colors=never', '--fail-on-skipped', '--fail-on-risky', '--fail-on-warning',
                '--cache-directory', str(workspace / 'phpunit-cache'),
                '--log-junit', str(logs / (name + '.xml')), *paths]))
        for name, command in stages:
            log = logs / (name + '.log')
            print('단계=' + name + ' | 상태=시작 | 로그=' + str(log), flush=True)
            with open(log, 'wb') as output:
                process = subprocess.Popen([php, *command], cwd=root, env=env,
                                           stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
                for line in iter(process.stdout.readline, b''):
                    output.write(scrub(line.decode(errors='replace'), env['DB_PASSWORD']).encode())
                code = process.wait()
            if (logs / (name + '.xml')).exists():
                xml = logs / (name + '.xml')
                xml.write_text(scrub(xml.read_text(), env['DB_PASSWORD']))
                report = ET.parse(logs / (name + '.xml')).getroot()
                if not list(report.iter('testcase')):
                    code = 1
                if code:
                    print('단계=' + name + ' | 요약=JUnit 실패/오류/skip 합계 ' +
                          str(sum(1 for e in report.iter() if e.tag in ('failure', 'error', 'skipped'))), flush=True)
            print('단계=' + name + ' | 결과=' + ('통과' if code == 0 else '실패') +
                  ' | 종료 코드=' + str(code) + ' | 로그=' + str(log), flush=True)
            if code:
                return code
        return 0
    except Exception:
        print('단계=namespace/실행 준비 | 결과=실패 | 종료 코드=1 | 로그=' + str(logs), flush=True)
        return 1
    finally:
        if relay:
            relay.terminate()
            relay.wait(timeout=10)


if __name__ == '__main__':
    sys.exit(main())
