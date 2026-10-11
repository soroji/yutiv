<?php

namespace Modules\Yutiv\PersonalStore\Http\Controllers;

use App\Enums\PermissionType;
use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Modules\Yutiv\PersonalStore\Http\Requests\ManageStoreRequest;
use Modules\Yutiv\PersonalStore\Http\Requests\StoreListRequest;
use Modules\Yutiv\PersonalStore\Services\StoreManagementService;

class HeadquartersController extends AdminBaseController
{
    public function index(StoreListRequest $request, StoreManagementService $service): JsonResponse
    {
        return ResponseHelper::success('messages.success', $service->headquarters($request->user()->id, $request->validated()));
    }

    public function update(ManageStoreRequest $request, StoreManagementService $service): JsonResponse
    {
        abort_unless($request->user()->isSuperAdmin() || $request->user()->hasPermission('yutiv-personal_store.headquarters.manage', PermissionType::Admin), 403);

        return ResponseHelper::success('messages.success', $service->manage($request->user()->id, $request->validated()));
    }
}
