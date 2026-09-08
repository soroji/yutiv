<?php

return [
    'errors' => [
        'payment_failed' => '支付处理失败。请稍后重试。',
    ],
    'refund' => [
        'missing_payment_key' => '由于不存在 Toss Payments 支付密钥，无法进行退款处理。',
        'default_reason' => '应客户要求取消',
        'escrow_partial_not_allowed' => '担保交易支付不支持部分取消。只能进行全部取消。',
        'missing_refund_account' => '要取消虚拟账户支付，需要提供退款接收账户信息（银行、账号、开户人）。',
    ],
    'cash_receipt' => [
        'provider_name' => 'Toss Payments',
        'invalid_order_id' => '现金收据开具标识符不符合 Toss Payments 的格式（英文、数字、-、_ 共 6~64 个字符）。',
        'cancel_reason' => '因订单金额变更而重新开具',
    ],
    'settings_validation' => [
        'vbank_valid_hours_range' => '虚拟账户的到账期限必须在 :min~:max 小时（最长 90 天）之间。',
        'use_escrow_invalid' => '担保交易的使用设置值不正确。',
    ],
];
