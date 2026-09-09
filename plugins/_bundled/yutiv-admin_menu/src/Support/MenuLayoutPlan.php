<?php

namespace Plugins\Yutiv\AdminMenu\Support;

/**
 * YUTIV 쇼핑몰 관리자 메뉴 배치 계획기 — 순수 함수 계층.
 *
 * DB 도 Laravel 도 건드리지 않는다. 입력(현재 메뉴 정의 / 현재 메뉴 행)과 배치 정의를 받아
 * "무엇을 어떻게 바꿀지" 만 계산한다. 실제 쓰기는 호출부(리스너·명령)가 한다.
 *
 * 두 곳이 이 클래스를 공유한다:
 *   - EcommerceAdminMenuListener  모듈 동기화 직전에 메뉴 정의를 다시 그린다 (업데이트 안전)
 *   - AdminMenuCommand            지금 당장 DB 에 적용/롤백한다 (재설치 없이)
 * 계획 로직이 한 곳에 있어야 "적용은 됐는데 모듈 업데이트 후 달라지는" 어긋남이 없다.
 *
 * ── 이 파일만 PHP 7.4 문법 부분집합으로 쓴 이유 ──────────────────────────────
 * 로컬 검증 환경의 PHP 는 7.4 이고 `vendor/` 가 없어 Laravel 부팅도 PHPUnit 실행도 불가능하다.
 * 그래서 이 계획기를 7.4 로도 파싱되는 범위(타입 프로퍼티·enum·match·readonly 미사용)로 써서,
 * 저장소의 독립 검증 하네스(tests/Menu/yutiv-admin-menu-check.php)가 **이 파일 그대로**를
 * require 해 멱등성·순환·slug·URL/권한 보존을 실제로 실행 검증한다.
 * 계획기를 하네스에 복제하면 둘이 갈라져 검증이 거짓이 되므로, 문법을 낮춰서라도 하나로 둔다.
 */
class MenuLayoutPlan
{
    /** @var array 배치 정의 (config/menu-layout.json) */
    private $layout;

    /**
     * @param  array  $layout  config/menu-layout.json 을 디코드한 배열
     */
    public function __construct(array $layout)
    {
        $this->layout = $layout;
    }

    /**
     * 배치 정의 파일을 읽어 인스턴스를 만듭니다.
     *
     * @param  string  $path  menu-layout.json 경로
     * @return self
     *
     * @throws \RuntimeException 파일이 없거나 JSON 이 깨졌을 때
     */
    public static function fromFile($path)
    {
        if (! is_file($path)) {
            throw new \RuntimeException("메뉴 배치 정의를 찾을 수 없습니다: {$path}");
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException("메뉴 배치 정의 JSON 파싱 실패: {$path}");
        }

        return new self($decoded);
    }

    /** @return string 이 배치가 대상으로 삼는 모듈 식별자 */
    public function targetModule()
    {
        return isset($this->layout['target_module']) ? $this->layout['target_module'] : 'sirsoft-ecommerce';
    }

    /** @return array 최상위로 올릴 slug ⇒ order */
    public function promotedSlugs()
    {
        return $this->stripComments(isset($this->layout['promote']) ? $this->layout['promote'] : []);
    }

    /** @return array '쇼핑몰 설정' 아래에 남길 slug ⇒ order */
    public function settingsChildSlugs()
    {
        return $this->stripComments(isset($this->layout['settings_children']) ? $this->layout['settings_children'] : []);
    }

    /** @return array 재사용할 부모 메뉴 정의 (slug/order/icon/name) */
    public function settingsParent()
    {
        return isset($this->layout['settings_parent']) ? $this->layout['settings_parent'] : [];
    }

    /** @return array 코어 메뉴 순서 설정 (enabled / orders) */
    public function coreOrderSpec()
    {
        return isset($this->layout['core_order']) ? $this->layout['core_order'] : ['enabled' => false];
    }

    /**
     * 코어 메뉴 slug ⇒ **절대** 목표 순서.
     *
     * "현재값 + N" 이 아니라 목표값이라 몇 번을 적용해도 누적되지 않는다.
     *
     * @return array
     */
    public function coreOrders()
    {
        $spec = $this->coreOrderSpec();
        if (empty($spec['enabled'])) {
            return [];
        }

        return $this->stripComments(isset($spec['orders']) ? $spec['orders'] : []);
    }

    /**
     * 이 배치가 최종적으로 기대하는 **최상위 메뉴 순서** (slug ⇒ order).
     *
     * `--check` 와 적용 후 자체 검증이 이 값을 기준으로 현재 DB 와 대조한다.
     *
     * @return array
     */
    public function expectedTopLevelOrders()
    {
        $expected = $this->coreOrders();
        foreach ($this->promotedSlugs() as $slug => $spec) {
            $expected[$slug] = isset($spec['order']) ? $spec['order'] : null;
        }
        $parent = $this->settingsParent();
        if (isset($parent['slug'])) {
            $expected[$parent['slug']] = isset($parent['order']) ? $parent['order'] : null;
        }

        return $expected;
    }

    /**
     * 현재 DB 상태가 목표 배치와 일치하는지 검사한다 (읽기 전용).
     *
     * `--check` 와 `--apply` 직후 자체 검증이 함께 쓴다. 순서·계층뿐 아니라
     * **보호 표시(user_overrides 의 order)** 까지 본다 — 코어 메뉴를 재시드하면
     * 순서는 맞지만 보호 표시가 사라져, 다음 코어 업데이트에서 되돌아가기 때문이다.
     *
     * @param  array  $rows  현재 메뉴 행 (user_overrides 포함 권장)
     * @return array drift 목록 (빈 배열이면 일치)
     */
    public function verify(array $rows)
    {
        $bySlug = [];
        foreach ($rows as $row) {
            $bySlug[$row['slug']] = $row;
        }

        $drift = [];

        // 1. 최상위 순서
        foreach ($this->expectedTopLevelOrders() as $slug => $order) {
            if (! isset($bySlug[$slug])) {
                $drift[] = ['slug' => $slug, 'kind' => 'missing', 'expected' => $order, 'actual' => null];
                continue;
            }
            $row = $bySlug[$slug];
            if ($row['parent_id'] !== null) {
                $drift[] = ['slug' => $slug, 'kind' => 'parent', 'expected' => null, 'actual' => $row['parent_id']];
            }
            if ((int) $row['order'] !== (int) $order) {
                $drift[] = ['slug' => $slug, 'kind' => 'order', 'expected' => $order, 'actual' => (int) $row['order']];
            }
        }

        // 2. '쇼핑몰 설정' 하위 메뉴
        $parent = $this->settingsParent();
        $parentId = isset($parent['slug'], $bySlug[$parent['slug']]) ? $bySlug[$parent['slug']]['id'] : null;
        foreach ($this->settingsChildSlugs() as $slug => $spec) {
            if (! isset($bySlug[$slug])) {
                $drift[] = ['slug' => $slug, 'kind' => 'missing', 'expected' => 'child', 'actual' => null];
                continue;
            }
            $row = $bySlug[$slug];
            if ($parentId !== null && $row['parent_id'] !== $parentId) {
                $drift[] = ['slug' => $slug, 'kind' => 'parent', 'expected' => $parentId, 'actual' => $row['parent_id']];
            }
            if (isset($spec['order']) && (int) $row['order'] !== (int) $spec['order']) {
                $drift[] = ['slug' => $slug, 'kind' => 'order', 'expected' => $spec['order'], 'actual' => (int) $row['order']];
            }
        }

        // 3. 코어 메뉴 order 보호 표시
        //    CoreUpdateService::syncCoreMenus() 는 user_overrides 에 'order' 가 있어야 보존한다.
        //    CoreAdminMenuSeeder 가 코어 메뉴를 삭제·재생성하면 이 표시가 사라져, 순서가 맞아도
        //    다음 코어 업데이트에서 되돌아간다. 그래서 순서와 별개로 확인한다.
        foreach (array_keys($this->coreOrders()) as $slug) {
            if (! isset($bySlug[$slug]) || ! array_key_exists('user_overrides', $bySlug[$slug])) {
                continue;
            }
            $overrides = $bySlug[$slug]['user_overrides'];
            $overrides = is_array($overrides) ? $overrides : [];
            if (! in_array('order', $overrides, true)) {
                $drift[] = ['slug' => $slug, 'kind' => 'unprotected', 'expected' => 'user_overrides[order]', 'actual' => '없음'];
            }
        }

        return $drift;
    }

    /**
     * 코어 메뉴 정의(config('core.menus'))에 목표 순서를 얹는다.
     *
     * `CoreAdminMenuSeeder` 는 코어 메뉴를 **삭제 후 재생성**하며 그 직전에
     * `core.menus.config` 필터를 통과시킨다. 그 필터에 이 결과를 넣으면 재시드 후에도
     * 순서가 목표대로 만들어진다.
     *
     * slug/url/icon/permission 은 손대지 않고 order 만 바꾼다.
     *
     * @param  array  $coreMenus  config('core.menus') 형태
     * @return array
     */
    public function rewriteCoreDefinition(array $coreMenus)
    {
        $orders = $this->coreOrders();
        if (! $orders) {
            return $coreMenus;
        }

        foreach ($coreMenus as $i => $menu) {
            $slug = isset($menu['slug']) ? $menu['slug'] : null;
            if ($slug !== null && isset($orders[$slug])) {
                $coreMenus[$i]['order'] = $orders[$slug];
            }
        }

        return $coreMenus;
    }

    /**
     * 모듈이 선언한 관리자 메뉴 정의를 YUTIV 배치로 다시 그립니다.
     *
     * 이 결과가 `ExtensionMenuSyncHelper::syncMenuRecursive()` 에 그대로 들어가므로,
     * 모듈 설치·업데이트·재활성화가 일어날 때마다 배치가 자동으로 재적용된다.
     *
     * 지키는 것: slug / url / icon / extension ownership. 바꾸는 것: 계층(parent) / order / name.
     * 새 slug 를 만들지 않는다 — cleanupStaleModuleEntries 가 원본 정의의 slug 집합으로
     * stale 정리를 하므로 정의에 없는 slug 는 다음 동기화에서 삭제된다.
     *
     * @param  array  $menus  모듈의 getAdminMenus() 결과 (필터 이전)
     * @return array 다시 그린 메뉴 정의
     */
    public function rewriteDefinition(array $menus)
    {
        $flat = $this->flattenDefinition($menus);

        $promote = $this->promotedSlugs();
        $settingsChildren = $this->settingsChildSlugs();
        $parentSpec = $this->settingsParent();
        $parentSlug = isset($parentSpec['slug']) ? $parentSpec['slug'] : null;

        $promotedNames = $this->stripComments(isset($this->layout['promoted_names']) ? $this->layout['promoted_names'] : []);
        $childNames = $this->stripComments(isset($this->layout['settings_children_names']) ? $this->layout['settings_children_names'] : []);

        // 정의에 없는 slug 를 배치가 참조하면 조용히 사라지므로 즉시 드러낸다.
        foreach (array_merge(array_keys($promote), array_keys($settingsChildren)) as $slug) {
            if (! isset($flat[$slug])) {
                throw new \RuntimeException(
                    "배치 정의가 모듈에 없는 slug 를 참조합니다: {$slug}. ".
                    '모듈 메뉴 정의가 바뀌었는지 확인하세요 (config/menu-layout.json).'
                );
            }
        }

        $top = [];

        // 1) 고빈도 메뉴를 최상위로
        foreach ($promote as $slug => $spec) {
            $node = $flat[$slug];
            unset($node['children']);
            $node['order'] = isset($spec['order']) ? $spec['order'] : $node['order'];
            if (isset($promotedNames[$slug])) {
                $node['name'] = $this->mergeName($node['name'], $promotedNames[$slug]);
            }
            $top[] = $node;
        }

        // 2) 기존 부모를 '쇼핑몰 설정' 으로 재사용하고 저빈도 메뉴만 남긴다
        if ($parentSlug !== null && isset($flat[$parentSlug])) {
            $parent = $flat[$parentSlug];
            unset($parent['children']);
            if (isset($parentSpec['order'])) {
                $parent['order'] = $parentSpec['order'];
            }
            if (isset($parentSpec['icon'])) {
                $parent['icon'] = $parentSpec['icon'];
            }
            if (isset($parentSpec['name'])) {
                $parent['name'] = $this->mergeName($parent['name'], $parentSpec['name']);
            }

            $children = [];
            foreach ($settingsChildren as $slug => $spec) {
                $child = $flat[$slug];
                unset($child['children']);
                $child['order'] = isset($spec['order']) ? $spec['order'] : $child['order'];
                if (isset($childNames[$slug])) {
                    $child['name'] = $this->mergeName($child['name'], $childNames[$slug]);
                }
                $children[] = $child;
            }
            $parent['children'] = $children;
            $top[] = $parent;
        }

        // 3) 배치가 언급하지 않은 메뉴는 원래 자리(부모 아래)에 그대로 둔다.
        //    모듈이 새 메뉴를 추가해도 사라지지 않게 하는 안전장치다.
        $mentioned = array_merge(
            array_keys($promote),
            array_keys($settingsChildren),
            $parentSlug !== null ? [$parentSlug] : []
        );
        $leftovers = [];
        foreach ($flat as $slug => $node) {
            if (in_array($slug, $mentioned, true)) {
                continue;
            }
            unset($node['children']);
            $leftovers[] = $node;
        }
        if ($leftovers && $parentSlug !== null) {
            foreach ($top as $i => $node) {
                if (isset($node['slug']) && $node['slug'] === $parentSlug) {
                    $top[$i]['children'] = array_merge($top[$i]['children'], $leftovers);
                    break;
                }
            }
        }

        return $top;
    }

    /**
     * 현재 DB 메뉴 행에 대해 적용할 변경 목록을 계산합니다 (쓰기 없음).
     *
     * @param  array  $rows  [['id','slug','parent_id','order','name','url','extension_type','extension_identifier'], ...]
     * @return array{changes: array, warnings: array} changes 는 slug 별 before/after
     */
    public function planApply(array $rows)
    {
        $bySlug = [];
        foreach ($rows as $row) {
            $bySlug[$row['slug']] = $row;
        }

        $changes = [];
        $warnings = [];

        $parentSpec = $this->settingsParent();
        $parentSlug = isset($parentSpec['slug']) ? $parentSpec['slug'] : null;
        $parentRow = ($parentSlug !== null && isset($bySlug[$parentSlug])) ? $bySlug[$parentSlug] : null;

        if ($parentRow === null) {
            $warnings[] = "부모 메뉴 slug '{$parentSlug}' 가 DB 에 없습니다 — 이커머스 모듈이 설치·활성 상태인지 확인하세요.";

            return ['changes' => [], 'warnings' => $warnings];
        }

        $promotedNames = $this->stripComments(isset($this->layout['promoted_names']) ? $this->layout['promoted_names'] : []);
        $childNames = $this->stripComments(isset($this->layout['settings_children_names']) ? $this->layout['settings_children_names'] : []);

        // 1) 최상위 승격
        foreach ($this->promotedSlugs() as $slug => $spec) {
            if (! isset($bySlug[$slug])) {
                $warnings[] = "메뉴를 찾을 수 없어 건너뜁니다: {$slug}";
                continue;
            }
            $change = $this->diffRow($bySlug[$slug], [
                'parent_id' => null,
                'order' => isset($spec['order']) ? $spec['order'] : $bySlug[$slug]['order'],
                'name' => isset($promotedNames[$slug])
                    ? $this->mergeName($bySlug[$slug]['name'], $promotedNames[$slug])
                    : $bySlug[$slug]['name'],
            ]);
            if ($change) {
                $changes[$slug] = $change;
            }
        }

        // 2) 부모 메뉴 재사용
        $parentAfter = [
            'parent_id' => null,
            'order' => isset($parentSpec['order']) ? $parentSpec['order'] : $parentRow['order'],
        ];
        if (isset($parentSpec['icon'])) {
            $parentAfter['icon'] = $parentSpec['icon'];
        }
        if (isset($parentSpec['name'])) {
            $parentAfter['name'] = $this->mergeName($parentRow['name'], $parentSpec['name']);
        }
        $change = $this->diffRow($parentRow, $parentAfter);
        if ($change) {
            $changes[$parentSlug] = $change;
        }

        // 3) 설정 하위 메뉴
        foreach ($this->settingsChildSlugs() as $slug => $spec) {
            if (! isset($bySlug[$slug])) {
                $warnings[] = "메뉴를 찾을 수 없어 건너뜁니다: {$slug}";
                continue;
            }
            $change = $this->diffRow($bySlug[$slug], [
                'parent_id' => $parentRow['id'],
                'order' => isset($spec['order']) ? $spec['order'] : $bySlug[$slug]['order'],
                'name' => isset($childNames[$slug])
                    ? $this->mergeName($bySlug[$slug]['name'], $childNames[$slug])
                    : $bySlug[$slug]['name'],
            ]);
            if ($change) {
                $changes[$slug] = $change;
            }
        }

        // 4) 코어 최상위 메뉴 순서 — **절대 목표값**으로 맞춘다.
        //    "현재값 + N" 이 아니라 목표값이라 반복 실행해도 누적되지 않는다.
        //    order 만 바꾼다 — slug/url/menu_permissions/ownership 은 손대지 않는다.
        foreach ($this->coreOrders() as $slug => $order) {
            if (! isset($bySlug[$slug])) {
                $warnings[] = "코어 메뉴를 찾을 수 없어 건너뜁니다: {$slug}";
                continue;
            }
            $change = $this->diffRow($bySlug[$slug], ['order' => (int) $order]);
            if ($change) {
                $changes[$slug] = $change;
            }
        }

        return ['changes' => $changes, 'warnings' => $warnings];
    }

    /**
     * 현재 행과 목표값을 비교해 실제로 바뀌는 필드만 남깁니다.
     *
     * @param  array  $row  현재 행
     * @param  array  $after  목표값
     * @return array|null 바뀌는 것이 없으면 null
     */
    private function diffRow(array $row, array $after)
    {
        $before = [];
        $target = [];
        foreach ($after as $field => $value) {
            $current = isset($row[$field]) ? $row[$field] : null;
            if ($field === 'name') {
                $current = $this->normalizeName($current);
                $value = $this->normalizeName($value);
            }
            if ($current === $value) {
                continue;
            }
            $before[$field] = $current;
            $target[$field] = $value;
        }

        if (! $target) {
            return null;
        }

        return [
            'id' => isset($row['id']) ? $row['id'] : null,
            'slug' => $row['slug'],
            'before' => $before,
            'after' => $target,
        ];
    }

    /**
     * 정의된 메뉴 트리를 slug ⇒ 노드 로 평탄화합니다 (children 은 보존).
     *
     * @param  array  $menus
     * @return array
     */
    public function flattenDefinition(array $menus)
    {
        $flat = [];
        $walk = function ($nodes) use (&$walk, &$flat) {
            foreach ($nodes as $node) {
                if (! isset($node['slug'])) {
                    continue;
                }
                $flat[$node['slug']] = $node;
                if (isset($node['children']) && is_array($node['children'])) {
                    $walk($node['children']);
                }
            }
        };
        $walk($menus);

        return $flat;
    }

    /**
     * 기존 name 배열에 로케일을 덧씌웁니다 (기존 로케일은 유지, 없는 것만 채움 + 지정값 우선).
     *
     * @param  mixed  $current  기존 name (문자열 또는 로케일 배열)
     * @param  array  $overrides  덧씌울 로케일 값
     * @return array
     */
    private function mergeName($current, array $overrides)
    {
        $base = $this->normalizeName($current);

        return array_merge($base, $overrides);
    }

    /**
     * name 을 로케일 배열로 정규화합니다.
     *
     * @param  mixed  $name
     * @return array
     */
    private function normalizeName($name)
    {
        if (is_array($name)) {
            return $name;
        }
        if (is_string($name) && $name !== '') {
            $decoded = json_decode($name, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            return ['ko' => $name, 'en' => $name];
        }

        return [];
    }

    /**
     * JSON 정의의 `$comment` 키를 제거합니다 (문서용 주석이지 데이터가 아니다).
     *
     * @param  array  $data
     * @return array
     */
    private function stripComments(array $data)
    {
        unset($data['$comment']);

        return $data;
    }
}
