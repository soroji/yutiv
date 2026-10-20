<?php

namespace Plugins\Yutiv\LiveCommerce\Tests\Feature;

use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Yutiv\LiveCommerce\Contracts\LiveRepositoryInterface;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;
use Plugins\Yutiv\LiveCommerce\Models\TeeWideUser;
use Plugins\Yutiv\LiveCommerce\Services\LiveAccountService;
use Plugins\Yutiv\LiveCommerce\Services\LiveCatalogService;
use Plugins\Yutiv\LiveCommerce\Services\LiveInventoryService;
use Plugins\Yutiv\LiveCommerce\Services\LiveLedgerService;
use Plugins\Yutiv\LiveCommerce\Services\LiveReceiptService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LiveReceiptLedgerTest extends TeeWideMainDesignTest
{
    public function test_new_receipt_cannot_reuse_legacy_receipt_key_and_mix_old_qrs(): void
    {
        $tenant = $this->seedGolfifTenant();
        $catalog = app(LiveCatalogService::class);
        $product = $catalog->save(E::Product, $tenant->id, 0, ['name' => '이전 상품', 'code' => 'LEGACY']);
        $option = $catalog->save(E::Option, $tenant->id, 0, ['product_id' => $product->id, 'name' => '기본', 'price' => 0]);
        app(LiveInventoryService::class)->receive($tenant->id, 0, $option->id, 1, '기존 창고', 'legacy-key');
        try {
            app(LiveReceiptService::class)->receive($tenant->id, 0, $this->input('legacy-key'));
            $this->fail('기존 입고 물품이 새 배치에 혼입되었습니다.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertSame(1, app(LiveRepositoryInterface::class)->query(E::Unit, $tenant->id)->count());
    }

    #[DataProvider('roleMatrix')]
    public function test_role_permissions_for_receipt_labels_and_financial_ledger(string $role, bool $inventory, bool $labels, bool $ledger): void
    {
        $this->loginRole($role);
        foreach (['product-receive' => $inventory, 'labels' => $labels, 'ledger' => $ledger] as $section => $allowed) {
            $response = $this->get('http://'.self::ROOT_HOST.'/seller/'.$section);
            $allowed ? $response->assertOk() : $response->assertForbidden();
        }
        $response = $this->withCredentials()->postJson('http://'.self::ROOT_HOST.'/seller/receipt', $this->input('role-'.$role));
        $role === 'owner' ? $response->assertOk() : $response->assertForbidden();
        $saved = $this->post('http://'.self::ROOT_HOST.'/seller/reports', ['from' => '2026-10-01', 'to' => '2026-10-31', 'period' => 'day']);
        if ($ledger) {
            $saved->assertRedirect();
            $this->get($saved->headers->get('Location'))->assertOk()->assertSee('저장 운영 보고서');
            $other = LiveTenant::create(['name' => '별도 보고서 업체', 'slug' => 'report-other-'.$role, 'status' => 'active']);
            $uuid = app(LiveLedgerService::class)->save($other->id, 0, '2026-10-01', '2026-10-31', 'day');
            $this->get('http://'.self::ROOT_HOST.'/seller/reports/'.$uuid)->assertNotFound();
        } else {
            $saved->assertForbidden();
        }
    }

    public static function roleMatrix(): array
    {
        return [['owner', true, true, true], ['sales', false, false, false], ['warehouse', true, true, false], ['finance', false, false, true], ['customs', false, false, false]];
    }

    private function input(string $key = 'batch-one'): array
    {
        return ['operation_key' => $key, 'mode' => 'new', 'name' => '격리 입고 상품', 'code' => $key, 'description' => '합성 데이터', 'published' => false, 'sellable' => true,
            'location' => '격리 창고', 'memo' => '입고 테스트', 'items' => [
                ['name' => '검정 / S', 'price' => 10000, 'discount' => 100, 'quantity' => 2],
                ['name' => '검정 / M', 'price' => 10000, 'discount' => 0, 'quantity' => 3],
            ]];
    }

    private function loginRole(string $role = 'owner'): array
    {
        $tenant = $this->seedGolfifTenant();
        $accounts = app(LiveAccountService::class);
        $issued = $accounts->issue($tenant->id, 0, ['name' => '격리 직원', 'username' => 'test-'.$role, 'role' => $role]);
        $user = TeeWideUser::findOrFail($issued['user_id']);
        $accounts->changePassword($user, 'Synthetic-only-1234!');
        $login = $this->post('http://'.self::ROOT_HOST.'/login', ['email' => $issued['username'], 'password' => 'Synthetic-only-1234!']);
        $login->assertRedirect();
        $this->withUnencryptedCookie('teewide_session', $login->getCookie('teewide_session', false)->getValue());

        return [$tenant, $user];
    }

    public function test_receipt_atomic_retry_copy_and_batch_qrs(): void
    {
        [$tenant, $user] = $this->loginRole();
        $this->get('http://'.self::ROOT_HOST.'/seller/product-receive')->assertOk()->assertSee('옵션과 수량');
        $this->get('http://'.self::ROOT_HOST.'/seller/ledger')->assertOk()->assertSee('재고 원장');
        $url = 'http://'.self::ROOT_HOST.'/seller/receipt';
        $input = $this->input();
        $first = $this->withCredentials()->postJson($url, $input)->assertOk()->json('data');
        $this->postJson($url, $input)->assertOk()->assertJsonPath('data.receipt_id', $first['receipt_id']);
        $repo = app(LiveRepositoryInterface::class);
        $this->assertSame(5, $repo->query(E::Unit, $tenant->id)->count());
        $qrs = $repo->query(E::Unit, $tenant->id)->pluck('uuid')->all();
        $this->assertCount(5, array_unique($qrs));
        $options = $repo->query(E::Option, $tenant->id)->orderBy('id')->get();
        $second = ['operation_key' => 'batch-two', 'mode' => 'existing', 'product_id' => $first['product_id'], 'location' => '별도 창고', 'items' => [
            ['option_id' => $options[0]->id, 'name' => $options[0]->name, 'price' => 1, 'discount' => 0, 'quantity' => 1],
        ]];
        $next = $this->postJson($url, $second)->assertOk()->json('data');
        $this->assertNotSame($first['receipt_id'], $next['receipt_id']);
        $this->assertSame(2, $repo->query(E::Option, $tenant->id)->count());
        $this->assertSame(10000, (int) $options[0]->fresh()->price);
        $this->get('http://'.self::ROOT_HOST.'/seller/labels?batch='.$first['receipt_id'])->assertOk()->assertSee('입고 '.$first['receipt_id'].'번');
        $this->get('http://'.self::ROOT_HOST.'/seller/receipts?batch='.$first['receipt_id'])->assertOk()->assertSee('입고 완료: '.$first['receipt_id'].'번');
        $inventory = app(LiveInventoryService::class);
        $this->assertSame($inventory->labelPrint($tenant->id, $user->id, $qrs, 50, 30, 'print-one', $first['receipt_id']), $inventory->labelPrint($tenant->id, $user->id, $qrs, 50, 30, 'print-one', $first['receipt_id']));
        $copy = $input;
        $copy['operation_key'] = 'copy-one';
        $copy['mode'] = 'copy';
        $copy['product_id'] = $first['product_id'];
        $this->postJson($url, $copy)->assertStatus(422);
        $copy['code'] = 'NEW-COPY';
        $this->postJson($url, $copy)->assertOk();
        $this->assertSame(11, $repo->query(E::Unit, $tenant->id)->count());
        $otherQr = $repo->query(E::Unit, $tenant->id)->where('receipt_key', 'batch-two')->value('uuid');
        try {
            $inventory->labelPrint($tenant->id, $user->id, [$otherQr], 50, 30, 'mixed', $first['receipt_id']);
            $this->fail('다른 배치 QR 허용');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    public function test_invalid_quantities_options_and_rollback(): void
    {
        [$tenant] = $this->loginRole();
        $url = 'http://'.self::ROOT_HOST.'/seller/receipt';
        foreach ([-1, 1.5, 'bad', 201] as $quantity) {
            $data = $this->input();
            $data['items'][0]['quantity'] = $quantity;
            $this->withCredentials()->postJson($url, $data)->assertStatus(422);
        }
        $data = $this->input();
        $data['items'][0]['quantity'] = 0;
        $data['items'][1]['quantity'] = 0;
        $this->postJson($url, $data)->assertStatus(422);
        $data = $this->input();
        $data['items'][1]['name'] = $data['items'][0]['name'];
        $this->postJson($url, $data)->assertStatus(422);
        // DB failure on the second option must roll back the first option and its units.
        $data = $this->input();
        $data['items'][1]['discount'] = 20000;
        try {
            app(LiveReceiptService::class)->receive($tenant->id, 0, $data);
            $this->fail('실패 누락');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $repo = app(LiveRepositoryInterface::class);
        $this->assertSame(0, $repo->query(E::Product, $tenant->id)->count());
        $this->assertSame(0, $repo->query(E::Unit, $tenant->id)->count());
        $this->postJson($url, $this->input())->assertOk();
        $changed = $this->input();
        $changed['location'] = '다른 위치';
        $this->postJson($url, $changed)->assertStatus(409);
    }

    public function test_default_option_tenant_scope_and_finance_permissions(): void
    {
        [$tenant] = $this->loginRole('warehouse');
        $this->withCredentials()->postJson('http://'.self::ROOT_HOST.'/seller/receipt', $this->input())->assertForbidden();
        $this->get('http://'.self::ROOT_HOST.'/seller/ledger')->assertForbidden();
        $catalog = app(LiveCatalogService::class);
        $product = $catalog->save(E::Product, $tenant->id, 0, ['name' => '기본 상품', 'code' => 'DEFAULT']);
        $option = $catalog->save(E::Option, $tenant->id, 0, ['name' => '기본', 'product_id' => $product->id, 'price' => 0]);
        $data = ['operation_key' => 'default-one', 'mode' => 'existing', 'product_id' => $product->id, 'location' => '창고', 'items' => [['option_id' => $option->id, 'name' => '기본', 'price' => 0, 'discount' => 0, 'quantity' => 1]]];
        $this->postJson('http://'.self::ROOT_HOST.'/seller/receipt', $data)->assertOk();
        $other = LiveTenant::create(['name' => '다른 업체', 'slug' => 'isolated-other', 'status' => 'active']);
        $foreign = app(LiveReceiptService::class)->receive($other->id, 0, $this->input('other-batch'));
        $foreignReport = app(LiveLedgerService::class)->save($other->id, 0, '2026-10-01', '2026-10-31', 'day');
        $this->get('http://'.self::ROOT_HOST.'/seller/reports/'.$foreignReport)->assertForbidden();
        $this->get('http://'.self::ROOT_HOST.'/seller/labels?batch='.$foreign['receipt_id'])->assertNotFound();
        $data['operation_key'] = 'foreign-attempt';
        $data['product_id'] = $foreign['product_id'];
        $this->postJson('http://'.self::ROOT_HOST.'/seller/receipt', $data)->assertNotFound();
    }

    public function test_historical_stock_boundaries_states_and_missing_history(): void
    {
        $tenant = $this->seedGolfifTenant();
        $tenant->update(['timezone' => 'Asia/Seoul']);
        $repo = app(LiveRepositoryInterface::class);
        $service = app(LiveReceiptService::class);
        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(14, 59, 59));
        $receipt = $service->receive($tenant->id, 0, $this->input('before'));
        $units = $repo->query(E::Unit, $tenant->id)->get();
        $inventory = app(LiveInventoryService::class);
        $this->travelTo(now()->setTime(15, 0, 0)); // 업체 10/2 00:00:00
        $inventory->transition($tenant->id, 0, $units[0]->uuid, 'lost', '창고', '합성 망실', 'lost');
        $inventory->serviceUnit($tenant->id, 0, $units[1]->uuid, false, '수리실', '내부 수리', 'as');
        $this->travelTo(now()->addDay()); // 업체 10/3 00:00:00: 기간 제외
        $inventory->transition($tenant->id, 0, $units[2]->uuid, 'disposed', '창고', '합성 폐기', 'dispose');
        $report = app(LiveLedgerService::class)->report($tenant->id, 0, '2026-10-02', '2026-10-02', 'day');
        $this->assertSame(5, array_sum(array_column($report['stock'], 'opening')));
        $this->assertSame(4, array_sum(array_column($report['stock'], 'closing')));
        $this->assertSame(-1, array_sum(array_column($report['stock'], 'loss')));
        $this->assertSame(0, array_sum(array_column($report['stock'], 'as_in')));
        $this->assertTrue($report['stock'][$units[0]->option_id]['complete']);
        $repo->query(E::Movement, $tenant->id)->where('unit_id', $units[0]->id)->where('type', 'receive')->delete();
        $report = app(LiveLedgerService::class)->report($tenant->id, 0, '2026-10-02', '2026-10-02', 'day');
        $this->assertFalse($report['stock'][$units[0]->option_id]['complete']);
    }

    public function test_password_reset_preserves_profile_role_and_hides_password_in_audit(): void
    {
        [$tenant,$user] = $this->loginRole();
        $before = $user->fresh();
        $result = app(LiveAccountService::class)->resetPassword($tenant->id, 0, $user->id);
        $after = $user->fresh();
        $this->assertSame($before->name, $after->name);
        $this->assertSame($before->username, $after->username);
        $this->assertSame($before->session_version + 1, $after->session_version);
        $this->assertTrue(Hash::check($result['temporary_password'], $after->password));
        $this->assertStringNotContainsString($result['temporary_password'], app(LiveRepositoryInterface::class)->query(E::Audit, $tenant->id)->get()->toJson());
    }
}
