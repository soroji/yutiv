<?php

namespace Modules\Yutiv\PersonalStore\Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Yutiv\PersonalStore\Exceptions\StorePolicyException;
use Modules\Yutiv\PersonalStore\Models\Store;
use Modules\Yutiv\PersonalStore\Repositories\Contracts\StoreRepositoryInterface;
use Modules\Yutiv\PersonalStore\Repositories\StoreRepository;
use Modules\Yutiv\PersonalStore\Services\AttributionProof;
use Modules\Yutiv\PersonalStore\Tests\ModuleTestCase;

class HttpBoundaryTest extends ModuleTestCase
{
    public function test_membership_insert_failure_rolls_back_user_insert_on_the_same_connection(): void
    {
        $repo = \Mockery::mock(StoreRepositoryInterface::class);
        $repo->shouldReceive('validateMembershipReference')->andReturn(1);
        $repo->shouldReceive('registerMembership')->once()->andReturnUsing(function () {
            $this->assertGreaterThan(0, DB::transactionLevel());
            $this->assertSame(DB::connection(), (new User)->getConnection());
            $this->assertTrue(User::where('email', 'rollback@example.invalid')->exists());
            throw new StorePolicyException('Injected membership failure');
        });
        $this->app->instance(StoreRepositoryInterface::class, $repo);
        $this->postJson('/api/auth/register', $this->signupData('rollback') + ['store_membership_proof' => app(AttributionProof::class)->issue('test-reference', time() + 60, 'membership')])->assertUnprocessable();
        $this->assertFalse(User::where('email', 'rollback@example.invalid')->exists());
    }

    private function signupData(string $name): array
    {
        return ['name' => 'Fixture', 'email' => $name.'@example.invalid', 'password' => 'Fixture!pass123', 'password_confirmation' => 'Fixture!pass123', 'agree_terms' => true, 'agree_privacy' => true];
    }

    public function test_expiry_tampering_replay_inactive_parent_and_ordinary_registration(): void
    {
        $owner = User::factory()->create();
        $repo = app(StoreRepository::class);
        $repo->manage($owner->id, 'apply', ['slug' => 'proof-review']);
        $store = Store::where('user_id', $owner->id)->firstOrFail();
        $store->update(['status' => 'active']);
        $reference = $repo->publicOrigin($store->slug);
        $proofs = app(AttributionProof::class);
        $token = $proofs->issue($reference, time() + 60, 'membership');
        foreach ([$proofs->issue($reference, time() - 1, 'membership'), $token.'tampered', $proofs->issue($reference, time() + 60, 'sale')] as $invalid) {
            $this->postJson('/api/auth/register', $this->signupData('invalid') + ['store_membership_proof' => $invalid])->assertUnprocessable();
        }
        $store->update(['status' => 'pending']);
        $this->postJson('/api/auth/register', $this->signupData('inactive') + ['store_membership_proof' => $token])->assertUnprocessable();
        $store->update(['status' => 'active']);
        $this->postJson('/api/auth/register', $this->signupData('first') + ['store_membership_proof' => $token])->assertSuccessful();
        $this->postJson('/api/auth/register', $this->signupData('replay') + ['store_membership_proof' => $token])->assertUnprocessable();
        $this->assertFalse(User::where('email', 'replay@example.invalid')->exists());
        $this->postJson('/api/auth/register', $this->signupData('ordinary'))->assertSuccessful();
        $ordinary = User::where('email', 'ordinary@example.invalid')->firstOrFail();
        $this->assertFalse(DB::table('personal_store_memberships')->where('user_id', $ordinary->id)->exists());
        $member = User::where('email', 'first@example.invalid')->firstOrFail();
        try {
            $repo->registerMembership($member->id, $reference);
            $this->fail('Existing membership must not be changed');
        } catch (QueryException) {
            $this->assertSame($store->id, (int) DB::table('personal_store_memberships')->where('user_id', $member->id)->value('store_id'));
        }
    }

    public function test_operator_response_has_closed_fields_and_never_exposes_other_store_lines(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $repo = app(StoreRepository::class);
        $order = OrderFactory::new()->create(['order_meta' => ['buyer_name' => 'PRIVATE-BUYER-MARKER', 'phone' => 'PRIVATE-PHONE-MARKER']]);
        foreach ([[$owner, 'mine'], [$other, 'other']] as [$user, $slug]) {
            $repo->manage($user->id, 'apply', ['slug' => $slug]);
            $store = Store::where('user_id', $user->id)->firstOrFail();
            DB::transaction(fn () => $repo->snapshot(['line_uuid' => (string) Str::uuid(), 'order_id' => $order->id, 'store_id' => $store->id, 'created_at' => now(), 'payload' => ['product_name' => $slug.' product', 'quantity' => 1, 'eligible_base' => 10000, 'points_used_share' => 1000, 'currency' => 'KRW', 'reward' => ['allocations' => [['store_id' => $store->id, 'amount' => 1000]]]]]));
        }
        $this->actingAs($owner, 'sanctum');
        $response = $this->getJson('/api/modules/yutiv-personal_store/operator?store_id=999999');
        $response->assertOk()->assertJsonCount(1, 'data.orders');
        $fields = array_keys($response->json('data.orders.0'));
        sort($fields);
        $expected = ['reference', 'ordered_at', 'product', 'quantity', 'payment_amount', 'currency', 'reward', 'point_status', 'order_status'];
        sort($expected);
        $this->assertSame($expected, $fields);
        $this->assertSame('mine product', $response->json('data.orders.0.product'));
        foreach ([$owner->email, $other->email, $order->order_number, 'PRIVATE-BUYER-MARKER', 'PRIVATE-PHONE-MARKER', 'other product', 'recipient_phone', 'refund_bank_account', 'user_id'] as $value) {
            $response->assertDontSee($value);
        }
        $this->getJson('/api/modules/yutiv-personal_store/admin')->assertForbidden();
        $this->getJson('/api/modules/yutiv-personal_store/stores/not-present')->assertNotFound()->assertDontSee('PRIVATE-BUYER-MARKER')->assertDontSee($order->order_number);
        $this->postJson('/api/auth/register', $this->signupData('private-error') + ['store_membership_proof' => 'PRIVATE-TOKEN-MARKER'])->assertUnprocessable()->assertDontSee('PRIVATE-TOKEN-MARKER')->assertDontSee('PRIVATE-BUYER-MARKER');
        $this->postJson('/api/modules/yutiv-personal_store/operator', ['action' => 'product_rate', 'product_id' => 0, 'rate_bps' => 10001])->assertUnprocessable();
    }

    public function test_registration_validates_proof_and_atomically_creates_membership(): void
    {
        $owner = User::factory()->create();
        $repo = app(StoreRepository::class);
        $repo->manage($owner->id, 'apply', ['slug' => 'signup-origin']);
        $store = Store::where('user_id', $owner->id)->firstOrFail();
        $store->update(['status' => 'active']);
        $proof = app(AttributionProof::class);
        $reference = $repo->publicOrigin($store->slug);
        $data = ['name' => 'Fixture', 'email' => 'new-member@example.invalid', 'password' => 'Fixture!pass123', 'password_confirmation' => 'Fixture!pass123', 'agree_terms' => true, 'agree_privacy' => true];
        $this->postJson('/api/auth/register', $data + ['store_membership_proof' => 'forged'])->assertUnprocessable();
        $this->assertFalse(User::where('email', $data['email'])->exists());
        $response = $this->postJson('/api/auth/register', $data + ['store_membership_proof' => $proof->issue($reference, time() + 60, 'membership')]);
        $response->assertSuccessful();
        $member = User::where('email', $data['email'])->firstOrFail();
        $this->assertSame($store->id, (int) DB::table('personal_store_memberships')->where('user_id', $member->id)->value('store_id'));
        $this->assertFalse($member->isAdmin());
    }

    public function test_active_operator_catalog_formats_prices_and_empty_category_selection_is_valid(): void
    {
        $owner = User::factory()->create();
        $repo = app(StoreRepository::class);
        $repo->manage($owner->id, 'apply', ['slug' => 'catalog-test']);
        Store::where('user_id', $owner->id)->firstOrFail()->update(['status' => 'active']);
        ProductFactory::new()->create(['display_status' => 'visible', 'sales_status' => 'on_sale']);
        $this->actingAs($owner, 'sanctum');
        $this->getJson('/api/modules/yutiv-personal_store/operator/catalog')->assertOk()->assertJsonCount(1, 'data.data')->assertJsonStructure(['data' => ['data' => [['selling_price_formatted', 'estimated_points', 'base_rate_bps']]]]);
        $this->postJson('/api/modules/yutiv-personal_store/operator', ['action' => 'categories', 'category_ids' => []])->assertOk();
    }
}
