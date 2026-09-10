<?php

namespace Plugins\Yutiv\LiveCommerce\Tests\Feature;

use App\Models\User as YutivUser;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Yutiv\LiveCommerce\Models\TeeWideUser;
use Plugins\Yutiv\LiveCommerce\Tests\PluginTestCase;

/**
 * TeeWide 전용 회원가입·로그인·마이페이지.
 *
 * ── 왜 요청 밖에서 로그인 상태를 확인하지 않는가 ───────────────────────────
 * `ConfigureTeeWideSession` 은 TeeWide 요청 **동안에만** 전용 `session.store` 와
 * `teewide` guard 를 설치하고, 응답 쿠키 발급·세션 저장이 끝난 뒤 `finally` 에서
 * YUTIV Store 와 guard 를 되돌린다. 그게 이 플러그인의 핵심 보안 계약이다.
 *
 * 따라서 HTTP 요청이 끝난 뒤에 아래를 보면 **TeeWide 상태가 아니라 복원된 YUTIV 상태**를
 * 본다 — 테스트가 관찰하는 대상 자체가 틀린다.
 *
 *   TeeWideAuth::check() / TeeWideAuth::user()
 *   session()->getId()
 *   TestResponse::assertSessionHasErrors() / getSession()
 *
 * 그래서 이 스위트는 브라우저가 하는 것과 똑같이 한다 — 응답에서 `teewide_session`
 * 쿠키를 꺼내 다음 요청에 명시적으로 실어 보내고, **그 응답으로** 판정한다.
 * (`TeeWideSessionIsolationTest` · `TeeWideSessionBoundaryTest` 와 같은 방식)
 */
class TeeWideAuthTest extends PluginTestCase
{
    /** TeeWide 전용 세션 쿠키 이름 — YUTIV 의 것과 다르다. */
    private const SESSION_COOKIE = 'teewide_session';

    /** 로그인 실패 시 언제나 같은 문구 (계정 열거 차단). */
    private const GENERIC_FAILURE = '이메일 또는 비밀번호가 올바르지 않습니다.';

    protected function teeWideBootConfig(): ?array
    {
        return static::teeWideConfigValues();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureTeeWide();
        $this->bootPlugin();
        $this->attachHostGate();
    }

    private function portal(string $path): string
    {
        return 'http://'.self::ROOT_HOST.$path;
    }

    /**
     * 응답이 발급한 TeeWide 세션 쿠키 값.
     *
     * 암호화되지 않은 원본을 읽는다(`getCookie($name, false)`) — 다음 요청에
     * `withUnencryptedCookie()` 로 그대로 돌려주기 위해서다.
     */
    private function teeWideSessionCookie(TestResponse $response): string
    {
        $cookie = $response->getCookie(self::SESSION_COOKIE, false);

        $this->assertNotNull(
            $cookie,
            'TeeWide 세션 쿠키('.self::SESSION_COOKIE.')가 응답에 없습니다. '
            .'상태 '.$response->getStatusCode().' — 세션 미들웨어가 붙지 않았거나 요청이 TeeWide 라우트에 닿지 않았습니다.'
        );

        return (string) $cookie->getValue();
    }

    /**
     * 주어진 세션으로 다음 TeeWide 요청을 보낸다.
     *
     * 전역 app 세션이나 기본 쿠키 자동 전달에 기대지 않는다 — 그것들은 요청이 끝나면
     * YUTIV 상태로 복원되므로 TeeWide 세션을 이어 주지 못한다.
     */
    private function withTeeWideSession(string $sessionId): self
    {
        return $this->withUnencryptedCookie(self::SESSION_COOKIE, $sessionId);
    }

    /**
     * 회원을 만들고 로그인해, 그 결과 세션 쿠키를 돌려준다.
     */
    private function loginAs(string $email): string
    {
        $response = $this->post($this->portal('/login'), [
            'email' => $email,
            'password' => self::TEST_USER_PASSWORD,
        ]);

        return $this->teeWideSessionCookie($response);
    }

    // ── 화면 ────────────────────────────────────────────────────────────────

    public function test_회원가입과_로그인_화면이_HTML_200_이다(): void
    {
        foreach (['/register' => 'teewide.register', '/login' => 'teewide.login'] as $path => $name) {
            $url = $this->portal($path);

            $this->assertSame($name, $this->matchedRouteName($url), $this->routingDiagnostics($url));

            $response = $this->get($url);
            $response->assertOk();
            $response->assertHeader('content-type', 'text/html; charset=UTF-8');
            $response->assertSee('<form', false);
            $response->assertSee('_token', false);
        }
    }

    public function test_비밀번호_입력에_autocomplete_가_지정된다(): void
    {
        $this->get($this->portal('/login'))->assertSee('autocomplete="current-password"', false);

        $register = $this->get($this->portal('/register'));
        $register->assertSee('autocomplete="new-password"', false);
        $register->assertSee('autocomplete="email"', false);
        $register->assertSee('autocomplete="name"', false);
    }

    // ── 회원가입 ────────────────────────────────────────────────────────────

    public function test_정상_회원가입은_계정을_만들고_로그인시킨다(): void
    {
        $response = $this->post($this->portal('/register'), [
            'name' => '김테스트',
            'email' => '  NewMember@TeeWide.TEST ',
            'password' => 'teewide-secret-1234',
            'password_confirmation' => 'teewide-secret-1234',
            'terms' => '1',
        ]);

        $response->assertRedirect(route('teewide.account'));

        $user = TeeWideUser::query()->where('email', 'newmember@teewide.test')->first();

        $this->assertNotNull($user, '이메일이 소문자·trim 으로 정규화돼 저장되지 않았습니다');
        $this->assertSame('김테스트', $user->name);
        $this->assertSame(TeeWideUser::STATUS_ACTIVE, $user->status);
        $this->assertNotNull($user->uuid, '외부 노출용 UUID 가 없습니다');

        // "가입 후 로그인" 은 다음 요청이 실제로 통과하는지로 증명한다.
        $account = $this->withTeeWideSession($this->teeWideSessionCookie($response))
            ->get($this->portal('/account'));

        $account->assertOk();
        $account->assertSee('김테스트');
        $account->assertSee('newmember@teewide.test');
    }

    public function test_비밀번호는_평문으로_저장되지_않는다(): void
    {
        $this->post($this->portal('/register'), [
            'name' => '김테스트',
            'email' => 'hash@teewide.test',
            'password' => 'teewide-secret-1234',
            'password_confirmation' => 'teewide-secret-1234',
            'terms' => '1',
        ]);

        $stored = TeeWideUser::query()->where('email', 'hash@teewide.test')->value('password');

        $this->assertNotSame('teewide-secret-1234', $stored, '평문 비밀번호가 저장됐습니다');
        $this->assertTrue(Hash::check('teewide-secret-1234', $stored), '해시가 원본과 맞지 않습니다');
    }

    public function test_중복_이메일은_가입되지_않는다(): void
    {
        $this->makeTeeWideUser(['email' => 'taken@teewide.test']);

        $response = $this->from($this->portal('/register'))->post($this->portal('/register'), [
            'name' => '김테스트',
            'email' => 'TAKEN@teewide.test',
            'password' => 'teewide-secret-1234',
            'password_confirmation' => 'teewide-secret-1234',
            'terms' => '1',
        ]);

        $response->assertRedirect($this->portal('/register'));

        // 오류는 세션에 flash 되므로, 같은 세션으로 돌아가 **렌더된 화면**에서 확인한다.
        // 문구가 아니라 오류 전용 요소로 판정한다 — 이 span 은 해당 필드에 오류가
        // 있을 때만 렌더되므로, 번역이 바뀌어도 계약은 그대로다.
        $this->withTeeWideSession($this->teeWideSessionCookie($response))
            ->get($this->portal('/register'))
            ->assertOk()
            ->assertSee('id="tw-email-error"', false);

        $this->assertSame(1, TeeWideUser::query()->where('email', 'taken@teewide.test')->count());
    }

    /**
     * 회원가입 검증 사례.
     *
     * ── 왜 dataset 으로 나누는가 ────────────────────────────────────────
     * 한 메서드 안에서 POST→redirect→GET 을 여러 번 돌리면 Laravel HTTP 테스트의
     * `withUnencryptedCookie` 상태가 다음 반복으로 **누적**된다. 앞 사례가 남긴 쿠키가
     * 뒤 사례의 요청에 섞여, 두 번째 반복부터 flash 된 오류를 엉뚱한 세션에서 찾는다.
     * dataset 으로 나누면 사례마다 setUp 부터 새로 돌아 쿠키도 Application 도 격리된다.
     *
     * @return array<string, array{0: array<string, string>, 1: string, 2: string|null}>
     */
    public static function registerValidationCases(): array
    {
        $valid = [
            'name' => '김테스트',
            'email' => 'case@teewide.test',
            'password' => 'teewide-secret-1234',
            'password_confirmation' => 'teewide-secret-1234',
            'terms' => '1',
        ];

        return [
            '이름 누락' => [
                array_merge($valid, ['name' => '']),
                'id="tw-name-error"',
                null,
            ],
            '이메일 형식 오류' => [
                array_merge($valid, ['email' => 'not-an-email']),
                'id="tw-email-error"',
                null,
            ],
            '비밀번호 길이 부족' => [
                array_merge($valid, ['password' => 'short', 'password_confirmation' => 'short']),
                'id="tw-password-error"',
                null,
            ],
            '비밀번호 확인 불일치' => [
                array_merge($valid, ['password_confirmation' => 'different-1234']),
                'id="tw-password-error"',
                null,
            ],
            '약관 미동의' => [
                // 체크박스는 미동의 시 아예 전송되지 않는다.
                array_diff_key($valid, ['terms' => null]),
                'id="tw-terms-error"',
                // 이 문구는 플러그인이 직접 정한 것이라 문구 자체를 고정한다.
                '이용약관에 동의해야 가입할 수 있습니다.',
            ],
        ];
    }

    /**
     * @param  array<string, string>  $payload
     */
    #[DataProvider('registerValidationCases')]
    public function test_회원가입_검증이_동작한다(array $payload, string $errorMarker, ?string $expectedMessage): void
    {
        $response = $this->from($this->portal('/register'))
            ->post($this->portal('/register'), $payload);

        $this->assertTrue(
            $response->isRedirect($this->portal('/register')),
            '검증 실패인데 되돌아가지 않았습니다 (상태 '.$response->getStatusCode().')'
        );

        // 오류는 세션에 flash 되므로, 같은 세션으로 돌아가 **렌더된 화면**에서 확인한다.
        $page = $this->withTeeWideSession($this->teeWideSessionCookie($response))
            ->get($this->portal('/register'));

        $page->assertOk();
        $page->assertSee('입력한 내용을 다시 확인해 주세요.');

        $this->assertStringContainsString(
            $errorMarker,
            (string) $page->getContent(),
            '해당 필드의 오류 표시가 화면에 없습니다'
        );

        if ($expectedMessage !== null) {
            $page->assertSee($expectedMessage);
        }

        $this->assertSame(0, TeeWideUser::query()->count(), '검증 실패인데 계정이 만들어졌습니다');
    }

    // ── 로그인 ──────────────────────────────────────────────────────────────

    public function test_정상_로그인과_last_login_at_갱신(): void
    {
        $user = $this->makeTeeWideUser(['email' => 'login@teewide.test']);
        $this->assertNull($user->last_login_at);

        $response = $this->post($this->portal('/login'), [
            'email' => ' LOGIN@teewide.test ',
            'password' => self::TEST_USER_PASSWORD,
        ]);

        $response->assertRedirect(route('teewide.account'));
        $this->assertNotNull($user->fresh()->last_login_at, 'last_login_at 이 갱신되지 않았습니다');

        // 로그인 성립은 다음 요청이 실제로 통과하는지로 증명한다.
        $this->withTeeWideSession($this->teeWideSessionCookie($response))
            ->get($this->portal('/account'))
            ->assertOk();
    }

    /**
     * 로그인 실패가 계정 존재 여부를 드러내지 않는지 확인한다.
     *
     * ── 왜 두 사례를 한 메서드에 넣지 않는가 ────────────────────────────
     * 한 메서드에서 POST→redirect→GET 을 두 번 돌리면 `withUnencryptedCookie` 상태가
     * 다음 사례로 누적돼, 두 번째 사례가 앞 사례의 쿠키를 함께 보낸다. 그래서
     * "비밀번호 틀림" 은 통과하고 "없는 계정" 만 실패했다. 사례마다 setUp 부터 새로
     * 돌도록 테스트를 나눈다.
     *
     * "두 결과가 같다" 는 계약은 두 테스트가 **같은 상수**(GENERIC_FAILURE)를 단언하고,
     * 양쪽 모두 계정 노출 표현이 없음을 확인하는 것으로 유지된다.
     */
    private function assertLoginFailureIsGeneric(string $email): void
    {
        $response = $this->from($this->portal('/login'))->post($this->portal('/login'), [
            'email' => $email,
            'password' => 'wrong-password-1234',
        ]);

        $this->assertTrue(
            $response->isRedirect($this->portal('/login')),
            '로그인이 성립했습니다 (상태 '.$response->getStatusCode().')'
        );

        $page = $this->withTeeWideSession($this->teeWideSessionCookie($response))
            ->get($this->portal('/login'));

        $page->assertOk();

        $html = (string) $page->getContent();

        $this->assertStringContainsString(self::GENERIC_FAILURE, $html, '일반 오류 문구가 없습니다');

        foreach (['존재하지 않', '등록되지 않', '없는 계정', '가입되지 않', '비밀번호가 틀'] as $leak) {
            $this->assertStringNotContainsString(
                $leak,
                $html,
                '계정 존재 여부를 드러내는 문구가 있습니다: '.$leak
            );
        }
    }

    public function test_비밀번호가_틀리면_일반_오류만_보여준다(): void
    {
        // 이 사례에서만 계정을 만든다 — 다른 사례가 이 fixture 에 기대지 않게 한다.
        $this->makeTeeWideUser(['email' => 'real@teewide.test']);

        $this->assertLoginFailureIsGeneric('real@teewide.test');
    }

    public function test_없는_계정도_같은_일반_오류만_보여준다(): void
    {
        // 계정을 만들지 않는다. 위 테스트와 **같은 문구**가 나와야 계정 열거가 불가능하다.
        $this->assertLoginFailureIsGeneric('ghost@teewide.test');
    }

    public function test_정지된_계정은_로그인할_수_없다(): void
    {
        $this->makeTeeWideUser([
            'email' => 'suspended@teewide.test',
            'status' => TeeWideUser::STATUS_SUSPENDED,
        ]);

        $response = $this->from($this->portal('/login'))->post($this->portal('/login'), [
            'email' => 'suspended@teewide.test',
            'password' => self::TEST_USER_PASSWORD,
        ]);

        $this->assertTrue(
            $response->isRedirect($this->portal('/login')),
            '정지 계정이 로그인됐습니다 (상태 '.$response->getStatusCode().')'
        );

        $sessionId = $this->teeWideSessionCookie($response);

        // 정지 사실을 따로 알려 주지 않는다 — 일반 오류 문구 하나뿐이다.
        $page = $this->withTeeWideSession($sessionId)->get($this->portal('/login'));
        $page->assertOk();
        $page->assertSee(self::GENERIC_FAILURE, false);
        $page->assertDontSee('정지');

        // 같은 세션으로도 보호 화면에 들어갈 수 없다.
        $this->withTeeWideSession($sessionId)
            ->get($this->portal('/account'))
            ->assertRedirect(route('teewide.login'));
    }

    public function test_로그인시_세션_ID_가_재발급된다(): void
    {
        $this->makeTeeWideUser(['email' => 'regen@teewide.test']);

        // 로그인 전 세션을 실제로 하나 만든다.
        $before = $this->teeWideSessionCookie($this->get($this->portal('/login')));

        // 그 세션을 그대로 들고 로그인한다 — 세션 고정 공격의 형태다.
        $response = $this->withTeeWideSession($before)->post($this->portal('/login'), [
            'email' => 'regen@teewide.test',
            'password' => self::TEST_USER_PASSWORD,
        ]);

        $after = $this->teeWideSessionCookie($response);

        $this->assertNotSame($before, $after, '로그인 후에도 같은 세션 ID 입니다 — 세션 고정 방어가 없습니다');

        // 새 세션은 정상 동작해야 한다.
        $this->withTeeWideSession($after)->get($this->portal('/account'))->assertOk();
    }

    // ── 로그아웃 ────────────────────────────────────────────────────────────

    public function test_로그아웃은_POST_만_허용한다(): void
    {
        $this->makeTeeWideUser(['email' => 'out@teewide.test']);
        $sessionId = $this->loginAs('out@teewide.test');

        // GET 로그아웃은 링크·이미지 프리페치로 강제 실행된다 — 라우트 자체가 없어야 한다.
        $this->assertNull($this->matchedRouteName($this->portal('/logout')));
        $this->assertSame('teewide.logout', $this->matchedRouteName($this->portal('/logout'), 'POST'));

        // GET 을 확인하는 과정에서 로그아웃되지 않았는지 실제 요청으로 본다.
        $this->withTeeWideSession($sessionId)->get($this->portal('/account'))->assertOk();
    }

    public function test_로그아웃하면_세션이_무효화된다(): void
    {
        $this->makeTeeWideUser(['email' => 'bye@teewide.test']);
        $before = $this->loginAs('bye@teewide.test');

        $logout = $this->withTeeWideSession($before)->post($this->portal('/logout'));

        $logout->assertRedirect(route('teewide.portal'));

        $after = $this->teeWideSessionCookie($logout);

        $this->assertNotSame($before, $after, '로그아웃 후에도 같은 세션 ID 입니다 — 세션이 무효화되지 않았습니다');

        // 새 세션은 물론, 옛 세션으로도 보호 화면에 들어갈 수 없어야 한다.
        $this->withTeeWideSession($after)
            ->get($this->portal('/account'))
            ->assertRedirect(route('teewide.login'));

        $stale = $this->withTeeWideSession($before)->get($this->portal('/account'));
        $this->assertTrue(
            $stale->isRedirect(route('teewide.login')),
            '로그아웃 전 세션이 여전히 유효합니다 (상태 '.$stale->getStatusCode().')'
        );
    }

    // ── 마이페이지 ──────────────────────────────────────────────────────────

    public function test_비로그인_account_는_TeeWide_로그인으로_보낸다(): void
    {
        $response = $this->get($this->portal('/account'));

        $response->assertRedirect(route('teewide.login'));

        // YUTIV 로그인 화면으로 보내면 안 된다.
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString(self::ROOT_HOST, $location);
        $this->assertStringNotContainsString(self::YUTIV_HOST, $location);
    }

    public function test_로그인_account_는_본인_정보를_보여준다(): void
    {
        $user = $this->makeTeeWideUser([
            'email' => 'me@teewide.test',
            'name' => '내이름',
        ]);

        $response = $this->withTeeWideSession($this->loginAs('me@teewide.test'))
            ->get($this->portal('/account'));

        $response->assertOk();
        $response->assertSee('내이름');
        $response->assertSee('me@teewide.test');
        $response->assertSee('미인증');
        $response->assertSee('아직 소유한 채널이 없습니다');

        // 비밀번호 해시가 화면에 새어 나오면 안 된다.
        $response->assertDontSee($user->fresh()->password, false);
    }

    public function test_소유한_채널이_있으면_마이페이지에_표시된다(): void
    {
        $user = $this->makeTeeWideUser(['email' => 'owner@teewide.test']);
        $this->seedLiveTenant(['slug' => 'my-shop', 'name' => '내 채널', 'owner_user_id' => $user->id]);

        $response = $this->withTeeWideSession($this->loginAs('owner@teewide.test'))
            ->get($this->portal('/account'));

        $response->assertOk();
        $response->assertSee('내 채널');
        $response->assertSee('/my-shop');
        $response->assertDontSee('아직 소유한 채널이 없습니다');
    }

    // ── YUTIV 와의 경계 ─────────────────────────────────────────────────────

    public function test_YUTIV_로그인_세션_쿠키로는_TeeWide_보호화면에_들어갈_수_없다(): void
    {
        $yutiv = YutivUser::factory()->create();

        // ⚠ actingAs() 를 쓰지 않는다. 그건 세션이 아니라 guard 객체에 사용자를 직접
        //   꽂으므로 쿠키 격리를 전혀 증명하지 못하고, `ConfigureTeeWideSession` 이
        //   요청 진입 시 forgetGuards() 를 부르는 순간 그 주입은 사라진다.
        //   진짜 YUTIV 로그인 세션을 만들어 그 쿠키를 실어 보낸다.
        $yutivSessionId = $this->makeYutivLoginSession($yutiv);

        $response = $this->withUnencryptedCookie($this->yutivSessionCookieName(), $yutivSessionId)
            ->get($this->portal('/account'));

        $response->assertRedirect(route('teewide.login'));

        // YUTIV 로그인 화면으로 보내지도 않는다.
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString(self::ROOT_HOST, $location);
        $this->assertStringNotContainsString(self::YUTIV_HOST, $location);
    }

    public function test_YUTIV_로그인_세션_쿠키는_TeeWide_진단에서도_사용자로_보이지_않는다(): void
    {
        $yutiv = YutivUser::factory()->create();
        $yutivSessionId = $this->makeYutivLoginSession($yutiv);

        // 같은 실제 쿠키를 TeeWide 진단에 보낸다. TeeWide 는 쿠키 이름이 달라
        // 이 세션을 아예 쳐다보지 않는다.
        $this->withUnencryptedCookie($this->yutivSessionCookieName(), $yutivSessionId)
            ->get($this->portal('/_teewide/session'))
            ->assertOk()
            ->assertJsonPath('yutiv_user_leaked', false)
            ->assertJsonPath('session_cookie', self::SESSION_COOKIE);
    }

    public function test_TeeWide_로그인은_YUTIV_사용자로_인정되지_않는다(): void
    {
        $this->makeTeeWideUser(['email' => 'only@teewide.test']);

        $sessionId = $this->loginAs('only@teewide.test');

        // TeeWide 쪽은 통과하고,
        $this->withTeeWideSession($sessionId)->get($this->portal('/account'))->assertOk();

        // 기본 guard(YUTIV web)는 여전히 비로그인이어야 한다.
        // (요청이 끝나면 미들웨어가 YUTIV guard 를 복원하므로 여기서 볼 수 있다)
        $this->assertFalse(auth()->guard('web')->check(), 'TeeWide 로그인이 YUTIV 로그인으로 새어 나갔습니다');
        $this->assertNull(auth()->guard('web')->user());
    }

    public function test_guard_와_provider_가_YUTIV_설정을_덮어쓰지_않는다(): void
    {
        // TeeWide guard 는 추가되고,
        $this->assertSame('session', config('auth.guards.teewide.driver'));
        $this->assertSame('teewide_users', config('auth.guards.teewide.provider'));
        $this->assertSame(TeeWideUser::class, config('auth.providers.teewide_users.model'));

        // YUTIV guard/provider 는 그대로여야 한다.
        $this->assertSame('session', config('auth.guards.web.driver'));
        $this->assertSame('users', config('auth.guards.web.provider'));
        $this->assertNotSame(TeeWideUser::class, config('auth.providers.users.model'));
    }

    public function test_인증_라우트는_라이브_호스트에_없다(): void
    {
        foreach (['/login', '/register', '/account'] as $path) {
            $name = $this->matchedRouteName('http://'.self::LIVE_HOST.$path);

            $this->assertTrue(
                $name === null || ! in_array($name, ['teewide.login', 'teewide.register', 'teewide.account'], true),
                self::LIVE_HOST.$path.' 이 인증 라우트에 매칭됐습니다: '.var_export($name, true)
            );
        }
    }
}
