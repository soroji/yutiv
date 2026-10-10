<?php

namespace Modules\Sirsoft\Ecommerce\Support;

/** Empty translations follow the same chain as missing translations. */
final class CatalogLocalizedText
{
    public static function resolve(mixed $value, ?string $locale = null): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (! is_array($value)) {
            return '';
        }
        foreach ([$locale ?? app()->getLocale(), config('app.fallback_locale', 'ko'), 'ko', ...array_keys($value)] as $language) {
            if (is_string($value[$language] ?? null) && trim($value[$language]) !== '') {
                return $value[$language];
            }
        }

        return '';
    }
}
