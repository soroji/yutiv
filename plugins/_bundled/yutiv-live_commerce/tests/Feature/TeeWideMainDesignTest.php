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

    public function test_guest_main_uses_one_golf_hero_and_preserves_live_and_auth_actions(): void
    {
        $response = $this->get('http://'.self::ROOT_HOST.'/');
        $response->assertOk()->assertSee('방송으로 만나는 상품,')->assertSee('더 가까이 즐기는 라이브.')->assertSee('라이브 보러가기')->assertSee(route('teewide.login'), false)->assertSee(route('teewide.register'), false)->assertDontSee('판매 시작하기')->assertDontSee('개인 스토어')->assertDontSee('tw-stage')->assertDontSee('COMING NEXT')->assertDontSee('서비스 준비 중입니다.');
        $this->assertSame(1, substr_count($response->getContent(), '<section class="tw-golf-hero"'));
        $this->get('http://'.self::LIVE_HOST.'/')->assertOk()->assertSee('다음 라이브를 준비하고 있습니다')->assertSee('실제 방송이 아닌 방송 준비 디자인');
    }

    public function test_member_main_retains_account_post_logout_and_private_guard(): void
    {
        $response = $this->memberResponse();
        $response->assertOk()->assertSee(route('teewide.account'), false)->assertSee('method="POST"', false)->assertSee(route('teewide.logout'), false)->assertDontSee('회원가입')->assertDontSee(route('teewide.register'), false);
        $response->assertDontSee('APP_KEY')->assertDontSee('store-office')->assertDontSee('리워드');
    }

    public function test_background_bytes_are_the_original_without_remote_hotlinks(): void
    {
        $body = $this->get('http://'.self::ROOT_HOST.'/')->assertOk()->getContent();
        $this->assertSame(1, preg_match_all('/data:image\/png;base64,([A-Za-z0-9+\/=]+)/', $body, $matches));
        $this->assertSame('7a8c7751ce6dc97305e387a34afc73b554c7783fbced3dce1ba74959624bc5c7', hash('sha256', base64_decode($matches[1][0], true)));
        $this->assertStringNotContainsString('https://golfif.store', $body);
    }
}
