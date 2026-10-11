<?php

namespace Modules\Yutiv\PersonalStore\Tests\Feature;

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Yutiv\PersonalStore\Models\Store;
use Modules\Yutiv\PersonalStore\Repositories\StoreRepository;
use Modules\Yutiv\PersonalStore\Services\AttributionProof;
use Modules\Yutiv\PersonalStore\Tests\ModuleTestCase;

class StoreBoundaryTest extends ModuleTestCase
{
    private function open(string $slug): Store
    {
        $owner = User::factory()->create();
        app(StoreRepository::class)->manage($owner->id, 'apply', ['slug' => $slug]);
        $store = Store::where('user_id', $owner->id)->firstOrFail();
        $store->update(['status' => 'active']);

        return $store;
    }

    public function test_cycle_is_rejected_and_closure_repair_preserves_adjacency(): void
    {
        $a = $this->open('parent');
        $b = $this->open('child');
        $repo = app(StoreRepository::class);
        $repo->move($b->id, $a->id, $a->user_id);
        try {
            $repo->move($a->id, $b->id, $a->user_id);
            $this->fail('A cycle must fail.');
        } catch (\DomainException) {
            $this->assertNull($a->fresh()->parent_id);
        }
        DB::table('personal_store_closure')->where('ancestor_id', $a->id)->where('descendant_id', $b->id)->delete();
        $this->assertSame(1, $repo->rebuildClosure()['missing_or_wrong_depth']);
        $repo->rebuildClosure(true);
        $this->assertSame(0, $repo->rebuildClosure()['missing_or_wrong_depth']);
        $this->assertSame($a->id, $b->fresh()->parent_id);
    }

    public function test_category_descendants_individual_include_and_exclusion_do_not_duplicate_products(): void
    {
        $store = $this->open('products');
        $product = ProductFactory::new()->create(['display_status' => 'visible', 'sales_status' => 'on_sale']);
        $parent = Category::create(['name' => ['ko' => '상위'], 'path' => '/', 'is_active' => true, 'sort_order' => 0]);
        $parent->generatePath();
        $parent->save();
        $child = Category::create(['name' => ['ko' => '하위'], 'path' => '/'.$parent->id.'/', 'parent_id' => $parent->id, 'is_active' => true, 'sort_order' => 0]);
        $child->generatePath();
        $child->save();
        $product->categories()->attach([$parent->id => ['is_primary' => true], $child->id => ['is_primary' => false]]);
        $repo = app(StoreRepository::class);
        $repo->manage($store->user_id, 'categories', ['category_ids' => [$parent->id, $child->id]]);
        $this->assertTrue($repo->allowed($store->id, $product->id));
        $repo->manage($store->user_id, 'products', ['product_id' => $product->id, 'excluded' => false]);
        $this->assertCount(1, $repo->products($store->slug, [])['data']);
        $repo->manage($store->user_id, 'products', ['product_id' => $product->id, 'excluded' => true]);
        $this->assertFalse($repo->allowed($store->id, $product->id));
        $this->assertCount(0, $repo->products($store->slug, [])['data']);
        $product->update(['sales_status' => 'suspended']);
        $this->assertFalse($repo->allowed($store->id, $product->id));
    }

    public function test_failed_snapshot_rolls_back_snapshot_beneficiaries_state_and_outbox(): void
    {
        $store = $this->open('rollback');
        $order = OrderFactory::new()->create();
        try {
            DB::transaction(function () use ($store, $order) {
                app(StoreRepository::class)->snapshot(['line_uuid' => (string) Str::uuid(), 'order_id' => $order->id, 'store_id' => $store->id, 'created_at' => now(), 'payload' => ['quantity' => 1, 'reward' => ['allocations' => [['store_id' => $store->id, 'amount' => 7]]]]]);
                throw new \RuntimeException('Injected transaction failure.');
            });
        } catch (\RuntimeException) {
            foreach (['line_snapshots', 'beneficiaries', 'line_states', 'outbox'] as $table) {
                $this->assertSame(0, DB::table('personal_store_'.$table)->count());
            }
        }
    }

    public function test_proofs_reject_wrong_purpose_expiry_and_tampering(): void
    {
        $proof = new AttributionProof;
        foreach ([$proof->issue('reference', time() + 60, 'sale'), $proof->issue('reference', time() - 60, 'membership'), 'forged'] as $token) {
            try {
                $proof->verify($token, 'membership');
                $this->fail('Invalid proof must fail.');
            } catch (\DomainException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('reference', $proof->verify($proof->issue('reference', time() + 60, 'membership'), 'membership'));
    }

    public function test_member_parent_is_not_replaced_by_later_visits_and_owner_cannot_change_other_child_rate(): void
    {
        $parent = $this->open('membership');
        $other = $this->open('other');
        $member = User::factory()->create();
        $repo = app(StoreRepository::class);
        DB::transaction(fn () => $repo->registerMembership($member->id, $repo->publicOrigin($parent->slug)));
        $repo->publicOrigin($other->slug);
        $repo->manage($member->id, 'apply', ['slug' => 'member-child']);
        $child = Store::where('user_id', $member->id)->firstOrFail();
        $this->assertSame($parent->id, $child->parent_id);
        $this->expectException(ModelNotFoundException::class);
        $repo->manage($other->user_id, 'allocation', ['child_id' => $child->id, 'rate_bps' => 10000]);
    }
}
