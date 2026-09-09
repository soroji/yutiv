<?php

namespace Plugins\Yutiv\SesMonitor\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * SES 이벤트 로그 (append-only).
 *
 * 이 모델에는 의도적으로 update/delete 헬퍼를 두지 않는다. 삭제는 보존기간이 지난
 * 행을 정리하는 `yutiv:ses-prune` 한 곳에서만 일어난다.
 *
 * @property int $id
 * @property string $sns_message_id
 * @property string $topic_arn
 * @property string $event_type
 * @property string|null $ses_message_id
 * @property string|null $source
 * @property array<int, string>|null $recipients
 * @property \Illuminate\Support\Carbon $occurred_at
 * @property string|null $bounce_type
 * @property string|null $bounce_subtype
 * @property string|null $complaint_feedback_type
 * @property string|null $diagnostic_code
 * @property array<string, mixed>|null $raw_payload
 */
class SesEventLog extends Model
{
    protected $table = 'ses_event_logs';

    protected $fillable = [
        'sns_message_id',
        'topic_arn',
        'event_type',
        'ses_message_id',
        'source',
        'recipients',
        'occurred_at',
        'bounce_type',
        'bounce_subtype',
        'complaint_feedback_type',
        'diagnostic_code',
        'raw_payload',
    ];

    protected $casts = [
        'recipients' => 'array',
        'raw_payload' => 'array',
        'occurred_at' => 'datetime',
    ];

    /**
     * 이 이벤트에 대응하는 발송 이력 연결 (있으면).
     */
    public function link(): HasOne
    {
        return $this->hasOne(SesMessageLink::class, 'ses_message_id', 'ses_message_id');
    }

    /**
     * 이벤트 발생 시각과 수신(저장) 시각의 차이(초).
     *
     * Notification 에는 과거 Timestamp 상한이 없다 — 장애·배포로 밀린 정상 재시도를
     * 버리지 않기 위해서다. 대신 얼마나 늦게 도착했는지를 이 값으로 드러낸다.
     */
    public function delaySeconds(): ?int
    {
        if ($this->occurred_at === null || $this->created_at === null) {
            return null;
        }

        return max(0, $this->created_at->getTimestamp() - $this->occurred_at->getTimestamp());
    }

    /**
     * 수신자 목록을 마스킹해 반환한다.
     *
     * 관리자 화면 기본 노출용 — 원문 주소는 발송 이력 화면이 이미 권한 하에 보여주므로
     * 이벤트 목록에서까지 평문으로 반복 노출하지 않는다.
     *
     * @return array<int, string>
     */
    public function maskedRecipients(): array
    {
        return array_map(
            static fn ($address) => self::maskEmail((string) $address),
            is_array($this->recipients) ? $this->recipients : []
        );
    }

    /**
     * `alice@example.com` → `a***e@example.com`.
     */
    public static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return '***';
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at);

        if (mb_strlen($local) <= 2) {
            return mb_substr($local, 0, 1).'***'.$domain;
        }

        return mb_substr($local, 0, 1).'***'.mb_substr($local, -1).$domain;
    }
}
