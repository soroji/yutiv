<?php

namespace Plugins\Yutiv\SesMonitor\Tests\Feature;

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

    /**
     * @param  array<string, mixed>|string  $payload
     */
    private function post(array|string $payload): \Illuminate\Testing\TestResponse
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

        $this->post($this->bag->factory()->notification($event))
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
        $this->post($this->bag->factory()->notification(SnsFixtureFactory::deliveryEvent('v2-msg'), [], '2'))
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

        $this->post($tampered)
            ->assertForbidden()
            ->assertJsonPath('reason', 'signature_invalid');

        $this->assertSame(0, SesEventLog::count());
    }

    public function test_서명이_없으면_거부한다(): void
    {
        $message = $this->bag->factory()->notification(SnsFixtureFactory::bounceEvent());
        unset($message['Signature']);

        $this->post($message)->assertStatus(400)->assertJsonPath('reason', 'malformed');
        $this->assertSame(0, SesEventLog::count());
    }

    public function test_허용되지_않은_인증서_URL_을_거부한다(): void
    {
        $message = $this->bag->factory()->notification(
            SnsFixtureFactory::bounceEvent(),
            ['SigningCertURL' => 'https://evil.example.com/cert.pem']
        );

        $this->post($message)
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

        $this->post($message)->assertForbidden()->assertJsonPath('reason', 'cert_url_rejected');
    }

    // ── 출처 검증 ───────────────────────────────────────────────────────────

    public function test_topic_arn_이_다르면_거부한다(): void
    {
        $this->configureSes(['topic_arn' => 'arn:aws:sns:ap-northeast-2:123456789012:someone-else']);
        $this->bindFixtureValidator();

        $this->post($this->bag->factory()->notification(SnsFixtureFactory::bounceEvent()))
            ->assertForbidden()
            ->assertJsonPath('reason', 'topic_mismatch');

        $this->assertSame(0, SesEventLog::count());
    }

    public function test_region_이_다르면_거부한다(): void
    {
        $this->configureSes(['region' => 'us-east-1']);
        $this->bindFixtureValidator();

        $this->post($this->bag->factory()->notification(SnsFixtureFactory::bounceEvent()))
            ->assertForbidden()
            ->assertJsonPath('reason', 'region_mismatch');
    }

    public function test_오래된_timestamp_를_거부한다(): void
    {
        $message = $this->bag->factory()->notificationAt(SnsFixtureFactory::bounceEvent(), time() - 4000);

        $this->post($message)
            ->assertForbidden()
            ->assertJsonPath('reason', 'timestamp_expired');

        $this->assertSame(0, SesEventLog::count());
    }

    // ── 입력 검증 ───────────────────────────────────────────────────────────

    public function test_잘못된_JSON_을_거부한다(): void
    {
        $this->post('{ this is not json ')
            ->assertStatus(400)
            ->assertJsonPath('reason', 'invalid_json');
    }

    public function test_지원하지_않는_sns_type_을_거부한다(): void
    {
        $message = $this->bag->factory()->notification(SnsFixtureFactory::bounceEvent(), ['Type' => 'SomethingElse']);

        $this->post($message)->assertStatus(400)->assertJsonPath('reason', 'type_not_allowed');
    }

    public function test_과대_body_를_거부한다(): void
    {
        $this->configureSes(['max_body_bytes' => 1024]);

        $huge = str_repeat('a', 2048);

        $this->post('{"padding":"'.$huge.'"}')
            ->assertStatus(400)
            ->assertJsonPath('reason', 'body_too_large');
    }

    public function test_우리가_다루지_않는_이벤트는_저장하지_않고_200_을_준다(): void
    {
        $this->post($this->bag->factory()->notification(SnsFixtureFactory::openEvent()))
            ->assertOk()
            ->assertJsonPath('status', 'ignored');

        $this->assertSame(0, SesEventLog::count());
    }

    // ── 멱등성 ──────────────────────────────────────────────────────────────

    public function test_같은_MessageId_재전송은_중복_저장하지_않는다(): void
    {
        $message = $this->bag->factory()->notification(SnsFixtureFactory::bounceEvent('dup-msg'));

        $this->post($message)->assertOk()->assertJsonPath('status', 'stored');
        $this->post($message)->assertOk()->assertJsonPath('status', 'duplicate');

        $this->assertSame(1, SesEventLog::where('ses_message_id', 'dup-msg')->count());
    }

    // ── 구독 확인 ───────────────────────────────────────────────────────────

    public function test_subscription_confirmation_은_기본적으로_자동_호출하지_않는다(): void
    {
        $this->post($this->bag->factory()->subscriptionConfirmation())
            ->assertOk()
            ->assertJsonPath('status', 'confirmation_pending');
    }

    public function test_서명이_틀린_subscription_confirmation_은_처리하지_않는다(): void
    {
        $message = $this->bag->factory()->subscriptionConfirmation();
        $message['Token'] = 'attacker-supplied-token';

        $this->post($message)
            ->assertForbidden()
            ->assertJsonPath('reason', 'signature_invalid');
    }

    public function test_unsubscribe_confirmation_은_기록만_하고_200_을_준다(): void
    {
        $this->post($this->bag->factory()->unsubscribeConfirmation())
            ->assertOk()
            ->assertJsonPath('status', 'unsubscribe_acknowledged');
    }

    // ── 이벤트 파싱 ─────────────────────────────────────────────────────────

    public function test_complaint_를_파싱해_저장한다(): void
    {
        $this->post($this->bag->factory()->notification(SnsFixtureFactory::complaintEvent('c-1')))->assertOk();

        $this->assertDatabaseHas('ses_event_logs', [
            'ses_message_id' => 'c-1',
            'event_type' => 'complaint',
            'complaint_feedback_type' => 'abuse',
        ]);
    }

    public function test_delivery_를_파싱해_저장한다(): void
    {
        $this->post($this->bag->factory()->notification(SnsFixtureFactory::deliveryEvent('d-1')))->assertOk();

        $this->assertDatabaseHas('ses_event_logs', ['ses_message_id' => 'd-1', 'event_type' => 'delivery']);
    }

    // ── 개인정보 ────────────────────────────────────────────────────────────

    public function test_raw_payload_는_기본적으로_저장하지_않는다(): void
    {
        $this->post($this->bag->factory()->notification(SnsFixtureFactory::bounceEvent('raw-off')))->assertOk();

        $this->assertNull(SesEventLog::where('ses_message_id', 'raw-off')->value('raw_payload'));
    }

    public function test_raw_payload_를_켜면_저장한다(): void
    {
        $this->configureSes(['raw_payload_enabled' => true]);
        $this->bindFixtureValidator();

        $this->post($this->bag->factory()->notification(SnsFixtureFactory::bounceEvent('raw-on')))->assertOk();

        $this->assertNotNull(SesEventLog::where('ses_message_id', 'raw-on')->value('raw_payload'));
    }

    // ── 라우트 계약 ─────────────────────────────────────────────────────────

    public function test_endpoint_는_CSRF_토큰_없이도_동작한다(): void
    {
        // 세션 미들웨어가 붙은 상태에서 토큰 없이 POST — 419 가 아니어야 한다.
        $this->withMiddleware();

        $this->post($this->bag->factory()->notification(SnsFixtureFactory::deliveryEvent('csrf-free')))
            ->assertOk();
    }

    public function test_GET_은_허용되지_않는다(): void
    {
        $this->get(self::ENDPOINT)->assertStatus(405);
    }
}
