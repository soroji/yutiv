<?php

return [
    'image' => [
        'not_found' => '找不到图片。',
    ],
    'upload' => [
        'required' => '需要选择要上传的图片文件。',
        'invalid_file' => '文件无效。',
        'not_image' => '只能上传图片文件。',
        'invalid_mime' => '仅支持 jpeg, jpg, png, gif, webp 格式。',
        'too_large' => '文件大小超出了允许的容量（:max MB）。',
        'forbidden' => '您没有上传图片的权限。',
        'failed' => '图片上传失败。',
    ],
    'uploads' => [
        'not_found' => '找不到上传的图片。',
        'file_delete_failed' => '删除图片文件失败。请稍后重试。',
        'ids_required' => '请选择要删除的图片。',
        'ids_invalid' => '所选图片中包含已不存在的项目。请刷新列表后重新选择。',
        'deleted' => '已删除图片。',
        'bulk_deleted' => '已删除所选的 :deleted 张图片。',
        'bulk_partially_deleted' => '所选图片中已删除 :deleted 张，:failed 张未能删除。删除失败的项目仍保留在列表中。',
    ],
    'cleanup' => [
        'retention_disabled' => '保留期限被设置为不足 1 天，因此未执行清理。',
        'sources_incomplete' => '由于存在处于停用状态的模块，引用判定可能不完整，因此跳过了未被引用图片的清理。请启用或删除该模块后重新执行。',
    ],
];
