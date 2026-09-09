<?php

namespace Plugins\Yutiv\SesMonitor\Support;

/**
 * 설정 접근 단일 창구.
 *
 * 어떤 값이 없을 때 무엇이 꺼지는지를 여기 한 곳에서 정의한다.
 * 다른 클래스가 `config()` 를 직접 읽지 않게 해서 "환경값이 없으면 안전하게 비활성"
 * 규칙이 코드 여러 곳으로 흩어지지 않도록 한다.
 */
class SesConfig
{
    public const KEY = 'yutiv_ses_monitor';

    /**
     * 발송 메일에 붙일 configuration set 이름. 비어 있으면 헤더를 붙이지 않는다.
     */
    public static function configurationSet(): string
    {
        return trim((string) config(self::KEY.'.configuration_set', ''));
    }

    public static function headerEnabled(): bool
    {
        return self::configurationSet() !== '';
    }

    public static function topicArn(): string
    {
        return trim((string) config(self::KEY.'.topic_arn', ''));
    }

    public static function region(): string
    {
        return trim((string) config(self::KEY.'.region', 'ap-northeast-2'));
    }

    /**
     * webhook endpoint 활성 여부.
     *
     * TopicArn 이 없으면 어떤 메시지도 통과시킬 수 없으므로 라우트 자체를 등록하지
     * 않는다 — 검증 로직에만 의존하지 않고 공격 표면을 아예 없앤다.
     */
    public static function webhookEnabled(): bool
    {
        return self::topicArn() !== '' && self::region() !== '';
    }

    public static function autoConfirm(): bool
    {
        return (bool) config(self::KEY.'.auto_confirm', false);
    }

    /**
     * 제어 메시지(구독 확인/해지)의 과거 최대 나이(초).
     *
     * Notification 에는 적용되지 않는다 — 뒤늦게 도착한 정상 이벤트를 버리지 않기 위해서다.
     */
    public static function controlMaxAgeSeconds(): int
    {
        $value = (int) config(self::KEY.'.control_max_age_seconds', 3600);

        return $value > 0 ? $value : 3600;
    }

    /**
     * 미래 시각 허용 오차(초). 전 타입 공통.
     */
    public static function maxFutureSkewSeconds(): int
    {
        $value = (int) config(self::KEY.'.max_future_skew_seconds', 300);

        return $value > 0 ? $value : 300;
    }

    /**
     * 폐기된 SES_SNS_MAX_AGE_SECONDS 가 아직 .env 에 남아 있는가.
     *
     * 이 값은 **판정에 쓰이지 않는다.** 남아 있으면 운영자가 "설정했는데 왜 안 먹지" 로
     * 오해하므로 status 명령이 경고한다. 두 정책이 동시에 모호하게 적용되는 일은 없다.
     */
    public static function legacyMaxAgeEnvPresent(): bool
    {
        $value = env('SES_SNS_MAX_AGE_SECONDS');

        return $value !== null && $value !== '';
    }

    public static function rawPayloadEnabled(): bool
    {
        return (bool) config(self::KEY.'.raw_payload_enabled', false);
    }

    public static function maxBodyBytes(): int
    {
        $value = (int) config(self::KEY.'.max_body_bytes', 262144);

        return $value > 0 ? $value : 262144;
    }

    public static function retentionDays(): int
    {
        $value = (int) config(self::KEY.'.retention_days', 90);

        return $value > 0 ? $value : 90;
    }

    public static function endpointPath(): string
    {
        return trim((string) config(self::KEY.'.endpoint_path', 'webhooks/aws/ses'), '/');
    }

    /**
     * @return array<int, string>
     */
    public static function allowedSnsTypes(): array
    {
        $types = config(self::KEY.'.allowed_sns_types', []);

        return is_array($types) ? array_values(array_filter($types, 'is_string')) : [];
    }

    /**
     * @return array<int, string>
     */
    public static function allowedEventTypes(): array
    {
        $types = config(self::KEY.'.allowed_event_types', []);

        return is_array($types) ? array_values(array_filter($types, 'is_string')) : [];
    }
}
