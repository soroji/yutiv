<?php

return [
    'secret_mode' => [
        'disabled' => '不使用',
        'enabled' => '可选使用',
        'always' => '强制使用',
    ],
    'reply_delete_policy' => [
        'block' => '阻止删除（存在回复时无法删除原帖）',
        'cascade' => '一并删除（删除原帖时回复也一并删除）',
    ],
    'order_direction' => [
        'asc' => '升序',
        'desc' => '降序',
    ],
    'board_order_by' => [
        'created_at' => '创建日期',
        'view_count' => '浏览量',
        'title' => '标题',
        'author' => '作者',
    ],
    'report_type' => [
        'post' => '帖子',
        'comment' => '评论',
    ],
    'report_reason_type' => [
        'abuse' => '辱骂／诽谤',
        'hate_speech' => '仇恨言论',
        'spam' => '垃圾信息／广告',
        'copyright' => '侵犯著作权',
        'privacy' => '泄露个人信息',
        'misinformation' => '虚假信息',
        'sexual' => '色情内容',
        'violence' => '暴力内容',
        'other' => '其他',
    ],
    'report_status' => [
        'pending' => '已受理',
        'review' => '审核中',
        'rejected' => '已驳回',
        'suspended' => '已下架',
        'deleted' => '永久删除',
    ],
    'trigger_type' => [
        'report' => '举报处理',
        'admin' => '管理员手动',
        'system' => '系统',
        'auto_hide' => '自动屏蔽',
        'user' => '用户自行删除',
        'cascade' => '帖子删除',
    ],
    'post_status' => [
        'published' => '已发布',
        'blinded' => '已屏蔽',
        'deleted' => '已删除',
    ],
];
