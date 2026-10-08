<?php

// Development-only front controller. Never install in public/ or use on production.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
ob_start();
try {
    require __DIR__.'/bootstrap.php';
    sfRequire(PHP_SAPI === 'cli-server', 'built-in-server-only');
    sfRequire(($_SERVER['SERVER_ADDR'] ?? $_SERVER['SERVER_NAME'] ?? '') === '127.0.0.1', 'loopback-listener');
    sfRequire(($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1', 'loopback-client');
    sfRequire(($_SERVER['HTTP_HOST'] ?? '') === '127.0.0.1:8765', 'loopback-host');
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    sfRequire(is_string($path) && strpos(rawurldecode($path), '..') === false && strpos($path, '\\') === false, 'request-path');
    sfRequire(sfPreviewMethod($_SERVER['REQUEST_METHOD'] ?? '', $path), 'preview-method');
    sfRequire(! preg_match('~/(admin|install)(/|$)~', rawurldecode($path)), 'preview-scope');
    // Preview is anonymous; do not refresh existing tokens or attach an authenticated session.
    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_COOKIE']);
    $_COOKIE = [];
    [$app, $kernel, $request] = sfBootstrap();
    sfRequire(sfPreviewMethod($request->getMethod(), $path), 'effective-method');
    $public = realpath($app->publicPath());
    $static = realpath($public.rawurldecode($path));
    if ($static && is_file($static) && strpos($static, $public.'/') === 0
        && preg_match('/\.(?:js|css|woff2?|ttf|png|jpe?g|gif|webp|avif|svg|ico)$/i', $static)) {
        sfInside($app->basePath(), $static);
        $response = new Symfony\Component\HttpFoundation\BinaryFileResponse($static);
    } else {
        $response = $kernel->handle($request);
    }
    sfRequire($response->getStatusCode() < 500, 'http-server-error');
    $response->headers->set('X-Stillform-Preview', 'testing-db-guarded');
    $response->headers->set('Cache-Control', 'no-store');
    // Prevent accidental navigation to external services from the isolated preview.
    $response->headers->set('Content-Security-Policy', "form-action 'self'; frame-src 'none'; connect-src 'self'");
    ob_end_clean();
    $response->send();
    // No terminate(): avoid deferred jobs/after-response effects in a visual-only preview.
} catch (Throwable $error) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    $code = $error instanceof StillformGuardFailure ? ' / '.$error->getMessage() : '';
    echo 'Preview stopped: check failed'.$code." (details suppressed).\n";
    exit(1);
}
