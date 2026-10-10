<?php

namespace Modules\Sirsoft\Ecommerce\Enums;

enum TranslationConnectionStatus: string
{
    case Missing = 'config_missing';
    case Disabled = 'disabled';
    case Incomplete = 'not_configured';
    case Ready = 'ready';

    public static function detect(mixed $configuration, bool $configured): self
    {
        if (! is_array($configuration) || ! isset($configuration['driver'])) {
            return self::Missing;
        }
        if ($configuration['driver'] === 'disabled') {
            return self::Disabled;
        }

        return $configured ? self::Ready : self::Incomplete;
    }
}
