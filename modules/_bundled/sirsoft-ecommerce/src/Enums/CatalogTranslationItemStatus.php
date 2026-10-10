<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

enum CatalogTranslationItemStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
