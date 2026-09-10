<?php

namespace Plugins\Yutiv\LiveCommerce\Support;

use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Support\Facades\Auth;
use Plugins\Yutiv\LiveCommerce\Models\TeeWideUser;

/**
 * TeeWide 인증 진입점 — **오직 `teewide` guard 만** 쓴다.
 *
 * ── 왜 헬퍼로 감싸는가 ─────────────────────────────────────────────────────
 * `auth()->user()` / `Auth::user()` 는 **기본 guard**(YUTIV `web`)를 본다. TeeWide 코드가
 * 그중 하나라도 쓰면 그 순간 YUTIV 로그인 사용자가 TeeWide 사용자로 인정된다.
 * 한 곳에서만 guard 이름을 말하도록 모아 두면, 실수로 기본 guard 를 쓰는 코드가
 * 리뷰와 정적 검사에서 바로 눈에 띈다.
 *
 * 반대 방향도 마찬가지다 — 이 guard 는 `teewide_users` 테이블만 보므로
 * TeeWide 로그인 사용자가 YUTIV 사용자로 인정될 수 없다.
 */
final class TeeWideAuth
{
    public const GUARD = 'teewide';

    public const PROVIDER = 'teewide_users';

    /**
     * TeeWide 세션 guard.
     */
    public static function guard(): StatefulGuard
    {
        /** @var StatefulGuard $guard */
        $guard = Auth::guard(self::GUARD);

        return $guard;
    }

    /**
     * 로그인한 TeeWide 회원 (없으면 null).
     */
    public static function user(): ?TeeWideUser
    {
        $user = self::guard()->user();

        return $user instanceof TeeWideUser ? $user : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    /**
     * guard/provider 설정 — 플러그인 프로바이더가 부팅 때 주입한다.
     *
     * 기존 `config/auth.php` 를 **덮어쓰지 않는다.** 점 표기로 두 키만 더한다:
     * `auth.guards.teewide`, `auth.providers.teewide_users`.
     * YUTIV 의 `web` guard 와 `users` provider 는 그대로 남는다.
     *
     * 설정 파일이 아니라 런타임에 넣으므로 `config:cache` 환경에서도 동작한다
     * (캐시된 배열 위에 부팅 시점마다 다시 얹힌다).
     *
     * @return array<string, mixed>
     */
    public static function configValues(): array
    {
        return [
            'auth.guards.'.self::GUARD => [
                'driver' => 'session',
                'provider' => self::PROVIDER,
            ],
            'auth.providers.'.self::PROVIDER => [
                'driver' => 'eloquent',
                'model' => TeeWideUser::class,
            ],
        ];
    }
}
