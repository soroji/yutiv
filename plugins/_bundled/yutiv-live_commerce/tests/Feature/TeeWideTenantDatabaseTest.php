<?php

namespace Plugins\Yutiv\LiveCommerce\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Plugins\Yutiv\LiveCommerce\Database\Seeders\LiveTenantSeeder;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;
use Plugins\Yutiv\LiveCommerce\Support\TeeWidePresenter;
use Plugins\Yutiv\LiveCommerce\Tests\PluginTestCase;

/**
 * `live_tenants` 가 공개 채널의 **권위 소스**임을 고정한다.
 *
 * Phase 1-A 까지는 설정(`known_tenants`)이 정했다. 이제는 DB 가 정한다 —
 * 설정에 있어도 DB 에 active 행이 없으면 열리지 않는다.
 */
class TeeWideTenantDatabaseTest extends PluginTestCase
{
    protected function teeWideBootConfig(): ?array
    {
        return static::teeWideConfigValues();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureTeeWide();
        $this->bootPlugin();
        $this->attachHostGate();
    }

    // ── 스키마 ──────────────────────────────────────────────────────────────

    public function test_마이그레이션이_두_테이블을_만든다(): void
    {
        $this->assertTrue(Schema::hasTable('teewide_users'));
        $this->assertTrue(Schema::hasTable('live_tenants'));

        foreach (['id', 'uuid', 'email', 'password', 'name', 'nickname', 'status',
            'email_verified_at', 'last_login_at', 'remember_token', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('teewide_users', $column), 'teewide_users.'.$column.' 이 없습니다');
        }

        foreach (['id', 'uuid', 'slug', 'name', 'description', 'initials', 'status',
            'owner_user_id', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('live_tenants', $column), 'live_tenants.'.$column.' 이 없습니다');
        }
    }

    public function test_TeeWide_회원_테이블은_YUTIV_users_와_별개다(): void
    {
        // 같은 테이블을 쓰면 세션 경계가 데이터 레벨에서 무너진다.
        $this->assertTrue(Schema::hasTable('users'), 'YUTIV users 테이블 전제가 바뀌었습니다');
        $this->assertNotSame('users', (new \Plugins\Yutiv\LiveCommerce\Models\TeeWideUser)->getTable());
        $this->assertSame('teewide_users', (new \Plugins\Yutiv\LiveCommerce\Models\TeeWideUser)->getTable());
    }

    // ── 시더 ────────────────────────────────────────────────────────────────

    public function test_GolfIF_시더는_몇_번_실행해도_중복되지_않는다(): void
    {
        $seeder = new LiveTenantSeeder;

        $seeder->run();
        $seeder->run();
        $seeder->run();

        $this->assertSame(1, LiveTenant::query()->where('slug', 'golfif')->count());
    }

    public function test_시더_재실행이_UUID_를_바꾸지_않는다(): void
    {
        $before = LiveTenant::query()->where('slug', 'golfif')->value('uuid');
        $this->assertNotNull($before);

        (new LiveTenantSeeder)->run();

        $this->assertSame($before, LiveTenant::query()->where('slug', 'golfif')->value('uuid'),
            '외부에 노출되는 식별자가 재실행마다 바뀝니다');
    }

    public function test_시더가_회원_계정을_만들지_않는다(): void
    {
        (new LiveTenantSeeder)->run();

        // 기본 비밀번호를 가진 계정은 그 자체가 취약점이다.
        $this->assertSame(0, \Plugins\Yutiv\LiveCommerce\Models\TeeWideUser::query()->count());
        $this->assertNull(LiveTenant::query()->where('slug', 'golfif')->value('owner_user_id'));
    }

    public function test_GolfIF_표시값이_Phase_1A_와_같다(): void
    {
        $tenant = LiveTenant::query()->where('slug', 'golfif')->first();

        $this->assertNotNull($tenant);
        $this->assertSame('골프이프', $tenant->name);
        $this->assertSame('골프 용품과 라운드 준비물을 라이브로 소개하는 채널입니다.', $tenant->description);
        $this->assertSame('GI', $tenant->initials);
        $this->assertSame(LiveTenant::STATUS_ACTIVE, $tenant->status);
    }

    // ── 공개 판정 ───────────────────────────────────────────────────────────

    public function test_active_채널만_라이브_홈에_표시된다(): void
    {
        $this->seedLiveTenant(['slug' => 'draft-shop', 'name' => '준비중 채널', 'status' => LiveTenant::STATUS_DRAFT]);
        $this->seedLiveTenant(['slug' => 'stopped-shop', 'name' => '정지된 채널', 'status' => LiveTenant::STATUS_SUSPENDED]);
        $this->seedLiveTenant(['slug' => 'open-shop', 'name' => '열린 채널', 'status' => LiveTenant::STATUS_ACTIVE]);

        $response = $this->get('http://'.self::LIVE_HOST.'/');

        $response->assertOk();
        $response->assertSee('골프이프');
        $response->assertSee('열린 채널');
        $response->assertDontSee('준비중 채널');
        $response->assertDontSee('정지된 채널');
    }

    public function test_active_채널은_200_이고_이름이_DB_값이다(): void
    {
        $this->seedLiveTenant(['slug' => 'open-shop', 'name' => '열린 채널', 'description' => 'DB 에서 온 소개']);

        $response = $this->get('http://'.self::LIVE_HOST.'/open-shop');

        $response->assertOk();
        $response->assertSee('열린 채널');
        $response->assertSee('DB 에서 온 소개');
    }

    public function test_draft_와_suspended_와_미등록은_모두_404(): void
    {
        $this->seedLiveTenant(['slug' => 'draft-shop', 'status' => LiveTenant::STATUS_DRAFT]);
        $this->seedLiveTenant(['slug' => 'stopped-shop', 'status' => LiveTenant::STATUS_SUSPENDED]);

        foreach (['draft-shop', 'stopped-shop', 'never-existed'] as $slug) {
            $this->get('http://'.self::LIVE_HOST.'/'.$slug)->assertNotFound($slug.' 가 404 가 아닙니다');
        }
    }

    public function test_설정에만_있고_DB_에_없는_slug_는_열리지_않는다(): void
    {
        // 설정에는 golfif 가 있지만 DB 행을 지우면 닫혀야 한다 — DB 가 권위 소스다.
        LiveTenant::query()->where('slug', 'golfif')->delete();

        $this->assertContains('golfif', \Plugins\Yutiv\LiveCommerce\Support\TeeWideConfig::knownTenants(),
            '이 테스트의 전제(설정에 golfif 존재)가 깨졌습니다');

        $this->get('http://'.self::LIVE_HOST.'/golfif')->assertNotFound();
    }

    public function test_status_를_내리면_즉시_닫힌다(): void
    {
        $this->get('http://'.self::LIVE_HOST.'/golfif')->assertOk();

        LiveTenant::query()->where('slug', 'golfif')->update(['status' => LiveTenant::STATUS_SUSPENDED]);

        $this->get('http://'.self::LIVE_HOST.'/golfif')->assertNotFound();
    }

    // ── fail-closed ─────────────────────────────────────────────────────────

    public function test_조회_실패시_채널을_열지_않는다(): void
    {
        // 테이블을 없애 조회를 실패시킨다. fail-open 이면 여기서 slug 가 열린다.
        Schema::drop('live_tenants');

        $this->assertSame([], TeeWidePresenter::publicTenants(), '조회 실패인데 목록이 비어 있지 않습니다');
        $this->assertNull(TeeWidePresenter::publicChannel('golfif'), '조회 실패인데 채널이 열렸습니다');

        $this->get('http://'.self::LIVE_HOST.'/golfif')->assertNotFound();
    }
}
