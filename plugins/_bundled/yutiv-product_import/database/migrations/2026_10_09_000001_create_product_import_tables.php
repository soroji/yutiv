<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('yutiv_product_import_runs', function (Blueprint $t) {
            $t->uuid('id')->primary()->comment('일괄등록 작업 ID');
            $t->unsignedBigInteger('user_id')->index()->comment('요청 관리자');
            $t->string('status', 20)->comment('미리보기 또는 확정 상태');
            $t->string('filename')->comment('원본 파일 이름');
            $t->json('errors')->nullable()->comment('시트 행 열 검증 오류');
            $t->timestamps();
        });
        Schema::create('yutiv_product_import_rows', function (Blueprint $t) {
            $t->id();
            $t->uuid('run_id')->index()->comment('일괄등록 작업 ID');
            $t->unsignedInteger('source_row')->comment('상품 시트 원본 행');
            $t->string('management_code', 50)->comment('문자열 관리코드');
            $t->json('source')->comment('원본 상품 및 옵션 행');
            $t->json('payload')->comment('서버 검증 상품 입력');
            $t->json('image_urls')->comment('반입할 이미지 URL');
            $t->string('status', 20)->index()->comment('상품별 처리 상태');
            $t->string('product_code', 50)->nullable()->unique()->comment('기존 채번 서비스 상품코드');
            $t->unsignedBigInteger('product_id')->nullable()->comment('성공 상품 ID');
            $t->string('storage_disk')->nullable()->comment('정리할 이미지 저장소');
            $t->text('error')->nullable()->comment('상품별 실패 내용');
            $t->unsignedInteger('attempts')->default(0)->comment('처리 시도 횟수');
            $t->timestamp('dispatched_at')->nullable()->comment('큐 전송 시각');
            $t->timestamps();
            $t->unique(['run_id', 'source_row']);
        });
        Schema::create('yutiv_product_import_identities', function (Blueprint $t) {
            $t->id();
            $t->string('management_code', 50)->unique()->comment('중복 금지 관리코드');
            $t->unsignedBigInteger('row_id')->unique()->comment('코드 예약 상품 행');
            $t->unsignedBigInteger('product_id')->nullable()->comment('등록 상품 ID');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('yutiv_product_import_identities');
        Schema::dropIfExists('yutiv_product_import_rows');
        Schema::dropIfExists('yutiv_product_import_runs');
    }
};
