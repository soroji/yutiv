<?php

/**
 * yutiv-commerce 사용자 템플릿 정합성 검사기 (standalone CLI — vendor/Laravel 불필요).
 *
 * 이 템플릿은 `sirsoft-basic` 에서 파생된 **독립 템플릿**이라, 원본이 바뀌어도 자동으로
 * 따라가지 않는다. 그래서 "복사한 뒤 손댄 부분"이 계약을 깨지 않았는지 파일 단위로 고정한다.
 *
 * 검사 항목:
 *   1. JSON 전수 파싱
 *   2. template.json 필수 필드 + validator 규칙 (identifier 형식/디렉토리명/type/locales)
 *   3. 원본(sirsoft-basic) 무변경 — 이 검사기는 원본을 읽기만 한다
 *   4. routes.json 계약 (홈/상품/장바구니/주문/마이페이지 라우트 보존)
 *   5. 레이아웃이 참조하는 partial 파일 실재
 *   6. 레이아웃이 참조하는 컴포넌트가 components.json 에 선언되어 있는지
 *   7. 홈이 쓰는 data source endpoint 가 실제 이커머스 모듈 라우트에 존재하는지
 *   8. 홈에 커뮤니티 잔재(통계 카드·최근 게시글·인기 게시판·커뮤니티 가이드)가 없는지
 *   9. 홈 레이아웃의 하드코딩 문구 부재 ($t: 번역키 사용)
 *  10. 4개 로케일 번역 키 집합 동일 + 로케일 오염(zh-CN 에 한글/가나, ja 에 한글)
 *  11. UTF-8 / BOM / CRLF
 *
 * 사용법:
 *   php tests/Translations/yutiv-commerce-template-check.php [--verbose]
 */
$root = dirname(__DIR__, 2);
$tpl = $root.'/templates/_bundled/yutiv-commerce';
$origin = $root.'/templates/_bundled/sirsoft-basic';
$verbose = in_array('--verbose', $argv, true);

$violations = [];
$counts = [];

/** 위반을 기록합니다. */
function fail(string $category, string $message): void
{
    global $violations;
    $violations[$category][] = $message;
}

/** 통계를 올립니다. */
function bump(string $key, int $n = 1): void
{
    global $counts;
    $counts[$key] = ($counts[$key] ?? 0) + $n;
}

/** 디렉토리 아래 모든 파일을 반환합니다. */
function allFiles(string $dir): array
{
    if (! is_dir($dir)) {
        return [];
    }
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $out[] = $f->getPathname();
    }
    sort($out);

    return $out;
}

/** 중첩 배열을 dot-path 키 목록으로 평탄화합니다. */
function flattenKeys($node, string $prefix = ''): array
{
    if (! is_array($node)) {
        return [$prefix];
    }
    $keys = [];
    foreach ($node as $k => $v) {
        $p = $prefix === '' ? (string) $k : $prefix.'.'.$k;
        $keys = array_merge($keys, flattenKeys($v, $p));
    }

    return $keys;
}

/** 중첩 배열을 dot-path ⇒ 문자열 값 맵으로 평탄화합니다. */
function flattenValues($node, string $prefix = ''): array
{
    if (! is_array($node)) {
        return is_string($node) ? [$prefix => $node] : [];
    }
    $out = [];
    foreach ($node as $k => $v) {
        $p = $prefix === '' ? (string) $k : $prefix.'.'.$k;
        $out += flattenValues($v, $p);
    }

    return $out;
}

// ── 0. 템플릿 존재 ───────────────────────────────────────────────────────────────
if (! is_dir($tpl)) {
    echo "FATAL: {$tpl} 이 없습니다\n";
    exit(1);
}

// ── 1. JSON 전수 파싱 + 인코딩 ───────────────────────────────────────────────────
$jsonFiles = [];
foreach (allFiles($tpl) as $path) {
    $raw = file_get_contents($path);
    $rel = substr($path, strlen($tpl) + 1);

    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
        fail('encoding', "BOM: $rel");
    }
    if (strpos($raw, "\r") !== false && ! preg_match('/\.(png|jpg|jpeg|gif|ico|woff2?|ttf)$/i', $rel)) {
        fail('encoding', "CRLF: $rel");
    }
    if (! preg_match('/\.(png|jpg|jpeg|gif|ico|woff2?|ttf)$/i', $rel) && ! mb_check_encoding($raw, 'UTF-8')) {
        fail('encoding', "UTF-8 아님: $rel");
    }

    // tsconfig 계열은 JSONC(주석 허용) 규약이라 엄격 JSON 파서로 검사하지 않는다.
    // 원본 sirsoft-basic 도 동일하게 주석을 담고 있으며 TypeScript 툴체인만 읽는다.
    $isJsonc = in_array(basename($path), ['tsconfig.json', 'tsconfig.node.json'], true);

    if (substr($path, -5) === '.json' && ! $isJsonc) {
        $jsonFiles[] = $path;
        json_decode($raw);
        if (json_last_error() !== JSON_ERROR_NONE) {
            fail('json', "$rel — ".json_last_error_msg());
        }
    }
}
bump('json_files', count($jsonFiles));

// ── 2. template.json 계약 ────────────────────────────────────────────────────────
$manifest = json_decode(file_get_contents($tpl.'/template.json'), true);
if (! is_array($manifest)) {
    fail('manifest', 'template.json 파싱 실패');
} else {
    // TemplateManager::validateTemplateData 의 필수 필드
    foreach (['identifier', 'vendor', 'name', 'version', 'type'] as $f) {
        if (! isset($manifest[$f])) {
            fail('manifest', "필수 필드 누락: $f");
        }
    }
    if (($manifest['type'] ?? null) !== 'user') {
        fail('manifest', "type 은 'user' 여야 합니다 (현재: ".var_export($manifest['type'] ?? null, true).')');
    }
    if (($manifest['identifier'] ?? null) !== 'yutiv-commerce') {
        fail('manifest', 'identifier 는 yutiv-commerce 여야 합니다');
    }
    // TemplateManager::loadTemplates 의 디렉토리명 정규식
    if (! preg_match('/^[a-z0-9]+-[a-z0-9_]+$/i', basename($tpl))) {
        fail('manifest', '디렉토리명이 vendor-name 형식이 아닙니다: '.basename($tpl));
    }
    // ValidExtensionIdentifier 규칙 재현
    $parts = explode('-', (string) ($manifest['identifier'] ?? ''));
    if (count($parts) < 2) {
        fail('manifest', 'identifier 는 vendor-name 2부분 이상이어야 합니다');
    }
    foreach ($parts as $part) {
        if ($part === '' || ! preg_match('/^[a-z0-9_]+$/', $part)) {
            fail('manifest', "identifier 부분이 소문자/숫자/밑줄 규칙 위반: '$part'");
        }
        foreach (explode('_', $part) as $word) {
            if ($word === '' || preg_match('/^[0-9]/', $word)) {
                fail('manifest', "identifier 단어가 비었거나 숫자로 시작: '$word'");
            }
        }
    }
    if (($manifest['version'] ?? null) !== '1.0.0') {
        fail('manifest', '초기 버전은 1.0.0 이어야 합니다');
    }
    if (($manifest['license'] ?? null) !== 'MIT') {
        fail('manifest', 'license 는 원본과 동일한 MIT 여야 합니다');
    }
    $expectedLocales = ['ko', 'en', 'ja', 'zh-CN'];
    if (($manifest['locales'] ?? []) !== $expectedLocales) {
        fail('manifest', 'locales 는 '.implode(',', $expectedLocales).' 여야 합니다');
    }
    // 이커머스 의존성 — 홈이 이커머스 공개 API 에 의존하므로 선언이 계약이다
    if (! isset($manifest['dependencies']['modules']['sirsoft-ecommerce'])) {
        fail('manifest', 'dependencies.modules.sirsoft-ecommerce 선언 필요 (홈이 이커머스 API 를 쓴다)');
    }
    // 자산 경로 실재
    foreach (array_merge($manifest['assets']['css'] ?? [], $manifest['assets']['js'] ?? []) as $asset) {
        if (! is_file($tpl.'/'.$asset)) {
            fail('manifest', "assets 경로가 실재하지 않음: $asset");
        }
    }
    // 오류 레이아웃 실재
    foreach ($manifest['error_config']['layouts'] ?? [] as $code => $layoutName) {
        if (! is_file($tpl.'/layouts/'.$layoutName.'.json')) {
            fail('manifest', "error_config.$code 레이아웃 파일 없음: $layoutName.json");
        }
    }
}

// components.json templateId
$componentsManifest = json_decode(file_get_contents($tpl.'/components.json'), true);
if (($componentsManifest['templateId'] ?? null) !== 'yutiv-commerce') {
    fail('manifest', 'components.json templateId 가 yutiv-commerce 가 아닙니다');
}

// LICENSE 원저작권 보존
$license = file_get_contents($tpl.'/LICENSE');
foreach (['Copyright (c) 2026 SIRSOFT', '(주)에스아이알소프트'] as $needle) {
    if (strpos($license, $needle) === false) {
        fail('license', "원본 저작권 고지 누락: $needle");
    }
}

// ── 3. 원본 무변경 ───────────────────────────────────────────────────────────────
// 이 검사기는 원본을 읽기만 한다. 원본이 존재하고 identifier 가 그대로인지만 확인한다.
$originManifest = json_decode(@file_get_contents($origin.'/template.json'), true);
if (($originManifest['identifier'] ?? null) !== 'sirsoft-basic') {
    fail('origin', '원본 sirsoft-basic template.json 이 훼손되었습니다');
}

// ── 4. routes.json 계약 ──────────────────────────────────────────────────────────
$routes = json_decode(file_get_contents($tpl.'/routes.json'), true);
$routeList = $routes['routes'] ?? [];
bump('routes', count($routeList));
$paths = array_map(fn ($r) => $r['path'] ?? '', $routeList);

$homeRoute = null;
foreach ($routeList as $r) {
    if (($r['path'] ?? '') === '/') {
        $homeRoute = $r;
    }
}
if ($homeRoute === null) {
    fail('routes', '`/` 라우트가 없습니다');
} else {
    if (($homeRoute['layout'] ?? '') !== 'home') {
        fail('routes', '`/` 는 home 레이아웃이어야 합니다');
    }
    if (isset($homeRoute['redirect'])) {
        fail('routes', '`/` 를 redirect 로 처리하면 안 됩니다 (쇼핑몰 홈을 직접 구성해야 함)');
    }
}

// 이커머스/마이페이지 경로 보존 — 원본과 path 집합이 동일해야 한다
$originRoutes = json_decode(file_get_contents($origin.'/routes.json'), true)['routes'] ?? [];
$originPaths = array_map(fn ($r) => $r['path'] ?? '', $originRoutes);
sort($paths);
sort($originPaths);
if ($paths !== $originPaths) {
    $missing = array_values(array_diff($originPaths, $paths));
    $extra = array_values(array_diff($paths, $originPaths));
    if ($missing) {
        fail('routes', '원본에 있으나 사라진 라우트: '.implode(', ', array_slice($missing, 0, 5)));
    }
    if ($extra) {
        fail('routes', '원본에 없는 신규 라우트: '.implode(', ', array_slice($extra, 0, 5)));
    }
}

// ── 5~9. 레이아웃 검사 ──────────────────────────────────────────────────────────
$layoutDir = $tpl.'/layouts';
$declared = [];
foreach (($componentsManifest['components'] ?? []) as $group => $items) {
    foreach ($items as $item) {
        $name = is_array($item) ? ($item['name'] ?? null) : $item;
        if (is_string($name)) {
            $declared[$name] = true;
        }
    }
}

$layoutFiles = array_values(array_filter(allFiles($layoutDir), fn ($p) => substr($p, -5) === '.json'));
bump('layouts', count($layoutFiles));

foreach ($layoutFiles as $path) {
    $rel = substr($path, strlen($layoutDir) + 1);
    $data = json_decode(file_get_contents($path), true);
    if (! is_array($data)) {
        continue;
    }

    $walk = function ($node) use (&$walk, $rel, $layoutDir, $declared, $path) {
        if (! is_array($node)) {
            return;
        }
        // partial 참조 실재
        if (isset($node['partial']) && is_string($node['partial'])) {
            $candidates = [
                dirname($path).'/'.$node['partial'],
                $layoutDir.'/'.$node['partial'],
            ];
            $found = false;
            foreach ($candidates as $c) {
                if (is_file($c)) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                fail('partials', "$rel → 참조한 partial 없음: ".$node['partial']);
            }
        }
        // 컴포넌트 선언 여부
        if (isset($node['name'], $node['type']) && is_string($node['name'])
            && in_array($node['type'], ['basic', 'composite', 'layout'], true)) {
            if (! isset($declared[$node['name']])) {
                fail('components', "$rel → components.json 미선언 컴포넌트: ".$node['name']);
            }
        }
        foreach ($node as $v) {
            $walk($v);
        }
    };
    $walk($data);
}

// 홈 전용 검사
$homeRaw = file_get_contents($layoutDir.'/home.json');
$home = json_decode($homeRaw, true);

// 7. data source endpoint 실재 (이커머스 모듈 라우트 파일 대조)
$ecomRoutes = @file_get_contents($root.'/modules/_bundled/sirsoft-ecommerce/src/routes/api.php');
$endpointChecks = [
    '/api/modules/sirsoft-ecommerce/categories' => "prefix('categories')",
    '/api/modules/sirsoft-ecommerce/products' => "prefix('products')",
    '/api/modules/sirsoft-ecommerce/products/new' => "get('/new'",
    '/api/modules/sirsoft-ecommerce/products/popular' => "get('/popular'",
];
$homeEndpoints = [];
foreach ($home['data_sources'] ?? [] as $ds) {
    $ep = $ds['endpoint'] ?? '';
    $homeEndpoints[] = $ep;
    $bare = strtok($ep, '?');
    if (! isset($endpointChecks[$bare])) {
        fail('datasource', "홈이 알 수 없는 endpoint 를 씁니다: $ep");

        continue;
    }
    if ($ecomRoutes !== false && strpos($ecomRoutes, $endpointChecks[$bare]) === false) {
        fail('datasource', "이커머스 라우트에서 근거를 찾지 못함: $ep");
    }
}
bump('home_datasources', count($homeEndpoints));

// 8. 커뮤니티 잔재 제거
$homeTree = json_encode($home, JSON_UNESCAPED_UNICODE);
foreach ([
    'sirsoft-board' => '게시판 API',
    '_stat_card' => '통계 카드 partial',
    '_recent_posts' => '최근 게시글 partial',
    '_popular_boards' => '인기 게시판 partial',
    '_community_guide' => '커뮤니티 가이드 partial',
    '_welcome_card' => '커뮤니티 웰컴 카드',
] as $needle => $label) {
    if (strpos($homeTree, $needle) !== false) {
        fail('home_cleanup', "홈에 커뮤니티 잔재가 남아 있습니다: $label ($needle)");
    }
}
// 삭제한 파셜 파일이 실제로 없어야 한다
foreach (['_welcome_card', '_stat_card_users', '_stat_card_posts', '_stat_card_comments',
    '_stat_card_boards', '_recent_posts', '_popular_boards', '_community_guide', '_board_summary'] as $gone) {
    if (is_file($layoutDir.'/partials/home/'.$gone.'.json')) {
        fail('home_cleanup', "삭제되어야 할 홈 파셜이 남아 있습니다: $gone.json");
    }
}

// 9. 홈 파셜 하드코딩 문구 부재 — text/label 값이 번역키·표현식·기호 외의 자연어면 위반
$homePartials = array_values(array_filter(allFiles($layoutDir.'/partials/home'), fn ($p) => substr($p, -5) === '.json'));
bump('home_partials', count($homePartials));
foreach ($homePartials as $path) {
    $rel = basename($path);
    $data = json_decode(file_get_contents($path), true);
    $walkText = function ($node) use (&$walkText, $rel) {
        if (! is_array($node)) {
            return;
        }
        foreach (['text', 'label', 'placeholder', 'title'] as $prop) {
            $v = $node[$prop] ?? null;
            if (! is_string($v) || $v === '') {
                continue;
            }
            $isKey = strpos($v, '$t:') === 0;
            $isExpr = strpos($v, '{{') !== false;
            if (! $isKey && ! $isExpr) {
                fail('hardcoded_text', "$rel → $prop 하드코딩: \"$v\"");
            }
        }
        foreach ($node as $v) {
            $walkText($v);
        }
    };
    $walkText($data);
}

// ── 9b. 홈이 쓰는 Tailwind 클래스가 빌드된 CSS 에 실제로 존재하는지 ───────────────
//
// Tailwind v4 는 `src/**` 와 `src/styles/safelist.txt` 만 스캔한다. 레이아웃 JSON 은 스캔
// 대상이 **아니다**. 따라서 여기서 CSS 에 없는 클래스를 쓰면 규칙이 생성되지 않아 스타일만
// 조용히 빠진다 — 콘솔 오류도, 렌더 실패도 없다. 실제로 최초 구현에서 27종이 이 상태였다.
// 이 검사가 곧 "프론트엔드 빌드가 필요한가"의 판정이기도 하다(0종이면 불필요).
$cssPath = $tpl.'/dist/css/components.css';
$safelistPath = $tpl.'/src/styles/safelist.txt';
if (! is_file($cssPath)) {
    fail('tailwind', 'dist/css/components.css 가 없습니다');
} else {
    $css = file_get_contents($cssPath);
    $safelist = is_file($safelistPath) ? file_get_contents($safelistPath) : '';

    // 디자인을 직접 작성·교체한 레이아웃 전체가 검사 대상이다. 홈뿐 아니라 상품 카드·
    // 스켈레톤·상품 목록·상품 상세 헤더·페이지 본문까지 같은 규칙을 적용한다 —
    // Tailwind v4 는 레이아웃 JSON 을 스캔하지 않으므로 없는 클래스는 조용히 스타일만 빠진다.
    $designedFiles = array_merge(
        [$layoutDir.'/home.json'],
        $homePartials,
        array_filter([
            $layoutDir.'/partials/shop/_product_card_image.json',
            $layoutDir.'/partials/shop/_product_card_info.json',
            $layoutDir.'/partials/shop/_product_skeleton.json',
            $layoutDir.'/partials/shop/list/_product_grid.json',
            $layoutDir.'/partials/shop/list/_new_products.json',
            $layoutDir.'/partials/shop/list/_popular_products.json',
            $layoutDir.'/partials/shop/list/_recent_products.json',
            $layoutDir.'/partials/shop/detail/_header.json',
            $layoutDir.'/shop/index.json',
            $layoutDir.'/shop/show.json',
            $layoutDir.'/page/show.json',
        ], 'is_file')
    );
    bump('designed_layouts', count($designedFiles));

    /**
     * className 값에서 정적으로 검증 가능한 클래스 토큰을 뽑는다.
     *
     * 표현식이 섞인 값(`{{cond ? 'a b' : 'c d'}}`)은 통째로 버리지 않고 작은따옴표
     * 리터럴 안쪽을 훑는다 — 상품 목록의 활성/비활성 카테고리 링크처럼 클래스가
     * 삼항 안에만 있는 자리가 실제로 있기 때문이다. 다만 리터럴에는 경로('/shop')나
     * 평범한 단어도 섞이므로, 하이픈이나 콜론을 가진 토큰만 유틸리티로 간주한다.
     */
    $extractClassTokens = static function (string $val): array {
        $out = [];
        $push = static function (string $chunk, bool $utilityOnly) use (&$out) {
            foreach (preg_split('/\s+/', $chunk) as $tok) {
                $tok = trim($tok);
                if ($tok === '' || strpos($tok, '{{') !== false || strpos($tok, '}}') !== false) {
                    continue;
                }
                if ($utilityOnly && strpbrk($tok, '-:') === false) {
                    continue;
                }
                $out[$tok] = true;
            }
        };

        if (strpos($val, '{{') === false) {
            $push($val, false);

            return $out;
        }

        // 표현식 밖의 고정 부분
        $push(preg_replace('/\{\{.*?\}\}/s', ' ', $val), false);
        // 표현식 안의 문자열 리터럴
        if (preg_match_all("/'([^']*)'/", $val, $lits)) {
            foreach ($lits[1] as $lit) {
                $push($lit, true);
            }
        }

        return $out;
    };

    $classTokens = [];
    foreach ($designedFiles as $path) {
        if (preg_match_all('/"className"\s*:\s*"([^"]*)"/', file_get_contents($path), $m)) {
            foreach ($m[1] as $val) {
                $classTokens += $extractClassTokens($val);
            }
        }
    }
    bump('home_css_classes', count($classTokens));

    foreach (array_keys($classTokens) as $class) {
        // CSS 셀렉터에 실재하는 클래스명 집합과 대조한다. Tailwind 는 특수문자를
        // 백슬래시로 이스케이프하므로(`.text-\[10px\]`) 문자별 이스케이프 목록을
        // 손으로 관리하면 대괄호·괄호·퍼센트 같은 임의값 클래스에서 오탐이 난다.
        // 셀렉터에서 클래스명을 한 번만 수집해 두고 집합 조회로 판정한다.
        static $cssClassSet = null;
        if ($cssClassSet === null) {
            $cssClassSet = [];
            if (preg_match_all('/\.((?:\\\\.|[A-Za-z0-9_-])+)/', $css, $sel)) {
                foreach ($sel[1] as $raw) {
                    $cssClassSet[stripslashes($raw)] = true;
                }
            }
        }
        $inCss = isset($cssClassSet[$class]);
        $inSafelist = $safelist !== '' && preg_match('/(^|\s)'.preg_quote($class, '/').'(\s|$)/m', $safelist);
        if (! $inCss && ! $inSafelist) {
            fail('tailwind', "빌드 CSS 에 없는 클래스(스타일이 조용히 빠짐): $class");
        }
    }
}

// ── 10. 4개 로케일 번역 ──────────────────────────────────────────────────────────
$locales = ['ko', 'en', 'ja', 'zh-CN'];
$langDir = $tpl.'/lang';
$keySets = [];
foreach ($locales as $loc) {
    $entry = $langDir.'/'.$loc.'.json';
    if (! is_file($entry)) {
        fail('lang', "진입 파일 없음: lang/$loc.json");

        continue;
    }
    $entryData = json_decode(file_get_contents($entry), true);
    // $partial 경로가 해당 로케일을 가리키는지 + 실재하는지
    foreach ($entryData as $ns => $node) {
        $pp = $node['$partial'] ?? null;
        if (! is_string($pp)) {
            continue;
        }
        if (strpos($pp, 'partial/'.$loc.'/') !== 0) {
            fail('lang', "lang/$loc.json → \$partial 경로가 $loc 로케일이 아님: $pp");
        }
        if (! is_file($langDir.'/'.$pp)) {
            fail('lang', "lang/$loc.json → \$partial 파일 없음: $pp");
        }
    }
    // 파셜 키 집합 수집
    $merged = [];
    foreach (glob($langDir.'/partial/'.$loc.'/*.json') as $pf) {
        $merged[basename($pf)] = json_decode(file_get_contents($pf), true);
    }
    $keySets[$loc] = $merged;
    bump('lang_files_'.$loc, count($merged));
}

$koSet = $keySets['ko'] ?? [];
foreach ($locales as $loc) {
    if ($loc === 'ko' || ! isset($keySets[$loc])) {
        continue;
    }
    $set = $keySets[$loc];
    $missingFiles = array_values(array_diff(array_keys($koSet), array_keys($set)));
    $extraFiles = array_values(array_diff(array_keys($set), array_keys($koSet)));
    if ($missingFiles) {
        fail('lang', "[$loc] ko 에 있으나 없는 파일: ".implode(', ', $missingFiles));
    }
    if ($extraFiles) {
        fail('lang', "[$loc] ko 에 없는 잉여 파일: ".implode(', ', $extraFiles));
    }
    foreach ($koSet as $file => $koData) {
        if (! isset($set[$file])) {
            continue;
        }
        $koKeys = flattenKeys($koData);
        $locKeys = flattenKeys($set[$file]);
        $miss = array_values(array_diff($koKeys, $locKeys));
        $extra = array_values(array_diff($locKeys, $koKeys));
        if ($miss) {
            fail('lang', "[$loc/$file] 누락 키: ".implode(', ', array_slice($miss, 0, 3)));
        }
        if ($extra) {
            fail('lang', "[$loc/$file] 잉여 키: ".implode(', ', array_slice($extra, 0, 3)));
        }
    }
}

// 로케일 오염 — zh-CN 에 한글/가나, ja 에 한글
$pollution = [
    'zh-CN' => ['/[\x{AC00}-\x{D7A3}]/u' => '한글', '/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}]/u' => '일본어 가나'],
    'ja' => ['/[\x{AC00}-\x{D7A3}]/u' => '한글'],
];
foreach ($pollution as $loc => $patterns) {
    foreach ($keySets[$loc] ?? [] as $file => $data) {
        foreach (flattenValues($data) as $key => $val) {
            foreach ($patterns as $re => $label) {
                if (preg_match($re, $val)) {
                    fail('lang_pollution', "[$loc/$file] $key 에 $label 잔존");
                }
            }
        }
    }
}

// 홈이 쓰는 번역키가 4개 로케일에 모두 있는지
preg_match_all('/\$t:([a-zA-Z0-9_.\-]+)/', implode("\n", array_map('file_get_contents', array_merge([$layoutDir.'/home.json'], $homePartials))), $m);
$usedKeys = array_values(array_unique($m[1]));
bump('home_translation_keys', count($usedKeys));
foreach ($usedKeys as $key) {
    $segments = explode('.', $key);
    $ns = array_shift($segments);
    foreach ($locales as $loc) {
        $data = $keySets[$loc][$ns.'.json'] ?? null;
        if ($data === null) {
            fail('lang', "[$loc] 번역 네임스페이스 파일 없음: $ns.json (키 $key)");

            continue;
        }
        $cursor = $data;
        foreach ($segments as $seg) {
            if (! is_array($cursor) || ! array_key_exists($seg, $cursor)) {
                fail('lang', "[$loc] 홈이 쓰는 번역키 없음: $key");
                $cursor = null;
                break;
            }
            $cursor = $cursor[$seg];
        }
        if (is_string($cursor) && trim($cursor) === '') {
            fail('lang', "[$loc] 번역값이 비어 있음: $key");
        }
    }
}

// ── 결과 출력 ───────────────────────────────────────────────────────────────────
$categories = [
    'json' => 'JSON 구문',
    'encoding' => '인코딩 (UTF-8/BOM/CRLF)',
    'manifest' => 'template.json 계약',
    'license' => 'LICENSE 저작권 보존',
    'origin' => '원본 무변경',
    'routes' => 'routes.json 계약',
    'partials' => 'partial 참조',
    'components' => '컴포넌트 선언',
    'datasource' => 'data source endpoint',
    'home_cleanup' => '홈 커뮤니티 잔재 제거',
    'hardcoded_text' => '홈 하드코딩 문구',
    'tailwind' => '디자인 레이아웃 Tailwind 클래스 실재',
    'lang' => '다국어 키 정합성',
    'lang_pollution' => '로케일 오염',
];

echo "=== yutiv-commerce 템플릿 정합성 검사 ===\n\n";
printf("검사 파일 %d개 (JSON %d) · 레이아웃 %d개 · 홈 파셜 %d개 · 라우트 %d개 · 홈 데이터소스 %d개 · 홈 번역키 %d개\n\n",
    count(allFiles($tpl)), $counts['json_files'] ?? 0, $counts['layouts'] ?? 0,
    $counts['home_partials'] ?? 0, $counts['routes'] ?? 0,
    $counts['home_datasources'] ?? 0, $counts['home_translation_keys'] ?? 0);
printf("디자인 레이아웃 %d개 · CSS 클래스 %d종 (전부 빌드된 dist/css/components.css 에 존재해야 함)\n\n",
    $counts['designed_layouts'] ?? 0, $counts['home_css_classes'] ?? 0);

$total = 0;
foreach ($categories as $key => $label) {
    $list = $violations[$key] ?? [];
    $n = count($list);
    $total += $n;
    printf("%-28s %s (%d)\n", $label, $n === 0 ? 'OK  ' : 'FAIL', $n);
    if ($n > 0) {
        foreach (array_slice($list, 0, $verbose ? 100 : 8) as $msg) {
            echo "    - $msg\n";
        }
        if (! $verbose && $n > 8) {
            echo '    … 외 '.($n - 8)."건 (--verbose 로 전체 표시)\n";
        }
    }
}

foreach (['ko', 'en', 'ja', 'zh-CN'] as $loc) {
    printf("  lang/%-6s %2d 파일\n", $loc, $counts['lang_files_'.$loc] ?? 0);
}

echo "\n".($total === 0 ? 'RESULT: PASS — 위반 0건' : "RESULT: FAIL — 총 {$total}건")."\n";
exit($total === 0 ? 0 : 1);
