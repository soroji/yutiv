<?php

namespace Plugins\Yutiv\LiveCommerce\Support;

/**
 * 설정 접근 단일 창구.
 *
 * "값이 없으면 무엇이 꺼지는가" 를 여기 한 곳에서 정의한다. 다른 클래스가 `config()` 를
 * 직접 읽지 않게 해서 "기본 비활성" 규칙이 여러 곳으로 흩어지지 않도록 한다.
 */
class TeeWideConfig
{
    const KEY = 'yutiv_live_commerce';

    /**
     * TeeWide 기능 전체 스위치.
     *
     * false 면 라우트·미들웨어·세션 변경이 **하나도** 일어나지 않는다.
     */
    public static function enabled(): bool
    {
        return (bool) config(self::KEY.'.enabled', false);
    }

    public static function rootHost(): string
    {
        return TeeWideHost::canonical((string) config(self::KEY.'.root_host', ''));
    }

    public static function liveHost(): string
    {
        return TeeWideHost::canonical((string) config(self::KEY.'.live_host', ''));
    }

    /**
     * 호스트가 둘 다 설정돼 있고 서로 다른가.
     *
     * 같은 값이면 root/live 판정이 무너지므로 라우트를 등록하지 않는다.
     */
    public static function hostsConfigured(): bool
    {
        $root = self::rootHost();
        $live = self::liveHost();

        return $root !== '' && $live !== '' && $root !== $live;
    }

    /**
     * 라우트·미들웨어를 실제로 붙일 수 있는 상태인가.
     */
    public static function active(): bool
    {
        return self::enabled() && self::hostsConfigured();
    }

    public static function sessionCookie(): string
    {
        $value = trim((string) config(self::KEY.'.session.cookie', ''));

        return $value !== '' ? $value : 'teewide_session';
    }

    public static function sessionDomain(): string
    {
        $value = trim((string) config(self::KEY.'.session.domain', ''));

        return $value !== '' ? $value : '.teewide.com';
    }

    public static function blockStatus(): int
    {
        $value = (int) config(self::KEY.'.block_status', 404);

        return in_array($value, [404, 421], true) ? $value : 404;
    }

    public static function diagnosticsEnabled(): bool
    {
        return (bool) config(self::KEY.'.diagnostics_enabled', false);
    }

    public static function tenantSlugPattern(): string
    {
        $value = (string) config(self::KEY.'.tenant_slug_pattern', '');

        return $value !== '' ? $value : '[a-z0-9][a-z0-9-]{0,62}';
    }

    /**
     * @return array<int, string>
     */
    public static function knownTenants(): array
    {
        $tenants = config(self::KEY.'.known_tenants', []);

        return is_array($tenants) ? array_values(array_filter($tenants, 'is_string')) : [];
    }

    /**
     * tenant 의 화면 표시 정보.
     *
     * ⚠ 이 메서드는 **접근 판정이 아니다.** 여기에 항목이 있다고 해서 채널이 열리지
     *   않는다 — 열림 여부는 `knownTenants()` 만이 정한다. 설정에 없는 slug 도 안전한
     *   기본값을 돌려주므로, 화면이 빈 값으로 깨지지 않는다.
     *
     * @return array{name: string, description: string, initials: string}
     */
    public static function tenantProfile(string $slug): array
    {
        $profiles = config(self::KEY.'.tenant_profiles', []);
        $profile = is_array($profiles) && isset($profiles[$slug]) && is_array($profiles[$slug])
            ? $profiles[$slug]
            : [];

        $name = isset($profile['name']) && is_string($profile['name']) && $profile['name'] !== ''
            ? $profile['name']
            : $slug;

        $description = isset($profile['description']) && is_string($profile['description'])
            ? $profile['description']
            : '';

        $initials = isset($profile['initials']) && is_string($profile['initials']) && $profile['initials'] !== ''
            ? $profile['initials']
            : mb_strtoupper(mb_substr($slug, 0, 2));

        return [
            'name' => $name,
            'description' => $description,
            'initials' => $initials,
        ];
    }

    /**
     * 이 요청 호스트의 TeeWide 역할.
     */
    public static function roleFor($host): string
    {
        return TeeWideHost::role($host, self::rootHost(), self::liveHost());
    }
}
