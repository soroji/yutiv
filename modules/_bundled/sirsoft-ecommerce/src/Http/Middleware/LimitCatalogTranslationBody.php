<?php

namespace Modules\Sirsoft\Ecommerce\Http\Middleware;

use App\Helpers\ResponseHelper;
use Closure;
use Illuminate\Http\Request;

class LimitCatalogTranslationBody
{
    public function handle(Request $request, Closure $next)
    {
        if ((int) $request->header('Content-Length', 0) > 1048576 || strlen($request->getContent()) > 1048576) {
            return ResponseHelper::moduleError('sirsoft-ecommerce', 'translation.too_large', 413);
        }

        return $next($request);
    }
}
