<?php

namespace Plugins\Yutiv\LiveCommerce\Tests\Support;

use Plugins\Yutiv\LiveCommerce\Providers\LiveCommerceServiceProvider;
use Plugins\Yutiv\LiveCommerce\Support\TeeWideConfig;

/**
 * 운영과 같은 **단계**에 등록되는 테스트용 프로바이더.
 *
 * ── 왜 필요한가 ─────────────────────────────────────────────────────────────
 * 라우트 우선순위는 등록 순서로만 갈린다. `routes/web.php:51` 의 SPA catch-all 은
 * 무명이고 fallback 도 아니며 도메인 제약도 없어 `/` 와 `/{tenant}` 를 모든 호스트에서
 * 삼킨다. 그보다 **앞서** 등록되지 않으면 TeeWide 도메인 라우트는 영영 매칭되지 않는다.
 *
 * ── 등록 경로: RegisterProviders::merge() ───────────────────────────────────
 * `Illuminate\Foundation\Bootstrap\RegisterProviders` 는 부트스트랩 시
 * `mergeAdditionalProviders()` 로 `static::$merge` 를 `config('app.providers')` 에 합친
 * 뒤 `registerConfiguredProviders()` 를 부른다(v12.62.0 소스). 즉 여기 넣은 프로바이더는
 * **코어 프로바이더와 같은 목록·같은 단계**에서 등록된다. Testbench 의 `getPackageProviders()`
 * 도 이 경로를 쓴다 — 이 프로젝트에는 Testbench 가 설치돼 있지 않아 직접 호출한다.
 *
 * 그 자리가 왜 충분히 이른가:
 *   RegisterProviders  → 이 프로바이더 register()  ← 여기
 *   BootProviders → Application::boot()
 *     ├ booting 콜백 → `withRouting()` 이 **이때서야** AppRouteServiceProvider 를 등록
 *     │                (ApplicationBuilder::withRouting() 172-174행) → 목록 맨 뒤
 *     └ 프로바이더 boot 루프 → 이 프로바이더 boot(라우트 등록) → … → 맨 뒤의
 *                              AppRouteServiceProvider boot → routes/web.php 적재
 *
 * 이전 구현은 `beforeBootstrapping(BootProviders::class)` 이벤트 훅을 썼는데, 서버 런타임
 * 결과(TeeWide #336 > catch-all #327)는 그 훅으로 등록한 라우트가 **routes/web.php 뒤에**
 * 붙었음을 보여준다. 이벤트 훅 대신 프레임워크가 제공하는 프로바이더 목록 자체를 쓰면
 * 그 불확실성이 사라진다. 실제 호출 순서는 아래 `$trace` 가 서버에서 기록한다.
 *
 * ── 왜 활성 판정과 설정만 고정하는가 ────────────────────────────────────────
 * 부모의 `pluginIsActive()` 는 `plugins` 테이블을 읽는다. 테스트 앱 부팅은 RefreshDatabase
 * 의 migrate:fresh 보다 **먼저** 일어나 프로세스 첫 테스트에서는 테이블이 없다. 그대로 두면
 * 실행 순서가 결과를 바꾸는 테스트가 된다. 그래서 부팅 시점 판정만 명시값으로 고정한다.
 * 활성 판정 계약 자체(DB 행이 inactive 면 라우트를 등록하지 않는다)는 DB 가 준비된 뒤에
 * 도는 TeeWideSafetyTest 가 실제 행으로 검증하므로 계약이 약해지지 않는다.
 */
class BootTimeLiveCommerceServiceProvider extends LiveCommerceServiceProvider
{
    /**
     * 부팅 시점 활성 판정.
     *
     * 프로바이더 인스턴스는 컨테이너가 만들므로 주입할 수 없다 — 정적 값으로 둔다.
     * `PluginTestCase::createApplication()` 이 앱마다 다시 설정한다.
     */
    public static bool $pluginActive = true;

    /**
     * 부팅 시점에 심을 TeeWide 설정. `null` 이면 손대지 않는다.
     *
     * `register()` 에서 심는 이유: 부모의 `mergeConfigFrom()` 은 이미 있는 키를 덮지
     * 않으므로, 그보다 먼저 넣으면 이 값이 이긴다. 이벤트 훅 없이 config → register →
     * boot 순서가 프로바이더 한 곳에서 결정된다.
     *
     * @var array<string, mixed>|null
     */
    public static ?array $config = null;

    /**
     * 생명주기 추적 기록 — **사람이 읽는 용도 전용.**
     *
     * ⚠ 어떤 판정도 이 문자열을 근거로 삼지 않는다. 문자열은 손으로 써넣을 수 있어
     *   "trace 에 그런 줄이 있으니 순서가 맞다" 는 증명이 되지 못한다. 판정은 아래
     *   구조화된 카운터·스냅샷과 살아 있는 라우터/프로바이더 레지스트리로만 한다.
     *
     * @var array<int, string>
     */
    public static array $trace = [];

    /** register() 실행 횟수 — 정확히 1이어야 한다(0=미등록, 2=중복 등록). */
    public static int $registerCount = 0;

    /** boot() 실행 횟수 — 정확히 1이어야 한다. */
    public static int $bootCount = 0;

    /** boot() 진입 시점의 전체 라우트 수. */
    public static ?int $routeCountAtBootStart = null;

    /**
     * boot() 종료 시점의 전체 라우트 수.
     *
     * 이 값이 bootstrap 완료 시점의 라우트 수보다 **작아야** 늦은 등록이 아니다 —
     * 부팅이 끝난 뒤 등록했다면 두 값이 같아진다.
     */
    public static ?int $routeCountAtBootEnd = null;

    /**
     * boot() 종료 시점에 존재한 TeeWide 라우트 이름 스냅샷.
     *
     * 나중에 남아 있는 라우트가 "그때 그 라우트" 인지 대조하는 데 쓴다.
     *
     * @var array<int, string>
     */
    public static array $routeNamesAtBootEnd = [];

    public static function resetTestState(): void
    {
        static::$pluginActive = true;
        static::$config = null;
        static::$trace = [];
        static::$registerCount = 0;
        static::$bootCount = 0;
        static::$routeCountAtBootStart = null;
        static::$routeCountAtBootEnd = null;
        static::$routeNamesAtBootEnd = [];
    }

    public function register(): void
    {
        static::$registerCount++;
        static::$trace[] = 'provider.register 진입 #'.static::$registerCount
            .' (라우트 '.$this->currentRouteCount().'개)';

        if (static::$config !== null) {
            // 부모의 mergeConfigFrom 보다 먼저 — 그래야 이 값이 파일 기본값을 이긴다.
            $this->app['config']->set(TeeWideConfig::KEY, static::$config);
        }

        parent::register();
    }

    public function boot(): void
    {
        static::$bootCount++;
        static::$routeCountAtBootStart = $this->currentRouteCount();

        static::$trace[] = 'provider.boot 진입 #'.static::$bootCount
            .' (라우트 '.static::$routeCountAtBootStart.'개)'
            .' pluginActive='.var_export(static::$pluginActive, true)
            .' TeeWideConfig::active()='.var_export(TeeWideConfig::active(), true);

        parent::boot();

        static::$routeCountAtBootEnd = $this->currentRouteCount();
        static::$routeNamesAtBootEnd = $this->teeWideRouteNames();

        static::$trace[] = 'provider.boot 종료 (라우트 '.static::$routeCountAtBootEnd.'개, TeeWide '
            .implode(', ', static::$routeNamesAtBootEnd).')';
    }

    /**
     * 현재 라우터에 올라와 있는 TeeWide 라우트 이름들.
     *
     * @return array<int, string>
     */
    private function teeWideRouteNames(): array
    {
        $names = [];

        try {
            foreach ($this->app['router']->getRoutes() as $route) {
                $name = $route->getName();
                if (is_string($name) && str_starts_with($name, 'teewide.')) {
                    $names[] = $name;
                }
            }
        } catch (\Throwable) {
            return [];
        }

        sort($names);

        return $names;
    }

    protected function pluginIsActive(): bool
    {
        return static::$pluginActive;
    }

    private function currentRouteCount(): int
    {
        try {
            return count($this->app['router']->getRoutes()->getRoutes());
        } catch (\Throwable) {
            return -1;
        }
    }
}
