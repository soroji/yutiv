<?php

namespace Plugins\Yutiv\SesMonitor\Tests\Feature;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Plugins\Yutiv\SesMonitor\Support\SubscriptionConfirmer;
use Plugins\Yutiv\SesMonitor\Tests\PluginTestCase;
use Plugins\Yutiv\SesMonitor\Tests\SnsFixtureBag;

/**
 * SNS `SubscriptionConfirmation` 처리 계약 테스트.
 *
 * ── 이 스위트가 지키는 사실 ─────────────────────────────────────────────────
 * SNS HTTPS 구독은 **엔드포인트가 SubscribeURL 을 호출해야** 확정된다. 그래서
 * "호출하느냐/안 하느냐" 가 이 기능의 핵심 계약이고, 여기서 검사하는 것도 그것이다.
 *
 * `Http::preventStrayRequests()` 가 걸려 있어(PluginTestCase) 페이크로 선언하지 않은
 * 실제 요청은 예외가 된다 — "실수로 진짜 AWS 를 부르는" 경로가 없다.
 */
class SesSubscriptionConfirmationTest extends PluginTestCase
{
    private const ENDPOINT = '/webhooks/aws/ses';

    /** SNS 확인 URL 페이크 패턴. */
    private const SUBSCRIBE_HOST = 'sns.ap-northeast-2.amazonaws.com/*';

    /**
     * @param  array<string, mixed>  $message
     */
    private function post(array $message): \Illuminate\Testing\TestResponse
    {
        return $this->call(
            'POST',
            self::ENDPOINT,
            [], [], [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode($message)
        );
    }

    private function enableAutoConfirm(): void
    {
        $this->configureSes(['auto_confirm' => true]);
        $this->bindFixtureValidator();
    }

    // ── auto-confirm = false ────────────────────────────────────────────────

    public function test_auto_confirm_false_면_SubscribeURL_을_호출하지_않는다(): void
    {
        Http::fake([self::SUBSCRIBE_HOST => Http::response('ok', 200)]);

        $this->post($this->bag->factory()->subscriptionConfirmation())
            ->assertOk()
            ->assertJsonPath('status', 'confirmation_pending');

        Http::assertNothingSent();
    }

    public function test_auto_confirm_false_에서_pending_구조화_로그를_남긴다(): void
    {
        $captured = [];
        Log::listen(function ($log) use (&$captured) {
            $captured[] = $log;
        });

        $this->post($this->bag->factory()->subscriptionConfirmation())->assertOk();

        $messages = array_column($captured, 'message');
        $this->assertContains('ses_monitor.subscription_confirmation_pending', $messages);

        $entry = collect($captured)->firstWhere('message', 'ses_monitor.subscription_confirmation_pending');
        $this->assertFalse($entry->context['subscribe_url_called']);
        $this->assertFalse($entry->context['auto_confirm']);
    }

    // ── auto-confirm = true ─────────────────────────────────────────────────

    public function test_auto_confirm_true_이고_서명이_정상이면_1회_호출한다(): void
    {
        $this->enableAutoConfirm();
        Http::fake([self::SUBSCRIBE_HOST => Http::response('<ConfirmSubscriptionResponse/>', 200)]);

        $this->post($this->bag->factory()->subscriptionConfirmation())
            ->assertOk()
            ->assertJsonPath('status', 'confirmed');

        Http::assertSentCount(1);
        Http::assertSent(fn (ClientRequest $r) => str_starts_with($r->url(), 'https://sns.ap-northeast-2.amazonaws.com/'));
    }

    // ── 검증 실패 시 호출 금지 ──────────────────────────────────────────────

    public function test_서명이_틀리면_auto_confirm_true_라도_호출하지_않는다(): void
    {
        $this->enableAutoConfirm();
        Http::fake([self::SUBSCRIBE_HOST => Http::response('ok', 200)]);

        // 서명 후 Token 을 바꾼다 — Token 은 서명 대상이므로 검증이 깨진다.
        $message = $this->bag->factory()->subscriptionConfirmation();
        $message['Token'] = 'attacker-supplied-token';

        $this->post($message)
            ->assertForbidden()
            ->assertJsonPath('reason', 'signature_invalid');

        Http::assertNothingSent();
    }

    public function test_TopicArn_이_다르면_호출하지_않는다(): void
    {
        $this->configureSes([
            'auto_confirm' => true,
            'topic_arn' => 'arn:aws:sns:ap-northeast-2:123456789012:someone-else',
        ]);
        $this->bindFixtureValidator();
        Http::fake([self::SUBSCRIBE_HOST => Http::response('ok', 200)]);

        $this->post($this->bag->factory()->subscriptionConfirmation())
            ->assertForbidden()
            ->assertJsonPath('reason', 'topic_mismatch');

        Http::assertNothingSent();
    }

    public function test_리전이_다르면_호출하지_않는다(): void
    {
        $this->configureSes(['auto_confirm' => true, 'region' => 'us-east-1']);
        $this->bindFixtureValidator();
        Http::fake(['*' => Http::response('ok', 200)]);

        $this->post($this->bag->factory()->subscriptionConfirmation())
            ->assertForbidden()
            ->assertJsonPath('reason', 'region_mismatch');

        Http::assertNothingSent();
    }

    public function test_오래된_Timestamp_면_호출하지_않는다(): void
    {
        $this->enableAutoConfirm();
        Http::fake([self::SUBSCRIBE_HOST => Http::response('ok', 200)]);

        $message = $this->bag->factory()->subscriptionConfirmation([
            'Timestamp' => gmdate('Y-m-d\TH:i:s.000\Z', time() - 4000),
        ]);

        $this->post($message)->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_SubscribeURL_이_SNS_호스트가_아니면_호출하지_않는다(): void
    {
        $this->enableAutoConfirm();
        Http::fake(['*' => Http::response('ok', 200)]);

        // 서명 대상에 포함된 필드라 검증 자체가 먼저 깨진다 — 어느 쪽이든 호출은 없어야 한다.
        $message = $this->bag->factory()->subscriptionConfirmation([
            'SubscribeURL' => 'https://evil.example.com/confirm',
        ]);

        $response = $this->post($message);

        $this->assertContains($response->getStatusCode(), [403]);
        Http::assertNothingSent();
    }

    public function test_confirmer_는_SNS_호스트가_아닌_URL_을_직접_거부한다(): void
    {
        // 컨트롤러를 거치지 않고 호출기 자체의 재검증을 확인한다.
        $this->assertFalse(SubscriptionConfirmer::isAllowedSubscribeUrl('https://evil.example.com/x', 'ap-northeast-2'));
        $this->assertFalse(SubscriptionConfirmer::isAllowedSubscribeUrl('http://sns.ap-northeast-2.amazonaws.com/x', 'ap-northeast-2'));
        $this->assertFalse(SubscriptionConfirmer::isAllowedSubscribeUrl('https://sns.us-east-1.amazonaws.com/x', 'ap-northeast-2'));
        $this->assertFalse(SubscriptionConfirmer::isAllowedSubscribeUrl('https://sns.ap-northeast-2.amazonaws.com:8443/x', 'ap-northeast-2'));
        $this->assertFalse(SubscriptionConfirmer::isAllowedSubscribeUrl('https://u:p@sns.ap-northeast-2.amazonaws.com/x', 'ap-northeast-2'));
        $this->assertTrue(SubscriptionConfirmer::isAllowedSubscribeUrl('https://sns.ap-northeast-2.amazonaws.com/?Action=ConfirmSubscription', 'ap-northeast-2'));
    }

    // ── redirect / 실패 ─────────────────────────────────────────────────────

    public function test_redirect_응답을_따라가지_않고_실패로_처리한다(): void
    {
        $this->enableAutoConfirm();

        Http::fake([
            self::SUBSCRIBE_HOST => Http::response('', 302, ['Location' => 'https://evil.example.com/steal']),
            'evil.example.com/*' => Http::response('should not be reached', 200),
        ]);

        $this->post($this->bag->factory()->subscriptionConfirmation())
            ->assertStatus(500)
            ->assertJsonPath('status', 'confirm_failed');

        // 최초 1회만 나갔고 리다이렉트 대상은 부르지 않았다.
        Http::assertSentCount(1);
        Http::assertNotSent(fn (ClientRequest $r) => str_contains($r->url(), 'evil.example.com'));
    }

    public function test_HTTP_실패면_500_으로_재시도를_허용한다(): void
    {
        $this->enableAutoConfirm();
        Http::fake([self::SUBSCRIBE_HOST => Http::response('server error', 500)]);

        $this->post($this->bag->factory()->subscriptionConfirmation())
            ->assertStatus(500)
            ->assertJsonPath('status', 'confirm_failed');
    }

    public function test_timeout_이면_안전하게_실패한다(): void
    {
        $this->enableAutoConfirm();
        Http::fake([
            self::SUBSCRIBE_HOST => fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out'),
        ]);

        $this->post($this->bag->factory()->subscriptionConfirmation())
            ->assertStatus(500)
            ->assertJsonPath('status', 'confirm_failed');
    }

    public function test_실패_후_재전송되면_다시_호출한다(): void
    {
        $this->enableAutoConfirm();

        $message = $this->bag->factory()->subscriptionConfirmation();

        Http::fake([self::SUBSCRIBE_HOST => Http::response('err', 500)]);
        $this->post($message)->assertStatus(500);

        // 실패했으므로 중복 표시가 해제되어야 한다 — 재시도가 통해야 복구된다.
        Http::fake([self::SUBSCRIBE_HOST => Http::response('ok', 200)]);
        $this->post($message)->assertOk()->assertJsonPath('status', 'confirmed');
    }

    // ── 멱등성 ──────────────────────────────────────────────────────────────

    public function test_같은_MessageId_재전송은_한_번만_호출한다(): void
    {
        $this->enableAutoConfirm();
        Http::fake([self::SUBSCRIBE_HOST => Http::response('ok', 200)]);

        $message = $this->bag->factory()->subscriptionConfirmation();

        $this->post($message)->assertOk()->assertJsonPath('status', 'confirmed');
        $this->post($message)->assertOk()->assertJsonPath('status', 'already_confirmed');

        Http::assertSentCount(1);
    }

    // ── 로깅 위생 ───────────────────────────────────────────────────────────

    public function test_로그에_Token_과_SubscribeURL_전체와_원문이_없다(): void
    {
        $this->enableAutoConfirm();
        Http::fake([self::SUBSCRIBE_HOST => Http::response('ok', 200)]);

        $message = $this->bag->factory()->subscriptionConfirmation();

        $captured = [];
        Log::listen(function ($log) use (&$captured) {
            $captured[] = $log;
        });

        $this->post($message)->assertOk();

        $dump = json_encode(array_map(
            fn ($l) => ['message' => $l->message, 'context' => $l->context],
            $captured
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertStringNotContainsString($message['Token'], $dump, 'Token 이 로그에 남았습니다');
        $this->assertStringNotContainsString($message['SubscribeURL'], $dump, 'SubscribeURL 전체가 로그에 남았습니다');
        $this->assertStringNotContainsString($message['Signature'], $dump, 'Signature 가 로그에 남았습니다');
        $this->assertStringNotContainsString('ConfirmSubscription', $dump, 'SubscribeURL 조각이 로그에 남았습니다');
        $this->assertStringNotContainsString($message['MessageId'], $dump, 'MessageId 전체가 로그에 남았습니다');
    }

    public function test_실패_로그에도_Token_과_URL_이_없다(): void
    {
        $this->enableAutoConfirm();
        Http::fake([self::SUBSCRIBE_HOST => Http::response('err', 500)]);

        $message = $this->bag->factory()->subscriptionConfirmation();

        $captured = [];
        Log::listen(function ($log) use (&$captured) {
            $captured[] = $log;
        });

        $this->post($message)->assertStatus(500);

        $dump = json_encode(array_map(
            fn ($l) => ['message' => $l->message, 'context' => $l->context],
            $captured
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertStringNotContainsString($message['Token'], $dump);
        $this->assertStringNotContainsString($message['SubscribeURL'], $dump);
    }

    // ── 문서 계약 ───────────────────────────────────────────────────────────

    public function test_문서에_콘솔_수동_확인이라는_잘못된_문구가_없다(): void
    {
        $root = base_path('plugins/_bundled/yutiv-ses_monitor');

        $files = [
            $root.'/README.md',
            $root.'/CHANGELOG.md',
            $root.'/config/ses-monitor.php',
            $root.'/src/Http/Controllers/SesWebhookController.php',
        ];

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);

            $this->assertStringNotContainsString('콘솔에서 수동 확인', $contents, basename($file));
            $this->assertStringNotContainsString('수동으로 연다', $contents, basename($file));
        }

        // 올바른 절차가 실제로 문서화되어 있어야 한다.
        $readme = (string) file_get_contents($root.'/README.md');
        $this->assertStringContainsString('최초 구독 절차', $readme);
        $this->assertStringContainsString('SES_SNS_AUTO_CONFIRM=true', $readme);
        $this->assertStringContainsString('PendingConfirmation', $readme);
    }

    public function test_기본_설정은_auto_confirm_이_꺼져_있다(): void
    {
        $config = require base_path('plugins/_bundled/yutiv-ses_monitor/config/ses-monitor.php');

        $this->assertFalse($config['auto_confirm'], 'SES_SNS_AUTO_CONFIRM 기본값은 false 여야 합니다');
    }

    public function test_fixture_토픽은_설정과_일치한다(): void
    {
        $this->assertSame(SnsFixtureBag::TOPIC_ARN, config('yutiv_ses_monitor.topic_arn'));
    }
}
