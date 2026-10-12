<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Controllers;

use Illuminate\Http\Response;

class LiveAssetController
{
    public function script(): Response
    {
        return $this->asset('dist/js/plugin.iife.js', 'application/javascript');
    }

    public function styles(): Response
    {
        return $this->asset('dist/css/plugin.css', 'text/css');
    }

    private function asset(string $path, string $type): Response
    {
        $file = dirname(__DIR__, 3).'/'.$path;
        abort_unless(is_file($file), 404);

        return response(file_get_contents($file), 200, ['Content-Type' => $type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'public, max-age=3600']);
    }
}
