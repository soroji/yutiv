<?php

namespace Plugins\Yutiv\AdminMenu\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Plugins\Yutiv\AdminMenu\Plugin;

/**
 * 이커머스 관리자 메뉴 정의를 YUTIV 배치로 다시 그리는 필터 리스너.
 *
 * 구독 훅: `module.sirsoft-ecommerce.admin_menus.translations`
 *   `ModuleManager::createModuleMenus()` 가 `ExtensionMenuSyncHelper` 로 넘기기 **직전에**
 *   메뉴 배열 전체를 통과시키는 필터다. 여기서 정의를 다시 그리면 공식 동기화 경로가
 *   우리 배치를 그대로 DB 에 기록하므로, 모듈 설치·업데이트·재활성화 때마다 배치가
 *   자동으로 재적용된다 (parent_id 는 동기화가 항상 정의값으로 덮어쓰기 때문에,
 *   정의를 바꾸는 이 방법만이 업데이트에 안전하다).
 *
 * 훅 이름이 `.translations` 인 것은 이 필터의 최초 용도가 언어팩의 로케일 주입이기
 * 때문이다. 코어가 제공하는 **동기화 직전 유일한 메뉴 필터**라 여기에 붙었고, 우리도
 * name 로케일(ja/zh-CN)을 함께 채우므로 용도에서 크게 벗어나지 않는다.
 *
 * priority 는 언어팩 주입(기본 우선순위)보다 **뒤**에 오도록 크게 잡는다. 언어팩이 먼저
 * ko/en 외 로케일을 채우고, 그 결과 위에 우리가 최상위 메뉴 이름을 덧씌운다.
 */
class EcommerceAdminMenuListener implements HookListenerInterface
{
    /**
     * 구독할 훅 목록.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'module.sirsoft-ecommerce.admin_menus.translations' => [
                'method' => 'rewriteAdminMenus',
                'priority' => 50,
                'type' => 'filter',
            ],
        ];
    }

    /**
     * HookListenerInterface 기본 핸들러 — 단일 필터 구독이라 호출되지 않는다.
     *
     * @param  mixed  ...$args
     */
    public function handle(...$args): void
    {
        // 등록은 method 키로 rewriteAdminMenus 에 직접 연결된다.
    }

    /**
     * 모듈의 관리자 메뉴 정의를 YUTIV 배치로 다시 그린다.
     *
     * 실패해도 원본 정의를 그대로 돌려준다. 배치 하나 때문에 모듈 설치·업데이트 전체가
     * 깨지면 안 된다 — 그 경우 메뉴는 모듈 기본 계층으로 남고 로그에 이유가 남는다.
     *
     * @param  mixed  $menus  모듈이 선언한 메뉴 배열 (앞선 필터를 거친 상태)
     * @return array 다시 그린 메뉴 배열
     */
    public function rewriteAdminMenus($menus): array
    {
        if (! is_array($menus) || $menus === []) {
            return is_array($menus) ? $menus : [];
        }

        try {
            return Plugin::layoutPlan()->rewriteDefinition($menus);
        } catch (\Throwable $e) {
            Log::warning('yutiv-admin_menu: 메뉴 배치 재작성 실패 — 모듈 기본 계층을 그대로 사용합니다', [
                'error' => $e->getMessage(),
            ]);

            return $menus;
        }
    }
}
