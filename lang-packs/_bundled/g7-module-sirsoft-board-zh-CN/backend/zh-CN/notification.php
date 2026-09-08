<?php

return [
    'new_comment' => [
        'subject' => '[:board_name] “:post_title”帖子有了新评论',
        'greeting' => ':name，您好。',
        'line' => ':comment_author 在“:post_title”帖子中发表了新评论。',
    ],
    'reply_comment' => [
        'subject' => '[:board_name] “:post_title”帖子中我的评论有了回复',
        'greeting' => ':name，您好。',
        'line' => ':comment_author 回复了我在“:post_title”帖子中发表的评论。',
    ],
    'post_reply' => [
        'subject' => '[:board_name] “:post_title”帖子有了回复',
        'greeting' => ':name，您好。',
        'line' => '“:post_title”帖子有了新的回复帖。',
    ],
    'post_action' => [
        'subject' => '[:board_name] “:post_title”帖子已被:action_type处理',
        'greeting' => ':name，您好。',
        'line' => '“:post_title”帖子已被管理员:action_type处理。',
        'action_types' => [
            'blind' => '屏蔽',
            'deleted' => '删除',
            'restored' => '恢复',
        ],
    ],
    'new_post_admin' => [
        'subject' => '[:board_name] 新帖子“:post_title”已发布',
        'greeting' => ':name，您好。',
        'line' => '“:board_name”版块发布了新帖子“:post_title”。',
    ],
    'report_received_admin' => [
        'reason_types' => [
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
    ],
    'report_action' => [
        'target_types' => [
            'post' => '帖子',
            'comment' => '评论',
        ],
        'action_types' => [
            'blind' => '屏蔽',
            'deleted' => '删除',
            'restored' => '驳回（恢复）',
        ],
    ],
    'common' => [
        'view_button' => '查看帖子',
        'regards' => '感谢您的支持',
    ],
];
