<?php

namespace Plugins\Yutiv\ProductImport\Models;

use Illuminate\Database\Eloquent\Model;
use Plugins\Yutiv\ProductImport\Enums\ImportStatus;

class ImportRow extends Model
{
    protected $table = 'yutiv_product_import_rows';

    protected $guarded = [];

    protected $casts = ['payload' => 'array', 'image_urls' => 'array', 'source' => 'array', 'status' => ImportStatus::class];

    public function run()
    {
        return $this->belongsTo(ImportRun::class, 'run_id');
    }
}
