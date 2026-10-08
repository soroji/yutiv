<?php

namespace Plugins\Yutiv\StorefrontSupport\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\Yutiv\StorefrontSupport\Http\Resources\BusinessInfoResource;
use Plugins\Yutiv\StorefrontSupport\Services\BusinessInfoService;

final class BusinessInfoController extends PublicBaseController
{
    public function __construct(private BusinessInfoService $service)
    {
        parent::__construct();
    }

    /** GET /api/plugins/yutiv-storefront_support/business-info; no request parameters. */
    public function show(): JsonResponse
    {
        return ResponseHelper::success('common.success', new BusinessInfoResource($this->service->get()));
    }
}
