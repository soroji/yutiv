<?php

return [
    'refund' => [
        'missing_tid' => '由于没有交易编号（tno），无法进行退款。',
        'default_reason' => '买家退款申请',
        'in_progress' => 'NHN KCP 退款已在处理中。',
    ],
    'escrow' => [
        'invoice_required' => '请输入运单号。',
        'courier_required' => '请选择物流公司。',
    ],
    'errors' => [
        'payment_failed' => '支付处理失败。请稍后重试。',
        'wsdl_missing' => '缺少 KCP WSDL 文件：:file',
        'approval_key_error' => 'KCP 授权密钥错误 [:code]：:message',
        'soap_error' => 'KCP SOAP 对接错误：:message',
        'cli_binary_missing' => '缺少 KCP CLI 二进制文件：:path',
        'cli_binary_not_executable' => 'KCP CLI 二进制文件缺少执行权限 — 需要运营者处理（sudo chmod 755 :path）',
    ],
];
