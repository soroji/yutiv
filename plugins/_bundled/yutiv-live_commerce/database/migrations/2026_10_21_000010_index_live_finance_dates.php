<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_fund_entries', function (Blueprint $table) {
            $table->index(['tenant_id', 'fund_id', 'occurred_on', 'id'], 'live_fund_date_lookup');
        });
        Schema::table('live_money_transfers', function (Blueprint $table) {
            $table->date('cancelled_on')->nullable()->comment('취소 또는 실제 반환 확인일');
        });
    }

    public function down(): void
    {
        if (DB::table('live_money_transfers')->whereNotNull('cancelled_on')->exists()) {
            throw new RuntimeException('이체 취소 기록 보존을 위해 전진 복구가 필요합니다.');
        }
        Schema::table('live_money_transfers', fn (Blueprint $table) => $table->dropColumn('cancelled_on'));
        Schema::table('live_fund_entries', fn (Blueprint $table) => $table->dropIndex('live_fund_date_lookup'));
    }
};
