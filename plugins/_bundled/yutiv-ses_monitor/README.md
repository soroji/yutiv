# YUTIV Amazon SES 이벤트 모니터

Amazon SES 가 SNS 로 보내는 이벤트(전달·반송·스팸신고 등)를 **서명 검증 후**
append-only 로 기록하고, 알림 발송 이력과 대조해 볼 수 있게 합니다.

## 두 기록을 섞지 않습니다

| | `notification_logs` (코어) | `ses_event_logs` (이 플러그인) |
|---|---|---|
| 의미 | 애플리케이션이 **발송을 시도했다** | AWS 가 알려준 **실제 처리 결과** |
| 시점 | 발송 직후 | 수 초~수 일 뒤 |
| 쓰기 주체 | 코어 | 이 플러그인 |
| 이 플러그인의 접근 | **읽기 전용** | append-only 쓰기 |

`notification_logs.status` 의 `sent` 를 SES `delivery` 로 바꾸거나 덮어쓰지 않습니다.
"보냈다" 와 "도착했다" 는 다른 사실이고, 하나로 합치면 되돌릴 수 없기 때문입니다.

## 설정 (없으면 안전하게 비활성)

```env
SES_CONFIGURATION_SET=yutiv-production
SES_SNS_TOPIC_ARN=arn:aws:sns:ap-northeast-2:<ACCOUNT_ID>:yutiv-ses-events
SES_SNS_REGION=ap-northeast-2
SES_SNS_AUTO_CONFIRM=false
SES_SNS_MAX_FUTURE_SKEW_SECONDS=300
SES_SNS_CONTROL_MAX_AGE_SECONDS=3600
SES_EVENT_RAW_PAYLOAD_ENABLED=false
SES_EVENT_RETENTION_DAYS=90
```

> **`SES_SNS_MAX_AGE_SECONDS` 는 폐기됐습니다.** 판정에 쓰이지 않으니 `.env` 에서
> 지우세요. 남아 있으면 `yutiv:ses-status` 가 경고합니다. 대체 설정은 아래 두 가지이며
> 역할이 겹치지 않습니다.

| 값이 없으면 | 결과 |
|---|---|
| `SES_CONFIGURATION_SET` | 발송 메일에 헤더를 **붙이지 않음** |
| `SES_SNS_TOPIC_ARN` | webhook 라우트를 **등록하지 않음** (공개 endpoint 자체가 없음) |
| 플러그인 비활성 | 라우트·메일 리스너 모두 등록되지 않음 |

## 공개 endpoint

```
POST /webhooks/aws/ses
```

### 보안 계약

검증은 **싼 것부터** 하고, 하나라도 실패하면 저장·SubscribeURL 호출·원문 로깅을
전혀 하지 않습니다.

1. body 크기 제한 (기본 256KB)
2. JSON 파싱
3. SNS `Type` allowlist — `SubscriptionConfirmation` / `Notification` / `UnsubscribeConfirmation`
4. `TopicArn` 정확 일치 + ARN 리전 == `SES_SNS_REGION`
   (TopicArn 미설정은 "전부 허용" 이 아니라 **전부 거부**)
5. `Timestamp` — **메시지 종류별 정책** (아래 표)
6. `SigningCertURL` — HTTPS + `sns.{허용리전}.amazonaws.com(.cn)` + `.pem`,
   사용자정보·명시 포트 거부
7. `SignatureVersion` 1(SHA1) / 2(SHA256) RSA 서명 검증
8. `eventType` allowlist — 7종 외 이벤트(Open/Click 등)는 저장하지 않고 200
9. `sns_message_id` unique 로 중복 수신 멱등 처리

### Timestamp 정책 — 메시지 종류별로 다르다

| | 미래 Timestamp | 과거 Timestamp |
|---|---|---|
| `Notification` | `SES_SNS_MAX_FUTURE_SKEW_SECONDS`(기본 300) 초과 시 **403** | **상한 없음** |
| `SubscriptionConfirmation` / `UnsubscribeConfirmation` | 동일하게 **403** | `SES_SNS_CONTROL_MAX_AGE_SECONDS`(기본 3600) 초과 시 **403** |

**왜 Notification 에는 과거 상한이 없는가.** 상한을 걸면 서버 장애나 배포로 받지 못한
정상 SES 이벤트가 SNS 재시도로 뒤늦게 도착했을 때 403이 되어 **영구 유실**됩니다.
replay 방어는 `ses_event_logs.sns_message_id` unique 가 이미 담당하므로 시각으로 또
막을 이유가 없습니다. 대신 늦게 도착한 사실을 버리지 않고 드러냅니다.

- `occurred_at` 은 **SES 이벤트 시각을 그대로 보존**합니다 (수신 시각으로 덮어쓰지 않음).
- 관리자 목록 응답에 `received_at`(= `created_at`)과 `delay_seconds` 를 함께 실어
  "언제 일어났고 언제 받았는가" 를 바로 볼 수 있습니다.
- 6시간을 넘겨 도착하면 `ses_monitor.event_delayed` warning 을 남깁니다 —
  **저장은 정상 처리**하며, 로그에 payload·수신자·전체 MessageId 는 넣지 않습니다.

**왜 제어 메시지에는 상한이 있는가.** 구독 확인/해지는 Token 하나로 구독 상태를 바꾸는
메시지라, 오래된 것을 받아들이면 지난 Token 으로 구독이 되살아나거나 끊길 수 있습니다.
기본 1시간은 배포·점검 창을 덮으면서 지난 Token 의 유효 기간을 하루 단위로 늘리지 않는
절충값입니다.

**만료된 confirmation 을 받았다면** SubscribeURL 을 **호출하지 않고 403** 으로 끝냅니다.
AWS SNS 콘솔 → Subscriptions → 해당 구독 → **Request confirmation** 으로 새 확인
메시지를 재발급받으면 됩니다 (위 "최초 구독 절차" 2~5단계를 그대로 다시 수행).

### 응답 정책

SNS 는 non-2xx 를 재시도합니다. 그래서 재시도가 의미 있는 것만 실패로 돌려줍니다.

| 상황 | 상태 |
|---|---|
| 저장 / 중복 / 미지원 이벤트 / 구독확인 / 해지확인 | 200 |
| 오래된 정상 Notification (저장 또는 중복) | 200 |
| body 초과, JSON 오류, 형식 오류, 미허용 Type | 400 |
| TopicArn·리전·인증서 URL·서명 위반 | 403 |
| 미래 Timestamp (전 타입) | 403 |
| 만료된 제어 메시지 — SubscribeURL 미호출 | 403 |
| 멱등 선점 실패 — SubscribeURL 미호출 (fail-closed) | 500 (재시도로 복구) |
| SubscribeURL 호출 실패 (비 2xx·redirect·timeout) | 500 (재시도로 복구) |
| 저장 중 서버 오류 | 500 (재시도로 복구) |

### 구독 확인 멱등성 — 캐시 장애는 fail-closed

`SubscribeURL` 은 **MessageId 당 1회만** 호출합니다. 선점은 유한 TTL(1시간) 캐시 키로
합니다.

| `Cache::add()` | 확인 | 결과 |
|---|---|---|
| `true` | — | **CLAIMED** — 이 요청만 호출 |
| `false` | `Cache::get()` 으로 기존 키 확인됨 | **DUPLICATE** — 미호출, **200** |
| `false` | 기존 키 확인 안 됨 | **UNAVAILABLE** — 미호출, **500** |
| 예외 발생 | — | **UNAVAILABLE** — 미호출, **500** |

`Cache::get()` 이 예외를 던져도 UNAVAILABLE 입니다. 즉 **선점을 보장할 수 없으면 절대
호출하지 않습니다.** 선점 없이 호출하면 캐시 장애나 동시 요청에서 같은 URL 이 여러 번
나가 "1회" 계약이 깨지는데, 그건 재시도로 되돌릴 수 없습니다. 반대로 500 은 SNS 가
재시도하므로 캐시가 회복된 뒤 정상 처리됩니다 — **잃는 것은 지연뿐입니다.**

HTTP 호출이 실패하면 선점을 해제해 다음 재시도가 다시 선점할 수 있게 하고, 성공하면
TTL 동안 유지해 재전송이 `already_confirmed` 200 으로 끝나게 합니다.
`ses_monitor.subscription_claim_unavailable` error 로그에는 Token·SubscribeURL·Signature·
payload·수신자·전체 MessageId 를 넣지 않습니다.

### CSRF

`web` 그룹에 붙이고 **이 라우트 하나에서만** `ValidateCsrfToken` 을 제외합니다
(`Route::withoutMiddleware`). `bootstrap/app.php` 의 전역 CSRF 예외 목록은
건드리지 않으므로 면제 범위는 정확히 이 URI 하나입니다.

### 개인정보

- 거부 로그에 payload 전체를 남기지 않습니다. 사유 코드, MessageId 앞 8자,
  본문 길이 정도만 남깁니다.
- `raw_payload` 는 `SES_EVENT_RAW_PAYLOAD_ENABLED=true` 일 때만 저장합니다.
- 관리자 목록 응답의 수신자는 **항상 마스킹**(`a***e@example.com`)되며
  원문 payload 는 목록에 절대 실리지 않습니다.

## 발송 이력과의 연결

`notification_logs` 에는 메일 Message-ID 컬럼이 **없습니다**. 그래서 이미 쌓인 행을
소급해 잇는 것은 불가능하고, 기존 행에 값을 채워 넣는 것은 원칙 위반입니다.

대신 발송 시점에 플러그인 소유 `ses_message_links` 에 매핑을 새로 쌓습니다.

```
DbTemplateMail::send() / Mail::raw()
  └ MessageSending  → X-SES-CONFIGURATION-SET 헤더 삽입
  └ MessageSent     → SentMessage::getMessageId() 확보 (요청 범위 보관)
  └ core.mail.after_send
       └ NotificationLogService::logSent()
            └ core.notification_log.after_log_sent → ses_message_links 기록
```

연결이 없으면(플러그인 설치 이전 발송, 전송기가 ID 를 주지 않는 경우) SES 이벤트는
`ses_message_id` 로 **독립 조회**됩니다. 관리자 화면도 "미연결" 을 1급 필터로 둡니다.

> `SentMessage::getMessageId()` 가 SES `mail.messageId` 와 같은 값인지는 운영 SMTP
> 응답으로만 최종 확인됩니다. 연결은 부가 기능이며, 어긋나도 독립 조회는 보장됩니다.

## 관리자 화면

- 기존 **알림 발송 이력** 화면 상단에 SES 결과 요약 패널을 주입합니다
  (코어 레이아웃 파일 무수정 — 기존 컨테이너 id 에 `prepend_child`).
- 전용 화면 `/admin/plugins/yutiv-ses_monitor/events` 에서 전체 목록,
  이벤트 타입 배지, 연결/미연결 필터를 제공합니다.
- 권한은 기존 **`core.notification-logs.read`** 를 그대로 씁니다. 새 권한을 만들면
  역할 설정이 이원화되어 "발송 이력은 못 보는데 SES 결과는 보이는" 상태가 생깁니다.

## 운영 명령

```bash
php artisan yutiv:ses-status            # 설정·수집 상태 (읽기 전용, 비밀값 미출력)
php artisan yutiv:ses-status --json     # 기계 판독용
php artisan yutiv:ses-prune             # 보존기간(기본 90일) 경과분 삭제
php artisan yutiv:ses-prune --dry-run   # 대상 건수만 확인
```

`yutiv:ses-prune` 은 `getSchedules()` 로 매일 자동 실행됩니다.
`notification_logs` 는 건드리지 않습니다 — 코어의 `PruneNotificationLogsCommand` 담당입니다.

## AWS 쪽 설정

1. SES → Configuration sets → `yutiv-production` 생성
2. Event destination 추가 → SNS topic `yutiv-ses-events`
   이벤트: Send, Reject, Bounce, Complaint, Delivery, Delivery delay, Rendering failure

### 최초 구독 절차 (auto-confirm 을 한 번만 켠다)

> **SNS HTTPS 구독은 엔드포인트가 `SubscribeURL` 을 호출해야 확정됩니다.**
> AWS 콘솔의 "Request confirmation" 은 확인 메시지를 **재전송**할 뿐 구독을 확정하지
> 못합니다. 그래서 최초 1회에 한해 자동 호출을 열어야 합니다.

```bash
# 1) endpoint 를 먼저 배포하고 TopicArn 을 설정한다
#    .env: SES_SNS_TOPIC_ARN=arn:aws:sns:ap-northeast-2:<ACCOUNT_ID>:yutiv-ses-events
php artisan config:cache
php artisan yutiv:ses-status          # webhook 활성 = 예 인지 확인

# 2) 최초 구독 직전에만 자동 확인을 켠다
#    .env: SES_SNS_AUTO_CONFIRM=true
php artisan config:cache

# 3) SNS 콘솔에서 HTTPS 구독을 생성하거나, 이미 있으면 confirmation 을 재전송한다
#    Protocol: HTTPS / Endpoint: https://<도메인>/webhooks/aws/ses
#    (기존 구독이면 Subscriptions → 해당 구독 → "Request confirmation")

# 4) 확정 확인 — SubscriptionArn 이 PendingConfirmation 이 아니어야 한다
#    SNS 콘솔 Subscriptions 목록에서 확인
#    로그에서도 확인: ses_monitor.subscription_confirmed

# 5) 확정됐으면 즉시 되돌린다
#    .env: SES_SNS_AUTO_CONFIRM=false
php artisan config:cache
php artisan yutiv:ses-status          # auto-confirm = 꺼짐 (권장 상태)
```

3단계에서 우리 엔드포인트는 **서명 · TopicArn 정확 일치 · ARN 리전 · Timestamp ·
SigningCertURL 을 모두 통과한 뒤에만** `SubscribeURL` 을 호출합니다. 하나라도
어긋나면 호출하지 않습니다.

`SES_SNS_AUTO_CONFIRM=false` 인 평상시에는 확인 메시지가 와도 **호출하지 않고**
200 으로 수신만 하며, `ses_monitor.subscription_confirmation_pending` 구조화 로그를
남깁니다(구독은 PendingConfirmation 으로 남습니다).

`yutiv:ses-status` 는 auto-confirm 이 켜져 있으면 끄라고 경고하고, 이벤트가 0건이면
구독이 미확정일 수 있다고 알려줍니다.

## 테스트

```bash
# 서명 검증·파싱 (PHP 7.4 로도 실행, vendor 불필요)
php tests/Ses/yutiv-ses-monitor-check.php --verbose

# 서버 (PHP 8.3 + vendor)
php artisan test --testsuite=Plugin --filter=SesMonitor
php artisan test --testsuite=Plugin --filter=SesWebhook
```

독립 하네스는 **실제 RSA 키쌍으로 서명한 fixture** 를 만들어 실제 `openssl_verify` 로
검증합니다. 서명 검증을 모킹하면 검사 자체가 무의미해지기 때문입니다.
AWS 계정도 네트워크도 쓰지 않습니다.
