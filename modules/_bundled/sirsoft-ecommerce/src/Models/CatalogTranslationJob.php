<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use Illuminate\Database\Eloquent\Model;

class CatalogTranslationJob extends Model
{
    protected $table = 'ecommerce_translation_jobs';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['items' => 'array', 'terms' => 'array', 'cancelled' => 'boolean'];
}
