<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Controllers\Customer;

use App\Helpers\ResponseHelper;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Http\Requests\LiveCustomerRequest;
use Plugins\Yutiv\LiveCommerce\Services\LiveCustomerService;
use Plugins\Yutiv\LiveCommerce\Services\LiveOfficeService;
use Plugins\Yutiv\LiveCommerce\Services\LiveOrderService;
use Plugins\Yutiv\LiveCommerce\Services\LivePasswordResetService;
use Plugins\Yutiv\LiveCommerce\Support\LiveContext;
use Plugins\Yutiv\LiveCommerce\Support\TeeWideAuth;
use Plugins\Yutiv\LiveCommerce\Support\TeeWidePresenter;

class LiveCustomerController
{
    public function __construct(private readonly LiveOfficeService $office, private readonly LiveCustomerService $customers, private readonly LiveOrderService $orders, private readonly LivePasswordResetService $resets) {}

    private function base(array $data = []): array
    {
        return $data + ['portalUrl' => TeeWidePresenter::portalUrl(), 'liveUrl' => TeeWidePresenter::liveUrl(), 'teeWideUser' => TeeWideAuth::user(), 'tenant' => LiveContext::requireTenant()];
    }

    public function password(Request $request, ?string $token = null): View
    {
        return view('teewide::customer.password', $this->base(['token' => $token, 'changing' => $request->is('password/change')]));
    }

    public function account(): View
    {
        $user = LiveContext::user();

        return view('teewide::customer.account', $this->base(['user' => $user, 'customsMask' => $user->customs_code ? 'P********'.substr($user->customs_code, -4) : '미등록',
            'canOperate' => $this->office->query(E::Staff, $user->tenant_id)->where('user_id', $user->id)->where('active', true)->exists(),
            'ownedChannels' => TeeWidePresenter::ownedChannels($user),
            'addresses' => $this->office->query(E::Address, $user->tenant_id)->where('user_id', $user->id)->get(),
            'orders' => $this->office->query(E::Order, $user->tenant_id)->where('user_id', $user->id)->orderByDesc('id')->paginate(20)]));
    }

    public function order(string $uuid): View
    {
        $user = LiveContext::user();
        $order = $this->office->query(E::Order, $user->tenant_id)->where('user_id', $user->id)->where('uuid', $uuid)->firstOrFail();
        $notice = $this->office->query(E::Event, $user->tenant_id)->where('order_id', $order->id)->where('type', 'bank_notice')->orderByDesc('id')->first();

        return view('teewide::customer.order', $this->base(['order' => $order, 'bankNotice' => $notice ? decrypt($notice->data['encrypted_notice']) : null, 'lines' => $this->office->query(E::OrderLine, $user->tenant_id)->where('order_id', $order->id)->get()]));
    }

    public function cart(Request $request): View
    {
        $tenant = LiveContext::requireTenant();
        $user = TeeWideAuth::user();
        $key = $this->cartKey($request);
        $lines = $this->office->query(E::CartLine, $tenant->id)->where(fn ($q) => $user ? $q->where('user_id', $user->id) : $q->whereNull('user_id')->where('cart_key', $key))->get();
        foreach ($lines as $line) {
            $line->display_option = $this->office->query(E::Option, $tenant->id)->findOrFail($line->option_id);
        }

        return view('teewide::customer.cart', $this->base(['lines' => $lines, 'addresses' => $user ? $this->office->query(E::Address, $tenant->id)->where('user_id', $user->id)->get() : collect()]));
    }

    public function store(LiveCustomerRequest $request): JsonResponse
    {
        $action = $request->route('action');
        $d = $request->validated();
        $tenant = LiveContext::requireTenant();
        if ($action === 'forgot') {
            $result = ['message' => $this->resets->requestReset($tenant->id, $d['email'])];
        } elseif ($action === 'reset') {
            $this->resets->reset($tenant->id, $d['token'], $d['password']);
            $result = ['saved' => true];
        } elseif ($action === 'cart') {
            $result = $this->orders->addCart($tenant->id, TeeWideAuth::user()?->id, $this->cartKey($request), $d['broadcast_id'], $d['option_id'], $d['quantity'], $d['code'] ?? null, $d['operation_key']);
        } else {
            $user = LiveContext::user();
            if ($action === 'profile') {
                $this->customers->profile($user, $d);
                $result = ['saved' => true, 'external_verification' => false];
            } elseif ($action === 'address') {
                $this->customers->address($user, $d);
                $result = ['saved' => true];
            } elseif ($action === 'password') {
                $this->customers->password($user, $d['current_password'], $d['password']);
                $request->session()->put('live.session_version', $user->session_version);
                $request->session()->regenerate();
                $result = ['saved' => true];
            } elseif ($action === 'order') {
                $address = $this->office->query(E::Address, $tenant->id)->where('user_id', $user->id)->findOrFail($d['address_id']);
                $result = $this->orders->create($tenant->id, $user->id, $d['lines'], $address->delivery, $d['operation_key']);
            } else {
                $this->customers->orderAction($user, $request->route('uuid'), $action, $d);
                $result = ['saved' => true];
            }
        }

        return ResponseHelper::success('common.success', $result);
    }

    private function cartKey(Request $request): string
    {
        if (! $request->session()->has('live.cart_key')) {
            $request->session()->put('live.cart_key', bin2hex(random_bytes(24)));
        }

        return $request->session()->get('live.cart_key');
    }
}
