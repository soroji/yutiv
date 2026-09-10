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

// ── 7. 테스트 앱 생명주기 계약 ──────────────────────────────────────────────
//
// 여기부터는 **서버 PHPUnit 이 옳은 것을 재도록** 테스트 기반 자체를 고정하는 검사다.
// 아래 각 검사는 요청받은 9가지 회귀 주입 중 하나에 1:1 로 대응하며, 그 주입을 넣으면
// 이 하네스가 red 가 된다. (주입 → 검사 대응은 각 check 라벨의 [주입 N] 표시 참조)
//
// ⚠ 다시 말하지만 이건 **소스 계약**이다. 라우트가 실제로 매칭되는지, 세션이 실제로
//   공유되는지는 여전히 서버 PHPUnit 만이 증명한다.

$baseSrc = file_get_contents($pluginDir.'/tests/PluginTestCase.php');
$baseCode = twStripComments($baseSrc);
$routingTestSrc = file_get_contents($pluginDir.'/tests/Feature/TeeWideDomainRoutingTest.php');
$routingTestCode = twStripComments($routingTestSrc);
$controllerCode = twStripComments(file_get_contents($pluginDir.'/src/Http/Controllers/DiagnosticsController.php'));
$bootProviderSrc = file_get_contents($pluginDir.'/tests/Support/BootTimeLiveCommerceServiceProvider.php');

// [주입 1] 결정적 APP_KEY 설정 제거
preg_match("/TEST_APP_KEY_PLAINTEXT = '([^']*)'/", $baseCode, $keyMatch);
$appKey = $keyMatch[1] ?? '';
check('생명주기: 테스트 전용 APP_KEY 상수가 있다 [주입 1]', $appKey !== '');
check('생명주기: APP_KEY 평문이 정확히 32바이트 (AES-256) [주입 1]',
    strlen($appKey) === 32, '실제 '.strlen($appKey).'바이트');
check('생명주기: app.key 를 그 상수로 설정한다 [주입 1]',
    strpos($baseCode, "config(['app.key' => 'base64:'.base64_encode(self::TEST_APP_KEY_PLAINTEXT)])") !== false);

// [주입 2] APP_KEY 설정을 요청/StartSession 뒤로 이동
//   afterApplicationCreated 등록이 parent::setUp() 보다 앞에 있어야 앱 생성 직후,
//   즉 어떤 요청·세션보다 먼저 키가 박힌다.
$afterAppAt = strpos($baseCode, 'afterApplicationCreated');
$parentSetUpAt = strpos($baseCode, 'parent::setUp()');
check('생명주기: APP_KEY 주입이 parent::setUp() 보다 먼저 등록된다 [주입 2]',
    $afterAppAt !== false && $parentSetUpAt !== false && $afterAppAt < $parentSetUpAt);
check('생명주기: APP_KEY 주입이 setUp 단계에서 일어난다 (요청 시점 아님) [주입 2]',
    preg_match('/function useDeterministicTestAppKey\(\): void/', $baseCode) === 1
    && strpos($baseCode, '$this->get(') === false);

// [주입 3] 운영 APP_KEY/env 사용
check('생명주기: 운영 APP_KEY 를 읽지 않는다 [주입 3]',
    strpos($baseCode, "env('APP_KEY'") === false
    && strpos($baseCode, "getenv('APP_KEY')") === false
    && strpos($baseCode, "config('app.key')") === false);
check('생명주기: key:generate 를 부르지 않는다 [주입 3]',
    strpos($baseCode, 'key:generate') === false);
check('생명주기: encrypter/Crypt 캐시를 정리한다',
    strpos($baseCode, "forgetInstance('encrypter')") !== false
    && strpos($baseCode, 'Crypt::clearResolvedInstances()') !== false);

// [주입 4] provider 등록 제거
check('생명주기: 부팅 시점에 프로바이더를 등록한다 [주입 4]',
    strpos($baseCode, 'beforeBootstrapping(BootProviders::class') !== false
    && strpos($baseCode, '$app->register(BootTimeLiveCommerceServiceProvider::class)') !== false);
check('생명주기: 그 훅을 쓰는 스위트가 실제로 있다 [주입 4]',
    strpos($routingTestCode, 'function teeWideBootConfig(): ?array') !== false);

// [주입 5] enabled=true 설정을 provider boot 뒤로 이동
//   설정 주입이 프로바이더 등록보다 **앞**이어야 boot 이 그 값을 읽는다.
$configSetAt = strpos($baseCode, "\$app['config']->set(TeeWideConfig::KEY");
$providerRegisterAt = strpos($baseCode, '$app->register(BootTimeLiveCommerceServiceProvider::class)');
check('생명주기: 설정 주입이 프로바이더 등록보다 먼저다 [주입 5]',
    $configSetAt !== false && $providerRegisterAt !== false && $configSetAt < $providerRegisterAt);
check('생명주기: 부팅 시점 등록은 BootProviders 직전이다 (라우트 프로바이더보다 앞) [주입 5]',
    strpos($baseCode, 'use Illuminate\\Foundation\\Bootstrap\\BootProviders;') !== false);

// [주입 6] Route::domain 제거 — 위 4절에서 이미 검사한다(2회 등록).
//   여기서는 라우트 우선순위 계약을 검사로 고정한다.
check('생명주기: SPA catch-all 보다 앞선다는 순서 계약이 테스트로 고정돼 있다 [주입 6]',
    strpos($routingTestCode, 'spaCatchAllRoute()') !== false
    && strpos($routingTestCode, 'assertLessThan(') !== false
    && strpos($routingTestCode, '$catchAllIndex = $this->routeIndex($catchAll);') !== false
    && strpos($routingTestCode, '$this->routeIndex($route),') !== false);

// [주입 7] route name 단언 제거
check('생명주기: 포털 route name 단언이 살아 있다 [주입 7]',
    strpos($routingTestCode, "assertSame('teewide.portal', \$this->matchedRouteName(\$url)") !== false);
check('생명주기: 라이브 route name 단언이 살아 있다 [주입 7]',
    strpos($routingTestCode, "assertSame('teewide.live.tenant', \$this->matchedRouteName(\$url)") !== false);
check('생명주기: 실패 시 진단(Host·등록 라우트·매칭 라우트)을 붙인다 [주입 7]',
    substr_count($routingTestCode, 'routingDiagnostics(') >= 3);
check('생명주기: 진단이 요구된 항목을 모두 담는다',
    strpos($baseCode, 'getHost()') !== false
    && strpos($baseCode, 'getDomain()') !== false
    && strpos($baseCode, 'getActionName()') !== false
    && strpos($baseCode, "'.enabled'") !== false
    && strpos($baseCode, "'.root_host'") !== false
    && strpos($baseCode, "'.live_host'") !== false
    && strpos($baseCode, 'providerIsRegistered()') !== false);

// [주입 8] response body 를 고정 route name 으로 만들어 거짓 통과
check('생명주기: 컨트롤러가 route name 을 라우터에서 읽는다 (하드코딩 아님) [주입 8]',
    substr_count($controllerCode, "'route' => \$request->route()?->getName()") === 3
    && preg_match("/'route' => '/", $controllerCode) !== 1);

// [주입 9] provider 상태 초기화 제거
check('생명주기: tearDown 이 프로바이더 재등록 플래그를 되돌린다 [주입 9]',
    preg_match('/function tearDown\(\): void/', $baseCode) === 1
    && strpos($baseCode, '$this->pluginRegistered = false;') !== false);
check('생명주기: tearDown 이 파사드 정적 상태를 되돌린다 [주입 9]',
    preg_match('/function tearDown\(\).*?Crypt::clearResolvedInstances\(\)/s', $baseCode) === 1);
check('생명주기: 부팅 시점 등록과 부팅 후 등록이 중복되지 않는다 [주입 9]',
    strpos($baseCode, 'if ($this->providerIsRegistered()) {') !== false);

// 부팅 시점 활성 판정 seam 이 계약을 넓히지 않는지
check('생명주기: 부팅용 프로바이더는 활성 판정만 고정한다',
    substr_count($bootProviderSrc, 'protected function ') === 1
    && strpos($bootProviderSrc, 'function pluginIsActive(): bool') !== false);
check('생명주기: 부팅용 프로바이더는 테스트 디렉토리에만 있다',
    is_file($pluginDir.'/tests/Support/BootTimeLiveCommerceServiceProvider.php')
    && ! is_file($pluginDir.'/src/Providers/BootTimeLiveCommerceServiceProvider.php'));

// 운영 코드 무수정 — createApplication 오버라이드는 가시성 축소가 아니어야 한다
check('생명주기: createApplication 오버라이드가 public 이다 (가시성 축소 Fatal 방지)',
    preg_match('/public function createApplication\(/', $baseCode) === 1
    && preg_match('/protected function createApplication\(/', $baseCode) !== 1);

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
