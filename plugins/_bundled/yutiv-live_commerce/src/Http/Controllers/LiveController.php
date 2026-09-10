<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Controllers;

use Illuminate\Contracts\View\View;
use Plugins\Yutiv\LiveCommerce\Support\TeeWideConfig;
use Plugins\Yutiv\LiveCommerce\Support\TeeWidePresenter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * live.teewide.com 라이브커머스 화면.
 *
 *   /            라이브 홈 — 현재/예정 방송과 판매 채널 목록
 *   /{tenant}    판매자 채널 — 영상 무대·방송 정보·상품
 *
 * ── 미등록 tenant 는 계속 404 ──────────────────────────────────────────────
 * 판정은 Phase 0 과 같은 `TeeWideConfig::knownTenants()` 계약을 그대로 쓴다.
 * 라우트의 slug 패턴을 통과해도 등록된 tenant 가 아니면 여기서 끊는다.
 */
class LiveController
{
    public function home(): View
    {
        return view('teewide::live.home', [
            'onAir' => TeeWidePresenter::onAir(),
            'upcoming' => TeeWidePresenter::upcoming(),
            'channels' => TeeWidePresenter::liveChannels(),
            'portalUrl' => TeeWidePresenter::portalUrl(),
            'liveUrl' => TeeWidePresenter::liveUrl(),
        ]);
    }

    public function channel(string $tenant): View
    {
        if (! in_array($tenant, TeeWideConfig::knownTenants(), true)) {
            // 존재하지 않는 채널은 존재하지 않는다고만 말한다 — 어떤 slug 가 있는지
            // 되돌려주면 입점사 목록이 열거된다.
            throw new NotFoundHttpException;
        }

        return view('teewide::live.channel', [
            'channel' => TeeWidePresenter::channel($tenant),
            'broadcast' => TeeWidePresenter::broadcastMeta($tenant),
            'products' => TeeWidePresenter::liveProducts($tenant),
            'portalUrl' => TeeWidePresenter::portalUrl(),
            'liveUrl' => TeeWidePresenter::liveUrl(),
        ]);
    }
}
