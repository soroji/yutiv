<?php

return [
    'bridge_page_title' => '实名认证结果',
    'title' => 'KG Inicis 实名认证',
    'description' => '将 KG Inicis 统合认证的实名认证服务接入 G7 核心实名认证基础设施的插件。',
    'channels' => [
        'ipin' => 'i-PIN',
    ],
    'settings' => [
        'test_mode' => '测试模式',
        'live_mid' => '生产 MID（SRB 前缀）',
        'live_api_key' => '生产 API 密钥',
        'live_mid_attribute' => '生产 MID',
        'live_api_key_attribute' => '生产 API 密钥',
        'duplicate_field' => '重复检查基准',
        'duplicate_block_enabled' => [
            'label' => '拦截重复注册',
            'description' => '启用后，若通过实名认证的用户此前曾用其他邮箱注册过，则拒绝其注册。如果存在家庭共用手机或 B2B 场景等一人需要注册多个账户的情况，请停用此项。无论此设置如何，使用同一邮箱重复注册始终会被拦截（核心默认行为）。',
        ],
    ],
    'card' => [
        'title' => '实名认证信息',
        'method' => '认证方式',
        'method_value' => 'KG Inicis 实名认证',
        'status' => '认证状态',
        'status_verified' => '✓ 已认证',
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
            'label' => '成人认证（仅限 KG Inicis 实名认证）',
            'description' => '请务必仅映射到 KG Inicis provider。邮件／SMS provider 不返回出生日期，无法判定是否成年。映射错误时，可能会向未成年用户展示 19 禁内容。',
        ],
    ],
];
