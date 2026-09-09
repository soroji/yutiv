<?php

namespace Plugins\Yutiv\SesMonitor\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\NotificationLog;
use Illuminate\Support\Facades\Log;
use Plugins\Yutiv\SesMonitor\Models\SesMessageLink;
use Plugins\Yutiv\SesMonitor\Support\PendingSentMessage;

/**
 * 발송 이력이 기록되는 순간, 방금 확보한 SES 메시지 ID 와 매핑 행을 만든다.
 *
 * 구독 훅: `core.notification_log.after_log_sent` (action)
 *   `App\Services\NotificationLogService::logSent()` 가 행을 만든 직후 발생한다.
 *
 * ── 원칙 준수 ───────────────────────────────────────────────────────────────
 *   · `notification_logs` 행을 **읽기만** 한다. id 만 가져와 우리 테이블에 쓴다.
 *   · 실패해도 발송 흐름을 깨지 않는다 (조회용 부가 데이터일 뿐).
 *   · 수신자가 다르면 매핑하지 않는다 — 잘못 이어진 링크는 없느니만 못하다.
 */
class LinkNotificationLogListener implements HookListenerInterface
{
    public function __construct(private readonly PendingSentMessage $pending) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.notification_log.after_log_sent' => [
                'method' => 'recordLink',
                'priority' => 50,
                'type' => 'action',
                // 반드시 동기 — 이 리스너는 같은 요청의 PendingSentMessage 싱글턴에서
                // 방금 발송한 메시지 ID 를 꺼낸다. 큐로 넘어가면 그 상태가 없다.
                'sync' => true,
            ],
        ];
    }

    /**
     * @param  mixed  ...$args
     */
    public function handle(...$args): void
    {
        // 단일 action 구독 — method 키로 recordLink 에 직접 연결된다.
    }

    /**
     * 발송 이력 ↔ SES 메시지 ID 매핑을 남긴다.
     */
    public function recordLink(mixed $log = null): void
    {
        $sent = $this->pending->pull();

        if ($sent === null || ! $log instanceof NotificationLog) {
            return;
        }

        // 메일 채널이 아니면 SES 와 무관하다.
        if ((string) $log->channel !== 'mail') {
            return;
        }

        // 수신자가 어긋나면 잇지 않는다. 같은 요청에서 여러 통을 보낼 때
        // 마지막 하나만 보관하는 구조라, 대조 없이 이으면 오연결이 생길 수 있다.
        $recipient = $sent['recipient'] ?? null;
        if (is_string($recipient) && $recipient !== ''
            && strcasecmp($recipient, (string) $log->recipient_identifier) !== 0) {
            return;
        }

        try {
            SesMessageLink::updateOrCreate(
                ['ses_message_id' => $sent['ses_message_id']],
                [
                    'notification_log_id' => $log->id,
                    'recipient_identifier' => (string) $log->recipient_identifier,
                    'source' => $sent['source'] ?? $log->source,
                    'sent_at' => $log->sent_at,
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('ses_monitor.link_record_failed', [
                'plugin' => 'yutiv-ses_monitor',
                'notification_log_id' => $log->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
