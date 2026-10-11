<?php

namespace Modules\Yutiv\PersonalStore\Tests\Feature;

use App\Services\LayoutService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Models\Cart;
use Modules\Sirsoft\Ecommerce\Services\TempOrderService;
use Modules\Yutiv\PersonalStore\Exceptions\StorePolicyException;
use Modules\Yutiv\PersonalStore\Tests\ModuleTestCase;

class LayoutAndEligibilityTest extends ModuleTestCase
{
    public function test_detail_inherits_existing_purchase_flow_without_duplicate_source_ids(): void
    {
        $parent = json_decode(file_get_contents(base_path('templates/_bundled/yutiv-stillform/layouts/shop/show.json')), true, flags: JSON_THROW_ON_ERROR);
        // mergeLayouts receives resolved modal IDs; raw partial references have no ID.
        $parent['modals'] = array_map(fn ($modal) => json_decode(file_get_contents(base_path('templates/_bundled/yutiv-stillform/layouts/'.$modal['partial'])), true, flags: JSON_THROW_ON_ERROR), $parent['modals']);
        $child = json_decode(file_get_contents(base_path('modules/_bundled/yutiv-personal_store/resources/layouts/user/user_detail.json')), true, flags: JSON_THROW_ON_ERROR);
        $service = app(LayoutService::class);
        $result = $service->mergeLayouts($parent, $child);
        $sources = array_column($result['data_sources'], null, 'id');
        $this->assertCount(count($result['data_sources']), $sources);
        $this->assertSame('/api/modules/yutiv-personal_store/stores/{{route.slug}}/products/{{route.product_code}}', $sources['product']['endpoint']);
        $this->assertSame($parent['computed'], $result['computed']);
        $this->assertSame($parent['modals'], $result['modals']);
        $normal = $service->mergeLayouts($parent, ['extends' => 'shop/show']);
        $normalSources = array_column($normal['data_sources'], null, 'id');
        $this->assertSame($parent['data_sources'][0]['endpoint'], $normalSources['product']['endpoint']);
    }

    public function test_stale_attribution_is_rejected_before_temp_order_calculation_or_write(): void
    {
        $cart = new Cart(['product_id' => 1, 'product_option_id' => 1, 'quantity' => 1, 'attribution_key' => 'absent-reference']);
        try {
            app(TempOrderService::class)->createTempOrder(new Collection([$cart]), null, 'synthetic-cart');
            $this->fail('Stale origin was accepted');
        } catch (StorePolicyException) {
            $this->assertSame(0, DB::table('ecommerce_temp_orders')->count());
        }
    }
}
