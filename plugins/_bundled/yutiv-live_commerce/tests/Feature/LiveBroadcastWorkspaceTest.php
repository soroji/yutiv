<?php

namespace Plugins\Yutiv\LiveCommerce\Tests\Feature;

use Plugins\Yutiv\LiveCommerce\Contracts\LiveRepositoryInterface;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;
use Plugins\Yutiv\LiveCommerce\Models\TeeWideUser;
use Plugins\Yutiv\LiveCommerce\Services\LiveAccountService;
use Plugins\Yutiv\LiveCommerce\Services\LiveBroadcastWorkspace;
use Plugins\Yutiv\LiveCommerce\Services\LiveCatalogService;
use Plugins\Yutiv\LiveCommerce\Services\LiveInventoryService;
use Plugins\Yutiv\LiveCommerce\Services\LiveOrderService;

class LiveBroadcastWorkspaceTest extends TeeWideMainDesignTest
{
    private function setupBroadcast(): array
    {
        $tenant = $this->seedGolfifTenant();
        $repo = app(LiveRepositoryInterface::class);
        $catalog = app(LiveCatalogService::class);
        $accounts = app(LiveAccountService::class);
        $issued = $accounts->issue($tenant->id, 0, ['name' => '방송 합성 관리자', 'username' => 'broadcast-operator', 'role' => 'owner']);
        $user = TeeWideUser::findOrFail($issued['user_id']);
        $accounts->changePassword($user, 'Synthetic-only-1234!');
        $login = $this->post('http://'.self::ROOT_HOST.'/login', ['email' => 'broadcast-operator', 'password' => 'Synthetic-only-1234!']);
        $login->assertRedirect();
        $cookie = $login->getCookie('teewide_session', false);
        $this->withUnencryptedCookie('teewide_session', $cookie->getValue());
        $this->withCredentials();
        $channel = $catalog->save(E::Channel, $tenant->id, $user->id, ['name' => '격리 채널', 'url' => 'https://www.youtube.com/@synthetic', 'active' => true]);
        $host = $catalog->save(E::Showhost, $tenant->id, $user->id, ['name' => 'Jessi', 'active' => true]);
        $bank = $catalog->save(E::Bank, $tenant->id, $user->id, ['bank' => '격리 은행', 'holder' => '합성', 'account_number' => '0000000000', 'default' => true, 'active' => true]);
        $product = $catalog->save(E::Product, $tenant->id, $user->id, ['name' => '방송 합성 상품', 'code' => 'BROADCAST070', 'published' => true, 'sellable' => true]);
        $option = $catalog->save(E::Option, $tenant->id, $user->id, ['name' => '기본', 'product_id' => $product->id, 'price' => 100000, 'discount' => 0, 'active' => true]);
        app(LiveInventoryService::class)->receive($tenant->id, $user->id, $option->id, 5, '격리 선반', 'broadcast070-stock');

        return compact('tenant', 'repo', 'catalog', 'user', 'channel', 'host', 'bank', 'product', 'option');
    }

    private function input(int $channel): array
    {
        $zone = 'Asia/Seoul';

        return ['name' => '방송070', 'channel_id' => $channel, 'starts_at' => now($zone)->subHour()->format('Y-m-d\TH:i'), 'ends_at' => now($zone)->addHour()->format('Y-m-d\TH:i'), 'state' => 'draft', 'broadcast_workspace' => '1', 'channel_payment' => '0', 'separate_sales' => '0', 'list' => 'search=방송070&state=draft&page=2'];
    }

    public function test_broadcast_http_starts_without_hosts_and_preserves_context_and_saved_policy(): void
    {
        extract($this->setupBroadcast());
        $base = 'http://'.self::ROOT_HOST;
        $this->get($base.'/seller/broadcasts')->assertOk()->assertDontSee('data-broadcast-form', false);
        $new = $this->get($base.'/seller/broadcasts/create');
        $new->assertOk()->assertSee('참여 쇼호스트 미선택')->assertSee('data-selected-hosts>[]', false);
        $data = $this->input($channel->id);
        $result = $this->postJson($base.'/seller/catalog/broadcasts', $data);
        $result->assertOk();
        $id = $result->json('data.record_id');
        $b = $repo->query(E::Broadcast, $tenant->id)->findOrFail($id);
        $this->assertSame([], $b->host_shares);
        $this->assertSame(0, $b->host_rate);
        $this->assertTrue($b->starts_at->equalTo($b->sale_starts_at));
        $this->assertStringContainsString('list=', $result->json('data.return_url'));
        $single = $data + ['host_shares' => [$host->id => '9.99'], 'host_percent' => '5.25'];
        $single['name'] = '방송070 수정';
        $this->postJson($base.'/seller/catalog/broadcasts/'.$id, $single)->assertOk();
        $this->assertEquals([$host->id => 10000], $b->fresh()->host_shares);
        $this->assertSame(525, $b->fresh()->host_rate);
        $other = $catalog->save(E::Showhost, $tenant->id, $user->id, ['name' => '다른 진행자', 'active' => true]);
        $multi = $data + ['host_shares' => [$host->id => '33.33', $other->id => '66.67'], 'host_percent' => '5.25', 'channel_percent' => '3.33'];
        $multi['channel_payment'] = '1';
        $this->postJson($base.'/seller/catalog/broadcasts/'.$id, $multi)->assertOk();
        $before = $b->fresh()->host_shares;
        $this->get($base.'/seller/broadcasts/'.$id)->assertOk()->assertSee('Jessi')->assertSee('다른 진행자')->assertSee('33.33');
        $this->assertSame($before, $b->fresh()->host_shares);
        $multi['host_shares'][$other->id] = '66.66';
        $this->postJson($base.'/seller/catalog/broadcasts/'.$id, $multi)->assertUnprocessable();
        $multi['host_shares'] = [$host->id => '50', '0'.$host->id => '50'];
        $this->postJson($base.'/seller/catalog/broadcasts/'.$id, $multi)->assertUnprocessable();
        $bad = $data;
        $bad['ends_at'] = $data['starts_at'];
        $this->postJson($base.'/seller/catalog/broadcasts/'.$id, $bad)->assertUnprocessable();
        $separate = $data + ['sale_starts_at' => now('Asia/Seoul')->subHours(2)->format('Y-m-d\TH:i'), 'sale_ends_at' => now('Asia/Seoul')->addHours(2)->format('Y-m-d\TH:i')];
        $separate['separate_sales'] = '1';
        $this->postJson($base.'/seller/catalog/broadcasts/'.$id, $separate)->assertOk();
        $this->assertFalse($b->fresh()->starts_at->equalTo($b->fresh()->sale_starts_at));
        $this->get($base.'/seller/channels')->assertRedirect('/seller/broadcasts/channels');
        $this->get($base.'/seller/broadcasts/channels')->assertOk();
        $this->get($base.'/seller/broadcasts/showhosts')->assertOk();
    }

    public function test_broadcast_products_periods_links_and_existing_order_snapshots_are_isolated(): void
    {
        extract($this->setupBroadcast());
        $base = 'http://'.self::ROOT_HOST;
        $make = fn ($name, $shares) => $catalog->save(E::Broadcast, $tenant->id, $user->id, ['name' => $name, 'channel_id' => $channel->id, 'state' => 'selling', 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'sale_starts_at' => now()->subHour(), 'sale_ends_at' => now()->addHour(), 'channel_rate' => 333, 'host_rate' => 525, 'host_shares' => $shares]);
        $a = $make('동시 방송 A', [$host->id => 10000]);
        $b = $make('동시 방송 B', []);
        $this->assertSame(0, $b->host_rate);
        $item = $catalog->save(E::BroadcastItem, $tenant->id, $user->id, ['broadcast_id' => $a->id, 'option_id' => $option->id, 'active' => true, 'position' => 1]);
        $catalog->save(E::BroadcastItem, $tenant->id, $user->id, ['broadcast_id' => $b->id, 'option_id' => $option->id, 'active' => true, 'position' => 0]);
        $link = $catalog->save(E::SaleLink, $tenant->id, $user->id, ['broadcast_id' => $a->id, 'channel_id' => $channel->id, 'showhost_id' => $host->id, 'contact' => 'kakao', 'active' => true]);
        $orders = app(LiveOrderService::class);
        $cart = $orders->addCart($tenant->id, $user->id, 'b070', $a->id, $option->id, 1, $link->code, 'b070-cart');
        $created = $orders->create($tenant->id, $user->id, [$cart['line']], ['name' => '합성 고객'], 'b070-order');
        $order = $repo->query(E::Order, $tenant->id)->where('uuid', $created['order'])->firstOrFail();
        $line = $repo->query(E::OrderLine, $tenant->id)->where('order_id', $order->id)->firstOrFail();
        $snapshot = $line->snapshot;
        $this->assertEquals(['channel:'.$channel->id => 3330, 'host:'.$host->id => 5250], $snapshot['incentives']);
        $this->get($base.'/shop')->assertOk()->assertSee('동시 방송 A')->assertSee('동시 방송 B');
        $this->get($base.'/s/'.$link->code)->assertOk()->assertSee('동시 방송 A')->assertDontSee('동시 방송 B');
        $bad = $this->postJson($base.'/customer/tasks/cart', ['broadcast_id' => $b->id, 'option_id' => $option->id, 'quantity' => 1, 'code' => $link->code, 'operation_key' => 'foreign-broadcast-link']);
        $bad->assertNotFound();
        $catalog->save(E::BroadcastItem, $tenant->id, $user->id, ['broadcast_id' => $a->id, 'option_id' => $option->id, 'active' => false, 'position' => 9], $item->id);
        $this->get($base.'/shop/broadcasts/'.$a->id)->assertOk()->assertDontSee('방송 합성 상품');
        $this->get($base.'/shop/broadcasts/'.$b->id)->assertOk()->assertSee('방송 합성 상품');
        $this->assertTrue($product->fresh()->published);
        $this->assertTrue($option->fresh()->active);
        $a->fill(['state' => 'closed', 'host_rate' => 0, 'host_shares' => []])->save();
        $this->get($base.'/shop/broadcasts/'.$a->id)->assertNotFound();
        $this->assertSame($snapshot, $line->fresh()->snapshot);
        $this->assertSame('paid', $orders->deposit($tenant->id, $user->id, $order->id, 100000, 'closed-existing-order')['state']);
        $b->fill(['sale_starts_at' => now()->addHour(), 'sale_ends_at' => now()->addHours(2)])->save();
        $this->get($base.'/shop/broadcasts/'.$b->id)->assertNotFound();
        $b->fill(['sale_starts_at' => now()->subHours(2), 'sale_ends_at' => now()->subHour()])->save();
        $this->get($base.'/shop/broadcasts/'.$b->id)->assertNotFound();
        $this->assertTrue(app(LiveInventoryService::class)->reconcile($tenant->id)['consistent']);
    }

    public function test_broadcast_list_pages_and_cross_tenant_permissions(): void
    {
        extract($this->setupBroadcast());
        $base = 'http://'.self::ROOT_HOST;
        $data = $this->input($channel->id);
        $data += ['channel_rate' => 0, 'host_rate' => 0, 'host_shares' => [], 'sale_starts_at' => now(), 'sale_ends_at' => now()->addHour()];
        unset($data['broadcast_workspace'],$data['channel_payment'],$data['separate_sales'],$data['list']);
        for ($i = 0; $i < 23; $i++) {
            $catalog->save(E::Broadcast, $tenant->id, $user->id, $data + []);
        }
        $service = app(LiveBroadcastWorkspace::class);
        $one = $service->listing($tenant->id, ['search' => '방송070'])['rows'];
        $this->assertSame(23, $one->total());
        $this->assertCount(20, $one);
        $this->assertSame($one->pluck('id')->sortDesc()->values()->all(), $one->pluck('id')->all());
        $this->get($base.'/seller/broadcasts?search=방송070&page=2')->assertOk()->assertSee('23건');
        config(['g7_settings.core.pagination.result_cap' => 2]);
        $bounded = $service->listing($tenant->id, ['search' => '방송070', 'page'=>1])['rows'];
        $this->assertSame(20, $bounded->total());
        $this->assertTrue($bounded->isTruncated());
        $this->assertTrue($bounded->hasMorePages());
        $this->assertCount(20, $bounded);
        $this->get($base.'/seller/broadcasts?search=방송070')->assertOk()->assertSee('건 이상')->assertSee('rel="next"', false);
        config(['g7_settings.core.pagination.result_cap' => 10000]);
        $other = LiveTenant::create(['slug' => 'other070', 'name' => '격리 타 업체', 'timezone' => 'UTC', 'status' => 'active']);
        $foreign = $repo->create(E::Showhost, $other->id, ['name' => '타 업체 진행자', 'active' => true]);
        $request = $this->input($channel->id) + ['host_shares' => [$foreign->id => '100'], 'host_percent' => '5'];
        $this->postJson($base.'/seller/catalog/broadcasts', $request)->assertNotFound();
        $foreignBroadcast = $repo->create(E::Broadcast, $other->id, ['name' => '타 업체 방송', 'channel_id' => $channel->id, 'state' => 'draft', 'starts_at' => now(), 'ends_at' => now()->addHour(), 'sale_starts_at' => now(), 'sale_ends_at' => now()->addHour(), 'host_shares' => []]);
        $this->get($base.'/seller/broadcasts/'.$foreignBroadcast->id)->assertNotFound();
        $repo->query(E::Staff, $tenant->id)->where('user_id', $user->id)->update(['role' => 'warehouse']);
        $this->get($base.'/seller/broadcasts')->assertForbidden();
        $this->get($base.'/seller/broadcast-items')->assertForbidden();
        $this->get($base.'/seller/channels')->assertForbidden();
    }
}
