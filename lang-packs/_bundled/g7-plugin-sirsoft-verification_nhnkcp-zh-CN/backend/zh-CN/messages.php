<?php

return [
    'title' => 'NHN KCP 手机实名认证',
    'description' => '将 NHN KCP 的手机实名认证服务接入 G7 核心实名认证基础设施的插件。',
    'bridge_page_title' => '实名认证结果',
    'channels' => [
        'phone' => '手机',
    ],
    'settings' => [
        'test_mode' => '测试模式',
        'test_site_cd' => '测试站点代码',
        'test_enc_key' => '测试加密密钥',
        'live_site_cd' => '生产站点代码（SM 前缀）',
        'live_enc_key' => '生产加密密钥',
        'web_siteid' => '网站 ID',
        'live_site_cd_attribute' => '生产站点代码',
        'live_enc_key_attribute' => '生产加密密钥',
        'duplicate_field' => '重复检查基准',
        'duplicate_block_enabled' => [
            'label' => '拦截重复注册',
            'description' => '启用后，若通过实名认证的用户此前曾用其他邮箱注册过，则拒绝其注册。如果存在家庭共用手机或 B2B 场景等一人需要注册多个账户的情况，请停用此项。无论此设置如何，使用同一邮箱重复注册始终会被拦截（核心默认行为）。',
        ],
    ],
    'card' => [
        'title' => '实名认证信息',
        'method' => '认证方式',
        'method_value' => 'NHN KCP 手机实名认证',
        'verified_at' => '认证时间',
        'name' => '真实姓名',
        'birthday' => '出生日期',
        'phone' => '手机',
        'is_adult' => [
            'label' => '是否成年',
            'true' => '成年人',
            'false' => '未成年人',
        ],
    ],
    'purposes' => [
        'adult_verification' => [
            'label' => '成人认证（仅限 NHN KCP 实名认证）',
            'description' => '请务必仅映射到 NHN KCP provider。邮件／SMS provider 不返回出生日期，无法判定是否成年。映射错误时，可能会向未成年用户展示 19 禁内容。',
        ],
    ],
];
