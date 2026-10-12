<?php

namespace Plugins\Yutiv\LiveCommerce\Services;

use Illuminate\Support\Str;
use Plugins\Yutiv\LiveCommerce\Contracts\LiveRepositoryInterface;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Models\LiveRecord;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;

class LiveOrderService extends LiveWorkflow
{
    public function __construct(LiveRepositoryInterface $records, private readonly LiveCatalogService $catalog, private readonly LiveInventoryService $inventory)
    {
        parent::__construct($records);
    }

    public function addCart(int $tenant, ?int $user, string $cart, int $broadcast, int $option, int $quantity, ?string $link, string $key): array
    {
        return $this->once($tenant, 'cart:'.$key, compact('user', 'cart', 'broadcast', 'option', 'quantity', 'link'), function () use ($tenant, $user, $cart, $broadcast, $option, $quantity, $link) {
            $attribution = $this->catalog->attribution($tenant, $broadcast, $option, $link);
            $this->sellable($tenant, $option);
            $line = $this->records->create(E::CartLine, $tenant, ['uuid' => (string) Str::uuid(), 'cart_key' => $cart, 'user_id' => $user,
                'option_id' => $option, 'quantity' => $quantity, 'attribution' => $attribution]);

            return ['line' => $line->uuid];
        });
    }

    public function mergeCart(int $tenant, int $user, string $cart): void
    {
        // 라인을 병합/복제하지 않고 소유권만 옮겨 UUID와 귀속을 보존합니다.
        $this->records->query(E::CartLine, $tenant)->where('cart_key', $cart)->whereNull('user_id')->update(['user_id' => $user]);
    }

    public function create(int $tenant, int $user, array $uuids, array $delivery, string $key): array
    {
        sort($uuids);

        return $this->once($tenant, 'order:'.$user.':'.$key, compact('user', 'uuids', 'delivery'), function () use ($tenant, $user, $uuids, $delivery, $key) {
            $lines = $this->records->query(E::CartLine, $tenant)->where('user_id', $user)->whereIn('uuid', $uuids)->orderBy('option_id')->lockForUpdate()->get();
            abort_unless($lines->count() === count($uuids) && $lines->count() > 0, 422, __('yutiv-live_commerce::live.errors.e_0552d9daad'));
            $tenantModel = LiveTenant::query()->findOrFail($tenant);
            $total = 0;
            $prepared = [];
            $bankIds = [];
            foreach ($lines as $line) {
                $old = $line->attribution;
                $link = isset($old['sale_link_id']) ? $this->row(E::SaleLink, $tenant, $old['sale_link_id'])->code : null;
                $attribution = $this->catalog->attribution($tenant, $old['broadcast_id'], $line->option_id, $link);
                // 유효성·현재 정책은 다시 검사하되, 담을 때 서버가 확인한 판매 귀속은 링크 수정으로 바꾸지 않습니다.
                foreach (['channel_id', 'showhost_id', 'sale_link_id', 'contact'] as $field) {
                    $attribution[$field] = $old[$field];
                }
                $this->row(E::Channel, $tenant, $attribution['channel_id']);
                if ($attribution['showhost_id']) {
                    $this->row(E::Showhost, $tenant, $attribution['showhost_id']);
                }
                $option = $this->sellable($tenant, $line->option_id, true);
                $product = $this->row(E::Product, $tenant, $option->product_id);
                $available = $this->records->query(E::Unit, $tenant)->where('option_id', $option->id)->where('state', 'available')->orderBy('id')->limit($line->quantity)->lockForUpdate()->get();
                abort_unless($available->count() === $line->quantity, 409, __('yutiv-live_commerce::live.errors.e_16e7ba70cb'));
                $price = $option->price - $option->discount;
                $total += $price * $line->quantity;
                $broadcast = $this->row(E::Broadcast, $tenant, $old['broadcast_id']);
                $bank = $broadcast->bank_account_id ? $this->row(E::Bank, $tenant, $broadcast->bank_account_id) : $this->records->query(E::Bank, $tenant)->where('default', true)->where('active', true)->first();
                abort_unless($bank && $bank->active, 409, __('yutiv-live_commerce::live.errors.e_5a3a8c4f44'));
                $bankIds[$bank->id] = $bank;
                $prepared[] = [$line, $option, $product, $price, $attribution, $available];
                // 동일 옵션의 다음 라인이 같은 재고를 고르지 않도록 트랜잭션 안에서 잠정 예약합니다.
                foreach ($available as $unit) {
                    $unit->fill(['state' => 'reserved'])->save();
                }
            }
            abort_unless(count($bankIds) === 1, 422, __('yutiv-live_commerce::live.errors.e_eabbd810ef'));
            $bank = reset($bankIds);
            $order = $this->records->create(E::Order, $tenant, ['uuid' => (string) Str::uuid(), 'user_id' => $user, 'operation_key' => $key,
                'request_hash' => hash('sha256', json_encode($uuids)), 'subtotal' => $total, 'shipping' => 0, 'total' => $total,
                'bank_snapshot' => ['bank' => $bank->bank, 'number' => $bank->account_number, 'holder' => $bank->holder, 'account_id' => $bank->id],
                'delivery' => $delivery, 'expires_at' => now()->addMinutes($tenantModel->reservation_minutes)]);
            foreach ($prepared as [$cart, $option, $product, $price, $attribution, $units]) {
                $basis = $price * $cart->quantity;
                $snapshot = array_merge($attribution, ['name' => $product->name, 'product_code' => $product->code, 'option_name' => $option->name, 'price' => $option->price,
                    'discount' => $option->discount, 'basis' => $basis, 'return_days' => $tenantModel->return_days,
                    'incentives' => IncentiveCalculator::amounts($basis, $attribution['channel_rate'], $attribution['host_rate'], $attribution['channel_id'], $attribution['host_shares'])]);
                $line = $this->records->create(E::OrderLine, $tenant, ['uuid' => $cart->uuid, 'order_id' => $order->id, 'option_id' => $option->id,
                    'quantity' => $cart->quantity, 'unit_price' => $price, 'snapshot' => $snapshot]);
                foreach ($units as $unit) {
                    $unit->fill(['order_line_id' => $line->id])->save();
                    $this->inventory->movement($unit, $user, 'reserve:'.$order->uuid, 'reserve', ['state' => 'available', 'location' => $unit->location], '주문 예약');
                }
                $cart->delete();
            }
            $this->audit($tenant, $user, 'order.created', ['order_id' => $order->id]);

            return ['order' => $order->uuid, 'total' => $total];
        });
    }

    public function deposit(int $tenant, int $actor, int $orderId, int $received, string $key, string $reason = ''): array
    {
        return $this->once($tenant, 'deposit:'.$key, compact('orderId', 'received', 'reason'), function () use ($tenant, $actor, $orderId, $received, $key, $reason) {
            $order = $this->row(E::Order, $tenant, $orderId, true);
            if ($order->payment_state === 'paid') {
                abort_unless($received === $order->received, 409, __('yutiv-live_commerce::live.errors.e_5749dca850'));

                return ['state' => 'paid'];
            }
            $expected = $order->shipping;
            foreach ($this->records->query(E::OrderLine, $tenant)->where('order_id', $orderId)->get() as $line) {
                $expected += ($line->quantity - $line->cancelled) * $line->unit_price;
            }
            $valid = $order->payment_state === 'pending' && $order->expires_at > now() && $received === $expected;
            $order->fill(['received' => $received, 'payment_state' => $valid ? 'paid' : 'review', 'paid_at' => $valid ? now() : null])->save();
            $this->records->create(E::Event, $tenant, ['order_id' => $orderId, 'operation_key' => $key, 'type' => 'deposit', 'amount' => $received, 'data' => ['result' => $order->payment_state, 'reason' => $reason], 'actor_id' => $actor]);
            $this->audit($tenant, $actor, 'order.deposit', ['order_id' => $orderId, 'state' => $order->payment_state, 'reason' => $reason]);

            return ['state' => $order->payment_state];
        });
    }

    public function resolveDeposit(int $tenant, int $actor, int $orderId, int $received, string $reason, string $key): array
    {
        return $this->once($tenant, 'resolve-deposit:'.$key, compact('orderId', 'received', 'reason'), function () use ($tenant, $actor, $orderId, $received, $reason, $key) {
            $order = $this->row(E::Order, $tenant, $orderId, true);
            abort_unless($order->payment_state === 'review' && $order->received === $received, 409, __('yutiv-live_commerce::live.errors.e_2d199496ec'));
            $lines = $this->records->query(E::OrderLine, $tenant)->where('order_id', $orderId)->orderBy('option_id')->lockForUpdate()->get();
            $target = 0;
            abort_unless($lines->sum(fn ($line) => $line->quantity - $line->cancelled) > 0, 409, __('yutiv-live_commerce::live.errors.e_3921b4f248'));
            foreach ($lines as $line) {
                $this->row(E::Option, $tenant, $line->option_id, true);
                $needed = $line->quantity - $line->cancelled - $line->shipped;
                $reserved = $this->records->query(E::Unit, $tenant)->where('order_line_id', $line->id)->where('state', 'reserved')->count();
                $extra = $needed - $reserved;
                $units = $this->records->query(E::Unit, $tenant)->where('option_id', $line->option_id)->where('state', 'available')->orderBy('id')->limit(max(0, $extra))->lockForUpdate()->get();
                abort_unless($units->count() === max(0, $extra), 409, __('yutiv-live_commerce::live.errors.e_368307a90d'));
                foreach ($units as $unit) {
                    $unit->fill(['state' => 'reserved', 'order_line_id' => $line->id])->save();
                    $this->inventory->movement($unit, $actor, $key, 'rereserve', ['state' => 'available', 'location' => $unit->location], $reason);
                }
                $target += ($line->quantity - $line->cancelled) * $line->unit_price;
            }
            abort_unless($received === $target + $order->shipping, 409, __('yutiv-live_commerce::live.errors.e_9528343379'));
            $order->fill(['payment_state' => 'paid', 'paid_at' => now()])->save();
            $this->records->create(E::Event, $tenant, ['order_id' => $orderId, 'operation_key' => $key, 'type' => 'deposit_review', 'amount' => $received,
                'data' => ['reason' => $reason, 'inventory_rechecked' => true], 'actor_id' => $actor]);
            $this->audit($tenant, $actor, 'deposit.review_resolved', ['order_id' => $orderId]);

            return ['state' => 'paid'];
        });
    }

    public function ship(int $tenant, int $actor, int $orderId, array $qrs, string $key): array
    {
        sort($qrs);

        return $this->once($tenant, 'ship:'.$key, compact('orderId', 'qrs'), function () use ($tenant, $actor, $orderId, $qrs, $key) {
            $order = $this->row(E::Order, $tenant, $orderId, true);
            abort_unless($order->payment_state === 'paid', 409, __('yutiv-live_commerce::live.errors.e_6db435c298'));
            abort_unless(count($qrs) === count(array_unique($qrs)), 422, __('yutiv-live_commerce::live.errors.e_88c704bee2'));
            foreach ($qrs as $qr) {
                $unit = $this->records->query(E::Unit, $tenant)->where('uuid', $qr)->lockForUpdate()->firstOrFail();
                abort_unless($unit->state === 'reserved', 409, __('yutiv-live_commerce::live.errors.e_68dae84a88'));
                $line = $this->row(E::OrderLine, $tenant, $unit->order_line_id, true);
                abort_unless($line->order_id === $orderId && $line->option_id === $unit->option_id && $line->shipped < $line->quantity - $line->cancelled, 409, __('yutiv-live_commerce::live.errors.e_963c3722cd'));
                $unit->state = 'shipped';
                $unit->save();
                $line->increment('shipped');
                $this->inventory->movement($unit, $actor, $key, 'ship', ['state' => 'reserved', 'location' => $unit->location], '판매 출고');
            }
            $remaining = $this->records->query(E::Unit, $tenant)->whereIn('order_line_id', $this->records->query(E::OrderLine, $tenant)->where('order_id', $orderId)->select('id'))->where('state', 'reserved')->exists();
            $order->delivery_state = $remaining ? 'partial' : 'shipped';
            $order->save();
            $this->audit($tenant, $actor, 'order.ship', ['order_id' => $orderId, 'quantity' => count($qrs)]);

            return ['state' => $order->delivery_state];
        });
    }

    public function cancel(int $tenant, int $actor, int $lineId, int $quantity, string $reason, string $key): array
    {
        return $this->once($tenant, 'cancel:'.$key, compact('lineId', 'quantity', 'reason'), function () use ($tenant, $actor, $lineId, $quantity, $reason, $key) {
            $line = $this->row(E::OrderLine, $tenant, $lineId, true);
            $order = $this->row(E::Order, $tenant, $line->order_id, true);
            abort_unless($quantity > 0 && $quantity <= $line->quantity - $line->cancelled - $line->shipped, 409, __('yutiv-live_commerce::live.errors.e_46cc7f9ef2'));
            $units = $this->records->query(E::Unit, $tenant)->where('order_line_id', $lineId)->where('state', 'reserved')->orderBy('id')->limit($quantity)->lockForUpdate()->get();
            foreach ($units as $unit) {
                $unit->fill(['state' => 'available', 'order_line_id' => null])->save();
                $this->inventory->movement($unit, $actor, $key, 'cancel', ['state' => 'reserved', 'location' => $unit->location, 'order_line_id' => $lineId], $reason);
            }
            $line->increment('cancelled', $quantity);
            $remainingLines = $this->records->query(E::OrderLine, $tenant)->where('order_id', $order->id)->get();
            if ($remainingLines->sum(fn ($item) => $item->quantity - $item->cancelled) === 0) {
                $order->delivery_state = 'cancelled';
                if ($order->payment_state === 'pending') {
                    $order->payment_state = 'cancelled';
                }
                $order->save();
            } elseif ($order->delivery_state === 'partial' && ! $remainingLines->contains(fn ($item) => $item->shipped < $item->quantity - $item->cancelled)) {
                $order->fill(['delivery_state' => 'shipped'])->save();
            }
            $this->records->create(E::Event, $tenant, ['order_id' => $order->id, 'operation_key' => $key, 'type' => 'cancel', 'amount' => $line->unit_price * $quantity,
                'data' => ['line_id' => $lineId, 'quantity' => $quantity, 'reason' => $reason], 'actor_id' => $actor]);
            $this->audit($tenant, $actor, 'order.cancel', ['line_id' => $lineId, 'quantity' => $quantity]);

            return ['cancelled' => $line->cancelled];
        });
    }

    public function expire(int $tenant, int $orderId): array
    {
        $candidate = $this->row(E::Order, $tenant, $orderId);
        if ($candidate->reservation_released_at || ! in_array($candidate->payment_state, ['pending', 'review'], true) || $candidate->expires_at > now()) {
            return ['released' => 0];
        }

        return $this->once($tenant, 'expire:'.$orderId, compact('orderId'), function () use ($tenant, $orderId) {
            $order = $this->row(E::Order, $tenant, $orderId, true);
            if (! in_array($order->payment_state, ['pending', 'review'], true) || $order->expires_at > now()) {
                return ['released' => 0];
            }
            $lines = $this->records->query(E::OrderLine, $tenant)->where('order_id', $orderId)->pluck('id');
            $units = $this->records->query(E::Unit, $tenant)->whereIn('order_line_id', $lines)->where('state', 'reserved')->lockForUpdate()->get();
            foreach ($units as $unit) {
                $originalLine = $unit->order_line_id;
                $unit->fill(['state' => 'available', 'order_line_id' => null])->save();
                $this->inventory->movement($unit, 0, 'expire:'.$orderId, 'expire', ['state' => 'reserved', 'location' => $unit->location, 'order_line_id' => $originalLine], '입금 대기 예약 만료');
            }
            if ($order->payment_state === 'pending') {
                $order->payment_state = 'expired';
            }
            $order->reservation_released_at = now();
            $order->save();

            return ['released' => $units->count()];
        });
    }

    public function delivered(int $tenant, int $actor, int $orderId, string $key): array
    {
        return $this->once($tenant, 'delivered:'.$key, compact('orderId'), function () use ($tenant, $actor, $orderId, $key) {
            $order = $this->row(E::Order, $tenant, $orderId, true);
            if ($order->delivery_state === 'delivered') {
                return ['delivered' => true];
            }
            abort_unless($order->payment_state === 'paid' && $order->delivery_state === 'shipped', 409, __('yutiv-live_commerce::live.errors.e_6f171ca8bd'));
            $order->fill(['delivery_state' => 'delivered', 'delivered_at' => now()])->save();
            $this->records->create(E::Event, $tenant, ['order_id' => $orderId, 'operation_key' => $key, 'type' => 'delivered', 'amount' => 0, 'data' => [], 'actor_id' => $actor]);
            $this->audit($tenant, $actor, 'order.delivered', ['order_id' => $orderId]);

            return ['delivered' => true];
        });
    }

    private function sellable(int $tenant, int $id, bool $lock = false): LiveRecord
    {
        $option = $this->row(E::Option, $tenant, $id, $lock);
        $product = $this->row(E::Product, $tenant, $option->product_id, $lock);
        abort_unless($option->active && $product->sellable && $product->published, 409, __('yutiv-live_commerce::live.errors.e_f1243e0b4a'));

        return $option;
    }
}
