<?php

namespace Modules\Yutiv\PersonalStore\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Yutiv\PersonalStore\Enums\StoreStatus;

class Store extends Model
{
    protected $table = 'personal_stores';

    protected $fillable = ['user_id', 'parent_id', 'slug', 'status', 'logo_url', 'banner_url', 'introduction'];

    protected $casts = ['status' => StoreStatus::class];
}
