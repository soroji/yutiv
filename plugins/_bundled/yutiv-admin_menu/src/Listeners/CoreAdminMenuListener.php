<?php

namespace Plugins\Yutiv\AdminMenu\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use Illuminate\Support\Facades\Log;
use Plugins\Yutiv\AdminMenu\Plugin;

/**
 * 코어 메뉴 정의에 YUTIV 목표 순서를 얹는 필터 리스너.
 *
 * 구독 훅: `core.menus.config`
 *   `CoreAdminMenuSeeder::createCoreMenus()` 가 `config('core.menus')` 를 읽은 직후
 *   통과시키는 필터다 (database/seeders/CoreAdminMenuSeeder.php:57).
 *
 * ── 왜 이 리스너가 필요한가 ─────────────────────────────────────────────────
 * 코어 메뉴 순서를 지키는 경로는 두 개이고 성격이 다르다.
 *
 *   1) `CoreUpdateService::syncCoreMenus()` — 코어 업데이트 경로.
 *      `ExtensionMenuSyncHelper` 를 쓰므로 `order` 가 `user_overrides` 에 마킹돼
 *      있으면 **보존**된다. `yutiv:admin-menu --apply` 가 모델 경유 저장으로 마킹한다.
 *      (이 경로는 `config('core.menus')` 를 필터 없이 읽으므로 이 리스너가 관여하지 않는다 —
 *       CoreUpdateService::getCoreMenuDefinitions() 확인.)
 *
 *   2) `CoreAdminMenuSeeder` — 코어 메뉴 **삭제 후 재생성**.
 *      새 행이라 `user_overrides` 가 비어 있어 1) 의 보호가 통하지 않는다.
 *      대신 이 필터를 타므로, 여기서 순서를 넣어 두면 재시드 직후부터 목표 순서가 된다.
 *
 * 즉 두 경로를 각각 다른 수단으로 덮는다. 어느 쪽도 요청한 1~8 순서를 무너뜨리지 않는다.
 *
 * ── 주의 ────────────────────────────────────────────────────────────────────
 * 재시드는 코어 메뉴 행을 지웠다 다시 만들기 때문에 `menu_permissions` 가
 * cascade 로 함께 사라진다 (이 플러그인이 아니라 시더 자체의 동작이다).
 * 재시드 후에는 `yutiv:admin-menu --check` 가 "보호 표시 없음" drift 를 보고하며,
 * `--apply` 를 한 번 더 실행하면 `user_overrides` 마킹이 복구된다.
 */
class CoreAdminMenuListener implements HookListenerInterface
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.menus.config' => [
                'method' => 'rewriteCoreMenus',
                'priority' => 50,
                'type' => 'filter',
            ],
        ];
    }

    /**
     * @param  mixed  ...$args
     */
    public function handle(...$args): void
    {
        // 단일 필터 구독 — method 키로 rewriteCoreMenus 에 직접 연결된다.
    }

    /**
     * 코어 메뉴 정의의 order 만 목표값으로 바꾼다.
     *
     * 실패해도 원본을 그대로 돌려준다 — 배치 하나 때문에 코어 메뉴 시딩이 깨지면 안 된다.
     *
     * @param  mixed  $menus  `config('core.menus')` (앞선 필터를 거친 상태)
     * @return array
     */
    public function rewriteCoreMenus($menus): array
    {
        if (! is_array($menus) || $menus === []) {
            return is_array($menus) ? $menus : [];
        }

        try {
            return Plugin::layoutPlan()->rewriteCoreDefinition($menus);
        } catch (\Throwable $e) {
            Log::warning('yutiv-admin_menu: 코어 메뉴 순서 적용 실패 — 코어 기본 순서를 그대로 사용합니다', [
                'error' => $e->getMessage(),
            ]);

            return $menus;
        }
    }
}
