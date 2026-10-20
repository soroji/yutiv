<?php

namespace Plugins\Yutiv\LiveCommerce;

use App\Extension\AbstractPlugin;
use App\Extension\ExtensionManager;
use Plugins\Yutiv\LiveCommerce\Http\Middleware\TeeWideHostGate;
use Plugins\Yutiv\LiveCommerce\Support\TeeWideConfig;

/**
 * 업체별 독립 라이브 운영 플러그인.
 * 기존 TeeWide 호스트·세션 경계를 유지하며 본사 관제와 업체 운영을 제공합니다.
 * 기본 비활성: 설치·활성화 외에 TEEWIDE_ENABLED 명시 설정이 필요합니다.
 */
class Plugin extends AbstractPlugin
{
    public function install(): bool
    {
        // 초기 시드 migration은 공식 설치의 오토로드 파일 갱신보다 먼저 실행됩니다.
        ExtensionManager::registerExtensionAutoloadPaths('plugins', $this->getIdentifier());

        return parent::install();
    }

    /**
     * 호스트 게이트 — TeeWide 호스트에서 YUTIV 콘텐츠가 나가지 않게 막는다.
     *
     * 코어의 확장 미들웨어 자가 게이트를 쓴다. `targets: ['everything']` 은 모든 라우트를
     * 대상으로 삼는다는 뜻이고, `before_core` 는 코어 전처리보다 먼저 실행하라는 뜻이다.
     * 같은 조합을 sirsoft-gdpr 이 이미 쓰고 있다 (그 plugin.php:357-367).
     *
     * 코어 게이트는 **활성 플러그인의 선언만** 수집하므로, 이 플러그인이 비활성이면
     * 미들웨어가 아예 실행되지 않는다. 활성이어도 `TEEWIDE_ENABLED=false` 면
     * 미들웨어 자신이 즉시 통과시킨다 (완전한 no-op).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getMiddleware(): array
    {
        return [
            [
                'class' => TeeWideHostGate::class,
                'groups' => ['web', 'api'],
                'timing' => 'before_core',
                'targets' => ['everything'],
            ],
        ];
    }

    /**
     * 활성 확장의 본사 라이브 관리 메뉴.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAdminMenus(): array
    {
        return [['name' => ['ko' => '라이브', 'en' => 'Live'], 'slug' => 'live-management', 'url' => '/admin/plugins/yutiv-live_commerce/office', 'icon' => 'fas fa-video', 'order' => 65]];
    }

    /**
     * 본사 관리 권한. 판매자 직원 역할과 별개입니다.
     *
     * @return array<string, mixed>
     */
    public function getPermissions(): array
    {
        return ['name' => ['ko' => '라이브', 'en' => 'Live'], 'description' => ['ko' => '본사 라이브 업체 관리', 'en' => 'Live management'],
            'categories' => [['identifier' => 'management', 'name' => ['ko' => '업체 관리', 'en' => 'Tenant management'],
                'permissions' => [['action' => 'manage', 'name' => ['ko' => '라이브 관리', 'en' => 'Manage live'], 'description' => ['ko' => '업체·계정·도메인 관제', 'en' => 'Manage tenants, accounts and domains']]]]]];
    }

    public function getSchedules(): array
    {
        return [['command' => 'live:maintain --batch=100 --seconds=45', 'schedule' => 'everyMinute', 'description' => '라이브 예약 만료 및 인센티브 대사']];
    }

    /**
     * 현재 설정 요약 (플러그인 상세 화면용).
     *
     * 비밀값을 담지 않는다. 세션 쿠키 **이름** 은 비밀이 아니지만 세션 ID 는 노출하지 않는다.
     *
     * @return array<string, mixed>
     */
    public function getConfigValues(): array
    {
        return [
            'enabled' => TeeWideConfig::enabled(),
            'hosts_configured' => TeeWideConfig::hostsConfigured(),
            'active' => TeeWideConfig::active(),
            'root_host' => TeeWideConfig::rootHost(),
            'live_host' => TeeWideConfig::liveHost(),
            'session_cookie' => TeeWideConfig::sessionCookie(),
            'session_domain' => TeeWideConfig::sessionDomain(),
            'block_status' => TeeWideConfig::blockStatus(),
            'diagnostics_enabled' => TeeWideConfig::diagnosticsEnabled(),
            'known_tenants' => TeeWideConfig::knownTenants(),
        ];
    }
}
