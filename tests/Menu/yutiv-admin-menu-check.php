<?php

/**
 * YUTIV 관리자 메뉴 배치 검증 하네스 (standalone CLI — vendor/Laravel 불필요).
 *
 * 왜 PHPUnit 이 아닌가:
 *   로컬 PHP 는 7.4 이고 `vendor/` 가 비어 있어 Laravel 부팅도 PHPUnit 실행도 불가능하다.
 *   그래서 이 하네스가 **플러그인의 실제 계획기(MenuLayoutPlan)** 를 그대로 require 해
 *   메뉴 테이블을 메모리에서 재현하고, 코어의 동기화 규칙을 그대로 흉내 내 실행 검증한다.
 *   계획 로직을 복제하지 않으므로 하네스와 운영 코드가 갈라질 수 없다.
 *
 * 재현하는 코어 규칙 (조사로 확정, 파일·행 근거는 보고서 참조):
 *   - ExtensionMenuSyncHelper::syncMenu() 는 parent_id 를 **항상** 정의값으로 덮어쓴다.
 *   - order/name/icon/url 은 user_overrides 에 마킹돼 있으면 보존한다.
 *   - Menu::$trackableFields = ['name','icon','order','url'] — parent_id 는 없다.
 *   - cleanupStaleMenus 는 **필터 이전** 원본 정의의 slug 집합으로 stale 을 지운다.
 *
 * 재현하지 않는 것 (서버에서만 확인 가능):
 *   실제 DB 트랜잭션, menu_permissions/role_menus 조인, 관리자 화면 렌더, 권한 게이트.
 *
 * 사용: php tests/Menu/yutiv-admin-menu-check.php [--verbose] [--preview]
 * 종료코드: 위반이 있으면 1
 */
$root = dirname(__DIR__, 2);
$pluginDir = $root.'/plugins/_bundled/yutiv-admin_menu';
$modulePath = $root.'/modules/_bundled/sirsoft-ecommerce/module.php';
$corePath = $root.'/config/core.php';

require_once $pluginDir.'/src/Support/MenuLayoutPlan.php';
require_once $pluginDir.'/src/Support/DeactivationToken.php';

use Plugins\Yutiv\AdminMenu\Support\DeactivationToken;
use Plugins\Yutiv\AdminMenu\Support\MenuLayoutPlan;

$verbose = in_array('--verbose', $argv, true);
$makePreview = in_array('--preview', $argv, true);

$violations = [];
$passes = [];

/** 단언 — 실패는 모으고 계속 진행한다 (첫 실패에서 멈추면 나머지 상태를 못 본다). */
function check($label, $condition, $detail = '')
{
    global $violations, $passes;
    if ($condition) {
        $passes[] = $label;
    } else {
        $violations[] = $label.($detail !== '' ? " — {$detail}" : '');
    }
}

// ── 0. 플러그인 매니페스트 · 네임스페이스 계약 ───────────────────────────────
// 코어는 디렉토리명에서 네임스페이스를 계산해 ServiceProvider 와 Plugin 클래스를 찾는다
// (ExtensionManager::directoryToNamespace + PluginServiceProvider::resolveProviderClass).
// 여기가 어긋나면 플러그인이 조용히 로드되지 않으므로 규칙을 그대로 재현해 대조한다.
$pluginDirName = basename($pluginDir);
$expectedNamespace = implode('\\', array_map(function ($part) {
    return str_replace(' ', '', ucwords(str_replace('_', ' ', $part)));
}, explode('-', $pluginDirName)));

check('플러그인 디렉토리명이 vendor-name 규칙', (bool) preg_match('/^[a-z0-9]+-[a-z0-9_]+$/i', $pluginDirName), $pluginDirName);
check('계산된 네임스페이스가 Yutiv\\AdminMenu', $expectedNamespace === 'Yutiv\\AdminMenu', $expectedNamespace);

$manifest = json_decode((string) file_get_contents($pluginDir.'/plugin.json'), true);
check('plugin.json 파싱', is_array($manifest));
check('plugin.json identifier 가 디렉토리명과 일치',
    isset($manifest['identifier']) && $manifest['identifier'] === $pluginDirName,
    isset($manifest['identifier']) ? $manifest['identifier'] : '(없음)');
check('plugin.json 이 이커머스 모듈 의존성을 선언',
    isset($manifest['dependencies']['modules']['sirsoft-ecommerce']));
check('plugin.json name 이 ko/en/ja/zh-CN 4개 로케일', (function () use ($manifest) {
    foreach (['ko', 'en', 'ja', 'zh-CN'] as $l) {
        if (empty($manifest['name'][$l])) {
            return false;
        }
    }

    return true;
})());

$providerFile = $pluginDir.'/src/Providers/AdminMenuServiceProvider.php';
check('ServiceProvider 파일이 src/Providers 아래에 존재 (코어 탐색 경로)', is_file($providerFile));
if (is_file($providerFile)) {
    $providerSrc = (string) file_get_contents($providerFile);
    check('ServiceProvider 네임스페이스가 코어 계산값과 일치',
        strpos($providerSrc, 'namespace Plugins\\'.$expectedNamespace.'\\Providers;') !== false);
    check('ServiceProvider 의 pluginIdentifier 가 디렉토리명과 일치',
        strpos($providerSrc, "'".$pluginDirName."'") !== false);
}

$listenerFile = $pluginDir.'/src/Listeners/EcommerceAdminMenuListener.php';
check('리스너가 모듈 동기화 필터를 구독', is_file($listenerFile)
    && strpos((string) file_get_contents($listenerFile), 'module.sirsoft-ecommerce.admin_menus.translations') !== false);
check('리스너가 filter 타입으로 등록', is_file($listenerFile)
    && strpos((string) file_get_contents($listenerFile), "'type' => 'filter'") !== false);

// 이 플러그인이 스스로 관리자 메뉴를 만들면 목적(메뉴 정리)에 어긋난다
$pluginSrc = (string) file_get_contents($pluginDir.'/plugin.php');
check('플러그인이 자체 관리자 메뉴를 선언하지 않음',
    strpos($pluginSrc, 'public function getAdminMenus(): array') !== false
    && preg_match('/getAdminMenus\(\): array\s*\{\s*return \[\];/s', $pluginSrc) === 1);

// ── 모듈의 메뉴 정의 추출 ────────────────────────────────────────────────────
// module.php 는 PHP 8 문법이라 7.4 에서 include 할 수 없다. getAdminMenus() 의 배열
// 리터럴만 잘라 평가한다 — 리터럴 자체는 7.4 에서도 유효한 순수 데이터다.
function extractAdminMenus($modulePath)
{
    $src = file_get_contents($modulePath);
    $needle = 'public function getAdminMenus(): array';
    $at = strpos($src, $needle);
    if ($at === false) {
        throw new RuntimeException('getAdminMenus() 를 찾지 못했습니다: '.$modulePath);
    }
    $returnAt = strpos($src, 'return [', $at);
    if ($returnAt === false) {
        throw new RuntimeException('getAdminMenus() 의 return 배열을 찾지 못했습니다');
    }
    $start = $returnAt + strlen('return ');

    // 대괄호 균형으로 리터럴 끝을 찾는다 (문자열 안의 괄호는 건너뛴다)
    $depth = 0;
    $len = strlen($src);
    $inStr = false;
    $quote = '';
    for ($i = $start; $i < $len; $i++) {
        $ch = $src[$i];
        if ($inStr) {
            if ($ch === '\\') {
                $i++;
                continue;
            }
            if ($ch === $quote) {
                $inStr = false;
            }
            continue;
        }
        if ($ch === "'" || $ch === '"') {
            $inStr = true;
            $quote = $ch;
            continue;
        }
        if ($ch === '[') {
            $depth++;
        } elseif ($ch === ']') {
            $depth--;
            if ($depth === 0) {
                $literal = substr($src, $start, $i - $start + 1);

                return eval('return '.$literal.';');
            }
        }
    }
    throw new RuntimeException('배열 리터럴의 끝을 찾지 못했습니다');
}

$definition = extractAdminMenus($modulePath);
$plan = MenuLayoutPlan::fromFile($pluginDir.'/config/menu-layout.json');

// ── 1. 변경 전 상태: 코어 규칙대로 DB 를 만든다 ──────────────────────────────
$nextId = 1;

/** 정의 트리를 메뉴 행으로 펼친다 — syncMenuRecursive 와 같은 순회. */
function seedRows(array $menus, $parentId, $extType, $extId, &$rows, &$nextId)
{
    foreach ($menus as $node) {
        $id = $nextId++;
        $rows[$id] = [
            'id' => $id,
            'slug' => $node['slug'],
            'name' => is_array($node['name']) ? $node['name'] : ['ko' => $node['name']],
            'url' => isset($node['url']) ? $node['url'] : null,
            'icon' => isset($node['icon']) ? $node['icon'] : null,
            'parent_id' => $parentId,
            'order' => isset($node['order']) ? $node['order'] : 0,
            'is_active' => true,
            'extension_type' => $extType,
            'extension_identifier' => $extId,
            'user_overrides' => [],
            // 정의의 permission 키는 menus 테이블에 저장되지 않는다(컬럼 없음).
            // 접근 제어는 menu_permissions(menu_id) 가 담당하므로 여기서는 별도 보관해
            // "계층을 바꿔도 권한 매핑이 그대로인가" 를 검증할 수 있게 한다.
            '_permission' => isset($node['permission']) ? $node['permission'] : null,
        ];
        if (isset($node['children']) && is_array($node['children'])) {
            seedRows($node['children'], $id, $extType, $extId, $rows, $nextId);
        }
    }
}

$rows = [];
// 코어 최상위 메뉴 (config/core.php)
$coreMenus = include $corePath;
$coreTop = [];
foreach ($coreMenus['menus'] as $m) {
    $coreTop[] = [
        'slug' => $m['slug'],
        'name' => is_array($m['name']) ? $m['name'] : ['ko' => $m['name']],
        'url' => isset($m['url']) ? $m['url'] : null,
        'icon' => isset($m['icon']) ? $m['icon'] : null,
        'order' => isset($m['order']) ? $m['order'] : 0,
    ];
}
seedRows($coreTop, null, 'core', 'core', $rows, $nextId);
seedRows($definition, null, 'module', 'sirsoft-ecommerce', $rows, $nextId);

$rowsBefore = $rows;

// ── 검사 A. 변경 전 계층 확인 ────────────────────────────────────────────────
$ecommerceParentId = null;
foreach ($rows as $r) {
    if ($r['slug'] === 'sirsoft-ecommerce') {
        $ecommerceParentId = $r['id'];
    }
}
$childrenBefore = array_values(array_filter($rows, function ($r) use ($ecommerceParentId) {
    return $r['parent_id'] === $ecommerceParentId;
}));
check('변경 전: 이커머스 부모 아래 하위 메뉴 11개', count($childrenBefore) === 11, '실제 '.count($childrenBefore).'개');
check('변경 전: 주문/상품/카테고리/쿠폰/리뷰가 모두 하위 메뉴', (function () use ($rows, $ecommerceParentId) {
    foreach (['orders', 'products', 'categories', 'promotion-coupons', 'reviews'] as $k) {
        $found = false;
        foreach ($rows as $r) {
            if ($r['slug'] === 'sirsoft-ecommerce-'.$k && $r['parent_id'] === $ecommerceParentId) {
                $found = true;
            }
        }
        if (! $found) {
            return false;
        }
    }

    return true;
})());

// ── 2. 배치 정의가 참조하는 slug 가 모두 실재하는가 ──────────────────────────
$flatDef = $plan->flattenDefinition($definition);
$referenced = array_merge(array_keys($plan->promotedSlugs()), array_keys($plan->settingsChildSlugs()));
$parentSpec = $plan->settingsParent();
$referenced[] = $parentSpec['slug'];
$missing = array_values(array_filter($referenced, function ($s) use ($flatDef) {
    return ! isset($flatDef[$s]);
}));
check('배치 정의가 참조하는 slug 가 모두 모듈 정의에 존재', empty($missing), implode(', ', $missing));

// 모듈 정의의 모든 slug 가 배치에서 다뤄지는가 (누락 시 조용히 사라질 수 있다)
$unhandled = array_values(array_diff(array_keys($flatDef), $referenced));
check('모듈 정의의 모든 메뉴가 배치에서 다뤄짐 (누락 0)', empty($unhandled), implode(', ', $unhandled));

// ── 3. rewriteDefinition — 모듈 동기화 경로에 들어갈 정의 ────────────────────
// 정의에 없는 slug 를 참조하면 계획기가 예외를 던진다. 하네스가 죽지 않도록 받아서
// 위반으로 보고한다 — 나머지 검사 결과도 함께 봐야 원인을 좁힐 수 있다.
try {
    $rewritten = $plan->rewriteDefinition($definition);
} catch (\Throwable $e) {
    check('재작성 정의 생성', false, $e->getMessage());
    $rewritten = [];
}
$flatRewritten = $plan->flattenDefinition($rewritten);

check('재작성 정의: 새 slug 를 만들지 않음',
    empty(array_diff(array_keys($flatRewritten), array_keys($flatDef))),
    implode(', ', array_diff(array_keys($flatRewritten), array_keys($flatDef))));
check('재작성 정의: 원본 slug 를 잃지 않음',
    empty(array_diff(array_keys($flatDef), array_keys($flatRewritten))),
    implode(', ', array_diff(array_keys($flatDef), array_keys($flatRewritten))));
check('재작성 정의: 최상위 6개 (승격 5 + 쇼핑몰 설정 1)', count($rewritten) === 6, '실제 '.count($rewritten).'개');

$urlMismatch = [];
foreach ($flatRewritten as $slug => $node) {
    $before = $flatDef[$slug];
    if ((isset($node['url']) ? $node['url'] : null) !== (isset($before['url']) ? $before['url'] : null)) {
        $urlMismatch[] = $slug;
    }
}
check('재작성 정의: 모든 URL 이 원본과 동일', empty($urlMismatch), implode(', ', $urlMismatch));

$permMismatch = [];
foreach ($flatRewritten as $slug => $node) {
    $before = $flatDef[$slug];
    if ((isset($node['permission']) ? $node['permission'] : null) !== (isset($before['permission']) ? $before['permission'] : null)) {
        $permMismatch[] = $slug;
    }
}
check('재작성 정의: 모든 permission 키가 원본과 동일', empty($permMismatch), implode(', ', $permMismatch));

// ── 4. 적용 (planApply → 쓰기 시뮬레이션) ────────────────────────────────────
/** 계획을 행에 반영한다. 코어의 model 경유 저장과 같이 order/name 은 user_overrides 에 기록. */
function applyPlan(array $planResult, array &$rows, $plan = null)
{
    $byId = [];
    foreach ($rows as $id => $r) {
        $byId[$r['slug']] = $id;
    }
    foreach ($planResult['changes'] as $slug => $change) {
        $id = $byId[$slug];
        foreach ($change['after'] as $field => $value) {
            $rows[$id][$field] = $value;
            // Menu::$trackableFields = ['name','icon','order','url'] — 이 필드만 마킹된다.
            if (in_array($field, ['name', 'icon', 'order', 'url'], true)
                && ! in_array($field, $rows[$id]['user_overrides'], true)) {
                $rows[$id]['user_overrides'][] = $field;
            }
        }
    }

    // 명령(AdminMenuCommand)과 같은 보호 표시 보정.
    // 값이 이미 목표와 같아 변경이 없던 코어 메뉴(예: 대시보드 order=1)는 위 루프에서
    // 마킹되지 않는다. 그러면 다음 코어 업데이트가 그 order 를 정의값으로 되돌릴 수 있다.
    if ($plan !== null) {
        foreach (array_keys($plan->coreOrders()) as $slug) {
            if (! isset($byId[$slug])) {
                continue;
            }
            $id = $byId[$slug];
            if (! in_array('order', $rows[$id]['user_overrides'], true)) {
                $rows[$id]['user_overrides'][] = 'order';
            }
        }
    }
}

$snapshot = $rows; // 적용 전 스냅샷 (롤백 근거)
$planResult = $plan->planApply(array_values($rows));
check('적용 계획: 경고 없음', empty($planResult['warnings']), implode(' / ', $planResult['warnings']));
$plannedCount = count($planResult['changes']);
applyPlan($planResult, $rows, $plan);

// dry-run 이 DB 를 바꾸지 않는지 — 계획 생성만으로 원본이 변하지 않아야 한다
$dryRunProbe = $rowsBefore;
$plan->planApply(array_values($dryRunProbe));
check('dry-run 은 상태를 바꾸지 않음', $dryRunProbe === $rowsBefore);

// ── 5. 적용 후 구조 검증 ─────────────────────────────────────────────────────
$bySlug = [];
foreach ($rows as $r) {
    $bySlug[$r['slug']] = $r;
}

$topLevelTargets = [
    'sirsoft-ecommerce-orders' => 2,
    'sirsoft-ecommerce-products' => 3,
    'sirsoft-ecommerce-categories' => 4,
    'sirsoft-ecommerce-promotion-coupons' => 5,
    'sirsoft-ecommerce-reviews' => 6,
];
foreach ($topLevelTargets as $slug => $order) {
    check("적용 후: {$slug} 가 최상위(parent_id=null)", $bySlug[$slug]['parent_id'] === null);
    check("적용 후: {$slug} order={$order}", $bySlug[$slug]['order'] === $order, '실제 '.$bySlug[$slug]['order']);
}

check('적용 후: 쇼핑몰 설정 부모가 최상위', $bySlug['sirsoft-ecommerce']['parent_id'] === null);
check('적용 후: 쇼핑몰 설정 order=8', $bySlug['sirsoft-ecommerce']['order'] === 8, '실제 '.$bySlug['sirsoft-ecommerce']['order']);

$settingsChildren = array_values(array_filter($rows, function ($r) use ($bySlug) {
    return $r['parent_id'] === $bySlug['sirsoft-ecommerce']['id'];
}));
check('적용 후: 쇼핑몰 설정 아래 저빈도 6개', count($settingsChildren) === 6, '실제 '.count($settingsChildren).'개');

// 이커머스 부모가 최상위에 중복 노출되지 않는가 (승격 메뉴와 이름이 겹치지 않아야 한다)
$topSlugs = [];
foreach ($rows as $r) {
    if ($r['parent_id'] === null) {
        $topSlugs[] = $r['slug'];
    }
}
check('적용 후: 최상위 slug 중복 없음', count($topSlugs) === count(array_unique($topSlugs)));

// 코어 순서 밀림
check('적용 후: 대시보드 order=1 유지', $bySlug['admin-dashboard']['order'] === 1, '실제 '.$bySlug['admin-dashboard']['order']);
// 요청한 최종 최상위 순서를 그대로 못 박는다.
$expectedTopOrder = [
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
$actualTopOrder = [];
foreach ($rows as $r) {
    if ($r['parent_id'] === null) {
        $actualTopOrder[$r['order']] = $r['slug'];
    }
}
ksort($actualTopOrder);
check('적용 후: 최상위 순서 1~18 이 요청과 정확히 일치', $actualTopOrder === $expectedTopOrder,
    '실제: '.implode(' ', array_map(function ($o, $s) {
        return $o.':'.$s;
    }, array_keys($actualTopOrder), $actualTopOrder)));

// 나머지 코어 메뉴의 상대 순서가 보존되는가 (사용자 관리만 앞으로 이동)
$coreRest = ['admin-settings', 'admin-notification-logs', 'admin-identity-logs', 'admin-activity-logs',
    'admin-menus', 'admin-roles', 'admin-modules', 'admin-plugins', 'admin-templates', 'admin-schedules'];
$restOrders = array_map(function ($s) use ($bySlug) {
    return $bySlug[$s]['order'];
}, $coreRest);
$sorted = $restOrders;
sort($sorted);
check('적용 후: 사용자 관리를 제외한 코어 메뉴의 상대 순서 유지', $restOrders === $sorted, implode(',', $restOrders));
check('적용 후: 사용자 관리 order=7', $bySlug['admin-users']['order'] === 7, '실제 '.$bySlug['admin-users']['order']);

// 최상위 순서에 충돌이 없는가
$topOrders = [];
foreach ($rows as $r) {
    if ($r['parent_id'] === null) {
        $topOrders[] = $r['order'];
    }
}
check('적용 후: 최상위 order 값 충돌 없음', count($topOrders) === count(array_unique($topOrders)),
    '중복: '.implode(',', array_keys(array_filter(array_count_values($topOrders), function ($n) {
        return $n > 1;
    }))));

// ── 6. 불변식: slug 유일 / URL·권한·소유권 보존 / 순환 없음 ──────────────────
$allSlugs = array_column($rows, 'slug');
check('slug 전역 유일', count($allSlugs) === count(array_unique($allSlugs)));

$urlChanged = [];
$permChanged = [];
$ownerChanged = [];
foreach ($rows as $id => $r) {
    $b = $rowsBefore[$id];
    if ($r['url'] !== $b['url']) {
        $urlChanged[] = $r['slug'];
    }
    if ($r['_permission'] !== $b['_permission']) {
        $permChanged[] = $r['slug'];
    }
    if ($r['extension_type'] !== $b['extension_type'] || $r['extension_identifier'] !== $b['extension_identifier']) {
        $ownerChanged[] = $r['slug'];
    }
}
check('적용 후: URL 이 바뀐 메뉴 없음', empty($urlChanged), implode(', ', $urlChanged));
check('적용 후: permission 이 바뀐 메뉴 없음', empty($permChanged), implode(', ', $permChanged));
check('적용 후: extension ownership 이 바뀐 메뉴 없음', empty($ownerChanged), implode(', ', $ownerChanged));

/** parent 사슬을 따라가며 순환을 찾는다. */
function hasCycle(array $rows)
{
    foreach ($rows as $r) {
        $seen = [];
        $cur = $r;
        while ($cur['parent_id'] !== null) {
            if (isset($seen[$cur['id']])) {
                return true;
            }
            $seen[$cur['id']] = true;
            if (! isset($rows[$cur['parent_id']])) {
                return true; // 존재하지 않는 부모 참조
            }
            $cur = $rows[$cur['parent_id']];
        }
    }

    return false;
}
check('적용 후: 부모-자식 순환 및 유령 부모 없음', ! hasCycle($rows));

// ── 7. 멱등성 — 같은 적용을 두 번 ────────────────────────────────────────────
$second = $plan->planApply(array_values($rows));
check('두 번째 적용은 변경 0건 (멱등)', count($second['changes']) === 0, '실제 '.count($second['changes']).'건');
$rowsTwice = $rows;
applyPlan($second, $rowsTwice, $plan);
check('두 번 적용해도 결과 동일', $rowsTwice === $rows);

// ── 8. 모듈 재동기화 — 코어 규칙 재현 ────────────────────────────────────────
/**
 * ExtensionMenuSyncHelper::syncMenu() 를 그대로 흉내 낸다.
 *   - parent_id 는 무조건 정의값
 *   - name/icon/order/url 은 user_overrides 에 있으면 보존
 */
function simulateSync(array $definitionTree, array &$rows)
{
    $byId = [];
    foreach ($rows as $id => $r) {
        $byId[$r['slug']] = $id;
    }
    $walk = function ($nodes, $parentId) use (&$walk, &$rows, $byId) {
        foreach ($nodes as $node) {
            $slug = $node['slug'];
            if (! isset($byId[$slug])) {
                continue;
            }
            $id = $byId[$slug];
            $ov = $rows[$id]['user_overrides'];

            $rows[$id]['parent_id'] = $parentId;              // 항상 덮어씀
            if (! in_array('name', $ov, true)) {
                $rows[$id]['name'] = is_array($node['name']) ? $node['name'] : ['ko' => $node['name']];
            }
            if (! in_array('icon', $ov, true)) {
                $rows[$id]['icon'] = isset($node['icon']) ? $node['icon'] : null;
            }
            if (! in_array('order', $ov, true)) {
                $rows[$id]['order'] = isset($node['order']) ? $node['order'] : 0;
            }
            if (! in_array('url', $ov, true)) {
                $rows[$id]['url'] = isset($node['url']) ? $node['url'] : null;
            }

            if (isset($node['children']) && is_array($node['children'])) {
                $walk($node['children'], $rows[$id]['id']);
            }
        }
    };
    $walk($definitionTree, null);
}

// 8-a. 플러그인 활성 상태의 재동기화 — 필터가 재작성 정의를 넘긴다
$rowsWithPlugin = $rows;
simulateSync($rewritten, $rowsWithPlugin);
$keptTop = true;
foreach (array_keys($topLevelTargets) as $slug) {
    foreach ($rowsWithPlugin as $r) {
        if ($r['slug'] === $slug && $r['parent_id'] !== null) {
            $keptTop = false;
        }
    }
}
check('모듈 재동기화(플러그인 활성): 승격 메뉴가 최상위 유지', $keptTop);
check('모듈 재동기화(플러그인 활성): 전체 배치 그대로', $rowsWithPlugin === $rows);

// 8-b. 플러그인 비활성 상태의 재동기화 — 원본 정의가 그대로 들어간다
//
// 이 검사가 확인하는 것은 **재동기화의 효과**이지 비활성화의 효과가 아니다.
// `plugin:deactivate` 자체는 DB 메뉴를 복원하지 않는다 — 비활성 상태에서 이커머스
// 메뉴 재동기화가 실제로 실행돼야 원래 모듈 정의 계층이 다시 적용된다.
// 정상 해제 순서는 `yutiv:admin-menu --rollback` → `plugin:deactivate` 이다.
$rowsNoPlugin = $rows;
simulateSync($definition, $rowsNoPlugin);
$revertedCount = 0;
foreach (array_keys($topLevelTargets) as $slug) {
    foreach ($rowsNoPlugin as $r) {
        if ($r['slug'] === $slug && $r['parent_id'] !== null) {
            $revertedCount++;
        }
    }
}
check('플러그인 비활성 상태에서 이커머스 메뉴 재동기화 시 원래 계층으로 되돌아감', $revertedCount === 5, '되돌아간 메뉴 '.$revertedCount.'개');

// ── 8-c. 코어 메뉴 재동기화 (CoreUpdateService::syncCoreMenus) ───────────────
// 코어 업데이트 경로다. ExtensionMenuSyncHelper 를 쓰므로 order 가 user_overrides 에
// 마킹돼 있으면 보존된다. 이 경로는 config('core.menus') 를 **필터 없이** 읽으므로
// (CoreUpdateService::getCoreMenuDefinitions) 플러그인 리스너가 관여하지 않는다.
$coreDefinition = [];
foreach ($coreTop as $m) {
    $coreDefinition[] = [
        'slug' => $m['slug'],
        'name' => $m['name'],
        'url' => $m['url'],
        'icon' => $m['icon'],
        'order' => $m['order'],   // config 원본 순서 (1~12)
    ];
}
$rowsCoreSync = $rows;
simulateSync($coreDefinition, $rowsCoreSync);
$coreOrderKept = true;
foreach (['admin-users' => 7, 'admin-settings' => 9, 'admin-schedules' => 18] as $slug => $want) {
    foreach ($rowsCoreSync as $r) {
        if ($r['slug'] === $slug && $r['order'] !== $want) {
            $coreOrderKept = false;
        }
    }
}
check('코어 메뉴 재동기화(코어 업데이트) 후에도 순서 유지 — user_overrides 보호', $coreOrderKept);
check('코어 재동기화 후 전체 배치 그대로', $rowsCoreSync === $rows);

// ── 8-d. 코어 메뉴 재시드 (CoreAdminMenuSeeder) ──────────────────────────────
// 시더는 코어 메뉴를 **삭제 후 재생성**한다 (user_overrides 소실). 대신 재생성 직전에
// `core.menus.config` 필터를 타므로, 플러그인 리스너가 넣은 순서로 만들어진다.
$filteredCore = $plan->rewriteCoreDefinition($coreTop);
$reseedOrders = [];
foreach ($filteredCore as $m) {
    $reseedOrders[$m['slug']] = $m['order'];
}
check('코어 재시드(필터 적용): 사용자 관리 order=7',
    ($reseedOrders['admin-users'] ?? null) === 7, '실제 '.var_export($reseedOrders['admin-users'] ?? null, true));
check('코어 재시드(필터 적용): 대시보드 order=1',
    ($reseedOrders['admin-dashboard'] ?? null) === 1);
check('코어 재시드(필터 적용): 나머지 코어가 9번 이후',
    ($reseedOrders['admin-settings'] ?? null) === 9 && ($reseedOrders['admin-schedules'] ?? null) === 18);

// 재시드 직후 상태를 만들어 본다 — 순서는 맞지만 user_overrides 가 비어 있다.
$rowsAfterReseed = $rows;
foreach ($rowsAfterReseed as $id => $r) {
    if ($r['extension_identifier'] === 'core') {
        $rowsAfterReseed[$id]['order'] = $reseedOrders[$r['slug']] ?? $r['order'];
        $rowsAfterReseed[$id]['user_overrides'] = [];   // 재생성된 행
    }
}
$reseedDrift = $plan->verify(array_values($rowsAfterReseed));
$unprotected = array_values(array_filter($reseedDrift, function ($d) {
    return $d['kind'] === 'unprotected';
}));
$orderDrift = array_values(array_filter($reseedDrift, function ($d) {
    return $d['kind'] === 'order';
}));
check('코어 재시드 후: 순서 drift 없음', empty($orderDrift), count($orderDrift).'건');
check('코어 재시드 후: 보호 표시 소실을 --check 가 감지', count($unprotected) === 12, '감지 '.count($unprotected).'건');

// 재적용하면 보호 표시가 복구된다 (명령의 --apply 가 하는 일)
foreach ($rowsAfterReseed as $id => $r) {
    if ($r['extension_identifier'] === 'core' && isset($plan->coreOrders()[$r['slug']])) {
        $rowsAfterReseed[$id]['user_overrides'] = ['order'];
    }
}
check('코어 재시드 후 재적용하면 drift 0', $plan->verify(array_values($rowsAfterReseed)) === []);

// ── 8-e. 플러그인 재활성화 ───────────────────────────────────────────────────
// 재활성화는 DB 를 건드리지 않는다 — 배치는 이미 기록돼 있고, 리스너가 다시 등록될 뿐이다.
// 재활성화 직후 동기화가 돌아도 8-a/8-c 와 같은 결과가 된다.
$rowsReactivated = $rows;
simulateSync($rewritten, $rowsReactivated);
simulateSync($coreDefinition, $rowsReactivated);
check('플러그인 재활성화 + 전체 재동기화 후에도 배치 그대로', $rowsReactivated === $rows);

// ── 8-f. drift 감지 (--check 계약) ───────────────────────────────────────────
check('배치 적용 상태에서 verify() 는 drift 0', $plan->verify(array_values($rows)) === []);

$rowsTampered = $rows;
foreach ($rowsTampered as $id => $r) {
    if ($r['slug'] === 'sirsoft-ecommerce-orders') {
        $rowsTampered[$id]['parent_id'] = $bySlug['sirsoft-ecommerce']['id']; // 누군가 다시 하위로 내림
    }
}
$tamperDrift = $plan->verify(array_values($rowsTampered));
check('누군가 메뉴를 되돌리면 verify() 가 감지', count($tamperDrift) > 0, '감지 '.count($tamperDrift).'건');

// ── 9. 롤백 — 스냅샷 복원 ────────────────────────────────────────────────────
$rowsRolledBack = $rows;
foreach ($snapshot as $id => $orig) {
    $rowsRolledBack[$id]['parent_id'] = $orig['parent_id'];
    $rowsRolledBack[$id]['order'] = $orig['order'];
    $rowsRolledBack[$id]['name'] = $orig['name'];
    $rowsRolledBack[$id]['icon'] = $orig['icon'];
    $rowsRolledBack[$id]['user_overrides'] = $orig['user_overrides'];
}
check('롤백 후 원래 계층·순서·이름 복원', $rowsRolledBack === $rowsBefore);

// ── 10. 다국어 ───────────────────────────────────────────────────────────────
$requiredLocales = ['ko', 'en', 'ja', 'zh-CN'];
$i18nMissing = [];
$i18nTargets = array_merge(array_keys($topLevelTargets), ['sirsoft-ecommerce'], array_keys($plan->settingsChildSlugs()));
foreach ($i18nTargets as $slug) {
    $name = $bySlug[$slug]['name'];
    foreach ($requiredLocales as $loc) {
        if (! isset($name[$loc]) || trim((string) $name[$loc]) === '') {
            $i18nMissing[] = "{$slug}.{$loc}";
        }
    }
}
check('적용 후: 최상위·설정 메뉴가 ko/en/ja/zh-CN 4개 로케일 보유', empty($i18nMissing), implode(', ', $i18nMissing));

$nonArrayName = [];
foreach ($i18nTargets as $slug) {
    if (! is_array($bySlug[$slug]['name'])) {
        $nonArrayName[] = $slug;
    }
}
check('적용 후: name 이 로케일 배열 계약 준수', empty($nonArrayName), implode(', ', $nonArrayName));

// ── 11. 모듈 비활성 시 이커머스 메뉴 비노출 ──────────────────────────────────
// 코어는 확장 비활성 시 해당 확장 소유 메뉴를 숨긴다. 배치 변경이 그 소유권을 건드리지
// 않았음을 확인하는 것으로 대신한다 (실제 비활성 동작은 서버 확인 항목).
$ecommerceOwned = array_values(array_filter($rows, function ($r) {
    return $r['extension_identifier'] === 'sirsoft-ecommerce';
}));
check('적용 후: 이커머스 메뉴 12개가 여전히 모듈 소유', count($ecommerceOwned) === 12, '실제 '.count($ecommerceOwned).'개');

// ── 12. 비활성화 허용 토큰 (allow-deactivate) 계약 ───────────────────────────
// 토큰 자체는 순수 클래스라 **프로덕션 코드를 그대로 실행**해 검증한다.
// 명령/가드의 호출 순서는 Laravel 없이 실행할 수 없으므로 소스 단언으로 고정한다.
$tokenDir = sys_get_temp_dir().'/yutiv-admin-menu-token-'.getmypid();
$token = DeactivationToken::inDirectory($tokenDir);

check('토큰: 경로가 <dir>/allow-deactivate', substr($token->path(), -17) === '/allow-deactivate', $token->path());
check('토큰: 최초에는 존재하지 않음', $token->exists() === false);

$token->issue(['snapshot' => 'snapshot-test.json', 'restored' => 24]);
check('토큰: issue() 로 발급된다', $token->exists() === true);

$payload = $token->consume();
check('토큰: consume() 가 페이로드를 돌려준다', is_array($payload) && ($payload['snapshot'] ?? null) === 'snapshot-test.json');
check('토큰: consume() 직후 파일이 삭제된다 (일회용)', $token->exists() === false);
check('토큰: 두 번째 consume() 는 null — 한 번 만든 파일로 반복 허용 없음', $token->consume() === null);

$token->issue([]);
check('토큰: revoke() 가 존재하는 토큰을 지운다', $token->revoke() === true && $token->exists() === false);
check('토큰: revoke() 는 없을 때 false', $token->revoke() === false);

// 12-b. 흐름 시뮬레이션 — 가드 결정 규칙 (plugin.php 의 순서를 그대로 따른다)
$guard = function (DeactivationToken $t, $applied) {
    if ($t->consume() !== null) {
        return 'allow-consumed';   // 토큰 소비 후 통과
    }

    return $applied ? 'blocked' : 'allow-not-applied';
};

// apply → deactivate : 토큰 없음 + 배치 적용 상태 → 차단
$token->revoke();
$appliedState = $plan->verify(array_values($rows)) === [];
check('흐름: apply 직후 deactivate 는 차단된다', $guard($token, $appliedState) === 'blocked');

// apply → rollback(성공) → deactivate : 토큰 소비 후 통과
$rolledBackState = $plan->verify(array_values($rowsRolledBack)) !== [];
$token->issue(['snapshot' => 'snapshot-flow.json', 'restored' => count($snapshot)]);
check('흐름: rollback 성공 후에만 토큰이 존재한다', $token->exists() === true && $rolledBackState);
check('흐름: rollback → deactivate 는 토큰을 소비하고 통과', $guard($token, false) === 'allow-consumed');
check('흐름: 소비 후 재차 deactivate 는 다시 차단', $guard($token, $appliedState) === 'blocked');

// rollback 실패 → 토큰 미발급 → 차단
$token->revoke();
check('흐름: rollback 실패·부분 실패 시 토큰이 없어 차단된다', $guard($token, $appliedState) === 'blocked');

@rmdir($tokenDir);

// 12-c. 호출 순서·로그 등급 — 소스 단언 (Laravel 없이는 실행 불가)
$cmdSrc = file_get_contents($pluginDir.'/src/Console/Commands/AdminMenuCommand.php');
$pluginSrc = file_get_contents($pluginDir.'/plugin.php');

// 성공 출력("적용 완료") 직전 구간에 **주석 아닌** 폐기 호출이 있어야 한다.
// (파일 어딘가에 호출이 하나라도 있으면 통과하는 느슨한 검사는 결함을 놓친다 —
//  실제로 그 형태는 폐기 호출을 지운 결함을 PASS 시켰다.)
$applyOk = strpos($cmdSrc, '적용 완료 — 메뉴');
$applyRegion = $applyOk === false ? '' : substr($cmdSrc, max(0, $applyOk - 600), 600);
check('소스: --apply 성공 직전에 토큰 폐기 호출이 있다',
    $applyOk !== false
    && preg_match('/\n[ \t]*\$this->revokeDeactivationToken\(\);/', $applyRegion) === 1,
    '성공 출력 직전 600자에서 미검출');

// 이미 적용된 상태로 --apply 한 경우(변경 0건)도 성공이므로 같은 폐기가 필요하다.
$noopIdx = strpos($cmdSrc, '변경할 내용이 없습니다');
$noopRegion = $noopIdx === false ? '' : substr($cmdSrc, $noopIdx, 400);
check('소스: --apply 가 변경 0건으로 성공해도 토큰을 폐기한다',
    preg_match('/\n[ \t]*\$this->revokeDeactivationToken\(\);/', $noopRegion) === 1);

check('소스: 토큰 폐기 호출이 주석 처리되어 있지 않다',
    preg_match('#//[ \t]*\$this->revokeDeactivationToken#', $cmdSrc) === 0);

$verifyCall = strpos($cmdSrc, '$mismatch = $this->verifyRestored(');
$issueCall = strpos($cmdSrc, '$issued = $token->issue(');
check('소스: --rollback 은 복원 후 검증(verifyRestored) 뒤에 토큰을 발급한다',
    $verifyCall !== false && $issueCall !== false && $verifyCall < $issueCall);

check('소스: --rollback 트랜잭션 실패 시 failRollback 후 FAILURE',
    strpos($cmdSrc, '복원 트랜잭션 실패') !== false);
check('소스: --rollback 부분 복원(누락) 시 failRollback 후 FAILURE',
    strpos($cmdSrc, '부분 복원 (누락 ') !== false);
check('소스: --rollback 검증 실패 시 failRollback 후 FAILURE',
    strpos($cmdSrc, '복원 후 검증 실패 (') !== false);
check('소스: failRollback 이 토큰을 발급하지 않고 기존 토큰도 폐기한다',
    strpos($cmdSrc, '비활성화 허용 토큰을 발급하지 않았습니다') !== false
    && strpos($cmdSrc, 'private function failRollback') !== false
    && preg_match('/function failRollback.*?revokeDeactivationToken\(\)/s', $cmdSrc) === 1);

$consumeCall = strpos($pluginSrc, '$payload = $token->consume();');
$driftCall = strpos($pluginSrc, '$drift = self::layoutPlan()->verify($rows);');
check('소스: plugin:deactivate 가 토큰을 소비(consume)한 뒤 통과시킨다',
    $consumeCall !== false && $driftCall !== false && $consumeCall < $driftCall);
check('소스: 가드가 is_file 단순 확인이 아니라 소비형이다',
    strpos($pluginSrc, "is_file(\$override)") === false);

check('소스: fail-open 이 critical 등급으로 기록된다',
    strpos($pluginSrc, 'Log::critical(') !== false
    && strpos($pluginSrc, 'fail-open') !== false);
check('소스: fail-open 로그가 DB 복원을 주장하지 않는다',
    strpos($pluginSrc, "'db_menus_restored' => false") !== false
    && strpos($pluginSrc, 'DB 메뉴 배치는 복원되지 않았습니다') !== false);
check('소스: fail-open 경로가 남은 토큰을 폐기한다',
    preg_match('/Log::critical\(.*?\$token->revoke\(\)/s', $pluginSrc) === 1);

// ── 출력 ─────────────────────────────────────────────────────────────────────
/** 메뉴 트리를 사람이 읽는 문자열로. */
function renderTree(array $rows, $locale = 'ko')
{
    $byParent = [];
    foreach ($rows as $r) {
        $byParent[$r['parent_id'] === null ? 0 : $r['parent_id']][] = $r;
    }
    foreach ($byParent as &$g) {
        usort($g, function ($a, $b) {
            return $a['order'] <=> $b['order'];
        });
    }
    unset($g);

    $out = [];
    $walk = function ($parentKey, $depth) use (&$walk, &$out, $byParent, $locale) {
        if (! isset($byParent[$parentKey])) {
            return;
        }
        foreach ($byParent[$parentKey] as $r) {
            $name = is_array($r['name']) ? (isset($r['name'][$locale]) ? $r['name'][$locale] : reset($r['name'])) : $r['name'];
            $out[] = sprintf('%s%-22s  order=%-3d  %s', str_repeat('    ', $depth), $name, $r['order'], (string) $r['url']);
            $walk($r['id'], $depth + 1);
        }
    };
    $walk(0, 0);

    return implode("\n", $out);
}

echo "=== YUTIV 관리자 메뉴 배치 검증 ===\n\n";
echo '메뉴 행 '.count($rows)."개 (코어 12 + 이커머스 12) · 계획된 변경 {$plannedCount}건\n\n";

if ($verbose) {
    echo "── 변경 전 ──\n".renderTree($rowsBefore)."\n\n";
    echo "── 변경 후 ──\n".renderTree($rows)."\n\n";
}

foreach ($passes as $p) {
    echo "  OK   {$p}\n";
}
foreach ($violations as $v) {
    echo "  FAIL {$v}\n";
}

echo "\n";
if ($makePreview) {
    $previewDir = $root.'/tests/Menu/output';
    if (! is_dir($previewDir)) {
        mkdir($previewDir, 0777, true);
    }
    $html = "<!doctype html><html lang=\"ko\"><head><meta charset=\"utf-8\"><title>YUTIV 관리자 메뉴 배치</title>"
        ."<style>body{font:14px/1.7 system-ui;margin:40px auto;max-width:1000px;padding:0 16px}"
        ."h2{margin-top:2em}pre{background:#f6f6f6;padding:16px;border-radius:6px;overflow:auto}"
        ."table{border-collapse:collapse}td{padding:0 24px 0 0;vertical-align:top}</style></head><body>"
        .'<h1>YUTIV 쇼핑몰 관리자 메뉴 배치</h1>'
        .'<p>이 문서는 <b>코어 동기화 규칙을 재현한 시뮬레이션</b> 결과입니다. 실제 관리자 화면 렌더가 아닙니다 '
        .'— 서버 E2E 의 대체물이 아닙니다.</p>'
        .'<table><tr><td><h2>변경 전</h2><pre>'.htmlspecialchars(renderTree($rowsBefore)).'</pre></td>'
        .'<td><h2>변경 후</h2><pre>'.htmlspecialchars(renderTree($rows)).'</pre></td></tr></table>'
        .'<h2>로케일별 최상위 이름</h2><pre>';
    foreach (['ko', 'en', 'ja', 'zh-CN'] as $loc) {
        $html .= htmlspecialchars(sprintf("[%s]\n%s\n\n", $loc, renderTree($rows, $loc)));
    }
    $html .= '</pre></body></html>';
    file_put_contents($previewDir.'/menu-layout-preview.html', $html);
    echo '프리뷰: '.$previewDir."/menu-layout-preview.html\n\n";
}

printf("RESULT: %s — 통과 %d건, 위반 %d건\n", $violations ? 'FAIL' : 'PASS', count($passes), count($violations));
exit($violations ? 1 : 0);
