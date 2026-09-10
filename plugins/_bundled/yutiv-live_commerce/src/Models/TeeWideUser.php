<?php

namespace Plugins\Yutiv\LiveCommerce\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;

/**
 * TeeWide 회원.
 *
 * YUTIV `App\Models\User` 와 **아무 관계가 없다.** 다른 테이블, 다른 guard(`teewide`),
 * 다른 세션 쿠키를 쓴다. 어느 쪽 로그인도 상대를 인정하지 않는다.
 *
 * @property int $id
 * @property string $uuid
 * @property string $email
 * @property string $name
 * @property string|null $nickname
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property \Illuminate\Support\Carbon|null $last_login_at
 */
class TeeWideUser extends Authenticatable
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_WITHDRAWN = 'withdrawn';

    protected $table = 'teewide_users';

    /**
     * `password` 는 대량 할당 대상이지만, 반드시 해시된 값만 넣는다.
     * 평문이 들어오지 않도록 `hashed` 캐스트를 건다 (아래 casts 참조).
     */
    protected $fillable = [
        'uuid',
        'email',
        'password',
        'name',
        'nickname',
        'status',
        'email_verified_at',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            // Laravel 이 대입 시점에 해시한다 — 평문이 DB 로 가는 경로를 막는 안전망이다.
            // (컨트롤러도 Hash::make 를 쓰지만, 이중으로 해시되지는 않는다)
            'password' => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $user) {
            if (! $user->uuid) {
                $user->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * 로그인을 허용할 상태인가.
     *
     * 정지·탈퇴 계정은 비밀번호가 맞아도 들어올 수 없다.
     */
    public function canAuthenticate(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** 이메일 인증을 마쳤는가. */
    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    /** 화면에 보여 줄 이름. */
    public function displayName(): string
    {
        return $this->nickname ?: $this->name;
    }

    /**
     * 이 회원이 소유한 판매 채널.
     *
     * @return HasMany<LiveTenant, $this>
     */
    public function liveTenants(): HasMany
    {
        return $this->hasMany(LiveTenant::class, 'owner_user_id');
    }

    /**
     * 로그인 이메일 정규화 — 저장과 조회가 같은 규칙을 쓰게 한다.
     *
     * 이 한 곳만 고치면 회원가입·로그인 양쪽이 함께 바뀐다.
     */
    public static function normalizeEmail(?string $email): string
    {
        return Str::lower(trim((string) $email));
    }
}
