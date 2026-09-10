<?php

namespace Plugins\Yutiv\LiveCommerce\Database\Seeders;

use Illuminate\Support\Str;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;

/**
 * 초기 라이브 채널 시드.
 *
 * ── 멱등 ───────────────────────────────────────────────────────────────────
 * `slug` 를 키로 `updateOrCreate` 한다. 몇 번을 실행해도 행이 늘지 않는다.
 * `uuid` 는 최초 1회만 만들고 이후에는 건드리지 않는다 — 외부에 노출되는 식별자가
 * 재실행마다 바뀌면 안 되기 때문이다.
 *
 * ── 가짜 계정을 만들지 않는다 ──────────────────────────────────────────────
 * 회원(`teewide_users`)은 시드하지 않는다. 기본 비밀번호를 가진 계정은 그 자체가
 * 취약점이다. 그래서 이 채널의 `owner_user_id` 는 null 로 두고, 실제 판매자가
 * 가입한 뒤 연결한다.
 */
class LiveTenantSeeder
{
    /**
     * Phase 1-B 초기 채널.
     *
     * Phase 1-A 까지 설정에 있던 GolfIF 값을 그대로 옮긴 것이다 — 화면에 보이는
     * 이름·소개·약자가 달라지지 않아야 한다.
     *
     * @var array<int, array<string, mixed>>
     */
    private const TENANTS = [
        [
            'slug' => 'golfif',
            'name' => '골프이프',
            'description' => '골프 용품과 라운드 준비물을 라이브로 소개하는 채널입니다.',
            'initials' => 'GI',
            'status' => LiveTenant::STATUS_ACTIVE,
        ],
    ];

    public function run(): void
    {
        foreach (self::TENANTS as $tenant) {
            LiveTenant::query()->updateOrCreate(
                ['slug' => $tenant['slug']],
                [
                    'name' => $tenant['name'],
                    'description' => $tenant['description'],
                    'initials' => $tenant['initials'],
                    'status' => $tenant['status'],
                    // 이미 있으면 그 값을 유지한다 — 재실행으로 UUID 가 바뀌면
                    // 외부에 알린 식별자가 무효가 된다.
                    'uuid' => LiveTenant::query()->where('slug', $tenant['slug'])->value('uuid')
                        ?? (string) Str::uuid(),
                ]
            );
        }
    }

    /**
     * 시드한 채널만 되돌린다 (마이그레이션 롤백용).
     *
     * 운영자가 나중에 추가한 채널은 건드리지 않는다.
     */
    public function rollback(): void
    {
        LiveTenant::query()
            ->whereIn('slug', array_column(self::TENANTS, 'slug'))
            ->delete();
    }
}
