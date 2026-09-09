<?php

/**
 * YUTIV SES 이벤트 모니터 설정.
 *
 * 이 배열은 `SesMonitorServiceProvider::register()` 에서 `yutiv_ses_monitor` 키로
 * 머지된다. 값은 전부 `.env` 에서 읽으며, **값이 없으면 안전하게 비활성** 된다:
 *
 *   - `SES_CONFIGURATION_SET` 이 비어 있으면 발송 메일에 헤더를 붙이지 않는다.
 *   - `SES_SNS_TOPIC_ARN` 이 비어 있으면 webhook 라우트를 등록하지 않는다
 *     (= 공개 endpoint 자체가 존재하지 않는다).
 *
 * config:cache 를 쓰는 운영 환경을 고려해 `env()` 호출은 이 파일 안에서만 한다.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | 발송 측 — Configuration Set 헤더
    |--------------------------------------------------------------------------
    |
    | SMTP 로 보내는 메일에 `X-SES-CONFIGURATION-SET` 헤더를 붙이면 SES 가 해당
    | configuration set 의 event destination 으로 이벤트를 발행한다. 빈 값이면
    | 헤더를 붙이지 않는다 (SES 를 쓰지 않는 환경에서 무해).
    |
    */
    'configuration_set' => env('SES_CONFIGURATION_SET', ''),

    /*
    |--------------------------------------------------------------------------
    | 수신 측 — SNS webhook
    |--------------------------------------------------------------------------
    */

    // 구독한 SNS 토픽 ARN. 비어 있으면 endpoint 를 등록하지 않는다.
    'topic_arn' => env('SES_SNS_TOPIC_ARN', ''),

    // 허용 리전. TopicArn 과 SigningCertURL 호스트를 이 리전으로 제한한다.
    'region' => env('SES_SNS_REGION', 'ap-northeast-2'),

    // SubscriptionConfirmation 의 SubscribeURL 자동 호출 여부. 기본 false.
    //
    // SNS HTTPS 구독은 **엔드포인트가 SubscribeURL 을 호출해야** 확정된다. 콘솔에서
    // 클릭만으로는 확정되지 않는다(콘솔의 "Request confirmation" 은 확인 메시지 재전송일 뿐).
    // 그래서 최초 구독 때만 true 로 잠깐 열고, 확정 직후 false 로 되돌린다.
    // 절차는 README "최초 구독 절차" 참고.
    //
    // false 인 동안에는 확인 메시지를 200 으로 수신만 하고 SubscribeURL 을 호출하지 않는다.
    'auto_confirm' => (bool) env('SES_SNS_AUTO_CONFIRM', false),

    /*
    |--------------------------------------------------------------------------
    | Timestamp 정책 — 메시지 종류별로 다르다
    |--------------------------------------------------------------------------
    |
    | 두 값의 역할이 겹치지 않는다. 하나는 "미래", 하나는 "제어 메시지의 과거" 다.
    |
    |   max_future_skew_seconds  : 모든 타입 공통. 미래 시각 허용 오차.
    |   control_max_age_seconds  : SubscriptionConfirmation / UnsubscribeConfirmation
    |                              에만 적용하는 과거 최대 나이.
    |
    | Notification 에는 **과거 상한이 없다.** 서버 장애나 배포로 못 받은 정상 SES 이벤트가
    | SNS 재시도로 뒤늦게 도착했을 때 거부하면 그 이벤트는 영구 유실된다. replay 방어는
    | ses_event_logs.sns_message_id unique 가 이미 담당하므로 시각으로 또 막지 않는다.
    |
    */

    // 미래 시각 허용 오차(초). 전 타입 공통. 정상 SNS 는 미래에서 오지 않으며,
    // 허용하면 "만료되지 않는 메시지" 를 만들 수 있다. 서버 시계 오차만 인정한다.
    'max_future_skew_seconds' => (int) env('SES_SNS_MAX_FUTURE_SKEW_SECONDS', 300),

    // 제어 메시지의 과거 최대 나이(초). 기본 3600.
    //
    // 근거: 구독 확인/해지는 Token 하나로 구독 상태를 바꾸는 메시지라, 오래된 것을
    // 받아들이면 지난 Token 으로 구독이 되살아나거나 끊길 수 있다. AWS 콘솔에서
    // "Request confirmation" 으로 새 메시지를 즉시 재발급받을 수 있으므로 상한을
    // 짧게 둬도 운영에 지장이 없다. 1시간은 배포·점검 창을 덮으면서도 지난 Token 의
    // 유효 기간을 하루 단위로 늘리지 않는 절충값이다.
    'control_max_age_seconds' => (int) env('SES_SNS_CONTROL_MAX_AGE_SECONDS', 3600),

    // 원문 payload 저장 여부. 기본 false — 개인정보 최소화.
    'raw_payload_enabled' => (bool) env('SES_EVENT_RAW_PAYLOAD_ENABLED', false),

    // 요청 본문 최대 바이트. SNS 메시지는 최대 256KB 이므로 그 위에 여유를 둔다.
    'max_body_bytes' => (int) env('SES_SNS_MAX_BODY_BYTES', 262144),

    // 보존 기간(일). prune 명령이 이 값을 기본으로 쓴다.
    'retention_days' => (int) env('SES_EVENT_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | 고정 계약 (env 로 완화하지 않는다)
    |--------------------------------------------------------------------------
    */

    // webhook 경로. AWS 콘솔에 등록하는 값과 같아야 한다.
    'endpoint_path' => 'webhooks/aws/ses',

    // 처리하는 SNS 메시지 타입.
    'allowed_sns_types' => [
        'SubscriptionConfirmation',
        'Notification',
        'UnsubscribeConfirmation',
    ],

    // 기록하는 SES 이벤트 타입 (정규화된 표기).
    'allowed_event_types' => [
        'send',
        'reject',
        'bounce',
        'complaint',
        'delivery',
        'deliveryDelay',
        'renderingFailure',
    ],
];
