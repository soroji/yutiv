<?php

// No Laravel dependency: these guards can also be exercised on the local PHP 7.4 host.
final class StillformGuardFailure extends RuntimeException
{
}

function sfRequire($condition, string $code): void
{
    if (! $condition) {
        throw new StillformGuardFailure($code);
    }
}

function sfInside(string $root, string $path): string
{
    return sfResolveInside($root, $path, false);
}

function sfPathFailure(string $code, string $root, string $path): void
{
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $path = str_replace('\\', '/', $path);
    $relative = strpos($path, $root.'/') === 0 ? substr($path, strlen($root) + 1) : '[outside-workspace]';
    $relative = preg_replace('/[\x00-\x1f\x7f]/', '?', $relative);
    throw new StillformGuardFailure($code.': '.$relative);
}

/** Walk components before collapsing '..', so links cannot hide a boundary crossing. */
function sfResolveInside(string $root, string $path, bool $optional): ?string
{
    clearstatcache(true);
    $root = rtrim(str_replace('\\', '/', realpath($root) ?: $root), '/');
    $input = str_replace('\\', '/', $path);
    if (strpos($input, "\0") !== false || ($input !== $root && strpos($input, $root.'/') !== 0)) {
        sfPathFailure('outside-path', $root, $input);
    }
    $current = $root;
    $parts = explode('/', substr($input, strlen($root)));
    foreach ($parts as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            if ($current === $root) {
                sfPathFailure('outside-path', $root, $input);
            }
            $current = str_replace('\\', '/', dirname($current));
            continue;
        }
        if (file_exists($current) && ! is_dir($current)) {
            sfPathFailure('not-directory', $root, $input);
        }
        $current .= '/'.$part;
        if (is_link($current) && realpath($current) === false) {
            sfPathFailure('broken-symbolic-link', $root, $input);
        }
        $real = realpath($current);
        if ($real !== false) {
            $current = str_replace('\\', '/', $real);
            if ($current !== $root && strpos($current, $root.'/') !== 0) {
                sfPathFailure('outside-path', $root, $input);
            }
        }
    }
    $resolved = realpath($path);
    if ($resolved !== false) {
        $normalized = str_replace('\\', '/', $resolved);
        if ($normalized !== $root && strpos($normalized, $root.'/') !== 0) {
            sfPathFailure('outside-path', $root, $input);
        }
    }
    if ($resolved === false && ! $optional) {
        sfPathFailure('missing-required-path', $root, $input);
    }
    return $resolved === false ? null : $resolved;
}

function sfRequiredFile(string $root, string $path): string
{
    $resolved = sfInside($root, $path);
    if (! is_file($resolved)) {
        sfPathFailure('not-file', $root, $path);
    }
    return $resolved;
}

/** Only a missing PSR-4 directory is optional; existing files/unsafe links are errors. */
function sfPsr4Directory(string $root, string $base, string $declaration): ?string
{
    sfInside($root, $base);
    if ($declaration === '' || preg_match('~^[\\\\/]|:|[\x00-\x1f\x7f]~', $declaration)) {
        sfPathFailure('outside-path', $root, $base.'/[invalid-psr4-declaration]');
    }
    $path = $base.'/'.$declaration;
    $resolved = sfResolveInside($root, $path, true);
    if ($resolved !== null && ! is_dir($resolved)) {
        sfPathFailure('not-directory', $root, $path);
    }
    return $resolved;
}

/** Reject ambiguous maps and overrides before merging; never repair a database value. */
function sfDatabaseLayer(array $config): void
{
    foreach (array_keys($config) as $key) {
        sfRequire(is_string($key), 'database-map');
    }
    if (array_key_exists('url', $config)) {
        sfRequire($config['url'] === null || $config['url'] === '', 'database-url');
    }
    if (array_key_exists('driver', $config)) {
        sfRequire($config['driver'] === 'mysql', 'database-driver');
    }
    if (array_key_exists('database', $config)) {
        sfRequire($config['database'] === 'yutiv_g7_testing', 'database-name');
    }
}

/** Flat effective config, including Connection::getConfig() (merged WRITE config). */
function sfResolvedDatabaseConfig(array $config): void
{
    sfDatabaseLayer($config);
    sfRequire(! array_key_exists('read', $config) && ! array_key_exists('write', $config), 'database-resolved-shape');
    sfRequire(($config['driver'] ?? null) === 'mysql' && ($config['database'] ?? null) === 'yutiv_g7_testing', 'database-resolved-target');
    sfRequire(is_string($config['username'] ?? null) && trim($config['username']) !== '', 'database-username');
    $hosts = $config['host'] ?? null;
    $hosts = is_string($hosts) ? [$hosts] : $hosts;
    sfRequire(is_array($hosts) && count($hosts) > 0 && array_keys($hosts) === range(0, count($hosts) - 1), 'database-hosts');
    foreach ($hosts as $host) {
        sfRequire(is_string($host) && trim($host) !== '', 'database-host');
    }
}

/**
 * Original database.connections.mysql, before DatabaseManager/ConnectionFactory.
 * Laravel 12.62: array_merge(common, chosen side), then remove read/write.
 * Enumerate ALL side candidates instead of Laravel's random selection; host
 * candidates are checked in sfResolvedDatabaseConfig. Empty maps inherit common.
 */
function sfDatabaseConfig(array $config): void
{
    sfDatabaseLayer($config);
    sfRequire(($config['driver'] ?? null) === 'mysql', 'database-driver');
    $hasRead = array_key_exists('read', $config);
    $hasWrite = array_key_exists('write', $config);
    if (! $hasRead && ! $hasWrite) {
        sfResolvedDatabaseConfig($config);
        return;
    }
    // A missing/null side is not an inheritance marker in ConnectionFactory.
    sfRequire($hasRead && $hasWrite, 'database-sides');
    foreach (['read', 'write'] as $side) {
        $value = $config[$side];
        sfRequire(is_array($value), 'database-side-shape');
        $numeric = array_filter(array_keys($value), 'is_int');
        if ($numeric !== []) {
            sfRequire(array_keys($value) === range(0, count($value) - 1), 'database-candidates-shape');
            $candidates = $value;
        } else {
            $candidates = [$value];
        }
        foreach ($candidates as $candidate) {
            sfRequire(is_array($candidate), 'database-candidate');
            sfDatabaseLayer($candidate);
            sfRequire(! array_key_exists('read', $candidate) && ! array_key_exists('write', $candidate), 'database-nested-side');
            $effective = array_merge($config, $candidate);
            unset($effective['read'], $effective['write']);
            sfResolvedDatabaseConfig($effective);
        }
    }
}

function sfPublicPayload(array $payload): void
{
    sfRequire(($payload['success'] ?? null) === true && isset($payload['data']) && is_array($payload['data']), 'api-envelope');
    $keys = ['company', 'representative', 'business_number', 'mail_order_number', 'address', 'phone', 'email', 'hosting', 'verification_url'];
    $actual = array_keys($payload['data']);
    sort($keys);
    sort($actual);
    sfRequire($keys === $actual, 'api-exact-fields');
    foreach ($payload['data'] as $value) {
        sfRequire($value === null || is_string($value), 'api-field-type');
    }
}

function sfPreviewMethod(string $method, string $path): bool
{
    return in_array($method, ['GET', 'HEAD'], true)
        || ($method === 'POST' && $path === '/api/modules/sirsoft-ecommerce/cart/query');
}
