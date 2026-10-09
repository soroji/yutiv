<?php

namespace Plugins\Yutiv\ProductImport\Models;

use Illuminate\Database\Eloquent\Model;
use Plugins\Yutiv\ProductImport\Enums\ImportStatus;

class ImportRun extends Model
{
    protected $table = 'yutiv_product_import_runs';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['errors' => 'array', 'status' => ImportStatus::class];

    public function rows()
    {
        return $this->hasMany(ImportRow::class, 'run_id');
    }
}
