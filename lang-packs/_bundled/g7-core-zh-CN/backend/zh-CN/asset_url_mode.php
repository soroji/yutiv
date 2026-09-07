<?php

return [
    'current' => '当前资源 URL 方式：:mode',
    'table' => [
        'in_use' => '使用中',
        'alternative' => '切换后',
    ],
    'diagnose_title' => '若要确认服务器是否拦截了动态响应，请比较以下两个请求：',
    'diagnose_hint' => '如果只有第一个失败而第二个成功，说明静态优化配置拦截了请求 → 请切换为 extensionless。',
    'switch_hint' => '切换：php artisan g7:asset-url-mode extensionless',
    'invalid' => '未知的方式：:mode（extension 或 extensionless）',
    'already' => '已在使用 :mode 方式。',
    'switched' => '资源 URL 方式已从 :from 变更为 :to。',
    'save_failed' => '保存设置失败。',
    'seo_cache_cleared' => '已一并清空 SEO 缓存（因为已生成的资源地址仍使用旧方式）。',
];
