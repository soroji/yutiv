<?php

return [
    'mode' => [
        'auto' => '自动（推荐）',
        'composer' => '执行 Composer',
        'bundled' => '使用捆绑 Vendor',
    ],
    'installer' => [
        'checking_bundle' => '正在校验 Vendor 捆绑包...',
        'extracting_bundle' => '正在解压 Vendor 捆绑包（{current}/{total}）',
        'running_composer' => '正在执行 Composer install...',
        'mode_label' => 'Vendor 安装方式',
        'mode_hint' => '在无法使用 Composer 的环境中，请选择捆绑模式。',
    ],
    'build' => [
        'start' => '正在构建 {target}...',
        'success' => '{target} 构建完成（{size}，{packages} packages）',
        'skipped_no_deps' => '已跳过 {target}（无外部 composer 依赖）',
        'skipped_not_installed' => '已跳过 {target}（扩展未安装）',
        'up_to_date' => '{target}: up-to-date',
        'stale' => '{target}: STALE — 需要重新构建',
    ],
];
