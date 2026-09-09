<?php

namespace Plugins\Yutiv\SesMonitor\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Plugins\Yutiv\SesMonitor\Models\SesEventLog;
use Plugins\Yutiv\SesMonitor\Support\SesConfig;
use Plugins\Yutiv\SesMonitor\Support\SesEventParser;
use Plugins\Yutiv\SesMonitor\Support\SnsMessageValidator;
use Plugins\Yutiv\SesMonitor\Support\SubscriptionConfirmer;

/**
 * `POST /webhooks/aws/ses` — Amazon SNS 가 SES 이벤트를 밀어 넣는 공개 endpoint.
 *
 * ── 보안 계약 ───────────────────────────────────────────────────────────────
 *   · 인증 없음(공개) 이지만 **서명 검증을 통과하지 못한 요청은 아무 것도 하지 않는다.**
 *     저장도, SubscribeURL 호출도, 원문 로깅도 없다.
 *   · 검증 순서는 싼 것부터: body 크기 → JSON → Type → TopicArn/리전 → Timestamp →
 *     인증서 URL → RSA 서명. 서명 검증(네트워크·CPU)은 마지막이다.
 *   · 비정상 요청 로그에는 payload 전체를 넣지 않는다. 사유 코드와 MessageId 접두,
 *     본문 길이 정도만 남긴다.
 *   · SNS MessageId unique 로 중복 수신은 재저장 없이 200 을 돌려준다.
 *
 * ── 응답 정책 ───────────────────────────────────────────────────────────────
 * SNS 는 non-2xx 를 실패로 보고 재시도한다. 그래서
 *   · 정상 처리 / 중복 / "우리가 다루지 않는 이벤트 타입" → 200 (재시도 무의미)
 *   · 서명·출처 검증 실패 → 403 (위조이므로 재시도돼도 같은 결과)
 *   · body 초과·JSON 오류 → 400
 *   · 저장 중 서버 오류 → 500 (SNS 재시도로 복구 가능)
 */
class SesWebhookController
{
    /**
     * 이 시간을 넘겨 도착한 이벤트는 warning 을 남긴다 (거부하지는 않는다).
     *
     * 6시간 — 배포·점검으로 밀리는 정상 범위는 덮으면서, 그보다 오래된 것은
     * 파이프라인 어딘가가 막혔다는 신호로 보기에 충분한 값.
     */
    private const STALE_EVENT_WARN_SECONDS = 21600;

    /**
     * SNS 요청 처리.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        $size = strlen($raw);

        if ($size > SesConfig::maxBodyBytes()) {
            return $this->reject('body_too_large', 400, ['bytes' => $size]);
        }

        $message = json_decode($raw, true);
        if (! is_array($message) || json_last_error() !== JSON_ERROR_NONE) {
            return $this->reject('invalid_json', 400, ['bytes' => $size]);
        }

        $validator = app(SnsMessageValidator::class);
        $result = $validator->validate($message);

        if (! $result['valid']) {
            // 사유별 상태코드: 형식 오류는 400, 출처·서명 위반은 403.
            $status = in_array($result['reason'], [
                SnsMessageValidator::REASON_MALFORMED,
                SnsMessageValidator::REASON_TYPE_NOT_ALLOWED,
            ], true) ? 400 : 403;

            return $this->reject((string) $result['reason'], $status, [
                'message_id' => $this->safeId($message['MessageId'] ?? null),
                'sns_type' => $this->safeType($message['Type'] ?? null),
            ]);
        }

        return match ($message['Type']) {
            'SubscriptionConfirmation' => $this->handleSubscriptionConfirmation($message),
            'UnsubscribeConfirmation' => $this->handleUnsubscribeConfirmation($message),
            default => $this->handleNotification($message),
        };
    }

    /**
     * 구독 확인 — **서명·TopicArn·리전·Timestamp·인증서 검증을 모두 통과한 뒤에만** 여기 도달한다.
     * (`__invoke()` 가 `SnsMessageValidator::validate()` 를 먼저 통과시키므로, 검증 실패
     *  메시지는 이 메서드까지 오지 않는다.)
     *
     * ── SNS HTTPS 구독의 사실 ───────────────────────────────────────────────
     * HTTPS 구독은 **엔드포인트가 SubscribeURL 을 호출해야** 확정된다. AWS 콘솔의
     * "Request confirmation" 은 확인 메시지를 **재전송**할 뿐 구독을 확정하지 못한다.
     * 따라서 최초 구독 때는 `SES_SNS_AUTO_CONFIRM=true` 로 잠깐 열어 호출을 성사시키고
     * 곧바로 false 로 되돌린다 (README "최초 구독 절차").
     *
     * auto-confirm=false 에서는 **SubscribeURL 을 절대 호출하지 않는다.** 200 으로
     * 정상 수신만 하고 pending 상태를 구조화 로그로 남긴다. Token 과 SubscribeURL 전체는
     * 구독 권한을 가진 자격증명이므로 어떤 경우에도 로그에 남기지 않는다.
     *
     * @param  array<string, mixed>  $message
     */
    private function handleSubscriptionConfirmation(array $message): JsonResponse
    {
        $messageId = (string) $message['MessageId'];

        if (! SesConfig::autoConfirm()) {
            Log::info('ses_monitor.subscription_confirmation_pending', [
                'plugin' => 'yutiv-ses_monitor',
                'topic_arn' => $message['TopicArn'],
                'message_id' => $this->safeId($messageId),
                'auto_confirm' => false,
                'subscribe_url_called' => false,
                'note' => 'SES_SNS_AUTO_CONFIRM=false — SubscribeURL 을 호출하지 않았습니다. '
                    .'구독은 PendingConfirmation 상태로 남습니다. 최초 구독 시에는 '
                    .'SES_SNS_AUTO_CONFIRM=true 로 잠시 전환한 뒤 확인 메시지를 재전송하세요.',
            ]);

            return response()->json(['status' => 'confirmation_pending'], 200);
        }

        $outcome = app(SubscriptionConfirmer::class)->confirm(
            (string) $message['SubscribeURL'],
            $messageId,
            SesConfig::region()
        );

        // URL 이 허용된 SNS 호스트가 아니면 호출하지 않았다 — 위조 가능성이므로 403.
        if ($outcome['result'] === SubscriptionConfirmer::RESULT_URL_REJECTED) {
            return $this->reject('subscribe_url_rejected', 403, [
                'message_id' => $this->safeId($messageId),
            ]);
        }

        // 같은 확인 메시지 재전송 — 이미 처리했으므로 다시 호출하지 않는다.
        if ($outcome['result'] === SubscriptionConfirmer::RESULT_ALREADY_HANDLED) {
            Log::info('ses_monitor.subscription_confirmation_duplicate', [
                'plugin' => 'yutiv-ses_monitor',
                'topic_arn' => $message['TopicArn'],
                'message_id' => $this->safeId($messageId),
                'subscribe_url_called' => false,
            ]);

            return response()->json(['status' => 'already_confirmed'], 200);
        }

        // ★ 멱등 선점을 보장할 수 없으면 **호출하지 않았다** — fail-closed.
        //
        // 선점 없이 호출하면 캐시 장애·동시 요청에서 같은 SubscribeURL 이 여러 번 나가
        // "MessageId 당 1회" 계약이 깨진다. 500 으로 SNS 재시도를 유도하면 캐시가
        // 회복된 뒤 정상 처리된다 — 잃는 것은 지연뿐이다.
        if ($outcome['result'] === SubscriptionConfirmer::RESULT_CLAIM_UNAVAILABLE) {
            Log::error('ses_monitor.subscription_claim_unavailable', [
                'plugin' => 'yutiv-ses_monitor',
                'topic_arn' => $message['TopicArn'],
                'message_id' => $this->safeId($messageId),
                'subscribe_url_called' => false,
                'note' => '멱등 선점에 실패해 SubscribeURL 을 호출하지 않았습니다(fail-closed). '
                    .'캐시 저장소 상태를 확인하세요. SNS 재시도에서 다시 처리됩니다.',
            ]);

            return response()->json(['status' => 'claim_unavailable'], 500);
        }

        if ($outcome['result'] === SubscriptionConfirmer::RESULT_FAILED) {
            // 500 으로 돌려줘 SNS 재시도를 허용한다. token/URL/원문은 남기지 않는다.
            Log::error('ses_monitor.subscription_confirmation_failed', [
                'plugin' => 'yutiv-ses_monitor',
                'topic_arn' => $message['TopicArn'],
                'message_id' => $this->safeId($messageId),
                'subscribe_url_called' => true,
                'http_status' => $outcome['status'],
                'note' => 'SubscribeURL 호출이 실패했습니다(비 2xx·redirect·timeout). SNS 재시도를 기다립니다.',
            ]);

            return response()->json(['status' => 'confirm_failed'], 500);
        }

        Log::info('ses_monitor.subscription_confirmed', [
            'plugin' => 'yutiv-ses_monitor',
            'topic_arn' => $message['TopicArn'],
            'message_id' => $this->safeId($messageId),
            'auto_confirm' => true,
            'subscribe_url_called' => true,
            'http_status' => $outcome['status'],
            'claim' => $outcome['claim'],
            'note' => 'AWS SNS 에서 SubscriptionArn 이 PendingConfirmation 이 아닌지 확인한 뒤 '
                .'SES_SNS_AUTO_CONFIRM=false 로 되돌리세요.',
        ]);

        return response()->json(['status' => 'confirmed'], 200);
    }

    /**
     * 구독 해지 확인 — 기록만 남긴다. 재구독을 자동으로 시도하지 않는다.
     *
     * @param  array<string, mixed>  $message
     */
    private function handleUnsubscribeConfirmation(array $message): JsonResponse
    {
        Log::warning('ses_monitor.unsubscribe_confirmation_received', [
            'plugin' => 'yutiv-ses_monitor',
            'topic_arn' => $message['TopicArn'],
            'message_id' => $this->safeId($message['MessageId'] ?? null),
            'note' => 'SNS 구독이 해지되었습니다. SES 이벤트 수집이 멈출 수 있습니다.',
        ]);

        return response()->json(['status' => 'unsubscribe_acknowledged'], 200);
    }

    /**
     * SES 이벤트 저장.
     *
     * @param  array<string, mixed>  $message
     */
    private function handleNotification(array $message): JsonResponse
    {
        $event = json_decode((string) ($message['Message'] ?? ''), true);

        if (! is_array($event) || json_last_error() !== JSON_ERROR_NONE) {
            return $this->reject('invalid_event_json', 400, [
                'message_id' => $this->safeId($message['MessageId'] ?? null),
            ]);
        }

        $parser = app(SesEventParser::class);
        $eventType = $parser->eventType($event);

        if ($eventType === null) {
            // 우리가 다루지 않는 이벤트(Open/Click/Subscription 등)는 저장하지 않되,
            // SNS 가 계속 재시도하지 않도록 200 으로 응답한다.
            Log::info('ses_monitor.event_type_skipped', [
                'plugin' => 'yutiv-ses_monitor',
                'message_id' => $this->safeId($message['MessageId'] ?? null),
                'event_type' => $this->safeType($event['eventType'] ?? $event['notificationType'] ?? null),
            ]);

            return response()->json(['status' => 'ignored'], 200);
        }

        $attributes = $parser->toAttributes($event, $eventType, SesConfig::rawPayloadEnabled());
        $attributes['sns_message_id'] = (string) $message['MessageId'];
        $attributes['topic_arn'] = (string) $message['TopicArn'];

        try {
            SesEventLog::create($attributes);
        } catch (QueryException $e) {
            // unique(sns_message_id) 위반 = SNS at-least-once 재전송. 정상 상황이다.
            if ($this->isDuplicateKey($e)) {
                return response()->json(['status' => 'duplicate'], 200);
            }

            Log::error('ses_monitor.event_store_failed', [
                'plugin' => 'yutiv-ses_monitor',
                'message_id' => $this->safeId($message['MessageId'] ?? null),
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['status' => 'error'], 500);
        }

        $delaySeconds = max(0, time() - strtotime((string) $attributes['occurred_at'].' UTC'));

        Log::info('ses_monitor.event_stored', [
            'plugin' => 'yutiv-ses_monitor',
            'message_id' => $this->safeId($message['MessageId'] ?? null),
            'event_type' => $eventType,
            'ses_message_id' => $attributes['ses_message_id'],
            'recipient_count' => is_array($attributes['recipients']) ? count($attributes['recipients']) : 0,
            'delay_seconds' => $delaySeconds,
        ]);

        // 비정상적으로 늦게 도착한 이벤트는 **저장은 하되** 눈에 띄게 남긴다.
        // 거부하지 않는 이유: 장애·배포로 밀린 정상 재시도를 버리면 영구 유실이다.
        // 로그에는 payload·수신자·전체 MessageId 를 넣지 않는다.
        if ($delaySeconds > self::STALE_EVENT_WARN_SECONDS) {
            Log::warning('ses_monitor.event_delayed', [
                'plugin' => 'yutiv-ses_monitor',
                'message_id' => $this->safeId($message['MessageId'] ?? null),
                'event_type' => $eventType,
                'delay_seconds' => $delaySeconds,
                'occurred_at' => $attributes['occurred_at'],
                'note' => 'SES 이벤트 발생 후 오래 지나 도착했습니다. 저장은 정상 처리했습니다.',
            ]);
        }

        return response()->json(['status' => 'stored'], 200);
    }

    /**
     * 거부 응답 — 구조화 로그에 **payload 전체를 남기지 않는다.**
     *
     * @param  array<string, mixed>  $context
     */
    private function reject(string $reason, int $status, array $context = []): JsonResponse
    {
        Log::warning('ses_monitor.request_rejected', array_merge([
            'plugin' => 'yutiv-ses_monitor',
            'reason' => $reason,
            'status' => $status,
        ], $context));

        return response()->json(['status' => 'rejected', 'reason' => $reason], $status);
    }

    /**
     * 로그에 넣을 수 있는 최소 식별자 — 앞 8자만.
     */
    private function safeId(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, 8).'…' : null;
    }

    /**
     * 로그에 넣을 수 있는 타입 문자열 — 짧게 자르고 제어문자를 제거한다.
     */
    private function safeType(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return mb_substr(preg_replace('/[^A-Za-z0-9_.-]/', '', $value) ?? '', 0, 40);
    }


    /**
     * 드라이버 무관 중복 키 판정 (MySQL 1062 / SQLite 19 / Postgres 23505).
     */
    private function isDuplicateKey(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $driverCode = (string) ($e->errorInfo[1] ?? '');

        if ($sqlState === '23000' || $sqlState === '23505') {
            return true;
        }

        // SQLite in-memory 테스트 경로.
        return $driverCode === '19' || str_contains(strtolower($e->getMessage()), 'unique constraint');
    }
}
