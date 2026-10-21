<?php

namespace Plugins\Yutiv\LiveCommerce\Models;

use Illuminate\Database\Eloquent\Model;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity;

/** 내부 enum만 테이블을 선택합니다. 요청의 테이블명은 받지 않습니다. */
class LiveRecord extends Model
{
    protected $guarded = [];

    protected $hidden = ['account_number', 'bank_snapshot', 'bank_identity_key', 'delivery', 'token_hash', 'unit_cost', 'purchase_amount', 'receipt_item_id','unit_original','exchange_rate','amount_original','amount_krw','remaining_krw','original_cost_krw'];

    protected function casts(): array
    {
        return ['started_at'=>'datetime','recognized_at'=>'datetime','remaining_quantity'=>'integer','remaining_krw'=>'integer','amount_krw'=>'integer','amount_minor'=>'integer','reversal_of'=>'integer','report_kind'=>'string','customer_visible'=>'boolean','identified_units'=>'array','adjustments'=>'array','baseline_at'=>'datetime','revision'=>'integer','inventory_revision'=>'integer','confirmed_at'=>'datetime','active' => 'boolean', 'primary' => 'boolean', 'default' => 'boolean', 'published' => 'boolean', 'sellable' => 'boolean', 'disputed' => 'boolean',
            'attributes' => 'array', 'items' => 'array', 'received_at' => 'datetime', 'data' => 'array', 'result' => 'array', 'snapshot' => 'array', 'attribution' => 'array', 'host_shares' => 'array', 'bank_snapshot' => 'encrypted:array',
            'account_number' => 'encrypted', 'delivery' => 'encrypted:array',
            'expires_at' => 'datetime', 'reservation_released_at' => 'datetime', 'used_at' => 'datetime', 'paid_at' => 'datetime', 'delivered_at' => 'datetime', 'confirmed_at' => 'datetime',
            'starts_at' => 'datetime', 'ends_at' => 'datetime', 'sale_starts_at' => 'datetime', 'sale_ends_at' => 'datetime'];
    }

    public static function entity(LiveEntity $entity): self
    {
        return (new self)->setTable($entity->value);
    }
}
