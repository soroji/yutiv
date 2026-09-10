<?php

namespace Plugins\Yutiv\LiveCommerce\Support;

/**
 * 화면에 넘길 데이터를 만드는 ViewModel.
 *
 * ── 왜 이 클래스가 따로 있는가 ─────────────────────────────────────────────
 * Phase 1-A 에는 아직 `live_tenants` 같은 테이블이 없다. 그렇다고 Blade 안에 문구를
 * 흩뿌리면 Phase 1-B 에서 DB 로 옮길 때 화면마다 다시 찾아다녀야 한다. 그래서 화면이
 * 쓰는 값은 전부 여기서 만들고, 나중에 이 클래스의 내부만 DB 조회로 바꾼다.
 *
 * ── 지어내지 않는다 ────────────────────────────────────────────────────────
 * 할인율·시청자 수·주문 수·재고처럼 사실이 아닌 수치는 만들지 않는다. 아직 없는 것은
 * "준비 중" 이라고 말한다 — 빈 배열을 돌려주고, 화면이 그것을 정직한 빈 상태로 그린다.
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
     * 라이브 홈에 노출할 판매 채널 목록.
     *
     * 지금은 `known_tenants` 가 유일한 출처다 — 라우트가 인정하는 tenant 와 화면에 보이는
     * 채널이 어긋나면 "보이는데 눌러도 404" 가 되므로, 같은 값에서 만든다.
     *
     * @return array<int, array{slug: string, name: string, description: string, initials: string, status_label: string, url: string}>
     */
    public static function liveChannels(): array
    {
        $channels = [];

        foreach (TeeWideConfig::knownTenants() as $slug) {
            $channels[] = self::channel($slug);
        }

        return $channels;
    }

    /**
     * 단일 채널의 표시 정보.
     *
     * @return array{slug: string, name: string, description: string, initials: string, status_label: string, url: string}
     */
    public static function channel(string $slug): array
    {
        $profile = TeeWideConfig::tenantProfile($slug);

        return [
            'slug' => $slug,
            'name' => $profile['name'],
            'description' => $profile['description'],
            'initials' => $profile['initials'],
            'status_label' => '방송 준비 중',
            'url' => self::liveUrl().'/'.$slug,
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
     * 라이브 상품 목록 — Phase 1-A 에는 데이터 출처가 없다.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function liveProducts(string $slug): array
    {
        return [];
    }

    /** 현재 진행 중인 방송 — Phase 1-A 에는 데이터 출처가 없다. */
    public static function onAir(): array
    {
        return [];
    }

    /** 예정된 방송 — Phase 1-A 에는 데이터 출처가 없다. */
    public static function upcoming(): array
    {
        return [];
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
}
