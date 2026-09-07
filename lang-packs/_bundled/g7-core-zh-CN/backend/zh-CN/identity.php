<?php

return [
    'providers' => [
        'mail' => [
            'label' => '电子邮件',
            'settings' => [
                'code_length' => '验证码长度',
                'code_length_help' => '发送的数字验证码位数（默认 6，最小 4，最大 10）。',
                'from_address' => '发件人地址',
                'from_address_help' => '留空则使用系统默认发件人。',
            ],
        ],
    ],
    'errors' => [
        'verification_required' => '需要进行实名认证。',
        'challenge_not_found' => '认证请求无效。',
        'wrong_provider' => '此认证请求需由其他服务商处理。',
        'invalid_state' => '该认证请求已处理。',
        'expired' => '认证已超时。请重新尝试。',
        'max_attempts' => '尝试次数已超出限制。请重新申请。',
        'invalid_code' => '验证码不正确。',
        'invalid_verification_token' => '实名认证令牌无效。',
        'missing_target' => '需要认证目标（邮箱／手机号）。',
        'target_mismatch' => '已认证的目标与请求的目标不一致。',
        'purpose_not_supported' => '所选服务商不支持此用途。',
        'provider_unavailable' => '实名认证服务商当前不可用。',
        'generic' => '实名认证失败。',
        'missing_scope_or_target' => '查询策略需要同时提供 scope 和 target。',
        'admin_policy_has_no_default' => '管理员自行创建的策略没有声明默认值。',
        'reset_field_failed' => '恢复声明默认值失败。请确认字段是否有效。',
        'cannot_delete_system_policy' => '无法删除系统声明的策略。只能删除管理员自行创建的策略。',
    ],
    'messages' => [
        'challenge_requested' => '实名认证验证码已发送。',
        'challenge_verified' => '实名认证已完成。',
        'challenge_cancelled' => '实名认证请求已取消。',
    ],
    'logs' => [
        'activity' => [
            'requested' => '已向 :email 发送实名认证验证码。',
            'verified' => '实名认证已完成。',
            'failed' => '实名认证失败。',
            'expired' => '实名认证已超时。',
            'cancelled' => '已取消实名认证请求。',
        ],
    ],
    'purposes' => [
        'signup' => [
            'label' => '注册认证',
            'description' => '确认新注册用户对邮箱／手机号的所有权。',
        ],
        'password_reset' => [
            'label' => '重置密码',
            'description' => '忘记密码的用户在完成身份确认后重置密码。',
        ],
        'self_update' => [
            'label' => '修改个人信息',
            'description' => '已登录用户修改邮箱、手机号等本人信息时。',
        ],
        'sensitive_action' => [
            'label' => '敏感操作',
            'description' => '注销账号、管理员操作等需要重新认证的场景。',
        ],
        'login' => [
            'label' => '登录两步验证',
            'description' => '开启两步验证后，在验证密码之后再增加一道验证。',
        ],
    ],
    'channels' => [
        'email' => '电子邮件',
    ],
    'origin_types' => [
        'route' => '路由',
        'hook' => '钩子',
        'policy' => '策略',
        'middleware' => '中间件',
        'api' => 'API 直接调用',
        'custom' => '自定义',
        'system' => '系统',
    ],
    'policy' => [
        'scope' => [
            'route' => '路由',
            'hook' => '钩子',
            'custom' => '自定义',
        ],
        'fail_mode' => [
            'block' => '拦截（HTTP 428）',
            'log_only' => '仅记录日志',
        ],
        'applies_to' => [
            'self' => '本人',
            'admin' => '管理员',
            'both' => '全部',
        ],
        'source_type' => [
            'core' => '核心',
            'module' => '模块',
            'plugin' => '插件',
            'admin' => '管理员',
        ],
    ],
    'message' => [
        'scope_type' => [
            'provider_default' => 'Provider 默认',
            'purpose' => '按 Purpose',
            'policy' => '按 Policy',
        ],
    ],
];
