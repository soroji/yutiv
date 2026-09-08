<?php

namespace Tests\Feature\Settings;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Enums\ExtensionOwnerType;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * `general.site_description` 의 로케일별 저장·해석 계약을 고정합니다.
 *
 * 증상: 브라우저 로케일을 zh-CN 으로 바꾸면 메뉴와 언어팩은 중국어로 바뀌는데, 홈 환영
 * 카드의 사이트 소개만 한국어로 남았습니다. 이 설정이 단일 string 이고 레이아웃이
 * `{{_global.settings.general.site_description}}` 로 그 원문을 그대로 출력했기 때문입니다.
 *
 * 이 값은 한 곳에서 입력되지만 네 지점을 지나야 동작합니다 —
 * 검증(SaveSettingsRequest), 저장(general 카테고리), 관리자 재로드(로케일 맵 정규화),
 * 프론트 노출(현재 로케일 문자열로 축약). 어느 한 지점이 빠지면 "저장은 되는데 화면은
 * 그대로"이거나 배열이 그대로 새어 `"Array"` 가 렌더되고, 둘 다 예외 없이 조용히 지나갑니다.
 *
 * @effects site_description_accepts_locale_map
 * @effects site_description_resolves_per_locale
 * @effects site_description_legacy_string_preserved
 */
class SiteDescriptionLocalizationTest extends TestCase
{
    use RefreshDatabase;

    private const LEGACY = '레거시 한국어 사이트 설명';

    /**
     * general 카테고리에 값을 직접 심습니다 (저장 경로를 거치지 않는 사전 상태 구성).
     *
     * @param  mixed  $value  site_description 값 (string 또는 로케일 맵)
     */
    private function seedSiteDescription(mixed $value): void
    {
        $repo = app(ConfigRepositoryInterface::class);
        $general = $repo->getCategory('general');
        $general['site_description'] = $value;
        // saveCategory 가 저장소 캐시(전체 맵 + 카테고리)를 함께 비우므로,
        // 같은 싱글톤을 쓰는 SettingsService 도 곧바로 새 값을 읽는다.
        $repo->saveCategory('general', $general);
    }

    /**
     * 프론트엔드에 노출되는 site_description 을 반환합니다.
     */
    private function frontendDescription(string $locale): mixed
    {
        App::setLocale($locale);

        return app(SettingsService::class)->getFrontendSettings()['general']['site_description'] ?? null;
    }

    /**
     * 설정 저장 권한을 가진 관리자와 토큰을 만듭니다.
     *
     * @return array{0: User, 1: string}
     */
    private function makeAdmin(): array
    {
        $user = User::factory()->create(['password' => Hash::make('password123')]);

        $permissionIds = [];
        foreach (['core.settings.read', 'core.settings.update'] as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            );
            $permissionIds[] = $permission->id;
        }

        $adminBaseRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'description' => json_encode(['ko' => '시스템 관리자', 'en' => 'System Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        $scopedRole = Role::create([
            'identifier' => 'admin_sitedesc_'.uniqid(),
            'name' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'description' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'is_active' => true,
        ]);
        $scopedRole->permissions()->sync($permissionIds);

        $user->roles()->attach($adminBaseRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($scopedRole->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $user = $user->fresh();

        return [$user, $user->createToken('site-desc-token')->plainTextToken];
    }

    // ── G-1 / G-2 : 레거시 string 호환 ────────────────────────────────────────────

    /**
     * 기존에 저장된 단일 string 을 읽을 수 있어야 합니다 (기준 로케일 화면).
     *
     * @effects site_description_legacy_string_preserved
     */
    public function test_legacy_string_is_readable_on_base_locale(): void
    {
        $this->seedSiteDescription(self::LEGACY);

        $this->assertSame(self::LEGACY, $this->frontendDescription('ko'));
    }

    /**
     * 레거시 string 은 관리자 조회에서 기준 로케일 한 칸에만 담겨야 합니다.
     *
     * 모든 로케일에 복제하면 중국어 화면에 한국어가 그대로 남아, 고치려는 증상이
     * 데이터에 굳습니다. 그래서 복제하지 않는다는 것 자체가 계약입니다.
     *
     * @effects site_description_legacy_string_preserved
     */
    public function test_legacy_string_is_anchored_to_base_locale_only(): void
    {
        $this->seedSiteDescription(self::LEGACY);

        $loaded = app(SettingsService::class)->getAllSettings()['general']['site_description'];

        $this->assertIsArray($loaded, '관리자 화면은 로케일 맵을 받아야 편집 컴포넌트가 값을 표시한다');
        $this->assertSame(['ko' => self::LEGACY], $loaded);
        $this->assertArrayNotHasKey('zh-CN', $loaded, '레거시 값을 다른 로케일로 복제하면 안 된다');
        $this->assertArrayNotHasKey('en', $loaded);
        $this->assertArrayNotHasKey('ja', $loaded);
    }

    /**
     * 레거시 string 은 기준 로케일이 아닌 화면에서 노출되지 않아야 합니다.
     *
     * 이것이 이번 수정의 핵심입니다 — 중국어 화면의 한국어 잔존이 사라지고,
     * 레이아웃의 `$t:home.welcome_description` 폴백이 실제로 쓰이게 됩니다.
     *
     * @effects site_description_resolves_per_locale
     */
    public function test_legacy_string_does_not_leak_into_other_locales(): void
    {
        $this->seedSiteDescription(self::LEGACY);

        foreach (['en', 'ja', 'zh-CN'] as $locale) {
            $this->assertSame('', $this->frontendDescription($locale), "[$locale] 기준 로케일 원문이 새면 안 된다");
        }
    }

    // ── G-3 / G-9 : 로케일 맵 저장 ────────────────────────────────────────────────

    /**
     * 로케일 맵 저장이 성공하고 그대로 영속되어야 합니다.
     *
     * @effects site_description_accepts_locale_map
     */
    public function test_locale_map_is_saved_and_persisted(): void
    {
        [, $token] = $this->makeAdmin();

        $map = [
            'ko' => '한국어 설명',
            'en' => 'English description',
            'ja' => '日本語の説明',
            'zh-CN' => '中文说明',
        ];

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/admin/settings', [
                '_tab' => 'general',
                'general' => [
                    'site_name' => 'YUTIV',
                    'site_url' => 'https://example.com',
                    'admin_email' => 'admin@example.com',
                    'site_description' => $map,
                ],
            ])
            ->assertOk();

        $stored = app(ConfigRepositoryInterface::class)->get('general.site_description');

        $this->assertSame($map, $stored);
    }

    /**
     * 사이트 설명을 다국어로 저장해도 다른 일반 설정이 회귀하지 않아야 합니다.
     *
     * @effects site_description_accepts_locale_map
     */
    public function test_other_general_settings_survive_localized_save(): void
    {
        [, $token] = $this->makeAdmin();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/admin/settings', [
                '_tab' => 'general',
                'general' => [
                    'site_name' => 'YUTIV',
                    'site_url' => 'https://yutiv.example.com',
                    'admin_email' => 'ops@example.com',
                    'timezone' => 'Asia/Seoul',
                    'site_description' => ['ko' => '가', 'zh-CN' => '甲'],
                ],
            ])
            ->assertOk();

        $general = app(ConfigRepositoryInterface::class)->getCategory('general');

        $this->assertSame('YUTIV', $general['site_name']);
        $this->assertSame('https://yutiv.example.com', $general['site_url']);
        $this->assertSame('ops@example.com', $general['admin_email']);
        $this->assertSame('Asia/Seoul', $general['timezone']);
    }

    /**
     * 레거시 string 을 그대로 되돌려 보내는 저장도 계속 통과해야 합니다.
     *
     * 다른 탭/필드만 고치는 저장에서 기존 string 이 그대로 실려 오므로, 배열만 허용하면
     * 사이트 설명을 건드리지 않은 저장까지 422 로 막힙니다.
     *
     * @effects site_description_legacy_string_preserved
     */
    public function test_legacy_string_payload_is_still_accepted(): void
    {
        [, $token] = $this->makeAdmin();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/admin/settings', [
                '_tab' => 'general',
                'general' => [
                    'site_name' => 'YUTIV',
                    'site_url' => 'https://example.com',
                    'admin_email' => 'admin@example.com',
                    'site_description' => self::LEGACY,
                ],
            ])
            ->assertOk();

        $this->assertSame(self::LEGACY, app(ConfigRepositoryInterface::class)->get('general.site_description'));
    }

    // ── G-4 / G-5 : 검증 ──────────────────────────────────────────────────────────

    /**
     * 로케일별 값에 max:500 이 적용되어야 합니다.
     *
     * @effects site_description_accepts_locale_map
     */
    public function test_locale_value_longer_than_500_is_rejected(): void
    {
        [, $token] = $this->makeAdmin();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/admin/settings', [
                '_tab' => 'general',
                'general' => [
                    'site_name' => 'YUTIV',
                    'site_url' => 'https://example.com',
                    'admin_email' => 'admin@example.com',
                    'site_description' => ['ko' => str_repeat('가', 501)],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['general.site_description']);
    }

    /**
     * 경계값 500자는 통과해야 합니다 (off-by-one 방지).
     *
     * @effects site_description_accepts_locale_map
     */
    public function test_locale_value_of_exactly_500_is_accepted(): void
    {
        [, $token] = $this->makeAdmin();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/admin/settings', [
                '_tab' => 'general',
                'general' => [
                    'site_name' => 'YUTIV',
                    'site_url' => 'https://example.com',
                    'admin_email' => 'admin@example.com',
                    'site_description' => ['ko' => str_repeat('가', 500)],
                ],
            ])
            ->assertOk();
    }

    /**
     * 지원 로케일 목록 밖의 키는 거부되어야 합니다.
     *
     * 허용하면 임의 중첩 키가 설정 파일에 그대로 굳고, 어떤 화면도 그 값을 읽지 않아
     * 조용히 쌓이기만 합니다.
     *
     * @effects site_description_accepts_locale_map
     */
    public function test_unsupported_locale_key_is_rejected(): void
    {
        [, $token] = $this->makeAdmin();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/admin/settings', [
                '_tab' => 'general',
                'general' => [
                    'site_name' => 'YUTIV',
                    'site_url' => 'https://example.com',
                    'admin_email' => 'admin@example.com',
                    'site_description' => ['ko' => '설명', 'xx-YY' => 'bogus'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['general.site_description']);
    }

    /**
     * 로케일 값 자리에 중첩 구조가 오면 거부되어야 합니다.
     *
     * @effects site_description_accepts_locale_map
     */
    public function test_nested_structure_in_locale_value_is_rejected(): void
    {
        [, $token] = $this->makeAdmin();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/admin/settings', [
                '_tab' => 'general',
                'general' => [
                    'site_name' => 'YUTIV',
                    'site_url' => 'https://example.com',
                    'admin_email' => 'admin@example.com',
                    'site_description' => ['ko' => ['deep' => 'value']],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['general.site_description']);
    }

    // ── G-6 / G-7 : 로케일별 해석 ────────────────────────────────────────────────

    /**
     * 각 로케일 화면에 그 로케일의 설명이 전달되어야 합니다.
     *
     * @effects site_description_resolves_per_locale
     */
    public function test_each_locale_receives_its_own_description(): void
    {
        $map = [
            'ko' => '한국어 설명',
            'en' => 'English description',
            'ja' => '日本語の説明',
            'zh-CN' => '中文说明',
        ];
        $this->seedSiteDescription($map);

        foreach ($map as $locale => $expected) {
            $this->assertSame($expected, $this->frontendDescription($locale), "[$locale] 해당 로케일 값이 와야 한다");
        }
    }

    /**
     * 요청 로케일 값이 비어 있으면 폴백 없이 빈 문자열이어야 합니다.
     *
     * 여기서 ko 로 폴백하면 중국어 화면에 한국어가 남는 증상이 그대로 재현됩니다.
     * 빈 문자열을 받은 레이아웃이 `$t:home.welcome_description` 으로 넘어가야 합니다.
     *
     * @effects site_description_resolves_per_locale
     */
    public function test_missing_locale_value_falls_through_to_empty_string(): void
    {
        $this->seedSiteDescription(['ko' => '한국어 설명', 'zh-CN' => '']);

        $this->assertSame('', $this->frontendDescription('zh-CN'), '빈 값은 ko 로 폴백하면 안 된다');
        $this->assertSame('', $this->frontendDescription('ja'), '키 자체가 없어도 폴백하면 안 된다');
        $this->assertSame('한국어 설명', $this->frontendDescription('ko'));
    }

    // ── G-8 : 배열 노출 차단 ──────────────────────────────────────────────────────

    /**
     * 로케일 맵이 렌더링 경로로 그대로 새지 않아야 합니다.
     *
     * `_global.settings` 는 사용자 템플릿의 환영 카드·Footer 와 SEO 봇 렌더가 그대로
     * 출력합니다. 배열이 그대로 나가면 `(string)` 캐스팅에서 `"Array"` 가 찍힙니다.
     *
     * @effects site_description_resolves_per_locale
     */
    public function test_locale_map_never_reaches_frontend_as_array(): void
    {
        $this->seedSiteDescription([
            'ko' => '한국어 설명',
            'en' => 'English description',
            'ja' => '日本語の説明',
            'zh-CN' => '中文说明',
        ]);

        foreach (['ko', 'en', 'ja', 'zh-CN'] as $locale) {
            $value = $this->frontendDescription($locale);
            $this->assertIsString($value, "[$locale] 프론트에는 항상 문자열이 나가야 한다");
            $this->assertNotSame('Array', $value);
        }
    }

    /**
     * site_name 도 동일하게 배열이 새지 않아야 합니다.
     *
     * site_name 은 이미 코어 곳곳(`config('app.name')`, SEO og:site_name)이 다국어 배열을
     * 상정하고 방어하는데, 프론트 노출 경로만 그 방어가 없었습니다. 다만 사이트 이름은
     * 어느 언어 화면에서도 무언가는 보여야 하므로 폴백을 허용합니다 (site_description 과 반대).
     *
     * @effects site_description_resolves_per_locale
     */
    public function test_site_name_locale_map_is_resolved_with_fallback(): void
    {
        $repo = app(ConfigRepositoryInterface::class);
        $general = $repo->getCategory('general');
        $general['site_name'] = ['ko' => '유티브', 'en' => 'YUTIV'];
        $repo->saveCategory('general', $general);

        App::setLocale('en');
        $this->assertSame('YUTIV', app(SettingsService::class)->getFrontendSettings()['general']['site_name']);

        // ja 값이 없어도 사이트 이름은 비어서는 안 된다 — 기준 로케일로 폴백한다.
        App::setLocale('ja');
        $this->assertSame('유티브', app(SettingsService::class)->getFrontendSettings()['general']['site_name']);
    }

    /**
     * 설정이 비어 있으면 빈 문자열이어야 합니다 (레이아웃의 `||` 폴백 조건).
     *
     * @effects site_description_resolves_per_locale
     */
    public function test_empty_setting_yields_empty_string(): void
    {
        $this->seedSiteDescription('');

        $this->assertSame('', $this->frontendDescription('ko'));
        $this->assertSame('', $this->frontendDescription('zh-CN'));
    }
}
