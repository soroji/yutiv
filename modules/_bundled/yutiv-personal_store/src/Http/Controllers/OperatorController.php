<?php

namespace Modules\Yutiv\PersonalStore\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AuthBaseController;
use Illuminate\Http\JsonResponse;
use Modules\Yutiv\PersonalStore\Http\Requests\ManageStoreRequest;
use Modules\Yutiv\PersonalStore\Http\Requests\StoreListRequest;
use Modules\Yutiv\PersonalStore\Repositories\Contracts\StoreRepositoryInterface;
use Modules\Yutiv\PersonalStore\Services\StoreManagementService;

class OperatorController extends AuthBaseController
{
    public function parents(StoreListRequest $request, StoreRepositoryInterface $stores): JsonResponse
    {
        return ResponseHelper::success('messages.success', $stores->parentOptions($request->user()->id, $request->validated()));
    }

    public function categories(StoreListRequest $request, StoreRepositoryInterface $stores): JsonResponse
    {
        return ResponseHelper::success('messages.success', $stores->categories($request->user()->id, $request->validated()));
    }

    public function index(StoreListRequest $request, StoreManagementService $service): JsonResponse
    {
        return ResponseHelper::success('messages.success', $service->dashboard($request->user()->id, $request->validated()));
    }

    public function update(ManageStoreRequest $request, StoreManagementService $service): JsonResponse
    {
        return ResponseHelper::success('messages.success', $service->manage($request->user()->id, $request->validated()));
    }

    public function catalog(StoreListRequest $request, StoreManagementService $service): JsonResponse
    {
        return ResponseHelper::success('messages.success', $service->catalog($request->user()->id, $request->validated()));
    }
}
