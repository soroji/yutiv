<?php

namespace Plugins\Yutiv\SesMonitor\Tests\Support;

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;

/**
 * 기본 스토어만 고장 낸 CacheManager (테스트용).
 *
 * ── 왜 Repository 를 통째로 swap 하면 안 되는가 ─────────────────────────────
 * `Cache::swap(new Repository($brokenStore))` 는 Cache 파사드의 root 를 **Repository** 로
 * 바꾼다. 그런데 코어는 `Cache::store($name)` 을 부른다
 * (`App\Extension\Cache\AbstractCacheDriver::store()`). Repository 에는 `store()` 가 없어
 * `__call` 이 그대로 스토어로 넘기고 → `BrokenCacheStore::store()` 없음 → 치명 오류.
 * 실제로 `ExtensionMiddlewareGate` 가 매 HTTP 요청에서 이 경로를 타 테스트가 통째로 깨졌다.
 *
 * ── 이 클래스가 지키는 계약 ─────────────────────────────────────────────────
 * root 를 **CacheManager 로 유지**하고, 이름 없는 기본 스토어 접근만 고장 낸다.
 *
 *   Cache::add(...)          → __call → store(null)      → **고장난 저장소**
 *   Cache::store('array')    → 이름 지정                  → 진짜 저장소
 *
 * `SubscriptionConfirmer` 는 `Cache::add()/get()/forget()` 만 쓰므로(이름 미지정) 고장을
 * 그대로 받고, `CoreCacheDriver` 는 `config('cache.default')` 이름을 넘겨 부르므로 영향이
 * 없다. 두 경로가 서로 다른 저장소를 본다는 사실은 테스트가 직접 단언한다.
 */
class BrokenDefaultCacheManager extends CacheManager
{
    private Repository $brokenRepository;

    /**
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     */
    public function __construct($app, Repository $brokenRepository)
    {
        parent::__construct($app);

        $this->brokenRepository = $brokenRepository;
    }

    /**
     * 이름을 주지 않은 접근(= 기본 스토어)만 고장난 저장소로 돌린다.
     *
     * @param  string|null  $name
     * @return \Illuminate\Contracts\Cache\Repository
     */
    public function store($name = null)
    {
        if ($name === null) {
            return $this->brokenRepository;
        }

        return parent::store($name);
    }
}
