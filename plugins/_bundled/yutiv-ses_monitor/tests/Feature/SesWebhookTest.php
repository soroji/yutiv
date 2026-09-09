<?php

namespace Plugins\Yutiv\SesMonitor\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Plugins\Yutiv\SesMonitor\Models\SesEventLog;
use Plugins\Yutiv\SesMonitor\Tests\PluginTestCase;
use Plugins\Yutiv\SesMonitor\Tests\Support\SnsFixtureFactory;

/**
 * `POST /webhooks/aws/ses` 계약 테스트.
 *
 * 서명은 실제 RSA 키로 만들고 실제 openssl 로 검증한다 — 모킹하지 않는다.
 * 네트워크는 쓰지 않는다 (인증서 fetcher 를 fixture 로 주입).
 */
class SesWebhookTest extends PluginTestCase
{
    private const ENDPOINT = '/webhooks/aws/ses';

    /** SNS 확인 URL 페이크 패턴. */
    private const SUBSCRIBE_HOST_PATTERN = 'sns.ap-northeast-2.amazonaws.com/*';

    /**
     * @param  array<string, mixed>|string  $payload
     */
    private function postSnsPayload(array|string $payload): \Illuminate\Testing\TestResponse
    {
        $body = is_string($payload) ? $payload : (string) json_encode($payload);

        return $this->call(
            'POST',
            self::ENDPOINT,
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_AMZ_SNS_MESSAGE_TYPE' => 'Notification'],
            $body
        );
    }

    // ── 서명 검증 ───────────────────────────────────────────────────────────

    public function test_정상_서명된_notification_을_저장한다(): void
    {
        $event = SnsFixtureFactory::bounceEvent('ses-msg-1');

        $this->postSnsPayload($this->bag->factory()->notification($event))
            ->assertOk()
            ->assertJsonPath('status', 'stored');

        $this->assertDatabaseHas('ses_event_logs', [
            'event_type' => 'bounce',
            'ses_message_id' => 'ses-msg-1',
            'bounce_type' => 'Permanent',
            'bounce_subtype' => 'General',
        ]);
    }

    public function test_signature_version_2_도_통과한다(): void
    {
        $this->postSnsPayload($this->bag->factory()->notification(SnsFixtureFactory::deliveryEvent('v2-msg'), [], '2'))
            ->assertOk()
            ->assertJsonPath('status', 'stored');

        $this->assertDatabaseHas('ses_event_logs', ['ses_message_id' => 'v2-msg', 'event_type' => 'delivery']);
    }

    public function test_변조된_메시지를_거부하고_저장하지_않는다(): void
    {
        // 서명 후 본문 교체 — 서명은 유효한 키로 만들어졌지만 내용이 다르다.
        $tampered = $this->bag->factory()->notification(
            SnsFixtureFactory::bounceEvent(),
            ['Message' => (string) json_encode(SnsFixtureFactory::deliveryEvent('injected'))]
        );

        $this->postSnsPayload($tampered)
            ->assertForbidden()
            ->assertJsonPath('reason', 'signature_invalid');

        $this->assertSame(0, SesEventLog::count());
    }

    public function test_서명이_없으면_거부한다(): void
    {
        $message = $this->bag->factory()->notification(SnsFixtureFactory::bounceEvent());
        unset($message['Signature']);

        $this->postSnsPayload($message)->assertStatus(400)->assertJsonPath('reason', 'malformed');
        $this->assertSame(0, SesEventLog::count());
    }

    public function test_허용되지_않은_인증서_URL_을_거부한다(): void
    {
        $message = $this->bag->factory()->notification(
            SnsFixtureFactory::bounceEvent(),
            ['SigningCertURL' => 'https://evil.example.com/cert.pem']
        );

        $this->postSnsPayload($message)
            ->assertForbidden()
            ->assertJsonPath('reason', 'cert_url_rejected');

        $this->assertSame(0, SesEventLog::count());
    }

    public function test_http_인증서_URL_을_거부한다(): void
    {
        $message = $this->bag->factory()->notification(
            SnsFixtureFactory::bounceEvent(),
            ['SigningCertURL' => 'http://sns.ap-northeast-2.amazonaws.com/cert.pem']
        );

        $this->postSnsPayload($message)->assertForbidden()->assertJsonPath('reason', 'cert_url_rejected');
    }

    // ── 출처 검증 ───────────────────────────────────────────────────────────

    public function test_topic_arn_이_다르면_거부한다(): void
    {
        $this->configureSes(['topic_arn' => 'arn:aws:sns:ap-northeast-2:123456789012:someone-else']);
        $this->bindFixtureValidator();

        $this->postSnsPayload($this->bag->factory()->notification(SnsFixtureFactory::bounceEvent()))
            ->assertForbidden()
            ->assertJsonPath('reason', 'topic_mismatch');

        $this->assertSame(0, SesEventLog::count());
    }

    public function test_region_이_다르면_거부한다(): void
    {
        $this->configureSes(['region' => 'us-east-1']);
        $this->bindFixtureValidator();

        $this->postSnsPayload($this->bag->factory()->notification(SnsFixtureFactory::bounceEvent()))
            ->assertForbidden()
            ->assertJsonPath('reason', 'region_mismatch');
    }

    /**
     * 만료된 **제어 메시지**는 거부한다.
     *
     * 이 테스트는 원래 "오래된 Notification 거부" 였다. 그 정책은 폐기됐다 —
     * 장애·배포로 밀린 정상 SES 재시도를 영구 유실시키기 때문이다(SesTimestampPolicyTest
     * 가 "오래된 Notification 허용" 을 계약으로 갖는다). 과거 상한이 남아 있는 쪽은
     * 구독 상태를 바꾸는 제어 메시지뿐이라, 이 자리를 그 검사로 돌린다.
     */
    public function test_만료된_control_메시지_timestamp_를_거부한다(): void
    {
        // 2시간 전 — 서명은 유효하지만 제어 메시지 상한(1시간)을 넘었다.
        $message = $this->bag->factory()->unsubscribeConfirmationAt(time() - 7200);

        $this->postSnsPayload($message)
            ->assertForbidden()
            ->assertJsonPath('reason', 'timestamp_expired');

        $this->assertSame(0, SesEventLog::count());

        // 같은 나이의 Notification 은 반대로 통과해야 한다 — 정책이 종류별로 갈린다는
        // 사실을 이 한 테스트 안에서 대조로 확인한다.
        $late = $this->bag->factory()->notificationAt(SnsFixtureFactory::deliveryEvent('late-ok'), time() - 7200);

        $this->postSnsPayload($late)
            ->assertOk()
            ->assertJsonPath('status', 'stored');

        $this->assertSame(1, SesEventLog::where('ses_message_id', 'late-ok')->count());
    }

    // ── 입력 검증 ───────────────────────────────────────────────────────────

    public function test_잘못된_JSON_을_거부한다(): void
    {
        $this->postSnsPayload('{ this is not json ')
            ->assertStatus(400)
            ->assertJsonPath('reason', 'invalid_json');
    }

    public function test_지원하지_않는_sns_type_을_거부한다(): void
    {
        $message = $this->bag->factory()->notification(SnsFixtureFactory::bounceEvent(), ['Type' => 'SomethingElse']);

        $this->postSnsPayload($message)->assertStatus(400)->assertJsonPath('reason', 'type_not_allowed');
    }

    public function test_과대_body_를_거부한다(): void
    {
        $this->configureSes(['max_body_bytes' => 1024]);

        $huge = str_repeat('a', 2048);

        $this->postSnsPayload('{"padding":"'.$huge.'"}')
            ->assertStatus(400)
            ->assertJsonPath('reason', 'body_too_large');
    }

    public function test_우리가_다루지_않는_이벤트는_저장하지_않고_200_을_준다(): void
    {
        $this->postSnsPayload($this->bag->factory()->notification(SnsFixtureFactory::openEvent()))
            ->assertOk()
            ->assertJsonPath('status', 'ignored');

        $this->assertSame(0, SesEventLog::count());
    }

    // ── 멱등성 ──────────────────────────────────────────────────────────────

    public function test_같은_MessageId_재전송은_중복_저장하지_않는다(): void
    {
        $message = $this->bag->factory()->notification(SnsFixtureFactory::bounceEvent('dup-msg'));

        $this->postSnsPayload($message)->assertOk()->assertJsonPath('status', 'stored');
        $this->postSnsPayload($message)->assertOk()->assertJsonPath('status', 'duplicate');

        $this->assertSame(1, SesEventLog::where('ses_message_id', 'dup-msg')->count());
    }

    // ── 구독 확인 ───────────────────────────────────────────────────────────

    public function test_subscription_confirmation_은_기본적으로_자동_호출하지_않는다(): void
    {
        $this->postSnsPayload($this->bag->factory()->subscriptionConfirmation())
            ->assertOk()
            ->assertJsonPath('status', 'confirmation_pending');
    }

    public function test_서명이_틀린_subscription_confirmation_은_처리하지_않는다(): void
    {
        $message = $this->bag->factory()->subscriptionConfirmation();
        $message['Token'] = 'attacker-supplied-token';

        $this->postSnsPayload($message)
            ->assertForbidden()
            ->assertJsonPath('reason', 'signature_invalid');
    }

    public function test_unsubscribe_confirmation_은_기록만_하고_200_을_준다(): void
    {
        $this->postSnsPayload($this->bag->factory()->unsubscribeConfirmation())
            ->assertOk()
            ->assertJsonPath('status', 'unsubscribe_acknowledged');
    }

    // ── 이벤트 파싱 ─────────────────────────────────────────────────────────

    public function test_complaint_를_파싱해_저장한다(): void
    {
        $this->postSnsPayload($this->bag->factory()->notification(SnsFixtureFactory::complaintEvent('c-1')))->assertOk();

        $this->assertDatabaseHas('ses_event_logs', [
            'ses_message_id' => 'c-1',
            'event_type' => 'complaint',
            'complaint_feedback_type' => 'abuse',
        ]);
    }

    public function test_delivery_를_파싱해_저장한다(): void
    {
        $this->postSnsPayload($this->bag->factory()->notification(SnsFixtureFactory::deliveryEvent('d-1')))->assertOk();

        $this->assertDatabaseHas('ses_event_logs', ['ses_message_id' => 'd-1', 'event_type' => 'delivery']);
    }

    // ── 개인정보 ────────────────────────────────────────────────────────────

    public function test_raw_payload_는_기본적으로_저장하지_않는다(): void
    {
        $this->postSnsPayload($this->bag->factory()->notification(SnsFixtureFactory::bounceEvent('raw-off')))->assertOk();

        $this->assertNull(SesEventLog::where('ses_message_id', 'raw-off')->value('raw_payload'));
    }

    public function test_raw_payload_를_켜면_저장한다(): void
    {
        $this->configureSes(['raw_payload_enabled' => true]);
        $this->bindFixtureValidator();

        $this->postSnsPayload($this->bag->factory()->notification(SnsFixtureFactory::bounceEvent('raw-on')))->assertOk();

        $this->assertNotNull(SesEventLog::where('ses_message_id', 'raw-on')->value('raw_payload'));
    }

    // ── 라우트 계약 ─────────────────────────────────────────────────────────

    public function test_endpoint_는_CSRF_토큰_없이도_동작한다(): void
    {
        // 세션 미들웨어가 붙은 상태에서 토큰 없이 POST — 419 가 아니어야 한다.
        $this->withMiddleware();

        $this->postSnsPayload($this->bag->factory()->notification(SnsFixtureFactory::deliveryEvent('csrf-free')))
            ->assertOk();
    }

    /**
     * GET 은 webhook 처리 경로에 들어가지 않는다.
     *
     * 405 를 기대하지 않는 이유: 이 앱에는 `routes/web.php` 에 SPA catch-all
     * `Route::get('/{any?}')` 가 있어 GET 요청을 그쪽이 200 으로 받는다. 즉 200 은
     * "webhook 이 GET 을 처리했다" 는 뜻이 아니라 "전역 fallback 이 받았다" 는 뜻이다.
     * 정확한 405 를 만들려고 코어 라우트나 플러그인 라우트를 넓히지 않는다.
     *
     * 대신 증명해야 할 것을 직접 검사한다 — webhook 액션에는 POST 만 있고,
     * GET 으로는 저장·호출·처리 로그가 하나도 일어나지 않는다.
     */
    public function test_GET_은_webhook_처리_경로에_들어가지_않는다(): void
    {
        // (1) 라우트 수준: webhook 액션은 POST 로만 등록돼 있다.
        $methods = [];
        foreach ($this->app['router']->getRoutes() as $route) {
            if ($route->uri() !== ltrim(self::ENDPOINT, '/')) {
                continue;
            }
            $methods = array_merge($methods, $route->methods());
        }

        $methods = array_values(array_unique($methods));
        sort($methods);

        // Laravel 은 POST 라우트에 HEAD 를 붙이지 않는다 (GET 에만 붙인다).
        $this->assertSame(['POST'], $methods, 'webhook 라우트에 POST 외 메서드가 있습니다');

        // (2) 동작 수준: GET 으로는 어떤 부수효과도 없다.
        Http::fake([self::SUBSCRIBE_HOST_PATTERN => Http::response('ok', 200)]);

        $before = SesEventLog::count();

        // 라우터가 GET 요청을 어디로 보내는지 직접 확인한다 — 200 응답 코드가 아니라
        // "어떤 라우트가 받았는가" 가 증명해야 할 사실이다.
        $matched = null;
        try {
            $matched = $this->app['router']->getRoutes()->match(
                Request::create(self::ENDPOINT, 'GET')
            );
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            // 매칭되는 라우트가 없으면(405/404) 그 자체로 webhook 미도달이다.
        }

        if ($matched !== null) {
            $this->assertNotSame(
                'yutiv.ses-monitor.webhook',
                $matched->getName(),
                'GET 이 webhook 라우트로 매칭됐습니다'
            );
            $this->assertStringNotContainsString(
                'SesWebhookController',
                (string) $matched->getActionName(),
                'GET 이 webhook 컨트롤러로 들어갔습니다'
            );
        }

        $this->get(self::ENDPOINT);

        // 부수효과가 하나도 없어야 한다.
        $this->assertSame($before, SesEventLog::count(), 'GET 으로 SES 이벤트가 저장됐습니다');
        Http::assertNothingSent();

        // 코어가 남기는 로그는 무시하고, 우리 webhook 처리 로그만 없어야 한다.
        $sesLogs = array_values(array_filter(
            $this->logSpy->messages(),
            static fn ($message) => str_starts_with($message, 'ses_monitor.')
        ));
        $this->assertSame([], $sesLogs, 'GET 이 webhook 처리 로그를 남겼습니다: '.implode(', ', $sesLogs));
    }
}
