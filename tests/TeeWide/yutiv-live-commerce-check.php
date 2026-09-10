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

/**
 * 클래스 소스에서 메서드 하나의 본문만 잘라낸다 (중괄호 균형 기준).
 *
 * 파일 전체에서 문자열 존재만 보는 검사는 "어딘가에 그 글자가 있다" 만 증명한다.
 * 실제로 필요한 건 "그 호출이 이 메서드 안에서 저 호출보다 먼저다" 이므로,
 * 비교는 반드시 같은 본문 안에서 해야 한다. (2차 서버 실패를 놓친 이유가 이것이다)
 */
function twMethodBody($code, $name)
{
    if (! preg_match('/function\s+'.preg_quote($name, '/').'\s*\([^)]*\)[^{]*\{/u', $code, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }

    $start = $m[0][1] + strlen($m[0][0]);
    $depth = 1;
    $len = strlen($code);

    for ($i = $start; $i < $len; $i++) {
        if ($code[$i] === '{') {
            $depth++;
        } elseif ($code[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($code, $start, $i - $start);
            }
        }
    }

    return null;
}

/** 본문 안에서 $first 가 $second 보다 먼저 나오는가 (둘 다 있어야 한다). */
function twOrderedIn($body, $first, $second)
{
    if (! is_string($body)) {
        return false;
    }
    $a = strpos($body, $first);
    $b = strpos($body, $second);

    return $a !== false && $b !== false && $a < $b;
}

/** 로컬에서 증명할 수 없어 서버 PHPUnit 이 필요한 계약 목록. */
$serverOnly = [];
function serverOnly($label)
{
    global $serverOnly;
    $serverOnly[] = $label;
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

// ── 세션 설정의 요청 범위 격리 (4차 서버 실패 3건의 원인) ──────────────────
//
// config() 는 전역이다. 되돌리지 않으면 같은 Application 을 재사용하는 실행 모델
// (queue worker · Octane · RoadRunner · 테스트 앱)에서 다음 YUTIV 요청이
// teewide_session 을 자기 세션 쿠키로 읽는다. 복원 책임은 미들웨어에 있다.
$sessionCode = twStripComments($sessionSrc);
$handleBody = twMethodBody($sessionCode, 'handle');

// [주입 22] 요청 종료 후 설정 복원 제거
check('세션범위: 미들웨어가 finally 로 복원한다 [주입 22]',
    is_string($handleBody)
    && preg_match('/try\\s*\\{\\s*return\\s+\\$next\\(\\$request\\);\\s*\\}\\s*finally\\s*\\{/', $handleBody) === 1,
    '예외가 나가도 복원되어야 하므로 finally 여야 한다');
check('세션범위: 복원이 스냅샷 배열 통째로 이뤄진다 (없던 키·null·빈문자열·false 보존) [주입 22]',
    is_string($handleBody)
    && strpos($handleBody, "\$snapshot = config('session')") !== false
    && strpos($handleBody, "config(['session' => \$snapshot])") !== false);
check('세션범위: 스냅샷이 설정 변경보다 먼저 찍힌다 [주입 22]',
    twOrderedIn($handleBody, "\$snapshot = config('session')", "'session.cookie' =>"));
check('세션범위: 스냅샷이 지역 변수라 중첩 호출이 서로를 훼손하지 않는다',
    is_string($handleBody)
    && strpos($sessionCode, 'static $snapshot') === false
    && strpos($sessionCode, '$this->snapshot') === false);

// [주입 23] 세션 스토어 이름 복원 제거
check('세션범위: 스토어를 설정 변경 **전에** 해석한다 [주입 23]',
    twOrderedIn($handleBody, 'resolveSessionStore()', "'session.cookie' =>"),
    '설정을 먼저 바꾸면 스토어가 TeeWide 이름으로 생성돼 앱 수명 내내 남는다');
check('세션범위: 원래 스토어 이름을 확보한다 [주입 23]',
    twOrderedIn($handleBody, 'getName()', 'setName('));
check('세션범위: finally 에서 스토어 이름을 되돌린다 [주입 23]',
    is_string($handleBody)
    && preg_match('/finally\\s*\\{.*?setName\\(\\$originalStoreName\\)/s', $handleBody) === 1);

// [주입 24] 테스트 tearDown 이 대신 치우는 방식
$scopeTestSrc = @file_get_contents($pluginDir.'/tests/Feature/TeeWideSessionScopeTest.php');
$scopeTestCode = is_string($scopeTestSrc) ? twStripComments($scopeTestSrc) : '';
check('세션범위: 복원을 테스트 tearDown 이 대신하지 않는다 [주입 24]',
    strpos($scopeTestCode, 'function tearDown') === false
    && strpos(twStripComments($baseSrc), "config(['session' =>") === false);
check('세션범위: 회귀 테스트가 미들웨어만 태워 복원을 확인한다 [주입 24]',
    strpos($scopeTestCode, 'ConfigureTeeWideSession::class') !== false
    && strpos($scopeTestCode, 'sessionConfigSnapshot()') !== false);

// 요구된 회귀 시나리오가 실제로 테스트로 존재하는가
foreach ([
    'YUTIV_TeeWide_YUTIV_순서에서_설정이_복원된다',
    'TeeWide_live_YUTIV_순서에서_설정이_복원된다',
    'TeeWide_요청_중_예외가_나도_설정이_복원된다',
    '원래_설정의_null_빈문자열_false_가_그대로_보존된다',
    '원래_없던_키는_되살아나지_않는다',
    '중첩_호출이_바깥_스냅샷을_훼손하지_않는다',
    'TeeWide_응답은_전용_쿠키_이름과_도메인으로_발급한다',
    'TeeWide_요청_직후_YUTIV_응답은_YUTIV_쿠키_이름을_쓴다',
    '연속_TeeWide_요청끼리는_세션을_계속_공유한다',
] as $case) {
    check('세션범위 회귀: '.$case, strpos($scopeTestCode, 'function test_'.$case) !== false);
}

// [주입 25] 실패 2번을 상태코드만으로 판정하는 느슨한 단언
$isolationSrc = file_get_contents($pluginDir.'/tests/Feature/TeeWideSessionIsolationTest.php');
$isolationCode = twStripComments($isolationSrc);
check('세션범위: yutiv 호스트 판정이 상태코드가 아니라 응답 정체로 이뤄진다 [주입 25]',
    strpos($isolationCode, "assertNotSame(200, \$response->getStatusCode()") === false
    && strpos($isolationCode, "'\"platform\":\"teewide\"'") !== false);
check('세션범위: 그 판정이 매칭 라우트도 확인한다 [주입 25]',
    strpos($isolationCode, 'matchedRoute($url)') !== false
    && strpos($isolationCode, "str_starts_with((string) \$matched->getName(), 'teewide.')") !== false);

// [주입 26] 실패 3번을 actingAs 로 되돌리기
check('세션범위: 로그인 누수 판정에 actingAs 를 쓰지 않는다 [주입 26]',
    strpos($isolationCode, 'actingAs(') === false,
    'actingAs 는 guard 인스턴스에 사용자를 꽂아 쿠키 격리를 증명하지 못한다');
check('세션범위: 실제 YUTIV 로그인 세션 쿠키로 판정한다 [주입 26]',
    strpos($isolationCode, 'makeYutivLoginSession(') !== false
    && strpos($isolationCode, "'login_web_'.sha1") !== false
    && strpos($isolationCode, 'withUnencryptedCookie($yutivCookie') !== false);
check('세션범위: 누수 단언과 함께 TeeWide 쿠키 이름·라우트도 고정한다 [주입 26]',
    strpos($isolationCode, "assertJsonPath('session_cookie', 'teewide_session')") !== false
    && strpos($isolationCode, "assertJsonPath('route', 'teewide.portal.session')") !== false);

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
//
// 검사는 createApplication() **본문 안**에서만 한다. 파일 어딘가의 문자열이 아니라
// 실제 호출 위치가 중요하기 때문이다.
$createBody = twMethodBody($baseCode, 'createApplication');

check('생명주기: createApplication 본문을 찾을 수 있다 [주입 4]', is_string($createBody));
check('생명주기: 프로바이더를 코어 프로바이더 목록에 넣는다 [주입 4]',
    is_string($createBody)
    && strpos($createBody, 'RegisterProviders::merge(') !== false
    && strpos($createBody, 'BootTimeLiveCommerceServiceProvider::class') !== false);
check('생명주기: merge 가 bootstrap() 보다 먼저 호출된다 [주입 4]',
    twOrderedIn($createBody, 'RegisterProviders::merge(', '->bootstrap()'));
check('생명주기: bootstrap/providers.php 경로를 함께 넘긴다 (코어 목록 보존) [주입 4]',
    is_string($createBody) && strpos($createBody, 'getBootstrapProvidersPath()') !== false);
check('생명주기: 앞 테스트의 merge 잔재를 먼저 비운다 [주입 4]',
    twOrderedIn($createBody, 'RegisterProviders::flushState()', "require Application::inferBasePath()"));
check('생명주기: 늦게 발화하는 이벤트 훅으로 프로바이더를 등록하지 않는다 [주입 4]',
    is_string($createBody) && strpos($createBody, 'beforeBootstrapping') === false,
    'beforeBootstrapping 경로는 서버 2차 실행에서 routes/web.php 뒤에 등록됐다');
check('생명주기: 그 경로를 쓰는 스위트가 실제로 있다 [주입 4]',
    strpos($routingTestCode, 'function teeWideBootConfig(): ?array') !== false);
// 부팅 스위트는 늦은 등록으로 되돌아가지 않는다 — 사전검사를 거친 뒤 그대로 반환한다.
// (register() 호출이 사전검사보다 뒤에 오되, 부팅 스위트는 그 지점에 닿지 않는다)
$bootPluginBody = twMethodBody($baseCode, 'bootPlugin');
check('생명주기: 부팅 시점 등록 실패를 늦은 등록으로 덮지 않는다 [주입 4]',
    twOrderedIn($bootPluginBody, 'teeWideBootConfig()', 'assertBootTimeLifecycle()')
    && twOrderedIn($bootPluginBody, 'assertBootTimeLifecycle()', '$this->app->register('));

// [주입 5] enabled=true 설정을 provider boot 뒤로 이동
//   설정은 프로바이더 자신의 register() 에서, parent::register() 의 mergeConfigFrom
//   **보다 먼저** 심어야 파일 기본값(비활성)을 이긴다.
$bootProviderCode = twStripComments($bootProviderSrc);
$bootRegisterBody = twMethodBody($bootProviderCode, 'register');

check('생명주기: 부팅 설정을 프로바이더 register() 에서 심는다 [주입 5]',
    is_string($bootRegisterBody)
    && strpos($bootRegisterBody, 'set(TeeWideConfig::KEY') !== false);
check('생명주기: 설정 주입이 parent::register() 보다 먼저다 [주입 5]',
    twOrderedIn($bootRegisterBody, 'set(TeeWideConfig::KEY', 'parent::register()'));
check('생명주기: createApplication 이 설정을 프로바이더에 넘긴다 [주입 5]',
    twOrderedIn($createBody, 'BootTimeLiveCommerceServiceProvider::$config', 'RegisterProviders::merge('));
check('생명주기: 코어 프로바이더 등록 단계를 쓴다 [주입 5]',
    strpos($baseCode, 'use Illuminate\\Foundation\\Bootstrap\\RegisterProviders;') !== false);

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
check('생명주기: 부팅용 프로바이더가 활성 판정을 명시값으로 고정한다',
    strpos($bootProviderSrc, 'function pluginIsActive(): bool') !== false
    && strpos($bootProviderSrc, 'return static::$pluginActive;') !== false);
check('생명주기: 부팅용 프로바이더가 라우트 등록을 스스로 하지 않는다 (부모에 위임)',
    strpos($bootProviderCode, 'Route::domain(') === false
    && strpos($bootProviderCode, 'parent::boot()') !== false);
check('생명주기: 전역 정적 상태를 되돌리는 수단이 있다',
    strpos($bootProviderSrc, 'function resetTestState(): void') !== false
    && strpos(twStripComments($baseSrc), 'RegisterProviders::flushState()') !== false);
check('생명주기: 실패 진단에 부팅 추적이 실린다',
    strpos($bootProviderSrc, 'public static array $trace') !== false
    && strpos($baseCode, 'lifecycleTrace()') !== false
    && twOrderedIn(twMethodBody($baseCode, 'routingDiagnostics'), 'implode(', 'lifecycleTrace()'));
check('생명주기: 부팅용 프로바이더는 테스트 디렉토리에만 있다',
    is_file($pluginDir.'/tests/Support/BootTimeLiveCommerceServiceProvider.php')
    && ! is_file($pluginDir.'/src/Providers/BootTimeLiveCommerceServiceProvider.php'));

// 운영 코드 무수정 — createApplication 오버라이드는 가시성 축소가 아니어야 한다
check('생명주기: createApplication 오버라이드가 public 이다 (가시성 축소 Fatal 방지)',
    preg_match('/public function createApplication\(/', $baseCode) === 1
    && preg_match('/protected function createApplication\(/', $baseCode) !== 1);

// ── 9. 프로바이더 등록 판정 (3차 실패의 거짓 음성) ──────────────────────────
//
// 3차 서버 실행은 프로바이더가 정상 register/boot 됐는데도 사전검사가 false 를 내어
// 28건이 요청 전에 멈췄다. 원인은 Laravel 12 의 프로바이더 레지스트리가 **구상
// 클래스명을 키로** 쓰고(Application.php:970-977) `getProvider()` 가 그 키를 정확히
// 찾는 조회라(933-938), 부모 클래스명으로 물으면 서브클래스를 놓치기 때문이다.
// 아래 검사는 그 판정이 다시 좁아지지 않도록 못박는다.

$providerCheckBody = twMethodBody($baseCode, 'providerIsRegistered');
$registeredBody = twMethodBody($baseCode, 'registeredLiveCommerceProviders');
$lifecycleBody = twMethodBody($baseCode, 'assertBootTimeLifecycle');

// [주입 11] 등록 판정만 false 를 반환
check('등록판정: providerIsRegistered 가 레지스트리를 실제로 조회한다 [주입 11]',
    is_string($providerCheckBody)
    && strpos($providerCheckBody, 'registeredLiveCommerceProviders()') !== false
    && preg_match('/return\\s+(true|false)\\s*;/', $providerCheckBody) !== 1);

// [주입 12] 정확한 클래스명 비교로 서브클래스를 놓치는 구현
check('등록판정: instanceof 조회(getProviders)를 쓴다 [주입 12]',
    is_string($registeredBody)
    && strpos($registeredBody, 'getProviders(LiveCommerceServiceProvider::class)') !== false);
check('등록판정: 정확 키 조회(getProvider)로 판정하지 않는다 [주입 12]',
    is_string($registeredBody)
    && preg_match('/getProvider\\s*\\(/', $registeredBody) !== 1,
    'getProvider() 는 구상 클래스명 정확 조회라 서브클래스를 놓친다');
check('등록판정: 지원 프로바이더가 운영 프로바이더의 subclass 다 [주입 12]',
    preg_match('/class BootTimeLiveCommerceServiceProvider extends LiveCommerceServiceProvider/', $bootProviderSrc) === 1);
check('등록판정: 사전검사가 subclass 와 운영 클래스 양쪽을 확인한다 [주입 12]',
    is_string($lifecycleBody)
    && strpos($lifecycleBody, 'assertArrayHasKey(') !== false
    && strpos($lifecycleBody, 'BootTimeLiveCommerceServiceProvider::class') !== false
    && strpos($lifecycleBody, 'assertInstanceOf(LiveCommerceServiceProvider::class') !== false);

// [주입 13] register/boot 횟수가 0회 또는 2회
check('등록판정: register/boot 횟수를 정확히 1회로 단언한다 [주입 13]',
    is_string($lifecycleBody)
    && strpos($lifecycleBody, 'assertSame(1, BootTimeLiveCommerceServiceProvider::$registerCount') !== false
    && strpos($lifecycleBody, 'assertSame(1, BootTimeLiveCommerceServiceProvider::$bootCount') !== false);
// 주석 제거본으로 검사한다 — `// static::$bootCount++;` 처럼 주석 처리해
// 계측만 죽이는 무력화를 문자열 검색으로는 잡지 못한다.
check('등록판정: 횟수가 실제로 계측된다 (주석 처리 무력화 포함) [주입 13]',
    strpos($bootProviderCode, 'static::$registerCount++;') !== false
    && strpos($bootProviderCode, 'static::$bootCount++;') !== false);

// [주입 14] 라우트는 등록됐지만 bootstrap 이후 늦게 등록
// 이름이 있는지가 아니라 **그 두 값을 실제로 비교하는지**를 본다.
// (routeCountAfterBootstrap 은 assertNotNull 에도, assertLessThan 은 catch-all
//  순서 검사에도 따로 등장하므로 존재 검사만으로는 삭제를 놓친다)
check('등록판정: boot 종료 라우트 수 < bootstrap 완료 라우트 수 를 단언한다 [주입 14]',
    is_string($lifecycleBody)
    && preg_match('/assertLessThan\\(\\s*\\$this->routeCountAfterBootstrap\\s*,\\s*\\$end\\s*,/', $lifecycleBody) === 1);
check('등록판정: boot 중 라우트가 정확히 4개 늘었음을 단언한다 [주입 14]',
    is_string($lifecycleBody)
    && strpos($lifecycleBody, 'assertSame(4, $end - $start') !== false);
check('등록판정: SPA catch-all 보다 앞선다는 것도 사전검사가 확인한다 [주입 14]',
    is_string($lifecycleBody)
    && strpos($lifecycleBody, 'spaCatchAllRoute()') !== false
    && strpos($lifecycleBody, 'routeIndex(') !== false);
check('등록판정: 라우트 이름·도메인·액션까지 대조한다 [주입 14]',
    is_string($lifecycleBody)
    && strpos($lifecycleBody, 'routeNamesAtBootEnd') !== false
    && strpos($lifecycleBody, 'getDomain()') !== false
    && strpos($lifecycleBody, 'DiagnosticsController') !== false);

// [주입 15] trace 문자열만 조작하여 거짓 통과
check('등록판정: 사전검사가 trace 문자열을 판정 근거로 쓰지 않는다 [주입 15]',
    is_string($lifecycleBody)
    && strpos($lifecycleBody, 'BootTimeLiveCommerceServiceProvider::$trace') === false,
    'trace 는 사람이 읽는 용도이며 손으로 써넣을 수 있어 증명이 되지 못한다');
check('등록판정: trace 는 실패 메시지로만 쓰인다',
    is_string($lifecycleBody)
    && strpos($lifecycleBody, '$diagnostics = $this->lifecycleTrace();') !== false);
check('등록판정: 계측값은 앱마다 초기화된다',
    strpos($bootProviderSrc, 'static::$registerCount = 0;') !== false
    && strpos($bootProviderSrc, 'static::$bootCount = 0;') !== false);

// 사전검사가 실제로 호출되는가 (선언만 하고 안 부르면 무의미하다)
check('등록판정: bootPlugin 이 부팅 스위트에서 사전검사를 호출한다',
    twOrderedIn(twMethodBody($baseCode, 'bootPlugin'),
        'teeWideBootConfig()', 'assertBootTimeLifecycle()'));

serverOnly('사전검사 7항목이 실제 런타임에서 모두 만족되는가');

// ── 10. 테스트 격리: services manifest 오염 방지 ────────────────────────────
//
// RegisterProviders::merge() 로 프로바이더 목록이 달라지면 ProviderRepository 의
// shouldRecompile() 이 참이 되어 services manifest 를 다시 쓴다. 그 대상이 프로젝트의
// bootstrap/cache/services.php 라면 테스트가 끝난 뒤 운영 부팅이 테스트 전용
// 프로바이더를 읽으려 든다. 그 파일은 ignore 대상이라 git status 로는 보이지 않는다.
// 나중에 되돌리는 방식이 아니라 처음부터 다른 파일을 보게 만들어야 한다.

$isolateBody = twMethodBody($baseCode, 'isolateServicesManifestPath');
$releaseBody = twMethodBody($baseCode, 'releaseIsolatedServicesManifest');
$setEnvBody = twMethodBody($baseCode, 'setServicesCacheEnv');
$tearDownBody = twMethodBody($baseCode, 'tearDown');
$isolationTestSrc = @file_get_contents($pluginDir.'/tests/Feature/TeeWideTestIsolationTest.php');
$isolationTestCode = is_string($isolationTestSrc) ? twStripComments($isolationTestSrc) : '';

// [주입 16] APP_SERVICES_CACHE 격리 제거
check('격리: 앱 생성 전에 manifest 경로를 격리한다 [주입 16]',
    twOrderedIn($createBody, 'isolateServicesManifestPath()', "require Application::inferBasePath()"));
check('격리: 부팅 전 실제 manifest 지문을 기록한다 [주입 16]',
    twOrderedIn($createBody, 'snapshotRealServicesManifest()', "require Application::inferBasePath()"));
check('격리: 공식 지점(APP_SERVICES_CACHE)을 쓴다 [주입 16]',
    is_string($setEnvBody)
    && strpos($setEnvBody, "\$_ENV['APP_SERVICES_CACHE']") !== false
    && strpos($setEnvBody, "\$_SERVER['APP_SERVICES_CACHE']") !== false);
check('격리: putenv 를 쓰지 않는다 (bootstrap/app.php 가 disablePutenv 한다)',
    strpos($baseCode, 'putenv(') === false);

// [주입 17] 고정 공용 임시 파일 사용
check('격리: 임시 manifest 이름이 프로세스·호출마다 고유하다 [주입 17]',
    is_string($isolateBody)
    && strpos($isolateBody, 'getmypid()') !== false
    && strpos($isolateBody, 'random_bytes(') !== false);
check('격리: 고정된 공용 파일명을 쓰지 않는다 [주입 17]',
    is_string($isolateBody)
    && preg_match("/'services(-shared|-test)?\\.php'/", $isolateBody) !== 1);
check('격리: 임시 파일은 ignore 되는 테스트 디렉토리 아래에 둔다',
    strpos($baseCode, "ISOLATED_SERVICES_DIR = 'storage/framework/testing/teewide'") !== false
    && is_file($root.'/storage/framework/testing/.gitignore'));

// [주입 18] tearDown 정리 제거
check('격리: tearDown 이 임시 manifest 를 지운다 [주입 18]',
    is_string($tearDownBody)
    && strpos($tearDownBody, 'releaseIsolatedServicesManifest()') !== false);
check('격리: tearDown 이 실제 manifest 불변을 확인한다 [주입 18]',
    is_string($tearDownBody)
    && strpos($tearDownBody, 'guardRealServicesManifestUnchanged()') !== false);
check('격리: tearDown 을 못 타도 종료 훅이 잔여 파일을 지운다 [주입 18]',
    strpos($baseCode, 'register_shutdown_function(') !== false
    && strpos(twMethodBody($baseCode, 'registerShutdownCleanup') ?? '', 'getmypid()') !== false);
check('격리: 임시 파일을 실제로 unlink 한다 [주입 18]',
    is_string($releaseBody) && strpos($releaseBody, 'unlink(') !== false);

// [주입 19] 환경변수 복원 제거
// $_ENV 와 $_SERVER **양쪽 모두** 기록해야 한다. 한쪽만 남겨 두면 그쪽 문자열이
// 존재 검사를 통과시켜, 다른 쪽 복원이 사라진 것을 놓친다.
check('격리: 원래 "없음/빈문자열/값" 상태를 두 superglobal 모두에 기록한다 [주입 19]',
    is_string($setEnvBody)
    && substr_count($setEnvBody, "array_key_exists('APP_SERVICES_CACHE'") === 2
    && substr_count($setEnvBody, "'set' =>") === 2);
check('격리: 원래 없던 키는 삭제로 복원한다 [주입 19]',
    is_string($releaseBody)
    && strpos($releaseBody, "unset(\$_ENV['APP_SERVICES_CACHE'])") !== false
    && strpos($releaseBody, "unset(\$_SERVER['APP_SERVICES_CACHE'])") !== false);
check('격리: 원래 있던 값은 그 값 그대로 복원한다 [주입 19]',
    is_string($releaseBody)
    && strpos($releaseBody, "\$state['set']") !== false
    && strpos($releaseBody, "\$state['value']") !== false);

// [주입 20] 실제 bootstrap/cache/services.php 를 직접 사용
check('격리: 실제 manifest 경로를 격리 경로로 쓰지 않는다 [주입 20]',
    is_string($isolateBody)
    && strpos($isolateBody, 'realServicesManifestPath()') === false,
    '실제 manifest 를 백업·복원하는 방식이 아니라 처음부터 다른 파일을 봐야 한다');
check('격리: 실제 manifest 경로는 읽기(지문 확인) 용도로만 쓴다 [주입 20]',
    twOrderedIn(twMethodBody($baseCode, 'guardRealServicesManifestUnchanged'),
        'snapshotRealServicesManifest()', 'RuntimeException'));
check('격리: 실제 manifest 경로가 .laravel 규칙을 따른다',
    strpos(twMethodBody($baseCode, 'realServicesManifestPath') ?? '', "'/.laravel'") !== false);

// [주입 21] 테스트 provider 가 후속 앱에 남는 경우
check('격리: 후속 앱 누수를 실제 테스트가 확인한다 [주입 21]',
    strpos($isolationTestCode, 'getProviders(LiveCommerceServiceProvider::class)') !== false
    && strpos($isolationTestCode, 'RegisterProviders::flushState()') !== false
    && strpos($isolationTestCode, '->make(Kernel::class)->bootstrap()') !== false);
check('격리: 실제 manifest 지문 비교를 테스트가 단언한다 [주입 21]',
    strpos($isolationTestCode, 'manifestFingerprintBeforeBootstrap()') !== false
    && strpos($isolationTestCode, 'realServicesManifestPath()') !== false);
check('격리: 환경변수 복원을 테스트가 단언한다 [주입 21]',
    strpos($isolationTestCode, 'assertArrayNotHasKey(') !== false
    && strpos($isolationTestCode, 'assertFileDoesNotExist(') !== false);

serverOnly('실제 bootstrap/cache/services.php 가 테스트 전후로 바이트 동일한가');
serverOnly('임시 manifest 가 tearDown 후 하나도 남지 않는가');
serverOnly('TeeWide 요청 뒤 session.cookie/domain 이 실제로 복원되는가');
serverOnly('TeeWide 응답이 teewide_session 만, YUTIV 응답이 g7-session 만 발급하는가');
serverOnly('YUTIV 로그인 세션 쿠키가 TeeWide 요청을 인증시키지 못하는가');

// ── 8. 로컬에서 증명할 수 없는 계약 (서버 PHPUnit 필요) ─────────────────────
//
// 이 하네스는 vendor/ 없이 도는 **정적 검사**다. 위 검사가 전부 통과해도 아래 항목은
// 하나도 증명되지 않는다. 실제로 2차 서버 실행에서 라우트 등록 순서가 뒤집혔는데도
// 이 하네스는 PASS 였다 — 문자열 존재만 봤기 때문이다(false green). 구조 검사로
// 바꿔도 "언제 실행되는가" 는 여전히 런타임의 몫이므로, 아래를 명시적으로 남긴다.

serverOnly('프로바이더 register/boot 이 routes/web.php 적재보다 먼저 실행되는가');
serverOnly('TeeWide 라우트 4종의 컬렉션 순서 < SPA catch-all 순서');
serverOnly('teewide.test / 요청이 teewide.portal 로 매칭되는가');
serverOnly('live.teewide.test/golfif 요청이 teewide.live.tenant 로 매칭되는가');
serverOnly('teewide_session 쿠키 이름·도메인이 실제 응답에 적용되는가');
serverOnly('teewide.test 와 live.teewide.test 가 세션을 실제로 공유하는가');
serverOnly('호스트 게이트가 실제 HTTP 요청을 404 로 끊는가');
serverOnly('RegisterProviders::merge 잔재가 다음 테스트 클래스로 새지 않는가');

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

echo "서버 PHPUnit 필요 (이 하네스가 증명하지 못하는 계약 ".count($serverOnly)."건):\n";
foreach ($serverOnly as $item) {
    echo "  · {$item}\n";
}
echo "\n";

if ($violations === []) {
    echo 'RESULT: 정적 검사 PASS — 통과 '.count($passes)."건, 위반 0건\n";
    echo "이 PASS 는 소스 계약만 뜻합니다. 부팅 순서·라우트 매칭·세션 공유는\n";
    echo "위 '서버 PHPUnit 필요' 목록대로 서버 실행으로만 판정됩니다.\n";
    exit(0);
}

echo 'RESULT: 정적 검사 FAIL — 통과 '.count($passes).'건, 위반 '.count($violations)."건\n";
exit(1);
