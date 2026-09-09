<?php

namespace Plugins\Yutiv\SesMonitor\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Plugins\Yutiv\SesMonitor\Models\SesEventLog;
use Plugins\Yutiv\SesMonitor\Support\SesConfig;
use Plugins\Yutiv\SesMonitor\Tests\PluginTestCase;
use Plugins\Yutiv\SesMonitor\Tests\Support\SnsFixtureFactory;

/**
 * Timestamp 정책 — 메시지 종류별로 다르다.
 *
 * ── 왜 종류별인가 ───────────────────────────────────────────────────────────
 * Notification 에 과거 상한을 걸면, 서버 장애·배포로 못 받은 정상 SES 이벤트가
 * SNS 재시도로 뒤늦게 도착했을 때 403 이 되어 **영구 유실**된다. replay 방어는
 * `ses_event_logs.sns_message_id` unique 가 이미 담당한다.
 *
 * 제어 메시지(구독 확인/해지)는 다르다 — 오래된 Token 으로 구독 상태를 바꾸는 것은
 * 실제 위험이라 과거 상한을 유지한다.
 */
class SesTimestampPolicyTest extends PluginTestCase
{
    private const ENDPOINT = '/webhooks/aws/ses';

    /**
     * @param  array<string, mixed>  $message
     */
    private function postSnsMessage(array $message): \Illuminate\Testing\TestResponse
    {
        return $this->call(
            'POST',
            self::ENDPOINT,
            [], [], [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode($message)
        );
    }

    // ── Notification: 과거 상한 없음 ────────────────────────────────────────

    public function test_오래된_정상_Notification_은_저장되고_200(): void
    {
        // 3일 전 이벤트가 지금 도착했다 — 서명은 그 시각 기준으로 유효하다.
        $message = $this->bag->factory()->notificationAt(
            SnsFixtureFactory::bounceEvent('late-1'),
            time() - 259200
        );

        $this->postSnsMessage($message)
            ->assertOk()
            ->assertJsonPath('status', 'stored');

        $this->assertDatabaseHas('ses_event_logs', [
            'ses_message_id' => 'late-1',
            'event_type' => 'bounce',
        ]);
    }

    public function test_아주_오래된_Notification_도_거부하지_않는다(): void
    {
        // 30일 전. 과거 상한이 남아 있었다면 여기서 403 이 됐을 것이다.
        $message = $this->bag->factory()->notificationAt(
            SnsFixtureFactory::deliveryEvent('ancient-1'),
            time() - 2592000
        );

        $this->postSnsMessage($message)->assertOk()->assertJsonPath('status', 'stored');

        $this->assertSame(1, SesEventLog::where('ses_message_id', 'ancient-1')->count());
    }

    public function test_오래된_Notification_재전송은_중복_저장하지_않는다(): void
    {
        $message = $this->bag->factory()->notificationAt(
            SnsFixtureFactory::bounceEvent('late-dup'),
            time() - 259200
        );

        $this->postSnsMessage($message)->assertOk()->assertJsonPath('status', 'stored');
        $this->postSnsMessage($message)->assertOk()->assertJsonPath('status', 'duplicate');

        $this->assertSame(1, SesEventLog::where('ses_message_id', 'late-dup')->count());
    }

    public function test_오래된_이벤트의_occurred_at_은_SES_시각을_보존한다(): void
    {
        $this->postSnsMessage($this->bag->factory()->notificationAt(
            SnsFixtureFactory::bounceEvent('preserve-1'),
            time() - 259200
        ))->assertOk();

        $event = SesEventLog::where('ses_message_id', 'preserve-1')->firstOrFail();

        // fixture 의 bounce.timestamp (2026-09-09T01:00:05Z) 를 그대로 보존한다 —
        // 수신 시각으로 덮어쓰지 않는다.
        $this->assertSame('2026-09-09 01:00:05', $event->occurred_at->toDateTimeString());
    }

    public function test_지연_시간을_조회할_수_있다(): void
    {
        $this->postSnsMessage($this->bag->factory()->notificationAt(
            SnsFixtureFactory::bounceEvent('delay-1'),
            time() - 259200
        ))->assertOk();

        $event = SesEventLog::where('ses_message_id', 'delay-1')->firstOrFail();

        $this->assertNotNull($event->created_at, 'received_at(created_at) 이 없습니다');
        $this->assertNotNull($event->delaySeconds());
        $this->assertGreaterThan(0, $event->delaySeconds());

        $this->actingAs($this->createNotificationLogReader())
            ->getJson('/api/plugins/yutiv-ses_monitor/admin/ses-events?ses_message_id=delay-1')
            ->assertOk()
            ->assertJsonPath('data.items.0.occurred_at', '2026-09-09 01:00:05')
            ->assertJsonStructure(['data' => ['items' => [['received_at', 'delay_seconds']]]]);
    }

    public function test_아주_오래된_이벤트는_구조화_warning_을_남기되_개인정보는_없다(): void
    {
        // ★ 이벤트 시각을 **지금 기준**으로 만든다. fixture 의 고정 날짜를 쓰면
        //   서버 시계가 그 날짜에 가까울 때 지연이 임계값(6시간) 아래가 되어
        //   경고가 발생하지 않는다 — 실제로 그렇게 실패했다.
        $occurredAt = gmdate('Y-m-d\\TH:i:s.000\\Z', time() - 2592000);

        $message = $this->bag->factory()->notificationAt(
            SnsFixtureFactory::bounceEvent('warn-1', $occurredAt),
            time() - 2592000
        );

        $this->postSnsMessage($message)->assertOk();

        $this->assertContains('ses_monitor.event_delayed', $this->logSpy->messages());

        $entry = $this->logSpy->first('ses_monitor.event_delayed');
        $this->assertNotNull($entry);
        $this->assertGreaterThan(21600, $entry['context']['delay_seconds']);

        $dump = $this->logSpy->dump();

        $this->assertStringNotContainsString('bounced@example.com', $dump, '수신자가 로그에 남았습니다');
        $this->assertStringNotContainsString($message['MessageId'], $dump, '전체 MessageId 가 로그에 남았습니다');
        $this->assertStringNotContainsString($message['Message'], $dump, 'payload 가 로그에 남았습니다');
        $this->assertStringNotContainsString('550 5.1.1', $dump, '진단 코드가 로그에 남았습니다');
    }

    // ── 미래 Timestamp: 전 타입 거부 ────────────────────────────────────────

    public function test_미래_Notification_은_403(): void
    {
        $message = $this->bag->factory()->notificationAt(
            SnsFixtureFactory::bounceEvent('future-1'),
            time() + 3600
        );

        $this->postSnsMessage($message)
            ->assertForbidden()
            ->assertJsonPath('reason', 'timestamp_future');

        $this->assertSame(0, SesEventLog::count());
    }

    public function test_허용_오차_안의_미래는_통과한다(): void
    {
        // 기본 skew 300초 — 서버 시계가 조금 앞선 정도는 인정한다.
        $message = $this->bag->factory()->notificationAt(
            SnsFixtureFactory::deliveryEvent('skew-ok'),
            time() + 60
        );

        $this->postSnsMessage($message)->assertOk()->assertJsonPath('status', 'stored');
    }

    public function test_미래_SubscriptionConfirmation_도_403이고_호출하지_않는다(): void
    {
        $this->configureSes(['auto_confirm' => true]);
        $this->bindFixtureValidator();
        Http::fake(['*' => Http::response('ok', 200)]);

        $message = $this->bag->factory()->subscriptionConfirmation([
            'Timestamp' => gmdate('Y-m-d\TH:i:s.000\Z', time() + 3600),
        ]);

        $this->postSnsMessage($message)->assertForbidden();

        Http::assertNothingSent();
    }

    // ── 제어 메시지: 과거 상한 유지 ─────────────────────────────────────────

    public function test_만료된_SubscriptionConfirmation_은_403이고_SubscribeURL_미호출(): void
    {
        $this->configureSes(['auto_confirm' => true, 'control_max_age_seconds' => 3600]);
        $this->bindFixtureValidator();
        Http::fake(['*' => Http::response('ok', 200)]);

        // 2시간 전 — 서명은 유효하지만 제어 메시지 상한(1시간)을 넘었다.
        $message = $this->bag->factory()->subscriptionConfirmationAt(time() - 7200);

        $this->postSnsMessage($message)
            ->assertForbidden()
            ->assertJsonPath('reason', 'timestamp_expired');

        Http::assertNothingSent();
    }

    public function test_새_SubscriptionConfirmation_은_기존_auto_confirm_계약대로_동작한다(): void
    {
        $this->configureSes(['auto_confirm' => true]);
        $this->bindFixtureValidator();
        Http::fake(['sns.ap-northeast-2.amazonaws.com/*' => Http::response('ok', 200)]);

        $this->postSnsMessage($this->bag->factory()->subscriptionConfirmation())
            ->assertOk()
            ->assertJsonPath('status', 'confirmed');

        Http::assertSentCount(1);
    }

    public function test_만료된_UnsubscribeConfirmation_도_403(): void
    {
        $message = $this->bag->factory()->unsubscribeConfirmationAt(time() - 7200);

        $this->postSnsMessage($message)
            ->assertForbidden()
            ->assertJsonPath('reason', 'timestamp_expired');
    }

    public function test_상한_안의_UnsubscribeConfirmation_은_200(): void
    {
        $message = $this->bag->factory()->unsubscribeConfirmationAt(time() - 60);

        $this->postSnsMessage($message)
            ->assertOk()
            ->assertJsonPath('status', 'unsubscribe_acknowledged');
    }

    // ── 설정 계약 ───────────────────────────────────────────────────────────

    public function test_두_Timestamp_정책이_설정에서_분리되어_있다(): void
    {
        $config = require base_path('plugins/_bundled/yutiv-ses_monitor/config/ses-monitor.php');

        $this->assertArrayHasKey('max_future_skew_seconds', $config);
        $this->assertArrayHasKey('control_max_age_seconds', $config);
        $this->assertSame(300, $config['max_future_skew_seconds']);
        $this->assertSame(3600, $config['control_max_age_seconds']);

        // 폐기된 키가 설정에 남아 있으면 안 된다.
        $this->assertArrayNotHasKey('max_age_seconds', $config);
    }

    public function test_예전_설정은_판정에_쓰이지_않는다(): void
    {
        // 예전 키를 아무리 짧게 넣어도 오래된 Notification 은 통과해야 한다.
        $this->configureSes(['max_age_seconds' => 1]);
        $this->bindFixtureValidator();

        $message = $this->bag->factory()->notificationAt(
            SnsFixtureFactory::deliveryEvent('legacy-ignored'),
            time() - 259200
        );

        $this->postSnsMessage($message)->assertOk()->assertJsonPath('status', 'stored');
    }

    public function test_SesConfig_에_예전_접근자가_없다(): void
    {
        $this->assertFalse(
            method_exists(SesConfig::class, 'maxAgeSeconds'),
            'SES_SNS_MAX_AGE_SECONDS 접근자가 남아 있습니다'
        );
        $this->assertTrue(method_exists(SesConfig::class, 'controlMaxAgeSeconds'));
        $this->assertTrue(method_exists(SesConfig::class, 'maxFutureSkewSeconds'));
    }

    public function test_소스에_예전_설정_판정이_남아_있지_않다(): void
    {
        $root = base_path('plugins/_bundled/yutiv-ses_monitor');

        foreach (['src/Support/SnsMessageValidator.php', 'src/Providers/SesMonitorServiceProvider.php'] as $file) {
            $contents = (string) file_get_contents($root.'/'.$file);

            $this->assertStringNotContainsString('max_age_seconds', $contents, $file);
            $this->assertStringNotContainsString('maxAgeSeconds', $contents, $file);
        }
    }

    public function test_README_에_두_정책이_구분되어_있다(): void
    {
        $readme = (string) file_get_contents(base_path('plugins/_bundled/yutiv-ses_monitor/README.md'));

        $this->assertStringContainsString('SES_SNS_MAX_FUTURE_SKEW_SECONDS', $readme);
        $this->assertStringContainsString('SES_SNS_CONTROL_MAX_AGE_SECONDS', $readme);
        $this->assertStringContainsString('Request confirmation', $readme);
    }
}
