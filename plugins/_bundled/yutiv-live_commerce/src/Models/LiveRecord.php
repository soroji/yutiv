<?php

namespace Plugins\Yutiv\LiveCommerce\Models;

use Illuminate\Database\Eloquent\Model;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity;

/** 내부 enum만 테이블을 선택합니다. 요청의 테이블명은 받지 않습니다. */
class LiveRecord extends Model
{
    protected $guarded = [];

    protected $hidden = ['account_number', 'bank_snapshot', 'delivery', 'token_hash'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'primary' => 'boolean', 'default' => 'boolean', 'published' => 'boolean', 'sellable' => 'boolean', 'disputed' => 'boolean',
            'data' => 'array', 'result' => 'array', 'snapshot' => 'array', 'attribution' => 'array', 'host_shares' => 'array', 'bank_snapshot' => 'encrypted:array',
            'account_number' => 'encrypted', 'delivery' => 'encrypted:array',
            'expires_at' => 'datetime', 'reservation_released_at' => 'datetime', 'used_at' => 'datetime', 'paid_at' => 'datetime', 'delivered_at' => 'datetime', 'confirmed_at' => 'datetime',
            'starts_at' => 'datetime', 'ends_at' => 'datetime', 'sale_starts_at' => 'datetime', 'sale_ends_at' => 'datetime'];
    }

    public static function entity(LiveEntity $entity): self
    {
        return (new self)->setTable($entity->value);
    }
}
