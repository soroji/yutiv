<?php

require __DIR__.'/guards.php';
$fixture = __DIR__.'/.path-fixture-'.bin2hex(random_bytes(6));
$count = 0;
$linkChecks = 0;
function pathCase(callable $run, ?string $code = null): void
{
    global $count;
    try {
        $run();
        sfRequire($code === null, 'expected-path-rejection');
    } catch (StillformGuardFailure $error) {
        if ($code === null || strpos($error->getMessage(), $code.': ') !== 0) {
            throw new RuntimeException('path-regression');
        }
        // Diagnostics must never contain absolute fixture/workspace paths.
        sfRequire(strpos($error->getMessage(), str_replace('\\', '/', __DIR__)) === false, 'absolute-path-leak');
    }
    $count++;
}
function removePathFixture(string $path): void
{
    if (is_link($path)) {
        // Windows directory links must be removed with rmdir, never traversed.
        if (PHP_OS_FAMILY === 'Windows' && is_dir($path)) {
            rmdir($path);
        } else {
            unlink($path);
        }
    } elseif (is_dir($path)) {
        foreach (scandir($path) as $name) {
            if ($name !== '.' && $name !== '..') {
                removePathFixture($path.'/'.$name);
            }
        }
        rmdir($path);
    } elseif (file_exists($path)) {
        unlink($path);
    }
}
$exit = 1;
try {
    mkdir($fixture.'/workspace/plugins/postcode', 0700, true);
    mkdir($fixture.'/outside', 0700);
    $root = str_replace('\\', '/', realpath($fixture.'/workspace'));
    $plugin = $root.'/plugins/postcode';
    file_put_contents($plugin.'/plugin.php', '<?php // fixture only');
    // Reproduce the original defect before testing optional PSR-4 resolution.
    pathCase(static function () use ($root, $plugin) { sfInside($root, $plugin.'/missing.php'); }, 'missing-required-path');
    pathCase(static function () use ($root, $plugin) { sfRequire(sfPsr4Directory($root, $plugin, 'src/') === null, 'optional-src'); });
    pathCase(static function () use ($root, $plugin) { sfRequire(sfPsr4Directory($root, $plugin, './') === realpath($plugin), 'root-namespace'); });
    pathCase(static function () use ($root, $plugin) { sfPsr4Directory($root, $plugin, 'plugin.php'); }, 'not-directory');
    pathCase(static function () use ($root, $plugin) { sfInside($root, $plugin.'/src/'); }, 'missing-required-path');
    pathCase(static function () use ($root, $plugin) { sfRequiredFile($root, $plugin.'/autoload-helper.php'); }, 'missing-required-path');
    pathCase(static function () use ($root) { sfRequiredFile($root, $root.'/.env.testing'); }, 'missing-required-path');
    pathCase(static function () use ($root) { sfRequiredFile($root, $root.'/plugins/module.php'); }, 'missing-required-path');
    pathCase(static function () use ($root, $plugin) { sfRequiredFile($root, $plugin); }, 'not-file');
    pathCase(static function () use ($root, $plugin) { sfPsr4Directory($root, $plugin, '../../../outside/missing'); }, 'outside-path');
    pathCase(static function () use ($root, $plugin) { sfPsr4Directory($root, $plugin, 'missing/../../../../outside'); }, 'outside-path');
    pathCase(static function () use ($root, $plugin) { sfPsr4Directory($root, $plugin, '/outside/missing'); }, 'outside-path');
    pathCase(static function () use ($root, $plugin) { sfPsr4Directory($root, $plugin, '../postcode/'); });
    $repo = realpath(dirname(__DIR__, 3));
    $actual = $repo.'/plugins/_bundled/sirsoft-daum_postcode';
    $manifest = json_decode(file_get_contents($actual.'/composer.json'), true);
    $paths = $manifest['autoload']['psr-4']['Plugins\\Sirsoft\\DaumPostcode\\'];
    pathCase(static function () use ($repo, $actual, $paths) {
        sfRequire($paths === ['src/', './'], 'actual-manifest');
        sfRequire(sfPsr4Directory($repo, $actual, $paths[0]) === null, 'actual-optional-src');
        sfRequire(sfPsr4Directory($repo, $actual, $paths[1]) === realpath($actual), 'actual-plugin-root');
        sfRequiredFile($repo, $actual.'/plugin.php');
    });
    $links = ['inside-link' => $plugin, 'outside-link' => realpath($fixture.'/outside'), 'broken-link' => $root.'/absent-target'];
    foreach ($links as $name => $target) {
        if (! @symlink($target, $root.'/'.$name)) {
            throw new LogicException('symlink-unavailable');
        }
    }
    foreach (['outside-link/new/src', 'outside-link/../workspace/plugins/postcode'] as $path) {
        pathCase(static function () use ($root, $path) { sfPsr4Directory($root, $root, $path); }, 'outside-path');
        $linkChecks++;
    }
    foreach (['broken-link', 'broken-link/src'] as $path) {
        pathCase(static function () use ($root, $path) { sfPsr4Directory($root, $root, $path); }, 'broken-symbolic-link');
        $linkChecks++;
    }
    pathCase(static function () use ($root) { sfRequire(sfPsr4Directory($root, $root, 'inside-link/src') === null, 'internal-link-missing-src'); });
    pathCase(static function () use ($root) { sfPsr4Directory($root, $root, 'inside-link/'); });
    pathCase(static function () use ($root) { sfRequiredFile($root, $root.'/broken-link'); }, 'broken-symbolic-link');
    pathCase(static function () use ($root) { sfRequiredFile($root, $root.'/outside-link/missing.php'); }, 'outside-path');
    $linkChecks += 4;
    echo "PASS: {$count} path cases, including {$linkChecks} symbolic-link cases.\n";
    $exit = 0;
} catch (LogicException $error) {
    fwrite(STDERR, "INCOMPLETE: {$count} path cases passed; 8 symbolic-link cases require link creation permission. Rerun on Linux.\n");
    $exit = 2;
} catch (Throwable $error) {
    fwrite(STDERR, "FAIL: path regression (details suppressed).\n");
} finally {
    if (is_dir($fixture)) {
        sfRequire(dirname(realpath($fixture)) === realpath(__DIR__) && strpos(basename($fixture), '.path-fixture-') === 0, 'fixture-cleanup-boundary');
        removePathFixture($fixture);
    }
}
exit($exit);
