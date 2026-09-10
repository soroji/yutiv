<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TeeWide 회원 — YUTIV `users` 와 **완전히 별개의 테이블**.
 *
 * ── 왜 users 를 재사용하지 않는가 ──────────────────────────────────────────
 * TeeWide 는 yutiv.com 과 다른 도메인·다른 세션·다른 브랜드다. 같은 테이블을 쓰면
 * "쇼핑몰 회원이 곧 TeeWide 회원" 이 되어 Phase 0 에서 증명한 세션 경계가 데이터
 * 레벨에서 무너진다. 두 서비스의 탈퇴·정지·이메일 인증 정책도 서로 다를 수 있다.
 *
 * 그래서 별도 테이블 + 별도 guard(`teewide`) + 별도 provider(`teewide_users`) 를 쓴다.
 * YUTIV 의 `users` 테이블과 `web` guard 는 이 플러그인이 읽지도 쓰지도 않는다.
 *
 * ── 외부 노출 식별자 ───────────────────────────────────────────────────────
 * `id` 는 순번이라 회원 수와 가입 순서가 드러난다. 외부(URL·API)에는 `uuid` 만 쓴다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teewide_users', function (Blueprint $table) {
            $table->id()->comment('ID');

            $table->char('uuid', 36)->unique()->comment('외부 노출용 UUID');

            // 191 = utf8mb4 에서 단일 컬럼 unique 인덱스가 안전한 최대 길이.
            $table->string('email', 191)->unique()->comment('로그인 이메일 (소문자 정규화 저장)');
            $table->string('password', 255)->comment('해시된 비밀번호 (평문 저장 금지)');

            $table->string('name', 100)->comment('이름');
            $table->string('nickname', 50)->nullable()->comment('표시용 별명');

            // enum 대신 문자열 — 상태 값이 늘어날 때 ALTER 없이 확장한다.
            $table->string('status', 20)->default('active')->comment('active/suspended/withdrawn');

            $table->timestamp('email_verified_at')->nullable()->comment('이메일 인증 시각');
            $table->timestamp('last_login_at')->nullable()->comment('마지막 로그인 시각');

            $table->rememberToken();
            $table->timestamps();

            $table->index('status', 'idx_teewide_users_status');
            $table->index('created_at', 'idx_teewide_users_created_at');
        });
    }

    public function down(): void
    {
        // live_tenants 가 이 테이블을 FK 로 참조한다. 그쪽 마이그레이션이 나중에
        // 실행되므로 롤백은 역순(먼저 live_tenants, 그다음 여기)으로 진행된다.
        Schema::dropIfExists('teewide_users');
    }
};
