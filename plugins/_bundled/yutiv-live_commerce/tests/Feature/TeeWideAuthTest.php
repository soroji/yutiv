<?php

namespace Plugins\Yutiv\LiveCommerce\Tests\Feature;

use App\Models\User as YutivUser;
use Illuminate\Support\Facades\Hash;
use Plugins\Yutiv\LiveCommerce\Models\TeeWideUser;
use Plugins\Yutiv\LiveCommerce\Support\TeeWideAuth;
use Plugins\Yutiv\LiveCommerce\Tests\PluginTestCase;

/**
 * TeeWide 전용 회원가입·로그인·마이페이지.
 *
 * ── 지켜야 할 경계 ─────────────────────────────────────────────────────────
 * TeeWide 회원은 `teewide_users` 테이블, `teewide` guard, `teewide_session` 쿠키를 쓴다.
 * YUTIV `users`/`web` guard 와는 어느 방향으로도 통하지 않는다.
 */
class TeeWideAuthTest extends PluginTestCase
{
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

        $this->assertTrue(TeeWideAuth::check(), '가입 후 TeeWide guard 로 로그인되지 않았습니다');
        $this->assertSame($user->id, TeeWideAuth::user()?->id);
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

        $response->assertSessionHasErrors('email');
        $this->assertSame(1, TeeWideUser::query()->where('email', 'taken@teewide.test')->count());
    }

    public function test_회원가입_검증이_동작한다(): void
    {
        $cases = [
            'name' => ['name' => '', 'email' => 'a@teewide.test', 'password' => 'teewide-secret-1234', 'password_confirmation' => 'teewide-secret-1234', 'terms' => '1'],
            'email' => ['name' => '김', 'email' => 'not-an-email', 'password' => 'teewide-secret-1234', 'password_confirmation' => 'teewide-secret-1234', 'terms' => '1'],
            'password' => ['name' => '김', 'email' => 'b@teewide.test', 'password' => 'short', 'password_confirmation' => 'short', 'terms' => '1'],
            'terms' => ['name' => '김', 'email' => 'd@teewide.test', 'password' => 'teewide-secret-1234', 'password_confirmation' => 'teewide-secret-1234'],
        ];

        foreach ($cases as $field => $payload) {
            $this->from($this->portal('/register'))
                ->post($this->portal('/register'), $payload)
                ->assertSessionHasErrors($field);
        }

        // 비밀번호 확인 불일치는 password 필드에 오류가 붙는다.
        $this->from($this->portal('/register'))->post($this->portal('/register'), [
            'name' => '김', 'email' => 'c@teewide.test',
            'password' => 'teewide-secret-1234', 'password_confirmation' => 'different-1234', 'terms' => '1',
        ])->assertSessionHasErrors('password');

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
        $this->assertTrue(TeeWideAuth::check());
        $this->assertNotNull($user->fresh()->last_login_at, 'last_login_at 이 갱신되지 않았습니다');
    }

    public function test_잘못된_로그인은_계정_존재_여부를_알려주지_않는다(): void
    {
        $this->makeTeeWideUser(['email' => 'real@teewide.test']);

        $wrongPassword = $this->from($this->portal('/login'))->post($this->portal('/login'), [
            'email' => 'real@teewide.test',
            'password' => 'wrong-password-1234',
        ]);

        $noSuchAccount = $this->from($this->portal('/login'))->post($this->portal('/login'), [
            'email' => 'ghost@teewide.test',
            'password' => 'wrong-password-1234',
        ]);

        $wrongPassword->assertSessionHasErrors('email');
        $noSuchAccount->assertSessionHasErrors('email');

        // 두 경우의 문구가 같아야 계정 열거가 불가능하다.
        $this->assertSame(
            $wrongPassword->getSession()->get('errors')?->first('email'),
            $noSuchAccount->getSession()->get('errors')?->first('email'),
            '계정 존재 여부에 따라 오류 문구가 달라집니다'
        );

        $this->assertFalse(TeeWideAuth::check());
    }

    public function test_정지된_계정은_로그인할_수_없다(): void
    {
        $this->makeTeeWideUser([
            'email' => 'suspended@teewide.test',
            'status' => TeeWideUser::STATUS_SUSPENDED,
        ]);

        $this->from($this->portal('/login'))->post($this->portal('/login'), [
            'email' => 'suspended@teewide.test',
            'password' => self::TEST_USER_PASSWORD,
        ])->assertSessionHasErrors('email');

        $this->assertFalse(TeeWideAuth::check(), '정지 계정이 로그인됐습니다');
    }

    public function test_로그인시_세션_ID_가_재발급된다(): void
    {
        $this->makeTeeWideUser(['email' => 'regen@teewide.test']);

        $this->get($this->portal('/login'));
        $before = session()->getId();

        $this->post($this->portal('/login'), [
            'email' => 'regen@teewide.test',
            'password' => self::TEST_USER_PASSWORD,
        ]);

        $this->assertNotSame($before, session()->getId(), '세션 고정 방어(regenerate)가 없습니다');
    }

    // ── 로그아웃 ────────────────────────────────────────────────────────────

    public function test_로그아웃은_POST_만_허용한다(): void
    {
        $this->makeTeeWideUser(['email' => 'out@teewide.test']);
        $this->post($this->portal('/login'), [
            'email' => 'out@teewide.test',
            'password' => self::TEST_USER_PASSWORD,
        ]);

        // GET 로그아웃은 링크·이미지 프리페치로 강제 실행된다 — 라우트 자체가 없어야 한다.
        $this->assertNull($this->matchedRouteName($this->portal('/logout')));
        $this->assertSame('teewide.logout', $this->matchedRouteName($this->portal('/logout'), 'POST'));

        $this->assertTrue(TeeWideAuth::check(), 'GET 확인 과정에서 로그아웃됐습니다');
    }

    public function test_로그아웃하면_세션이_무효화된다(): void
    {
        $this->makeTeeWideUser(['email' => 'bye@teewide.test']);
        $this->post($this->portal('/login'), [
            'email' => 'bye@teewide.test',
            'password' => self::TEST_USER_PASSWORD,
        ]);

        $sessionBefore = session()->getId();

        $this->post($this->portal('/logout'))->assertRedirect(route('teewide.portal'));

        $this->assertFalse(TeeWideAuth::check(), '로그아웃 후에도 로그인 상태입니다');
        $this->assertNotSame($sessionBefore, session()->getId(), '세션이 무효화되지 않았습니다');
    }

    // ── 마이페이지 ──────────────────────────────────────────────────────────

    public function test_비로그인_account_는_TeeWide_로그인으로_보낸다(): void
    {
        $response = $this->get($this->portal('/account'));

        $response->assertRedirect(route('teewide.login'));

        // YUTIV 로그인 화면으로 보내면 안 된다.
        $this->assertStringContainsString(self::ROOT_HOST, $response->headers->get('Location'));
        $this->assertStringNotContainsString(self::YUTIV_HOST, (string) $response->headers->get('Location'));
    }

    public function test_로그인_account_는_본인_정보를_보여준다(): void
    {
        $user = $this->makeTeeWideUser([
            'email' => 'me@teewide.test',
            'name' => '내이름',
        ]);

        $this->post($this->portal('/login'), [
            'email' => 'me@teewide.test',
            'password' => self::TEST_USER_PASSWORD,
        ]);

        $response = $this->get($this->portal('/account'));

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

        $this->post($this->portal('/login'), [
            'email' => 'owner@teewide.test',
            'password' => self::TEST_USER_PASSWORD,
        ]);

        $response = $this->get($this->portal('/account'));

        $response->assertOk();
        $response->assertSee('내 채널');
        $response->assertSee('/my-shop');
        $response->assertDontSee('아직 소유한 채널이 없습니다');
    }

    // ── YUTIV 와의 경계 ─────────────────────────────────────────────────────

    public function test_YUTIV_로그인은_TeeWide_회원으로_인정되지_않는다(): void
    {
        $yutiv = YutivUser::factory()->create();

        $this->actingAs($yutiv);

        $this->assertFalse(TeeWideAuth::check(), 'YUTIV 로그인이 TeeWide 회원으로 인정됐습니다');

        // /account 는 여전히 TeeWide 로그인으로 보낸다.
        $this->get($this->portal('/account'))->assertRedirect(route('teewide.login'));
    }

    public function test_TeeWide_로그인은_YUTIV_사용자로_인정되지_않는다(): void
    {
        $this->makeTeeWideUser(['email' => 'only@teewide.test']);

        $this->post($this->portal('/login'), [
            'email' => 'only@teewide.test',
            'password' => self::TEST_USER_PASSWORD,
        ]);

        $this->assertTrue(TeeWideAuth::check());

        // 기본 guard(YUTIV web)는 여전히 비로그인이어야 한다.
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
