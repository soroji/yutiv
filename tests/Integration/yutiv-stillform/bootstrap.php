<?php

require_once __DIR__.'/guards.php';

/** HTTP and CLI use exactly the same pre-provider environment and DB guard. */
function sfBootstrap($request = null): array
{
    sfRequire(PHP_VERSION_ID >= 80200, 'php-8.2-required');
    $root = realpath(dirname(__DIR__, 3));
    sfRequire($root === getenv('STILLFORM_TEST_ROOT') && preg_match('~^/tmp/yutiv-ses-test\.[A-Za-z0-9]+/repo$~D', $root), 'isolated-workspace');
    sfRequire(getenv('APP_ENV') === 'testing', 'process-environment');
    foreach (['.env', '.env.testing', 'storage', 'bootstrap/cache', 'modules', 'plugins', 'templates', 'public'] as $path) {
        sfInside($root, $root.'/'.$path);
    }
    foreach (['.env', '.env.testing', 'vendor/autoload.php', 'bootstrap/app.php'] as $path) {
        sfRequiredFile($root, $root.'/'.$path);
    }
    foreach (['storage', 'bootstrap/cache'] as $tree) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$tree, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
            if ($file->isLink()) {
                sfInside($root, $file->getPathname());
            }
        }
    }
    sfRequire(hash_file('sha256', $root.'/.env') === hash_file('sha256', $root.'/.env.testing'), 'env-copy-mismatch');
    // Do not delete caches: reject snapshots that can bypass environment/config/route loading.
    sfRequire(! is_file($root.'/bootstrap/cache/config.php') && ! glob($root.'/bootstrap/cache/routes*.php'), 'cached-bootstrap');
    sfRequire(! is_file($root.'/public/hot'), 'external-vite-dev-server');
    foreach (['APP_CONFIG_CACHE', 'APP_ROUTES_CACHE', 'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE', 'LARAVEL_STORAGE_PATH'] as $key) {
        sfRequire(getenv($key) === false || getenv($key) === '', 'external-bootstrap-path');
    }
    $loader = require $root.'/vendor/autoload.php';
    $disk = Dotenv\Dotenv::parse(file_get_contents($root.'/.env.testing'));
    sfRequire(($disk['APP_ENV'] ?? null) === 'testing', 'file-environment');
    foreach (['APP_CONFIG_CACHE', 'APP_ROUTES_CACHE', 'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE', 'LARAVEL_STORAGE_PATH'] as $key) {
        sfRequire(empty($disk[$key]), 'file-bootstrap-path');
    }
    // Inherited process variables must not silently override the verified testing file.
    foreach ($disk as $key => $value) {
        if (strpos($key, 'DB_') === 0 || $key === 'APP_ENV') {
            foreach ([getenv($key), $_ENV[$key] ?? false, $_SERVER[$key] ?? false] as $inherited) {
                sfRequire($inherited === false || (string) $inherited === (string) $value, 'inherited-environment');
            }
        }
    }
    defined('LARAVEL_START') || define('LARAVEL_START', microtime(true));
    define('G7_EXTENSION_AUTOLOAD_REGISTERED', true);
    // Use the HTTP boot branch even in check.php: CoreServiceProvider otherwise skips templates.
    $_ENV['APP_RUNNING_IN_CONSOLE'] = 'false';
    $_SERVER['APP_RUNNING_IN_CONSOLE'] = 'false';
    $app = require $root.'/bootstrap/app.php';
    $app->loadEnvironmentFrom('.env.testing');
    $request = $request ?: (PHP_SAPI === 'cli-server' ? Illuminate\Http\Request::capture() : Illuminate\Http\Request::create('http://127.0.0.1:8765/', 'GET'));
    $app->instance('request', $request); // Required BEFORE HTTP kernel bootstrap.

    $harden = static function ($app): void {
        $app['config']->set([
            'app.debug' => false, 'app.url' => 'http://127.0.0.1:8765',
            'cache.default' => 'array', 'session.driver' => 'array',
            'session.domain' => null, 'session.secure' => false,
            'queue.default' => 'null', 'queue.connections.null' => ['driver' => 'null'],
            'mail.default' => 'array', 'broadcasting.default' => 'null',
            'logging.default' => 'null', 'g7.sql_query_log' => false,
        ]);
        foreach (array_keys($app['config']->get('logging.channels', [])) as $channel) {
            $app['config']->set('logging.channels.'.$channel, ['driver' => 'monolog', 'handler' => Monolog\Handler\NullHandler::class]);
        }
    };
    $guardDb = static function ($app): void {
        sfRequire($app->environment('testing') && $app['config']->get('app.env') === 'testing', 'effective-environment');
        sfRequire($app['config']->get('database.default') === 'mysql', 'database-default');
        sfDatabaseConfig($app['config']->get('database.connections.mysql'));
        $connection = $app['db']->connection('mysql');
        // ConnectionFactory strips read/write from the merged WRITE config.
        // Validate read candidates from the original config above, not this flat map.
        sfResolvedDatabaseConfig($connection->getConfig());
        // Check real PDOs, not just config strings; no write query is issued.
        foreach ([$connection->getReadPdo(), $connection->getPdo()] as $pdo) {
            sfRequire($pdo->query('SELECT DATABASE()')->fetchColumn() === 'yutiv_g7_testing', 'actual-database');
        }
    };
    $app->afterBootstrapping(Illuminate\Foundation\Bootstrap\LoadConfiguration::class, static function ($app) use ($root, $loader, $harden, $guardDb): void {
        sfRequire($app->environment('testing'), 'loaded-environment');
        $harden($app);
        // Remove unused connections so an explicit alternate name cannot reach another database.
        $mysql = $app['config']->get('database.connections.mysql');
        sfDatabaseConfig($mysql);
        $app['config']->set('database.connections', ['mysql' => $mysql]);
        $app->register(Illuminate\Database\DatabaseServiceProvider::class);
        $guardDb($app); // Before ANY application/extension provider registers or boots.

        $files = [];
        foreach (['modules' => 'module', 'plugins' => 'plugin'] as $folder => $entry) {
            foreach (glob($root.'/'.$folder.'/*/composer.json') as $composer) {
                $dir = dirname($composer);
                if (strpos(basename($dir), '_') === 0) {
                    continue;
                }
                sfRequiredFile($root, $composer);
                $manifest = json_decode(file_get_contents($composer), true, 512, JSON_THROW_ON_ERROR);
                foreach ($manifest['autoload']['psr-4'] ?? [] as $namespace => $paths) {
                    foreach ((array) $paths as $path) {
                        $directory = sfPsr4Directory($root, $dir, $path);
                        if ($directory !== null) {
                            $loader->addPsr4($namespace, $directory);
                        }
                    }
                }
                foreach ($manifest['autoload']['files'] ?? [] as $file) {
                    $files[] = sfRequiredFile($root, $dir.'/'.$file);
                }
                $vendor = sfResolveInside($root, $dir.'/vendor/autoload.php', true);
                if ($vendor !== null) {
                    $files[] = sfRequiredFile($root, $dir.'/vendor/autoload.php');
                }
                $files[] = sfRequiredFile($root, $dir.'/'.$entry.'.php');
            }
        }
        // All PSR-4 namespaces exist before any extension entry or helper executes.
        foreach (array_unique($files) as $file) {
            require_once $file;
        }
    });
    $app->afterBootstrapping(Illuminate\Foundation\Bootstrap\RegisterProviders::class, static function ($app) use ($harden, $guardDb): void {
        $harden($app); // SettingsServiceProvider can override debug/mail settings.
        $guardDb($app);
    });
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $kernel->bootstrap();
    $harden($app);
    $guardDb($app);
    return [$app, $kernel, $request];
}
