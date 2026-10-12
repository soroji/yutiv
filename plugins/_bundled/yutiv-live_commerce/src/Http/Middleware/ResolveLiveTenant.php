<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Plugins\Yutiv\LiveCommerce\Support\LiveContext;
use Plugins\Yutiv\LiveCommerce\Support\TeeWideAuth;

class ResolveLiveTenant
{
    public function handle(Request $request, Closure $next): mixed
    {
        $tenant = LiveContext::requireTenant();
        $user = TeeWideAuth::user();
        if ($user && ! $request->session()->has('live.session_version') && TeeWideAuth::guard()->viaRemember()) {
            $request->session()->put('live.session_version', $user->session_version);
        }
        if ($user && ($user->tenant_id !== $tenant->id || ! $user->canAuthenticate()
            || $request->session()->get('live.session_version') !== $user->session_version)) {
            TeeWideAuth::guard()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $user = null;
        }
        if ($user?->must_change_password && ! $request->is('password/change', 'customer/tasks/password', 'logout', 'live-assets*', 'live-styles*', 'live-media/*')) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => '비밀번호를 먼저 변경해 주세요.'], 403);
            }

            return redirect('/password/change');
        }

        $response = $next($request);
        if (! $request->is('live-assets*', 'live-styles*', 'live-media/*')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
