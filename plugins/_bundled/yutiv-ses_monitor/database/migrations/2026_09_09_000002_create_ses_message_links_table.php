<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 발송 이력 ↔ SES 메시지 ID 연결 테이블.
 *
 * ── 왜 별도 테이블인가 ──────────────────────────────────────────────────────
 * `notification_logs` 에는 메일 Message-ID 를 담는 컬럼이 **없다**
 * (2026_04_08_000004_create_notification_logs_table.php 확인). 그래서 이미 쌓인
 * 행을 SES 이벤트와 맞출 방법이 없고, 기존 행에 컬럼을 추가하거나 값을 채워 넣는
 * 것은 "기존 행을 임의로 수정하지 않는다" 는 원칙에 어긋난다.
 *
 * 대신 이 플러그인이 소유하는 조회용 매핑을 **발송 시점에** 새로 쌓는다. 원본
 * 테이블은 읽기만 하고, 연결은 전부 이 테이블을 통해 이뤄진다. 연결이 없으면
 * (플러그인 설치 이전 발송, SMTP 응답에서 ID 를 못 얻은 경우) SES 이벤트는
 * `ses_message_id` 로 독립 조회된다 — 연결은 부가 기능이지 전제가 아니다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ses_message_links', function (Blueprint $table) {
            $table->id()->comment('ID');

            // 전송 계층이 돌려준 메시지 ID(SES SMTP 응답의 SES message id).
            // ses_event_logs.ses_message_id 와 대조한다.
            $table->string('ses_message_id', 200)->comment('전송 시 확보한 SES/SMTP 메시지 ID');

            // 연결된 발송 이력. 로그가 지워지면 매핑도 함께 정리한다(참조 무결성).
            $table->foreignId('notification_log_id')->nullable()
                ->comment('notification_logs.id (연결된 경우)')
                ->constrained('notification_logs')->nullOnDelete();

            $table->string('recipient_identifier', 255)->nullable()->comment('수신자 (대조 보조용 스냅샷)');
            $table->string('source', 100)->nullable()->comment('발송 출처: notification, test_mail 등');
            $table->timestamp('sent_at')->nullable()->comment('발송 시각');

            $table->timestamps();

            $table->unique('ses_message_id', 'uq_ses_message_links_message_id');
            $table->index('notification_log_id', 'idx_ses_message_links_log');
            $table->index('sent_at', 'idx_ses_message_links_sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ses_message_links');
    }
};
