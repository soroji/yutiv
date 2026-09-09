# Changelog

이 플러그인의 모든 주요 변경사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,
[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.

## [1.0.0] - 2026-09-09

### Added

- 공개 endpoint `POST /webhooks/aws/ses`. AWS SDK 없이 SNS 서명 계약만으로 검증합니다.
  - SignatureVersion **1(SHA1) / 2(SHA256)** RSA 서명 검증
  - `SigningCertURL` 은 HTTPS + `sns.{허용리전}.amazonaws.com(.cn)` + `.pem` 만 허용
    (사용자정보·명시 포트 거부, 리전 불일치 거부)
  - `TopicArn` 정확 일치 + ARN 리전 검증. **TopicArn 미설정은 전부 거부**
  - `Timestamp` **메시지 종류별 정책**
    - 미래 시각은 전 타입 공통으로 `SES_SNS_MAX_FUTURE_SKEW_SECONDS`(기본 300) 초과 시 403.
    - `Notification` 에는 **과거 상한을 두지 않는다.** 장애·배포로 밀린 정상 SES 재시도가
      뒤늦게 도착해도 버리지 않는다 — replay 방어는 `sns_message_id` unique 가 담당한다.
    - 제어 메시지(구독 확인/해지)만 `SES_SNS_CONTROL_MAX_AGE_SECONDS`(기본 3600) 초과 시 403.
      오래된 Token 으로 구독 상태가 바뀌는 것을 막기 위해서다. 만료된 confirmation 은
      SubscribeURL 을 호출하지 않는다.
  - body 크기 제한, JSON·`Type`·`eventType` allowlist
  - `sns_message_id` unique 로 중복 수신 멱등 처리
- SNS `SubscriptionConfirmation` / `Notification` / `UnsubscribeConfirmation` 처리.
  - `SubscribeURL` 호출은 body/JSON/Type · TopicArn 정확 일치 · ARN 리전 · Timestamp ·
    SigningCertURL · RSA 서명이 **모두** 통과한 뒤에만 일어납니다.
  - 호출 직전 `SubscribeURL` 자체를 다시 검증합니다 (HTTPS + 허용 리전의 SNS 호스트).
  - redirect 를 따라가지 않고(3xx = 실패), connect 2초 / read 4초 timeout 을 둡니다.
  - 같은 MessageId 재전송은 캐시로 **한 번만** 호출합니다. 실패 시에는 표시를 해제해
    SNS 재시도로 다시 시도할 수 있게 하고 **500** 을 반환합니다.
  - `SES_SNS_AUTO_CONFIRM` 기본값은 **false** 이고, false 인 동안에는 SubscribeURL 을
    **절대 호출하지 않습니다.** 200 으로 수신만 하고
    `ses_monitor.subscription_confirmation_pending` 을 남깁니다.
  - `Token` 과 `SubscribeURL` 전체는 어떤 로그에도 남기지 않습니다.
- append-only `ses_event_logs` 테이블. send / reject / bounce / complaint / delivery /
  deliveryDelay / renderingFailure 7종을 기록합니다.
- `ses_message_links` 테이블 — 발송 이력 ↔ SES 메시지 ID 조회용 매핑.
  발송 시점에 새로 쌓으며 `notification_logs` 는 **읽기만** 합니다.
- 발송 측: `MessageSending` 리스너가 모든 Laravel 메일(테스트 메일 포함)에
  `X-SES-CONFIGURATION-SET` 을 **중복 없이 1회** 붙입니다. 환경변수가 비어 있으면
  붙이지 않고, 비메일 채널에는 영향이 없습니다.
- 관리자: 기존 '알림 발송 이력' 화면에 SES 결과 요약 패널 주입(코어 레이아웃 무수정) +
  전용 화면 `/admin/plugins/yutiv-ses_monitor/events` (연결/미연결 필터).
  권한은 기존 `core.notification-logs.read` 를 재사용합니다.
- 운영: `yutiv:ses-status`(읽기 전용 상태), `yutiv:ses-prune`(보존기간 정리) +
  일 1회 스케줄. 구조화 로그 `ses_monitor.*`.
- 테스트: 실제 RSA 키쌍으로 서명한 fixture 기반. AWS 계정·네트워크 없이 실행됩니다.
  - `tests/Ses/yutiv-ses-monitor-check.php` (PHP 7.4 로도 실행 가능한 독립 하네스)
  - `tests/Feature/SesWebhookTest.php`, `tests/Feature/SesMonitorIntegrationTest.php`

### Changed

- **`SES_SNS_MAX_AGE_SECONDS` 를 제거했습니다.** 모든 SNS 메시지에 같은 상한을 적용하면
  뒤늦게 도착한 정상 SES Notification 이 403 으로 **영구 유실**됩니다. 역할이 겹치지 않는
  두 설정으로 분리했고, 예전 키는 판정 경로에 남아 있지 않습니다. `.env` 에 남아 있으면
  `yutiv:ses-status` 가 제거하라고 경고합니다.
- 지연 도착을 버리지 않는 대신 드러냅니다 — `occurred_at` 은 SES 이벤트 시각을 보존하고,
  관리자 응답에 `received_at`·`delay_seconds` 를 싣습니다. 6시간을 넘겨 도착하면
  `ses_monitor.event_delayed` warning 을 남기되 payload·수신자·전체 MessageId 는 넣지 않습니다.
- 구독 확인 멱등 선점을 **fail-closed** 로 처리합니다. `Cache::add()` 는 키 존재와 쓰기
  실패 모두 false 를 돌려주므로 `Cache::get()` 으로 구분하되, **기존 키를 확인하지 못하면
  SubscribeURL 을 호출하지 않고 500** 을 돌려줍니다. `Cache::add()` / `Cache::get()` 이
  예외를 던져도 마찬가지입니다. 선점 없이 호출하면 캐시 장애·동시 요청에서 같은 URL 이
  여러 번 나가 "MessageId 당 1회" 계약이 깨지기 때문입니다 — SNS 재시도로 복구되므로
  잃는 것은 지연뿐입니다. `ses_monitor.subscription_claim_unavailable` error 로그를 남깁니다.
- 프로바이더의 `registerWebhookRoute()` / `pluginIsActive()` 를 다시 `protected` 로
  되돌렸습니다. 테스트는 전용 서브클래스로 접근합니다 — 검사 편의로 운영 API 표면을
  넓히지 않습니다.

### Notes

- **`notification_logs` 를 수정하지 않습니다.** `status` 의 `sent` 를 SES `delivery` 로
  바꾸거나 덮어쓰지 않으며, 기존 행에 컬럼을 추가하지도 않습니다. "보냈다" 와
  "도착했다" 는 다른 사실이고, 합치면 되돌릴 수 없습니다.
- 환경값이 없으면 안전하게 비활성됩니다. `SES_SNS_TOPIC_ARN` 이 비어 있으면 webhook
  라우트를 아예 등록하지 않아 공격 표면이 생기지 않습니다. 플러그인이 비활성이어도
  라우트·메일 리스너를 붙이지 않습니다.
- **SNS HTTPS 구독은 엔드포인트가 `SubscribeURL` 을 호출해야 확정됩니다.** AWS 콘솔의
  "Request confirmation" 은 확인 메시지 재전송일 뿐 구독을 확정하지 못합니다. 그래서
  최초 구독 시에는 `SES_SNS_AUTO_CONFIRM=true` 로 잠깐 열어 한 번 확정한 뒤 즉시
  false 로 되돌립니다 — 절차는 README "최초 구독 절차" 를 따르세요.
- CSRF 면제는 `Route::withoutMiddleware` 로 **이 라우트 하나**에만 적용합니다.
  `bootstrap/app.php` 의 전역 예외 목록은 건드리지 않았습니다.
- 비정상 요청 로그에 payload 전체를 남기지 않습니다. 관리자 목록 응답의 수신자는
  항상 마스킹되며 `raw_payload` 는 목록에 실리지 않습니다.
- `SentMessage::getMessageId()` 가 SES `mail.messageId` 와 동일한지는 운영 SMTP 응답으로만
  최종 확인됩니다. 연결은 부가 기능이며, 어긋나도 `ses_message_id` 독립 조회는 보장됩니다.
