<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Controllers;

use Illuminate\Contracts\View\View;
use Plugins\Yutiv\LiveCommerce\Support\TeeWideAuth;
use Plugins\Yutiv\LiveCommerce\Support\TeeWidePresenter;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * TeeWide 마이페이지.
 *
 * 접근 제어는 `RequireTeeWideUser` 미들웨어가 맡는다 — 여기 도달했다면 이미 TeeWide
 * 회원이다. 그래도 사용자가 null 이면 화면을 그리지 않고 끊는다(심층 방어).
 */
class AccountController
{
    public function index(): View
    {
        $user = TeeWideAuth::user();

        if ($user === null) {
            // 미들웨어가 뚫린 상황 — 빈 화면을 그리느니 즉시 실패한다.
            throw new HttpException(403, 'TeeWide 로그인이 필요합니다.');
        }

        return view('teewide::account.index', [
            'account' => TeeWidePresenter::account($user),
            'ownedChannels' => TeeWidePresenter::ownedChannels($user),
            'portalUrl' => TeeWidePresenter::portalUrl(),
            'liveUrl' => TeeWidePresenter::liveUrl(),
        ]);
    }
}
