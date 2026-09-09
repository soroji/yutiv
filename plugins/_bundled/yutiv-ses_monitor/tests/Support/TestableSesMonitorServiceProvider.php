<?php

namespace Plugins\Yutiv\SesMonitor\Tests\Support;

use Plugins\Yutiv\SesMonitor\Providers\SesMonitorServiceProvider;

/**
 * 테스트 전용 서브클래스 — 프로바이더의 등록 판단을 직접 호출하기 위한 것.
 *
 * ── 왜 서브클래스인가 ───────────────────────────────────────────────────────
 * `registerWebhookRoute()` 와 `pluginIsActive()` 는 운영에서 `boot()` 한 곳에서만
 * 쓰인다. 검사 편의를 위해 운영 API 표면을 넓히는 대신, 테스트 쪽에서 가시성만
 * 열어 준다. 로직은 부모 그대로라 검사 대상과 실행 대상이 갈라지지 않는다.
 */
class TestableSesMonitorServiceProvider extends SesMonitorServiceProvider
{
    /**
     * 부팅과 같은 순서로 라우트 등록을 시도한다 (활성 검사 → 등록).
     *
     * @return bool 실제로 등록을 시도했는지 (활성 게이트 통과 여부)
     */
    public function attemptRouteRegistration(): bool
    {
        if (! $this->pluginIsActive()) {
            return false;
        }

        $this->registerWebhookRoute();

        return true;
    }

    /**
     * 활성 게이트를 건너뛰고 등록 조건(설정)만 시험한다.
     */
    public function registerRouteIgnoringActiveGate(): void
    {
        $this->registerWebhookRoute();
    }

    public function isActive(): bool
    {
        return $this->pluginIsActive();
    }
}
