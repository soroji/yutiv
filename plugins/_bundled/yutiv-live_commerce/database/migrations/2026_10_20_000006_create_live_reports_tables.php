<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $indexes = [
        'live_orders' => ['live_ledger_orders' => ['tenant_id', 'created_at', 'id']],
        'live_order_lines' => ['live_ledger_lines' => ['tenant_id', 'order_id', 'option_id']],
        'live_order_events' => ['live_ledger_events' => ['tenant_id', 'created_at', 'type'], 'live_ledger_deposits' => ['tenant_id', 'order_id', 'type', 'id']],
        'live_stock_movements' => ['live_ledger_history' => ['tenant_id', 'unit_id', 'id'], 'live_ledger_moves' => ['tenant_id', 'created_at', 'type']],
        'live_options' => ['live_ledger_options' => ['tenant_id', 'product_id', 'id']],
    ];

    public function up(): void
    {
        Schema::create('live_reports', function (Blueprint $t) {
            $t->id()->comment('저장 운영 보고서 번호');
            $t->unsignedBigInteger('tenant_id')->comment('소유 업체, 보고서 생성 중 업체 FK 공유 잠금 방지');
            $t->uuid('uuid')->unique()->comment('생성마다 별도 보존하는 보고서 버전');
            $t->unsignedBigInteger('actor_id')->comment('생성자 번호, 이름은 result에 보존');
            $t->json('result')->comment('업체명 기간 시간대 집계 기준 생성 시각 전체 합계 불변 사본');
            $t->unsignedBigInteger('row_count')->comment('저장 상세 행 수');
            $t->char('digest', 64)->comment('집계 결과 SHA256');
            $t->timestamps();
            $t->index(['tenant_id', 'id']);
        });
        Schema::create('live_report_rows', function (Blueprint $t) {
            $t->id()->comment('저장 상세 번호');
            $t->unsignedBigInteger('tenant_id')->comment('소유 업체, 업체 FK 공유 잠금 방지');
            $t->foreignId('report_id')->constrained('live_reports')->restrictOnDelete()->comment('불변 보고서');
            $t->unsignedBigInteger('sequence')->comment('분할 출력 순번');
            $t->string('kind', 24)->comment('option/order/event/movement 상세 분류');
            $t->json('data')->comment('집계에 사용한 당시 상세 값');
            $t->char('digest', 64)->comment('상세 값 SHA256');
            $t->timestamps();
            $t->unique(['tenant_id', 'report_id', 'sequence'], 'live_report_sequence');
        });
        foreach ($this->indexes as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                if (! Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->index($columns, $name));
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('live_reports') && DB::table('live_reports')->exists()) {
            throw new RuntimeException('저장 보고서가 있어 후진 복구할 수 없습니다.');
        }
        Schema::dropIfExists('live_report_rows');
        Schema::dropIfExists('live_reports');
        foreach ($this->indexes as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                if (Schema::hasTable($table) && Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
        }
    }
};
