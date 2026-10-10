<?php

namespace Modules\Sirsoft\Ecommerce\Services\Translation;

interface TranslationProviderInterface
{
    public function configured(): bool;

    /** Return exactly the requested segment IDs mapped to translated plain text. */
    public function translate(array $segments, string $locale): array;
}
