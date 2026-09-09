<?php

namespace Plugins\Yutiv\SesMonitor\Tests\Feature;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Plugins\Yutiv\SesMonitor\Tests\PluginTestCase;

/**
 * 구독 확인 멱등 선점 계약 — **fail-closed**.
 *
 * `SubscribeURL` 은 MessageId 당 1회만 나가야 한다. 선점을 보장할 수 없는 상황
 * (캐시 장애·동시 요청)에서 그대로 호출하면 그 계약이 깨지고, 깨진 건 재시도로
 * 되돌릴 수 없다. 그래서 확신이 없으면 **호출하지 않고 500** 으로 SNS 재시도를
 * 유도한다 — 잃는 것은 지연뿐이다.
 *
 * 이 스위트는 캐시 저장소를 실제로 고장 내서(예외를 던지는 스토어) 그 계약을 검사한다.
 */
class SesSubscriptionClaimTest extends PluginTestCase
{
    private const ENDPOINT = '/webhooks/aws/ses';

    private const SUBSCRIBE_HOST = 'sns.ap-northeast-2.amazonaws.com/*';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureSes(['auto_confirm' => true]);
        $this->bindFixtureValidator();
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function postSubscriptionConfirmation(array $message): \Illuminate\Testing\TestResponse
    {
        return $this->call(
            'POST',
            self::ENDPOINT,
            [], [], [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode($message)
        );
    }

    /**
     * 캐시 저장소를 지정한 방식으로 고장 낸다.
     *
     * @param  'add'|'get'|'both'  $failing
     */
    private function breakCache(string $failing, bool $addReturnsFalse = false): void
    {
        Cache::swap(new CacheRepository(new BrokenCacheStore($failing, $addReturnsFalse)));
    }

    // ── add() = false 인 두 갈래 ────────────────────────────────────────────

    public function test_add_false_이고_기존_키가_있으면_미호출_200(): void
    {
        Http::fake([self::SUBSCRIBE_HOST => Http::response('ok', 200)]);

        $message = $this->bag->factory()->subscriptionConfirmation();

        // 첫 요청이 선점 + 호출
        $this->postSubscriptionConfirmation($message)->assertOk()->assertJsonPath('status', 'confirmed');
        Http::assertSentCount(1);

        // 두 번째는 add() 가 false 이고 get() 으로 기존 키가 확인된다 → 중복
        $this->postSubscriptionConfirmation($message)->assertOk()->assertJsonPath('status', 'already_confirmed');

        Http::assertSentCount(1);
    }

    public function test_add_false_인데_기존_키가_없으면_미호출_500(): void
    {
        Http::fake([self::SUBSCRIBE_HOST => Http::response('ok', 200)]);

        // add() 는 false, get() 은 null — "쓰기 실패" 상황
        $this->breakCache('none', addReturnsFalse: true);

        $this->postSubscriptionConfirmation($this->bag->factory()->subscriptionConfirmation())
            ->assertStatus(500)
            ->assertJsonPath('status', 'claim_unavailable');

        Http::assertNothingSent();
    }

    // ── 예외 ────────────────────────────────────────────────────────────────

    public function test_add_예외면_미호출_500(): void
    {
        Http::fake([self::SUBSCRIBE_HOST => Http::response('ok', 200)]);

        $this->breakCache('add');

        $this->postSubscriptionConfirmation($this->bag->factory()->subscriptionConfirmation())
            ->assertStatus(500)
            ->assertJsonPath('status', 'claim_unavailable');

        Http::assertNothingSent();
    }

    public function test_get_예외면_미호출_500(): void
    {
        Http::fake([self::SUBSCRIBE_HOST => Http::response('ok', 200)]);

        // add() 는 false 를 돌려주고 get() 이 던진다 — 상태 확인 불가
        $this->breakCache('get', addReturnsFalse: true);

        $this->postSubscriptionConfirmation($this->bag->factory()->subscriptionConfirmation())
            ->assertStatus(500)
            ->assertJsonPath('status', 'claim_unavailable');

        Http::assertNothingSent();
    }

    public function test_캐시가_완전히_죽어도_미호출_500(): void
    {
        Http::fake([self::SUBSCRIBE_HOST => Http::response('ok', 200)]);

        $this->breakCache('both');

        $this->postSubscriptionConfirmation($this->bag->factory()->subscriptionConfirmation())
            ->assertStatus(500);

        Http::assertNothingSent();
    }

    // ── HTTP 실패 후 재시도 ────────────────────────────────────────────────

    public function test_HTTP_실패_후_선점이_해제되어_다음_재시도에서_1회_호출된다(): void
    {
        $message = $this->bag->factory()->subscriptionConfirmation();

        Http::fake([self::SUBSCRIBE_HOST => Http::response('err', 500)]);
        $this->postSubscriptionConfirmation($message)->assertStatus(500)->assertJsonPath('status', 'confirm_failed');
        Http::assertSentCount(1);

        // 선점이 해제됐으므로 재시도가 다시 선점하고 호출한다.
        Http::fake([self::SUBSCRIBE_HOST => Http::response('ok', 200)]);
        $this->postSubscriptionConfirmation($message)->assertOk()->assertJsonPath('status', 'confirmed');
        Http::assertSentCount(1);
    }

    public function test_성공_후_재전송은_추가_호출_없이_200(): void
    {
        Http::fake([self::SUBSCRIBE_HOST => Http::response('ok', 200)]);

        $message = $this->bag->factory()->subscriptionConfirmation();

        $this->postSubscriptionConfirmation($message)->assertOk()->assertJsonPath('status', 'confirmed');
        $this->postSubscriptionConfirmation($message)->assertOk()->assertJsonPath('status', 'already_confirmed');
        $this->postSubscriptionConfirmation($message)->assertOk()->assertJsonPath('status', 'already_confirmed');

        Http::assertSentCount(1);
    }

    public function test_선점_TTL_은_유한하다(): void
    {
        Http::fake([self::SUBSCRIBE_HOST => Http::response('ok', 200)]);

        $message = $this->bag->factory()->subscriptionConfirmation();
        $this->postSubscriptionConfirmation($message)->assertOk();

        $key = 'yutiv-ses-monitor:sns-confirm:'.sha1($message['MessageId']);

        $this->assertNotNull(Cache::get($key), '선점 키가 없습니다');

        // TTL 이 지나면 사라져야 한다 — 영구 키면 재확정이 영영 불가능해진다.
        $this->travel(3601)->seconds();

        $this->assertNull(Cache::get($key), '선점 키에 유한 TTL 이 없습니다');
    }

    // ── 동시 요청 ───────────────────────────────────────────────────────────

    public function test_같은_MessageId_가_동시에_들어와도_호출은_최대_1회(): void
    {
        Http::fake([self::SUBSCRIBE_HOST => Http::response('ok', 200)]);

        $message = $this->bag->factory()->subscriptionConfirmation();

        // 동시 도착을 순차 요청으로 근사한다. add() 가 원자적이므로 첫 요청만 선점하고
        // 나머지는 중복으로 떨어진다 — 어느 경우에도 호출은 누적되지 않는다.
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postSubscriptionConfirmation($message);
            $this->assertContains($response->getStatusCode(), [200, 500]);
        }

        Http::assertSentCount(1);
    }

    // ── 로깅 위생 ───────────────────────────────────────────────────────────

    public function test_선점_실패_로그에_비밀값과_개인정보가_없다(): void
    {
        Http::fake([self::SUBSCRIBE_HOST => Http::response('ok', 200)]);
        $this->breakCache('add');

        $message = $this->bag->factory()->subscriptionConfirmation();

        $captured = [];
        Log::listen(function ($log) use (&$captured) {
            $captured[] = $log;
        });

        $this->postSubscriptionConfirmation($message)->assertStatus(500);

        $messages = array_column($captured, 'message');
        $this->assertContains('ses_monitor.subscription_claim_unavailable', $messages);

        $entry = collect($captured)->firstWhere('message', 'ses_monitor.subscription_claim_unavailable');
        $this->assertFalse($entry->context['subscribe_url_called']);

        $dump = json_encode(array_map(
            fn ($l) => ['message' => $l->message, 'context' => $l->context],
            $captured
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertStringNotContainsString($message['Token'], $dump, 'Token 이 로그에 남았습니다');
        $this->assertStringNotContainsString($message['SubscribeURL'], $dump, 'SubscribeURL 이 로그에 남았습니다');
        $this->assertStringNotContainsString($message['Signature'], $dump, 'Signature 가 로그에 남았습니다');
        $this->assertStringNotContainsString($message['MessageId'], $dump, '전체 MessageId 가 로그에 남았습니다');
        $this->assertStringNotContainsString('ConfirmSubscription', $dump, 'SubscribeURL 조각이 로그에 남았습니다');
    }

    // ── 문서 계약 ───────────────────────────────────────────────────────────

    public function test_저장소에_fail_open_문구가_남아_있지_않다(): void
    {
        $root = base_path('plugins/_bundled/yutiv-ses_monitor');

        $targets = [
            $root.'/README.md',
            $root.'/CHANGELOG.md',
            $root.'/src/Support/SubscriptionConfirmer.php',
            $root.'/src/Http/Controllers/SesWebhookController.php',
        ];

        foreach ($targets as $file) {
            $contents = (string) file_get_contents($file);

            foreach (['멱등 보호 없이', '보호 없이 진행', '중복 호출 방지 없이'] as $phrase) {
                $this->assertStringNotContainsString($phrase, $contents, basename($file).' — '.$phrase);
            }
        }

        $readme = (string) file_get_contents($root.'/README.md');
        $this->assertStringContainsString('fail-closed', $readme);
    }
}

/**
 * 지정한 방식으로 실패하는 캐시 저장소 (테스트 전용).
 *
 * 캐시 장애를 모킹이 아니라 **실제 예외/반환값**으로 재현한다 — 모킹으로 결과만
 * 바꾸면 우리가 검사하려는 분기(add false vs 예외)가 실제로 도는지 알 수 없다.
 */
class BrokenCacheStore extends ArrayStore
{
    /**
     * @param  string  $failing  'add' | 'get' | 'both' | 'none'
     * @param  bool  $addReturnsFalse  add() 를 예외 없이 false 로 만들지 여부
     */
    public function __construct(
        private readonly string $failing = 'none',
        private readonly bool $addReturnsFalse = false,
    ) {
        parent::__construct();
    }

    /**
     * `add()` 는 `Store` 인터페이스가 아니라 선택 구현이다 — `ArrayStore` 에 있을 수도,
     * 없을 수도 있다(`Repository::add()` 가 `method_exists` 로 확인한다). 그래서
     * `parent::add()` 를 부르지 않고 `get`/`put` 으로 동등한 의미를 직접 구현한다.
     * 부모에 없는 메서드를 호출해 테스트가 프레임워크 버전에 묶이지 않게 한다.
     */
    public function add($key, $value, $seconds)
    {
        if ($this->failing === 'add' || $this->failing === 'both') {
            throw new \RuntimeException('cache store unavailable');
        }

        if ($this->addReturnsFalse) {
            return false;
        }

        // 우리 get() 오버라이드를 거치지 않도록 부모 구현으로 확인한다 —
        // 'get' 고장 모드에서 add() 까지 덩달아 던지면 분기를 갈라 볼 수 없다.
        if (parent::get($key) !== null) {
            return false;
        }

        $this->put($key, $value, $seconds);

        return true;
    }

    public function get($key)
    {
        if ($this->failing === 'get' || $this->failing === 'both') {
            throw new \RuntimeException('cache store unavailable');
        }

        return parent::get($key);
    }
}
