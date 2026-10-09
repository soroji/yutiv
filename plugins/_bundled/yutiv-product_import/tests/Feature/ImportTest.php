<?php

namespace Plugins\Yutiv\ProductImport\Tests\Feature;

use App\Enums\ExtensionOwnerType;
use App\Extension\Helpers\ExtensionMenuSyncHelper;
use App\Extension\ModuleManager;
use App\Models\Menu;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Ecommerce\Exceptions\OptionHasOrderHistoryException;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductImage;
use Modules\Sirsoft\Ecommerce\Models\Sequence;
use Modules\Sirsoft\Ecommerce\Repositories\ProductRepository;
use Modules\Sirsoft\Ecommerce\Services\CartService;
use Modules\Sirsoft\Ecommerce\Services\ProductService;
use Modules\Sirsoft\Ecommerce\Services\StockService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Monolog\Handler\NullHandler;
use Plugins\Yutiv\ProductImport\Enums\ImportStatus;
use Plugins\Yutiv\ProductImport\Http\Controllers\ImportController;
use Plugins\Yutiv\ProductImport\Http\Requests\ImportRowsRequest;
use Plugins\Yutiv\ProductImport\Listeners\ImportMenuListener;
use Plugins\Yutiv\ProductImport\Plugin;
use Plugins\Yutiv\ProductImport\Providers\ProductImportServiceProvider;
use Plugins\Yutiv\ProductImport\Services\ImportProcessor;
use Plugins\Yutiv\ProductImport\Services\ImportService;
use Plugins\Yutiv\ProductImport\Support\ImageFetcher;
use Plugins\Yutiv\ProductImport\Support\ImportAccess;
use Plugins\Yutiv\ProductImport\Support\Workbook;

class ImportTest extends ModuleTestCase
{
    private string $lastError = '';

    protected array $requiredExtensions = ['sirsoft-ecommerce', 'yutiv-product_import'];

    public function createApplication()
    {
        $app = require dirname(__DIR__, 5).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function shouldSeed(): bool
    {
        return false;
    }

    protected function migrateFreshUsing(): array
    {
        // Use the real core/ecommerce/import migrations, without unrelated board partitions.
        $paths = [];
        foreach (glob(base_path('database/migrations/2026_04_01_*.php')) as $path) {
            if (preg_match('/2026_04_01_000(\d+)_/', basename($path), $m) && ((int) $m[1] <= 19 || (int) $m[1] === 30)) {
                $paths[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
            }
        }
        foreach (glob(base_path('modules/_bundled/sirsoft-ecommerce/database/migrations/*.php')) as $path) {
            $name = basename($path);
            if (preg_match('/2026_04_01_000(\d+)_/', $name, $m)) {
                if ((int) $m[1] <= 18 || str_contains($name, 'cart') || in_array((int) $m[1], [34, 35, 39, 40, 52, 62], true)) {
                    $paths[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
                }
            } elseif (str_contains($name, 'product') || str_contains($name, 'shipping_types') || str_contains($name, 'cart')) {
                $paths[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
            }
        }
        foreach (glob(base_path('database/migrations/*identity_policies*.php')) as $path) {
            $paths[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
        }

        return ['--path' => array_merge($paths, ['plugins/_bundled/yutiv-product_import/database/migrations']), '--seed' => false];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $moduleVendor = base_path('modules/_bundled/sirsoft-ecommerce/vendor/autoload.php');
        if (is_file($moduleVendor)) {
            require_once $moduleVendor;
        }
        config(['queue.default' => 'database', 'yutiv-product-import.connection' => 'database', 'queue.connections.database.retry_after' => 600]);
        config(['logging.default' => 'null', 'logging.channels.null' => ['driver' => 'monolog', 'handler' => NullHandler::class]]);
        Bus::fake();
        Log::partialMock()->shouldReceive('warning')->andReturnUsing(function ($message, $context = []) {
            if (isset($context['exception'])) {
                $this->lastError = $context['exception']->getMessage().' at '.$context['exception']->getFile().':'.$context['exception']->getLine();
            }
        });
        Log::shouldReceive('info')->andReturnNull();
        Log::shouldReceive('error')->andReturnNull();
        Sequence::firstOrCreate(['type' => 'product'], ['algorithm' => 'sequential', 'prefix' => 'P', 'current_value' => 0, 'increment' => 1, 'min_value' => 1, 'max_value' => 99999999, 'cycle' => false, 'pad_length' => 8]);
        Auth::login($this->createAdminUser(['sirsoft-ecommerce.products.create', 'yutiv-product_import.headquarters.create']));
        Storage::fake('local');
        Storage::fake('public');
        Route::prefix('api/plugins/yutiv-product_import')->group(dirname(__DIR__, 2).'/src/routes/api.php');
    }

    private function book(array $products = [], array $options = []): string
    {
        $cat = Category::where('is_active', true)->first() ?? Category::create(['name' => ['ko' => '테스트 분류'], 'is_active' => true, 'depth' => 0, 'path' => '']);
        $products = $products ?: [['000123', '테스트 상품', (string) $cat->id, '12000', '5', '<p>설명</p><script>alert(1)</script>', '', '']];
        $path = tempnam(sys_get_temp_dir(), 'import-test-');
        file_put_contents($path, app(Workbook::class)->write(['입력 안내' => [['예시']], '상품' => [Workbook::PRODUCT, ...$products], '옵션' => [Workbook::OPTION, ...$options], '카테고리 안내' => [['코드']]]));

        return $path;
    }

    private function preview(string $path)
    {
        try {
            return app(ImportService::class)->preview($path, 'products.xlsx', Auth::user());
        } finally {
            unlink($path);
        }
    }

    public function test_preview_creates_no_product_and_registers_single_hidden_product_once(): void
    {
        $before = Product::count();
        $run = $this->preview($this->book());
        $this->assertSame([], $run->errors);
        $this->assertSame($before, Product::count());
        $imports = app(ImportService::class);
        $imports->confirm($run);
        $imports->confirm($run);
        $row = $run->rows()->first();
        app(ImportProcessor::class)->process($row->id);
        app(ImportProcessor::class)->process($row->id);
        $this->assertSame($before + 1, Product::count(), $this->lastError.' '.json_encode($row->fresh()->only(['status', 'error', 'product_code', 'attempts'])));
        $row->refresh();
        $this->assertSame('succeeded', $row->status->value, $row->error ?? '');
        $p = Product::findOrFail($row->product_id);
        $this->assertSame('hidden', $p->display_status->value);
        $this->assertSame('suspended', $p->sales_status->value);
        $this->assertCount(1, $p->options);
        $this->assertSame(5, $p->stock_quantity);
        $this->assertStringNotContainsString('<script', $p->description['ko']);
    }

    private function category(): Category
    {
        return Category::create(['name' => ['ko' => '분류'], 'is_active' => true, 'depth' => 0, 'path' => '']);
    }

    private function validation(array $p, array $o = []): array
    {
        return app(ImportRowsRequest::class)->validateWorkbook(['상품' => $p, '옵션' => $o]);
    }

    public function test_combination_options_use_existing_stock_and_price_rules(): void
    {
        $cat = $this->category();
        $run = $this->preview($this->book([['0001', '옵션 상품', (string) $cat->id, '12000', '7', '', '', '']],
            [['0001', '001', '색상', '베이지', '크기', 'M', '', '', '0', '5'], ['0001', '002', '색상', '검정', '크기', 'L', '', '', '1000', '2']]));
        $this->assertSame([], $run->errors);
        app(ImportService::class)->confirm($run);
        app(ImportProcessor::class)->process($run->rows()->first()->id);
        $p = Product::findOrFail($run->rows()->first()->product_id);
        $this->assertCount(2, $p->options);
        $this->assertSame(7, $p->stock_quantity);
        $this->assertTrue($p->has_options);
        $this->assertSame(13000.0, $p->options[1]->getFinalPrice());
        $this->assertCount(2, $p->option_groups);
    }

    public function test_category_money_stock_and_required_fields_report_sheet_row_column(): void
    {
        $r = $this->validation([17 => ['bad', '', '999999', '12.5', '-1', '', '', '']]);
        $this->assertContains('카테고리 코드', array_column($r['errors'], 'column'));
        $this->assertContains('판매가', array_column($r['errors'], 'column'));
        $this->assertContains('재고', array_column($r['errors'], 'column'));
        $this->assertContains('상품명', array_column($r['errors'], 'column'));
        foreach ($r['errors'] as $e) {
            $this->assertSame(17, $e['row']);
            $this->assertNotEmpty($e['fix']);
        }
    }

    public function test_file_duplicate_and_orphan_duplicate_options_are_invalid(): void
    {
        $cat = $this->category();
        $p = ['0001', '상품', (string) $cat->id, '100', '2', '', '', ''];
        $r = $this->validation([2 => $p, 3 => $p], [2 => ['missing', '1', '색상', '검정', '', '', '', '', '0', '1'], 3 => ['0001', '1', '색상', '검정', '', '', '', '', '0', '1'], 4 => ['0001', '1', '색상', '검정', '', '', '', '', '0', '1']]);
        $messages = implode(' ', array_column($r['errors'], 'message'));
        $this->assertStringContainsString('관리코드가 중복', $messages);
        $this->assertStringContainsString('상품 행이 없습니다', $messages);
        $this->assertStringContainsString('옵션 조합이 중복', $messages);
        $this->assertContains(4, array_column($r['errors'], 'row'));
    }

    public function test_mismatched_stock_groups_and_nonzero_default_adjustment_are_invalid(): void
    {
        $cat = $this->category();
        $r = $this->validation([2 => ['0001', '상품', (string) $cat->id, '100', '10', '', '', '']],
            [2 => ['0001', '1', '색상', '검정', '', '', '', '', '10', '1'], 3 => ['0001', '2', '크기', 'M', '', '', '', '', '0', '1']]);
        $messages = implode(' ', array_column($r['errors'], 'message'));
        foreach (['기본 옵션', '그룹 이름', '옵션 재고 합계'] as $term) {
            $this->assertStringContainsString($term, $messages);
        }
    }

    public function test_existing_import_identity_and_existing_manual_product_code_are_rejected(): void
    {
        $run = $this->preview($this->book());
        app(ImportService::class)->confirm($run);
        $row = $run->rows()->first();
        app(ImportProcessor::class)->process($row->id);
        $second = $this->preview($this->book());
        $this->assertSame(ImportStatus::Invalid, $second->status);
        $cat = $this->category();
        $r = $this->validation([2 => [$row->fresh()->product_code, '상품', (string) $cat->id, '100', '1', '', '', '']]);
        $this->assertStringContainsString('기존 상품', implode(' ', array_column($r['errors'], 'message')));
        $this->assertSame(1, Product::count());
    }

    public function test_confirm_revalidates_category_and_never_trusts_browser_rows(): void
    {
        $cat = $this->category();
        $run = $this->preview($this->book([['0001', '상품', (string) $cat->id, '100', '1', '', '', '']]));
        $cat->update(['is_active' => false]);
        $this->expectException(ValidationException::class);
        try {
            app(ImportService::class)->confirm($run);
        } finally {
            $this->assertSame(0, Product::count());
            $this->assertSame('preview', $run->fresh()->status->value);
        }
    }

    public function test_confirm_uses_server_preview_and_ignores_client_price(): void
    {
        $run = $this->preview($this->book());
        $this->actingAs(Auth::user(), 'sanctum');
        $this->postJson('/api/plugins/yutiv-product_import/admin/product-imports/'.$run->id.'/confirm', ['selling_price' => 1, 'rows' => [['price' => 1]]])->assertOk();
        app(ImportProcessor::class)->process($run->rows()->first()->id);
        $this->assertEquals('12000.00', Product::firstOrFail()->selling_price);
    }

    public function test_unauthorized_access_and_scoped_manager_are_denied_on_all_endpoints(): void
    {
        $run = $this->preview($this->book());
        $member = User::factory()->create();
        $this->actingAs($member, 'sanctum');
        $base = '/api/plugins/yutiv-product_import/admin/product-imports';
        foreach (['', '/template', '/'.$run->id, '/'.$run->id.'/result'] as $path) {
            $this->getJson($base.$path)->assertForbidden();
        }
        foreach (['', '/'.$run->id.'/confirm', '/'.$run->id.'/retry'] as $path) {
            $this->postJson($base.$path)->assertForbidden();
        }
        $limited = $this->createAdminUser(['sirsoft-ecommerce.products.create']);
        $this->assertFalse(ImportAccess::allowed($limited));
        $hq = $this->createAdminUser(['sirsoft-ecommerce.products.create', 'yutiv-product_import.headquarters.create']);
        $role = $hq->roles()->first();
        $permission = Permission::where('identifier', 'sirsoft-ecommerce.products.create')->first();
        $role->permissions()->updateExistingPivot($permission->id, ['scope_type' => 'self']);
        $this->assertFalse(ImportAccess::allowed($hq->fresh()));
        $this->assertSame(0, Product::count());
    }

    public function test_another_headquarters_user_cannot_read_or_download_the_run(): void
    {
        $run = $this->preview($this->book());
        $other = $this->createAdminUser(['sirsoft-ecommerce.products.create', 'yutiv-product_import.headquarters.create']);
        $this->actingAs($other, 'sanctum');
        $base = '/api/plugins/yutiv-product_import/admin/product-imports/'.$run->id;
        $this->getJson($base)->assertNotFound();
        $this->getJson($base.'/result')->assertNotFound();
        $this->postJson($base.'/retry')->assertNotFound();
    }

    private function fakeImages(bool $failSecond = false): void
    {
        $this->app->instance(ImageFetcher::class, new class($failSecond) extends ImageFetcher
        {
            private int $calls = 0;

            public function __construct(private bool $failSecond) {}

            public function inspect(string $url): array
            {
                return ['images.example.org', ['93.184.216.34']];
            }

            public function fetch(string $url, ?string $path = null): UploadedFile
            {
                if ($this->failSecond && ++$this->calls === 2) {
                    Workbook::reject('이미지 실패 테스트');
                }
                $path = $path ?? tempnam(sys_get_temp_dir(), 'import-image-');
                $image = imagecreatetruecolor(2, 2);
                imagepng($image, $path);
                imagedestroy($image);

                return new UploadedFile($path, 'photo.png', 'image/png', null, true);
            }
        });
    }

    public function test_image_failure_rolls_back_the_unit_cleans_files_and_retries_only_failed_rows(): void
    {
        $this->fakeImages(true);
        $cat = $this->category();
        $run = $this->preview($this->book([['ok', '일반', (string) $cat->id, '100', '1', '', '', ''], ['bad', '이미지', (string) $cat->id, '100', '1', '', 'https://images.example.org/1.png', 'https://images.example.org/2.png']]));
        app(ImportService::class)->confirm($run);
        foreach ($run->rows()->get() as $row) {
            app(ImportProcessor::class)->process($row->id);
        }
        $summary = app(ImportService::class)->summary($run);
        $this->assertSame(1, $summary['counts']['succeeded']);
        $this->assertSame(1, $summary['counts']['failed']);
        $this->assertSame(1, Product::count());
        $this->assertSame(0, ProductImage::count());
        $failed = $run->rows()->where('management_code', 'bad')->first();
        $this->assertStringContainsString('이미지 실패 테스트', $failed->error);
        $storage = app(ModuleManager::class)->getModule('sirsoft-ecommerce')->getStorage();
        $this->assertSame([], $storage->files('images', 'products/'.$failed->product_code));
        $success = $run->rows()->where('management_code', 'ok')->first();
        $oldId = $success->product_id;
        $this->fakeImages(false);
        app(ImportService::class)->retry($run);
        foreach ($run->rows()->get() as $row) {
            app(ImportProcessor::class)->process($row->id);
        }
        $this->assertSame(2, Product::count(), $this->lastError);
        $this->assertSame($oldId, $success->fresh()->product_id);
        $this->assertSame(2, ProductImage::count());
        $this->assertSame(0, $run->rows()->where('status', 'failed')->count());
    }

    public function test_worker_recovers_stale_file_journal_and_rechecks_revoked_permission(): void
    {
        $run = $this->preview($this->book());
        app(ImportService::class)->confirm($run);
        $row = $run->rows()->first();
        $storage = app(ModuleManager::class)->getModule('sirsoft-ecommerce')->getStorage();
        $row->update(['status' => ImportStatus::Processing, 'product_code' => 'JOURNAL123', 'storage_disk' => $storage->getDisk()]);
        $storage->put('images', 'products/JOURNAL123/orphan.png', 'orphan');
        app(ImportProcessor::class)->process($row->id);
        $this->assertFalse($storage->exists('images', 'products/JOURNAL123/orphan.png'));
        $this->assertSame(1, Product::count());
        $second = $this->preview($this->book([['different', '상품', (string) $this->category()->id, '100', '1', '', '', '']]));
        app(ImportService::class)->confirm($second);
        Auth::user()->roles()->detach();
        app(ImportProcessor::class)->process($second->rows()->first()->id);
        $this->assertSame('failed', $second->rows()->first()->status->value);
        $this->assertSame(1, Product::count());
    }

    public function test_result_excel_preserves_original_rows_zero_prefixes_and_literal_formula_like_names(): void
    {
        $run = $this->preview($this->book([['0000123', '=1+1', (string) $this->category()->id, '100', '1', '', '', '']]));
        $request = Request::create('/');
        $request->setUserResolver(fn () => Auth::user());
        $bytes = app(ImportController::class)->result($request, $run->id)->getContent();
        $path = tempnam(sys_get_temp_dir(), 'result-');
        file_put_contents($path, $bytes);
        try {
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($path));
            $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->close();
            $this->assertStringContainsString('0000123', $xml);
            $this->assertStringContainsString('=1+1', $xml);
            $this->assertStringContainsString('>2</t>', $xml);
            $this->assertStringNotContainsString('<f>', $xml);
        } finally {
            unlink($path);
        }
    }

    public function test_download_template_has_four_empty_input_sheets_and_real_category_paths(): void
    {
        $parent = $this->category();
        $child = Category::create(['name' => ['ko' => '하위'], 'parent_id' => $parent->id, 'is_active' => true, 'depth' => 1, 'path' => $parent->id]);
        $bytes = app(ImportController::class)->template()->getContent();
        $path = tempnam(sys_get_temp_dir(), 'template-');
        file_put_contents($path, $bytes);
        try {
            $data = app(Workbook::class)->read($path);
            $this->assertSame([], $data['상품']);
            $this->assertSame([], $data['옵션']);
            $z = new \ZipArchive;
            $z->open($path);
            $this->assertStringContainsString('분류 &gt; 하위', $z->getFromName('xl/worksheets/sheet4.xml'));
            $this->assertStringContainsString('numFmtId="49"', $z->getFromName('xl/styles.xml'));
            $z->close();
        } finally {
            unlink($path);
        }
    }

    public function test_provider_loads_queue_configuration_from_its_own_directory(): void
    {
        config(['yutiv-product-import' => []]);
        (new ProductImportServiceProvider($this->app))->register();
        $this->assertSame('database', config('yutiv-product-import.connection'));
    }

    public function test_two_previews_cannot_confirm_the_same_management_code(): void
    {
        $first = $this->preview($this->book());
        $second = $this->preview($this->book());
        app(ImportService::class)->confirm($first);
        try {
            app(ImportService::class)->confirm($second);
            $this->fail('Duplicate reservation accepted');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('관리코드', implode(' ', $e->errors()['file']));
        }
        $this->assertSame(0, Product::count());
        $this->assertSame('preview', $second->fresh()->status->value);
    }

    public function test_manual_product_creation_and_public_listing_still_work(): void
    {
        $this->withoutExceptionHandling();
        Auth::user()->update(['language' => 'ko']);
        $run = $this->preview($this->book());
        $payload = $run->rows()->first()->payload;
        $payload['product_code'] = 'MANUAL001';
        $payload['sales_product_code'] = 'manual';
        $payload['display_status'] = 'visible';
        $payload['sales_status'] = 'on_sale';
        $response = $this->actingAs(Auth::user(), 'sanctum')->postJson('/api/modules/sirsoft-ecommerce/admin/products', $payload);
        $this->assertSame(201, $response->status(), $response->getContent());
        app(ImportService::class)->confirm($run);
        app(ImportProcessor::class)->process($run->rows()->first()->id);
        $this->assertSame(2, Product::count());
        $list = app(ProductRepository::class)->getPublicList([]);
        $this->assertSame(['MANUAL001'], $list->pluck('product_code')->all());
    }

    public function test_invalid_option_stock_is_a_row_error_instead_of_an_exception(): void
    {
        $run = $this->preview($this->book([], [['000123', '0001', '색상', '흰색', '', '', '', '', '0', '잘못된 값']]));
        $this->assertSame('invalid', $run->status->value);
        $this->assertTrue(collect($run->errors)->contains(fn ($e) => $e['sheet'] === '옵션' && $e['column'] === '재고' && $e['row'] === 2));
    }

    public function test_plugin_menu_remains_under_products_after_official_resync(): void
    {
        $parent = Menu::create(['slug' => 'sirsoft-ecommerce-products', 'name' => ['ko' => '상품 관리'], 'extension_type' => 'module', 'extension_identifier' => 'sirsoft-ecommerce']);
        $plugin = new Plugin;
        $plugin->activate();
        $menu = Menu::where('slug', 'yutiv-product_import')->firstOrFail();
        $this->assertSame($parent->id, $menu->parent_id);
        foreach ($plugin->getAdminMenus() as $data) {
            app(ExtensionMenuSyncHelper::class)->syncMenuRecursive($data, ExtensionOwnerType::Plugin, $plugin->getIdentifier());
        }
        $this->assertNull($menu->fresh()->parent_id);
        (new ImportMenuListener)->handle('yutiv-product_import');
        $this->assertSame($parent->id, $menu->fresh()->parent_id);
    }

    public function test_revalidation_preserves_preview_language_when_request_language_changes(): void
    {
        $run = $this->preview($this->book());
        $original = app()->getLocale();
        try {
            app()->setLocale('en');
            $result = app(ImportService::class)->revalidate($run->rows()->first());
            $this->assertSame([], $result['errors']);
            $this->assertArrayHasKey('ko', $result['rows'][0]['payload']['name']);
            $this->assertSame('en', app()->getLocale());
        } finally {
            app()->setLocale($original);
        }
    }

    public function test_database_capacity_and_bad_additional_image_url_are_preview_errors(): void
    {
        $run = $this->preview($this->book([['limits', '상품', (string) $this->category()->id, '10000000000000', '2147483648', '', '', 'http://localhost/image.png']]));
        $columns = collect($run->errors)->pluck('column')->all();
        $this->assertContains('판매가', $columns);
        $this->assertContains('재고', $columns);
        $this->assertContains('추가 이미지 URL', $columns);
        $this->assertSame(0, Product::count());
    }

    public function test_simple_manual_product_reuses_sales_unit_and_root_price_and_stock(): void
    {
        $this->withoutExceptionHandling();
        Auth::login($this->createAdminUser(['sirsoft-ecommerce.products.create', 'sirsoft-ecommerce.products.read', 'sirsoft-ecommerce.products.update', 'yutiv-product_import.headquarters.create']));
        Auth::user()->update(['language' => 'ko']);
        $run = $this->preview($this->book());
        $payload = $run->rows()->first()->payload;
        $this->assertFalse($payload['has_options']);
        $this->assertSame([], $payload['options']);
        unset($payload['options']);
        $this->actingAs(Auth::user(), 'sanctum')->postJson('/api/modules/sirsoft-ecommerce/admin/products', $payload)->assertCreated();
        $product = Product::firstOrFail();
        $unit = $product->options()->sole();
        $this->assertFalse($product->has_options);
        $this->assertSame([], $unit->option_values);
        $this->assertSame(5, $unit->stock_quantity);
        $service = app(ProductService::class);
        for ($i = 0; $i < 2; $i++) {
            $product = $service->update($product, ['has_options' => false, 'selling_price' => 15000, 'list_price' => 15000, 'stock_quantity' => 8]);
            $this->assertSame($unit->id, $product->options()->sole()->id);
            $this->assertSame(8, $product->options()->sole()->stock_quantity);
            $this->assertEquals(15000, $product->options()->sole()->selling_price);
        }
        $this->assertSame(1, Product::count());
        $this->getJson('/api/modules/sirsoft-ecommerce/admin/products/'.$product->id)->assertOk()->assertJsonPath('data.has_options', false);
        $this->putJson('/api/modules/sirsoft-ecommerce/admin/products/'.$product->id, [
            'product_code' => $product->product_code, 'has_options' => false,
            'stock_quantity' => 9, 'options' => $product->options()->get()->toArray(),
        ])->assertOk();
        $this->assertSame($unit->id, $product->options()->sole()->id);
        $this->assertSame(9, $product->options()->sole()->stock_quantity);
    }

    public function test_simple_sales_unit_links_cart_and_inventory_without_selecting_an_option(): void
    {
        $payload = $this->preview($this->book())->rows()->first()->payload;
        $payload['display_status'] = 'visible';
        $payload['sales_status'] = 'on_sale';
        $product = app(ProductService::class)->create($payload);
        $unit = $product->options()->sole();
        $cart = app(CartService::class)->bulkAddToCart([
            'product_id' => $product->id, 'user_id' => Auth::id(), 'items' => [['quantity' => 1]],
        ]);
        $this->assertSame($unit->id, $cart['items'][0]->product_option_id);
        $stock = app(StockService::class);
        $this->assertTrue($stock->deductOptionStock($unit->id, 2));
        $this->assertSame(3, $product->fresh()->stock_quantity);
        $this->assertTrue($stock->restoreOptionStock($unit->id, 1));
        $this->assertSame(4, $product->fresh()->stock_quantity);
    }

    public function test_simple_product_bulk_edit_keeps_sales_unit_price_and_stock_in_sync(): void
    {
        $payload = $this->preview($this->book())->rows()->first()->payload;
        $service = app(ProductService::class);
        $product = $service->create($payload);
        $unit = $product->options()->sole();
        $service->bulkUpdateStock([$product->id], 'set', 7);
        $this->assertSame(7, $unit->fresh()->stock_quantity);
        $service->bulkUpdatePrice([$product->id], 'set', 15000, 'amount');
        $this->assertEquals(15000, $unit->fresh()->selling_price);
        $this->assertSame($unit->id, $product->options()->sole()->id);
    }

    public function test_order_reference_survives_simple_resave_and_blocks_unit_replacement(): void
    {
        Auth::login($this->createAdminUser(['sirsoft-ecommerce.products.create', 'sirsoft-ecommerce.products.update', 'yutiv-product_import.headquarters.create']));
        Auth::user()->update(['language' => 'ko']);
        $payload = $this->preview($this->book())->rows()->first()->payload;
        $service = app(ProductService::class);
        $product = $service->create($payload);
        $unit = $product->options()->sole();
        $order = Order::create([
            'order_number' => 'OPTION-REFERENCE-TEST', 'order_status' => 'pending_order',
            'subtotal_amount' => 12000, 'total_amount' => 12000, 'item_count' => 1, 'ordered_at' => now(),
        ]);
        $line = OrderOption::create([
            'order_id' => $order->id, 'product_id' => $product->id, 'product_option_id' => $unit->id,
            'option_status' => 'pending_order', 'product_name' => ['ko' => '일반 상품'],
            'quantity' => 1, 'unit_price' => 12000, 'subtotal_price' => 12000,
            'product_snapshot' => $product->toArray(),
            'option_snapshot' => $unit->toArray(),
        ]);
        $service->update($product, ['has_options' => false, 'stock_quantity' => 7]);
        $this->assertSame($unit->id, $line->fresh()->product_option_id);
        $this->assertSame($unit->id, $product->options()->sole()->id);
        try {
            $service->update($product, ['has_options' => true, 'options' => [[
                'option_code' => 'NEW', 'option_name' => ['ko' => '검정'],
                'option_values' => [['key' => ['ko' => '색상'], 'value' => ['ko' => '검정']]],
                'list_price' => 12000, 'selling_price' => 12000, 'stock_quantity' => 7,
                'is_default' => true, 'is_active' => true,
            ]]]);
            $this->fail('Order-referenced sales unit replaced');
        } catch (OptionHasOrderHistoryException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $this->assertFalse($product->fresh()->has_options);
        $this->assertSame($unit->id, $product->options()->sole()->id);
        $choices = $unit->toArray();
        unset($choices['id']);
        $choices['option_code'] = 'NEW';
        $choices['option_name'] = ['ko' => '검정'];
        $choices['option_values'] = [['key' => ['ko' => '색상'], 'value' => ['ko' => '검정']]];
        $choices['stock_quantity'] = 7;
        $choices['price_adjustment'] = 0;
        $response = $this->actingAs(Auth::user(), 'sanctum')->putJson('/api/modules/sirsoft-ecommerce/admin/products/'.$product->id, [
            'product_code' => $product->product_code, 'has_options' => true, 'stock_quantity' => 7, 'options' => [$choices],
        ])->assertStatus(422);
        $this->assertArrayHasKey('options', $response->json('errors'), $response->getContent());
        $this->assertSame($unit->id, $product->options()->sole()->id);
    }

    public function test_existing_choices_cannot_be_silently_removed_or_recreated_on_resave(): void
    {
        $run = $this->preview($this->book([], [
            ['000123', '0001', '색상', '검정', '', '', '', '', '0', '2'],
            ['000123', '0002', '색상', '흰색', '', '', '', '', '1000', '3'],
        ]));
        $payload = $run->rows()->first()->payload;
        $service = app(ProductService::class);
        $product = $service->create($payload);
        $options = $product->options()->orderBy('id')->get();
        $form = $service->getDetailForForm($product->id);
        $this->assertTrue($form['has_options']);
        $this->assertCount(1, $form['option_groups']);
        $this->assertEquals(1000, $form['options'][1]['price_adjustment']);
        $copy = $service->getDetailForCopy($product->id);
        $this->assertTrue($copy['has_options']);
        $this->assertCount(1, $copy['option_groups']);
        $this->assertArrayNotHasKey('id', $copy['options'][0]);
        $withoutOptions = $service->getDetailForCopy($product->id, ['options' => false]);
        $this->assertFalse($withoutOptions['has_options']);
        $this->assertSame([], $withoutOptions['option_groups']);
        $payload['options'] = $options->toArray();
        $saved = $service->update($product, $payload);
        $this->assertSame($options->pluck('id')->all(), $saved->options()->orderBy('id')->pluck('id')->all());
        foreach ($options as $option) {
            $this->assertEquals($option->selling_price, $option->fresh()->selling_price);
            $this->assertSame($option->stock_quantity, $option->fresh()->stock_quantity);
            $this->assertSame($option->option_code, $option->fresh()->option_code);
        }
        try {
            $service->update($saved, ['has_options' => false, 'options' => []]);
            $this->fail('Existing choices were removed');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('has_options', $e->errors());
        }
        $this->assertSame($options->pluck('id')->all(), $product->options()->orderBy('id')->pluck('id')->all());
    }

    public function test_mixed_import_uses_the_same_simple_and_choice_sales_units(): void
    {
        $cat = (string) $this->category()->id;
        $run = $this->preview($this->book([
            ['simple', '일반 상품', $cat, '12000', '5', '', '', ''],
            ['choice', '옵션 상품', $cat, '12000', '5', '', '', ''],
        ], [
            ['choice', '001', '색상', '검정', '', '', '', '', '0', '2'],
            ['choice', '002', '색상', '흰색', '', '', '', '', '1000', '3'],
        ]));
        $this->assertSame('preview', $run->status->value, json_encode($run->errors));
        $this->assertSame(0, Product::count());
        app(ImportService::class)->confirm($run);
        foreach ($run->rows()->get() as $row) {
            app(ImportProcessor::class)->process($row->id);
        }
        $this->assertSame(2, Product::count());
        $simple = Product::where('has_options', false)->sole();
        $choice = Product::where('has_options', true)->sole();
        $this->assertSame([], $simple->options()->sole()->option_values);
        $this->assertSame(2, $choice->options()->count());
    }

    public function test_authenticated_upload_rejects_macro_extension_and_corrupt_workbook(): void
    {
        $this->actingAs(Auth::user(), 'sanctum')->withHeader('Accept', 'application/json');
        $base = '/api/plugins/yutiv-product_import/admin/product-imports';
        $this->post($base, ['file' => UploadedFile::fake()->create('macro.xlsm', 1)])->assertStatus(422);
        $this->post($base, ['file' => UploadedFile::fake()->createWithContent('broken.xlsx', 'broken zip')])->assertStatus(422);
        $this->assertSame(0, Product::count());
    }
}
