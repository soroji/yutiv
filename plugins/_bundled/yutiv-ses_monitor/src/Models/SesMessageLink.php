<?php

namespace Plugins\Yutiv\SesMonitor\Models;

use App\Models\NotificationLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 발송 이력 ↔ SES 메시지 ID 매핑.
 *
 * 이 플러그인이 발송 시점에 새로 쌓는 조회용 행이다. `notification_logs` 는 읽기만 한다.
 *
 * @property int $id
 * @property string $ses_message_id
 * @property int|null $notification_log_id
 * @property string|null $recipient_identifier
 * @property string|null $source
 * @property \Illuminate\Support\Carbon|null $sent_at
 */
class SesMessageLink extends Model
{
    protected $table = 'ses_message_links';

    protected $fillable = [
        'ses_message_id',
        'notification_log_id',
        'recipient_identifier',
        'source',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    /**
     * 연결된 발송 이력 (코어 모델 — 읽기 전용으로만 쓴다).
     */
    public function notificationLog(): BelongsTo
    {
        return $this->belongsTo(NotificationLog::class, 'notification_log_id');
    }

    /**
     * 전송 계층이 준 메시지 ID 를 비교 가능한 형태로 정규화한다.
     *
     * SMTP 응답과 SES 이벤트의 표기가 미묘하게 다를 수 있어(꺾쇠 포함, 앞뒤 공백)
     * 양쪽 모두 이 함수를 통과시킨 값으로 저장·대조한다.
     */
    public static function normalizeMessageId(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = trim($raw);
        $value = trim($value, '<>');
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
