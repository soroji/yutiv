<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SES 이벤트 로그 테이블 — **append-only**.
 *
 * `notification_logs` 는 애플리케이션이 "발송을 시도했다" 는 기록이고, 이 테이블은
 * AWS 가 "그 메일이 실제로 어떻게 됐는지" 알려준 결과다. 두 사실은 출처도 시점도
 * 다르므로 절대 합치지 않는다 — 이 플러그인은 `notification_logs` 를 읽기만 한다.
 *
 * 개인정보 최소화:
 *   - `recipients` 는 이벤트에 포함된 수신자 주소 배열이다. 발송 이력에 이미 있는
 *     정보이며 반송/스팸신고 대응에 필수라 저장하되, 화면 기본 노출은 마스킹한다.
 *   - `raw_payload` 는 `SES_EVENT_RAW_PAYLOAD_ENABLED=true` 일 때만 채워진다.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ses_event_logs', function (Blueprint $table) {
            $table->id()->comment('ID');

            // SNS 메시지 단위 멱등 키. SNS 는 at-least-once 라 같은 MessageId 가
            // 여러 번 도착할 수 있다. unique 로 중복 저장을 DB 레벨에서 막는다.
            $table->string('sns_message_id', 100)->unique()->comment('SNS MessageId (멱등 키)');

            $table->string('topic_arn', 255)->comment('SNS TopicArn');
            $table->string('event_type', 30)->comment('SES 이벤트: send/reject/bounce/complaint/delivery/deliveryDelay/renderingFailure');

            // SES 가 부여한 메시지 ID. 발송 이력과의 연결 키이자 독립 조회 키.
            $table->string('ses_message_id', 200)->nullable()->comment('SES mail.messageId');

            $table->string('source', 255)->nullable()->comment('발신 주소 (mail.source)');
            $table->json('recipients')->nullable()->comment('수신자 목록 (개인정보 최소화 대상)');
            $table->timestamp('occurred_at')->comment('이벤트 발생 시각 (AWS 기준)');

            // 반송/스팸신고 세부 — 목록에서 사유를 바로 보기 위해 별도 컬럼으로 승격.
            $table->string('bounce_type', 50)->nullable()->comment('Permanent/Transient/Undetermined');
            $table->string('bounce_subtype', 50)->nullable()->comment('General/NoEmail/Suppressed 등');
            $table->string('complaint_feedback_type', 50)->nullable()->comment('abuse/fraud/virus 등');
            $table->text('diagnostic_code')->nullable()->comment('SMTP 진단 코드 (첫 수신자 기준)');

            $table->json('raw_payload')->nullable()->comment('원문 SES 이벤트 (설정으로 켠 경우만)');

            $table->timestamps();

            $table->index('ses_message_id', 'idx_ses_event_logs_ses_message_id');
            $table->index('event_type', 'idx_ses_event_logs_event_type');
            $table->index('occurred_at', 'idx_ses_event_logs_occurred_at');
            $table->index(['event_type', 'occurred_at'], 'idx_ses_event_logs_type_occurred');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ses_event_logs');
    }
};
