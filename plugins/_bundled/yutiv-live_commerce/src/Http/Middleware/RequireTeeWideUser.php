<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Plugins\Yutiv\LiveCommerce\Support\TeeWideAuth;
use Symfony\Component\HttpFoundation\Response;

/**
 * TeeWide 로그인이 필요한 화면을 지킨다.
 *
 * ── 왜 코어 `auth` 미들웨어를 쓰지 않는가 ──────────────────────────────────
 * Laravel 의 `Authenticate` 미들웨어는 실패 시 `route('login')` 으로 보낸다. 그건
 * **YUTIV 로그인 페이지**다. TeeWide 방문자를 다른 서비스의 로그인 화면으로 보내면
 * 도메인 경계가 사용자 눈앞에서 무너진다.
 *
 * 그래서 리다이렉트 대상을 이 미들웨어가 직접 정한다 — 언제나 `teewide.login` 이다.
 */
class RequireTeeWideUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if (TeeWideAuth::check()) {
            return $next($request);
        }

        // 로그인 후 원래 가려던 곳으로 돌려보낸다.
        $request->session()->put('url.intended', $request->fullUrl());

        return redirect()->route('teewide.login');
    }
}
