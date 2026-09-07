<?php

return [
    'fetch_success' => '已成功获取角色信息。',
    'fetch_failed' => '获取角色信息失败。',
    'create_success' => '角色已成功创建。',
    'create_failed' => '创建角色失败。',
    'update_success' => '角色已成功修改。',
    'update_failed' => '修改角色失败。',
    'delete_success' => '角色已成功删除。',
    'delete_failed' => '删除角色失败。',
    'system_role_delete_error' => '无法删除系统角色。',
    'validation' => [
        'name_required' => '角色名称为必填项。',
        'identifier_required' => '标识符为必填项。',
        'identifier_format' => '标识符必须以小写字母开头，且只能使用小写字母、数字和下划线（_）。',
        'identifier_unique' => '该标识符已被使用。',
        'identifier_max' => '标识符最多可输入 100 个字符。',
        'permission_ids_array' => '权限列表必须为数组格式。',
        'permission_ids_exists' => '所选权限中包含无效的权限。',
        'permission_ids_integer' => '权限 ID 必须为整数。',
    ],
    'errors' => [
        'system_role_delete' => '无法删除系统角色。',
        'extension_owned_role_delete' => '无法删除由扩展（模块／插件）拥有的角色。卸载相应扩展后会自动清理。',
    ],
];
