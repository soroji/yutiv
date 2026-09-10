<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Controllers;

use Illuminate\Contracts\View\View;
use Plugins\Yutiv\LiveCommerce\Support\TeeWidePresenter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * live.teewide.com 라이브커머스 화면.
 *
 *   /            라이브 홈 — 현재/예정 방송과 공개 채널 목록
 *   /{tenant}    판매자 채널 — 영상 무대·방송 정보·상품
 *
 * ── 공개 판정은 DB 가 정한다 ───────────────────────────────────────────────
 * Phase 1-B 부터 `live_tenants.status = 'active'` 인 채널만 열린다.
 * `draft`(준비 중)·`suspended`(정지)·미등록 slug 는 모두 404 다 — 셋을 구분해 답하면
 * 입점사 목록이 열거된다.
 *
 * 조회가 실패해도 열어 주지 않는다(fail-closed) — `TeeWidePresenter` 참조.
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
        $channel = TeeWidePresenter::publicChannel($tenant);

        if ($channel === null) {
            // 존재하지 않는 채널은 존재하지 않는다고만 말한다.
            throw new NotFoundHttpException;
        }

        return view('teewide::live.channel', [
            'channel' => $channel,
            'broadcast' => TeeWidePresenter::broadcastMeta($tenant),
            'products' => TeeWidePresenter::liveProducts($tenant),
            'portalUrl' => TeeWidePresenter::portalUrl(),
            'liveUrl' => TeeWidePresenter::liveUrl(),
        ]);
    }
}
