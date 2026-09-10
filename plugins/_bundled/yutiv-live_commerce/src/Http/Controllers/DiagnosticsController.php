<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 도메인·세션 격리 진단 (JSON 전용).
 *
 * ── 제품 화면과 분리돼 있다 ────────────────────────────────────────────────
 * Phase 0 에서는 이 컨트롤러가 포털·라이브 응답까지 맡았다. 지금은 제품 화면이
 * `PortalController` / `LiveController` 로 나갔고, 여기에는 **진단만** 남는다.
 * 그래서 `TEEWIDE_DIAGNOSTICS=false` 로 이 엔드포인트를 닫아도 서비스 화면은 그대로다.
 *
 * 개인정보나 비밀값을 담지 않는다. 세션 **ID** 도 노출하지 않고 쿠키 **이름**만 알린다.
 */
class DiagnosticsController
{
    /**
     * 세션 공유 증명용 표식 기록/조회.
     *
     * ── 왜 공개 endpoint 로 두어도 되는가 ───────────────────────────────────
     * `TEEWIDE_DIAGNOSTICS` 가 꺼져 있으면 라우트 자체가 등록되지 않는다. 기록하는 값도
     * 임의 문자열 하나뿐이고 개인정보·인증 상태와 무관하다.
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
            'session_configured' => (bool) $request->attributes->get('teewide.session_configured', false),
            'marker' => $request->session()->get('teewide.marker'),
            // 기존 YUTIV 세션으로 로그인된 사용자가 있어도 TeeWide 사용자로 보지 않는다.
            'yutiv_user_leaked' => $request->user() !== null,
        ]);
    }
}
