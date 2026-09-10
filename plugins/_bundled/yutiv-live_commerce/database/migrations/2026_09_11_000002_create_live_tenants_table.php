<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 라이브 판매 채널(입점 업체).
 *
 * Phase 0·1-A 에서는 `config('yutiv_live_commerce.known_tenants')` 가 유일한 출처였다.
 * Phase 1-B 부터는 **이 테이블이 권위 소스**다 — 설정은 DB 가 아직 없는 초기 부팅에서만
 * fallback 으로 쓰인다.
 *
 * ── 공개 판정 ──────────────────────────────────────────────────────────────
 * `status = 'active'` 인 채널만 공개된다. `draft`(준비 중)·`suspended`(정지)는 목록에도
 * 나오지 않고 직접 주소로 들어와도 404 다 — "존재하지만 막혔다" 를 알려 주면 입점사
 * 목록이 열거되기 때문이다.
 *
 * ── 소유자 ─────────────────────────────────────────────────────────────────
 * `owner_user_id` 는 TeeWide 회원이다. YUTIV `users` 가 아니다. 초기 데이터처럼 아직
 * 주인이 없는 채널이 있으므로 nullable 이며, 회원이 삭제되면 채널은 남고 소유만 풀린다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_tenants', function (Blueprint $table) {
            $table->id()->comment('ID');

            $table->char('uuid', 36)->unique()->comment('외부 노출용 UUID');

            // 라우트의 slug 패턴([a-z0-9][a-z0-9-]{0,62})과 같은 상한(63)을 쓴다.
            $table->string('slug', 63)->unique()->comment('채널 주소 (소문자·숫자·하이픈)');

            $table->string('name', 100)->comment('채널 이름');
            $table->text('description')->nullable()->comment('채널 소개');
            $table->string('initials', 8)->nullable()->comment('썸네일 약자');

            $table->string('status', 20)->default('draft')->comment('draft/active/suspended');

            $table->unsignedBigInteger('owner_user_id')->nullable()->comment('소유 TeeWide 회원');

            $table->timestamps();

            $table->index('status', 'idx_live_tenants_status');
            // 공개 채널 조회는 항상 "status=active AND slug=?" 형태다.
            $table->index(['status', 'slug'], 'idx_live_tenants_status_slug');

            $table->foreign('owner_user_id', 'fk_live_tenants_owner_user_id')
                ->references('id')
                ->on('teewide_users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_tenants');
    }
};
