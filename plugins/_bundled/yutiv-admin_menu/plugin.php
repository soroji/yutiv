<?php

namespace Plugins\Yutiv\AdminMenu;

use App\Extension\AbstractPlugin;
use Illuminate\Support\Facades\Log;
use Plugins\Yutiv\AdminMenu\Listeners\CoreAdminMenuListener;
use Plugins\Yutiv\AdminMenu\Listeners\EcommerceAdminMenuListener;
use Plugins\Yutiv\AdminMenu\Support\DeactivationToken;
use Plugins\Yutiv\AdminMenu\Support\MenuLayoutPlan;

/**
 * YUTIV 쇼핑몰 관리자 메뉴 배치 플러그인.
 *
 * 하는 일은 **메뉴 정보구조 변경 하나**다. 상품·주문·쿠폰 같은 기능은 전부
 * `sirsoft-ecommerce` 모듈이 제공하며 이 플러그인은 그 화면을 복제하지도, 새 URL 로
 * 감싸지도 않는다. 모듈이 선언한 메뉴의 **계층(parent)과 순서(order), 이름(name)** 만
 * 다시 그린다.
 *
 * ── 왜 플러그인인가 (조사 결과에 따른 선택) ─────────────────────────────────
 * `ExtensionMenuSyncHelper::syncMenu()` 는 `parent_id` 를 **항상** 확장 정의값으로
 * 덮어쓴다. `Menu::$trackableFields` 에 `parent_id` 가 없어 `user_overrides` 로도
 * 보호되지 않는다. 즉 관리자 화면이나 DB 에서 계층만 바꿔 두면 모듈을 업데이트하거나
 * 재활성화하는 순간 원래대로 돌아간다.
 *
 * 반면 `ModuleManager::createModuleMenus()` 는 동기화 **직전에**
 * `module.{identifier}.admin_menus.translations` 필터를 통과시킨다. 이 필터에 붙어
 * 정의 자체를 다시 그리면, 공식 동기화 경로가 우리 배치를 그대로 기록한다 —
 * 모듈이 몇 번 재동기화돼도 배치가 유지된다. 그래서 "메뉴 배치만 소유하는" 이
 * 플러그인을 만들었다.
 *
 * ── 지키는 제약 ─────────────────────────────────────────────────────────────
 * `ModuleManager::cleanupStaleModuleEntries()` 는 **필터를 거치지 않은** 원본
 * `getAdminMenus()` 의 slug 집합으로 stale 메뉴를 지운다. 따라서 이 플러그인은
 * 새 slug 를 만들지 않고 모듈이 이미 선언한 slug 만 재배치한다. 새 부모가 필요하면
 * 기존 `sirsoft-ecommerce` 부모를 '쇼핑몰 설정' 으로 재사용한다.
 *
 * 배치 정의는 `config/menu-layout.json` 한 곳에 있고, 훅 리스너와 아티즌 명령
 * (`yutiv:admin-menu`) 이 같은 파일을 읽는다.
 */
class Plugin extends AbstractPlugin
{
    /**
     * 구독할 훅 리스너.
     *
     * @return array<class-string>
     */
    public function getHookListeners(): array
    {
        return [
            EcommerceAdminMenuListener::class,
            CoreAdminMenuListener::class,
        ];
    }

    /**
     * 이 플러그인은 자체 화면·라우트·권한·테이블을 만들지 않는다.
     *
     * 메뉴 배치만 소유하므로 관리자 메뉴도 선언하지 않는다 — 선언하면 그 자체가
     * 또 하나의 최상위 메뉴가 되어 목적(메뉴 정리)에 어긋난다.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAdminMenus(): array
    {
        return [];
    }

    /**
     * 비활성화 가드 — 배치가 적용된 상태로 끄면 되돌릴 수단이 사라진다.
     *
     * **이 플러그인을 끈다고 DB 배치가 자동 복원되지는 않는다.** 비활성화는 "앞으로 있을
     * 모듈·코어 동기화에서 배치를 다시 적용하지 않는다" 는 뜻일 뿐이고, 이미 DB 에 기록된
     * parent_id/order/name 은 그대로 남는다. 게다가 비활성화하면 `yutiv:admin-menu` 명령
     * 자체가 등록 해제되어 `--rollback` 을 쓸 수 없다.
     *
     * 그래서 배치가 적용된 상태면 비활성화를 **차단**한다. 올바른 순서는:
     *
     *     php artisan yutiv:admin-menu --rollback     # 먼저 원복
     *     php artisan plugin:deactivate yutiv-admin_menu
     *
     * 되돌릴 생각이 없다면(현 배치를 그대로 두고 자동 재적용만 멈추려면)
     * `storage/app/yutiv-admin-menu/allow-deactivate` 토큰을 만들어 이 가드를 해제한다.
     * 이 토큰은 **일회용**이다 — 여기서 한 번 쓰고 즉시 삭제하므로, 한 번 만든 파일로
     * 이후 비활성화를 반복 허용하지 않는다.
     */
    public function deactivate(): bool
    {
        $token = null;

        try {
            $token = self::deactivationToken();

            // 토큰이 있으면 **소비하고 즉시 삭제**한 뒤 통과시킨다 (일회용).
            $payload = $token->consume();
            if ($payload !== null) {
                Log::info('yutiv-admin_menu 비활성화 허용 토큰을 소비했습니다 (삭제 완료)', [
                    'token' => $token->path(),
                    'issued_at' => $payload['issued_at'] ?? null,
                    'issued_by' => $payload['issued_by'] ?? null,
                ]);

                return parent::deactivate();
            }

            $rows = self::currentMenuRows();
            if ($rows === []) {
                return parent::deactivate();
            }

            $drift = self::layoutPlan()->verify($rows);
            $applied = $drift === [];

            if ($applied) {
                Log::warning('yutiv-admin_menu 비활성화 차단 — 배치가 적용된 상태입니다', [
                    'hint' => 'php artisan yutiv:admin-menu --rollback 을 먼저 실행하세요.',
                ]);

                throw new \RuntimeException(
                    'YUTIV 메뉴 배치가 적용된 상태라 비활성화를 막았습니다. '
                    ."먼저 되돌리세요: php artisan yutiv:admin-menu --rollback\n"
                    .'배치를 그대로 두고 자동 재적용만 멈추려면 '
                    .'storage/app/yutiv-admin-menu/allow-deactivate 파일을 만드세요 (일회용).'
                );
            }
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // 판정 자체가 실패하면(테이블 부재 등) 비활성화를 막지 않는다 —
            // 가드 때문에 플러그인을 영영 끄지 못하는 상황이 더 나쁘다.
            //
            // 다만 이건 **조용히 넘어가도 되는 일이 아니다.** 가드가 판정하지 못한 채
            // 열어준 것이므로 critical 로 남기고, DB 메뉴가 복원됐다고 오인하지 않도록
            // 명시한다. 실제로 이 경로에서는 메뉴를 단 한 건도 되돌리지 않는다.
            Log::critical('yutiv-admin_menu 비활성화 가드 fail-open — 판정 실패로 비활성화를 허용했습니다', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'db_menus_restored' => false,
                'warning' => 'DB 메뉴 배치는 복원되지 않았습니다. parent_id/order/name 은 적용된 상태 그대로 남아 있습니다.',
                'action_required' => '플러그인을 다시 활성화한 뒤 php artisan yutiv:admin-menu --rollback 을 실행하세요.',
            ]);

            // 판정 실패 경로에서 토큰이 남아 있으면 다음 비활성화까지 오염시킨다.
            if ($token !== null) {
                try {
                    $token->revoke();
                } catch (\Throwable $ignored) {
                    // 폐기 실패는 비활성화를 막을 사유가 아니다.
                }
            }
        }

        return parent::deactivate();
    }

    /**
     * 비활성화 허용 토큰 (일회용).
     */
    public static function deactivationToken(): DeactivationToken
    {
        return DeactivationToken::inDirectory(storage_path('app/yutiv-admin-menu'));
    }

    /**
     * 현재 메뉴 행을 계획기 입력 형태로 읽는다 (가드·명령 공용).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function currentMenuRows(): array
    {
        if (! class_exists(\App\Models\Menu::class)) {
            return [];
        }

        return \App\Models\Menu::query()
            ->orderBy('id')
            ->get(['id', 'slug', 'name', 'url', 'icon', 'parent_id', 'order', 'is_active', 'user_overrides', 'extension_type', 'extension_identifier'])
            ->map(function ($m) {
                return [
                    'id' => $m->id,
                    'slug' => $m->slug,
                    'name' => $m->name,
                    'url' => $m->url,
                    'icon' => $m->icon,
                    'parent_id' => $m->parent_id,
                    'order' => (int) $m->order,
                    'is_active' => (bool) $m->is_active,
                    'user_overrides' => is_array($m->user_overrides) ? $m->user_overrides : [],
                    'extension_type' => is_object($m->extension_type) ? $m->extension_type->value : $m->extension_type,
                    'extension_identifier' => $m->extension_identifier,
                ];
            })
            ->all();
    }

    /**
     * 배치 정의 파일 경로.
     */
    public static function layoutPath(): string
    {
        return __DIR__.'/config/menu-layout.json';
    }

    /**
     * 배치 계획기를 만든다.
     */
    public static function layoutPlan(): MenuLayoutPlan
    {
        return MenuLayoutPlan::fromFile(self::layoutPath());
    }
}
