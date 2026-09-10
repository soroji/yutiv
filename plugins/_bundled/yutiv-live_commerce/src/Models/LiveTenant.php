<?php

namespace Plugins\Yutiv\LiveCommerce\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * 라이브 판매 채널(입점 업체).
 *
 * Phase 1-B 부터 **이 테이블이 공개 채널의 권위 소스**다.
 *
 * @property int $id
 * @property string $uuid
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property string|null $initials
 * @property string $status
 * @property int|null $owner_user_id
 */
class LiveTenant extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    protected $table = 'live_tenants';

    protected $fillable = [
        'uuid',
        'slug',
        'name',
        'description',
        'initials',
        'status',
        'owner_user_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $tenant) {
            if (! $tenant->uuid) {
                $tenant->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * 공개된 채널만.
     *
     * `draft`(준비 중)·`suspended`(정지)는 목록에도 나오지 않고 직접 주소로 와도
     * 404 다 — "존재하지만 막혔다" 를 알려 주면 입점사 목록이 열거된다.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePublic(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * 소유 회원 (아직 주인이 없을 수 있다).
     *
     * @return BelongsTo<TeeWideUser, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(TeeWideUser::class, 'owner_user_id');
    }

    /** 썸네일 약자 — 없으면 slug 앞 두 글자. */
    public function initials(): string
    {
        return $this->initials ?: Str::upper(Str::substr($this->slug, 0, 2));
    }
}
