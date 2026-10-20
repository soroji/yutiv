<?php

namespace Plugins\Yutiv\LiveCommerce\Tests\Feature;

use Illuminate\Support\Str;
use Plugins\Yutiv\LiveCommerce\Contracts\LiveRepositoryInterface;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Services\LiveCatalogService;
use Plugins\Yutiv\LiveCommerce\Services\LiveInventoryService;
use Plugins\Yutiv\LiveCommerce\Services\LiveLedgerService;
use Plugins\Yutiv\LiveCommerce\Tests\Support\LedgerSynthetic;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LiveLedgerScaleTest extends TeeWideMainDesignTest
{
    public function test_stock_validation_preserves_strict_state_and_location_comparisons(): void
    {
        $tenant = $this->seedGolfifTenant();
        $catalog = app(LiveCatalogService::class);
        $product = $catalog->save(E::Product, $tenant->id, 0, ['name' => '엄격 비교', 'code' => 'BINARY']);
        $option = $catalog->save(E::Option, $tenant->id, 0, ['product_id' => $product->id, 'name' => '기본', 'price' => 0]);
        app(LiveInventoryService::class)->receive($tenant->id, 0, $option->id, 1, 'Case-Shelf', 'binary');
        $unit = app(LiveRepositoryInterface::class)->query(E::Unit, $tenant->id)->firstOrFail();
        foreach ([['location' => 'case-shelf', 'state' => 'available'], ['location' => 'Case-Shelf', 'state' => 'Available']] as $change) {
            $unit->update($change);
            $report = app(LiveLedgerService::class)->report($tenant->id, 0, '2026-10-01', '2026-10-31', 'month');
            $this->assertFalse($report['stock'][$option->id]['complete']);
            $this->assertFalse($report['stock_totals']['complete']);
        }
    }

    public function test_mixed_product_order_cash_is_not_allocated_and_prior_deposit_is_outside_period(): void
    {
        $tenant = $this->seedGolfifTenant();
        $repo = app(LiveRepositoryInterface::class);
        $catalog = app(LiveCatalogService::class);
        $products = [];
        $options = [];
        foreach (['A', 'B'] as $code) {
            $products[] = $product = $catalog->save(E::Product, $tenant->id, 0, ['name' => $code, 'code' => $code]);
            $options[] = $catalog->save(E::Option, $tenant->id, 0, ['product_id' => $product->id, 'name' => '기본', 'price' => 100]);
        }
        $order = $repo->create(E::Order, $tenant->id, ['uuid' => (string) Str::uuid(), 'user_id' => 0, 'operation_key' => 'mixed', 'request_hash' => str_repeat('0', 64), 'subtotal' => 300, 'total' => 300, 'received' => 300, 'payment_state' => 'paid', 'delivery_state' => 'pending', 'bank_snapshot' => [], 'delivery' => [], 'expires_at' => '2026-11-01', 'created_at' => '2026-10-01 01:00:00']);
        foreach ($options as $i => $option) {
            $line = $repo->create(E::OrderLine, $tenant->id, ['uuid' => (string) Str::uuid(), 'order_id' => $order->id, 'option_id' => $option->id, 'quantity' => 1, 'unit_price' => ($i + 1) * 100, 'cancelled' => $i, 'snapshot' => ['product' => $products[$i]->name]]);
        }
        foreach ([['deposit', 100, '2026-09-30'], ['deposit', 300, '2026-10-02'], ['cancel', 200, '2026-10-03'], ['refund', 200, '2026-10-04']] as $i => [$type,$amount,$date]) {
            $repo->create(E::Event, $tenant->id, ['order_id' => $order->id, 'operation_key' => 'mixed-'.$i, 'type' => $type, 'amount' => $amount, 'data' => $type === 'cancel' ? ['line_id' => $line->id, 'quantity' => 1] : [], 'actor_id' => 0, 'created_at' => $date.' 01:00:00']);
        }
        $service = app(LiveLedgerService::class);
        $a = $service->report($tenant->id, 0, '2026-10-01', '2026-10-31', 'month', $products[0]->id);
        $all = $service->report($tenant->id, 0, '2026-10-01', '2026-10-31', 'month');
        $this->assertSame(100, $a['periods']['2026-10']['ordered']);
        $this->assertSame(0, $a['periods']['2026-10']['cancel']);
        $this->assertSame(200, $a['periods']['2026-10']['deposit']);
        $this->assertSame(200, $a['periods']['2026-10']['refund']);
        $this->assertSame(300, $all['periods']['2026-10']['ordered']);
        $this->assertSame(200, $all['periods']['2026-10']['cancel']);
        $this->assertSame(100, $a['sales_totals']['cohort_net_sales']);
        $uuid = $service->save($tenant->id, 0, '2026-10-01', '2026-10-31', 'month', $products[0]->id);
        $saved = $service->saved($tenant->id, 0, $uuid);
        $events = $saved['rows']->where('kind', 'event');
        $cancel = $events->first(fn ($r) => $r->data['type'] === 'cancel');
        $deposit = $events->first(fn ($r) => $r->data['type'] === 'deposit');
        $this->assertFalse($cancel->data['included_in_period_totals']);
        $this->assertSame(100, $deposit->data['previous_confirmation']['amount']);
        $this->assertSame(200, $deposit->data['deposit_delta']);
        $row = $saved['rows']->first();
        $repo->query(E::ReportRow, $tenant->id)->whereKey($row->id)->update(['data' => json_encode(['tampered' => true])]);
        try {
            $service->saved($tenant->id, 0, $uuid);
            $this->fail('손상된 보고서 허용');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_all_caps_pagination_period_events_and_immutable_report(): void
    {
        $tenant = $this->seedGolfifTenant();
        $fixture = LedgerSynthetic::seed($tenant->id);
        $service = app(LiveLedgerService::class);
        $repo = app(LiveRepositoryInterface::class);
        $metrics = [];
        foreach (['day' => '2026-10-01', 'week' => '2026-10-07', 'month' => '2026-10-31'] as $period => $to) {
            $db = $repo->query(E::Order, $tenant->id)->getModel()->getConnection();
            $db->flushQueryLog();
            $db->enableQueryLog();
            memory_reset_peak_usage();
            $baseline = memory_get_usage(true);
            $begin = hrtime(true);
            $report = $service->report($tenant->id, 0, '2026-10-01', $to, $period, $fixture['product']);
            $metrics[$period] = ['from' => '2026-10-01', 'to' => $to, 'milliseconds' => round((hrtime(true) - $begin) / 1e6, 2), 'peak_bytes' => memory_get_peak_usage(true), 'peak_increment_bytes' => memory_get_peak_usage(true) - $baseline, 'queries' => count($db->getQueryLog())];
            $db->disableQueryLog();
            $this->assertCount(20, $report['stock']);
            $this->assertSame(520, $report['total']);
            $this->assertSame(4200, $report['stock_totals']['opening']);
            $this->assertSame(4200, $report['stock_totals']['closing']);
            $this->assertTrue($report['stock_totals']['complete']);
            foreach (['ordered' => 400, 'deposit' => $period === 'day' ? 0 : 150, 'cancel' => $period === 'day' ? 0 : 100, 'return' => $period === 'day' ? 0 : 100, 'refund' => $period === 'month' ? 200 : 0] as $field => $amount) {
                $this->assertSame($amount, array_sum(array_column($report['periods'], $field)));
            }
            $this->assertSame(200, $report['sales_totals']['cohort_net_sales']);
            $this->assertSame(50, $report['outstanding']);
        }
        $sums = ['opening' => 0, 'closing' => 0, 'ordered' => 0];
        for ($page = 1; $page <= 26; $page++) {
            $r = $service->report($tenant->id, 0, '2026-10-01', '2026-10-31', 'month', $fixture['product'], $page);
            foreach (['opening', 'closing'] as $f) {
                $sums[$f] += array_sum(array_column($r['stock'], $f));
            }
            $sums['ordered'] += array_sum(array_column($r['sales'], 'ordered'));
        }
        $this->assertSame(['opening' => 4200, 'closing' => 4200, 'ordered' => 400], $sums);
        $begin = hrtime(true);
        memory_reset_peak_usage();
        $db->flushQueryLog();
        $db->enableQueryLog();
        $uuid = $service->save($tenant->id, 0, '2026-10-01', '2026-10-31', 'month', $fixture['product']);
        $metrics['save'] = ['milliseconds' => round((hrtime(true) - $begin) / 1e6, 2), 'peak_bytes' => memory_get_peak_usage(true), 'queries' => count($db->getQueryLog())];
        $db->disableQueryLog();
        $saved = $service->saved($tenant->id, 0, $uuid);
        $before = $saved['report']->result;
        $this->assertSame(532, (int) $saved['report']->row_count);
        $this->assertSame(6, $saved['parts']);
        $savedSums = ['opening' => 0, 'closing' => 0, 'ordered' => 0];
        $seen = [];
        for ($part = 1; $part <= $saved['parts']; $part++) {
            foreach ($service->saved($tenant->id, 0, $uuid, $part)['rows'] as $row) {
                $seen[] = $row->sequence;
                if ($row->kind === 'option') {
                    foreach (['opening', 'closing'] as $f) {
                        $savedSums[$f] += $row->data['stock'][$f];
                    } $savedSums['ordered'] += $row->data['sales']['ordered'];
                }
            }
        }
        $this->assertSame(range(1, 532), $seen);
        $this->assertSame($sums, $savedSums);
        $repo->query(E::Product, $tenant->id)->whereKey($fixture['product'])->update(['name' => '변경된 상품']);
        $repo->query(E::OrderLine, $tenant->id)->whereKey($fixture['line'])->update(['returned' => 1]);
        $this->assertSame($before, $service->saved($tenant->id, 0, $uuid)['report']->result);
        $this->assertSame($saved['rows']->toArray(), $service->saved($tenant->id, 0, $uuid)['rows']->toArray());
        $next = $service->save($tenant->id, 0, '2026-10-01', '2026-10-31', 'month', $fixture['product']);
        $this->assertNotSame($uuid, $next);
        $this->assertSame(100, $service->saved($tenant->id, 0, $next)['report']->result['sales_totals']['cohort_net_sales']);
        if (getenv('LIVE_LEDGER_METRICS')) {
            file_put_contents(getenv('LIVE_LEDGER_METRICS'), json_encode(['sizes' => $fixture['sizes'], 'metrics' => $metrics], JSON_PRETTY_PRINT));
        }
    }

    public function test_old_global_unit_limit_does_not_block_a_day_week_or_month(): void
    {
        $tenant = $this->seedGolfifTenant();
        $repo = app(LiveRepositoryInterface::class);
        $catalog = app(LiveCatalogService::class);
        $product = $catalog->save(E::Product, $tenant->id, 0, ['name' => '누적 합성 상품', 'code' => 'SCALE']);
        $option = $catalog->save(E::Option, $tenant->id, 0, ['name' => '기본', 'product_id' => $product->id, 'price' => 100]);
        $rows = [];
        for ($i = 0; $i < 2101; $i++) {
            $rows[] = ['tenant_id' => $tenant->id, 'uuid' => (string) Str::uuid(), 'option_id' => $option->id, 'state' => 'available', 'location' => '창고', 'receipt_key' => 'synthetic', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'];
        }
        foreach (array_chunk($rows, 250) as $chunk) {
            $repo->query(E::Unit, $tenant->id)->insert($chunk);
        }
        $repo->query(E::Unit, $tenant->id)->chunkById(250, function ($units) use ($repo, $tenant) {
            $rows = [];
            foreach ($units as $unit) {
                $rows[] = ['tenant_id' => $tenant->id, 'unit_id' => $unit->id, 'operation_key' => 'synthetic-'.$unit->id, 'type' => 'receive', 'from_state' => null, 'to_state' => 'available', 'from_location' => null, 'to_location' => '창고', 'actor_id' => 0, 'reason' => '합성', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'];
            }
            $repo->query(E::Movement, $tenant->id)->insert($rows);
        });
        foreach (['day', 'week', 'month'] as $period) {
            $report = app(LiveLedgerService::class)->report($tenant->id, 0, '2026-10-01', '2026-10-01', $period);
            $this->assertSame(2101, $report['stock'][$option->id]['opening']);
            $this->assertSame(2101, $report['stock'][$option->id]['closing']);
            $this->assertTrue($report['stock'][$option->id]['complete']);
        }
    }
}
