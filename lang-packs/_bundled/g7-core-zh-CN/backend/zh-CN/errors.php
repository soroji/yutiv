<?php

return [
    'back_home' => '返回首页',
    '401' => [
        'title' => '需要登录认证',
        'message' => '访问此页面需要先登录。',
    ],
    '403' => [
        'title' => '访问被拒绝',
        'message' => '您没有访问此页面的权限。',
    ],
    '404' => [
        'title' => '找不到页面',
        'message' => '您请求的页面不存在或已被移动。',
    ],
    '500' => [
        'title' => '服务器发生错误',
        'message' => '处理请求时出现问题。请稍后重试。',
    ],
    '503' => [
        'title' => '服务暂时中断',
        'message' => '当前服务不可用。请稍后重试。',
        'unmet_dependencies' => '未满足的依赖列表',
        'template' => '模板',
        'modules' => '模块',
        'plugins' => '插件',
        'contact_admin' => '如果问题持续存在，请联系管理员。',
    ],
    'bootstrap' => [
        'title' => '页面加载失败',
        'message' => '网络连接可能不稳定。请刷新后重试。',
        'reload' => '刷新',
        'incompatible_title' => '此浏览器无法显示页面',
        'incompatible_message' => '浏览器版本过旧，无法运行本站点。请将浏览器更新到最新版本，或改用其他浏览器访问。',
    ],
];
