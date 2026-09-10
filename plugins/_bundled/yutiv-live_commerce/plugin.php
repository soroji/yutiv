<?php

namespace Plugins\Yutiv\LiveCommerce;

use App\Extension\AbstractPlugin;
use Plugins\Yutiv\LiveCommerce\Http\Middleware\TeeWideHostGate;
use Plugins\Yutiv\LiveCommerce\Support\TeeWideConfig;

/**
 * TeeWide 라이브커머스 (Phase 0 기술 스파이크).
 *
 * ── 이 단계의 범위 ──────────────────────────────────────────────────────────
 * 라이브커머스 기능을 만들지 않는다. 아래 네 가지를 **실제 코드와 자동 테스트로**
 * 증명하는 것이 전부다.
 *
 *   1. yutiv.com 과 teewide.com / live.teewide.com 의 라우트 격리
 *   2. yutiv.com 세션과 TeeWide 세션의 완전한 분리
 *   3. teewide.com 과 live.teewide.com 사이의 TeeWide 세션 공유
 *   4. config:cache / route:cache 및 플러그인 비활성 상태의 안전성
 *
 * 회원가입·주문·상품·재고·엑셀은 Phase 1 이후다.
 *
 * ── 기본 비활성 ─────────────────────────────────────────────────────────────
 * `TEEWIDE_ENABLED` 기본값이 false 다. 플러그인을 번들 상태로 두거나 설치·활성화만
 * 해도 기존 yutiv.com 은 아무 영향을 받지 않는다. 라우트도 미들웨어도 붙지 않는다.
 */
class Plugin extends AbstractPlugin
{
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
     * Phase 0 은 관리자 메뉴를 만들지 않는다.
     *
     * 메뉴·화면은 Phase 3(tenant) 이후에 붙인다. 지금 만들면 비활성 상태에서도
     * 관리자 메뉴가 보이는 부작용만 생긴다.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAdminMenus(): array
    {
        return [];
    }

    /**
     * Phase 0 은 자체 권한을 선언하지 않는다.
     *
     * @return array<string, mixed>
     */
    public function getPermissions(): array
    {
        return [];
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
