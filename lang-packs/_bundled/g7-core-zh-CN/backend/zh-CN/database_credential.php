<?php

return [
    'title' => '数据库账号配置错误',
    'message' => '数据库账号配置存在问题，无法显示站点。',
    'description' => '在此状态下，模块、插件和模板将无法加载。请按照以下说明修改配置。',
    'blocked_reason' => '出于安全考虑，不能使用 root 等数据库最高权限账号。该账号一旦泄露，整个数据库都将面临风险。',
    'empty_reason' => '数据库用户名为空。配置可能缺失或已损坏。',
    'recovery_guide' => '请将 .env 文件中的 DB_WRITE_USERNAME 值改为本站点专用的数据库账号，然后执行 php artisan config:clear 命令。',
];
