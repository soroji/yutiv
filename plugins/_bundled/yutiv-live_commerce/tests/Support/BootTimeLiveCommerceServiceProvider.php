<?php

namespace Plugins\Yutiv\LiveCommerce\Tests\Support;

use Plugins\Yutiv\LiveCommerce\Providers\LiveCommerceServiceProvider;

/**
 * 운영과 같은 **시점**에 부팅되는 테스트용 프로바이더.
 *
 * ── 왜 필요한가 ─────────────────────────────────────────────────────────────
 * 라우트 우선순위는 등록 순서로만 결정된다(RouteCollection 은 먼저 등록된 것부터 훑어
 * 첫 일치를 쓴다). 운영에서는
 *
 *   registerConfiguredProviders()  ← PluginServiceProvider::register() 가 플러그인
 *                                    프로바이더를 등록 (PluginServiceProvider.php:36)
 *   boot()
 *     ├ booting 콜백  ← bootstrap/app.php 의 withRouting() 이 이때서야 라우트
 *     │                 프로바이더를 등록한다 → 프로바이더 목록 **맨 뒤**에 붙는다
 *     └ 프로바이더 boot 루프 → 플러그인 boot 먼저, routes/web.php 로드는 마지막
 *
 * 이라 플러그인 라우트가 SPA catch-all 보다 앞선다. 그런데 테스트가 `$this->app->register()`
 * 를 **앱이 다 부팅된 뒤에** 부르면 순서가 뒤집혀, `routes/web.php:51` 의 무명 catch-all
 * (`/{any?}`, 도메인 제약 없음, fallback 아님)이 먼저 등록돼 있어 언제나 이긴다.
 * 서버 1차 실행의 "기대 teewide.portal, 실제 null" 이 정확히 이 현상이다 —
 * 라우트가 없어서 null 이 아니라, **무명 라우트가 매칭돼서** null 이었다.
 *
 * 그래서 이 서브클래스를 `beforeBootstrapping(BootProviders::class)` 에서 등록해
 * 운영과 같은 위치(코어 프로바이더 등록 이후 · 라우트 프로바이더 boot 이전)에 놓는다.
 *
 * ── 왜 활성 판정만 고정하는가 ───────────────────────────────────────────────
 * 부모의 `pluginIsActive()` 는 `plugins` 테이블을 읽는다. 테스트 앱의 부팅은
 * RefreshDatabase 의 migrate:fresh 보다 **먼저** 일어나므로, 프로세스 첫 테스트에서는
 * 테이블 자체가 없다(`CachesPluginStatus::isPluginTableReady()` → false). 그대로 두면
 * "몇 번째로 실행됐는가" 가 결과를 바꾸는 순서 의존 테스트가 된다.
 *
 * 그래서 **부팅 시점 판정만** 명시값으로 고정한다. 활성 판정 계약 자체(DB 행이
 * inactive 면 라우트를 등록하지 않는다)는 DB 가 준비된 뒤에 도는 TeeWideSafetyTest 가
 * 실제 행으로 검증하므로, 이 고정이 계약을 약화시키지 않는다.
 */
class BootTimeLiveCommerceServiceProvider extends LiveCommerceServiceProvider
{
    /**
     * 부팅 시점 활성 판정.
     *
     * 프로바이더 인스턴스는 컨테이너가 만들므로 테스트가 주입할 수 없다 — 정적 값으로 둔다.
     * `PluginTestCase::createApplication()` 이 앱마다 다시 설정한다.
     */
    public static bool $pluginActive = true;

    protected function pluginIsActive(): bool
    {
        return static::$pluginActive;
    }
}
