<?php

return [
    'type' => [
        'income' => '用于个人所得税抵扣',
        'expense' => '用于支出凭证',
    ],
    'identifier_type' => [
        'phone' => '手机号码',
        'card' => '现金收据卡号',
        'business' => '营业执照号码',
    ],
    'transaction_type' => [
        'issue' => '开具',
        'cancel' => '取消',
    ],
    'issue_status' => [
        'in_progress' => '处理中',
        'completed' => '开具完成',
        'failed' => '开具失败',
    ],
    'result_status' => [
        'in_progress' => '处理中',
        'completed' => '完成',
        'failed' => '失败',
    ],
    'shipping_fee_tax_policy' => [
        'proportional' => '按比例分摊（按应税商品比例计税）',
        'taxable' => '全额计税',
        'follow_main_item' => '跟随主商品',
    ],
    'attributes' => [
        'receipt_type' => '开具用途',
        'identifier_type' => '开具方式',
        'identifier' => '识别号码',
    ],
    'validation' => [
        'identifier_invalid' => '识别号码格式不正确。',
        'identifier_type_not_allowed' => ':type 无法通过 :identifier_type 开具。',
        'self_issue_income_only' => '自行开具指定号码只能用于个人所得税抵扣。',
        'identifier_format' => [
            'phone' => '手机号码必须是以 010、011、016、017、018、019 开头的 10~11 位数字。',
            'card' => '现金收据卡号必须是 13~19 位数字。',
            'business' => '营业执照号码不正确。请确认 10 位数字。',
        ],
    ],
    'errors' => [
        'provider_not_configured' => '尚未配置现金收据开具服务商。',
        'no_provider_handled' => '没有服务商处理该现金收据开具请求。',
        'no_issuable_amount' => '没有可开具的现金金额。',
        'identifier_unavailable' => '无法解密重新开具所需的识别号码。需由管理员重新输入识别号码后开具。',
        'payment_not_found' => '找不到支付信息。',
        'not_cash_payment' => '只有银行汇款订单才能开具现金收据。',
        'payment_not_paid' => '只有已确认到账的订单才能开具现金收据。',
        'already_issued' => '该订单已开具过现金收据。',
        'issue_failed' => '开具现金收据失败。',
        'cancel_failed' => '取消现金收据失败。',
        'no_active_receipt' => '没有可取消的现金收据。',
    ],
    'messages' => [
        'issued' => '现金收据已开具。',
        'cancelled' => '现金收据已取消。',
        'reissued' => '现金收据已重新开具。',
        'status_retrieved' => '已获取现金收据信息。',
    ],
];
