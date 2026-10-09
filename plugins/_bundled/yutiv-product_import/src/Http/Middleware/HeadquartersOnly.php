<?php

namespace Plugins\Yutiv\ProductImport\Http\Middleware;

use Closure;
use Plugins\Yutiv\ProductImport\Support\ImportAccess;

class HeadquartersOnly
{
    public function handle($request, Closure $next)
    {
        abort_unless(ImportAccess::allowed($request->user()), 403);

        return $next($request);
    }
}
