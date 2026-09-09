<?php

namespace Plugins\Yutiv\SesMonitor\Support;

/**
 * SES 이벤트 JSON → `ses_event_logs` 행 변환.
 *
 * SES 는 두 가지 형태로 이벤트를 보낸다. 둘 다 받아 하나의 표기로 정규화한다.
 *
 *   1) Configuration set event publishing — `eventType: "Bounce" | "Delivery" | ...`
 *   2) 구형 feedback notification — `notificationType: "Bounce" | "Complaint" | "Delivery"`
 *
 * 정규화 표기는 요구된 7종이다:
 *   send, reject, bounce, complaint, delivery, deliveryDelay, renderingFailure
 *
 * 이 클래스는 순수 변환만 한다 — DB 도 로그도 건드리지 않는다.
 *
 * SnsMessageValidator 와 같은 이유로 **PHP 7.4 파서로도 읽히는 문법**만 쓴다.
 * tests/Ses 하네스가 이 파일을 그대로 require 해 파싱 로직을 실행 검증한다.
 */
class SesEventParser
{
    /**
     * AWS 표기 → 정규화 표기.
     *
     * @var array<string, string>
     */
    const EVENT_TYPE_MAP = [
        'send' => 'send',
        'reject' => 'reject',
        'bounce' => 'bounce',
        'complaint' => 'complaint',
        'delivery' => 'delivery',
        'deliverydelay' => 'deliveryDelay',
        'renderingfailure' => 'renderingFailure',
    ];

    /** @var array<int, string> */
    private $allowedEventTypes;

    /**
     * @param  array<int, string>  $allowedEventTypes
     */
    public function __construct(array $allowedEventTypes)
    {
        $this->allowedEventTypes = $allowedEventTypes;
    }

    /**
     * 이벤트 본문에서 정규화된 이벤트 타입을 뽑는다.
     *
     * @param  array<string, mixed>  $event
     * @return string|null 허용 목록에 없으면 null
     */
    public function eventType(array $event): ?string
    {
        $raw = isset($event['eventType']) ? $event['eventType'] : (isset($event['notificationType']) ? $event['notificationType'] : null);

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $key = strtolower($raw);
        $normalized = isset(self::EVENT_TYPE_MAP[$key]) ? self::EVENT_TYPE_MAP[$key] : null;

        if ($normalized === null || ! in_array($normalized, $this->allowedEventTypes, true)) {
            return null;
        }

        return $normalized;
    }

    /**
     * `ses_event_logs` 에 넣을 속성 배열을 만든다.
     *
     * @param  array<string, mixed>  $event  SES 이벤트 본문 (SNS Message 를 파싱한 것)
     * @param  bool  $storeRawPayload  원문 저장 여부
     * @return array<string, mixed>
     */
    public function toAttributes(array $event, string $eventType, bool $storeRawPayload): array
    {
        $mail = isset($event['mail']) && is_array($event['mail']) ? $event['mail'] : [];
        $bounce = isset($event['bounce']) && is_array($event['bounce']) ? $event['bounce'] : [];
        $complaint = isset($event['complaint']) && is_array($event['complaint']) ? $event['complaint'] : [];

        return [
            'event_type' => $eventType,
            'ses_message_id' => $this->stringOrNull(isset($mail['messageId']) ? $mail['messageId'] : null, 200),
            'source' => $this->stringOrNull(isset($mail['source']) ? $mail['source'] : null, 255),
            'recipients' => $this->recipients($event, $mail),
            'occurred_at' => $this->occurredAt($event, $mail),
            'bounce_type' => $this->stringOrNull(isset($bounce['bounceType']) ? $bounce['bounceType'] : null, 50),
            'bounce_subtype' => $this->stringOrNull(isset($bounce['bounceSubType']) ? $bounce['bounceSubType'] : null, 50),
            'complaint_feedback_type' => $this->stringOrNull(isset($complaint['complaintFeedbackType']) ? $complaint['complaintFeedbackType'] : null, 50),
            'diagnostic_code' => $this->diagnosticCode($bounce),
            'raw_payload' => $storeRawPayload ? $event : null,
        ];
    }

    /**
     * 수신자 목록.
     *
     * 반송·스팸신고는 "누가 문제였는지" 가 핵심이라 이벤트별 대상 주소를 우선 쓰고,
     * 없으면 `mail.destination` 으로 폴백한다.
     *
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $mail
     * @return array<int, string>
     */
    private function recipients(array $event, array $mail): array
    {
        $candidates = [];

        foreach (['bounce' => 'bouncedRecipients', 'complaint' => 'complainedRecipients', 'delivery' => 'recipients', 'deliveryDelay' => 'delayedRecipients'] as $section => $key) {
            $block = isset($event[$section]) ? $event[$section] : null;
            if (! is_array($block) || ! isset($block[$key]) || ! is_array($block[$key])) {
                continue;
            }

            foreach ($block[$key] as $entry) {
                if (is_string($entry)) {
                    $candidates[] = $entry;
                } elseif (is_array($entry) && isset($entry['emailAddress']) && is_string($entry['emailAddress'])) {
                    $candidates[] = $entry['emailAddress'];
                }
            }
        }

        if ($candidates === [] && isset($mail['destination']) && is_array($mail['destination'])) {
            foreach ($mail['destination'] as $entry) {
                if (is_string($entry)) {
                    $candidates[] = $entry;
                }
            }
        }

        // 주소는 최대 20개까지만 — 대량 수신 이벤트로 행이 비대해지지 않게 한다.
        $unique = array_values(array_unique(array_filter($candidates, function ($v) {
            return $v !== '';
        })));

        return array_slice($unique, 0, 20);
    }

    /**
     * 이벤트 발생 시각. 이벤트별 timestamp 를 우선하고 mail.timestamp 로 폴백한다.
     *
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $mail
     */
    private function occurredAt(array $event, array $mail): string
    {
        foreach (['bounce', 'complaint', 'delivery', 'deliveryDelay', 'send', 'reject', 'failure'] as $section) {
            $block = isset($event[$section]) ? $event[$section] : null;
            if (is_array($block) && isset($block['timestamp']) && is_string($block['timestamp'])) {
                $parsed = strtotime($block['timestamp']);
                if ($parsed !== false) {
                    return gmdate('Y-m-d H:i:s', $parsed);
                }
            }
        }

        if (isset($mail['timestamp']) && is_string($mail['timestamp'])) {
            $parsed = strtotime($mail['timestamp']);
            if ($parsed !== false) {
                return gmdate('Y-m-d H:i:s', $parsed);
            }
        }

        return gmdate('Y-m-d H:i:s');
    }

    /**
     * 첫 수신자의 SMTP 진단 코드.
     *
     * @param  array<string, mixed>  $bounce
     */
    private function diagnosticCode(array $bounce): ?string
    {
        $recipients = isset($bounce['bouncedRecipients']) ? $bounce['bouncedRecipients'] : null;

        if (! is_array($recipients)) {
            return null;
        }

        foreach ($recipients as $recipient) {
            if (is_array($recipient) && isset($recipient['diagnosticCode']) && is_string($recipient['diagnosticCode'])) {
                return mb_substr($recipient['diagnosticCode'], 0, 2000);
            }
        }

        return null;
    }

    /**
     * @param  mixed  $value
     */
    private function stringOrNull($value, int $limit): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return mb_substr($value, 0, $limit);
    }
}
