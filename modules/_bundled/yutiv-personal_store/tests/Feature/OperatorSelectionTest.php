<?php

namespace Modules\Yutiv\PersonalStore\Tests\Feature;

use App\Models\User;
use App\Models\Attachment;
use App\Services\AttachmentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Yutiv\PersonalStore\Models\Store;
use Modules\Yutiv\PersonalStore\Repositories\Contracts\StoreRepositoryInterface;
use Modules\Yutiv\PersonalStore\Tests\ModuleTestCase;

class OperatorSelectionTest extends ModuleTestCase
{
    private function operator(string $slug, string $status = 'active'): array
    {
        $user = User::factory()->create();
        app(StoreRepositoryInterface::class)->manage($user->id, 'apply', ['slug' => $slug]);
        $store = Store::where('user_id', $user->id)->firstOrFail();
        $store->update(['status' => $status]);

        return [$user, $store];
    }

    public function test_parent_search_excludes_self_descendants_and_inactive_stores_without_personal_fields(): void
    {
        [$owner, $own] = $this->operator('own-store');
        [, $child] = $this->operator('child-store');
        [, $parent] = $this->operator('parent-store');
        $this->operator('inactive-store', 'pending');
        DB::table('personal_store_closure')->insert(['ancestor_id' => $own->id, 'descendant_id' => $child->id, 'depth' => 1]);
        $this->actingAs($owner, 'sanctum');
        $response = $this->getJson('/api/modules/yutiv-personal_store/operator/parents');
        $response->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.name', $parent->slug);
        $this->assertSame(['id', 'name'], array_keys($response->json('data.data.0')));
        $response->assertDontSee($owner->email);
        $this->getJson('/api/modules/yutiv-personal_store/operator/parents?search=missing')->assertOk()->assertJsonCount(0, 'data.data');
    }

    public function test_catalog_searches_product_code_and_distinguishes_explicit_exclusion(): void
    {
        [$owner] = $this->operator('catalog-operator');
        $product = ProductFactory::new()->create(['product_code' => 'UI-CODE-123', 'display_status' => 'visible', 'sales_status' => 'on_sale']);
        app(StoreRepositoryInterface::class)->manage($owner->id, 'products', ['product_id' => $product->id, 'excluded' => true]);
        $this->actingAs($owner, 'sanctum');
        $this->getJson('/api/modules/yutiv-personal_store/operator/catalog?search=UI-CODE-123')->assertOk()->assertJsonCount(1, 'data.data')->assertJsonPath('data.data.0.selection', 'exclude')->assertJsonPath('data.data.0.selected', false)->assertJsonStructure(['data' => ['data' => [['thumbnail_url', 'selling_price_formatted', 'estimated_points']]]]);
    }

    public function test_category_choices_include_localized_ancestry(): void
    {
        [$owner] = $this->operator('category-operator');
        $parent = Category::create(['name' => ['ko' => '상위', 'en' => 'Parent'], 'path' => 'ui-parent', 'is_active' => true]);
        $child = Category::create(['name' => ['ko' => '하위', 'en' => 'Child'], 'path' => 'ui-child', 'parent_id' => $parent->id, 'is_active' => true]);
        $this->actingAs($owner, 'sanctum');
        $items = $this->getJson('/api/modules/yutiv-personal_store/operator/categories')->assertOk()->json('data.data');
        $item = collect($items)->firstWhere('id', $child->id);
        $this->assertStringContainsString($parent->getLocalizedName().' / '.$child->getLocalizedName(), $item['path']);
    }

    public function test_media_and_parent_choices_require_an_active_operator(): void
    {
        $url = '/api/modules/yutiv-personal_store/operator';
        $this->getJson($url.'/parents')->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'sanctum');
        $this->getJson($url.'/parents')->assertForbidden();
        $attachments = \Mockery::mock(AttachmentService::class);
        $attachments->shouldNotReceive('upload');
        $this->app->instance(AttachmentService::class, $attachments);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/L9sAAAAASUVORK5CYII=');
        $this->postJson($url.'/media', ['collection' => 'logo', 'file' => UploadedFile::fake()->createWithContent('logo.png', $png)])->assertForbidden();
        [$pending] = $this->operator('pending-operator', 'pending');
        $this->actingAs($pending, 'sanctum');
        $this->getJson($url.'/parents')->assertForbidden();
        $this->postJson($url.'/media', ['collection' => 'logo', 'file' => UploadedFile::fake()->createWithContent('logo.png', $png)])->assertForbidden();
    }

    public function test_upload_rejects_non_image_and_unknown_collection_before_storage(): void
    {
        [$owner] = $this->operator('upload-operator');
        $this->actingAs($owner, 'sanctum');
        $this->postJson('/api/modules/yutiv-personal_store/operator/media', ['collection' => 'orders', 'file' => UploadedFile::fake()->create('private.txt', 1, 'text/plain')])->assertUnprocessable()->assertJsonValidationErrors(['file', 'collection']);
    }

    public function test_image_upload_reuses_core_storage_and_ignores_another_store_target(): void
    {
        Storage::fake('attachments');
        config(['attachment.disk' => 'attachments']);
        [$owner, $own] = $this->operator('own-image-store');
        [, $other] = $this->operator('other-image-store');
        $this->actingAs($owner, 'sanctum');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/L9sAAAAASUVORK5CYII=');
        $response = $this->postJson('/api/modules/yutiv-personal_store/operator/media', ['collection' => 'logo', 'file' => UploadedFile::fake()->createWithContent('logo.png', $png), 'store_id' => $other->id, 'attachmentable_id' => $other->id, 'attachmentable_type' => User::class]);
        $response->assertCreated()->assertJsonPath('data.is_image', true);
        $attachment = Attachment::where('hash', $response->json('data.hash'))->firstOrFail();
        $this->assertSame(Store::class, $attachment->attachmentable_type);
        $this->assertSame($own->id, $attachment->attachmentable_id);
        $this->assertSame('logo', $attachment->collection);
        $this->assertSame($owner->id, $attachment->created_by);
        Storage::disk('attachments')->assertExists($attachment->path);
        $this->get($response->json('data.download_url'))->assertOk();
    }
}
