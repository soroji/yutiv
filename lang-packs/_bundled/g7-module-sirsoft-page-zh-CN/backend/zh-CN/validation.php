<?php

return [
    'slug' => [
        'required' => '页面别名为必填项。',
        'format' => '页面别名只能使用英文小写字母、数字和连字符（-）。',
        'unique' => '该别名已被使用。',
        'max' => '别名不能超过 :max 个字符。',
    ],
    'title' => [
        'required' => '页面标题为必填项。',
        'max' => '页面标题不能超过 :max 个字符。',
        'locale_required' => '默认语言（:locale）的标题为必填项。',
    ],
    'content' => [
        'max' => '页面正文过长。',
    ],
    'content_mode' => [
        'in' => '正文格式必须为 html 或 text 中的一种。',
    ],
    'published' => [
        'boolean' => '是否发布必须为 true 或 false。',
        'required' => '是否发布为必填项。',
    ],
    'ids' => [
        'required' => '请选择要处理的页面。',
        'array' => '页面列表格式不正确。',
        'min' => '请至少选择 :min 个页面。',
        'integer' => '页面 ID 必须为整数。',
        'exists' => '所选页面中包含不存在的项目。',
    ],
    'per_page' => [
        'integer' => '每页条数必须为数字。',
        'min' => '每页条数至少为 :min 个。',
        'max' => '每页条数不能超过 :max 个。',
    ],
    'search' => [
        'max' => '搜索词最多可输入 :max 个字符。',
    ],
    'attributes' => [
        'search' => '搜索词',
    ],
    'search_field' => [
        'in' => '搜索字段必须为 all, title, slug 中的一种。',
    ],
    'sort_by' => [
        'in' => '排序基准必须为 created_at, published_at 中的一种。',
    ],
    'sort_order' => [
        'in' => '排序方向必须为 asc 或 desc。',
    ],
    'temp_key' => [
        'string' => '临时键格式不正确。',
        'max' => '临时键不能超过 :max 个字符。',
    ],
    'attachment' => [
        'file' => [
            'required' => '请选择文件。',
            'file' => '不是正确的文件格式。',
            'max' => '文件大小不能超过 :maxKB。',
            'mimes' => '不允许的文件格式。',
            'mimetypes' => '不允许的文件格式。',
        ],
        'order' => [
            'required' => '顺序信息为必填项。',
            'array' => '顺序信息格式不正确。',
        ],
    ],
];
