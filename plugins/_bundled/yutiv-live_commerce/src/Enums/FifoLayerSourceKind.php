<?php

namespace Plugins\Yutiv\LiveCommerce\Enums;

enum FifoLayerSourceKind: string
{
    case Opening = 'opening';
    case Receipt = 'receipt';
    case Unknown = 'unknown';
    case LegacyReturn = 'legacy_return';
}
