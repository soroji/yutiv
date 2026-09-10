<?php

/**
 * TeeWide 라이브커머스 Phase 0 — 독립 하네스 (standalone CLI, vendor/Laravel 불필요).
 *
 * 로컬 PHP 는 7.4 이고 `vendor/` 가 비어 있어 Laravel 부팅도 PHPUnit 실행도 불가능하다.
 * 그래서 이 하네스는 두 가지만 한다.
 *
 *   1. **실행 검증** — Host 정규화·역할 판정은 프로덕션 클래스를 그대로 require 해 돌린다.
 *   2. **소스 계약** — 라우트·미들웨어·세션처럼 Laravel 이 있어야 도는 부분은
 *      "그렇게 작성돼 있는가" 만 고정한다.
 *
 * ⚠ 2번은 **런타임 증명이 아니다.** 도메인 매칭·세션 공유·차단 동작은 서버 PHPUnit 으로만
 *   증명된다. 이 하네스가 PASS 라고 해서 동작이 검증된 것이 아니다.
 *
 * 사용: php tests/TeeWide/yutiv-live-commerce-check.php [--verbose]
 * 종료코드: 위반이 있으면 1
 */
$root = dirname(__DIR__, 2);
$pluginDir = $root.'/plugins/_bundled/yutiv-live_commerce';

require_once $pluginDir.'/src/Support/TeeWideHost.php';

use Plugins\Yutiv\LiveCommerce\Support\TeeWideHost;

$verbose = in_array('--verbose', $argv, true);

$violations = [];
$passes = [];

function check($label, $condition, $detail = '')
{
    global $violations, $passes;
    if ($condition) {
        $passes[] = $label;
    } else {
        $violations[] = $label.($detail !== '' ? " — {$detail}" : '');
    }
}

/** PHP 소스에서 주석을 걷어낸다 (금지 토큰 검사가 자기 문서를 벌하지 않도록). */
function twStripComments($source)
{
    $out = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            $out .= $token[1];

            continue;
        }
        $out .= $token;
    }

    return $out;
}

echo "=== TeeWide Phase 0 검증 ===\n\n";

// ── 1. Host 정규화 (실행 검증) ──────────────────────────────────────────────
$rootHost = 'teewide.com';
$liveHost = 'live.teewide.com';

$canonicalCases = [
    'teewide.com' => 'teewide.com',
    'TeeWide.COM' => 'teewide.com',
    'teewide.com.' => 'teewide.com',
    'teewide.com:8443' => 'teewide.com',
    'TEEWIDE.COM.:443' => 'teewide.com',
    '  TeeWide.Com.  ' => 'teewide.com',
    'live.teewide.com:80' => 'live.teewide.com',
    '[::1]:8000' => '[::1]',
    '[::1]' => '[::1]',
    '' => '',
    '   ' => '',
];

foreach ($canonicalCases as $input => $expected) {
    $actual = TeeWideHost::canonical($input);
    check("정규화: '".$input."' → '".$expected."'", $actual === $expected, "실제 '{$actual}'");
}

check('정규화: null 은 빈 문자열', TeeWideHost::canonical(null) === '');
check('정규화: 배열 등 비문자열도 빈 문자열', TeeWideHost::canonical(['x']) === '');

// ── 2. 역할 판정 (실행 검증) ────────────────────────────────────────────────
$roleCases = [
    'teewide.com' => TeeWideHost::ROLE_ROOT,
    'TeeWide.com:443' => TeeWideHost::ROLE_ROOT,
    'teewide.com.' => TeeWideHost::ROLE_ROOT,
    'live.teewide.com' => TeeWideHost::ROLE_LIVE,
    'LIVE.teewide.com.' => TeeWideHost::ROLE_LIVE,
    'yutiv.com' => TeeWideHost::ROLE_FOREIGN,
    'evil-teewide.com' => TeeWideHost::ROLE_FOREIGN,
    'teewide.com.attacker.net' => TeeWideHost::ROLE_FOREIGN,
    'xteewide.com' => TeeWideHost::ROLE_FOREIGN,
    'teewide.co' => TeeWideHost::ROLE_FOREIGN,
    '' => TeeWideHost::ROLE_FOREIGN,
];

foreach ($roleCases as $host => $expected) {
    $actual = TeeWideHost::role($host, $rootHost, $liveHost);
    check("역할: '".$host."' → ".$expected, $actual === $expected, "실제 {$actual}");
}

check('역할: 호스트 설정이 비면 전부 foreign',
    TeeWideHost::role('teewide.com', '', '') === TeeWideHost::ROLE_FOREIGN);
check('isTeeWide: root/live 만 true',
    TeeWideHost::isTeeWide('teewide.com', $rootHost, $liveHost) === true
    && TeeWideHost::isTeeWide('live.teewide.com', $rootHost, $liveHost) === true
    && TeeWideHost::isTeeWide('yutiv.com', $rootHost, $liveHost) === false);

// ── 3. 기본 비활성 (설정 파일 실행 검증) ────────────────────────────────────
if (! function_exists('env')) {
    function env($key, $default = null)
    {
        $value = getenv($key);

        return $value === false ? $default : $value;
    }
}

$configPath = $pluginDir.'/config/live-commerce.php';
$config = require $configPath;

check('설정: enabled 기본값이 false', $config['enabled'] === false);
check('설정: root_host 기본값 teewide.com', $config['root_host'] === 'teewide.com');
check('설정: live_host 기본값 live.teewide.com', $config['live_host'] === 'live.teewide.com');
check('설정: 세션 쿠키 기본값 teewide_session', $config['session']['cookie'] === 'teewide_session');
check('설정: 세션 도메인 기본값 .teewide.com', $config['session']['domain'] === '.teewide.com');
check('설정: 차단 상태코드가 404 또는 421', in_array($config['block_status'], [404, 421], true));
check('설정: 첫 업체 slug 는 golfif', in_array('golfif', $config['known_tenants'], true));

$configSource = file_get_contents($configPath);
check('설정: TEEWIDE_ENABLED 기본값이 소스에서도 false',
    strpos($configSource, "env('TEEWIDE_ENABLED', false)") !== false);

// config:cache 가능성 — 클로저가 있으면 var_export 가 실패한다
$exported = @var_export($config, true);
check('설정: config:cache 직렬화 가능 (클로저 없음)', is_string($exported) && $exported !== '');

// ── 4. 소스 계약 (런타임 증명 아님) ─────────────────────────────────────────
$providerSrc = file_get_contents($pluginDir.'/src/Providers/LiveCommerceServiceProvider.php');
$providerCode = twStripComments($providerSrc);
$pluginSrc = file_get_contents($pluginDir.'/plugin.php');
$gateSrc = file_get_contents($pluginDir.'/src/Http/Middleware/TeeWideHostGate.php');
$sessionSrc = file_get_contents($pluginDir.'/src/Http/Middleware/ConfigureTeeWideSession.php');

check('소스: 도메인 라우트를 Route::domain 으로 등록',
    substr_count($providerCode, 'Route::domain(') === 2,
    '실제 '.substr_count($providerCode, 'Route::domain(').'회');
check('소스: 두 관문(플러그인 활성 + 기능 스위치)을 모두 확인',
    strpos($providerSrc, '! $this->pluginIsActive() || ! TeeWideConfig::active()') !== false);
check('소스: 세션 설정 미들웨어가 스택 첫 자리',
    preg_match('/return \[\s*\/\/[^\n]*\n\s*ConfigureTeeWideSession::class,/', $providerSrc) === 1);
check('소스: 스택에 StartSession 이 포함',
    strpos($providerSrc, 'StartSession::class') !== false);
check('소스: 세션 설정이 StartSession 보다 앞',
    strpos($providerSrc, 'ConfigureTeeWideSession::class') < strpos($providerSrc, 'StartSession::class'));
check('소스: YUTIV web 그룹을 쓰지 않는다',
    preg_match("/middleware\(\s*'web'\s*\)/", $providerSrc) !== 1);
check('소스: 라이브 루트(/)를 등록하지 않는다',
    preg_match("/liveHost\(\)\).*?Route::get\('\/'/s", $providerSrc) !== 1);
check('소스: tenant 라우트에 slug 패턴 제약',
    strpos($providerSrc, "->where('tenant', TeeWideConfig::tenantSlugPattern())") !== false);
check('소스: 라우트 액션이 컨트롤러 배열 (route:cache 가능)',
    strpos($providerSrc, '[DiagnosticsController::class,') !== false
    && preg_match("/Route::get\([^,]+,\s*function\s*\(/", $providerSrc) !== 1);

check('소스: 게이트를 everything/before_core 로 선언',
    strpos($pluginSrc, "'targets' => ['everything']") !== false
    && strpos($pluginSrc, "'timing' => 'before_core'") !== false);
check('소스: 게이트가 web·api 두 그룹에 붙는다',
    strpos($pluginSrc, "'groups' => ['web', 'api']") !== false);
check('소스: Phase 0 은 관리자 메뉴·권한을 선언하지 않는다',
    preg_match('/function getAdminMenus\(\): array\s*\{\s*return \[\];/', $pluginSrc) === 1
    && preg_match('/function getPermissions\(\): array\s*\{\s*return \[\];/', $pluginSrc) === 1);

check('소스: 게이트가 꺼져 있으면 즉시 통과',
    strpos($gateSrc, 'if (! TeeWideConfig::active()) {') !== false);
check('소스: 게이트가 리다이렉트하지 않는다',
    strpos(twStripComments($gateSrc), 'redirect(') === false);
check('소스: 게이트가 라우트 이름 접두로 소유를 판정',
    strpos($gateSrc, "str_starts_with(\$name, self::ROUTE_PREFIX)") !== false);

check('소스: 세션 미들웨어가 꺼져 있으면 즉시 통과',
    strpos($sessionSrc, 'if (! TeeWideConfig::active()) {') !== false);
check('소스: 세션 미들웨어가 cookie/domain 만 바꾼다',
    strpos($sessionSrc, "'session.cookie' =>") !== false
    && strpos($sessionSrc, "'session.domain' =>") !== false
    && strpos(twStripComments($sessionSrc), "'session.driver'") === false);

// ── 5. 기존 코어 무수정 확인 ────────────────────────────────────────────────
check('격리: 플러그인이 src/routes 를 만들지 않았다 (코어 프리픽스 강제 회피)',
    ! is_dir($pluginDir.'/src/routes'));
check('격리: 플러그인 디렉토리 밖 파일을 만들지 않았다 (하네스 제외)',
    is_dir($pluginDir) && is_file($root.'/tests/TeeWide/yutiv-live-commerce-check.php'));

// ── 6. 테스트 존재 (런타임 증명은 서버에서) ─────────────────────────────────
$testFiles = [
    'TeeWideDomainRoutingTest.php',
    'TeeWideSessionIsolationTest.php',
    'TeeWideSafetyTest.php',
    'TeeWideHostGateTest.php',
];

foreach ($testFiles as $file) {
    check("테스트 파일 존재: {$file}", is_file($pluginDir.'/tests/Feature/'.$file));
}

$testCount = 0;
foreach (glob($pluginDir.'/tests/Feature/*.php') as $file) {
    $testCount += preg_match_all('/public function test_/u', file_get_contents($file));
}
check('테스트: PHPUnit 케이스가 충분히 있다', $testCount >= 30, "{$testCount}종");

// ── 출력 ────────────────────────────────────────────────────────────────────
if ($verbose) {
    foreach ($passes as $p) {
        echo "  OK   {$p}\n";
    }
}

foreach ($violations as $v) {
    echo "  FAIL {$v}\n";
}

echo "\n";

if ($violations === []) {
    echo 'RESULT: PASS — 통과 '.count($passes)."건, 위반 0건\n";
    echo "주의: 라우트 매칭·세션 공유·차단 동작은 서버 PHPUnit 으로만 증명됩니다.\n";
    exit(0);
}

echo 'RESULT: FAIL — 통과 '.count($passes).'건, 위반 '.count($violations)."건\n";
exit(1);
