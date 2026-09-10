<?php

namespace Plugins\Yutiv\LiveCommerce\Support;

use Illuminate\Support\Facades\Log;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;
use Plugins\Yutiv\LiveCommerce\Models\TeeWideUser;

/**
 * 화면에 넘길 데이터를 만드는 ViewModel.
 *
 * ── 권위 소스는 DB 다 ──────────────────────────────────────────────────────
 * Phase 1-B 부터 공개 채널은 `live_tenants` 테이블이 정한다. 설정의 `known_tenants`
 * 는 DB 가 아직 없는 초기 부팅(마이그레이션 전) 대비 fallback 으로만 남는다.
 *
 * ── DB 오류에 fail-open 하지 않는다 ────────────────────────────────────────
 * 조회가 실패하면 **아무 채널도 열지 않는다.** "DB 가 죽었으니 전부 열어 준다" 는
 * 정지·준비 중 채널까지 공개하는 것이므로, 닫는 쪽으로 실패한다.
 *
 * ── 지어내지 않는다 ────────────────────────────────────────────────────────
 * 할인율·시청자 수·주문 수·재고처럼 사실이 아닌 수치는 만들지 않는다. 아직 없는 것은
 * 빈 배열을 돌려주고, 화면이 그것을 정직한 빈 상태로 그린다.
 */
final class TeeWidePresenter
{
    /**
     * 포털 기능 카드.
     *
     * @return array<int, array{title: string, description: string}>
     */
    public static function portalFeatures(): array
    {
        return [
            [
                'title' => '실시간 라이브 판매',
                'description' => '판매자가 직접 방송하며 상품을 소개하고, 시청자는 보는 중에 바로 주문합니다.',
            ],
            [
                'title' => '상품과 방송의 연결',
                'description' => '방송에서 소개하는 상품을 화면에 함께 띄워, 설명과 구매가 끊기지 않게 합니다.',
            ],
            [
                'title' => '판매자별 전용 채널',
                'description' => '판매자마다 고유 주소의 채널을 갖습니다. 단골 고객이 언제든 같은 자리로 찾아옵니다.',
            ],
            [
                'title' => '안전한 주문 경험',
                'description' => '주문과 결제는 검증된 절차를 따릅니다. 방송 중에도 같은 기준으로 처리합니다.',
            ],
        ];
    }

    /**
     * 공개된 판매 채널 목록 (status=active 만).
     *
     * @return array<int, array{slug: string, name: string, description: string, initials: string, status_label: string, url: string}>
     */
    public static function liveChannels(): array
    {
        $channels = [];

        foreach (self::publicTenants() as $tenant) {
            $channels[] = self::presentTenant($tenant);
        }

        return $channels;
    }

    /**
     * 공개된 단일 채널 — 없거나 비공개면 null.
     *
     * 라우트가 이 값으로 200/404 를 가른다.
     *
     * @return array{slug: string, name: string, description: string, initials: string, status_label: string, url: string}|null
     */
    public static function publicChannel(string $slug): ?array
    {
        $tenant = self::findPublicTenant($slug);

        return $tenant === null ? null : self::presentTenant($tenant);
    }

    /**
     * 공개 채널 조회 — DB 가 권위 소스.
     *
     * @return array<int, LiveTenant>
     */
    public static function publicTenants(): array
    {
        try {
            return LiveTenant::query()
                ->public()
                ->orderBy('name')
                ->get()
                ->all();
        } catch (\Throwable $e) {
            self::reportTenantLookupFailure($e);

            // 닫는 쪽으로 실패한다 — 목록을 비운다.
            return [];
        }
    }

    /**
     * 공개 채널 하나를 slug 로 찾는다.
     */
    public static function findPublicTenant(string $slug): ?LiveTenant
    {
        try {
            return LiveTenant::query()
                ->public()
                ->where('slug', $slug)
                ->first();
        } catch (\Throwable $e) {
            self::reportTenantLookupFailure($e);

            // 열어 주지 않는다. 조회에 실패했다는 이유로 비공개 채널이 열리면 안 된다.
            return null;
        }
    }

    /**
     * 모델 → 화면 표시 값.
     *
     * @return array{slug: string, name: string, description: string, initials: string, status_label: string, url: string}
     */
    public static function presentTenant(LiveTenant $tenant): array
    {
        return [
            'slug' => $tenant->slug,
            'name' => $tenant->name,
            'description' => (string) $tenant->description,
            'initials' => $tenant->initials(),
            'status_label' => '방송 준비 중',
            'url' => self::liveUrl().'/'.$tenant->slug,
        ];
    }

    /**
     * 채널 화면의 방송 정보 표.
     *
     * 아직 편성이 없으므로 "미정" 을 명시한다 — 없는 시간을 지어내지 않는다.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public static function broadcastMeta(string $slug): array
    {
        return [
            ['label' => '상태', 'value' => '방송 준비 중'],
            ['label' => '다음 방송', 'value' => '편성 미정'],
            ['label' => '채널 주소', 'value' => '/'.$slug],
        ];
    }

    /**
     * 라이브 상품 목록 — Phase 1-B 에는 아직 데이터 출처가 없다.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function liveProducts(string $slug): array
    {
        return [];
    }

    /** 현재 진행 중인 방송 — 아직 데이터 출처가 없다. */
    public static function onAir(): array
    {
        return [];
    }

    /** 예정된 방송 — 아직 데이터 출처가 없다. */
    public static function upcoming(): array
    {
        return [];
    }

    /**
     * 마이페이지에 보여 줄 회원 정보.
     *
     * 비밀번호 해시·remember token·세션 ID 는 담지 않는다.
     *
     * @return array<string, mixed>
     */
    public static function account(TeeWideUser $user): array
    {
        return [
            'name' => $user->name,
            'display_name' => $user->displayName(),
            'email' => $user->email,
            'joined_at' => $user->created_at?->format('Y년 n월 j일'),
            'email_verified' => $user->hasVerifiedEmail(),
            'email_verified_label' => $user->hasVerifiedEmail() ? '인증 완료' : '미인증',
        ];
    }

    /**
     * 이 회원이 소유한 판매 채널.
     *
     * 소유 채널은 `draft` 여도 본인에게는 보여 준다 — 자기 채널의 준비 상태를 알아야 한다.
     *
     * @return array<int, array{name: string, slug: string, status_label: string, is_public: bool}>
     */
    public static function ownedChannels(TeeWideUser $user): array
    {
        try {
            $tenants = $user->liveTenants()->orderBy('name')->get();
        } catch (\Throwable $e) {
            self::reportTenantLookupFailure($e);

            return [];
        }

        $labels = [
            LiveTenant::STATUS_ACTIVE => '공개 중',
            LiveTenant::STATUS_DRAFT => '준비 중',
            LiveTenant::STATUS_SUSPENDED => '중지됨',
        ];

        $channels = [];

        foreach ($tenants as $tenant) {
            $channels[] = [
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status_label' => $labels[$tenant->status] ?? $tenant->status,
                'is_public' => $tenant->status === LiveTenant::STATUS_ACTIVE,
            ];
        }

        return $channels;
    }

    /**
     * 포털 절대 URL.
     *
     * 라우트 이름 대신 설정된 호스트로 만든다. TeeWide 는 요청 호스트가 두 개(root/live)라
     * `route()` 헬퍼가 현재 요청 호스트를 기준으로 잡아 교차 링크가 틀어질 수 있다.
     */
    public static function portalUrl(): string
    {
        return self::scheme().'://'.TeeWideConfig::rootHost();
    }

    /** 라이브 절대 URL. */
    public static function liveUrl(): string
    {
        return self::scheme().'://'.TeeWideConfig::liveHost();
    }

    /**
     * 링크 스킴.
     *
     * 로컬·테스트에서는 http 로 접근하므로 요청 스킴을 따른다. 운영은 https 이므로
     * 그대로 https 가 된다.
     */
    private static function scheme(): string
    {
        $request = request();

        return $request !== null && $request->isSecure() ? 'https' : 'http';
    }

    /**
     * 채널 조회 실패를 남긴다.
     *
     * 조용히 빈 목록을 돌려주면 "채널이 사라졌다" 는 장애가 원인 없이 묻힌다.
     */
    private static function reportTenantLookupFailure(\Throwable $e): void
    {
        try {
            Log::error('TeeWide 채널 조회 실패 — 공개 채널을 열지 않습니다 (fail-closed)', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
        } catch (\Throwable) {
            // 로깅 실패가 화면을 막지는 않는다.
        }
    }
}
