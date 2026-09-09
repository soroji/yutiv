<?php

namespace Tests\Feature\Menu;

use App\Enums\ExtensionOwnerType;
use App\Models\Menu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Plugins\Yutiv\AdminMenu\Plugin;
use Plugins\Yutiv\AdminMenu\Support\DeactivationToken;
use Plugins\Yutiv\AdminMenu\Support\MenuLayoutPlan;
use Tests\TestCase;

/**
 * YUTIV 쇼핑몰 관리자 메뉴 배치 통합 테스트.
 *
 * 검증 대상은 `plugins/_bundled/yutiv-admin_menu` 의 배치 계층이다. 이 플러그인은 기능을
 * 복제하지 않고 `sirsoft-ecommerce` 가 선언한 메뉴의 계층·순서·이름만 바꾼다.
 *
 * ── 로컬 실행 불가 안내 ─────────────────────────────────────────────────────
 * 이 파일은 프로젝트의 PHPUnit 관례를 따르지만 **작성 환경에서는 실행되지 않았다.**
 * 로컬 PHP 가 7.4 이고 `vendor/` 가 비어 있어 Laravel 부팅 자체가 불가능하기 때문이다.
 * 같은 불변식을 PHP 7.4 로도 돌아가는 독립 하네스가 실제로 실행 검증한다:
 *
 *     php tests/Menu/yutiv-admin-menu-check.php --verbose --preview
 *
 * 그 하네스는 이 테스트가 쓰는 것과 **같은 계획기**(MenuLayoutPlan)를 require 하므로
 * 로직이 갈라지지 않는다. 서버(PHP 8.2 + vendor)에서는 이 파일을 그대로 실행하면 된다.
 */
class YutivAdminMenuLayoutTest extends TestCase
{
    use RefreshDatabase;

    private MenuLayoutPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $layoutPath = base_path('plugins/_bundled/yutiv-admin_menu/config/menu-layout.json');
        if (! is_file($layoutPath)) {
            $this->markTestSkipped('yutiv-admin_menu 플러그인이 설치되어 있지 않습니다.');
        }
        $this->plan = MenuLayoutPlan::fromFile($layoutPath);

        $this->seedEcommerceMenus();
    }

    /**
     * 이커머스 모듈이 선언하는 형태 그대로 메뉴를 만든다 (부모 1 + 자식 11).
     */
    private function seedEcommerceMenus(): void
    {
        $parent = Menu::create([
            'name' => ['ko' => '이커머스', 'en' => 'Ecommerce'],
            'slug' => 'sirsoft-ecommerce',
            'url' => null,
            'icon' => 'fas fa-shopping-cart',
            'parent_id' => null,
            'order' => 40,
            'is_active' => true,
            'extension_type' => ExtensionOwnerType::Module,
            'extension_identifier' => 'sirsoft-ecommerce',
        ]);

        $children = [
            ['sirsoft-ecommerce-settings', '/admin/ecommerce/settings', 1],
            ['sirsoft-ecommerce-products', '/admin/ecommerce/products', 2],
            ['sirsoft-ecommerce-categories', '/admin/ecommerce/categories', 3],
            ['sirsoft-ecommerce-brands', '/admin/ecommerce/brands', 4],
            ['sirsoft-ecommerce-product-notices', '/admin/ecommerce/product-notices', 5],
            ['sirsoft-ecommerce-common-info', '/admin/ecommerce/common-info', 6],
            ['sirsoft-ecommerce-orders', '/admin/ecommerce/orders', 7],
            ['sirsoft-ecommerce-promotion-coupons', '/admin/ecommerce/promotion-coupons', 8],
            ['sirsoft-ecommerce-shipping-policies', '/admin/ecommerce/shipping-policies', 9],
            ['sirsoft-ecommerce-reviews', '/admin/ecommerce/reviews', 10],
            ['sirsoft-ecommerce-mileage-transactions', '/admin/ecommerce/mileage-transactions', 11],
        ];

        foreach ($children as [$slug, $url, $order]) {
            Menu::create([
                'name' => ['ko' => $slug, 'en' => $slug],
                'slug' => $slug,
                'url' => $url,
                'icon' => 'fas fa-circle',
                'parent_id' => $parent->id,
                'order' => $order,
                'is_active' => true,
                'extension_type' => ExtensionOwnerType::Module,
                'extension_identifier' => 'sirsoft-ecommerce',
            ]);
        }
    }

    /** 현재 메뉴 행을 계획기 입력 형태로 만든다. */
    private function rows(): array
    {
        return Menu::query()->orderBy('id')->get()->map(fn ($m) => [
            'id' => $m->id,
            'slug' => $m->slug,
            'name' => $m->name,
            'url' => $m->url,
            'icon' => $m->icon,
            'parent_id' => $m->parent_id,
            'order' => (int) $m->order,
            'user_overrides' => is_array($m->user_overrides) ? $m->user_overrides : [],
            'extension_type' => $m->extension_type instanceof ExtensionOwnerType
                ? $m->extension_type->value
                : $m->extension_type,
            'extension_identifier' => $m->extension_identifier,
        ])->all();
    }

    /** 계획을 DB 에 반영한다 (명령이 하는 일과 동일한 모델 경유 저장). */
    private function apply(): int
    {
        $result = $this->plan->planApply($this->rows());
        foreach ($result['changes'] as $slug => $change) {
            $menu = Menu::where('slug', $slug)->firstOrFail();
            foreach ($change['after'] as $field => $value) {
                $menu->{$field} = $value;
            }
            $menu->save();
        }

        // 명령과 동일한 보호 패스 — 코어 메뉴의 order 를 user_overrides 에 강제 마킹한다.
        // 이게 있어야 CoreUpdateService::syncCoreMenus() 가 순서를 덮어쓰지 않는다.
        foreach (array_keys($this->plan->coreOrders()) as $slug) {
            $menu = Menu::where('slug', $slug)->first();
            if (! $menu) {
                continue;
            }
            $overrides = is_array($menu->user_overrides) ? $menu->user_overrides : [];
            if (! in_array('order', $overrides, true)) {
                $overrides[] = 'order';
                $menu->user_overrides = $overrides;
                $menu->saveQuietly();
            }
        }

        return count($result['changes']);
    }

    /** 코어 메뉴 정의 (config('core.menus') 와 같은 모양, 최상위만). */
    private function coreDefinition(): array
    {
        $defs = [];
        $order = 0;
        foreach ([
            'admin-dashboard' => ['대시보드', '/admin', 'fas fa-tachometer-alt'],
            'admin-settings' => ['환경설정', '/admin/settings', 'fas fa-cog'],
            'admin-users' => ['사용자 관리', '/admin/users', 'fas fa-users'],
            'admin-notification-logs' => ['알림 로그', '/admin/notification-logs', 'fas fa-bell'],
            'admin-identity-logs' => ['본인인증 로그', '/admin/identity-logs', 'fas fa-id-card'],
            'admin-activity-logs' => ['활동 로그', '/admin/activity-logs', 'fas fa-history'],
            'admin-menus' => ['메뉴 관리', '/admin/menus', 'fas fa-bars'],
            'admin-roles' => ['역할 관리', '/admin/roles', 'fas fa-user-shield'],
            'admin-modules' => ['모듈 관리', '/admin/modules', 'fas fa-puzzle-piece'],
            'admin-plugins' => ['플러그인 관리', '/admin/plugins', 'fas fa-plug'],
            'admin-templates' => ['템플릿 관리', '/admin/templates', 'fas fa-paint-brush'],
            'admin-schedules' => ['스케쥴 관리', '/admin/schedules', 'fas fa-clock'],
        ] as $slug => [$ko, $url, $icon]) {
            $defs[] = [
                'name' => ['ko' => $ko, 'en' => $slug],
                'slug' => $slug,
                'url' => $url,
                'icon' => $icon,
                'order' => ++$order,
            ];
        }

        return $defs;
    }

    /** 코어 메뉴를 config 원본 순서 그대로 시드한다. */
    private function seedCoreMenus(): void
    {
        foreach ($this->coreDefinition() as $def) {
            Menu::create([
                'name' => $def['name'],
                'slug' => $def['slug'],
                'url' => $def['url'],
                'icon' => $def['icon'],
                'parent_id' => null,
                'order' => $def['order'],
                'is_active' => true,
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
            ]);
        }
    }

    public function test_적용_전에는_모든_쇼핑몰_메뉴가_이커머스_하위에_있다(): void
    {
        $parentId = Menu::where('slug', 'sirsoft-ecommerce')->value('id');

        $this->assertSame(11, Menu::where('parent_id', $parentId)->count());
        foreach (['orders', 'products', 'categories', 'promotion-coupons', 'reviews'] as $key) {
            $this->assertSame(
                $parentId,
                Menu::where('slug', "sirsoft-ecommerce-{$key}")->value('parent_id'),
                "{$key} 가 이커머스 하위가 아닙니다"
            );
        }
    }

    public function test_dry_run_은_DB_를_바꾸지_않는다(): void
    {
        $before = Menu::query()->orderBy('id')->get(['slug', 'parent_id', 'order'])->toArray();

        $result = $this->plan->planApply($this->rows()); // 계획만 세운다

        $this->assertNotEmpty($result['changes'], '변경 계획이 비어 있으면 이 테스트가 무의미합니다');
        $this->assertSame($before, Menu::query()->orderBy('id')->get(['slug', 'parent_id', 'order'])->toArray());
    }

    public function test_적용_후_자주_쓰는_5개_메뉴가_최상위가_된다(): void
    {
        $this->apply();

        $expected = [
            'sirsoft-ecommerce-orders' => 2,
            'sirsoft-ecommerce-products' => 3,
            'sirsoft-ecommerce-categories' => 4,
            'sirsoft-ecommerce-promotion-coupons' => 5,
            'sirsoft-ecommerce-reviews' => 6,
        ];

        foreach ($expected as $slug => $order) {
            $menu = Menu::where('slug', $slug)->firstOrFail();
            $this->assertNull($menu->parent_id, "{$slug} 가 최상위가 아닙니다");
            $this->assertSame($order, (int) $menu->order, "{$slug} 의 순서가 다릅니다");
        }
    }

    public function test_최상위_순서가_요청과_정확히_일치한다(): void
    {
        $this->seedCoreMenus();
        $this->apply();

        $expected = [
            1 => 'admin-dashboard',
            2 => 'sirsoft-ecommerce-orders',
            3 => 'sirsoft-ecommerce-products',
            4 => 'sirsoft-ecommerce-categories',
            5 => 'sirsoft-ecommerce-promotion-coupons',
            6 => 'sirsoft-ecommerce-reviews',
            7 => 'admin-users',
            8 => 'sirsoft-ecommerce',
            9 => 'admin-settings',
            10 => 'admin-notification-logs',
            11 => 'admin-identity-logs',
            12 => 'admin-activity-logs',
            13 => 'admin-menus',
            14 => 'admin-roles',
            15 => 'admin-modules',
            16 => 'admin-plugins',
            17 => 'admin-templates',
            18 => 'admin-schedules',
        ];

        $actual = Menu::whereNull('parent_id')->orderBy('order')->pluck('slug', 'order')->all();
        $this->assertSame($expected, $actual);
    }

    public function test_check_는_drift_를_감지한다(): void
    {
        $this->seedCoreMenus();
        $this->apply();
        $this->assertSame([], $this->plan->verify($this->rows()), '적용 직후에는 drift 가 없어야 합니다');

        // 누군가 메뉴를 다시 하위로 내린 상황
        $parentId = Menu::where('slug', 'sirsoft-ecommerce')->value('id');
        Menu::where('slug', 'sirsoft-ecommerce-orders')->update(['parent_id' => $parentId]);

        $this->assertNotEmpty($this->plan->verify($this->rows()), 'drift 를 감지하지 못했습니다');
    }

    public function test_코어_메뉴_재동기화_후에도_순서가_유지된다(): void
    {
        $this->seedCoreMenus();
        $this->apply();

        // CoreUpdateService::syncCoreMenus() 는 config 원본 순서를 들고 온다.
        // order 가 user_overrides 에 마킹돼 있으면 보존되어야 한다.
        $this->simulateSync($this->coreDefinition());

        $this->assertSame(7, (int) Menu::where('slug', 'admin-users')->value('order'));
        $this->assertSame(9, (int) Menu::where('slug', 'admin-settings')->value('order'));
        $this->assertSame([], $this->plan->verify($this->rows()));
    }

    public function test_코어_메뉴_재시드_정의에_목표_순서가_들어간다(): void
    {
        $filtered = $this->plan->rewriteCoreDefinition($this->coreDefinition());
        $orders = [];
        foreach ($filtered as $m) {
            $orders[$m['slug']] = $m['order'];
        }

        $this->assertSame(1, $orders['admin-dashboard']);
        $this->assertSame(7, $orders['admin-users']);
        $this->assertSame(9, $orders['admin-settings']);
        $this->assertSame(18, $orders['admin-schedules']);
    }
    public function test_저빈도_6개_메뉴가_쇼핑몰_설정_아래에_남는다(): void
    {
        $this->apply();

        $parent = Menu::where('slug', 'sirsoft-ecommerce')->firstOrFail();
        $this->assertNull($parent->parent_id);
        $this->assertSame('쇼핑몰 설정', $parent->name['ko']);
        $this->assertSame(6, Menu::where('parent_id', $parent->id)->count());
    }

    public function test_URL_permission_ownership_은_바뀌지_않는다(): void
    {
        $before = Menu::query()->orderBy('id')
            ->get(['slug', 'url', 'extension_type', 'extension_identifier'])->toArray();

        $this->apply();

        $after = Menu::query()->orderBy('id')
            ->get(['slug', 'url', 'extension_type', 'extension_identifier'])->toArray();

        $this->assertSame($before, $after, 'URL 또는 확장 소유권이 바뀌었습니다');
    }

    public function test_slug_는_전역_유일하고_순환이_없다(): void
    {
        $this->apply();

        $slugs = Menu::pluck('slug')->all();
        $this->assertSame(count($slugs), count(array_unique($slugs)));

        foreach (Menu::all() as $menu) {
            $seen = [];
            $cursor = $menu;
            while ($cursor->parent_id !== null) {
                $this->assertArrayNotHasKey($cursor->id, $seen, '메뉴 부모 사슬에 순환이 있습니다');
                $seen[$cursor->id] = true;
                $cursor = Menu::find($cursor->parent_id);
                $this->assertNotNull($cursor, '존재하지 않는 부모를 참조합니다');
            }
        }
    }

    public function test_같은_적용을_두_번_해도_결과가_같다(): void
    {
        $this->apply();
        $afterFirst = Menu::query()->orderBy('id')->get(['slug', 'parent_id', 'order'])->toArray();

        $secondChanges = $this->apply();

        $this->assertSame(0, $secondChanges, '두 번째 적용에서 변경이 발생했습니다 (멱등성 위반)');
        $this->assertSame($afterFirst, Menu::query()->orderBy('id')->get(['slug', 'parent_id', 'order'])->toArray());
    }

    public function test_모듈_재동기화_후에도_배치가_유지된다(): void
    {
        $this->apply();

        // 플러그인 리스너가 통과시키는 정의를 그대로 동기화한다.
        $definition = $this->plan->rewriteDefinition($this->moduleDefinition());
        $this->simulateSync($definition);

        foreach (['orders', 'products', 'categories', 'promotion-coupons', 'reviews'] as $key) {
            $this->assertNull(
                Menu::where('slug', "sirsoft-ecommerce-{$key}")->value('parent_id'),
                "재동기화 후 {$key} 가 다시 하위로 내려갔습니다"
            );
        }
    }

    /**
     * 확인 대상은 **재동기화의 효과**이지 비활성화의 효과가 아니다.
     * `plugin:deactivate` 자체는 DB 메뉴를 복원하지 않는다 — 비활성 상태에서 이커머스
     * 메뉴 재동기화가 실제로 실행돼야 원래 모듈 정의 계층이 다시 적용된다.
     * 정상 해제 순서는 `yutiv:admin-menu --rollback` → `plugin:deactivate` 이다.
     */
    public function test_플러그인_비활성_상태에서_이커머스_메뉴_재동기화_시_원래_계층으로_되돌아간다(): void
    {
        $this->apply();

        // 필터를 거치지 않은 원본 정의로 동기화 = 플러그인 비활성 상태의 재동기화
        $this->simulateSync($this->moduleDefinition());

        $parentId = Menu::where('slug', 'sirsoft-ecommerce')->value('id');
        $this->assertSame(11, Menu::where('parent_id', $parentId)->count());
    }

    public function test_최상위와_설정_메뉴는_4개_로케일_이름을_가진다(): void
    {
        $this->apply();

        $targets = array_merge(
            array_keys($this->plan->promotedSlugs()),
            ['sirsoft-ecommerce'],
            array_keys($this->plan->settingsChildSlugs())
        );

        foreach ($targets as $slug) {
            $name = Menu::where('slug', $slug)->value('name');
            $this->assertIsArray($name, "{$slug} 의 name 이 로케일 배열이 아닙니다");
            foreach (['ko', 'en', 'ja', 'zh-CN'] as $locale) {
                $this->assertNotEmpty($name[$locale] ?? null, "{$slug} 에 {$locale} 이름이 없습니다");
            }
        }
    }

    public function test_비활성화_토큰은_일회용이다(): void
    {
        $dir = storage_path('app/yutiv-admin-menu-test-'.uniqid());
        $token = DeactivationToken::inDirectory($dir);

        $this->assertFalse($token->exists(), '초기에는 토큰이 없어야 합니다');

        $this->assertTrue($token->issue(['snapshot' => 'snapshot-test.json', 'restored' => 3]));
        $this->assertTrue($token->exists());

        $payload = $token->consume();
        $this->assertIsArray($payload);
        $this->assertSame('snapshot-test.json', $payload['snapshot']);
        $this->assertFalse($token->exists(), 'consume() 후에는 파일이 남아 있으면 안 됩니다');

        // 한 번 만든 파일로 이후 비활성화를 반복 허용하면 안 된다.
        $this->assertNull($token->consume());

        @rmdir($dir);
    }

    public function test_토큰_revoke_는_있으면_지우고_없으면_false(): void
    {
        $dir = storage_path('app/yutiv-admin-menu-test-'.uniqid());
        $token = DeactivationToken::inDirectory($dir);

        $this->assertFalse($token->revoke(), '없는 토큰을 지웠다고 하면 안 됩니다');
        $token->issue([]);
        $this->assertTrue($token->revoke());
        $this->assertFalse($token->exists());

        @rmdir($dir);
    }

    public function test_apply_성공_시_기존_토큰이_제거된다(): void
    {
        $this->seedCoreMenus();

        $token = Plugin::deactivationToken();
        $token->issue(['snapshot' => 'stale.json']);
        $this->assertTrue($token->exists());

        $this->artisan('yutiv:admin-menu --apply')->assertExitCode(0);

        $this->assertFalse($token->exists(), '--apply 성공 후에는 허용 토큰이 남아 있으면 안 됩니다');
    }

    public function test_rollback_성공_시에만_토큰이_발급된다(): void
    {
        $this->seedCoreMenus();

        $this->artisan('yutiv:admin-menu --apply')->assertExitCode(0);
        $this->assertFalse(Plugin::deactivationToken()->exists());

        $this->artisan('yutiv:admin-menu --rollback')->assertExitCode(0);
        $this->assertTrue(Plugin::deactivationToken()->exists(), '복원·검증 성공 후에는 토큰이 발급돼야 합니다');

        Plugin::deactivationToken()->revoke();
    }

    public function test_rollback_부분_실패_시_토큰이_발급되지_않는다(): void
    {
        $this->seedCoreMenus();
        $this->artisan('yutiv:admin-menu --apply')->assertExitCode(0);

        // 스냅샷에 있던 메뉴 하나를 지워 부분 복원 상황을 만든다.
        Menu::where('slug', 'sirsoft-ecommerce-reviews')->delete();

        $this->artisan('yutiv:admin-menu --rollback')->assertExitCode(1);
        $this->assertFalse(
            Plugin::deactivationToken()->exists(),
            '부분 복원 상태에서 비활성화를 허용하면 되돌릴 수단이 사라집니다'
        );
    }

    public function test_배치가_적용된_상태에서는_비활성화가_차단된다(): void
    {
        $this->seedCoreMenus();
        $this->artisan('yutiv:admin-menu --apply')->assertExitCode(0);

        $this->expectException(\RuntimeException::class);
        (new Plugin)->deactivate();
    }

    public function test_토큰이_있으면_비활성화가_통과하고_토큰은_소비된다(): void
    {
        $this->seedCoreMenus();
        $this->artisan('yutiv:admin-menu --apply')->assertExitCode(0);
        $this->artisan('yutiv:admin-menu --rollback')->assertExitCode(0);

        $token = Plugin::deactivationToken();
        $this->assertTrue($token->exists());

        $this->assertTrue((new Plugin)->deactivate());
        $this->assertFalse($token->exists(), 'deactivate() 는 토큰을 소비하고 삭제해야 합니다');
    }

    /** 모듈이 선언하는 메뉴 정의 (테스트 시드와 같은 모양). */
    private function moduleDefinition(): array
    {
        $children = [];
        foreach ($this->rows() as $row) {
            if ($row['slug'] === 'sirsoft-ecommerce' || $row['extension_identifier'] !== 'sirsoft-ecommerce') {
                continue;
            }
            $children[] = [
                'name' => $row['name'],
                'slug' => $row['slug'],
                'url' => $row['url'],
                'icon' => $row['icon'],
                'order' => $row['order'],
            ];
        }

        return [[
            'name' => ['ko' => '이커머스', 'en' => 'Ecommerce'],
            'slug' => 'sirsoft-ecommerce',
            'url' => null,
            'icon' => 'fas fa-shopping-cart',
            'order' => 40,
            'children' => $children,
        ]];
    }

    /**
     * `ExtensionMenuSyncHelper::syncMenu()` 의 계약을 그대로 재현한다.
     * parent_id 는 항상 정의값, 나머지는 user_overrides 에 있으면 보존.
     */
    private function simulateSync(array $definition, ?int $parentId = null): void
    {
        foreach ($definition as $node) {
            $menu = Menu::where('slug', $node['slug'])->first();
            if (! $menu) {
                continue;
            }
            $overrides = $menu->user_overrides ?? [];

            $menu->parent_id = $parentId;
            foreach (['name', 'icon', 'order', 'url'] as $field) {
                if (! in_array($field, $overrides, true)) {
                    $menu->{$field} = $node[$field] ?? null;
                }
            }
            $menu->saveQuietly();

            if (! empty($node['children'])) {
                $this->simulateSync($node['children'], $menu->id);
            }
        }
    }
}
