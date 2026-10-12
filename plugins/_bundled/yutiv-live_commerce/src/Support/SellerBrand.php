<?php

namespace Plugins\Yutiv\LiveCommerce\Support;

use Illuminate\View\View;

/** DB 공개 판정을 통과한 판매자의 표시 이름만 고객 화면에 제공합니다. */
final class SellerBrand
{
    public static function compose(View $view): void
    {
        if (request()->attributes->has('teewide.seller_brand')) {
            $view->with('sellerBrand', request()->attributes->get('teewide.seller_brand'));

            return;
        }
        $brand = null;
        if (TeeWideConfig::roleFor(request()->getHost()) !== TeeWideHost::ROLE_FOREIGN) {
            // channel은 LiveController에서 공개 판매자를 검증한 결과입니다.
            try {
                $brand = LiveContext::tenant()?->name;
            } catch (\Throwable) {
                $brand = null;
            }
        }
        // 미설정/조회 실패를 다른 판매자 또는 내부 솔루션 브랜드로 대체하지 않습니다.
        request()->attributes->set('teewide.seller_brand', $brand ?: '라이브');
        $view->with('sellerBrand', $brand ?: '라이브');
    }
}
