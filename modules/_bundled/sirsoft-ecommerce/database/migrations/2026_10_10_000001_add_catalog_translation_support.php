<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Additive SEO maps keep legacy scalar SEO fields and all IDs/relations intact.
        Schema::table('ecommerce_categories', function (Blueprint $table) {
            $table->json('meta_title_translations')->nullable()->comment('언어별 카테고리 SEO 제목; 기존 평문은 fallback');
            $table->json('meta_description_translations')->nullable()->comment('언어별 카테고리 SEO 설명; 기존 평문은 fallback');
            $table->json('translation_sources')->nullable()->comment('번역 적용 시 한국어 원문 지문');
        });
        Schema::table('ecommerce_products', function (Blueprint $table) {
            $table->json('translation_sources')->nullable()->comment('번역 적용 시 한국어 원문 지문');
            $table->json('meta_keywords_translations')->nullable()->comment('언어별 SEO 키워드; 기존 목록은 fallback');
        });
        Schema::create('ecommerce_translation_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary()->comment('번역 작업 ID');
            $table->unsignedBigInteger('owner_id')->index()->comment('요청 관리자');
            $table->uuid('request_id')->comment('중복 요청 방지 키');
            $table->string('fingerprint', 64)->comment('요청 데이터 지문');
            $table->string('kind', 16)->comment('상품 또는 카테고리');
            $table->unsignedBigInteger('entity_id')->nullable()->comment('신규 폼은 null');
            $table->boolean('cancelled')->default(false)->comment('작업 취소');
            $table->json('items')->comment('항목별 원문·번역·상태; 최종 번역 저장소 아님');
            $table->json('terms')->comment('보존 용어');
            $table->timestamps();
            $table->unique(['owner_id', 'request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecommerce_translation_jobs');
        Schema::table('ecommerce_products', fn (Blueprint $table) => $table->dropColumn(['translation_sources', 'meta_keywords_translations']));
        Schema::table('ecommerce_categories', fn (Blueprint $table) => $table->dropColumn(['translation_sources', 'meta_title_translations', 'meta_description_translations']));
    }
};
