<?php

namespace Plugins\Yutiv\SesMonitor\Tests\Feature;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Plugins\Yutiv\SesMonitor\Tests\PluginTestCase;

/**
 * 라우트 등록 계약 — "언제 생기고, 언제 안 생기며, 몇 개인가".
 *
 * 공개 endpoint 는 설정 실수 하나로 열려선 안 되므로, 등록 조건을 테스트로 고정한다.
 */
class SesWebhookRouteTest extends PluginTestCase
{
    private const URI = 'webhooks/aws/ses';

    public function test_활성_상태에서_endpoint_가_정확히_1개_등록된다(): void
    {
        // setUp 에서 이미 활성화 + 등록됨. 중복 등록되지 않는지 확인한다.
        $this->assertSame(1, $this->countRoutesForUri(self::URI));
    }

    public function test_여러_번_등록해도_라우트가_늘지_않는다(): void
    {
        // 프로바이더 boot 가 두 번 도는 상황(테스트 재실행 등)을 흉내낸다.
        $this->registerWebhookRoute();
        $this->registerWebhookRoute();

        // Laravel 라우터는 같은 method+uri 를 덮어쓴다 — 누적되면 계약 위반이다.
        $this->assertSame(1, $this->countRoutesForUri(self::URI));
    }

    public function test_플러그인_prefix_라우트가_추가로_생기지_않는다(): void
    {
        $offenders = [];

        foreach ($this->app['router']->getRoutes() as $route) {
            $uri = $route->uri();

            if (str_contains($uri, 'webhooks/aws/ses') && $uri !== self::URI) {
                $offenders[] = $uri;
            }
        }

        $this->assertSame([], $offenders, 'webhook 이 다른 경로로도 노출됩니다: '.implode(', ', $offenders));

        // 플러그인 프리픽스 경로에는 webhook 이 없어야 한다.
        foreach ($this->app['router']->getRoutes() as $route) {
            $this->assertStringNotContainsString(
                'plugins/yutiv-ses_monitor/webhook',
                $route->uri()
            );
            $this->assertStringNotContainsString(
                'api/plugins/yutiv-ses_monitor/webhook',
                $route->uri()
            );
        }
    }

    public function test_TopicArn_이_비면_라우트를_등록하지_않는다(): void
    {
        $before = $this->countRoutesForUri('webhooks/aws/ses-unset-probe');

        // 등록 경로를 다른 URI 로 돌려 "등록 여부" 만 관찰한다 — 이미 등록된
        // 실제 라우트가 결과를 가리지 않게 한다.
        $this->configureSes(['topic_arn' => '', 'endpoint_path' => 'webhooks/aws/ses-unset-probe']);

        $this->testableProvider()->registerRouteIgnoringActiveGate();

        $this->assertSame($before, $this->countRoutesForUri('webhooks/aws/ses-unset-probe'));
        $this->assertSame(0, $this->countRoutesForUri('webhooks/aws/ses-unset-probe'));
    }

    public function test_region_이_비면_라우트를_등록하지_않는다(): void
    {
        $this->configureSes(['region' => '', 'endpoint_path' => 'webhooks/aws/ses-region-probe']);

        $this->testableProvider()->registerRouteIgnoringActiveGate();

        $this->assertSame(0, $this->countRoutesForUri('webhooks/aws/ses-region-probe'));
    }

    public function test_플러그인이_비활성이면_라우트를_등록하지_않는다(): void
    {
        $this->deactivatePlugin();

        $this->configureSes(['endpoint_path' => 'webhooks/aws/ses-inactive-probe']);

        $provider = $this->testableProvider();

        $this->assertFalse($provider->isActive(), '비활성 처리가 반영되지 않았습니다');

        // boot 와 같은 순서: 활성 검사를 통과해야만 등록한다.
        $attempted = $provider->attemptRouteRegistration();

        $this->assertFalse($attempted, '비활성인데 등록을 시도했습니다');
        $this->assertSame(0, $this->countRoutesForUri('webhooks/aws/ses-inactive-probe'));
    }

    public function test_endpoint_에는_CSRF_미들웨어가_붙어_있지_않다(): void
    {
        $route = collect($this->app['router']->getRoutes())
            ->first(fn ($r) => $r->uri() === self::URI && in_array('POST', $r->methods(), true));

        $this->assertNotNull($route, 'webhook 라우트를 찾지 못했습니다');

        $excluded = $route->excludedMiddleware();

        $this->assertContains(ValidateCsrfToken::class, $excluded, 'CSRF 면제가 이 라우트에 적용되지 않았습니다');
    }

    public function test_전역_CSRF_예외_목록에_이_경로가_들어가_있지_않다(): void
    {
        // 면제는 라우트 하나에만 적용되어야 한다 — 전역 except 목록을 늘리지 않았음을 고정한다.
        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));

        $this->assertStringNotContainsString('webhooks/aws/ses', $bootstrap);
        $this->assertStringNotContainsString('validateCsrfTokens', $bootstrap);
    }

    public function test_endpoint_는_POST_만_받는다(): void
    {
        $this->assertSame(1, $this->countRoutesForUri(self::URI, 'POST'));
        $this->assertSame(0, $this->countRoutesForUri(self::URI, 'GET'));
        $this->assertSame(0, $this->countRoutesForUri(self::URI, 'PUT'));
        $this->assertSame(0, $this->countRoutesForUri(self::URI, 'DELETE'));
    }
}
