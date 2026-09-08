<?php

return [
    'errors' => [
        'cash_receipt_issue_failed' => '开具现金收据失败。',
        'order_not_found' => '找不到订单。',
        'cbt_failed' => '海外支付处理失败。',
        'payment_failed' => '支付处理失败。请稍后重试。',
    ],
    'cash_receipt' => [
        'provider_name' => 'KG Inicis',
    ],
    'escrow' => [
        'invoice_required' => '请输入运单号。',
        'courier_required' => '请选择物流公司。',
        'default_confirmer' => '管理员',
    ],
    'refund' => [
        'missing_tid' => '由于没有交易 ID（TID），无法进行退款。',
        'default_reason' => '买家退款申请',
    ],
    'cbt_connectivity' => [
        'checked' => '连接诊断已完成。',
        'check_failed' => '连接诊断失败。',
    ],
    'cbt_reconciliation' => [
        'not_retryable' => '这不是可重试的 CBT 退款待处理记录。',
        'retry_success' => 'CBT 退款重试已完成。',
        'retry_failed' => 'CBT 退款重试失败。',
    ],
    'settings_validation' => [
        'test_japan_sign_key_required' => '要使用日本支付（CBT），需要测试用日本 CBT 哈希密钥。',
        'live_japan_mid_required' => '在生产模式下使用日本支付（CBT）需要生产日本 MID。',
        'live_japan_sign_key_required' => '在生产模式下使用日本支付（CBT）需要生产日本 CBT 哈希密钥。',
        'japan_merchant_name_required' => '需要填写在生产日本支付窗口中显示的商户名称。',
        'japan_merchant_name_kana_required' => '需要填写在生产日本支付窗口中显示的商户名称 Kana。',
        'japan_merchant_name_alphabet_required' => '需要填写在生产日本支付窗口中显示的英文商户名称。',
        'japan_merchant_name_short_required' => '需要填写在生产日本支付窗口中显示的商户简称。',
        'japan_contact_name_required' => '需要填写在生产日本支付窗口中显示的咨询处名称。',
        'japan_contact_email_required' => '需要填写在生产日本支付窗口中显示的咨询邮箱。',
        'japan_contact_phone_required' => '需要填写在生产日本支付窗口中显示的咨询电话号码。',
        'japan_contact_opening_hours_required' => '需要填写在生产日本支付窗口中显示的咨询营业时间。',
        'replace_sample_value' => '在生产模式下，请将日本商户展示信息的示例值替换为实际的签约信息。',
    ],
    'defaults' => [
        'good_name' => '商品',
    ],
    'cbt_cvs' => [
        'simulate_success' => '到账模拟已完成。',
        'simulate_failed' => '到账模拟失败。',
        'expire_success' => '虚拟账户的到账期限已按过期处理。',
        'recheck_success' => '已重新确认到账状态。',
        'not_test_mode' => '仅可在测试模式下使用。',
        'not_waiting_deposit' => '只能处理处于待付款状态的支付。',
        'not_expirable' => '这是无法进行过期处理的支付。',
        'not_cvs' => '这不是便利店／虚拟账户支付。',
    ],
];
