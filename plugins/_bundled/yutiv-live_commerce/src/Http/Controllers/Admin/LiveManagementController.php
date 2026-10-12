<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Controllers\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Support\RouteCacheHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Plugins\Yutiv\LiveCommerce\Http\Requests\LiveHeadquartersMediaRequest;
use Plugins\Yutiv\LiveCommerce\Http\Requests\LiveHeadquartersRequest;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;
use Plugins\Yutiv\LiveCommerce\Services\LiveAccountService;
use Plugins\Yutiv\LiveCommerce\Services\LiveDomainService;
use Plugins\Yutiv\LiveCommerce\Services\LiveHeadquartersService;
use Plugins\Yutiv\LiveCommerce\Services\LiveMediaService;

class LiveManagementController extends AdminBaseController
{
    public function __construct(private readonly LiveHeadquartersService $headquarters, private readonly LiveAccountService $accounts, private readonly LiveDomainService $domains)
    {
        parent::__construct();
        $this->middleware('permission:admin,yutiv-live_commerce.management.manage');
    }

    public function index(Request $request): JsonResponse
    {
        return ResponseHelper::success('common.success', $this->headquarters->overview($request->integer('tenant') ?: null));
    }

    public function logo(LiveHeadquartersMediaRequest $request): JsonResponse
    {
        $id = (int) $request->route('tenant');
        LiveTenant::findOrFail($id);

        return ResponseHelper::success('common.success', app(LiveMediaService::class)->save($id, $request->user()->id, 'logo', null, $request->file('image')));
    }

    public function store(LiveHeadquartersRequest $request): JsonResponse
    {
        $action = $request->route('action');
        $data = $request->validated();
        $id = (int) $request->route('tenant');
        $actor = $request->user()->id;
        if ($action === 'tenant') {
            $result = ['tenant' => $this->headquarters->tenant($actor, $data, $id ?: null)];
        } else {
            LiveTenant::findOrFail($id);
            $result = match ($action) {
                'domain' => ['domain' => $this->domains->save($id, $actor, $data['host'], $data['active'], $data['primary'])],
                'account' => $this->accounts->issue($id, $actor, $data, $data['user_id'] ?? null),
                'permission' => $this->voidResult(fn () => $this->accounts->permission($id, $actor, $data['user_id'], $data['role'], $data['active'])),
                'lock' => $this->voidResult(fn () => $this->accounts->lock($id, $actor, $data['user_id'], $data['locked'])),
                'legacy' => $this->voidResult(fn () => $this->accounts->transferLegacy($id, $actor, $data['user_id'], $data['reason'])),
                default => abort(404),
            };
        }
        if ($action === 'domain') {
            RouteCacheHelper::rebuild();
        }

        return ResponseHelper::success('common.success', $result);
    }

    private function voidResult(callable $work): array
    {
        $work();

        return ['saved' => true];
    }
}
