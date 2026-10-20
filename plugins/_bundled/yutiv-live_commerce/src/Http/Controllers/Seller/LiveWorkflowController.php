<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Controllers\Seller;

use App\Helpers\ResponseHelper;
use Illuminate\Http\JsonResponse;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Http\Requests\LiveWorkflowRequest;
use Plugins\Yutiv\LiveCommerce\Services\LiveAccountService;
use Plugins\Yutiv\LiveCommerce\Services\LiveIncentiveService;
use Plugins\Yutiv\LiveCommerce\Services\LiveInventoryService;
use Plugins\Yutiv\LiveCommerce\Services\LiveOfficeService;
use Plugins\Yutiv\LiveCommerce\Services\LiveOrderAdjustmentService;
use Plugins\Yutiv\LiveCommerce\Services\LiveOrderService;
use Plugins\Yutiv\LiveCommerce\Services\LiveReturnService;
use Plugins\Yutiv\LiveCommerce\Support\LiveContext;

class LiveWorkflowController
{
    public function __construct(private readonly LiveOfficeService $office, private readonly LiveInventoryService $inventory, private readonly LiveOrderService $orders,
        private readonly LiveReturnService $returns, private readonly LiveIncentiveService $incentives, private readonly LiveAccountService $accounts) {}

    public function store(LiveWorkflowRequest $request): JsonResponse
    {
        $tenant = LiveContext::requireTenant();
        $user = LiveContext::user();
        $d = $request->validated();
        $action = $request->route('action');
        $result = match ($action) {
            'as-receive', 'as-dispatch' => $this->inventory->serviceUnit($tenant->id, $user->id, $d['qr'], $action === 'as-dispatch', $d['location'], $d['reason'], $d['operation_key']),
            'stock-correction' => $this->inventory->correct($tenant->id, $user->id, $d['qr'], $d['movement_id'], $d['reason'], $d['operation_key']),
            'scan' => $this->inventory->preview($tenant->id, $d['qrs'], $d['order_id'] ?? null),
            'label-print' => $this->inventory->labelPrint($tenant->id, $user->id, $d['qrs'], $d['width'], $d['height'], $d['operation_key'], $d['batch'] ?? null),
            'attribution' => app(LiveOrderAdjustmentService::class)->attribution($tenant->id, $user->id, $d['line_id'], $d['channel_id'], $d['showhost_id'] ?? null, $d['reason'], $d['operation_key']),
            'bank-notice' => app(LiveOrderAdjustmentService::class)->bank($tenant->id, $user->id, $d['order_id'], $d['bank_id'], $d['reason'], $d['operation_key']),
            'recovery' => app(LiveOrderAdjustmentService::class)->recovery($tenant->id, $user->id, $d['line_id'], $d['beneficiary'], $d['amount'], $d['reason'], $d['operation_key']),
            'receive' => $this->inventory->receive($tenant->id, $user->id, $d['option_id'], $d['quantity'], $d['location'], $d['operation_key']),
            'transition' => $this->inventory->transition($tenant->id, $user->id, $d['qr'], $d['state'], $d['location'], $d['reason'], $d['operation_key']),
            'ship' => $this->orders->ship($tenant->id, $user->id, $d['order_id'], $d['qrs'], $d['operation_key']),
            'deposit' => $this->orders->deposit($tenant->id, $user->id, $d['order_id'], $d['amount'], $d['operation_key'], $d['reason']),
            'resolve-deposit' => $this->orders->resolveDeposit($tenant->id, $user->id, $d['order_id'], $d['amount'], $d['reason'], $d['operation_key']),
            'return' => $this->returns->receive($tenant->id, $user->id, $d['line_id'], $d['qrs'], $d['reason'], $d['operation_key']),
            'return-review' => $this->returns->resolve($tenant->id, $user->id, $d['line_id'], $d['reason'], $d['operation_key']),
            'refund' => $this->returns->refundRecord($tenant->id, $user->id, $d['order_id'], $d['amount'], $d['reason'], $d['operation_key']),
            'incentive' => $this->incentives->payment($tenant->id, $user->id, $d['line_id'], $d['beneficiary'], $d['amount'], $d['operation_key']),
            'customs' => ['code' => $this->accounts->revealCustoms($tenant->id, $user->id, $d['user_id']), 'external_verification' => false],
            'staff' => $this->accounts->issue($tenant->id, $user->id, $d),
            'staff-permission' => $this->permission($tenant->id, $user->id, $d),
            'brand' => $this->office->brand($tenant->id, $user->id, $d),
            'delivered' => $this->orders->delivered($tenant->id, $user->id, $d['order_id'], $d['operation_key']),
            default => abort(404),
        };
        if (in_array($action, ['deposit', 'resolve-deposit', 'delivered'], true)) {
            foreach ($this->office->query(E::OrderLine, $tenant->id)->where('order_id', $d['order_id'])->pluck('id') as $id) {
                $this->incentives->sync($tenant->id, $id);
            }
        }

        return ResponseHelper::success('common.success', $result);
    }

    private function permission(int $tenant, int $actor, array $data): array
    {
        $target = $this->office->query(E::Staff, $tenant)->where('user_id', $data['user_id'])->firstOrFail();
        abort_if($target->role === 'owner', 403, __('yutiv-live_commerce::live.errors.e_e20a22f1e3'));
        $this->accounts->permission($tenant, $actor, $data['user_id'], $data['role'], $data['active']);

        return ['saved' => true];
    }
}
