<?php

namespace Plugins\Yutiv\SesMonitor\Providers;

use App\Extension\BasePluginServiceProvider;
use App\Extension\Traits\CachesPluginStatus;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Plugins\Yutiv\SesMonitor\Console\Commands\PruneSesEventLogsCommand;
use Plugins\Yutiv\SesMonitor\Console\Commands\SesMonitorStatusCommand;
use Plugins\Yutiv\SesMonitor\Http\Controllers\SesWebhookController;
use Plugins\Yutiv\SesMonitor\Listeners\AttachSesConfigurationSet;
use Plugins\Yutiv\SesMonitor\Listeners\CaptureSentMessageId;
use Plugins\Yutiv\SesMonitor\Support\PendingSentMessage;
use Plugins\Yutiv\SesMonitor\Support\SesConfig;
use Plugins\Yutiv\SesMonitor\Support\SesEventParser;
use Plugins\Yutiv\SesMonitor\Support\SnsMessageValidator;
use Plugins\Yutiv\SesMonitor\Support\SubscriptionConfirmer;

/**
 * SES 모니터 서비스 프로바이더.
 *
 * ── 코어 `PluginServiceProvider` 의 계약 주의점 ─────────────────────────────
 * `App\Providers\PluginServiceProvider` 는 **활성 여부와 무관하게** plugins/ 아래
 * 모든 `src/Providers/*ServiceProvider.php` 를 등록한다(라우트 로더와 달리 활성
 * 게이트가 없다). 그래서 이 프로바이더는 스스로 활성 여부를 확인하고, 비활성이면
 * 공개 endpoint 도 메일 리스너도 붙이지 않는다.
 *
 * ── 왜 라우트를 여기서 등록하는가 ──────────────────────────────────────────
 * `PluginRouteServiceProvider` 는 플러그인 라우트를 `plugins/{id}` 또는
 * `api/plugins/{id}` 프리픽스로 **강제**한다. 요구된 경로는 `/webhooks/aws/ses` 라
 * 그 로더로는 만들 수 없다. 코어 `routes/web.php` 를 수정하지 않기 위해, 이
 * 프로바이더 boot 에서 라우트 하나를 직접 등록한다.
 */
class SesMonitorServiceProvider extends BasePluginServiceProvider
{
    use CachesPluginStatus;

    protected string $pluginIdentifier = 'yutiv-ses_monitor';

    public function register(): void
    {
        parent::register();

        // 설정 병합 — env 가 비어 있으면 config/ses-monitor.php 의 안전한 기본값이 남는다.
        $this->mergeConfigFrom(
            dirname(__DIR__, 2).'/config/ses-monitor.php',
            SesConfig::KEY
        );

        // 요청 범위 보관소 — MessageSent 와 로그 훅 사이를 잇는다.
        $this->app->singleton(PendingSentMessage::class);

        $this->app->bind(SnsMessageValidator::class, static fn () => new SnsMessageValidator(
            SesConfig::topicArn(),
            SesConfig::region(),
            SesConfig::controlMaxAgeSeconds(),
            SesConfig::maxFutureSkewSeconds(),
            SesConfig::allowedSnsTypes(),
        ));

        $this->app->bind(SesEventParser::class, static fn () => new SesEventParser(
            SesConfig::allowedEventTypes()
        ));

        // SubscribeURL 호출기 — timeout 을 짧게 두고 redirect 를 따라가지 않는다.
        // webhook 응답이 느려지면 SNS 가 실패로 보고 재시도하므로 넉넉히 잡지 않는다.
        $this->app->bind(SubscriptionConfirmer::class, static fn () => new SubscriptionConfirmer(
            connectTimeout: 2,
            timeout: 4,
        ));
    }

    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            // 명령은 항상 등록한다 — 비활성 상태에서도 status/prune 로 진단할 수 있어야 한다.
            $this->commands([
                SesMonitorStatusCommand::class,
                PruneSesEventLogsCommand::class,
            ]);
        }

        if (! $this->pluginIsActive()) {
            return;
        }

        $this->registerWebhookRoute();
        $this->registerMailListeners();
    }

    /**
     * 공개 webhook 라우트 등록.
     *
     * ── CSRF ────────────────────────────────────────────────────────────────
     * `web` 그룹에만 붙여 두고 이 라우트 하나에서만 `ValidateCsrfToken` 을 제외한다.
     * `bootstrap/app.php` 의 전역 CSRF 예외 목록은 건드리지 않으므로, 면제 범위는
     * 정확히 이 URI 하나다. (같은 방식: sirsoft-tosspayments webhook)
     *
     * TopicArn 이 없으면 라우트 자체를 만들지 않는다 — 설정 전에는 공격 표면이 없다.
     *
     * 운영에서 이 메서드를 부르는 곳은 boot() 하나뿐이라 protected 로 둔다. 테스트는
     * tests/Support/TestableSesMonitorServiceProvider 서브클래스로 접근한다 —
     * 검사 편의 때문에 운영 API 표면을 넓히지 않는다.
     */
    protected function registerWebhookRoute(): void
    {
        if (! SesConfig::webhookEnabled()) {
            return;
        }

        Route::middleware('web')
            ->withoutMiddleware([ValidateCsrfToken::class])
            ->post(SesConfig::endpointPath(), SesWebhookController::class)
            ->name('yutiv.ses-monitor.webhook');
    }

    /**
     * 메일 발송 리스너 등록.
     *
     * 헤더 삽입은 configuration set 이 설정된 경우에만 실제로 동작한다
     * (리스너 안에서 다시 확인 — 설정이 런타임에 바뀌어도 안전).
     */
    private function registerMailListeners(): void
    {
        Event::listen(MessageSending::class, AttachSesConfigurationSet::class);
        Event::listen(MessageSent::class, CaptureSentMessageId::class);
    }

    /**
     * 이 플러그인이 활성 상태인가.
     *
     * 코어가 캐시해 둔 활성 플러그인 목록을 공유한다(추가 쿼리 없음).
     * 조회 자체가 실패하는 부팅 초기·마이그레이션 전 상황에서는 비활성으로 본다 —
     * 공개 endpoint 를 여는 쪽으로 실패하지 않는다.
     */
    protected function pluginIsActive(): bool
    {
        try {
            return in_array($this->pluginIdentifier, self::getActivePluginIdentifiers(), true);
        } catch (\Throwable) {
            return false;
        }
    }
}
