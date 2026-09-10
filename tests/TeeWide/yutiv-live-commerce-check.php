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
// Phase 1-A: 라이브 루트가 실제 화면이 됐다. 더 이상 404 로 두지 않는다.
check('소스: 라이브 루트(/)가 라이브 홈으로 등록된다',
    strpos($providerCode, "Route::get('/', [LiveController::class, 'home'])") !== false
    && strpos($providerCode, "->name('teewide.live.home')") !== false);
check('소스: 포털 루트(/)가 포털 화면으로 등록된다',
    strpos($providerCode, "Route::get('/', [PortalController::class, 'index'])") !== false
    && strpos($providerCode, "->name('teewide.portal')") !== false);
check('소스: 채널 라우트가 LiveController 로 간다',
    strpos($providerCode, "Route::get('/{tenant}', [LiveController::class, 'channel'])") !== false);
// ── 진단 스위치와 제품 화면의 분리 (Phase 1-A 구조 변경) ────────────────────
//
// Phase 0 에서는 TEEWIDE_DIAGNOSTICS 가 화면까지 함께 껐다. 진단 스위치가 서비스를
// 끄는 구조는 옳지 않다 — 이제 그 값은 /_teewide/session 만 켜고 끈다.
$routesBody = twMethodBody($providerCode, 'registerTeeWideRoutes');

check('진단분리: 제품 화면 등록이 진단 스위치 안에 들어가 있지 않다',
    is_string($routesBody)
    && preg_match('/if \(\$diagnostics\) \{(?:(?!\}).)*PortalController/s', $routesBody) !== 1
    && preg_match('/if \(\$diagnostics\) \{(?:(?!\}).)*LiveController/s', $routesBody) !== 1);
check('진단분리: 진단 라우트만 스위치로 감싼다',
    is_string($routesBody)
    && substr_count($routesBody, 'if ($diagnostics) {') === 2
    && substr_count($routesBody, 'DiagnosticsController::class') === 2);
check('진단분리: 진단 라우트를 {tenant} 보다 먼저 등록한다',
    twOrderedIn($routesBody, "'teewide.live.session'", "Route::get('/{tenant}'"),
    'slug 패턴이 _teewide 를 삼키면 진단이 사라진다');
check('진단분리: 설정 주석이 화면과 무관함을 명시한다',
    strpos($configSource, '제품 화면과 무관') !== false);

// ── 화면 자산 ──────────────────────────────────────────────────────────────
$viewDir = $pluginDir.'/resources/views';
check('화면: Blade 화면 3종과 레이아웃이 있다',
    is_file($viewDir.'/layouts/teewide.blade.php')
    && is_file($viewDir.'/portal/index.blade.php')
    && is_file($viewDir.'/live/home.blade.php')
    && is_file($viewDir.'/live/channel.blade.php'));
check('화면: 플러그인 네임스페이스로 view 를 등록한다',
    strpos($providerCode, "loadViewsFrom(\$views, 'teewide')") !== false);

$viewSources = '';
foreach (glob($viewDir.'/*/*.blade.php') as $file) {
    $viewSources .= file_get_contents($file);
}
$layoutSource = (string) @file_get_contents($viewDir.'/layouts/teewide.blade.php');
$allViews = $viewSources.$layoutSource;

check('화면: 외부 CDN·폰트·이미지를 참조하지 않는다',
    strpos($allViews, '//cdn.') === false
    && strpos($allViews, 'fonts.googleapis.com') === false
    && strpos($allViews, 'fonts.gstatic.com') === false
    && strpos($allViews, 'unpkg.com') === false
    && strpos($allViews, 'jsdelivr.net') === false
    && preg_match('/<img[^>]+src="https?:/', $allViews) !== 1);
check('화면: 반응형·접근성 기본이 들어 있다',
    strpos($layoutSource, 'name="viewport"') !== false
    && strpos($layoutSource, '본문으로 건너뛰기') !== false
    && strpos($layoutSource, ':focus-visible') !== false
    && strpos($layoutSource, '@media (max-width: 640px)') !== false);
check('화면: 진단 필드를 화면에 노출하지 않는다',
    strpos($allViews, 'session_configured') === false
    && strpos($allViews, 'yutiv_user_leaked') === false);
check('화면: 없는 수치를 지어내지 않는다',
    strpos($allViews, '% 할인') === false
    && strpos($allViews, '명 시청') === false
    && strpos($allViews, '개 남음') === false);
check('화면: 준비 중 상태를 정직하게 표기한다',
    strpos($allViews, '라이브 상품을 준비하고 있습니다') !== false
    && strpos($allViews, '방송 준비 중') !== false);
// 판매 시작하기는 아직 백엔드가 없다 — 링크가 아니라 눌리지 않는 버튼이어야 한다.
$headerPartial = (string) @file_get_contents($viewDir.'/partials/portal-header.blade.php');
check('화면: 준비되지 않은 CTA 를 링크로 두지 않는다',
    strpos($headerPartial, 'disabled aria-describedby="tw-cta-note"') !== false
    && preg_match('/<a[^>]*>\\s*판매 시작하기/u', $allViews) !== 1);

// ── ViewModel ─────────────────────────────────────────────────────────────
$presenterSrc = (string) @file_get_contents($pluginDir.'/src/Support/TeeWidePresenter.php');
$presenterCode = twStripComments($presenterSrc);

// Phase 1-B: 공개 채널의 권위 소스가 설정에서 DB(live_tenants)로 옮겨졌다.
// 파일 어딘가가 아니라 **그 메서드가** DB 를 보는지 확인한다.
$publicTenantsBody = twMethodBody($presenterCode, 'publicTenants');
$findTenantBody = twMethodBody($presenterCode, 'findPublicTenant');
check('화면: 채널 목록이 DB(live_tenants)에서 나온다',
    is_string($publicTenantsBody)
    && strpos($publicTenantsBody, 'LiveTenant::query()') !== false
    && strpos($publicTenantsBody, '->public()') !== false,
    '보이는 채널과 열리는 채널이 어긋나면 눌러도 404 가 된다');
check('화면: 단일 채널 조회도 DB 를 본다',
    is_string($findTenantBody)
    && strpos($findTenantBody, 'LiveTenant::query()') !== false
    && strpos($findTenantBody, '->public()') !== false);
check('화면: 공개 판정이 status=active 로만 이뤄진다',
    strpos(twStripComments(file_get_contents($pluginDir.'/src/Models/LiveTenant.php')),
        "where('status', self::STATUS_ACTIVE)") !== false);
check('화면: 조회 실패에 fail-open 하지 않는다',
    preg_match('/catch \\(\\\\Throwable \\$e\\) \\{[^}]*reportTenantLookupFailure[^}]*return \\[\\];/s', $presenterCode) === 1
    && preg_match('/catch \\(\\\\Throwable \\$e\\) \\{[^}]*reportTenantLookupFailure[^}]*return null;/s', $presenterCode) === 1,
    'DB 오류를 이유로 비공개 채널이 열리면 안 된다');
check('화면: 아직 없는 데이터는 빈 배열로 정직하게 돌려준다',
    preg_match('/function onAir\(\): array\s*\{\s*return \[\];/', $presenterCode) === 1
    && preg_match('/function liveProducts\([^)]*\): array\s*\{\s*return \[\];/', $presenterCode) === 1);
check('화면: tenant 표시 정보와 접근 판정이 분리돼 있다',
    strpos($configSource, "'tenant_profiles'") !== false
    && strpos(file_get_contents($pluginDir.'/src/Support/TeeWideConfig.php'), 'function tenantProfile(') !== false);

// ── 컨트롤러 분리 ─────────────────────────────────────────────────────────
$portalSrc = (string) @file_get_contents($pluginDir.'/src/Http/Controllers/PortalController.php');
$liveSrc = (string) @file_get_contents($pluginDir.'/src/Http/Controllers/LiveController.php');

// 이 시점에는 아래쪽에서 쓰는 $controllerCode 가 아직 없다 — 여기서 직접 읽는다.
$diagCtrlCode = twStripComments(
    (string) @file_get_contents($pluginDir.'/src/Http/Controllers/DiagnosticsController.php')
);
check('컨트롤러: 진단 컨트롤러에는 JSON 진단만 남는다',
    strpos($diagCtrlCode, 'function sessionMarker(') !== false
    && strpos($diagCtrlCode, 'function portal(') === false
    && strpos($diagCtrlCode, 'function live(') === false);
check('컨트롤러: 화면 컨트롤러는 View 를 돌려준다',
    strpos($portalSrc, "view('teewide::portal.index'") !== false
    && strpos($liveSrc, "view('teewide::live.home'") !== false
    && strpos($liveSrc, "view('teewide::live.channel'") !== false);
check('컨트롤러: 화면 컨트롤러가 JSON 을 돌려주지 않는다',
    strpos(twStripComments($portalSrc), 'response()->json(') === false
    && strpos(twStripComments($liveSrc), 'response()->json(') === false);
$channelBody = twMethodBody(twStripComments($liveSrc), 'channel');
check('컨트롤러: 비공개 tenant 는 DB 판정으로 404',
    is_string($channelBody)
    && strpos($channelBody, 'TeeWidePresenter::publicChannel($tenant)') !== false
    && preg_match('/if \\(\\$channel === null\\) \\{\\s*throw new NotFoundHttpException/', $channelBody) === 1,
    '조회 결과가 null 인데 화면을 그리면 비공개 채널이 열린다');

// ── Phase 1-B: 회원·인증 ──────────────────────────────────────────────────
$authSrc = (string) @file_get_contents($pluginDir.'/src/Support/TeeWideAuth.php');
$loginSrc = (string) @file_get_contents($pluginDir.'/src/Http/Controllers/Auth/LoginController.php');
$registerSrc = (string) @file_get_contents($pluginDir.'/src/Http/Controllers/Auth/RegisterController.php');
$userModelSrc = (string) @file_get_contents($pluginDir.'/src/Models/TeeWideUser.php');
$requireSrc = (string) @file_get_contents($pluginDir.'/src/Http/Middleware/RequireTeeWideUser.php');
$authCode = twStripComments($authSrc);
$loginCode = twStripComments($loginSrc);
$registerCode = twStripComments($registerSrc);

check('인증: TeeWide 전용 guard/provider 를 쓴다',
    strpos($authCode, "const GUARD = 'teewide'") !== false
    && strpos($authCode, "const PROVIDER = 'teewide_users'") !== false
    && strpos($authCode, 'Auth::guard(self::GUARD)') !== false);
check('인증: 회원 테이블이 YUTIV users 와 분리돼 있다',
    strpos($userModelSrc, "protected \$table = 'teewide_users';") !== false);
// ⚠ `TeeWideAuth::user()` 가 `Auth::user()` 를 부분 문자열로 포함한다.
//   그래서 앞에 다른 식별자 문자가 붙지 않은 경우만 잡는다.
$authUsage = $loginCode.$registerCode;
check('인증: 기본 guard 로 TeeWide 인증을 판정하지 않는다',
    preg_match('/(?<![A-Za-z0-9_])Auth::(user|check|guard)\\(\\)/', $authUsage) !== 1
    && preg_match('/auth\\(\\)->(user|check)\\(\\)/', $authUsage) !== 1,
    'Auth::user() / auth()->user() 는 YUTIV web guard 를 본다');
check('인증: 설정을 통째로 덮어쓰지 않고 두 키만 더한다',
    strpos($authCode, "'auth.guards.'.self::GUARD") !== false
    && strpos($authCode, "'auth.providers.'.self::PROVIDER") !== false
    && preg_match("/config\\(\\['auth' =>/", $providerCode) !== 1);
check('인증: 비밀번호를 Hash 로 저장한다',
    strpos($registerCode, 'Hash::make($validated[') !== false
    && strpos($userModelSrc, "'password' => 'hashed'") !== false);
check('인증: 로그인 실패 문구가 계정 존재 여부를 구분하지 않는다',
    substr_count($loginCode, 'self::GENERIC_FAILURE') === 2);
check('인증: active 계정만 로그인한다',
    strpos($loginCode, 'canAuthenticate()') !== false
    && strpos(twStripComments($userModelSrc), "status === self::STATUS_ACTIVE") !== false);
check('인증: 로그인·회원가입이 세션 ID 를 재발급한다',
    strpos($loginCode, 'session()->regenerate()') !== false
    && strpos($registerCode, 'session()->regenerate()') !== false);
check('인증: 로그아웃이 teewide guard 만 끊는다',
    strpos($loginCode, 'TeeWideAuth::guard()->logout()') !== false
    && preg_match('/Auth::logout\\(\\)/', $loginCode) !== 1);
$logoutBody = twMethodBody($loginCode, 'destroy');
check('인증: 로그아웃이 세션을 무효화하고 CSRF 토큰을 갱신한다',
    is_string($logoutBody)
    && strpos($logoutBody, 'session()->invalidate()') !== false
    && strpos($logoutBody, 'session()->regenerateToken()') !== false);
check('인증: 이메일 정규화가 한 곳에서 이뤄진다',
    strpos($userModelSrc, 'function normalizeEmail(') !== false
    && substr_count($loginCode.$registerCode, 'TeeWideUser::normalizeEmail(') === 2);
// 주석에는 "코어는 route('login') 으로 보낸다" 는 설명이 있으므로 주석 제거본을 본다.
$requireCode = twStripComments($requireSrc);
check('인증: 비로그인 리다이렉트가 YUTIV 로그인으로 가지 않는다',
    strpos($requireCode, "route('teewide.login')") !== false
    && strpos($requireCode, "route('login')") === false);
check('인증: 쓰기 요청에 throttle 이 걸려 있다',
    substr_count($providerCode, "middleware('throttle:'.self::AUTH_THROTTLE)") === 2);
check('인증: 로그아웃 라우트가 POST 전용이다',
    strpos($providerCode, "Route::post('/logout'") !== false
    && preg_match("/Route::get\\('\\/logout'/", $providerCode) !== 1);
check('인증: 인증 라우트가 포털 호스트 그룹 안에만 있다',
    is_string($routesBody)
    && twOrderedIn($routesBody, "'teewide.account'", 'liveHost()'),
    '라이브 호스트에 로그인 폼이 생기면 어느 쪽이 진짜인지 흐려진다');
check('인증: 인증 라우트의 경로와 이름이 계약대로 짝지어져 있다',
    is_string($routesBody)
    && preg_match("/Route::get\\('\\/account'.*?->name\\('teewide\\.account'\\)/s", $routesBody) === 1
    && preg_match("/Route::get\\('\\/login'.*?->name\\('teewide\\.login'\\)/s", $routesBody) === 1
    && preg_match("/Route::get\\('\\/register'.*?->name\\('teewide\\.register'\\)/s", $routesBody) === 1
    && preg_match("/Route::post\\('\\/logout'.*?->name\\('teewide\\.logout'\\)/s", $routesBody) === 1);

// ── 인증 테스트는 요청 밖에서 세션을 관찰하지 않는다 ──────────────────────
//
// ConfigureTeeWideSession 은 TeeWide 요청 **동안에만** 전용 session.store 와 teewide
// guard 를 설치하고, 쿠키 발급·세션 저장이 끝난 뒤 finally 에서 YUTIV 상태로 되돌린다.
// 그래서 요청이 끝난 뒤 아래를 보면 TeeWide 가 아니라 복원된 YUTIV 상태를 본다 —
// 테스트가 관찰하는 대상 자체가 틀린다.
$authTestSrc = (string) @file_get_contents($pluginDir.'/tests/Feature/TeeWideAuthTest.php');
$authTestCode = twStripComments($authTestSrc);

check('인증테스트: 요청 밖에서 TeeWide guard 를 관찰하지 않는다',
    strpos($authTestCode, 'TeeWideAuth::check()') === false
    && strpos($authTestCode, 'TeeWideAuth::user()') === false,
    '요청이 끝나면 guard 는 YUTIV 로 복원된다');
check('인증테스트: 요청 밖에서 세션 ID 를 읽지 않는다',
    strpos($authTestCode, 'session()->getId()') === false,
    '요청이 끝나면 session() 은 YUTIV Store 를 가리킨다');
check('인증테스트: 세션 기반 오류 단언을 쓰지 않는다',
    strpos($authTestCode, 'assertSessionHasErrors') === false
    && strpos($authTestCode, '->getSession()') === false,
    'flash 된 오류는 TeeWide Store 에 있고 그 Store 는 요청 종료와 함께 내려간다');

// actingAs() 는 세션이 아니라 guard 객체에 사용자를 직접 꽂는다(SessionGuard::setUser).
// 게다가 ConfigureTeeWideSession 이 요청 진입 시 forgetGuards() 를 부르므로 그 주입은
// TeeWide 요청 안에서 사라진다 — 어느 쪽으로도 **쿠키 격리를 증명하지 못한다.**
check('인증테스트: YUTIV 경계 검증에 actingAs 를 쓰지 않는다',
    strpos($authTestCode, 'actingAs(') === false,
    'actingAs 는 guard 주입이라 실제 쿠키 격리를 증명하지 못한다');
check('인증테스트: 진짜 YUTIV 로그인 세션 쿠키로 경계를 검증한다',
    substr_count($authTestCode, 'makeYutivLoginSession(') === 2
    // 보호화면과 진단 **두 곳 모두** 실제 쿠키를 실어 보내야 한다.
    // 존재 검사만 하면 한쪽을 지워도 통과한다.
    && substr_count($authTestCode, 'withUnencryptedCookie($this->yutivSessionCookieName()') === 2);
check('인증테스트: 그 쿠키로 보호화면과 진단을 모두 확인한다',
    strpos($authTestCode, "assertRedirect(route('teewide.login'))") !== false
    && strpos($authTestCode, "assertJsonPath('yutiv_user_leaked', false)") !== false);

// 세 스위트가 같은 "진짜 YUTIV 로그인 세션" 정의를 쓰는가.
// 이 지점에는 아래쪽에서 쓰는 $baseCode 가 아직 없다 — 여기서 직접 읽는다.
$pluginBaseCode = twStripComments((string) @file_get_contents($pluginDir.'/tests/PluginTestCase.php'));
check('인증테스트: YUTIV 세션 헬퍼가 공통 베이스에 하나만 있다',
    strpos($pluginBaseCode, 'function makeYutivLoginSession(') !== false
    && strpos($pluginBaseCode, 'function yutivSessionCookieName(') !== false
    && strpos($pluginBaseCode, 'function yutivLoginSessionKey(') !== false);
check('인증테스트: 로그인 키가 SessionGuard 규칙 그대로다',
    strpos($pluginBaseCode, "'login_web_'.sha1(SessionGuard::class)") !== false,
    'guard 가 실제로 쓰는 키가 아니면 진짜 로그인 세션이 아니다');
check('인증테스트: 스위트가 그 헬퍼를 중복 정의하지 않는다',
    strpos($isolationCode, 'function makeYutivLoginSession(') === false
    && strpos(twStripComments((string) @file_get_contents($pluginDir.'/tests/Feature/TeeWideSessionBoundaryTest.php')),
        'function makeYutivLoginSession(') === false);

check('인증테스트: 응답 쿠키를 꺼내는 헬퍼가 있다',
    strpos($authTestCode, "getCookie(self::SESSION_COOKIE, false)") !== false
    && strpos($authTestCode, 'function teeWideSessionCookie(') !== false);
check('인증테스트: 다음 요청에 쿠키를 명시적으로 실어 보낸다',
    strpos($authTestCode, 'withUnencryptedCookie(self::SESSION_COOKIE, $sessionId)') !== false
    && substr_count($authTestCode, 'withTeeWideSession(') >= 10,
    '기본 쿠키 자동 전달에 기대면 TeeWide 세션이 이어지지 않는다');
check('인증테스트: 쿠키가 없으면 명확히 실패시킨다',
    preg_match('/assertNotNull\(\s*\$cookie,/', $authTestCode) === 1);

// 보안 계약이 그대로 남아 있는가 (숫자를 줄여 통과시키지 않았는지)
check('인증테스트: 보안 단언이 유지된다',
    strpos($authTestCode, 'GENERIC_FAILURE') !== false
    && strpos($authTestCode, 'Hash::check(') !== false
    && strpos($authTestCode, "auth()->guard('web')->check()") !== false
    && strpos($authTestCode, 'assertStringNotContainsString') !== false);
check('인증테스트: 테스트 수가 줄지 않았다',
    substr_count($authTestCode, 'public function test_') >= 19,
    '실제 '.substr_count($authTestCode, 'public function test_').'종');

// ── Phase 1-B: 마이그레이션·시더 ──────────────────────────────────────────
$migrationDir = $pluginDir.'/database/migrations';
$usersMigration = (string) @file_get_contents($migrationDir.'/2026_09_11_000001_create_teewide_users_table.php');
$tenantsMigration = (string) @file_get_contents($migrationDir.'/2026_09_11_000002_create_live_tenants_table.php');
$seederSrc = (string) @file_get_contents($pluginDir.'/src/Database/Seeders/LiveTenantSeeder.php');

check('DB: 두 테이블 마이그레이션이 있다',
    strpos($usersMigration, "Schema::create('teewide_users'") !== false
    && strpos($tenantsMigration, "Schema::create('live_tenants'") !== false);
check('DB: 롤백이 가능하다',
    strpos($usersMigration, "Schema::dropIfExists('teewide_users')") !== false
    && strpos($tenantsMigration, "Schema::dropIfExists('live_tenants')") !== false);
check('DB: unique 와 인덱스를 명시한다',
    substr_count($usersMigration, '->unique()') >= 2
    && strpos($usersMigration, "index('status'") !== false
    && strpos($tenantsMigration, "index(['status', 'slug']") !== false);
check('DB: owner FK 가 teewide_users 를 가리킨다',
    strpos($tenantsMigration, "->on('teewide_users')") !== false
    && strpos($tenantsMigration, '->nullOnDelete()') !== false);
check('DB: 시더가 updateOrCreate 로 멱등하다',
    strpos($seederSrc, 'updateOrCreate(') !== false
    && strpos($seederSrc, "['slug' => \$tenant['slug']]") !== false);
check('DB: 시더가 회원 계정이나 기본 비밀번호를 만들지 않는다',
    strpos($seederSrc, 'TeeWideUser') === false
    && strpos($seederSrc, 'Hash::make') === false
    && stripos($seederSrc, 'password') === false);

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
    // driver 를 **읽는** 것은 정상이다(전용 Store 를 같은 드라이버로 만들어야 하므로).
    // 금지는 driver 를 **바꾸는** 것이다.
    && preg_match("/'session\\.driver'\\s*=>/", twStripComments($sessionSrc)) !== 1);

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

// [주입 23] 세션 Store 인스턴스 격리 제거
//
// 쿠키 이름만 바꾸는 방식으로는 부족하다. Store 를 공유하면 `loadSession()` 의
// `array_replace($this->attributes, ...)`(Store.php:114-119) 때문에 이전 요청의
// attributes 가 그대로 남아 `login_web_<sha1>` 이 TeeWide 요청까지 따라온다.
// 서버 5차 실행의 마지막 실패가 정확히 이것이었다.
$scopeSrc = file_get_contents($pluginDir.'/src/Support/TeeWideSessionScope.php');
$scopeCode = twStripComments($scopeSrc);

check('세션범위: 원래 Store 를 설정 변경 **전에** 확보한다 [주입 23]',
    twOrderedIn($handleBody, 'resolveHostStore()', "'session.cookie' =>"),
    '설정을 먼저 바꾸면 원래 Store 가 TeeWide 설정으로 만들어진다');
$enterBody = twMethodBody($scopeCode, 'enter');

check('세션범위: TeeWide 요청은 **별도 Store** 를 쓴다 [주입 23]',
    is_string($handleBody)
    && strpos($handleBody, 'TeeWideSessionScope::enter(') !== false
    && is_string($enterBody)
    && strpos($enterBody, '$scopedManager->driver($driverName)') !== false);

// ── custom creator 반환 계약 (6차 서버 TypeError 24건의 원인) ──────────────
//
// `SessionManager` 는 `callCustomCreator()` 를 **오버라이드**한다(SessionManager.php:18-21):
//     return $this->buildSession(parent::callCustomCreator($driver));
// 즉 콜백 반환값은 완성된 Store 가 아니라 `buildSession($handler)` 에 넘길
// SessionHandlerInterface 다(190-200). Store 를 돌려주면 `new Store($cookie, $store, ...)`
// 가 되어 Store::__construct 의 타입 선언(Store.php:85)에 걸려 TypeError 가 난다.

// [주입 42] 콜백이 Store 를 반환
check('creator계약: 콜백이 handler 를 반환한다 [주입 42]',
    is_string($enterBody)
    && preg_match('/extend\\(\\$driverName,\\s*static fn \\(\\) => \\$handler\\)/', $enterBody) === 1);
check('creator계약: 콜백이 Store 를 반환하지 않는다 [주입 42]',
    is_string($enterBody)
    && preg_match('/extend\\([^)]*=>\\s*(\\$teeWideStore|\\$store|new Store|new EncryptedStore)/', $enterBody) !== 1,
    'SessionManager 는 콜백 결과를 buildSession() 에 넘긴다 — Store 를 주면 이중 build 로 TypeError');
check('creator계약: 직접 new Store 하지 않고 Laravel 이 만들게 한다 [주입 42]',
    strpos($scopeCode, 'new Store(') === false
    && strpos($scopeCode, 'new EncryptedStore(') === false,
    'buildSession/buildEncryptedSession 이 encrypt·serialization·cookie 를 처리하게 둔다');
check('creator계약: handler 를 host Store 에서 가져온다 [주입 42]',
    is_string($enterBody)
    && strpos($enterBody, '$hostStore->getHandler()') !== false);

// [주입 43] 타입 단언 제거
$assertBody = twMethodBody($scopeCode, 'assertScopedStore');
check('creator계약: handler 타입을 명시적으로 검증한다 [주입 43]',
    is_string($enterBody)
    && strpos($enterBody, 'instanceof SessionHandlerInterface') !== false);
check('creator계약: 생성된 Store 의 타입·정체를 검증한다 [주입 43]',
    is_string($assertBody)
    && strpos($assertBody, 'instanceof Store') !== false
    && strpos($assertBody, '$store === $hostStore') !== false
    && strpos($assertBody, '$store->getHandler() !== $handler') !== false);
check('creator계약: encrypt on/off 를 양방향으로 검증한다 [주입 43]',
    is_string($assertBody)
    && strpos($assertBody, '$encrypt && ! $store instanceof EncryptedStore') !== false
    && strpos($assertBody, '! $encrypt && $store instanceof EncryptedStore') !== false);
check('creator계약: 쿠키 이름을 검증한다 [주입 43]',
    is_string($assertBody) && strpos($assertBody, '$store->getName() !== $cookie') !== false);
check('creator계약: 불일치 시 의미 있는 예외로 끊는다 [주입 43]',
    substr_count($scopeCode, 'throw new LogicException(') >= 6);
check('세션범위: 쿠키 이름만 바꾸는 방식으로 끝내지 않는다 [주입 23]',
    is_string($handleBody) && strpos($handleBody, 'setName(') === false,
    '같은 객체의 이름만 바꾸면 attributes·started 상태가 그대로 공유된다');
check('세션범위: 전용 Store 가 핸들러를 공유한다 (세션 레코드 보존) [주입 23]',
    strpos($scopeCode, '$hostStore->getHandler()') !== false
    && is_string($assertBody)
    && strpos($assertBody, 'getHandler() !== $handler') !== false);
check('세션범위: 저장된 세션을 지우지 않는다 [주입 23]',
    strpos($scopeCode, '->flush()') === false
    && strpos($handleBody, '->flush()') === false
    && strpos($handleBody, '->forget(') === false);
check('세션범위: finally 에서 스코프를 닫는다 [주입 23]',
    is_string($handleBody)
    && preg_match('/finally\\s*\\{.*?TeeWideSessionScope::leave\\(/s', $handleBody) === 1);
check('세션범위: session.encrypt 처리를 프레임워크에 맡긴다',
    strpos($scopeCode, 'new EncryptedStore(') === false
    && strpos($scopeCode, "get('session.encrypt')") !== false
    && strpos($scopeCode, 'EncryptedStore') !== false,
    'buildSession() 이 encrypt 를 보고 고르게 두되, 결과 타입은 검증한다');

// [주입 28] 인증 guard 캐시 격리 제거
//
// AuthManager 는 guard 생성 시 `$this->app['session.store']` 를 캡처하고
// (AuthManager.php:122-131) guard 를 캐시한다(65-70). Store 만 바꾸고 guard 를 두면
// guard 가 옛 Store 를 계속 본다.
check('세션범위: session.store 바인딩을 전용 Store 로 바꾼다 [주입 28]',
    is_string($enterBody)
    && strpos($enterBody, "instance('session.store', \$teeWideStore)") !== false);
check('세션범위: guard 캐시를 비워 새 Store 를 보게 한다 [주입 28]',
    is_string($handleBody)
    && substr_count($handleBody, 'forgetGuards()') === 2,
    '진입과 finally 양쪽에서 비워야 한다');
check('세션범위: 스코프를 닫을 때 원래 인스턴스를 그대로 되돌린다 [주입 28]',
    strpos($scopeCode, "instance('session.store', \$token['session.store'])") !== false
    && strpos($scopeCode, "instance('session', \$token['session'])") !== false
    && strpos($scopeCode, "instance(StartSession::class, \$token['start_session'])") !== false);
check('세션범위: guard 를 비우는 것이 로그아웃이 아님을 명시한다 [주입 28]',
    strpos($sessionSrc, 'logout') === false
    && strpos($sessionSrc, '로그아웃이 아니다') !== false);

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
// 로그인 키 정의는 PluginTestCase 로 옮겼다(세 스위트 공용). 여기서는 이 스위트가
// 그 공용 헬퍼로 **진짜 세션**을 만들어 쿠키로 보내는지만 본다.
check('세션범위: 실제 YUTIV 로그인 세션 쿠키로 판정한다 [주입 26]',
    strpos($isolationCode, 'makeYutivLoginSession(') !== false
    && strpos($isolationCode, 'yutivSessionCookieName()') !== false
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
    substr_count($controllerCode, "'route' => \$request->route()?->getName()") === 1
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
check('등록판정: boot 중 늘어난 라우트 수를 기대 목록과 대조한다 [주입 14]',
    is_string($lifecycleBody)
    && strpos($lifecycleBody, 'count($this->expectedTeeWideRouteNames()), $end - $start') !== false);
// 메서드가 있는지가 아니라 **실제로 분기하는지**를 본다.
// 고정 목록을 돌려주면 진단 OFF 스위트가 조용히 통과해 버린다.
$expectedBody = twMethodBody($baseCode, 'expectedTeeWideRouteNames');
check('등록판정: 기대 목록이 진단 스위치에 따라 달라진다 [주입 14]',
    is_string($expectedBody)
    && strpos($expectedBody, "'diagnostics_enabled'") !== false
    && strpos($expectedBody, 'self::teeWideRouteNames()') !== false
    && strpos($expectedBody, 'self::teeWideProductRouteNames()') !== false);
check('등록판정: 제품 전용 목록에 진단 라우트가 없다 [주입 14]',
    is_string($productNamesBody = twMethodBody($baseCode, 'teeWideProductRouteNames'))
    && strpos($productNamesBody, 'teewide.live.home') !== false
    && strpos($productNamesBody, '.session') === false);
check('등록판정: SPA catch-all 보다 앞선다는 것도 사전검사가 확인한다 [주입 14]',
    is_string($lifecycleBody)
    && strpos($lifecycleBody, 'spaCatchAllRoute()') !== false
    && strpos($lifecycleBody, 'routeIndex(') !== false);
check('등록판정: 라우트 이름·도메인·액션까지 대조한다 [주입 14]',
    is_string($lifecycleBody)
    && strpos($lifecycleBody, 'routeNamesAtBootEnd') !== false
    && strpos($lifecycleBody, 'getDomain()') !== false
    && strpos($lifecycleBody, 'isTeeWideControllerAction(') !== false);
// 목록을 리터럴로 고정하지 않는다 — 컨트롤러가 늘 때마다 이 검사가 거짓 실패한다.
// 지켜야 할 계약은 "이 플러그인 네임스페이스 안의 컨트롤러만 허용한다" 이다.
$allowListBody = twMethodBody($baseCode, 'isTeeWideControllerAction');
// ── 기대 라우트 목록 ↔ provider 선언 교차 검증 ─────────────────────────────
//
// 정확 일치 단언은 런타임(PHPUnit)이 실제 라우터와 비교해 강제한다. 다만 기대 목록에서
// 이름 하나가 빠지면 서버에 올려야만 드러나므로, 여기서 provider 의 `->name('teewide.…')`
// 선언과 대조해 미리 잡는다.
preg_match_all("/->name\('(teewide\.[a-z.]+)'\)/", $providerCode, $declaredNames);
$declared = array_values(array_unique($declaredNames[1]));
sort($declared);

preg_match_all("/'(teewide\.[a-z.]+)'/", twMethodBody($baseCode, 'teeWideRouteNames') ?? '', $expectedNames);
$expected = array_values(array_unique($expectedNames[1]));
sort($expected);

check('등록판정: 기대 목록이 provider 가 선언한 라우트 이름과 정확히 일치한다',
    $declared !== [] && $declared === $expected,
    '선언 '.implode(',', $declared).' / 기대 '.implode(',', $expected));

// 진단 OFF 목록 = 전체 목록에서 /_teewide/session 두 개만 뺀 것이어야 한다.
preg_match_all("/'(teewide\.[a-z.]+)'/", twMethodBody($baseCode, 'teeWideProductRouteNames') ?? '', $productNames);
$product = array_values(array_unique($productNames[1]));
sort($product);
$expectedProduct = array_values(array_filter($expected, static fn ($n) => substr($n, -8) !== '.session'));

check('등록판정: 진단 OFF 목록은 진단 라우트만 빠진 것이다',
    $product === $expectedProduct,
    '기대 '.implode(',', $expectedProduct).' / 실제 '.implode(',', $product));

check('등록판정: 액션 허용 목록이 이 플러그인 컨트롤러로 한정된다',
    is_string($allowListBody)
    && strpos($allowListBody, 'Plugins\\\\Yutiv\\\\LiveCommerce\\\\Http\\\\Controllers\\\\') !== false
    // 항목 하나라도 맨 'Controller' 면 아무 컨트롤러나 통과한다 (와일드카드).
    && preg_match("/'Controller'\\s*,/", $allowListBody) !== 1
    && substr_count($allowListBody, 'Controller') >= 4);

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

// ── 11. 세션 경계 회귀 테스트 (5차 실패의 보안 계약) ───────────────────────

$boundarySrc = @file_get_contents($pluginDir.'/tests/Feature/TeeWideSessionBoundaryTest.php');
$boundaryCode = is_string($boundarySrc) ? twStripComments($boundarySrc) : '';

foreach ([
    'YUTIV_로그인_세션이_TeeWide_요청으로_넘어가지_않는다',
    'TeeWide_세션_속성이_YUTIV_요청으로_넘어가지_않는다',
    'TeeWide_요청은_YUTIV_세션_속성을_상속하지_않는다',
    'TeeWide_요청은_YUTIV_와_다른_Store_객체를_쓴다',
    'TeeWide_요청마다_새_Store_객체가_만들어진다',
    '객체는_달라도_세션_ID_기반_데이터는_유지된다',
    'TeeWide_요청_뒤에도_YUTIV_로그인_세션_레코드가_남아_있다',
    '예외가_나도_Store_와_guard_가_복원된다',
] as $case) {
    check('세션경계 회귀: '.$case, strpos($boundaryCode, 'function test_'.$case) !== false);
}

// ── custom creator 수명 (Manager::$customCreators 는 제거 API 가 없다) ──────
//
// `Manager::forgetDrivers()`(Manager.php:170-175)는 `$drivers` 인스턴스만 비우고
// `$customCreators` 는 남긴다. 공식 제거 API 가 없으므로, 호스트 매니저에 creator 를
// 설치하면 그 드라이버의 생성 경로가 **영구히** 가로채인다 — 이후 Laravel/Octane 이
// 새 Store 를 만들려 해도 붙잡아 둔 오래된 Store 가 다시 나온다.

// [주입 35] 호스트 SessionManager 에 creator 설치
check('creator수명: 호스트 SessionManager 에 extend 하지 않는다 [주입 35]',
    strpos($scopeCode, '$hostManager->extend(') === false
    && strpos($handleBody ?? '', '->extend(') === false
    && preg_match('/\\$scopedManager->extend\\(/', $scopeCode) === 1,
    'creator 는 쓰고 버리는 매니저에만 달아야 한다');
check('creator수명: 스코프 전용 매니저를 요청마다 새로 만든다 [주입 35]',
    strpos($scopeCode, 'new SessionManager($app)') !== false);
check('creator수명: 호스트 매니저의 forgetDrivers 를 부르지 않는다 [주입 35]',
    strpos($scopeCode, 'forgetDrivers()') === false
    && strpos(twStripComments($sessionSrc), 'forgetDrivers()') === false,
    '호스트 캐시를 비우면 YUTIV Store 와 handler 가 함께 날아간다');

// [주입 36] static registry 가 Store/handler/매니저를 붙잡음
// static **속성**만 검사한다 (private static function 은 정상).
preg_match_all('/(?:private|protected|public)\\s+static\\s+(?!function)([^;]+);/', $scopeCode, $staticProps);
check('creator수명: static 상태로 Store·handler·매니저를 붙잡지 않는다 [주입 36]',
    count($staticProps[1]) === 1 && strpos($staticProps[1][0], 'int $depth') !== false,
    'static 으로 객체를 들고 있으면 새 Application 으로 잔재가 넘어간다: '
        .implode(' | ', $staticProps[1]));
check('creator수명: 보유하는 static 은 깊이 정수 하나뿐이다 [주입 36]',
    strpos($scopeCode, 'private static int $depth = 0;') !== false);
check('creator수명: 복원 정보는 호출별 지역 토큰으로 전달된다 [주입 36]',
    // leave() 가 토큰을 **인자로 받아야** 한다 — static registry 로 바꾸면
    // 중첩 호출에서 바깥 상태가 덮인다.
    preg_match('/function leave\\([^)]*array \\$token[^)]*\\)/', $scopeCode) === 1
    && preg_match('/function enter\\([^)]*\\): array/', $scopeCode) === 1
    && strpos(twMethodBody($scopeCode, 'leave') ?? '', '$token[') !== false);

// [주입 37] StartSession 이 호스트 Store 를 받도록 되돌리기
// enter() 본문 안에서 **새로 만든** StartSession 을 바인딩해야 한다.
// (leave() 의 복원 바인딩과 `new StartSession(` 이 파일 어딘가에 있다는 사실만으로는
//  교체가 살아 있음을 증명하지 못한다)
$enterBody = twMethodBody($scopeCode, 'enter');
check('creator수명: StartSession 바인딩을 스코프 매니저로 교체한다 [주입 37]',
    is_string($enterBody)
    && preg_match('/instance\\(\\s*StartSession::class\\s*,\\s*new StartSession\\(/', $enterBody) === 1);
check('creator수명: 교체한 StartSession 이 스코프 매니저를 받는다 [주입 37]',
    is_string($enterBody)
    && preg_match('/new StartSession\\(\\s*\\$scopedManager\\s*,/', $enterBody) === 1);

// [주입 38] 예외 후 depth 잔류
check('creator수명: 스코프 깊이를 되돌린다 [주입 38]',
    strpos($scopeCode, 'self::$depth++') !== false
    && strpos($scopeCode, 'self::$depth--') !== false
    && strpos($scopeCode, 'function depth(): int') !== false);

// [주입 29] 경계 판정을 객체 ID 가 아니라 문자열/상수로 위조
// 개수만 세면 단언 하나가 빠져도 통과한다 — 비교 **쌍** 자체를 대조한다.
check('세션경계: TeeWide Store 가 YUTIV Store 와 다름을 단언한다 [주입 29]',
    preg_match('/assertNotSame\\(\\s*\\$hostStoreId\\s*,\\s*\\$seen\\s*,/', $boundaryCode) === 1);
check('세션경계: 연속 TeeWide 요청의 Store 가 서로 다름을 단언한다 [주입 29]',
    preg_match('/assertNotSame\\(\\s*\\$ids\\[0\\]\\s*,\\s*\\$ids\\[1\\]\\s*,/', $boundaryCode) === 1);
check('세션경계: 요청 후 원래 Store 로 복원됨을 단언한다 [주입 29]',
    preg_match('/assertSame\\(\\s*\\$hostStoreId\\s*,\\s*spl_object_id\\(/', $boundaryCode) === 1);
check('세션경계: 실제 로그인 키를 SessionGuard 규칙으로 계산한다 [주입 29]',
    // 정의는 PluginTestCase 에 하나뿐이고, 이 스위트는 그것을 그대로 쓴다.
    strpos($boundaryCode, 'yutivLoginSessionKey()') !== false
    && strpos($pluginBaseCode, "'login_web_'.sha1(SessionGuard::class)") !== false);

// [주입 30] 세션 레코드를 지워서 통과시키기
check('세션경계: 테스트가 세션 레코드를 지우지 않는다 [주입 30]',
    strpos($boundaryCode, '->flush()') === false
    && strpos($boundaryCode, '->invalidate()') === false
    && strpos($boundaryCode, "->forget('login") === false);
check('세션경계: YUTIV 로그인 레코드 보존을 실제로 다시 읽어 확인한다 [주입 30]',
    strpos($boundaryCode, '$store->setId($yutivSessionId)') !== false
    && strpos($boundaryCode, '$store->get($this->loginKey())') !== false);

// [주입 31] actingAs 복귀 (guard 직접 주입이 다시 섞이는 것)
check('세션경계: actingAs 를 쓰지 않는다 [주입 31]',
    strpos($boundaryCode, 'actingAs(') === false,
    'actingAs 는 guard 인스턴스에 사용자를 꽂아 세션 경계를 증명하지 못한다');

// [주입 32] 앱 간 정적 스코프 누수
check('세션경계: 앱마다 세션 스코프 정적 상태를 초기화한다 [주입 32]',
    substr_count($baseCode, 'TeeWideSessionScope::reset()') === 2,
    'createApplication 과 tearDown 양쪽에서 비워야 한다');
check('세션경계: 스코프에 초기화 수단이 있다 [주입 32]',
    strpos($scopeCode, 'function reset(): void') !== false);

// ── custom creator 수명 회귀 테스트 존재 확인 ──────────────────────────────
$lifecycleSrc = @file_get_contents($pluginDir.'/tests/Feature/TeeWideSessionDriverLifecycleTest.php');
$lifecycleCode = is_string($lifecycleSrc) ? twStripComments($lifecycleSrc) : '';

foreach ([
    'TeeWide_요청_후_forgetDrivers_는_새_Store_를_만든다',
    'forgetDrivers_후_새_Store_는_이전_attributes_를_물려받지_않는다',
    'driver_설정이_바뀌면_이전_handler_를_재사용하지_않는다',
    '비활성_스코프에서는_플러그인이_Store_생성에_개입하지_않는다',
    'StartSession_바인딩이_요청_후_원래_인스턴스로_돌아온다',
    '스코프_매니저는_요청마다_다른_인스턴스다',
    '정상_종료_후_스코프_깊이가_0_이다',
    '중첩_호출_중_예외가_나도_깊이가_0_으로_복원된다',
    '새_SessionManager_는_플러그인_creator_없이_동작한다',
    'YUTIV_TeeWide_YUTIV_TeeWide_경계가_각각_독립적이다',
] as $case) {
    check('creator수명 회귀: '.$case, strpos($lifecycleCode, 'function test_'.$case) !== false);
}

foreach ([
    '스코프_매니저의_creator_는_handler_를_반환한다',
    'creator_가_Store_를_반환하면_TypeError_가_난다',
    'TeeWide_Store_는_encrypt_설정에_따라_만들어진다',
    'encrypt_가_켜지면_EncryptedStore_가_만들어진다',
    'TeeWide_HTTP_요청이_500_이_아니라_200_이다',
] as $case) {
    check('creator계약 회귀: '.$case, strpos($lifecycleCode, 'function test_'.$case) !== false);
}

// 잘못된 구현을 **실행으로** 고정하는 테스트가 있어야 한다.
check('creator계약: 잘못된 반환(Store)이 TypeError 임을 실행 검증한다',
    strpos($lifecycleCode, 'expectException(\\TypeError::class)') !== false
    && preg_match('/extend\\(.array., static fn \\(\\) => \\$hostStore\\)/', $lifecycleCode) === 1);
check('creator계약: 존재가 보장되지 않는 드라이버 이름에 기대지 않는다',
    strpos($lifecycleCode, "'session.driver' => 'null'") === false);

serverOnly('스코프 매니저가 만든 Store 가 TeeWide 쿠키 이름·타입을 갖는가');
serverOnly('TeeWide HTTP 요청이 TypeError 없이 200 을 돌려주는가');

check('creator수명: stale Store 재등장을 객체 ID 로 판정한다',
    preg_match('/assertNotSame\\(\\s*spl_object_id\\(\\$before\\)\\s*,\\s*spl_object_id\\(\\$after\\)/', $lifecycleCode) === 1);
check('creator수명: 테스트가 세션 레코드를 지워서 통과하지 않는다',
    strpos($lifecycleCode, '->flush()') === false
    && strpos($lifecycleCode, '->invalidate()') === false);

serverOnly('forgetDrivers() 후 호스트 매니저가 새 Store 를 만드는가 (creator 잔재 없음)');
serverOnly('StartSession 이 TeeWide 구간에서 전용 Store 를 받는가');
serverOnly('중첩 예외 후 스코프 깊이가 0 으로 복원되는가');
serverOnly('YUTIV 로그인 세션이 TeeWide 요청에서 인증되지 않는가 (yutiv_user_leaked=false)');
serverOnly('TeeWide 요청의 Store 객체가 YUTIV 와 실제로 다른가');
serverOnly('TeeWide 요청 뒤 YUTIV 로그인 세션 레코드가 그대로 남는가');

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
