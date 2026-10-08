<?php

namespace Plugins\Yutiv\StorefrontSupport\Services;

use App\Services\ModuleSettingsService;
use App\Services\PluginSettingsService;
use Plugins\Yutiv\StorefrontSupport\Support\PublicBusinessInfo;

/** Read-only projection through the existing settings services; no persistence or migrations. */
final class BusinessInfoService
{
    public function __construct(
        private ModuleSettingsService $moduleSettings,
        private PluginSettingsService $pluginSettings,
    ) {}

    public function get(): array
    {
        $basic = $this->moduleSettings->get('sirsoft-ecommerce', 'basic_info', []);
        $basic = is_array($basic) ? $basic : [];
        $number = array_map(fn ($part) => trim((string) ($basic['business_number_'.$part] ?? '')), [1, 2, 3]);

        return PublicBusinessInfo::project([
            'company' => $basic['company_name'] ?? null,
            'representative' => $basic['ceo_name'] ?? null,
            'business_number' => count(array_filter($number)) === 3 ? implode('-', $number) : null,
            'mail_order_number' => $basic['mail_order_number'] ?? null,
            'address' => trim(implode(' ', array_filter([$basic['base_address'] ?? '', $basic['detail_address'] ?? '']))),
            'phone' => ! empty($basic['phone_1']) && ! empty($basic['phone_2']) && ! empty($basic['phone_3'])
                ? implode('-', [$basic['phone_1'], $basic['phone_2'], $basic['phone_3']]) : null,
            'email' => ! empty($basic['email_id']) && ! empty($basic['email_domain'])
                ? $basic['email_id'].'@'.$basic['email_domain'] : null,
            'hosting' => $this->pluginSettings->get('yutiv-storefront_support', 'hosting'),
            'verification_url' => $this->pluginSettings->get('yutiv-storefront_support', 'verification_url'),
        ]);
    }
}
