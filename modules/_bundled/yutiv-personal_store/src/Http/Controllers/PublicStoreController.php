<?php

namespace Modules\Yutiv\PersonalStore\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
use Modules\Yutiv\PersonalStore\Http\Requests\StoreListRequest;
use Modules\Yutiv\PersonalStore\Repositories\Contracts\StoreRepositoryInterface;
use Modules\Yutiv\PersonalStore\Services\AttributionProof;

class PublicStoreController extends PublicBaseController
{
    public function show(string $slug, StoreRepositoryInterface $stores, AttributionProof $proof): JsonResponse
    {
        return ResponseHelper::success('messages.success', $stores->publicStore($slug));
    }

    public function origin(string $slug, StoreRepositoryInterface $stores, AttributionProof $proof): JsonResponse
    {
        abort_if(request()->header('Sec-Fetch-Site') === 'cross-site', 403);
        if (request()->header('Origin') !== null) {
            abort_unless(request()->header('Origin') === request()->getSchemeAndHttpHost(), 403);
        }
        $reference = $stores->publicOrigin($slug);
        $saleProof = $proof->issue($reference, time() + 86400, 'sale');

        return ResponseHelper::success('messages.success', ['proof' => $saleProof])->withCookie(cookie('yutiv_store_origin', $saleProof, 1440, '/', null, request()->isSecure(), true, false, 'lax'));
    }

    public function products(string $slug, StoreListRequest $request, StoreRepositoryInterface $stores): JsonResponse
    {
        return ResponseHelper::success('messages.success', $stores->products($slug, $request->validated()));
    }

    public function membershipProof(AttributionProof $proof): JsonResponse
    {
        $reference = $proof->verify(request()->cookie('yutiv_store_origin'), 'sale');

        return ResponseHelper::success('messages.success', ['proof' => $reference === null ? null : $proof->issue($reference, time() + 3600, 'membership')]);
    }

    public function detail(string $slug, string $code, StoreRepositoryInterface $stores): JsonResponse
    {
        return ResponseHelper::success('messages.success', $stores->detail($slug, $code));
    }
}
