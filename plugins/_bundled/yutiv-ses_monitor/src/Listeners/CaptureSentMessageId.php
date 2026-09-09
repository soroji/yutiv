<?php

namespace Plugins\Yutiv\SesMonitor\Listeners;

use Illuminate\Mail\Events\MessageSent;
use Plugins\Yutiv\SesMonitor\Support\PendingSentMessage;

/**
 * 발송 직후 전송 계층이 돌려준 메시지 ID 를 확보한다.
 *
 * SES SMTP 는 `250 Ok <ses-message-id>` 로 응답하고 Symfony Mailer 가 그 값을
 * `SentMessage::getMessageId()` 로 노출한다. 이 값이 SES 이벤트의
 * `mail.messageId` 와 같은 것이라면 발송 이력과 이벤트를 정확히 이을 수 있다.
 *
 * **확인하지 못한 전제**: 이 동등성은 운영 SMTP 응답으로만 최종 확인된다
 * (로컬에 vendor/Symfony 도 AWS 계정도 없다). 그래서 이 연결은 **부가 기능**이며,
 * 어긋나더라도 SES 이벤트는 `ses_message_id` 로 독립 조회된다. 관리자 화면도
 * "미연결" 목록을 1급 시민으로 취급한다.
 */
class CaptureSentMessageId
{
    public function __construct(private readonly PendingSentMessage $pending) {}

    public function handle(MessageSent $event): void
    {
        $messageId = null;

        try {
            $messageId = $event->sent->getMessageId();
        } catch (\Throwable) {
            // 전송기에 따라 ID 를 제공하지 않을 수 있다. 연결은 부가 기능이므로 조용히 포기.
            $this->pending->clear();

            return;
        }

        $headers = $event->message->getHeaders();

        $source = $headers->has('X-G7-Source')
            ? (string) $headers->get('X-G7-Source')?->getBodyAsString()
            : null;

        $recipient = null;
        $to = $event->message->getTo();
        if ($to !== []) {
            $recipient = $to[0]->getAddress();
        }

        $this->pending->put(
            \Plugins\Yutiv\SesMonitor\Models\SesMessageLink::normalizeMessageId($messageId),
            $recipient,
            $source !== null && $source !== '' ? mb_substr($source, 0, 100) : null,
        );
    }
}
