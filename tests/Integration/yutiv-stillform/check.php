<?php

ini_set('display_errors', '0');
ini_set('log_errors', '0');
ob_start();
$stage = 'bootstrap';
try {
    require __DIR__.'/bootstrap.php';
    sfRequire(PHP_SAPI === 'cli', 'cli-only');
    [$app, $kernel] = sfBootstrap();
    $root = $app->basePath();
    $stage = 'active-template';
    $template = App\Models\Template::where('identifier', 'yutiv-stillform')->where('type', 'user')->where('status', 'active')->first();
    sfRequire($template !== null, $stage);
    sfRequire(App\Models\Template::where('type', 'user')->where('status', 'active')->count() === 1, 'unique-template');
    $active = $app->make(App\Extension\TemplateManager::class)->getActiveTemplate('user');
    sfRequire(($active['identifier'] ?? null) === 'yutiv-stillform', 'runtime-template');

    $stage = 'registered-layouts';
    $dir = sfInside($root, $root.'/templates/yutiv-stillform');
    $registered = App\Models\TemplateLayout::where('template_id', $template->id)->pluck('name')->all();
    $layoutCount = 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir.'/layouts', FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'json') {
            continue;
        }
        $layout = json_decode(file_get_contents(sfInside($root, $file->getPathname())), true, 512, JSON_THROW_ON_ERROR);
        if (($layout['meta']['is_partial'] ?? false) === true) {
            continue; // G7 ValidatesLayoutFiles intentionally does not store partials in DB.
        }
        sfRequire(isset($layout['layout_name']) && in_array($layout['layout_name'], $registered, true), 'layout-registration');
        $layoutCount++;
    }
    sfRequire($layoutCount > 0, 'layout-files');

    $get = static function (string $url) use ($app, $kernel) {
        $request = Illuminate\Http\Request::create('http://127.0.0.1:8765'.$url, 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $app->instance('request', $request);
        $response = $kernel->handle($request);
        sfRequire($response->getStatusCode() === 200, 'http-status');
        return $response;
    };
    $stage = 'business-api-route';
    $url = '/api/plugins/yutiv-storefront_support/business-info';
    $route = $app['router']->getRoutes()->match(Illuminate\Http\Request::create($url, 'GET'));
    sfRequire(strpos($route->getActionName(), 'Plugins\\Yutiv\\StorefrontSupport\\Http\\Controllers\\BusinessInfoController@show') !== false, 'route-controller');
    sfRequire($route->getName() !== null, 'route-name');
    $stage = 'business-api-response';
    sfPublicPayload(json_decode($get($url)->getContent(), true, 512, JSON_THROW_ON_ERROR));

    $stage = 'template-http-assets';
    $manifest = json_decode(file_get_contents($dir.'/template.json'), true, 512, JSON_THROW_ON_ERROR);
    foreach (['css' => 'dist/css/components.css', 'js' => 'dist/js/components.iife.js'] as $type => $path) {
        sfRequire(in_array($path, $manifest['assets'][$type] ?? [], true), 'asset-manifest');
        $file = sfInside($root, $dir.'/'.$path);
        sfRequire(filesize($file) > 0, 'asset-file');
        $response = $get(App\Support\AssetUrl::templateAsset('yutiv-stillform', substr($path, 5)));
        if ($response instanceof Symfony\Component\HttpFoundation\BinaryFileResponse) {
            sfRequire(hash_file('sha256', $file) === hash_file('sha256', $response->getFile()->getPathname()), 'asset-http-content');
        } else {
            sfRequire(hash_file('sha256', $file) === hash('sha256', $response->getContent()), 'asset-http-content');
        }
    }
    sfRequire(strpos(file_get_contents($dir.'/dist/js/components.iife.js'), 'YutivStillform') !== false, 'iife-symbol');
    $stage = 'template-routes-http';
    $routes = json_decode($get('/api/templates/yutiv-stillform/routes')->getContent(), true, 512, JSON_THROW_ON_ERROR);
    $routeData = $routes['data']['routes'] ?? $routes['routes'] ?? null;
    sfRequire(is_array($routeData), 'routes-envelope');
    $routeLayouts = array_column($routeData, 'layout');
    foreach (['home', 'shop/index', 'shop/show', 'shop/cart', 'auth/login'] as $layout) {
        sfRequire(in_array($layout, $routeLayouts, true), 'route-layout');
    }
    $stage = 'home-html';
    $html = $get('/')->getContent();
    sfRequire(strpos($html, 'data-template-id="yutiv-stillform"') !== false, 'html-template');
    sfRequire(strpos($html, 'components.iife.js') !== false || strpos($html, 'components.iife') !== false, 'html-script');

    $stage = 'product-inventory';
    $hasProducts = Modules\Sirsoft\Ecommerce\Models\Product::query()->exists();
    $stage = 'product-list-http';
    $list = json_decode($get('/api/modules/sirsoft-ecommerce/products')->getContent(), true, 512, JSON_THROW_ON_ERROR);
    sfRequire(($list['success'] ?? null) === true && isset($list['data']), 'product-list-envelope');
    sfRequire(is_array($list['data']['data'] ?? null), 'product-list-items');
    if (! $hasProducts) {
        sfRequire($list['data']['data'] === [], 'empty-product-list');
    }
    $stage = 'final-database';
    foreach ([$app['db']->connection()->getReadPdo(), $app['db']->connection()->getPdo()] as $pdo) {
        sfRequire($pdo->query('SELECT DATABASE()')->fetchColumn() === 'yutiv_g7_testing', 'database-after-http');
    }
    ob_end_clean();
    echo "PASS: testing environment and actual read/write databases; installed extension autoload.\n";
    echo "PASS: business API route, HTTP 200, exact 9 fields (string|null).\n";
    echo "PASS: active template, {$layoutCount} registered layout files, JS/CSS HTTP content, IIFE symbol, routes and home HTML, product list API.\n";
    echo $hasProducts ? "LIMIT: products exist; detail rendering and purchase flow NOT verified.\n" : "LIMIT: no products; empty inventory/list API only. Browser empty-state rendering still requires review.\n";
    echo "NOT VERIFIED: browser execution of window.YutivStillform, authentication, cart mutations, checkout/payment.\n";
    exit(0);
} catch (Throwable $error) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    // Never print exception messages, SQL, configuration, response bodies or business values.
    $code = $error instanceof StillformGuardFailure ? ' / '.$error->getMessage() : '';
    fwrite(STDERR, 'FAIL: '.$stage.$code." (details suppressed)\n");
    exit(1);
}
