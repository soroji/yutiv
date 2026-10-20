<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_receipts', function (Blueprint $t) {
            $t->id()->comment('입고 배치 번호');
            $t->foreignId('tenant_id')->constrained('live_tenants')->restrictOnDelete()->comment('소유 업체');
            $t->string('receipt_key', 100)->comment('물품 receipt_key 연결');
            $t->unsignedBigInteger('actor_id')->comment('입고 처리자');
            $t->foreignId('product_id')->constrained('live_products')->restrictOnDelete()->comment('입고 상품');
            $t->timestamp('received_at')->comment('실제 입고 확정 시각 UTC, 소급 불가');
            $t->string('location', 100)->comment('입고 당시 위치');
            $t->text('memo')->nullable()->comment('입고 메모');
            $t->json('items')->comment('옵션 ID, 명칭, 확정 수량 스냅샷');
            $t->unsignedInteger('quantity')->comment('총 확정 수량');
            $t->timestamps();
            $t->unique(['tenant_id', 'receipt_key']);
            $t->index(['tenant_id', 'received_at']);
        });
        Schema::table('live_stock_units', function (Blueprint $t) {
            $t->index(['tenant_id', 'receipt_key'], 'live_unit_receipt');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('live_receipts') && DB::table('live_receipts')->exists()) {
            throw new RuntimeException('확정 입고가 있어 전진 복구가 필요합니다.');
        }
        if (Schema::hasTable('live_stock_units') && Schema::hasIndex('live_stock_units', 'live_unit_receipt')) {
            Schema::table('live_stock_units', fn (Blueprint $t) => $t->dropIndex('live_unit_receipt'));
        }
        Schema::dropIfExists('live_receipts');
    }
};
