<?php

return [
    'inquiries' => [
        'content' => [
            'required' => '请输入咨询内容。',
            'min' => '咨询内容至少需输入 :min 个字符。',
            'max' => '咨询内容最多可输入 :max 个字符。',
        ],
        'reply_content' => [
            'required' => '请输入回复内容。',
            'min' => '回复内容至少需输入 1 个字符。',
            'max' => '回复内容最多可输入 5000 个字符。',
        ],
    ],
    'settings' => [
        'key_required' => '设置键为必填项。',
        'value_present' => '设置值为必填项。',
    ],
    'shipping_carrier' => [
        'code_required' => '物流公司代码为必填项。',
        'code_unique' => '该物流公司代码已被使用。',
        'code_format' => '物流公司代码只能使用英文小写字母、数字和连字符。',
        'name_required' => '物流公司名称为必填项。',
        'type_required' => '物流公司类型为必填项。',
        'type_invalid' => '物流公司类型只能为国内（domestic）或国际（international）。',
    ],
    'list' => [
        'per_page_max' => '单次最多可查询 :max 条。',
        'page' => [
            'integer' => '页码必须为数字。',
            'min' => '页码必须为 1 以上。',
        ],
        'per_page' => [
            'integer' => '每页条数必须为数字。',
            'min' => '每页条数必须为 :min 以上。',
            'max' => '每页条数必须为 :max 以下。',
        ],
        'sort' => [
            'string' => '排序基准必须为字符串。',
            'in' => '请选择正确的排序基准。',
        ],
        'sort_by' => [
            'string' => '排序字段必须为字符串。',
            'in' => '请选择正确的排序字段。',
        ],
        'sort_order' => [
            'string' => '排序方式必须为字符串。',
            'in' => '排序方式必须为 asc 或 desc。',
        ],
        'search' => [
            'string' => '搜索词必须为字符串。',
            'max' => '搜索词最多可输入 :max 个字符。',
        ],
        'search_field' => [
            'string' => '搜索字段必须为字符串。',
            'in' => '请选择正确的搜索字段。',
        ],
        'search_keyword' => [
            'string' => '搜索关键词必须为字符串。',
            'max' => '搜索关键词最多可输入 :max 个字符。',
        ],
        'category_id' => [
            'integer' => '分类 ID 必须为数字。',
        ],
        'no_category' => [
            'boolean' => '未设置分类筛选必须为 true 或 false。',
        ],
        'date_type' => [
            'in' => '请选择正确的日期类型。',
        ],
        'start_date' => [
            'date' => '开始日期必须为日期格式。',
        ],
        'end_date' => [
            'date' => '结束日期必须为日期格式。',
            'after_or_equal' => '结束日期必须晚于或等于开始日期。',
        ],
        'sales_status' => [
            'array' => '销售状态必须为数组格式。',
            'in' => '请选择正确的销售状态。',
        ],
        'display_status' => [
            'in' => '请选择正确的展示状态。',
        ],
        'brand_id' => [
            'integer' => '品牌 ID 必须为数字。',
        ],
        'no_brand' => [
            'boolean' => '未设置品牌筛选必须为 true 或 false。',
        ],
        'tax_status' => [
            'in' => '请选择正确的计税方式。',
        ],
        'price_type' => [
            'in' => '请选择正确的价格类型。',
        ],
        'min_price' => [
            'integer' => '最低价格必须为数字。',
            'min' => '最低价格必须为 0 以上。',
        ],
        'max_price' => [
            'integer' => '最高价格必须为数字。',
            'min' => '最高价格必须为 0 以上。',
        ],
        'min_stock' => [
            'integer' => '最少库存必须为数字。',
        ],
        'max_stock' => [
            'integer' => '最多库存必须为数字。',
        ],
        'shipping_policy_id' => [
            'integer' => '配送政策 ID 必须为数字。',
        ],
        'with_options' => [
            'boolean' => '是否包含选项必须为 true 或 false。',
        ],
        'is_active' => [
            'boolean' => '启用状态必须为 true 或 false。',
            'in' => '启用状态的值不正确。',
        ],
        'active_only' => [
            'boolean' => '仅显示启用项的筛选选项必须为 true 或 false。',
        ],
        'locale' => [
            'string' => '语言代码必须为字符串。',
            'in' => '不支持的语言。',
        ],
        'region' => [
            'string' => '地区必须为字符串。',
            'max' => '地区最多可输入 :max 个字符。',
        ],
        'parent_id' => [
            'exists' => '上级分类不存在。',
        ],
        'hierarchical' => [
            'boolean' => '是否使用树形结构必须为 true 或 false。',
        ],
        'flat' => [
            'boolean' => '是否使用平铺列表必须为 true 或 false。',
        ],
        'max_depth' => [
            'integer' => '最大层级必须为数字。',
            'min' => '最大层级必须为 :min 以上。',
            'max' => '最大层级必须为 :max 以下。',
        ],
        'target_type' => [
            'string' => '适用对象类型必须为字符串。',
            'in' => '请选择正确的适用对象类型。',
        ],
        'discount_type' => [
            'string' => '优惠类型必须为字符串。',
            'in' => '请选择正确的优惠类型。',
        ],
        'issue_status' => [
            'string' => '发放状态必须为字符串。',
            'in' => '请选择正确的发放状态。',
        ],
        'issue_method' => [
            'string' => '发放方式必须为字符串。',
            'in' => '请选择正确的发放方式。',
        ],
        'issue_condition' => [
            'string' => '发放条件必须为字符串。',
            'in' => '请选择正确的发放条件。',
        ],
        'min_benefit_amount' => [
            'numeric' => '最小优惠金额必须为数字。',
            'min' => '最小优惠金额必须为 0 以上。',
        ],
        'max_benefit_amount' => [
            'numeric' => '最大优惠金额必须为数字。',
            'min' => '最大优惠金额必须为 0 以上。',
        ],
        'min_order_amount' => [
            'numeric' => '最低订单金额必须为数字。',
            'min' => '最低订单金额必须为 0 以上。',
        ],
        'created_start_date' => [
            'date' => '登记开始日期必须为日期格式。',
        ],
        'created_end_date' => [
            'date' => '登记结束日期必须为日期格式。',
            'after_or_equal' => '登记结束日期必须晚于或等于开始日期。',
        ],
        'valid_start_date' => [
            'date' => '有效开始日期必须为日期格式。',
        ],
        'valid_end_date' => [
            'date' => '有效结束日期必须为日期格式。',
            'after_or_equal' => '有效结束日期必须晚于或等于开始日期。',
        ],
        'issue_start_date' => [
            'date' => '发放开始日期必须为日期格式。',
        ],
        'issue_end_date' => [
            'date' => '发放结束日期必须为日期格式。',
            'after_or_equal' => '发放结束日期必须晚于或等于开始日期。',
        ],
        'shipping_methods' => [
            'array' => '配送方式必须为数组格式。',
            'string' => '配送方式必须为字符串。',
            'in' => '请选择正确的配送方式。',
        ],
        'charge_policies' => [
            'array' => '计费政策必须为数组格式。',
            'string' => '计费政策必须为字符串。',
            'in' => '请选择正确的计费政策。',
        ],
        'countries' => [
            'array' => '配送国家／地区必须为数组格式。',
            'string' => '配送国家／地区必须为字符串。',
            'in' => '请选择正确的配送国家／地区。',
            'max' => '配送国家／地区代码最多可输入 :max 个字符。',
        ],
    ],
    'category_required' => '请选择分类。',
    'category_min' => '请至少选择 1 个分类。',
    'category_max' => '分类最多可选择 5 个。',
    'options_required' => '请至少添加 1 个选项。',
    'options_min' => '请至少添加 1 个选项。',
    'selling_price_lte_list' => '售价不能高于原价。',
    'option_selling_price_lte_list' => '选项售价不能高于原价。',
    'product' => [
        'mileage_percent_max' => '按比例的积分累积率不能超过 100%。',
        'selling_price_lte_list' => '售价不能高于原价。',
        'price_min' => '价格必须大于 0。',
        'attributes' => [
            'name' => '商品名称',
            'product_code' => '商品编码',
            'list_price' => '原价',
            'selling_price' => '售价',
            'stock_quantity' => '库存数量',
            'safe_stock_quantity' => '安全库存数量',
            'option_list_price' => '选项原价',
            'option_selling_price' => '选项售价',
            'option_price_adjustment' => '选项价格调整额',
            'option_stock_quantity' => '选项库存数量',
            'option_name' => '选项名称',
            'option_code' => '选项编码',
        ],
        'name' => [
            'required' => '请输入商品名称。',
        ],
        'allowed_roles' => [
            'required_when_restricted' => '选择限制购买对象时，请至少选择 1 个允许的角色。',
        ],
        'name_primary' => [
            'required' => '默认语言的商品名称为必填项。',
        ],
        'product_code' => [
            'required' => '请输入商品编码。',
            'unique' => '该商品编码已被使用。',
        ],
        'list_price' => [
            'required' => '请输入原价。',
            'min' => '原价必须为 1 以上。',
        ],
        'selling_price' => [
            'required' => '请输入售价。',
            'min' => '售价必须为 1 以上。',
            'lte' => '售价必须小于或等于原价。',
        ],
        'stock_quantity' => [
            'required' => '库存数量为必填项。',
        ],
        'sales_status' => [
            'required' => '销售状态为必填项。',
            'in' => '销售状态无效。',
        ],
        'display_status' => [
            'required' => '展示状态为必填项。',
            'in' => '展示状态无效。',
        ],
        'tax_status' => [
            'required' => '计税状态为必填项。',
            'in' => '计税状态无效。',
        ],
        'category_ids' => [
            'required' => '请至少选择 1 个分类。',
            'min' => '请至少选择 1 个分类。',
            'max' => '分类最多可选择 5 个。',
        ],
        'options' => [
            'required' => '商品选项为必填项。',
            'min' => '请至少添加 1 个商品选项。',
            'option_code' => [
                'required_with' => '选项编码为必填项。',
            ],
            'option_name' => [
                'required_with' => '选项名称为必填项。',
            ],
            'option_values' => [
                'required_with' => '选项值为必填项。',
            ],
            'list_price' => [
                'required_with' => '选项原价为必填项。',
            ],
            'selling_price' => [
                'required_with' => '选项售价为必填项。',
            ],
            'stock_quantity' => [
                'required_with' => '选项库存数量为必填项。',
            ],
        ],
        'additional_options' => [
            'values' => [
                'required_with' => '每个附加选项组请至少登记 1 个选择项。',
                'min' => '每个附加选项组请至少登记 1 个选择项。',
                'max' => '每个附加选项组最多可登记 :max 个选择项。',
                'name' => [
                    'required' => '请输入选择项名称。',
                ],
                'price_adjustment' => [
                    'min' => '追加费用必须为 0 以上。',
                ],
            ],
            'name' => [
                'required_with' => '请输入附加选项组名称。',
            ],
            'max' => '附加选项组最多可登记 :max 个。',
        ],
        'label_assignments' => [
            'label_id' => [
                'required' => '请选择标签。',
                'exists' => '标签不存在。',
            ],
            'end_date' => [
                'after_or_equal' => '结束日期必须晚于或等于开始日期。',
            ],
        ],
        'shipping_policy_id' => [
            'exists' => '配送政策不存在。',
        ],
        'common_info_id' => [
            'exists' => '通用信息不存在。',
        ],
        'use_main_image_for_og' => [
            'boolean' => 'OG 图片设置必须为真／假值。',
        ],
        'invalid_sales_status' => '销售状态不正确。',
        'invalid_display_status' => '展示状态不正确。',
        'bulk' => [
            'ids_required' => '请选择要变更的商品。',
            'ids_min' => '请至少选择 1 件商品。',
            'product_not_found' => '商品不存在。',
            'option_not_found' => '选项不存在。',
        ],
    ],
    'option' => [
        'bulk' => [
            'ids_required' => '请选择要变更的选项。',
            'ids_min' => '请至少选择 1 个选项。',
            'invalid_id_format' => '选项 ID 格式不正确。',
        ],
    ],
    'bulk' => [
        'ids_required' => '请选择要变更的商品。',
        'method_required' => '请选择变更方式。',
        'value_required' => '请输入变更值。',
    ],
    'bulk_option_price' => [
        'ids_required' => '请选择要变更的商品或选项。',
        'product_ids' => [
            'required' => '请选择要变更的商品。',
            'min' => '请至少选择 1 件商品。',
        ],
        'method' => [
            'required' => '请选择变更方式。',
            'in' => '请选择正确的变更方式。',
        ],
        'value' => [
            'required' => '请输入变更值。',
            'integer' => '变更值必须为数字。',
            'min' => '变更值必须为 0 以上。',
        ],
        'unit' => [
            'required' => '请选择单位。',
            'in' => '请选择正确的单位。',
        ],
    ],
    'bulk_option_stock' => [
        'ids_required' => '请选择要变更的商品或选项。',
        'product_ids' => [
            'required' => '请选择要变更的商品。',
            'min' => '请至少选择 1 件商品。',
        ],
        'method' => [
            'required' => '请选择变更方式。',
            'in' => '请选择正确的变更方式。',
        ],
        'value' => [
            'required' => '请输入变更值。',
            'integer' => '变更值必须为数字。',
            'min' => '变更值必须为 0 以上。',
        ],
    ],
    'preset' => [
        'name_required' => '请输入预设名称。',
        'name_exists' => '同名的预设已存在。',
        'conditions_required' => '请输入搜索条件。',
    ],
    'brand' => [
        'name_required' => '请输入品牌名称。',
        'slug_required' => '请输入别名（slug）。',
        'slug_unique' => '该别名（slug）已被使用。',
        'slug_format' => '别名（slug）必须以英文小写字母开头，且只能使用英文小写字母、数字和连字符（-）。',
        'website_invalid_url' => 'URL 格式不正确。',
    ],
    'label' => [
        'name_required' => '请输入标签名称。',
        'color_required' => '请输入标签颜色。',
        'color_invalid' => '标签颜色必须为 #RRGGBB 格式。',
    ],
    'category' => [
        'name_required' => '请输入分类名称。',
        'slug_required' => '请输入别名（slug）。',
        'slug_unique' => '该别名（slug）已被使用。',
        'slug_format' => '别名（slug）必须以英文小写字母开头，且只能使用英文小写字母、数字和连字符（-）。',
        'parent_not_found' => '找不到上级分类。',
        'parent_id' => [
            'self' => '不能将自身指定为上级分类。',
            'circular' => '不能将下级分类指定为上级分类。',
        ],
    ],
    'product_images' => [
        'file' => [
            'required' => '请选择图片文件。',
            'file' => '不是有效的文件。',
            'image' => '只能上传图片文件。',
            'mimes' => '支持的图片格式：jpeg, png, jpg, gif, webp',
            'max' => '图片文件大小必须在 10MB 以下。',
        ],
        'temp_key' => [
            'string' => '临时键必须为字符串。',
            'max' => '临时键最多可输入 64 个字符。',
        ],
        'collection' => [
            'in' => '请选择正确的集合。',
            'enum' => '请选择正确的集合。（main, detail, additional）',
        ],
        'alt_text' => [
            'array' => '替代文本必须为数组格式。',
        ],
        'orders' => [
            'required' => '请输入排序顺序。',
            'array' => '排序顺序必须为数组格式。',
            'min' => '请至少输入 1 个排序顺序。',
            'item' => [
                'required' => '请输入排序顺序值。',
                'integer' => '排序顺序必须为数字。',
                'min' => '排序顺序必须为 0 以上。',
            ],
        ],
    ],
    'category_images' => [
        'file' => [
            'required' => '请选择图片文件。',
            'file' => '不是有效的文件。',
            'image' => '只能上传图片文件。',
            'mimes' => '支持的图片格式：jpeg, png, jpg, gif, svg, webp',
            'max' => '图片文件大小必须在 10MB 以下。',
        ],
        'temp_key' => [
            'string' => '临时键必须为字符串。',
            'max' => '临时键最多可输入 64 个字符。',
        ],
        'collection' => [
            'in' => '请选择正确的集合。',
        ],
        'alt_text' => [
            'array' => '替代文本必须为数组格式。',
        ],
        'orders' => [
            'required' => '请输入排序顺序。',
            'array' => '排序顺序必须为数组格式。',
            'min' => '请至少输入 1 个排序顺序。',
            'item' => [
                'required' => '请输入排序顺序值。',
                'integer' => '排序顺序必须为数字。',
                'min' => '排序顺序必须为 0 以上。',
            ],
        ],
    ],
    'product_notice_template' => [
        'name_required' => '请输入商品类别名称。',
        'name_max' => '商品类别名称最多可输入 100 个字符。',
        'fields_required' => '请至少添加 1 个项目。',
        'fields_min' => '请至少添加 1 个项目。',
        'field_name_required' => '请输入项目名称。',
        'field_name_min' => '请输入项目名称。',
        'field_name_max' => '项目名称最多可输入 200 个字符。',
        'field_content_required' => '请输入内容。',
        'field_content_min' => '请输入内容。',
        'field_content_max' => '内容最多可输入 2000 个字符。',
    ],
    'coupon' => [
        'name' => [
            'required' => '请输入优惠券名称。',
        ],
        'name_ko' => [
            'required' => '请输入韩语优惠券名称。',
        ],
        'coupon_code' => [
            'required' => '请输入优惠券码。',
            'unique' => '该优惠券码已被使用。',
        ],
        'target_type' => [
            'required' => '请选择适用对象。',
            'in' => '请选择正确的适用对象。',
        ],
        'discount_type' => [
            'required' => '请选择优惠类型。',
            'in' => '请选择正确的优惠类型。',
        ],
        'discount_value' => [
            'required' => '请输入优惠值。',
            'numeric' => '优惠值必须为数字。',
            'min' => '优惠值必须为 0 以上。',
        ],
        'max_discount_amount' => [
            'numeric' => '最大优惠金额必须为数字。',
            'min' => '最大优惠金额必须为 0 以上。',
        ],
        'min_order_amount' => [
            'numeric' => '最低订单金额必须为数字。',
            'min' => '最低订单金额必须为 0 以上。',
        ],
        'max_issue_count' => [
            'integer' => '最大发放数量必须为整数。',
            'min' => '最大发放数量必须为 0 以上。',
        ],
        'max_use_count_per_user' => [
            'integer' => '每人最大使用次数必须为整数。',
            'min' => '每人最大使用次数必须为 0 以上。',
        ],
        'valid_from' => [
            'date' => '有效开始日期必须为日期格式。',
        ],
        'valid_until' => [
            'date' => '有效结束日期必须为日期格式。',
            'after_or_equal' => '有效结束日期必须晚于或等于有效开始日期。',
        ],
        'issue_start_at' => [
            'date' => '发放开始日期必须为日期格式。',
        ],
        'issue_end_at' => [
            'date' => '发放结束日期必须为日期格式。',
            'after_or_equal' => '发放结束日期必须晚于或等于发放开始日期。',
        ],
        'issue_status' => [
            'required' => '请选择发放状态。',
            'in' => '请选择正确的发放状态。',
        ],
        'issue_method' => [
            'in' => '请选择正确的发放方式。',
        ],
        'issue_condition' => [
            'in' => '请选择正确的发放条件。',
        ],
        'combinable' => [
            'boolean' => '是否可叠加使用必须为 true 或 false。',
        ],
        'products' => [
            'array' => '适用商品必须为数组格式。',
        ],
        'categories' => [
            'array' => '适用分类必须为数组格式。',
        ],
        'ids' => [
            'required' => '请选择要变更的优惠券。',
            'array' => '优惠券 ID 必须为数组格式。',
            'min' => '请至少选择 1 张优惠券。',
        ],
        'name_required' => '请输入优惠券名称。',
        'target_type_required' => '请选择适用对象。',
        'discount_type_required' => '请选择优惠类型。',
        'discount_value_required' => '请输入优惠值。',
        'discount_value_rate_min' => '优惠比例必须为 1% 以上。',
        'discount_value_rate_max' => '优惠比例必须为 100% 以下。',
        'discount_value_fixed_min' => '优惠值必须为 1 韩元以上。',
        'issue_method_required' => '请选择发放方式。',
        'issue_condition_required' => '请选择发放条件。',
        'valid_type_required' => '请选择有效期类型。',
        'valid_days_required' => '请输入有效天数。',
        'valid_from_required' => '请输入有效开始日期。',
        'valid_to_required' => '请输入有效结束日期。',
        'valid_to_after_from' => '有效结束日期必须晚于或等于有效开始日期。',
        'ids_required' => '请选择要变更的优惠券。',
        'ids_min' => '请至少选择 1 张优惠券。',
        'issue_status_required' => '请选择发放状态。',
        'issue_status_invalid' => '请选择正确的发放状态。',
        'id_required' => '优惠券 ID 为必填项。',
        'id_integer' => '优惠券 ID 必须为整数。',
        'id_not_found' => '优惠券不存在。',
        'target_products_required' => '请至少选择 1 件适用商品。',
        'target_categories_required' => '请至少选择 1 个适用分类。',
        'user_ids_required' => '请选择要发放的会员。',
        'user_ids_min' => '请至少选择 1 名会员。',
        'user_ids_invalid' => '其中包含不存在的会员。',
    ],
    'orders' => [
        'ids' => [
            'required' => '请选择要变更的订单。',
            'array' => '订单 ID 必须为数组格式。',
            'min' => '请至少选择 1 笔订单。',
            'exists' => '订单不存在。',
        ],
        'order_status' => [
            'required' => '请选择订单状态。',
            'array' => '订单状态必须为数组格式。',
            'string' => '订单状态必须为字符串。',
            'in' => '请选择正确的订单状态。',
        ],
        'carrier_id' => [
            'required' => '请选择物流公司。',
            'exists' => '物流公司不存在。',
        ],
        'tracking_number' => [
            'required' => '请输入运单号。',
            'string' => '运单号必须为字符串。',
            'max' => '运单号最多可输入 50 个字符。',
            'requires_status' => '输入运单号时，请同时变更配送状态。',
        ],
        'admin_memo' => [
            'max' => '管理员备注最多可输入 2000 个字符。',
        ],
        'recipient_name' => [
            'required' => '请输入收件人姓名。',
            'max' => '收件人姓名最多可输入 50 个字符。',
        ],
        'recipient_phone' => [
            'required_without' => '手机号码或固定电话号码必须填写其中一项。',
            'max' => '收件人联系方式最多可输入 20 个字符。',
        ],
        'recipient_tel' => [
            'required_without' => '固定电话号码或手机号码必须填写其中一项。',
            'max' => '收件人电话号码最多可输入 20 个字符。',
        ],
        'recipient_zipcode' => [
            'required' => '请输入邮政编码。',
            'max' => '邮政编码最多可输入 10 个字符。',
        ],
        'recipient_address' => [
            'required' => '请输入基本地址。请使用邮政编码搜索功能。',
            'max' => '基本地址最多可输入 255 个字符。',
        ],
        'recipient_detail_address' => [
            'required' => '请输入详细地址。',
            'max' => '详细地址最多可输入 255 个字符。',
        ],
        'delivery_memo' => [
            'max' => '配送备注最多可输入 500 个字符。',
        ],
        'recipient_country_code' => [
            'size' => '国家／地区代码必须为 2 位。',
        ],
        'email' => [
            'required' => '请输入邮箱地址。',
            'email' => '请输入正确的邮箱地址。',
            'max' => '邮箱地址最多可输入 255 个字符。',
        ],
        'email_message' => [
            'required' => '请输入邮件内容。',
            'max' => '邮件内容最多可输入 5000 个字符。',
        ],
        'not_found' => '找不到订单。',
        'cannot_update' => '该订单无法修改。',
        'cannot_cancel' => '该订单无法取消。',
        'cannot_refund' => '该订单无法退款。',
        'carrier_required' => '要变更为该状态，请先选择物流公司。',
        'tracking_number_required' => '要变更为该状态，请先输入运单号。',
        'status_transition' => [
            'invalid' => '无法从 :from 状态变更为 :to 状态。',
            'bulk_invalid' => '部分项目（:count 件）无法变更为 :to 状态。（当前状态：:from）',
        ],
        'bulk_update' => [
            'at_least_one' => '请至少输入订单状态、物流公司、运单号中的一项。',
        ],
        'search_field' => [
            'in' => '请选择正确的搜索字段。',
        ],
        'member_type' => [
            'in' => '请选择正确的会员类别。',
        ],
        'search_keyword' => [
            'string' => '搜索词必须为字符串。',
            'max' => '搜索词最多可输入 200 个字符。',
        ],
        'date_type' => [
            'in' => '请选择正确的日期类型。',
        ],
        'start_date' => [
            'date' => '开始日期必须为日期格式。',
        ],
        'end_date' => [
            'date' => '结束日期必须为日期格式。',
            'after_or_equal' => '结束日期必须晚于或等于开始日期。',
        ],
        'option_status' => [
            'array' => '选项状态必须为数组格式。',
            'string' => '选项状态必须为字符串。',
            'in' => '请选择正确的选项状态。',
        ],
        'shipping_type' => [
            'array' => '配送类型必须为数组格式。',
            'string' => '配送类型必须为字符串。',
            'in' => '请选择正确的配送类型。',
        ],
        'payment_method' => [
            'array' => '支付方式必须为数组格式。',
            'string' => '支付方式必须为字符串。',
            'in' => '请选择正确的支付方式。',
        ],
        'category_id' => [
            'integer' => '分类 ID 必须为数字。',
        ],
        'min_amount' => [
            'integer' => '最小金额必须为数字。',
            'min' => '最小金额必须为 0 以上。',
        ],
        'max_amount' => [
            'integer' => '最大金额必须为数字。',
            'min' => '最大金额必须为 0 以上。',
        ],
        'min_shipping_amount' => [
            'integer' => '最低运费必须为数字。',
            'min' => '最低运费必须为 0 以上。',
        ],
        'max_shipping_amount' => [
            'integer' => '最高运费必须为数字。',
            'min' => '最高运费必须为 0 以上。',
        ],
        'shipping_policy_id' => [
            'integer' => '配送政策 ID 必须为数字。',
        ],
        'country_codes' => [
            'array' => '国家／地区代码必须为数组格式。',
            'string' => '国家／地区代码必须为字符串。',
            'size' => '国家／地区代码必须为 2 位。',
        ],
        'order_device' => [
            'array' => '下单设备必须为数组格式。',
            'string' => '下单设备必须为字符串。',
            'in' => '请选择正确的下单设备。',
        ],
        'user_id' => [
            'integer' => '会员 ID 必须为数字。',
        ],
        'orderer_uuid' => [
            'uuid' => '下单人 UUID 格式不正确。',
        ],
        'sort_by' => [
            'in' => '请选择正确的排序字段。',
        ],
        'sort_order' => [
            'in' => '排序方式必须为 asc 或 desc。',
        ],
        'per_page' => [
            'integer' => '每页条数必须为数字。',
            'min' => '每页条数必须为 10 以上。',
            'max' => '每页条数必须为 100 以下。',
        ],
        'page' => [
            'integer' => '页码必须为数字。',
            'min' => '页码必须为 1 以上。',
        ],
    ],
    'quantity_exceeds_available' => '变更数量超出了持有数量。',
    'quantity_min_one' => '变更数量必须为 1 以上。',
    'order_options' => [
        'items' => [
            'required' => '请选择要变更的选项。',
            'min' => '请至少选择 1 个选项。',
        ],
        'option_id' => [
            'required' => '选项 ID 为必填项。',
            'exists' => '选项不存在。',
        ],
        'quantity' => [
            'required' => '请输入变更数量。',
            'min' => '变更数量必须为 1 以上。',
        ],
        'status' => [
            'required' => '请选择要变更的状态。',
            'in' => '请选择正确的选项状态。',
        ],
    ],
    'order' => [
        'payment_method_unavailable' => '该支付方式当前不可用。请选择其他支付方式。',
        'ids' => [
            'required' => '请选择要变更的订单。',
            'array' => '订单 ID 必须为数组格式。',
            'min' => '请至少选择 1 笔订单。',
        ],
        'order_status' => [
            'required' => '请选择订单状态。',
            'in' => '请选择正确的订单状态。',
        ],
        'carrier_id' => [
            'required' => '请选择物流公司。',
            'exists' => '物流公司不存在。',
        ],
        'tracking_number' => [
            'required' => '请输入运单号。',
            'string' => '运单号必须为字符串。',
            'max' => '运单号最多可输入 100 个字符。',
        ],
        'not_found' => '找不到订单。',
        'cannot_update' => '该订单无法修改。',
        'cannot_cancel' => '该订单无法取消。',
        'cannot_refund' => '该订单无法退款。',
        'orderer_name_required' => '请输入下单人姓名。',
        'orderer_phone_required' => '请输入下单人联系方式。',
        'orderer_email_required' => '请输入下单人邮箱。',
        'orderer_email_invalid' => '邮箱格式不正确。',
        'recipient_name_required' => '请输入收件人姓名。',
        'recipient_phone_required' => '请输入收件人联系方式。',
        'recipient_phone_required_without' => '请输入手机号码或固定电话号码中的一项。',
        'recipient_tel_required_without' => '请输入手机号码或固定电话号码中的一项。',
        'zipcode_required' => '请输入邮政编码。',
        'address_required' => '请输入地址。',
        'address_detail_required' => '请输入详细地址。',
        'address_line_1_required' => '请输入地址。',
        'intl_city_required' => '请输入城市。',
        'intl_postal_code_required' => '请输入邮政编码。',
        'payment_method_required' => '请选择支付方式。',
        'payment_method_invalid' => '请选择正确的支付方式。',
        'expected_total_amount_required' => '需要预计支付金额。',
        'expected_total_amount_numeric' => '预计支付金额必须为数字。',
        'depositor_name_required' => '请输入汇款人姓名。',
        'dbank_bank_code_required' => '请选择汇款银行。',
        'dbank_bank_name_required' => '需要汇款银行名称。',
        'dbank_account_number_required' => '需要汇款账号。',
        'dbank_account_holder_required' => '需要开户人姓名。',
        'guest_lookup_password_required' => '请输入订单查询密码。',
        'guest_lookup_password_min' => '订单查询密码必须为 8 位以上。',
        'guest_lookup_password_confirmed' => '订单查询密码不一致。',
        'guest_lookup_password_confirmation_required' => '请输入订单查询密码确认。',
        'cash_receipt_type_required' => '请选择现金收据的开具用途。',
        'cash_receipt_type_invalid' => '现金收据的开具用途不正确。',
        'cash_receipt_identifier_type_required' => '请选择现金收据的开具方式。',
        'cash_receipt_identifier_type_invalid' => '现金收据的开具方式不正确。',
        'cash_receipt_identifier_required' => '请输入用于开具现金收据的号码。',
        'refund_bank_required_with' => '退款账户必须完整填写银行、账号和开户人。',
        'refund_bank_required_for_vbank' => '已完成到账的虚拟账户订单需要填写退款账户。',
    ],
    'guest_order' => [
        'order_number_required' => '请输入订单号。',
        'orderer_phone_required' => '请输入电话号码。',
        'guest_lookup_password_required' => '请输入订单查询密码。',
    ],
    'order_bulk' => [
        'ids_required' => '请选择要变更的订单。',
        'status_or_shipping_required' => '订单状态或配送信息必须填写其中一项。',
        'invalid_status' => '订单状态不正确。',
        'invalid_carrier' => '物流公司不正确。',
    ],
    'order_export' => [
        'format' => [
            'required' => '请选择导出格式。',
            'in' => '请选择正确的导出格式。',
        ],
        'columns' => [
            'required' => '请选择要导出的列。',
            'array' => '列必须为数组格式。',
            'min' => '请至少选择 1 个列。',
        ],
    ],
    'cart' => [
        'quantity_limit_exceeded' => '购物车中每件商品最多可加入 :limit 件。（请求：:attempted 件）',
        'ids_required' => '请选择要删除的商品。',
        'ids_array' => '商品 ID 必须为数组格式。',
        'ids_min' => '请至少选择 1 件商品。',
        'item_not_found' => '找不到购物车商品。',
        'product_id_required' => '请选择商品。',
        'product_not_found' => '商品不存在。',
        'option_id_required' => '请选择选项。',
        'option_not_found' => '选项不存在。',
        'quantity_required' => '请输入数量。',
        'quantity_min' => '数量必须为 1 件以上。',
        'quantity_max' => '数量最多可为 :max 件。',
        'items_required' => '请选择要加入购物车的商品。',
        'items_min' => '请至少选择 1 件商品。',
        'selected_ids_array' => '已选购物车商品 ID 必须为数组格式。',
        'selected_ids_integer' => '已选购物车商品 ID 必须为数字。',
        'selected_ids_min' => '已选购物车商品 ID 必须为 1 以上。',
        'cart_key_required' => '需要非会员购物车密钥。',
        'invalid_cart_key' => '购物车密钥格式不正确。',
        'login_required' => '需要登录。',
    ],
    'wishlist' => [
        'product_id_required' => '请选择商品。',
        'product_not_found' => '找不到商品。',
        'selected_ids_array' => '已选商品 ID 必须为数组格式。',
        'selected_ids_integer' => '已选商品 ID 必须为数字。',
        'selected_ids_min' => '已选商品 ID 必须为 1 以上。',
        'cart_key_required' => '需要非会员购物车密钥。',
        'invalid_cart_key' => '购物车密钥格式不正确。',
        'login_required' => '需要登录。',
    ],
    'checkout' => [
        'item_ids_required' => '请选择要下单的商品。',
        'item_ids_array' => '商品 ID 必须为数组格式。',
        'item_ids_min' => '请至少选择 1 件商品。',
        'use_points_integer' => '积分必须为数字。',
        'use_points_min' => '积分必须为 0 以上。',
        'coupon_issue_ids_array' => '优惠券 ID 必须为数组格式。',
        'coupon_issue_id_integer' => '优惠券 ID 必须为数字。',
        'item_coupons_array' => '商品优惠券必须为数组格式。',
        'item_coupons_max' => '每件商品最多可使用 :max 张优惠券。',
        'item_coupon_integer' => '商品优惠券 ID 必须为数字。',
        'item_coupon_not_found' => '商品优惠券不存在。',
        'order_coupon_integer' => '订单优惠券 ID 必须为数字。',
        'order_coupon_not_found' => '订单优惠券不存在。',
        'shipping_coupon_integer' => '运费优惠券 ID 必须为数字。',
        'shipping_coupon_not_found' => '运费优惠券不存在。',
        'country_code_size' => '国家／地区代码必须为 2 位。',
        'zipcode_max' => '邮政编码最多可输入 20 个字符。',
        'region_max' => '地区最多可输入 100 个字符。',
        'city_max' => '城市最多可输入 100 个字符。',
        'address_max' => '地址最多可输入 255 个字符。',
    ],
    'shipping_policy' => [
        'name' => [
            'required' => '请输入配送政策名称。',
        ],
        'ids_required' => '请选择要变更的配送政策。',
        'ids_array' => '配送政策 ID 必须为数组格式。',
        'ids_min' => '请至少选择 1 个配送政策。',
        'id_integer' => '配送政策 ID 必须为数字。',
        'id_exists' => '配送政策不存在。',
        'is_active_required' => '请选择启用状态。',
        'is_active_boolean' => '启用状态必须为 true 或 false。',
        'base_fee_zero_not_allowed' => '非免运费的政策不能将运费设置为 0 韩元。',
        'custom_shipping_name_required' => '选择手动输入配送方式时，请输入配送方式名称。',
        'ranges' => [
            'first_min_zero' => '第一个区间的起始值必须为 0。',
            'last_max_unlimited' => '最后一个区间的结束值必须留空。',
            'continuity' => '区间不连续。',
            'min_less_than_max' => '起始值必须小于结束值。',
            'fee_non_negative' => '运费必须为 0 以上。',
            'fee_required' => '请输入区间运费。',
            'tier_min_non_negative' => '区间起始值必须为 0 以上。',
            'tier_max_non_negative' => '区间结束值必须为 0 以上。',
            'unit_value_min' => '区间单位值必须大于 0。',
            'tiers_required' => '按区间的运费政策必须至少登记 1 个区间。',
            'middle_max_required' => '除最后一个区间外，其余区间必须输入结束值。',
            'tier_value_integer' => '数量区间的起始值和结束值必须为整数。',
            'unit_value_required' => '按单位计费的运费政策必须输入单位值。',
        ],
        'free_threshold_required' => '有条件免运费的政策必须输入免运费门槛金额。',
        'extra_fee' => [
            'zipcode_format' => '邮政编码必须为 "63000"、"63000-63999"、"63*" 格式中的一种。',
        ],
        'country_settings' => [
            'required' => '请至少添加 1 项国家/地区配送设置。',
            'min' => '请至少添加 1 项国家/地区配送设置。',
            'country_code' => [
                'required' => '请选择国家／地区。',
                'distinct' => '国家／地区重复。',
            ],
            'shipping_method' => [
                'required' => '请选择配送方式。',
                'in' => '请选择正确的配送方式。',
            ],
            'charge_policy' => [
                'required' => '请选择计费政策。',
                'in' => '请选择正确的计费政策。',
            ],
            'base_fee' => [
                'numeric' => '基本运费必须为数字。',
                'min' => '基本运费必须为 0 以上。',
            ],
            'free_threshold' => [
                'numeric' => '免运费门槛金额必须为数字。',
                'min' => '免运费门槛金额必须为 0 以上。',
            ],
            'api_endpoint' => [
                'url' => 'URL 格式不正确。',
                'required' => '选择计算 API 政策时，请输入 API 地址。',
            ],
            'api_request_fields' => [
                'in' => '不支持的参考字段。',
            ],
            'api_config' => [
                'http_method_in' => '不支持的 HTTP 方法。',
                'auth_type_in' => '不支持的认证方式。',
                'auth_header_name_required' => '选择自定义标头认证时，请输入标头名称。',
                'auth_header_name_format' => '标头名称中包含不可使用的字符。',
                'response_type_in' => '不支持的响应格式。',
                'field_map_format' => '外部键名中包含不可使用的字符。',
            ],
            'extra_fee_enabled' => [
                'required' => '请选择是否使用附加运费。',
            ],
            'is_active' => [
                'required' => '请选择启用状态。',
            ],
        ],
    ],
    'extra_fee_template' => [
        'zipcode_required' => '请输入邮政编码。',
        'zipcode_unique' => '该邮政编码已登记。',
        'zipcode_max' => '邮政编码最多可输入 10 个字符。',
        'zipcode_format' => '邮政编码只能输入数字和连字符（-）。（例：12345 或 12345-12399）',
        'fee_required' => '请输入附加运费。',
        'fee_numeric' => '附加运费必须为数字。',
        'fee_min' => '附加运费必须为 0 以上。',
        'ids_required' => '请选择要变更的项目。',
        'ids_array' => '项目 ID 必须为数组格式。',
        'ids_min' => '请至少选择 1 个项目。',
        'id_not_found' => '项目不存在。',
        'is_active_required' => '请选择启用状态。',
        'is_active_boolean' => '启用状态必须为 true 或 false。',
        'items_required' => '请输入要登记的项目。',
        'items_array' => '项目必须为数组格式。',
        'items_min' => '请至少输入 1 个项目。',
        'items_max' => '单次最多可登记 100 个。',
        'item_zipcode_required' => '请输入邮政编码。',
        'item_fee_required' => '请输入附加运费。',
    ],
    'product_common_info' => [
        'name_required' => '请输入通用信息名称。',
        'name_max' => '通用信息名称最多可输入 100 个字符。',
        'content_mode_invalid' => '请选择正确的内容模式（text 或 html）。',
    ],
    'coupon_issues' => [
        'user_id_integer' => '用户 ID 必须为整数。',
        'user_id_exists' => '用户不存在。',
        'status_in' => '状态值无效。',
        'per_page_integer' => '每页条数必须为整数。',
        'per_page_min' => '每页条数至少为 1 条。',
        'per_page_max' => '每页条数最多为 100 条。',
    ],
    'search_preset' => [
        'target_screen_in' => '目标页面无效。',
    ],
    'category_reorder' => [
        'parent_menus_required' => '需要父菜单或子菜单数据。',
        'parent_menus_array' => '父菜单必须为数组。',
        'id_required' => '分类 ID 为必填项。',
        'id_integer' => '分类 ID 必须为整数。',
        'id_exists' => '分类不存在。',
        'order_required' => '顺序值为必填项。',
        'order_integer' => '顺序值必须为整数。',
        'order_min' => '顺序值必须为 0 以上。',
    ],
    'reviews' => [
        'search_field' => [
            'in' => '请选择正确的搜索字段。',
        ],
        'search_keyword' => [
            'string' => '搜索关键词必须为字符串。',
            'max' => '搜索关键词最多可输入 :max 个字符。',
        ],
        'rating' => [
            'in' => '请选择正确的评分。',
            'required' => '请选择评分。',
            'integer' => '评分必须为数字。',
            'min' => '评分必须为 :min 分以上。',
            'max' => '评分必须为 :max 分以下。',
        ],
        'reply_status' => [
            'in' => '请选择正确的回复状态。',
        ],
        'has_photo' => [
            'boolean' => '图片评价筛选必须为 true 或 false。',
        ],
        'status' => [
            'in' => '请选择正确的评价状态。',
            'required' => '请选择评价状态。',
            'required_if' => '选择变更状态时，请同时选择评价状态。',
        ],
        'start_date' => [
            'date' => '开始日期必须为日期格式。',
        ],
        'end_date' => [
            'date' => '结束日期必须为日期格式。',
            'after_or_equal' => '结束日期必须晚于或等于开始日期。',
        ],
        'sort_by' => [
            'in' => '请选择正确的排序字段。',
        ],
        'sort_order' => [
            'in' => '排序方式必须为 asc 或 desc。',
        ],
        'per_page' => [
            'integer' => '每页条数必须为数字。',
            'min' => '每页条数必须为 :min 以上。',
            'max' => '每页条数必须为 :max 以下。',
        ],
        'page' => [
            'integer' => '页码必须为数字。',
            'min' => '页码必须为 1 以上。',
        ],
        'product_id' => [
            'required' => '请选择商品。',
            'exists' => '商品不存在。',
        ],
        'order_option_id' => [
            'required' => '请选择要评价的订单商品。',
            'exists' => '订单商品不存在。',
        ],
        'content' => [
            'required' => '请输入评价内容。',
            'min' => '评价内容至少需输入 :min 个字符。',
            'max' => '评价内容最多可输入 :max 个字符。',
        ],
        'content_mode' => [
            'in' => '请选择正确的评价内容格式。',
        ],
        'reply_content' => [
            'required' => '请输入回复内容。',
            'min' => '回复内容至少需输入 :min 个字符。',
            'max' => '回复内容最多可输入 :max 个字符。',
        ],
        'reply_content_mode' => [
            'in' => '请选择正确的回复内容格式。',
        ],
        'ids' => [
            'required' => '请选择要处理的评价。',
            'array' => '评价 ID 必须为数组格式。',
            'min' => '请至少选择 1 条评价。',
            'integer' => '评价 ID 必须为数字。',
            'exists' => '评价不存在。',
        ],
        'action' => [
            'required' => '请选择处理方式。',
            'in' => '请选择正确的处理方式。',
        ],
    ],
    'public_product' => [
        'category_id' => [
            'integer' => '分类 ID 必须为数字。',
        ],
        'category_slug' => [
            'string' => '分类别名（slug）必须为字符串。',
            'max' => '分类别名（slug）最多可输入 :max 个字符。',
        ],
        'brand_id' => [
            'integer' => '品牌 ID 必须为数字。',
        ],
        'search' => [
            'string' => '搜索词必须为字符串。',
            'max' => '搜索词最多可输入 :max 个字符。',
        ],
        'sort' => [
            'in' => '请选择正确的排序基准。',
        ],
        'min_price' => [
            'integer' => '最低价格必须为数字。',
            'min' => '最低价格必须为 0 以上。',
        ],
        'max_price' => [
            'integer' => '最高价格必须为数字。',
            'min' => '最高价格必须为 0 以上。',
        ],
        'per_page' => [
            'integer' => '每页条数必须为数字。',
            'min' => '每页条数必须为 :min 以上。',
            'max' => '每页条数必须为 :max 以下。',
        ],
        'limit' => [
            'integer' => '查询条数必须为数字。',
            'min' => '查询条数必须为 :min 以上。',
            'max' => '查询条数必须为 :max 以下。',
        ],
        'ids' => [
            'string' => '商品 ID 列表必须为字符串。',
            'max' => '商品 ID 列表最多可输入 :max 个字符。',
        ],
    ],
    'public_review' => [
        'sort' => [
            'in' => '请选择正确的排序基准。',
        ],
        'photo_only' => [
            'boolean' => '图片评价筛选必须为 true 或 false。',
        ],
        'page' => [
            'integer' => '页码必须为数字。',
            'min' => '页码必须为 1 以上。',
        ],
        'per_page' => [
            'integer' => '每页条数必须为数字。',
            'min' => '每页条数必须为 :min 以上。',
            'max' => '每页条数必须为 :max 以下。',
        ],
        'rating' => [
            'in' => '请选择正确的评分。',
        ],
    ],
    'user_coupon' => [
        'status' => [
            'in' => '请选择正确的优惠券状态。',
        ],
        'per_page' => [
            'integer' => '每页条数必须为数字。',
            'min' => '每页条数必须为 :min 以上。',
            'max' => '每页条数必须为 :max 以下。',
        ],
        'product_ids' => [
            'array' => '商品 ID 列表必须为数组格式。',
        ],
        'product_ids_item' => [
            'integer' => '商品 ID 必须为数字。',
        ],
    ],
    'user_mileage' => [
        'order_amount' => [
            'required' => '订单金额为必填项。',
            'integer' => '订单金额必须为数字。',
            'min' => '订单金额必须为 0 以上。',
        ],
    ],
    'public_asset_disk_invalid' => '请选择正确的公开资源磁盘。',
    'attributes' => [
        'basic_info.public_asset_disk' => '公开资源磁盘',
        'country_settings' => '国家/地区设置',
        'country_settings.*.country_code' => '国家／地区',
        'country_settings.*.shipping_method' => '配送方式',
        'country_settings.*.currency_code' => '货币',
        'country_settings.*.charge_policy' => '计费政策',
        'country_settings.*.base_fee' => '基本运费',
        'country_settings.*.free_threshold' => '免运费门槛金额',
        'country_settings.*.ranges.unit_value' => '区间单位值',
        'country_settings.*.ranges.tiers.*.min' => '区间起始值',
        'country_settings.*.ranges.tiers.*.max' => '区间结束值',
        'country_settings.*.ranges.tiers.*.fee' => '区间运费',
        'country_settings.*.api_endpoint' => '计算 API 地址',
        'country_settings.*.api_config.http_method' => 'HTTP 方法',
        'country_settings.*.api_config.auth_type' => '认证方式',
        'country_settings.*.api_config.auth_token' => '认证令牌',
        'country_settings.*.api_config.auth_header_name' => '认证标头名称',
        'country_settings.*.api_config.response_type' => '响应格式',
        'country_settings.*.api_config.response_path' => '响应运费路径',
        'country_settings.*.extra_fee_settings.*.zipcode' => '邮政编码',
        'country_settings.*.extra_fee_settings.*.fee' => '附加运费',
        'review_settings.write_deadline_days' => '评价撰写期限（天）',
        'review_settings.max_images' => '评价图片最大数量',
        'review_settings.max_image_size_mb' => '评价图片最大容量（MB）',
        'basic_info' => '基本信息',
        'basic_info.shop_name' => '商城名称',
        'basic_info.route_path' => '路由路径',
        'basic_info.company_name' => '公司名称',
        'basic_info.business_number_1' => '营业执照号码',
        'basic_info.business_number_2' => '营业执照号码',
        'basic_info.business_number_3' => '营业执照号码',
        'basic_info.ceo_name' => '法定代表人姓名',
        'basic_info.business_type' => '行业形态',
        'basic_info.business_category' => '经营类目',
        'basic_info.zipcode' => '邮政编码',
        'basic_info.base_address' => '基本地址',
        'basic_info.detail_address' => '详细地址',
        'basic_info.phone_1' => '电话号码',
        'basic_info.phone_2' => '电话号码',
        'basic_info.phone_3' => '电话号码',
        'basic_info.fax_1' => '传真号码',
        'basic_info.fax_2' => '传真号码',
        'basic_info.fax_3' => '传真号码',
        'basic_info.email_id' => '邮箱',
        'basic_info.email_domain' => '邮箱',
        'basic_info.privacy_officer' => '个人信息负责人',
        'basic_info.privacy_officer_email' => '个人信息负责人邮箱',
        'basic_info.mail_order_number' => '通信销售业申报编号',
        'basic_info.telecom_number' => '增值电信业务经营者编号',
        'language_currency' => '语言/货币设置',
        'language_currency.default_currency' => '默认货币',
        'language_currency.currencies' => '货币列表',
        'language_currency.currencies.*.code' => '货币代码',
        'language_currency.currencies.*.name' => '货币名称',
        'language_currency.currencies.*.name.*' => '货币名称',
        'language_currency.currencies.*.exchange_rate' => '汇率',
        'language_currency.currencies.*.base_unit' => '基准单位',
        'language_currency.currencies.*.rounding_unit' => '舍入单位',
        'language_currency.currencies.*.rounding_method' => '舍入方式',
        'language_currency.currencies.*.decimal_places' => '小数位数',
        'language_currency.currencies.*.locales' => '使用语言',
        'language_currency.currencies.*.locales.*' => '使用语言',
        'mileage.default_earn_rate' => '默认积分累积率',
        'mileage.earn_trigger' => '累积时点',
        'mileage.earn_delay_days' => '累积延迟天数',
        'mileage.currency_rules.*.currency_code' => '货币代码',
        'mileage.currency_rules.*.point_value' => '每 1 分对应金额',
        'mileage.currency_rules.*.min_use_amount' => '最低使用金额',
        'mileage.currency_rules.*.use_unit' => '使用单位',
        'mileage.currency_rules.*.max_use_percent' => '最大使用比例',
        'mileage.currency_rules.*.max_use_value' => '最大使用金额',
        'mileage.currency_rules.*.earn_rounding_unit' => '累积截位单位',
        'mileage.currency_rules.*.earn_rounding_method' => '累积截位方式',
        'mileage.expiry_days' => '有效期',
        'mileage.expiry_notification_days_before' => '即将失效提醒天数',
        'seo' => 'SEO 设置',
        'seo.meta_main_title' => '首页标题',
        'seo.meta_main_description' => '首页描述',
        'seo.meta_category_title' => '分类页标题',
        'seo.meta_category_description' => '分类页描述',
        'seo.meta_search_title' => '搜索页标题',
        'seo.meta_search_description' => '搜索页描述',
        'seo.meta_product_title' => '商品页标题',
        'seo.meta_product_description' => '商品页描述',
        'seo.meta_shop_index_title' => '商城首页标题',
        'seo.meta_shop_index_description' => '商城首页描述',
        'seo.seo_shop_index' => '启用商城首页 SEO',
        'seo.seo_user_agents' => 'SEO 用户代理',
        'order_settings.payment_methods' => '支付方式',
        'order_settings.payment_methods.*.id' => '支付方式 ID',
        'order_settings.payment_methods.*.sort_order' => '支付方式排序',
        'order_settings.payment_methods.*.is_active' => '支付方式启用状态',
        'order_settings.payment_methods.*.min_order_amount' => '最低订单金额',
        'order_settings.payment_methods.*.stock_deduction_timing' => '库存扣减时点',
        'order_settings.banks' => '银行列表',
        'order_settings.bank_accounts' => '账户列表',
        'order_settings.bank_accounts.*.bank_code' => '银行代码',
        'order_settings.bank_accounts.*.account_number' => '账号',
        'order_settings.bank_accounts.*.account_holder' => '开户人',
        'order_settings.bank_accounts.*.is_active' => '账户启用状态',
        'order_settings.bank_accounts.*.is_default' => '默认账户',
        'order_settings.auto_cancel_expired' => '未支付自动取消',
        'order_settings.auto_cancel_days' => '自动取消期限（天）',
        'order_settings.cart_expiry_days' => '购物车保留期限（天）',
        'order_settings.default_pg_provider' => '默认 PG 公司',
        'order_settings.payment_methods.*.pg_provider' => 'PG 公司',
        'order_settings.stock_restore_on_cancel' => '取消时恢复库存',
        'order_number' => '订单号',
        'order_status' => '订单状态',
        'payment_status' => '支付状态',
        'payment_method' => '支付方式',
        'total_amount' => '订单总金额',
        'total_paid_amount' => '支付总金额',
        'ordered_at' => '下单时间',
        'paid_at' => '支付时间',
        'carrier_id' => '物流公司',
        'tracking_number' => '运单号',
        'shipping_status' => '配送状态',
        'shipping_type' => '配送类型',
        'orderer_name' => '下单人姓名',
        'orderer_phone' => '下单人联系方式',
        'orderer_email' => '下单人邮箱',
        'recipient_name' => '收件人',
        'recipient_phone' => '收件人联系方式',
        'recipient_zipcode' => '邮政编码',
        'recipient_address' => '收货地址',
        'recipient_detail_address' => '详细地址',
        'recipient_country_code' => '配送国家／地区',
        'delivery_memo' => '配送备注',
        'address_id' => '收货地址',
        'label_name' => '标签名称',
        'label_color' => '标签颜色',
        'is_active' => '启用状态',
        'sort_order' => '排序',
        'claim_reason_type' => '理赔类型',
        'claim_reason_code' => '理赔原因代码',
        'claim_reason_name' => '理赔原因名称',
        'claim_reason_fault_type' => '责任归属',
        'claim_reason_is_user_selectable' => '用户是否可选择',
        'claim_reason_is_active' => '启用状态',
        'claim_reason_sort_order' => '排序',
        'claim_reason_search' => '搜索词',
    ],
    'custom' => [
        'basic_info' => [
            'shop_name' => [
                'required' => '商城名称为必填项。',
                'string' => '商城名称必须为字符串。',
                'max' => '商城名称最多可输入 255 个字符。',
            ],
            'route_path' => [
                'required' => '路由路径为必填项。',
                'string' => '路由路径必须为字符串。',
                'max' => '路由路径最多可输入 100 个字符。',
            ],
            'no_route' => [
                'boolean' => '是否停用路由必须为真／假值。',
            ],
            'company_name' => [
                'string' => '公司名称必须为字符串。',
                'max' => '公司名称最多可输入 255 个字符。',
            ],
            'business_number' => [
                'string' => '营业执照号码必须为字符串。',
                'max' => '营业执照号码格式不正确。',
            ],
            'ceo_name' => [
                'string' => '法定代表人姓名必须为字符串。',
                'max' => '法定代表人姓名最多可输入 100 个字符。',
            ],
            'business_type' => [
                'string' => '行业形态必须为字符串。',
                'max' => '行业形态最多可输入 100 个字符。',
            ],
            'business_category' => [
                'string' => '经营类目必须为字符串。',
                'max' => '经营类目最多可输入 255 个字符。',
            ],
            'zipcode' => [
                'string' => '邮政编码必须为字符串。',
                'max' => '邮政编码最多可输入 10 个字符。',
            ],
            'base_address' => [
                'string' => '基本地址必须为字符串。',
                'max' => '基本地址最多可输入 500 个字符。',
            ],
            'detail_address' => [
                'string' => '详细地址必须为字符串。',
                'max' => '详细地址最多可输入 255 个字符。',
            ],
            'phone' => [
                'string' => '电话号码必须为字符串。',
                'max' => '电话号码格式不正确。',
            ],
            'fax' => [
                'string' => '传真号码必须为字符串。',
                'max' => '传真号码格式不正确。',
            ],
            'email_id' => [
                'string' => '邮箱账号必须为字符串。',
                'max' => '邮箱账号最多可输入 100 个字符。',
            ],
            'email_domain' => [
                'string' => '邮箱域名必须为字符串。',
                'max' => '邮箱域名最多可输入 100 个字符。',
            ],
            'privacy_officer' => [
                'string' => '个人信息负责人必须为字符串。',
                'max' => '个人信息负责人最多可输入 100 个字符。',
            ],
            'privacy_officer_email' => [
                'email' => '邮箱格式不正确。',
                'max' => '个人信息负责人邮箱最多可输入 255 个字符。',
            ],
            'mail_order_number' => [
                'string' => '通信销售业申报编号必须为字符串。',
                'max' => '通信销售业申报编号最多可输入 100 个字符。',
            ],
            'telecom_number' => [
                'string' => '增值电信业务经营者编号必须为字符串。',
                'max' => '增值电信业务经营者编号最多可输入 100 个字符。',
            ],
        ],
        'user_currency' => [
            'required' => '请选择支付货币。',
            'invalid' => '只能选择已登记的货币。',
        ],
        'user_shipping_country' => [
            'required' => '请选择配送国家／地区。',
            'invalid' => '只能选择可配送的国家／地区。',
        ],
        'language_currency' => [
            'base_locked_after_data' => '已登记 1 件以上商品或订单后，无法变更默认货币。',
            'default_currency' => [
                'string' => '默认货币必须为字符串。',
                'max' => '默认货币最多可输入 10 个字符。',
            ],
            'currencies' => [
                'duplicate_code' => '存在重复的货币代码。',
                'name_required' => '货币名称至少需以一种语言输入。',
                'code' => [
                    'required_with' => '货币代码为必填项。',
                    'string' => '货币代码必须为字符串。',
                    'regex' => '货币代码必须为 ISO 4217 格式（3 位英文大写字母，例：KRW）。',
                ],
                'name' => [
                    'required_with' => '货币名称为必填项。',
                    'array' => '货币名称必须为数组格式。',
                    'string' => '货币名称必须为字符串。',
                    'max' => '货币名称最多可输入 100 个字符。',
                ],
                'exchange_rate' => [
                    'numeric' => '汇率必须为数字。',
                    'min' => '汇率必须为 0 以上。',
                ],
                'rounding_unit' => [
                    'string' => '舍入单位必须为字符串。',
                ],
                'rounding_method' => [
                    'string' => '舍入方式必须为字符串。',
                    'in' => '舍入方式必须为 floor, round, ceil 中的一种。',
                ],
                'decimal_places' => [
                    'integer' => '小数位数必须为整数。',
                    'min' => '小数位数必须为 0 以上。',
                    'max' => '小数位数最多为 8 位。',
                ],
                'is_default' => [
                    'boolean' => '是否为默认货币必须为真／假值。',
                ],
            ],
        ],
        'mileage' => [
            'currency_rules' => [
                'currency_code' => [
                    'required_with' => '货币代码为必填项。',
                    'regex' => '货币代码必须为 ISO 4217 格式（3 位英文大写字母，例：KRW）。',
                ],
                'point_value' => [
                    'numeric' => '每 1 分对应金额必须为数字。',
                    'min' => '每 1 分对应金额必须大于 0。',
                ],
                'max_use_value' => [
                    'integer' => '最大使用金额必须为整数。',
                    'min' => '最大使用金额必须为 0 以上。',
                    'max' => '最大使用金额过大。（最大 10 亿）',
                ],
                'earn_rounding_unit' => [
                    'in' => '累积截位单位必须为 1, 10, 100 中的一种。',
                ],
                'earn_rounding_method' => [
                    'in' => '累积截位方式必须为 floor, round, ceil 中的一种。',
                ],
            ],
        ],
        'seo' => [
            'meta_main_title' => [
                'string' => '首页标题必须为字符串。',
                'max' => '首页标题最多可输入 500 个字符。',
            ],
            'meta_main_description' => [
                'string' => '首页描述必须为字符串。',
                'max' => '首页描述最多可输入 1000 个字符。',
            ],
            'meta_category_title' => [
                'string' => '分类页标题必须为字符串。',
                'max' => '分类页标题最多可输入 500 个字符。',
            ],
            'meta_category_description' => [
                'string' => '分类页描述必须为字符串。',
                'max' => '分类页描述最多可输入 1000 个字符。',
            ],
            'meta_search_title' => [
                'string' => '搜索页标题必须为字符串。',
                'max' => '搜索页标题最多可输入 500 个字符。',
            ],
            'meta_search_description' => [
                'string' => '搜索页描述必须为字符串。',
                'max' => '搜索页描述最多可输入 1000 个字符。',
            ],
            'meta_product_title' => [
                'string' => '商品页标题必须为字符串。',
                'max' => '商品页标题最多可输入 500 个字符。',
            ],
            'meta_product_description' => [
                'string' => '商品页描述必须为字符串。',
                'max' => '商品页描述最多可输入 1000 个字符。',
            ],
            'seo_site_main' => [
                'boolean' => '首页 SEO 启用状态必须为真／假值。',
            ],
            'seo_category' => [
                'boolean' => '分类页 SEO 启用状态必须为真／假值。',
            ],
            'seo_search_result' => [
                'boolean' => '搜索结果页 SEO 启用状态必须为真／假值。',
            ],
            'seo_product_detail' => [
                'boolean' => '商品详情页 SEO 启用状态必须为真／假值。',
            ],
            'meta_shop_index_title' => [
                'string' => '商城首页标题必须为字符串。',
                'max' => '商城首页标题最多可输入 500 个字符。',
            ],
            'meta_shop_index_description' => [
                'string' => '商城首页描述必须为字符串。',
                'max' => '商城首页描述最多可输入 1000 个字符。',
            ],
            'seo_shop_index' => [
                'boolean' => '商城首页 SEO 启用状态必须为真／假值。',
            ],
            'seo_user_agents' => [
                'string' => 'SEO 用户代理必须为字符串。',
                'max' => 'SEO 用户代理最多可输入 100 个字符。',
            ],
        ],
        'banks' => [
            'code' => [
                'required_with' => '银行代码为必填项。',
                'string' => '银行代码必须为字符串。',
                'max' => '银行代码最多可输入 10 个字符。',
            ],
            'name' => [
                'required_with' => '银行名称为必填项。',
                'array' => '银行名称必须为多语言数组格式。',
                'string' => '银行名称必须为字符串。',
                'max' => '银行名称最多可输入 100 个字符。',
            ],
        ],
        'order_settings' => [
            'payment_methods' => [
                'at_least_one_active' => '支付方式中至少需要启用一项。',
                'id' => [
                    'required_with' => '支付方式 ID 为必填项。',
                    'string' => '支付方式 ID 必须为字符串。',
                ],
                'sort_order' => [
                    'integer' => '支付方式排序必须为整数。',
                    'min' => '支付方式排序必须为 1 以上。',
                ],
                'is_active' => [
                    'boolean' => '支付方式启用状态必须为真／假值。',
                ],
                'min_order_amount' => [
                    'integer' => '最低订单金额必须为整数。',
                    'min' => '最低订单金额必须为 0 以上。',
                ],
                'stock_deduction_timing' => [
                    'string' => '库存扣减时点必须为字符串。',
                    'in' => '库存扣减时点必须为下单受理时、支付完成时、不扣减库存中的一种。',
                ],
                'pg_required_for_activation' => '要启用该支付方式，请先选择 PG 公司。',
            ],
            'bank_accounts' => [
                'at_least_one_active_default' => '银行汇款账户中至少需要有一个被设为默认并启用。',
                'bank_code' => [
                    'required_with' => '银行为必填项。',
                    'string' => '银行代码必须为字符串。',
                ],
                'account_number' => [
                    'required_with' => '账号为必填项。',
                    'string' => '账号必须为字符串。',
                    'max' => '账号最多可输入 50 个字符。',
                ],
                'account_holder' => [
                    'required_with' => '开户人为必填项。',
                    'string' => '开户人必须为字符串。',
                    'max' => '开户人最多可输入 100 个字符。',
                ],
                'is_active' => [
                    'boolean' => '账户启用状态必须为真／假值。',
                ],
                'is_default' => [
                    'boolean' => '是否为默认账户必须为真／假值。',
                ],
            ],
            'auto_cancel_expired' => [
                'boolean' => '未支付自动取消状态必须为真／假值。',
            ],
            'auto_cancel_days' => [
                'required' => '请输入自动取消期限。',
                'integer' => '自动取消期限必须为整数。',
                'min' => '自动取消期限必须为 1 天以上。',
                'max' => '自动取消期限最多可设置为 30 天。',
            ],
            'cart_expiry_days' => [
                'integer' => '购物车保留期限必须为整数。',
                'min' => '购物车保留期限必须为 1 天以上。',
                'max' => '购物车保留期限最多可设置为 365 天。',
            ],
            'stock_restore_on_cancel' => [
                'boolean' => '取消时是否恢复库存必须为真／假值。',
            ],
        ],
        'claim' => [
            'refund_reasons' => [
                'duplicate_code' => '存在重复的退款原因代码。',
                'name_required' => '退款原因名称为必填项。',
            ],
        ],
        'shipping' => [
            'default_country' => [
                'string' => '默认配送国家／地区必须为字符串。',
                'max' => '默认配送国家／地区最多可输入 10 个字符。',
                'must_exist_in_countries' => '默认配送国家／地区必须存在于可配送国家／地区列表中。',
            ],
            'available_countries' => [
                'array' => '可配送国家／地区必须为数组格式。',
                'duplicate_code' => '存在重复的国家／地区代码。',
                'name_required' => '国家／地区名称至少需以一种语言输入。',
                'code' => [
                    'required_with' => '国家／地区代码为必填项。',
                    'string' => '国家／地区代码必须为字符串。',
                    'max' => '国家／地区代码最多可输入 10 个字符。',
                ],
                'name' => [
                    'required_with' => '国家／地区名称为必填项。',
                    'array' => '国家／地区名称必须为数组格式。',
                    'string' => '国家／地区名称必须为字符串。',
                    'max' => '国家／地区名称最多可输入 100 个字符。',
                ],
                'is_active' => [
                    'boolean' => '国家／地区启用状态必须为真／假值。',
                ],
            ],
            'international_shipping_enabled' => [
                'boolean' => '海外配送启用状态必须为真／假值。',
            ],
            'remote_area_enabled' => [
                'boolean' => '偏远地区启用状态必须为真／假值。',
            ],
            'remote_area_extra_fee' => [
                'integer' => '山区附加运费必须为整数。',
                'min' => '山区附加运费必须为 0 韩元以上。',
            ],
            'island_extra_fee' => [
                'integer' => '岛屿地区附加运费必须为整数。',
                'min' => '岛屿地区附加运费必须为 0 韩元以上。',
            ],
            'free_shipping_threshold' => [
                'integer' => '免运费门槛金额必须为整数。',
                'min' => '免运费门槛金额必须为 0 韩元以上。',
            ],
            'free_shipping_enabled' => [
                'boolean' => '免运费启用状态必须为真／假值。',
            ],
            'address_validation_enabled' => [
                'boolean' => '地址校验启用状态必须为真／假值。',
            ],
            'address_api_provider' => [
                'string' => '地址校验 API 服务商必须为字符串。',
                'max' => '地址校验 API 服务商最多可输入 50 个字符。',
            ],
            'types' => [
                'duplicate_code' => '存在重复的配送类型代码。',
                'name_required' => '配送类型名称为必填项。',
                'code' => [
                    'required_with' => '配送类型代码为必填项。',
                    'string' => '配送类型代码必须为字符串。',
                    'max' => '配送类型代码最多可输入 50 个字符。',
                    'regex' => '配送类型代码只能使用英文小写字母、数字、连字符和下划线。',
                ],
                'name' => [
                    'required_with' => '配送类型名称为必填项。',
                    'array' => '配送类型名称必须为多语言数组格式。',
                ],
                'category' => [
                    'required_with' => '配送类型分类为必填项。',
                    'in' => '配送类型分类必须为国内配送、海外配送、其他中的一种。',
                ],
                'is_active' => [
                    'boolean' => '配送类型启用状态必须为真／假值。',
                ],
            ],
            'carriers' => [
                'duplicate_code' => '存在重复的物流公司代码。',
                'name_required' => '物流公司名称为必填项。',
                'code' => [
                    'required_with' => '物流公司代码为必填项。',
                    'string' => '物流公司代码必须为字符串。',
                    'max' => '物流公司代码最多可输入 50 个字符。',
                    'regex' => '物流公司代码只能使用英文小写字母、数字、连字符和下划线。',
                ],
                'name' => [
                    'required_with' => '物流公司名称为必填项。',
                    'array' => '物流公司名称必须为多语言数组格式。',
                    'string' => '物流公司名称必须为字符串。',
                    'max' => '物流公司名称最多可输入 100 个字符。',
                ],
                'name_ko' => [
                    'required_with' => '物流公司名称（韩语）为必填项。',
                    'string' => '物流公司名称（韩语）必须为字符串。',
                    'max' => '物流公司名称（韩语）最多可输入 100 个字符。',
                ],
                'type' => [
                    'required_with' => '物流公司类型为必填项。',
                    'in' => '物流公司类型必须为国内配送或国际配送中的一种。',
                ],
                'tracking_url' => [
                    'string' => '物流跟踪 URL 必须为字符串。',
                    'max' => '物流跟踪 URL 最多可输入 500 个字符。',
                ],
                'is_active' => [
                    'boolean' => '物流公司启用状态必须为真／假值。',
                ],
            ],
        ],
    ],
    'user_address' => [
        'name_required' => '收货地址名称为必填项。',
        'name_string' => '收货地址名称必须为字符串。',
        'recipient_name_required' => '收件人姓名为必填项。',
        'recipient_name_string' => '收件人姓名必须为字符串。',
        'recipient_phone_required' => '收件人联系方式为必填项。',
        'recipient_phone_string' => '收件人联系方式必须为字符串。',
        'zipcode_required' => '邮政编码为必填项。',
        'address_required' => '地址为必填项。',
        'address_line_1_required' => '海外地址（Address Line 1）为必填项。',
        'intl_city_required' => '海外城市名称为必填项。',
        'intl_postal_code_required' => '海外邮政编码为必填项。',
    ],
    'review_image' => [
        'image_required' => '请选择图片。',
        'image_file' => '不是有效的文件格式。',
        'image_image' => '只能上传图片文件。',
        'image_max' => '图片大小不能超过 :maxMB。',
    ],
    'mileage' => [
        'user_required' => '请选择目标会员。',
        'amount_min' => '金额必须为 1 分以上。',
        'action_invalid' => '只能进行发放或扣减。',
        'expires_at_invalid' => '有效期必须为正确的日期。',
        'duplicate_currency' => '货币代码重复。',
        'first_must_be_default' => '第一个货币必须为默认货币（:currency）。',
        'currency_not_registered' => '未登记的货币（:currency）。请先在语言/货币设置中添加。',
        'earn_rate_required_when_enabled' => '要使用积分，默认累积率必须大于 0。',
    ],
    'options_list' => [
        'product_ids' => [
            'required' => '请选择要查询的商品。',
            'array' => '商品 ID 列表必须为数组。',
            'min' => '请至少指定 1 件要查询的商品。',
            'max' => '单次最多可查询 :max 件商品。',
            'integer' => '商品 ID 必须为数字。',
            'item_min' => '商品 ID 必须为 1 以上。',
        ],
    ],
];
