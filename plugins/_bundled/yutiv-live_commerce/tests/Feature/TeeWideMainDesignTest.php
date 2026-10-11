<?php

namespace Plugins\Yutiv\LiveCommerce\Tests\Feature;

use Illuminate\Foundation\Application;
use Illuminate\Testing\TestResponse;
use Plugins\Yutiv\LiveCommerce\Tests\PluginTestCase;

class TeeWideMainDesignTest extends PluginTestCase
{
    private ?array $routeCacheState = null;

    public function createApplication(): Application
    {
        // 화면 테스트의 boot-time 라우트가 개발 작업공간의 기존 캐시로 교체되지 않게 합니다.
        $this->routeCacheState ??= ['env' => $_ENV['APP_ROUTES_CACHE'] ?? null, 'server' => $_SERVER['APP_ROUTES_CACHE'] ?? null, 'process' => getenv('APP_ROUTES_CACHE')];
        $path = 'storage/framework/testing/teewide-main-'.bin2hex(random_bytes(8)).'-routes.php';
        $_ENV['APP_ROUTES_CACHE'] = $_SERVER['APP_ROUTES_CACHE'] = $path;
        putenv('APP_ROUTES_CACHE='.$path);

        return parent::createApplication();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            if ($this->routeCacheState !== null) {
                foreach (['env', 'server'] as $source) {
                    $target = $source === 'env' ? '_ENV' : '_SERVER';
                    if ($this->routeCacheState[$source] === null) {
                        unset($GLOBALS[$target]['APP_ROUTES_CACHE']);
                    } else {
                        $GLOBALS[$target]['APP_ROUTES_CACHE'] = $this->routeCacheState[$source];
                    }
                }
                putenv($this->routeCacheState['process'] === false ? 'APP_ROUTES_CACHE' : 'APP_ROUTES_CACHE='.$this->routeCacheState['process']);
            }
        }
    }

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

    protected function memberResponse(): TestResponse
    {
        $this->makeTeeWideUser(['email' => 'design-fixture@teewide.test', 'name' => '합성 화면 검증 회원 이름이 매우 긴 경우']);
        $login = $this->post('http://'.self::ROOT_HOST.'/login', ['email' => 'design-fixture@teewide.test', 'password' => self::TEST_USER_PASSWORD]);
        $login->assertRedirect();
        $cookie = $login->getCookie('teewide_session', false);
        $this->assertNotNull($cookie);

        return $this->withUnencryptedCookie('teewide_session', $cookie->getValue())->get('http://'.self::ROOT_HOST.'/');
    }

    public function test_guest_main_and_empty_live_have_explicit_preparation_graphics_and_auth_actions(): void
    {
        $response = $this->get('http://'.self::ROOT_HOST.'/');
        $response->assertOk()->assertSee('라이브로 만나다.')->assertSee('실제 방송이 아닌 방송 준비 디자인')->assertSee(route('teewide.login'), false)->assertSee(route('teewide.register'), false)->assertDontSee('판매 시작하기')->assertDontSee('개인 스토어');
        $this->get('http://'.self::LIVE_HOST.'/')->assertOk()->assertSee('다음 라이브를 준비하고 있습니다')->assertSee('실제 방송이 아닌 방송 준비 디자인');
    }

    public function test_member_main_retains_account_post_logout_and_private_guard(): void
    {
        $response = $this->memberResponse();
        $response->assertOk()->assertSee(route('teewide.account'), false)->assertSee('method="POST"', false)->assertSee(route('teewide.logout'), false)->assertSee('합성 화면 검증 회원');
        $response->assertDontSee('APP_KEY')->assertDontSee('store-office')->assertDontSee('리워드');
    }
}
