<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Plugins\Yutiv\LiveCommerce\Http\Controllers\Customer\LiveCustomerController;
use Plugins\Yutiv\LiveCommerce\Http\Requests\UpdateBrandRequest;
use Plugins\Yutiv\LiveCommerce\Services\SellerBrandService;
use Plugins\Yutiv\LiveCommerce\Support\TeeWideAuth;

/**
 * TeeWide 마이페이지.
 *
 * 접근 제어는 `RequireTeeWideUser` 미들웨어가 맡는다 — 여기 도달했다면 이미 TeeWide
 * 회원이다. 그래도 사용자가 null 이면 화면을 그리지 않고 끊는다(심층 방어).
 */
class AccountController
{
    public function __construct(private readonly SellerBrandService $brands) {}

    public function updateBrand(UpdateBrandRequest $request): RedirectResponse
    {
        $this->brands->updateOwnedName(TeeWideAuth::user()->id, $request->route('tenant'), $request->validated('name'));

        $request->session()->flash('brand_saved', '브랜드 이름을 저장했습니다.');

        return redirect()->route('teewide.account');
    }

    public function index(): View
    {
        return app(LiveCustomerController::class)->account();
    }
}
