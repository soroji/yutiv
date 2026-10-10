<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Modules\Sirsoft\Ecommerce\Models\CatalogTranslationJob;

interface TranslationJobRepositoryInterface
{
    public function find(string $id): CatalogTranslationJob;

    public function createOnce(int $owner, array $data): CatalogTranslationJob;

    public function mutate(string $id, callable $callback): CatalogTranslationJob;

    public function lockOwnerAndCountProcessing(int $owner, string $except): int;
}
