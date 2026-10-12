<?php

namespace Plugins\Yutiv\LiveCommerce\Services;

use Illuminate\Support\Facades\Hash;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;
use Plugins\Yutiv\LiveCommerce\Models\TeeWideUser;

class LiveCustomerService extends LiveWorkflow
{
    public function profile(TeeWideUser $user, array $data): void
    {
        $user->name = $data['name'];
        $user->save();
        if (! empty($data['customs_code'])) {
            app(LiveAccountService::class)->customs($user, $data['customs_code']);
        }
    }

    public function address(TeeWideUser $user, array $data): void
    {
        $this->records->create(E::Address, $user->tenant_id, ['user_id' => $user->id, 'delivery' => $data]);
    }

    public function password(TeeWideUser $user, string $current, string $new): void
    {
        $this->records->transaction(function () use ($user, $current, $new) {
            LiveTenant::whereKey($user->tenant_id)->lockForUpdate()->firstOrFail();
            $locked = TeeWideUser::where('tenant_id', $user->tenant_id)->lockForUpdate()->findOrFail($user->id);
            abort_unless(Hash::check($current, $locked->password), 422, __('yutiv-live_commerce::live.errors.e_6c0af3028b'));
            app(LiveAccountService::class)->changePassword($locked, $new);
            $user->refresh();
        });
    }

    public function orderAction(TeeWideUser $user, string $uuid, string $action, array $data): void
    {
        $order = $this->records->query(E::Order, $user->tenant_id)->where('user_id', $user->id)->where('uuid', $uuid)->firstOrFail();
        if ($action === 'cancel') {
            $line = $this->records->query(E::OrderLine, $user->tenant_id)->where('order_id', $order->id)->findOrFail($data['line_id']);
            app(LiveOrderService::class)->cancel($user->tenant_id, $user->id, $line->id, $data['quantity'], $data['reason'], $data['operation_key']);
        } else {
            $this->once($user->tenant_id, 'customer:'.$user->id.':'.$action.':'.$data['operation_key'], ['order' => $uuid, 'data' => $data], function () use ($user, $order, $action, $data) {
                $order = $this->row(E::Order, $user->tenant_id, $order->id, true);
                if ($action === 'confirm') {
                    abort_unless($order->delivery_state === 'delivered', 409, __('yutiv-live_commerce::live.errors.e_22db556bdc'));
                    abort_if($this->records->query(E::OrderLine, $user->tenant_id)->where('order_id', $order->id)->where('disputed', true)->exists(), 409, __('yutiv-live_commerce::live.errors.e_54f5cfd535'));
                    if (! $order->confirmed_at) {
                        $order->fill(['confirmed_at' => now()])->save();
                    }
                } elseif ($action === 'return') {
                    $line = $this->records->query(E::OrderLine, $user->tenant_id)->where('order_id', $order->id)->lockForUpdate()->findOrFail($data['line_id']);
                    abort_unless($line->shipped > $line->returned, 409);
                    $line->disputed = true;
                    $line->save();
                    $this->audit($user->tenant_id, $user->id, 'return.requested', ['line_id' => $line->id, 'reason' => $data['reason']]);
                }
                $this->records->create(E::Event, $user->tenant_id, ['order_id' => $order->id, 'operation_key' => 'customer:'.$action.':'.$data['operation_key'], 'type' => 'customer_'.$action, 'amount' => 0, 'data' => array_diff_key($data, ['operation_key' => true]), 'actor_id' => $user->id]);

                return ['saved' => true];
            });
        }
        foreach ($this->records->query(E::OrderLine, $user->tenant_id)->where('order_id', $order->id)->pluck('id') as $id) {
            app(LiveIncentiveService::class)->sync($user->tenant_id, $id);
        }
    }
}
