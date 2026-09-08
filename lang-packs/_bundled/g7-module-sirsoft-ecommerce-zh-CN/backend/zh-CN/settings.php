<?php

return [
    'countries' => [
        'KR' => ['name' => '韩国'],
        'US' => ['name' => '美国'],
        'JP' => ['name' => '日本'],
        'CN' => ['name' => '中国'],
        'SG' => ['name' => '新加坡'],
        'HK' => ['name' => '中国香港'],
        'TW' => ['name' => '中国台湾'],
        'VN' => ['name' => '越南'],
        'TH' => ['name' => '泰国'],
        'MY' => ['name' => '马来西亚'],
    ],
    'currencies' => [
        'KRW' => ['name' => 'KRW（韩元）'],
        'USD' => ['name' => 'USD（美元）'],
        'JPY' => ['name' => 'JPY（日元）'],
        'CNY' => ['name' => 'CNY（人民币）'],
        'EUR' => ['name' => 'EUR（欧元）'],
    ],
    'payment_methods' => [
        'card' => [
            'name' => '信用卡',
            'description' => '使用信用卡安全支付',
        ],
        'vbank' => [
            'name' => '虚拟账户',
            'description' => '汇款至虚拟账户',
        ],
        'dbank' => [
            'name' => '银行汇款',
            'description' => '直接汇款至指定账户',
        ],
        'bank' => [
            'name' => '账户转账',
            'description' => '实时账户转账',
        ],
        'phone' => [
            'name' => '手机支付',
            'description' => '手机小额支付',
        ],
        'point' => [
            'name' => '积分支付',
            'description' => '使用累积的积分支付',
        ],
        'deposit' => [
            'name' => '预存款支付',
            'description' => '使用预存款支付',
        ],
        'free' => [
            'name' => '免费',
            'description' => '无需支付即可完成下单',
        ],
    ],
    'banks' => [
        '004' => ['name' => '国民银行'],
        '088' => ['name' => '新韩银行'],
        '020' => ['name' => '友利银行'],
        '081' => ['name' => '韩亚银行'],
        '003' => ['name' => 'IBK企业银行'],
        '011' => ['name' => 'NH农协银行'],
        '071' => ['name' => '邮政局'],
    ],
];
