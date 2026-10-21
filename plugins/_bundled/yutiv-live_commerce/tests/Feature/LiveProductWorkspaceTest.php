<?php

namespace Plugins\Yutiv\LiveCommerce\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Support\Str;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;
use Plugins\Yutiv\LiveCommerce\Models\TeeWideUser;
use Plugins\Yutiv\LiveCommerce\Services\LiveAccountService;
use Plugins\Yutiv\LiveCommerce\Services\LiveCatalogService;
use Plugins\Yutiv\LiveCommerce\Services\LiveInventoryService;
use Plugins\Yutiv\LiveCommerce\Services\LiveLedgerService;
use Plugins\Yutiv\LiveCommerce\Services\LiveProductWorkspace;
use Plugins\Yutiv\LiveCommerce\Services\LiveReceiptService;

class LiveProductWorkspaceTest extends TeeWideMainDesignTest
{
    protected function operator(string $role = 'owner'): array
    {
        $tenant = $this->seedGolfifTenant();
        $accounts = app(LiveAccountService::class);
        $issued = $accounts->issue($tenant->id, 0, ['name' => '격리 작업자', 'username' => 'workspace-'.$role, 'role' => $role]);
        $user = TeeWideUser::findOrFail($issued['user_id']);
        $accounts->changePassword($user, 'Synthetic-only-1234!');
        $login = $this->post('http://'.self::ROOT_HOST.'/login', ['email' => $issued['username'], 'password' => 'Synthetic-only-1234!']);
        $login->assertRedirect();
        $this->withUnencryptedCookie('teewide_session', $login->getCookie('teewide_session', false)->getValue());

        return [$tenant, $user];
    }

    public function test_products_open_the_inventory_independent_list_without_internal_submenus(): void
    {
        [$tenant] = $this->operator();
        $catalog = app(LiveCatalogService::class);
        foreach (range(1, 25) as $i) {
            $catalog->save(E::Product, $tenant->id, 0, ['name' => '기존 상품 '.$i, 'code' => 'WORK-'.$i]);
        }
        $response = $this->get('http://'.self::ROOT_HOST.'/seller/products');
        $response->assertOk()->assertSee('data-product-page-select', false)->assertDontSee('aria-label="상품 업무"', false)->assertSee('신규상품입고')->assertSee('QR출력');
        $this->assertCount(20, $response->viewData('rows'));
        $this->assertSame(25, $response->viewData('rows')->total());
        $this->get('http://'.self::ROOT_HOST.'/seller/products?page=2')->assertOk()->assertSee('기존 상품 1');
    }

    public function test_legacy_print_targets_and_label_count_limits_preserve_existing_uuid(): void
    {
        [$tenant,$actor] = $this->operator();
        $service = app(LiveProductWorkspace::class);
        app(LiveReceiptService::class)->receive($tenant->id, $actor->id, $this->receipt());
        $qr = $service->query(E::Unit, $tenant->id)->first()->uuid;
        $audit = $service->query(E::Audit, $tenant->id)->create(['tenant_id' => $tenant->id, 'actor_id' => $actor->id, 'actor_type' => 'staff', 'action' => 'labels.print_requested', 'data' => ['units' => [$qr], 'width_mm' => 50, 'height_mm' => 30]]);
        $this->get('http://'.self::ROOT_HOST.'/seller/product-qr?legacy_audit_id='.$audit->id)->assertOk()->assertSee($qr);
        $input = ['operation_key' => 'legacy-reprint-050', 'legacy_audit_id' => $audit->id, 'qrs' => [$qr], 'width' => 50, 'height' => 30, 'copies' => 2];
        $this->withCredentials()->postJson('http://'.self::ROOT_HOST.'/seller/product-actions/print', $input)->assertOk()->assertJsonPath('data.total_labels', 2);
        $entry = $service->query(E::LabelRequest, $tenant->id)->first();
        $this->assertSame('reprint', $entry->items[0]['kind']);
        $this->assertSame($audit->id, $entry->items[0]['legacy_audit_id']);
        $this->assertSame($qr, $entry->items[0]['uuid']);
        $input['operation_key'] = 'too-many-labels';
        $input['copies'] = 10;
        $input['qrs'] = array_map(fn () => (string) Str::uuid(), range(1, 21));
        unset($input['legacy_audit_id']);
        $this->postJson('http://'.self::ROOT_HOST.'/seller/product-actions/print', $input)->assertUnprocessable()->assertJsonValidationErrors('copies');
        $this->assertSame(1, $service->query(E::LabelRequest, $tenant->id)->count());
    }

    public function test_categories_are_tenant_scoped_and_existing_assignments_survive_archiving(): void
    {
        [$tenant] = $this->operator();
        $url = 'http://'.self::ROOT_HOST.'/seller/product-actions/category';
        $service = app(LiveProductWorkspace::class);
        $this->withCredentials()->postJson($url, ['name' => '상의', 'active' => true])->assertOk();
        $category = $service->query(E::Category, $tenant->id)->first();
        $data = $this->receipt();
        $data['category_id'] = $category->id;
        $receipt = $this->postJson('http://'.self::ROOT_HOST.'/seller/receipt', $data)->assertOk()->json('data');
        $this->postJson($url, ['id' => $category->id, 'name' => '상의', 'active' => false])->assertOk();
        $this->assertSame($category->id, (int) $service->query(E::Product, $tenant->id)->find($receipt['product_id'])->category_id);
        $r = $this->get('http://'.self::ROOT_HOST.'/seller/products?category_id='.$category->id);
        $r->assertOk();
        $this->assertSame(1, $r->viewData('rows')->total());
        $other = LiveTenant::create(['name' => '별도 분류 업체', 'slug' => 'foreign-category', 'status' => 'active']);
        $foreign = $service->query(E::Category, $other->id)->create(['tenant_id' => $other->id, 'name' => '외부', 'active' => true]);
        $this->postJson($url, ['id' => $foreign->id, 'name' => '침범', 'active' => true])->assertNotFound();
        $data['operation_key'] = 'foreign-category';
        $data['code'] = 'foreign-category';
        $data['category_id'] = $foreign->id;
        $this->postJson('http://'.self::ROOT_HOST.'/seller/receipt', $data)->assertNotFound();
    }

    protected function receipt(string $key = 'workspace-one'): array
    {
        return ['operation_key' => $key, 'mode' => 'new', 'name' => '입고 원가 검증', 'code' => $key, 'published' => false, 'sellable' => true, 'location' => '격리 창고', 'items' => [['name' => '기본', 'price' => 15000, 'discount' => 1000, 'quantity' => 2, 'unit_cost' => 7000]]];
    }

    public function test_filters_distinguish_available_stock_from_state_and_use_tenant_day_boundaries(): void
    {
        [$tenant,$actor] = $this->operator();
        $tenant->update(['timezone' => 'Asia/Seoul']);
        $workspace = app(LiveProductWorkspace::class);
        $this->travelTo(Carbon::parse('2026-10-20 14:59:59', 'UTC'));
        $a = app(LiveReceiptService::class)->receive($tenant->id, $actor->id, $this->receipt('before-midnight'));
        $this->travelTo(now()->addSecond());
        $b = app(LiveReceiptService::class)->receive($tenant->id, $actor->id, $this->receipt('at-midnight'));
        $units = $workspace->query(E::Unit, $tenant->id)->where('receipt_key', 'at-midnight')->get();
        app(LiveInventoryService::class)->transition($tenant->id, $actor->id, $units[0]->uuid, 'lost', '격리 창고', '합성 망실', 'filter-lost');
        $base = 'http://'.self::ROOT_HOST.'/seller/products';
        $r = $this->get($base.'?stock=yes&unit_state=lost');
        $r->assertOk();
        $this->assertSame([$b['product_id']], $r->viewData('rows')->pluck('id')->all());
        $r = $this->get($base.'?date_basis=received&from=2026-10-21&to=2026-10-21');
        $r->assertOk();
        $this->assertSame([$b['product_id']], $r->viewData('rows')->pluck('id')->all());
        app(LiveInventoryService::class)->transition($tenant->id, $actor->id, $units[1]->uuid, 'defective', '격리 창고', '합성 불량', 'filter-defective');
        $r = $this->get($base.'?stock=no');
        $r->assertOk();
        $this->assertSame([$b['product_id']], $r->viewData('rows')->pluck('id')->all());
        $this->get($base.'?unit_state=missing')->assertRedirect();
        $this->get($base.'?to=2026-10-21')->assertOk();
    }

    public function test_axes_limits_duplicates_and_invalid_attributes_fail_before_writes(): void
    {
        [$tenant] = $this->operator();
        $url = 'http://'.self::ROOT_HOST.'/seller/receipt';
        $data = $this->receipt();
        $data['axes'] = [['name' => '색상', 'values' => ['검정', '흰색']]];
        $data['items'][0]['attributes'] = ['색상' => '빨강'];
        $this->withCredentials()->postJson($url, $data)->assertUnprocessable();
        $data['axes'][0]['values'] = array_map('strval', range(1, 21));
        $this->postJson($url, $data)->assertUnprocessable();
        $data['axes'] = array_map(fn ($n) => ['name' => '종류'.$n, 'values' => ['값']], range(1, 5));
        $this->postJson($url, $data)->assertUnprocessable();
        $data['axes'] = [['name' => '색상', 'values' => ['검정', '검정']]];
        $this->postJson($url, $data)->assertUnprocessable();
        $this->assertSame(0, app(LiveProductWorkspace::class)->query(E::Receipt, $tenant->id)->count());
    }

    public function test_warehouse_receives_selected_options_without_financial_history_or_catalog_privileges(): void
    {
        [$tenant,$actor] = $this->operator('warehouse');
        $workspace = app(LiveProductWorkspace::class);
        $receipt = app(LiveReceiptService::class)->receive($tenant->id, 0, $this->receipt());
        $option = $workspace->query(E::Option, $tenant->id)->first();
        $this->get('http://'.self::ROOT_HOST.'/seller/products/'.$receipt['product_id'])->assertOk()->assertDontSee('원래 입고단가')->assertDontSee('7,000원');
        $this->get('http://'.self::ROOT_HOST.'/seller/product-receive?product_id='.$receipt['product_id'])->assertOk()->assertDontSee('data-combinations', false);
        $data = ['operation_key' => 'warehouse-cost', 'mode' => 'existing', 'product_id' => $receipt['product_id'], 'location' => '격리 선반', 'items' => [['option_id' => $option->id, 'name' => '기본', 'price' => 1, 'discount' => 0, 'unit_cost' => 9000, 'quantity' => 1]]];
        $this->withCredentials()->postJson('http://'.self::ROOT_HOST.'/seller/receipt', $data)->assertOk();
        $this->assertSame(15000, (int) $option->fresh()->price);
        unset($data['items'][0]['option_id']);
        $data['operation_key'] = 'warehouse-new-option';
        $this->postJson('http://'.self::ROOT_HOST.'/seller/receipt', $data)->assertForbidden();
        $this->postJson('http://'.self::ROOT_HOST.'/seller/product-actions/category', ['name' => '금지', 'active' => true])->assertForbidden();
        $this->get('http://'.self::ROOT_HOST.'/seller/ledger')->assertForbidden();
    }

    public function test_receipt_cost_validation_traceability_and_original_sale_price(): void
    {
        [$tenant] = $this->operator();
        $url = 'http://'.self::ROOT_HOST.'/seller/receipt';
        $service = app(LiveProductWorkspace::class);
        foreach ([null, -1, 1.5, 'bad', 1000000001] as $cost) {
            $bad = $this->receipt();
            $bad['items'][0]['unit_cost'] = $cost;
            $this->withCredentials()->postJson($url, $bad)->assertUnprocessable();
        }
        $this->assertSame(0, $service->query(E::Receipt, $tenant->id)->count());
        $data = $this->receipt();
        $first = $this->postJson($url, $data)->assertOk()->json('data');
        $this->postJson($url, $data)->assertOk()->assertJsonPath('data.receipt_id', $first['receipt_id']);
        $option = $service->query(E::Option, $tenant->id)->firstOrFail();
        $data = ['operation_key' => 'workspace-zero', 'mode' => 'existing', 'product_id' => $first['product_id'], 'location' => '다른 창고', 'items' => [['option_id' => $option->id, 'name' => '변경 안 됨', 'price' => 1, 'discount' => 0, 'quantity' => 1, 'unit_cost' => 0]]];
        $this->postJson($url, $data)->assertOk();
        $this->assertSame(15000, (int) $option->fresh()->price);
        $costs = $service->query(E::ReceiptItem, $tenant->id)->orderBy('id')->get();
        $this->assertSame([14000, 0], $costs->map(fn ($c) => (int) $c->purchase_amount)->all());
        $this->assertSame(3, $service->query(E::Unit, $tenant->id)->whereIn('receipt_item_id', $costs->pluck('id'))->count());
        $this->get('http://'.self::ROOT_HOST.'/seller/products/'.$first['product_id'].'?list=search%3Dabc%26page%3D2')->assertOk()->assertSee('search=abc&amp;page=2', false)->assertSee('7,000원');
        $this->get('http://'.self::ROOT_HOST.'/shop')->assertOk()->assertDontSee('unit_cost')->assertDontSee('receipt_item_id');
    }

    public function test_qr_preview_does_not_record_and_requests_are_idempotent_scoped_and_cost_free(): void
    {
        [$tenant,$actor] = $this->operator();
        app(LiveReceiptService::class)->receive($tenant->id, $actor->id, $this->receipt());
        $service = app(LiveProductWorkspace::class);
        $qrs = $service->query(E::Unit, $tenant->id)->pluck('uuid')->all();
        $data = ['qrs' => $qrs, 'width' => 50, 'height' => 30, 'copies' => 2];
        $url = 'http://'.self::ROOT_HOST.'/seller/product-actions/';
        $this->withCredentials()->post($url.'preview', $data)->assertOk()->assertSee('data-workspace-print', false)->assertDontSee('unit_cost')->assertDontSee('7000');
        $this->assertSame(0, $service->query(E::LabelRequest, $tenant->id)->count());
        $data['operation_key'] = 'qr-once';
        $first = $this->postJson($url.'print', $data)->assertOk()->assertJsonPath('data.total_labels', 4)->json('data');
        $this->postJson($url.'print', $data)->assertOk()->assertJsonPath('data.request_id', $first['request_id']);
        $data['copies'] = 3;
        $this->postJson($url.'print', $data)->assertConflict();
        $data['operation_key'] = 'qr-explicit';
        $data['original_id'] = $first['request_id'];
        $this->postJson($url.'print', $data)->assertOk();
        $entry = $service->query(E::LabelRequest, $tenant->id)->orderByDesc('id')->first();
        $this->assertSame('reprint', $entry->items[0]['kind']);
        $this->assertArrayNotHasKey('unit_cost', $entry->items[0]);
        $foreign = LiveTenant::create(['name' => '격리 타 업체', 'slug' => 'workspace-foreign', 'status' => 'active']);
        app(LiveReceiptService::class)->receive($foreign->id, 0, $this->receipt('foreign-one'));
        $data['qrs'] = [$service->query(E::Unit, $foreign->id)->first()->uuid];
        $data['operation_key'] = 'qr-foreign';
        unset($data['original_id']);
        $this->postJson($url.'print', $data)->assertNotFound();
        $this->get('http://'.self::ROOT_HOST.'/seller/product-qr?batch='.$service->query(E::Receipt, $foreign->id)->first()->id)->assertNotFound();
        $this->assertSame(2, $service->query(E::LabelRequest, $tenant->id)->count());
    }

    public function test_purchase_ledger_unknowns_and_saved_report_do_not_follow_later_changes(): void
    {
        [$tenant,$actor] = $this->operator();
        $service = app(LiveProductWorkspace::class);
        $receipt = app(LiveReceiptService::class)->receive($tenant->id, $actor->id, $this->receipt());
        $option = $service->query(E::Option, $tenant->id)->first();
        app(LiveInventoryService::class)->receive($tenant->id, $actor->id, $option->id, 1, '과거 원가 없음', 'legacy-unknown');
        $ledger = app(LiveLedgerService::class);
        $date = now($tenant->timezone)->format('Y-m-d');
        $result = $ledger->report($tenant->id, $actor->id, $date, $date, 'day');
        $this->assertSame(14000, array_sum(array_column($result['periods'], 'purchase_amount')));
        $this->assertSame(1, array_sum(array_column($result['periods'], 'purchase_unknown_quantity')));
        $uuid = $ledger->save($tenant->id, $actor->id, $date, $date, 'day');
        $report = $service->query(E::Report, $tenant->id)->where('uuid', $uuid)->firstOrFail();
        $snapshot = $report->result;
        $rows = $service->query(E::ReportRow, $tenant->id)->where('report_id', $report->id)->get()->toArray();
        $service->query(E::Product, $tenant->id)->whereKey($receipt['product_id'])->update(['name' => '나중에 변경한 상품']);
        app(LiveReceiptService::class)->receive($tenant->id, $actor->id, $this->receipt('later-one'));
        $this->assertSame($snapshot, $report->fresh()->result);
        $this->assertSame($rows, $service->query(E::ReportRow, $tenant->id)->where('report_id', $report->id)->get()->toArray());
        $this->get('http://'.self::ROOT_HOST.'/seller/reports/'.$uuid)->assertOk()->assertSee('기간 입고 매입금액')->assertSee('14,000');
        $next = $ledger->save($tenant->id, $actor->id, $date, $date, 'day');
        $this->assertNotSame($uuid,$next);
    }
}
