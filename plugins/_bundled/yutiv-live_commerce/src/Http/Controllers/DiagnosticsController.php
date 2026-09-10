<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Plugins\Yutiv\LiveCommerce\Support\TeeWideConfig;

/**
 * Phase 0 스파이크 진단 응답.
 *
 * 실제 UI 가 아니다. "어느 호스트에서 어느 라우트가 매칭됐고, 어떤 세션 쿠키가
 * 적용됐는가" 를 기계가 읽을 수 있는 형태로 돌려주어 도메인·세션 격리를 증명한다.
 * Phase 1 에서 실제 포털·라이브 화면으로 대체된다.
 *
 * 개인정보나 비밀값을 담지 않는다. 세션 **ID** 도 노출하지 않고 쿠키 **이름**만 알린다.
 */
class DiagnosticsController
{
    /**
     * teewide.com 루트 — 계정 포털 자리.
     *
     * 이 자리에는 로그인·회원가입·내 정보만 오게 된다. 쇼핑몰 상품·카테고리·검색은
     * 여기서 접근할 수 없어야 하며, 그 사실은 TeeWideHostGate 가 강제한다.
     */
    public function portal(Request $request): JsonResponse
    {
        return response()->json([
            'platform' => 'teewide',
            'area' => 'portal',
            'host' => $request->getHost(),
            'route' => $request->route()?->getName(),
            'session_cookie' => config('session.cookie'),
            'session_domain' => config('session.domain'),
            'session_configured' => (bool) $request->attributes->get('teewide.session_configured', false),
        ]);
    }

    /**
     * live.teewide.com/{tenant} — 라이브 판매 진입점.
     *
     * 알 수 없는 tenant 는 여기서 404 로 끝낸다. Phase 1 에서 `live_tenants` 조회로
     * 대체되지만, "모르는 slug 는 통과시키지 않는다" 는 계약은 지금 고정한다.
     */
    public function live(Request $request, string $tenant): JsonResponse
    {
        if (! in_array($tenant, TeeWideConfig::knownTenants(), true)) {
            return response()->json(['message' => 'Not Found'], TeeWideConfig::blockStatus());
        }

        return response()->json([
            'platform' => 'teewide',
            'area' => 'live',
            'tenant' => $tenant,
            'host' => $request->getHost(),
            'route' => $request->route()?->getName(),
            'session_cookie' => config('session.cookie'),
            'session_domain' => config('session.domain'),
            'session_configured' => (bool) $request->attributes->get('teewide.session_configured', false),
        ]);
    }

    /**
     * 세션 공유 증명용 표식 기록/조회 (진단 전용).
     *
     * ── 왜 공개 endpoint 로 두어도 되는가 ───────────────────────────────────
     * `TEEWIDE_DIAGNOSTICS` 가 꺼져 있으면 라우트 자체가 등록되지 않는다. 운영에서는
     * 이 값을 false 로 두어 존재하지 않게 만든다. 기록하는 값도 임의 문자열 하나뿐이고
     * 개인정보·인증 상태와 무관하다.
     */
    public function sessionMarker(Request $request): JsonResponse
    {
        $write = $request->query('write');

        if (is_string($write) && $write !== '') {
            $request->session()->put('teewide.marker', mb_substr($write, 0, 64));
        }

        return response()->json([
            'platform' => 'teewide',
            'area' => 'session-marker',
            'host' => $request->getHost(),
            'route' => $request->route()?->getName(),
            'session_cookie' => config('session.cookie'),
            'session_domain' => config('session.domain'),
            'marker' => $request->session()->get('teewide.marker'),
            // 기존 YUTIV 세션으로 로그인된 사용자가 있어도 TeeWide 사용자로 보지 않는다.
            'yutiv_user_leaked' => $request->user() !== null,
        ]);
    }
}
