<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('personal_store_memberships', 'proof_hash')) {
            Schema::table('personal_store_memberships', function (Blueprint $table) {
                $table->string('proof_hash', 64)->nullable()->unique()->comment('일회성 가입 증명 소비 키');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('personal_store_memberships', 'proof_hash')) {
            Schema::table('personal_store_memberships', function (Blueprint $table) {
                $table->dropUnique(['proof_hash']);
                $table->dropColumn('proof_hash');
            });
        }
    }
};
