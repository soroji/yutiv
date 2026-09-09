<?php

namespace Plugins\Yutiv\SesMonitor\Support;

/**
 * "방금 보낸 메일의 SES 메시지 ID" 를 잠깐 들고 있는 요청 범위 보관소.
 *
 * ── 왜 필요한가 (타이밍) ────────────────────────────────────────────────────
 * 발송 이력 행이 만들어지는 순서는 이렇다.
 *
 *   DbTemplateMail::send()
 *     └ parent::send()                    → MessageSending → MessageSent   ← ID 확보 시점
 *     └ HookManager::doAction('core.mail.after_send')
 *          └ (코어) NotificationLogService::logSent()
 *               └ doAction('core.notification_log.after_log_sent', $log)   ← 로그 id 확보 시점
 *
 * 즉 SES 메시지 ID 는 발송 이력 행보다 **먼저** 나온다. 두 시점을 잇기 위해
 * MessageSent 에서 여기에 넣어 두고, after_log_sent 에서 꺼내 매핑을 만든다.
 *
 * 컨테이너 싱글턴으로 등록되므로 수명은 한 요청(또는 한 큐 잡)이다. 전역 상태를
 * 만들지 않고, 소비하면 즉시 비운다.
 */
class PendingSentMessage
{
    /** @var array<string, mixed>|null */
    private ?array $pending = null;

    /**
     * 발송 직후 정보를 담아 둔다. 항상 마지막 하나만 유지한다.
     */
    public function put(?string $sesMessageId, ?string $recipient, ?string $source): void
    {
        if ($sesMessageId === null || $sesMessageId === '') {
            $this->pending = null;

            return;
        }

        $this->pending = [
            'ses_message_id' => $sesMessageId,
            'recipient' => $recipient,
            'source' => $source,
        ];
    }

    /**
     * 담아 둔 정보를 꺼내고 비운다.
     *
     * @return array<string, mixed>|null
     */
    public function pull(): ?array
    {
        $value = $this->pending;
        $this->pending = null;

        return $value;
    }

    public function clear(): void
    {
        $this->pending = null;
    }
}
