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
            $table->text('bank_snapshot')->nullable()->comment('실제 확인 당시 계좌 암호화 스냅샷');
            $table->char('bank_identity_key', 64)->nullable()->comment('확인 당시 계좌별 집계용 HMAC');
            $table->index(['tenant_id', 'bank_identity_key', 'id'], 'live_cash_bank_identity');
        });
    }

    public function down(): void
    {
        if (DB::table('live_fund_entries')->whereNotNull('bank_snapshot')->exists()) {
            throw new RuntimeException('실제 계좌 확인 기록 보존을 위해 전진 복구가 필요합니다.');
        }
        Schema::table('live_fund_entries', function (Blueprint $table) {
            $table->dropIndex('live_cash_bank_identity');
            $table->dropColumn(['bank_snapshot', 'bank_identity_key']);
        });
    }
};
