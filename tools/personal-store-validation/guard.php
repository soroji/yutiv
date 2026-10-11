<?php

use Illuminate\Database\Connectors\MySqlConnector;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\RegisterProviders;

/** 모든 PHP 프로세스에 PHPRC/auto_prepend_file로 적용하는 파괴적 실행 전 가드. */
final class YutivValidationGuard
{
    public static function fail(string $reason): never
    {
        fwrite(STDERR, '격리 사전 검사 실패: '.$reason."\n");
        exit(78);
    }

    public static function initial(): void
    {
        $root = getenv('YUTIV_ISOLATED_ROOT');
        if (! $root || ! str_starts_with($root, '/tmp/yutiv-validation-') || realpath($root) !== $root
            || realpath(getcwd()) !== $root || getenv('APP_ENV') !== 'testing'
            || getenv('DB_URL') || getenv('DATABASE_URL') || getenv('DB_SOCKET')) {
            self::fail('경로/환경/URL');
        }
        if (PHP_VERSION_ID !== 80306 || ! extension_loaded('bcmath') || ! extension_loaded('pdo_mysql')) {
            self::fail('PHP 8.3.6 / BCMath / PDO MySQL');
        }
        if (is_file($root.'/bootstrap/cache/config.php')) {
            self::fail('설정 캐시');
        }
        foreach (['DB_DATABASE', 'DB_WRITE_DATABASE', 'DB_READ_DATABASE'] as $key) {
            if (getenv($key) !== getenv('YUTIV_ISOLATED_DB')) {
                self::fail('DB 환경 불일치');
            }
        }
    }

    public static function pdo(PDO $pdo): void
    {
        if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== getenv('YUTIV_ISOLATED_DB')) {
            self::fail('실제 PDO DB');
        }
        if (! preg_match('/^8\.0\.46(?:[-.].*)?$/', $pdo->query('SELECT VERSION()')->fetchColumn())) {
            self::fail('MySQL 8.0.46');
        }
        $identity = $pdo->query('SELECT CURRENT_USER()')->fetchColumn();
        if (explode('@', $identity)[0] !== getenv('YUTIV_ISOLATED_USER')) {
            self::fail('실제 DB 계정');
        }
        if ($pdo->query('SELECT @@GLOBAL.mandatory_roles')->fetchColumn() !== '') {
            self::fail('mandatory role 권한을 증명할 수 없음');
        }
        self::grants($pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function grants(array $grants): void
    {
        $databaseGrant = false;
        foreach ($grants as $grant) {
            if (preg_match('/^GRANT USAGE ON \*\.\* TO /', $grant) && ! str_contains($grant, 'WITH GRANT OPTION')) {
                continue;
            }
            if (! preg_match('/^GRANT ALL PRIVILEGES ON `([^`]+)`\.\* TO /', $grant, $match)
                || str_contains($grant, 'WITH GRANT OPTION')) {
                self::fail('전역 권한/역할/추가 권한');
            }
            // SHOW GRANTS의 DB wildcard escape를 검사합니다. 비이스케이프 _/%는 거부합니다.
            $pattern = $match[1];
            if (preg_match('/(?<!\\\\)[_%]/', $pattern)
                || str_replace(['\\_', '\\%'], ['_', '%'], $pattern) !== getenv('YUTIV_ISOLATED_DB')) {
                self::fail('전용 DB 외 권한');
            }
            $databaseGrant = true;
        }
        if (! $databaseGrant) {
            self::fail('전용 DB 권한을 증명할 수 없음');
        }
    }

    public static function config(array $config): void
    {
        if (($config['driver'] ?? '') !== 'mysql' || ! empty($config['url'])
            || ! empty($config['unix_socket'])) {
            self::fail('연결 드라이버/URL/socket');
        }
        foreach (['read', 'write'] as $side) {
            $c = array_replace($config, $config[$side] ?? []);
            if (($c['database'] ?? '') !== getenv('YUTIV_ISOLATED_DB')
                || (array) ($c['host'] ?? []) !== ['127.0.0.1']
                || (string) ($c['port'] ?? '') !== '3306'
                || ($c['username'] ?? '') !== getenv('YUTIV_ISOLATED_USER')
                || ($c['password'] ?? '') !== getenv('DB_PASSWORD')) {
                self::fail('읽기/쓰기 연결 설정');
            }
            try {
                $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname='.$c['database'],
                    $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                self::pdo($pdo);
                $pdo = null;
            } catch (Throwable) {
                self::fail('PDO 접속/권한 검사 불가');
            }
        }
    }

    public static function attach(Application $app): void
    {
        $app->beforeBootstrapping(RegisterProviders::class,
            static function ($app): void {
                if (! $app->environment('testing') || $app->basePath() !== getenv('YUTIV_ISOLATED_ROOT')
                    || $app['config']->get('database.default') !== 'mysql') {
                    self::fail('Laravel 실행 환경');
                }
                $config = $app['config']->get('database.connections.mysql');
                self::config($config);
                // 사용하지 않는 sqlite/pgsql/mariadb 등도 서비스 연결로 우회하지 못하게 제거합니다.
                $app['config']->set('database.connections', ['mysql' => $config]);
                $app->bind('db.connector.mysql', static fn () => new class extends MySqlConnector
                {
                    public function connect(array $config)
                    {
                        YutivValidationGuard::config($config);
                        $pdo = parent::connect($config);
                        YutivValidationGuard::pdo($pdo);

                        return $pdo;
                    }
                });
                $app['config']->set('mail.default', 'array');
                $app['config']->set('queue.default', 'sync');
                $app['config']->set('cache.default', 'array');
                $app['config']->set('session.driver', 'array');
            });
    }
}

YutivValidationGuard::initial();
