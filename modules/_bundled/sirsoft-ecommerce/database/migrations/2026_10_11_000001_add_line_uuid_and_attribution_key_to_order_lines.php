<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['ecommerce_carts', 'ecommerce_order_options'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                // 기존 주문은 NULL 유지: 과거 귀속·리워드를 소급 생성하지 않습니다.
                if (! Schema::hasColumn($name, 'line_uuid')) {
                    $table->uuid('line_uuid')->nullable()->index()->comment('불변 원본 항목 식별자, 분할 시 보존');
                }
                if (! Schema::hasColumn($name, 'attribution_key')) {
                    $table->string('attribution_key', 64)->nullable()->index()->comment('서버 검증 판매 귀속 참조');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['ecommerce_order_options', 'ecommerce_carts'] as $name) {
            foreach (['attribution_key', 'line_uuid'] as $column) {
                if (Schema::hasColumn($name, $column)) {
                    Schema::table($name, fn (Blueprint $table) => $table->dropColumn($column));
                }
            }
        }
    }
};
