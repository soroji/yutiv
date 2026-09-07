<?php

return [
    'types' => [
        'module' => '模块',
        'plugin' => '插件',
        'template' => '模板',
    ],
    'errors' => [
        'core_version_mismatch' => ':extension（:type）要求 Gnuboard7 核心版本 :required 及以上。（当前：:installed）',
        'version_check_failed' => '版本校验失败。',
        'operation_in_progress' => '“:name”存在正在进行的操作（:status），无法处理此请求。',
        'zip_missing_manifest' => '在 ZIP 内找不到 :file 清单文件：:zip',
        'zip_invalid_manifest' => '无法将 ZIP 内的 :file 清单文件解析为 JSON。',
        'zip_identifier_mismatch' => 'ZIP 清单文件中的标识符与目标扩展不一致。（期望：:expected，实际：:actual）',
        'zip_missing_version' => 'ZIP 内的 :file 清单文件中缺少 version 字段。',
        'not_found' => '找不到扩展（:identifier）。',
        'cascade_dependency_failed' => '同时安装的 :type（:identifier）安装失败：:message',
        'invalid_type' => '扩展类型无效。',
        'not_auto_deactivated' => '此扩展并非因核心版本兼容性问题而被自动停用。',
        'hidden_extension' => '内部使用（hidden）的扩展不对用户展示。',
    ],
    'warnings' => [
        'auto_deactivated' => ':type “:identifier”因核心版本兼容性问题已被自动停用。',
    ],
    'alerts' => [
        'incompatible_deactivated' => ':type “:name”已自动停用',
        'incompatible_message' => '所需版本：:required，当前已安装：:installed',
        'recovered_title' => ':type “:name”已恢复兼容',
        'recovered_body' => '核心升级后已兼容（此前要求：:previously_required）。您可以重新启用。',
        'recovered_success' => '扩展已重新启用。',
        'dismissed' => '已关闭通知。',
        'auto_deactivated_listed' => '这是自动停用的扩展列表。',
        'recover_action' => '重新启用',
        'dismiss_action' => '关闭通知',
    ],
    'badges' => [
        'incompatible' => '需要升级核心',
        'incompatible_tooltip' => '需要核心 :required 及以上（当前：:installed）',
        'incompatible_sr' => ':name 需要核心 :required 及以上，但当前安装的是 :installed，因此无法更新。',
    ],
    'banner' => [
        'title' => '有扩展因核心兼容性问题被自动停用',
        'item_required' => '所需版本：:required',
        'guide_link' => '核心升级指南',
        'dismiss' => '关闭',
    ],
    'update_modal' => [
        'compat_warning_title' => '核心版本兼容性警告',
        'compat_warning_message' => '此 :type 需要核心 :required 及以上。（当前：:installed）',
        'compat_guide_link' => '查看核心升级指南',
        'force_label' => '忽略警告并强制更新（不推荐）',
    ],
    'commands' => [
        'clear_cache_success' => '扩展版本校验缓存已删除。',
    ],
];
