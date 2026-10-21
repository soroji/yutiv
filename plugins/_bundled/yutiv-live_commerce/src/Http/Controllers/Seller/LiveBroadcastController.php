<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Controllers\Seller;

use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Enums\StaffRole;
use Plugins\Yutiv\LiveCommerce\Http\Requests\LiveBroadcastRequest;
use Plugins\Yutiv\LiveCommerce\Services\LiveBroadcastWorkspace;
use Plugins\Yutiv\LiveCommerce\Support\LiveContext;
use Plugins\Yutiv\LiveCommerce\Support\TeeWidePresenter;

class LiveBroadcastController
{
    public function __construct(private readonly LiveBroadcastWorkspace $workspace) {}

    public function index(LiveBroadcastRequest $request)
    {
        $list = $this->workspace->context(http_build_query($request->validated()));
        if ($request->integer('edit')) {
            $this->workspace->queryRecord(E::Broadcast, LiveContext::requireTenant()->id, $request->integer('edit'));

            return redirect('/seller/broadcasts/'.$request->integer('edit').'?'.http_build_query(['list' => $list]));
        }

        return $this->page($this->workspace->listing(LiveContext::requireTenant()->id, $request->validated()) + ['mode' => 'list', 'list' => $list]);
    }

    public function create(LiveBroadcastRequest $request)
    {
        return $this->page($this->workspace->choices(LiveContext::requireTenant()->id) + ['mode' => 'form', 'editing' => null, 'list' => $this->workspace->context($request->input('list') ?? '')]);
    }

    public function show(LiveBroadcastRequest $request, int $broadcast)
    {
        return $this->page($this->workspace->detail(LiveContext::requireTenant()->id, $broadcast, $request->validated()) + ['mode' => 'form', 'list' => $this->workspace->context($request->input('list') ?? '')]);
    }

    public function legacy(LiveBroadcastRequest $request)
    {
        $record = $request->integer('edit');
        if ($request->segment(count($request->segments())) === 'broadcast-items') {
            if ($record) {
                $record = $this->workspace->queryRecord(E::BroadcastItem, LiveContext::requireTenant()->id, $record)->broadcast_id;
            } else {
                $record = $request->integer('broadcast_id');
            }
            if ($record) {
                $this->workspace->queryRecord(E::Broadcast, LiveContext::requireTenant()->id, $record);

                return redirect('/seller/broadcasts/'.$record.'#broadcast-products');
            }
        }

        return redirect('/seller/broadcasts');
    }

    private function page(array $data)
    {
        $tenant = LiveContext::requireTenant();
        $user = LiveContext::user();
        $staff = $this->workspace->queryRecordByUser($tenant->id, $user->id);
        $role = StaffRole::from($staff->role);
        $menu = app(LiveOfficeController::class)->menu($role);

        return view('teewide::seller.broadcasts', $data + ['tenant' => $tenant, 'teeWideUser' => $user, 'role' => $role, 'menu' => $menu, 'section' => 'broadcasts', 'back' => $this->workspace->back($data['list']), 'portalUrl' => TeeWidePresenter::portalUrl(), 'liveUrl' => TeeWidePresenter::liveUrl()]);
    }
}
