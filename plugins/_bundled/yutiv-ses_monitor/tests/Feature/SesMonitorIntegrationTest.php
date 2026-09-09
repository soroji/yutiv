<?php

namespace Plugins\Yutiv\SesMonitor\Tests\Feature;

use App\Enums\ExtensionOwnerType;
use App\Models\NotificationLog;
use Illuminate\Support\Facades\Mail;
use Plugins\Yutiv\SesMonitor\Listeners\AttachSesConfigurationSet;
use Plugins\Yutiv\SesMonitor\Models\SesEventLog;
use Plugins\Yutiv\SesMonitor\Models\SesMessageLink;
use Plugins\Yutiv\SesMonitor\Tests\PluginTestCase;
use Plugins\Yutiv\SesMonitor\Tests\Support\SnsFixtureFactory;

/**
 * 발송 측 헤더 삽입 · 관리자 API 권한 · prune · 기존 발송 이력 회귀 테스트.
 */
class SesMonitorIntegrationTest extends PluginTestCase
{
    // ── 발송 측: configuration set 헤더 ─────────────────────────────────────

    public function test_모든_메일에_configuration_set_헤더가_1회_붙는다(): void
    {
        $this->configureSes(['configuration_set' => 'yutiv-production']);

        $headers = $this->captureHeadersOfSentMail();

        $this->assertCount(
            1,
            $headers->all(AttachSesConfigurationSet::HEADER),
            '헤더가 없거나 두 번 이상 붙었습니다'
        );
        $this->assertSame(
            'yutiv-production',
            $headers->get(AttachSesConfigurationSet::HEADER)->getBodyAsString()
        );
    }

    public function test_configuration_set_이_비어_있으면_헤더를_붙이지_않는다(): void
    {
        $this->configureSes(['configuration_set' => '']);

        $headers = $this->captureHeadersOfSentMail();

        $this->assertFalse($headers->has(AttachSesConfigurationSet::HEADER));
    }

    public function test_이미_헤더가_있으면_덮어쓰지_않는다(): void
    {
        $this->configureSes(['configuration_set' => 'yutiv-production']);

        $captured = null;
        Mail::raw('body', function ($message) use (&$captured) {
            $message->to('someone@example.com')->subject('제목');
            $message->getHeaders()->addTextHeader(AttachSesConfigurationSet::HEADER, 'already-set');
            $captured = $message;
        });

        $headers = $captured->getHeaders();

        $this->assertCount(1, $headers->all(AttachSesConfigurationSet::HEADER));
        $this->assertSame('already-set', $headers->get(AttachSesConfigurationSet::HEADER)->getBodyAsString());
    }

    public function test_비메일_채널에는_영향이_없다(): void
    {
        $this->configureSes(['configuration_set' => 'yutiv-production']);

        // database 채널 발송 이력은 메일 전송기를 거치지 않으므로 링크도 생기지 않는다.
        $log = NotificationLog::create($this->logAttributes(['channel' => 'database']));

        $this->assertSame(0, SesMessageLink::where('notification_log_id', $log->id)->count());
    }

    /**
     * 실제로 한 통 보내고 그 메시지의 헤더를 돌려준다.
     */
    private function captureHeadersOfSentMail(): \Symfony\Component\Mime\Header\Headers
    {
        $captured = null;

        Mail::raw('본문', function ($message) use (&$captured) {
            $message->to('recipient@example.com')->subject('테스트 메일');
            $captured = $message;
        });

        $this->assertNotNull($captured, '메일이 발송되지 않았습니다');

        return $captured->getHeaders();
    }

    // ── 관리자 API 권한 ─────────────────────────────────────────────────────

    public function test_권한이_없으면_이벤트_목록을_볼_수_없다(): void
    {
        $this->actingAs($this->createAdminWithoutLogPermission())
            ->getJson('/api/plugins/yutiv-ses_monitor/admin/ses-events')
            ->assertForbidden();
    }

    public function test_비로그인은_이벤트_목록을_볼_수_없다(): void
    {
        $this->getJson('/api/plugins/yutiv-ses_monitor/admin/ses-events')
            ->assertUnauthorized();
    }

    public function test_notification_logs_read_권한이_있으면_목록을_볼_수_있다(): void
    {
        $this->seedEvent('list-1', 'delivery');

        $this->actingAs($this->createNotificationLogReader())
            ->getJson('/api/plugins/yutiv-ses_monitor/admin/ses-events')
            ->assertOk()
            ->assertJsonPath('data.items.0.ses_message_id', 'list-1');
    }

    public function test_목록_응답은_수신자를_마스킹하고_원문을_노출하지_않는다(): void
    {
        $this->seedEvent('mask-1', 'bounce', ['alice@example.com']);

        $response = $this->actingAs($this->createNotificationLogReader())
            ->getJson('/api/plugins/yutiv-ses_monitor/admin/ses-events')
            ->assertOk();

        $response->assertJsonPath('data.items.0.recipients_masked.0', 'a***e@example.com');
        $response->assertJsonMissingPath('data.items.0.raw_payload');
        $response->assertJsonMissingPath('data.items.0.recipients');
        $this->assertStringNotContainsString('alice@example.com', $response->getContent());
    }

    public function test_미연결_이벤트만_필터할_수_있다(): void
    {
        $this->seedEvent('linked-1', 'delivery');
        $this->seedEvent('unlinked-1', 'bounce');

        $log = NotificationLog::create($this->logAttributes());
        SesMessageLink::create([
            'ses_message_id' => 'linked-1',
            'notification_log_id' => $log->id,
            'recipient_identifier' => 'user@example.com',
            'sent_at' => now(),
        ]);

        $this->actingAs($this->createNotificationLogReader())
            ->getJson('/api/plugins/yutiv-ses_monitor/admin/ses-events?linked=unlinked')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.ses_message_id', 'unlinked-1');
    }

    public function test_발송_이력에_연결된_이벤트를_조회할_수_있다(): void
    {
        $log = NotificationLog::create($this->logAttributes());
        $this->seedEvent('joined-1', 'delivery');
        SesMessageLink::create([
            'ses_message_id' => 'joined-1',
            'notification_log_id' => $log->id,
            'recipient_identifier' => 'user@example.com',
            'sent_at' => now(),
        ]);

        $this->actingAs($this->createNotificationLogReader())
            ->getJson('/api/plugins/yutiv-ses_monitor/admin/ses-events/for-notification-log/'.$log->id)
            ->assertOk()
            ->assertJsonPath('data.events.0.event_type', 'delivery');
    }

    public function test_원문은_설정이_꺼져_있으면_상세에서도_나오지_않는다(): void
    {
        $event = $this->seedEvent('raw-1', 'bounce');

        $this->actingAs($this->createNotificationLogReader())
            ->getJson('/api/plugins/yutiv-ses_monitor/admin/ses-events/'.$event->id.'?include_raw=1')
            ->assertOk()
            ->assertJsonPath('data.raw_payload', null);
    }

    // ── 기존 발송 이력 회귀 ─────────────────────────────────────────────────

    public function test_SES_이벤트가_들어와도_발송_이력_status_는_바뀌지_않는다(): void
    {
        $log = NotificationLog::create($this->logAttributes());
        $before = $log->fresh()->toArray();

        $this->call(
            'POST',
            '/webhooks/aws/ses',
            [], [], [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode($this->bag->factory()->notification(SnsFixtureFactory::bounceEvent('regress-1')))
        )->assertOk();

        $after = $log->fresh()->toArray();

        $this->assertSame($before, $after, 'SES 이벤트 수신이 기존 발송 이력 행을 바꿨습니다');
        $this->assertSame('sent', $log->fresh()->status?->value ?? $log->fresh()->status);
    }

    public function test_발송_이력_목록_API_는_그대로_동작한다(): void
    {
        NotificationLog::create($this->logAttributes());

        $this->actingAs($this->createNotificationLogReader())
            ->getJson('/api/admin/notification-logs')
            ->assertOk();
    }

    // ── prune ───────────────────────────────────────────────────────────────

    public function test_prune_은_보존기간_경과분만_삭제한다(): void
    {
        $this->seedEvent('old-1', 'delivery', ['a@example.com'], now()->subDays(120));
        $this->seedEvent('new-1', 'delivery', ['b@example.com'], now()->subDays(10));

        $this->artisan('yutiv:ses-prune')->assertExitCode(0);

        $this->assertSame(0, SesEventLog::where('ses_message_id', 'old-1')->count());
        $this->assertSame(1, SesEventLog::where('ses_message_id', 'new-1')->count());
    }

    public function test_prune_dry_run_은_삭제하지_않는다(): void
    {
        $this->seedEvent('old-2', 'delivery', ['a@example.com'], now()->subDays(120));

        $this->artisan('yutiv:ses-prune --dry-run')->assertExitCode(0);

        $this->assertSame(1, SesEventLog::where('ses_message_id', 'old-2')->count());
    }

    public function test_prune_은_발송_이력을_건드리지_않는다(): void
    {
        $log = NotificationLog::create($this->logAttributes(['sent_at' => now()->subDays(365)]));
        $this->seedEvent('old-3', 'delivery', ['a@example.com'], now()->subDays(120));

        $this->artisan('yutiv:ses-prune')->assertExitCode(0);

        $this->assertDatabaseHas('notification_logs', ['id' => $log->id]);
    }

    // ── status ──────────────────────────────────────────────────────────────

    public function test_status_는_비밀값과_개인정보를_출력하지_않는다(): void
    {
        $this->seedEvent('status-1', 'bounce', ['secret-user@example.com']);

        $this->artisan('yutiv:ses-status --json')
            ->assertExitCode(0)
            ->expectsOutputToContain('"webhook_enabled": true')
            ->doesntExpectOutputToContain('secret-user@example.com')
            ->doesntExpectOutputToContain('123456789012');
    }

    // ── 헬퍼 ────────────────────────────────────────────────────────────────

    /**
     * @param  array<int, string>  $recipients
     */
    private function seedEvent(
        string $sesMessageId,
        string $eventType,
        array $recipients = ['user@example.com'],
        ?\Illuminate\Support\Carbon $occurredAt = null,
    ): SesEventLog {
        return SesEventLog::create([
            'sns_message_id' => 'sns-'.$sesMessageId,
            'topic_arn' => \Plugins\Yutiv\SesMonitor\Tests\SnsFixtureBag::TOPIC_ARN,
            'event_type' => $eventType,
            'ses_message_id' => $sesMessageId,
            'source' => 'no-reply@yutiv.com',
            'recipients' => $recipients,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function logAttributes(array $overrides = []): array
    {
        return array_merge([
            'channel' => 'mail',
            'notification_type' => 'welcome',
            'extension_type' => ExtensionOwnerType::Core->value,
            'extension_identifier' => 'core',
            'recipient_identifier' => 'user@example.com',
            'recipient_name' => '홍길동',
            'subject' => '환영합니다',
            'body' => '<p>본문</p>',
            'status' => 'sent',
            'source' => 'notification',
            'sent_at' => now(),
        ], $overrides);
    }
}
