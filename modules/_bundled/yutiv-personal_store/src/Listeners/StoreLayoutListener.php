<?php

namespace Modules\Yutiv\PersonalStore\Listeners;

use App\Contracts\Extension\HookListenerInterface;

class StoreLayoutListener implements HookListenerInterface
{
    public static function getSubscribedHooks(): array
    {
        return ['core.layout.filter_parent_data' => ['method' => 'parentData', 'type' => 'filter']];
    }

    public function handle(...$args): void {}

    public function parentData(array $parent, array $child): array
    {
        if (($child['meta']['personal_store_detail'] ?? false) !== true || ($child['extends'] ?? null) !== 'shop/show') {
            return $parent;
        }
        foreach ($parent['data_sources'] ?? [] as $index => $source) {
            if ($source['id'] === 'product') {
                $parent['data_sources'][$index]['endpoint'] = '/api/modules/yutiv-personal_store/stores/{{route.slug}}/products/{{route.product_code}}';
            }
        }

        return $parent;
    }
}
