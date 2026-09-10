<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Controllers;

use Illuminate\Contracts\View\View;
use Plugins\Yutiv\LiveCommerce\Support\TeeWidePresenter;

/**
 * teewide.com 계정 포털 화면.
 *
 * ── 진단과 분리한 이유 ─────────────────────────────────────────────────────
 * Phase 0 에서는 이 주소가 JSON 진단을 돌려줬다. 그러나 진단 스위치
 * (`TEEWIDE_DIAGNOSTICS`)를 끄면 제품 화면까지 사라지는 구조는 옳지 않다.
 * 제품 화면은 `DiagnosticsController` 와 분리해, 진단 스위치와 무관하게 항상 뜬다.
 *
 * 이 화면은 쇼핑몰 콘텐츠를 담지 않는다 — 상품·검색·장바구니는 라이브 호스트와
 * 이후 단계의 몫이고, 포털은 계정과 안내만 맡는다.
 */
class PortalController
{
    public function index(): View
    {
        return view('teewide::portal.index', [
            'features' => TeeWidePresenter::portalFeatures(),
            'portalUrl' => TeeWidePresenter::portalUrl(),
            'liveUrl' => TeeWidePresenter::liveUrl(),
        ]);
    }
}
