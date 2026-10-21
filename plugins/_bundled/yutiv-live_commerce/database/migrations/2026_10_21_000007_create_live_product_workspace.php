<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_categories', function (Blueprint $t) {
            $t->id()->comment('라이브 업체 전용 분류');
            $t->unsignedBigInteger('tenant_id')->comment('소유 업체');
            $t->string('name', 100)->comment('분류명');
            $t->boolean('active')->default(true)->comment('선택 가능 여부');
            $t->timestamps();
            $t->unique(['tenant_id', 'name']);
        });
        Schema::table('live_products', function (Blueprint $t) {
            $t->unsignedBigInteger('category_id')->nullable()->comment('라이브 분류, 기존 상품은 미분류');
            $t->index(['tenant_id', 'category_id', 'id'], 'live_product_category');
        });
        Schema::table('live_options', fn (Blueprint $t) => $t->json('attributes')->nullable()->comment('동적 옵션 종류와 값, 기존 옵션은 이름 유지'));
        Schema::create('live_receipt_items', function (Blueprint $t) {
            $t->id()->comment('입고 당시 매입 원가 기록');
            $t->unsignedBigInteger('tenant_id')->comment('소유 업체');
            $t->foreignId('receipt_id')->comment('입고 배치')->constrained('live_receipts')->restrictOnDelete();
            $t->unsignedBigInteger('product_id')->comment('상품');
            $t->unsignedBigInteger('option_id')->comment('기존 옵션 연결');
            $t->unsignedBigInteger('unit_cost')->nullable()->comment('원 단위 입고단가, null 미등록, 0 명시적 무상');
            $t->unsignedInteger('quantity')->comment('입고 수량');
            $t->unsignedBigInteger('purchase_amount')->nullable()->comment('단가 곱하기 수량, 매출원가 아님');
            $t->char('currency', 3)->default('KRW')->comment('기존 원화 정수 통화');
            $t->timestamps();
            $t->unique(['tenant_id', 'receipt_id', 'option_id'], 'live_receipt_cost_unique');
            $t->index(['tenant_id', 'product_id', 'receipt_id'], 'live_receipt_cost_product');
        });
        Schema::table('live_stock_units', fn (Blueprint $t) => $t->unsignedBigInteger('receipt_item_id')->nullable()->index()->comment('원래 입고단가 연결, 기존 물품 null'));
        Schema::create('live_label_requests', function (Blueprint $t) {
            $t->id()->comment('실물 성공이 아닌 인쇄 요청 번호');
            $t->unsignedBigInteger('tenant_id')->comment('소유 업체');
            $t->unsignedBigInteger('actor_id')->comment('요청자');
            $t->string('operation_key', 100)->comment('중복 요청 방지 키');
            $t->unsignedBigInteger('original_id')->nullable()->comment('재출력 원본 요청, 과거 발행일 추정 안 함');
            $t->json('items')->comment('UUID 상품명 옵션 연결, 원가 제외');
            $t->unsignedInteger('width')->comment('가로 mm');
            $t->unsignedInteger('height')->comment('세로 mm');
            $t->unsignedInteger('copies')->comment('물품당 장수');
            $t->unsignedInteger('total_labels')->comment('총 라벨 장수');
            $t->timestamps();
            $t->unique(['tenant_id', 'operation_key']);
            $t->index(['tenant_id', 'id']);
        });
        Schema::create('live_label_request_units', function (Blueprint $t) {
            $t->id()->comment('출력 대상 연결');
            $t->unsignedBigInteger('tenant_id')->comment('소유 업체');
            $t->foreignId('label_request_id')->comment('인쇄 요청')->constrained('live_label_requests')->restrictOnDelete();
            $t->uuid('unit_uuid')->comment('기존 물품 UUID');
            $t->timestamps();
            $t->unique(['tenant_id', 'label_request_id', 'unit_uuid'], 'live_label_request_unit');
            $t->index(['tenant_id', 'unit_uuid', 'label_request_id'], 'live_label_unit_history');
        });
    }

    public function down(): void
    {
        foreach (['live_receipt_items', 'live_label_requests', 'live_categories'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('업무 기록이 있어 전진 복구가 필요합니다.');
            }
        }
        if (Schema::hasColumn('live_stock_units', 'receipt_item_id')) {
            Schema::table('live_stock_units', fn (Blueprint $t) => $t->dropColumn('receipt_item_id'));
        }
        if (Schema::hasColumn('live_products', 'category_id')) {
            Schema::table('live_products', function (Blueprint $t) {
                $t->dropIndex('live_product_category');
                $t->dropColumn('category_id');
            });
        }
        if (Schema::hasColumn('live_options', 'attributes')) {
            Schema::table('live_options', fn (Blueprint $t) => $t->dropColumn('attributes'));
        }
        Schema::dropIfExists('live_label_request_units');
        Schema::dropIfExists('live_label_requests');
        Schema::dropIfExists('live_receipt_items');
        Schema::dropIfExists('live_categories');
    }
};
