<?php

namespace Modules\Yutiv\PersonalStore\Models;

use Illuminate\Database\Eloquent\Model;

class LineSnapshot extends Model
{
    protected $table = 'personal_store_line_snapshots';

    protected $guarded = [];

    public const UPDATED_AT = null;

    protected $casts = ['payload' => 'array'];

    public static function booted(): void
    {
        // 원본은 갱신하지 않습니다. 이벤트 상태·회수액은 별도 테이블에 저장합니다.
        static::updating(fn () => throw new \LogicException(__('yutiv-personal_store::messages.immutable')));
        static::deleting(fn () => throw new \LogicException(__('yutiv-personal_store::messages.immutable')));
    }
}
