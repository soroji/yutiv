<?php

namespace Plugins\Yutiv\SesMonitor\Tests\Feature;

use App\Enums\ExtensionOwnerType;
use App\Models\NotificationLog;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Plugins\Yutiv\SesMonitor\Listeners\AttachSesConfigurationSet;
use Plugins\Yutiv\SesMonitor\Models\SesEventLog;
use Plugins\Yutiv\SesMonitor\Models\SesMessageLink;
use Plugins\Yutiv\SesMonitor\Providers\SesMonitorServiceProvider;
use Plugins\Yutiv\SesMonitor\Support\PendingSentMessage;
use Plugins\Yutiv\SesMonitor\Support\SesConfig;
use Plugins\Yutiv\SesMonitor\Support\SesEventParser;
use Plugins\Yutiv\SesMonitor\Support\SnsMessageValidator;
use Plugins\Yutiv\SesMonitor\Support\SubscriptionConfirmer;
use Plugins\Yutiv\SesMonitor\Tests\PluginTestCase;
use Plugins\Yutiv\SesMonitor\Tests\Support\SnsFixtureFactory;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

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

        // Headers::all() 은 Generator 다 — 단언 전에 배열로 확정한다 (PHPUnit 11 계약).
        $values = $this->headerValues($headers, AttachSesConfigurationSet::HEADER);

        $this->assertCount(1, $values, '헤더가 없거나 두 번 이상 붙었습니다');

        // 개수와 값을 한 번에 못박는다 — 값이 맞아도 두 번 붙으면 실패한다.
        $this->assertSame(
            ['yutiv-production'],
            $this->headerBodies($headers, AttachSesConfigurationSet::HEADER)
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
        $this->assertArrayMailerActive();

        $captured = null;
        Mail::raw('body', function ($message) use (&$captured) {
            $message->to('someone@example.com')->subject('제목');
            $message->getHeaders()->addTextHeader(AttachSesConfigurationSet::HEADER, 'already-set');
            $captured = $message;
        });

        $headers = $captured->getHeaders();

        // 리스너가 기존 헤더를 덮어쓰지도, 하나 더 붙이지도 않았는지 본다.
        $this->assertCount(1, $this->headerValues($headers, AttachSesConfigurationSet::HEADER));
        $this->assertSame(
            ['already-set'],
            $this->headerBodies($headers, AttachSesConfigurationSet::HEADER)
        );
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
        // 실제 SMTP 로 나갈 가능성을 먼저 배제한다 — 여기서 터지면 환경 오염이다.
        $this->assertArrayMailerActive();

        $captured = null;

        Mail::raw('본문', function ($message) use (&$captured) {
            $message->to('recipient@example.com')->subject('테스트 메일');
            $captured = $message;
        });

        $this->assertNotNull($captured, '메일이 발송되지 않았습니다');

        return $captured->getHeaders();
    }

    public function test_테스트_전송기가_array_이며_네트워크로_나가지_않는다(): void
    {
        // 계약 자체를 하나의 테스트로 못박는다. 이게 깨지면 나머지 메일 테스트의
        // 결과는 신뢰할 수 없다 (실제 SMTP 로 나갔을 수 있으므로).
        $this->assertArrayMailerActive();

        $transport = Mail::mailer()->getSymfonyTransport();

        // ArrayTransport 는 메시지를 메모리에 쌓을 뿐 소켓을 열지 않는다.
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $this->assertNotInstanceOf(EsmtpTransport::class, $transport);

        $before = $transport->messages()->count();

        Mail::raw('본문', function ($message) {
            $message->to('nobody@example.com')->subject('격리 확인');
        });

        $this->assertSame($before + 1, $transport->messages()->count(), '메시지가 메모리 전송기에 쌓이지 않았습니다');
    }

    // ── 컨테이너 바인딩 계약 ────────────────────────────────────────────────

    public function test_플러그인_서비스가_컨테이너에서_해석된다(): void
    {
        // register() 가 돌지 않으면 생성자의 필수 primitive 를 채울 수 없어
        // Unresolvable dependency 로 터진다. 해석 자체를 계약으로 못박는다.
        $this->assertInstanceOf(SesEventParser::class, app(SesEventParser::class));
        $this->assertInstanceOf(SnsMessageValidator::class, app(SnsMessageValidator::class));
        $this->assertInstanceOf(SubscriptionConfirmer::class, app(SubscriptionConfirmer::class));
    }

    public function test_해석된_parser_의_허용_이벤트가_설정과_일치한다(): void
    {
        $parser = app(SesEventParser::class);

        // 설정에 있는 7종은 전부 인식하고,
        foreach (SesConfig::allowedEventTypes() as $type) {
            $this->assertNotNull(
                $parser->eventType(['eventType' => $type]),
                "{$type} 이 허용 목록에서 빠졌습니다"
            );
        }

        // 목록 밖 이벤트는 무시한다 — 빈 배열로 바인딩되지 않았음을 함께 확인한다.
        $this->assertNull($parser->eventType(['eventType' => 'Open']));
    }

    public function test_PendingSentMessage_는_요청_범위_싱글턴이다(): void
    {
        // 싱글턴이 아니면 MessageSent 와 로그 훅이 서로 다른 인스턴스를 보게 되어
        // 발송 이력 연결이 조용히 끊긴다.
        $this->assertSame(app(PendingSentMessage::class), app(PendingSentMessage::class));
    }

    public function test_프로바이더_생명주기를_반복해도_중복_등록되지_않는다(): void
    {
        $routesBefore = $this->countRoutesForUri('webhooks/aws/ses');
        $apiBefore = $this->countRoutesForUri(self::ADMIN_API_URI, 'GET');

        $this->app->register(SesMonitorServiceProvider::class);
        $this->app->register(SesMonitorServiceProvider::class);
        $this->registerPluginApiRoutes();

        $this->assertSame($routesBefore, $this->countRoutesForUri('webhooks/aws/ses'));
        $this->assertSame($apiBefore, $this->countRoutesForUri(self::ADMIN_API_URI, 'GET'));

        // 메일 리스너가 중복 등록되면 헤더가 두 번 붙을 수 있다 — 계약으로 확인한다.
        $this->configureSes(['configuration_set' => 'yutiv-production']);
        $headers = $this->captureHeadersOfSentMail();

        $this->assertCount(1, $this->headerValues($headers, AttachSesConfigurationSet::HEADER));
    }

    // ── 관리자 API 권한 ─────────────────────────────────────────────────────

    public function test_관리자_API_라우트가_테스트_앱에_등록되어_있다(): void
    {
        // 404 를 성공으로 착각하지 않기 위해, 권한 단언 전에 라우트 존재를 먼저 못박는다.
        $this->assertSame(
            1,
            $this->countRoutesForUri(self::ADMIN_API_URI, 'GET'),
            '관리자 API 라우트가 없거나 중복 등록됐습니다'
        );
    }

    public function test_관리자_API_라우트를_두_번_등록해도_중복되지_않는다(): void
    {
        $this->registerPluginApiRoutes();
        $this->registerPluginApiRoutes();

        $this->assertSame(1, $this->countRoutesForUri(self::ADMIN_API_URI, 'GET'));
    }


    public function test_권한이_없으면_이벤트_목록을_볼_수_없다(): void
    {
        $response = $this->actingAs($this->createAdminWithoutLogPermission())
            ->getJson('/'.self::ADMIN_API_URI);

        // 404 는 "권한 검사에 닿지도 못했다" 는 뜻이라 통과로 인정하지 않는다.
        $this->assertNotSame(404, $response->getStatusCode(), '라우트가 등록되지 않았습니다 (권한 검사 미도달)');
        $response->assertForbidden();
    }

    public function test_비로그인은_이벤트_목록을_볼_수_없다(): void
    {
        $response = $this->getJson('/'.self::ADMIN_API_URI);

        $this->assertNotSame(404, $response->getStatusCode(), '라우트가 등록되지 않았습니다 (인증 검사 미도달)');

        // API 경로는 redirect 하지 않고 401 JSON 이어야 한다 (bootstrap/app.php 계약).
        $this->assertNotSame(302, $response->getStatusCode(), 'API 인증 실패가 redirect 되었습니다');
        $response->assertUnauthorized();
    }

    public function test_notification_logs_read_권한이_있으면_목록을_볼_수_있다(): void
    {
        $this->seedEvent('list-1', 'delivery');

        $response = $this->actingAs($this->createNotificationLogReader())
            ->getJson('/'.self::ADMIN_API_URI);

        $this->assertNotSame(404, $response->getStatusCode(), '라우트가 등록되지 않았습니다');
        $response->assertOk()->assertJsonPath('data.items.0.ses_message_id', 'list-1');
    }

    public function test_목록_응답은_수신자를_마스킹하고_원문을_노출하지_않는다(): void
    {
        $this->seedEvent('mask-1', 'bounce', ['alice@example.com']);

        $response = $this->actingAs($this->createNotificationLogReader())
            ->getJson('/'.self::ADMIN_API_URI)
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
            ->getJson('/'.self::ADMIN_API_URI.'?linked=unlinked')
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
            ->getJson('/'.self::ADMIN_API_URI.'/for-notification-log/'.$log->id)
            ->assertOk()
            ->assertJsonPath('data.events.0.event_type', 'delivery');
    }

    public function test_원문은_설정이_꺼져_있으면_상세에서도_나오지_않는다(): void
    {
        $event = $this->seedEvent('raw-1', 'bounce');

        $this->actingAs($this->createNotificationLogReader())
            ->getJson('/'.self::ADMIN_API_URI.'/'.$event->id.'?include_raw=1')
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

        // 이벤트가 실제로 저장됐는지 먼저 본다 — 200 이지만 저장되지 않았다면
        // "status 가 안 바뀌었다" 는 단언이 공허해진다.
        $this->assertSame(1, SesEventLog::where('ses_message_id', 'regress-1')->count());

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
