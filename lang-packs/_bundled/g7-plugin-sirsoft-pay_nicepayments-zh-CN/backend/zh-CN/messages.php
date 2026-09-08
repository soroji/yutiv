<?php

return [
    'errors' => [
        'tid_required' => '请输入 TID。',
        'order_not_found' => '找不到订单。',
        'invalid_request' => '请求不正确。',
        'invalid_amount' => '请求金额无效。',
        'vbank_refund_order_not_found' => '找不到要退款的订单。',
        'vbank_refund_invalid_payment' => '这不是可退款的 NICE Payments 虚拟账户到账完成支付。',
        'vbank_refund_amount_mismatch' => '请求的退款金额与数据库中的可取消金额不一致。',
        'vbank_refund_already_processing' => '虚拟账户退款处理已在进行中。',
        'vbank_completed_requires_bank_info' => '虚拟账户到账完成的记录需要退款账户信息。请通过管理员 API 进行退款。',
        'invalid_refund_amount' => '退款金额无效。（请求：:amount）',
    ],
    'refund' => [
        'missing_tid' => '由于没有交易 ID（TID），无法进行退款。',
        'default_reason' => '买家退款申请',
    ],
    'defaults' => [
        'vbank_refund_msg' => '虚拟账户退款',
    ],
    'fields' => [
        'tid' => '交易 ID（TID）',
        'moid' => '订单号',
        'cancel_amt' => '取消金额',
        'cancel_msg' => '取消原因',
        'refund_acct_no' => '退款账号',
        'refund_bank_cd' => '退款银行代码',
        'refund_acct_nm' => '退款开户人',
    ],
];
