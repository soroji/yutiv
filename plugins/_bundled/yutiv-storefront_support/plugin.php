<?php

namespace Plugins\Yutiv\StorefrontSupport;

use App\Extension\AbstractPlugin;

class Plugin extends AbstractPlugin
{
    public function getConfigValues(): array
    {
        return ['hosting' => '', 'verification_url' => ''];
    }

    public function getSettingsSchema(): array
    {
        return [
            'hosting' => [
                'type' => 'string', 'default' => '', 'required' => false,
                'label' => ['ko' => '공개 호스팅 제공자', 'en' => 'Public hosting provider', 'ja' => '公開ホスティング提供者', 'zh-CN' => '公开托管服务商'],
            ],
            'verification_url' => [
                'type' => 'string', 'default' => '', 'required' => false,
                'label' => ['ko' => '사업자정보 확인 HTTPS URL', 'en' => 'Business verification HTTPS URL', 'ja' => '事業者情報確認 HTTPS URL', 'zh-CN' => '企业信息验证 HTTPS URL'],
            ],
        ];
    }
}
