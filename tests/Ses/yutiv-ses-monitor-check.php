<?php

/**
 * YUTIV SES 모니터 — 서명 검증·파싱 독립 하네스 (standalone CLI, vendor/Laravel 불필요).
 *
 * 왜 PHPUnit 이 아닌가:
 *   로컬 PHP 는 7.4 이고 `vendor/` 가 비어 있어 Laravel 부팅도 PHPUnit 실행도 불가능하다.
 *   그래서 이 하네스가 **플러그인의 실제 검증기·파서를 그대로 require** 해, 진짜 RSA
 *   키쌍으로 서명한 fixture 로 검증 경로 전체를 실행한다. 서명 검증을 모킹하면 검사가
 *   무의미해지므로, openssl_sign / openssl_verify 를 실제로 돌린다.
 *
 * 여기서 검증하는 것 (전부 실행 검증):
 *   · SignatureVersion 1/2 서명 성공
 *   · 본문 변조(Message/Timestamp/TopicArn) 거부
 *   · 서명 자체 훼손 거부
 *   · 허용되지 않은 SigningCertURL 거부 (http, 다른 호스트, 다른 리전, 포트, 비-pem)
 *   · TopicArn / region 불일치 거부
 *   · 오래된 Timestamp / 미래 Timestamp 거부
 *   · 미지원 SignatureVersion 거부, 필수 필드 누락 거부, 미허용 Type 거부
 *   · SES 이벤트 파싱 (bounce/complaint/delivery/deliveryDelay/renderingFailure/send/reject)
 *   · 미지원 이벤트(Open/Click) 무시
 *   · raw_payload 저장 토글
 *
 * 여기서 검증하지 **못하는** 것 (서버 PHPUnit 항목):
 *   HTTP 라우트·CSRF 면제·권한 게이트·DB unique 멱등·메일 헤더 삽입·prune·관리자 응답.
 *
 * 사용: php tests/Ses/yutiv-ses-monitor-check.php [--verbose]
 * 종료코드: 위반이 있으면 1
 */
$root = dirname(__DIR__, 2);
$pluginDir = $root.'/plugins/_bundled/yutiv-ses_monitor';

require_once $pluginDir.'/src/Support/SnsMessageValidator.php';
require_once $pluginDir.'/src/Support/SesEventParser.php';
require_once $pluginDir.'/tests/Support/SnsFixtureFactory.php';

use Plugins\Yutiv\SesMonitor\Support\SesEventParser;
use Plugins\Yutiv\SesMonitor\Support\SnsMessageValidator;
use Plugins\Yutiv\SesMonitor\Tests\Support\SnsFixtureFactory;

$verbose = in_array('--verbose', $argv, true);

$violations = [];
$passes = [];

/** 단언 — 실패는 모으고 계속 진행한다. */
function check($label, $condition, $detail = '')
{
    global $violations, $passes;
    if ($condition) {
        $passes[] = $label;
    } else {
        $violations[] = $label.($detail !== '' ? " — {$detail}" : '');
    }
}

echo "=== YUTIV SES 모니터 검증 ===\n\n";

if (! extension_loaded('openssl')) {
    fwrite(STDERR, "openssl 확장이 없어 서명 검증을 실행할 수 없습니다.\n");
    exit(1);
}

// ── 준비: 실제 RSA 키쌍 + 자체 서명 인증서 ──────────────────────────────────
$region = 'ap-northeast-2';
$topicArn = 'arn:aws:sns:'.$region.':123456789012:yutiv-ses-events';
$certUrl = 'https://sns.'.$region.'.amazonaws.com/SimpleNotificationService-abc123.pem';

$factory = new SnsFixtureFactory($topicArn, $certUrl);

$allowedTypes = ['SubscriptionConfirmation', 'Notification', 'UnsubscribeConfirmation'];
$allowedEvents = ['send', 'reject', 'bounce', 'complaint', 'delivery', 'deliveryDelay', 'renderingFailure'];

/** 표준 검증기 하나 만들기. */
function makeValidator($topicArn, $region, $controlMaxAge, $futureSkew, $allowedTypes, $factory)
{
    return new SnsMessageValidator($topicArn, $region, $controlMaxAge, $futureSkew, $allowedTypes, $factory->certificateFetcher());
}

$controlMaxAge = 3600;
$futureSkew = 300;
$validator = makeValidator($topicArn, $region, $controlMaxAge, $futureSkew, $allowedTypes, $factory);

echo "인증서·키쌍 생성 완료 (자체 서명, 네트워크 미사용)\n\n";

// ── 1. 정상 서명 통과 ───────────────────────────────────────────────────────
$notification = $factory->notification(SnsFixtureFactory::bounceEvent());
$r = $validator->validate($notification);
check('SignatureVersion 1: 정상 서명 통과', $r['valid'] === true, (string) $r['reason']);

$notificationV2 = $factory->notification(SnsFixtureFactory::deliveryEvent(), [], '2');
$r = $validator->validate($notificationV2);
check('SignatureVersion 2: 정상 서명 통과', $r['valid'] === true, (string) $r['reason']);

$subscription = $factory->subscriptionConfirmation();
$r = $validator->validate($subscription);
check('SubscriptionConfirmation: 정상 서명 통과', $r['valid'] === true, (string) $r['reason']);

$unsubscribe = $factory->unsubscribeConfirmation();
$r = $validator->validate($unsubscribe);
check('UnsubscribeConfirmation: 정상 서명 통과', $r['valid'] === true, (string) $r['reason']);

// ── 2. 변조 메시지 거부 ─────────────────────────────────────────────────────
$tamperedMessage = $factory->notification(
    SnsFixtureFactory::bounceEvent(),
    ['Message' => json_encode(SnsFixtureFactory::deliveryEvent())] // 서명 후 본문 교체
);
$r = $validator->validate($tamperedMessage);
check('변조: Message 교체 거부', $r['valid'] === false && $r['reason'] === SnsMessageValidator::REASON_SIGNATURE_INVALID, (string) $r['reason']);

$tamperedSubject = $factory->notification(
    SnsFixtureFactory::bounceEvent(),
    ['Subject' => '서명에 포함되지 않은 제목 추가'] // 서명 후 선택 필드 추가
);
$r = $validator->validate($tamperedSubject);
check('변조: 서명 후 Subject 추가 거부', $r['valid'] === false && $r['reason'] === SnsMessageValidator::REASON_SIGNATURE_INVALID, (string) $r['reason']);

$tamperedSignature = $factory->notification(SnsFixtureFactory::bounceEvent());
$tamperedSignature['Signature'] = base64_encode('완전히 다른 서명 바이트');
$r = $validator->validate($tamperedSignature);
check('변조: 서명 바이트 훼손 거부', $r['valid'] === false && $r['reason'] === SnsMessageValidator::REASON_SIGNATURE_INVALID, (string) $r['reason']);

$notBase64 = $factory->notification(SnsFixtureFactory::bounceEvent());
$notBase64['Signature'] = '!!!not-base64!!!';
$r = $validator->validate($notBase64);
check('변조: base64 가 아닌 서명 거부', $r['valid'] === false, (string) $r['reason']);

// ── 3. SigningCertURL allowlist ─────────────────────────────────────────────
$badUrls = [
    'http 스킴' => 'http://sns.ap-northeast-2.amazonaws.com/cert.pem',
    '공격자 호스트' => 'https://evil.example.com/cert.pem',
    'amazonaws 접미사 위장' => 'https://sns.ap-northeast-2.amazonaws.com.evil.com/cert.pem',
    '서브도메인 위장' => 'https://evil-sns.ap-northeast-2.amazonaws.com/cert.pem',
    '다른 리전' => 'https://sns.us-east-1.amazonaws.com/cert.pem',
    '명시 포트' => 'https://sns.ap-northeast-2.amazonaws.com:8443/cert.pem',
    '사용자정보 포함' => 'https://user:pass@sns.ap-northeast-2.amazonaws.com/cert.pem',
    'pem 이 아님' => 'https://sns.ap-northeast-2.amazonaws.com/cert.txt',
];

foreach ($badUrls as $label => $url) {
    check(
        "인증서 URL 거부: {$label}",
        $validator->isAllowedCertificateUrl($url) === false,
        $url
    );
}

check('인증서 URL 허용: 정상 SNS URL', $validator->isAllowedCertificateUrl($certUrl) === true);
check(
    '인증서 URL 허용: 중국 리전 표기',
    (new SnsMessageValidator($topicArn, 'cn-north-1', $controlMaxAge, $futureSkew, $allowedTypes, $factory->certificateFetcher()))
        ->isAllowedCertificateUrl('https://sns.cn-north-1.amazonaws.com.cn/x.pem') === true
);

// 실제 검증 경로에서도 거부되는지 (URL 만 바꾼 서명 유효 메시지)
$badCertMessage = $factory->notification(SnsFixtureFactory::bounceEvent(), [
    'SigningCertURL' => 'https://evil.example.com/cert.pem',
]);
$r = $validator->validate($badCertMessage);
check('검증 경로: 허용되지 않은 인증서 URL 거부', $r['valid'] === false && $r['reason'] === SnsMessageValidator::REASON_CERT_URL_REJECTED, (string) $r['reason']);

// 허용 호스트지만 우리가 모르는 인증서 → fetcher 가 null → 거부
$unknownCert = $factory->notification(SnsFixtureFactory::bounceEvent(), [
    'SigningCertURL' => 'https://sns.'.$region.'.amazonaws.com/Unknown-999.pem',
]);
$r = $validator->validate($unknownCert);
check('검증 경로: 인증서를 가져오지 못하면 거부', $r['valid'] === false && $r['reason'] === SnsMessageValidator::REASON_CERT_UNAVAILABLE, (string) $r['reason']);

// ── 4. TopicArn / region 불일치 ─────────────────────────────────────────────
$otherTopic = new SnsFixtureFactory('arn:aws:sns:'.$region.':999999999999:someone-else', $certUrl);
$foreign = $otherTopic->notification(SnsFixtureFactory::bounceEvent());
$foreignValidator = new SnsMessageValidator($topicArn, $region, $controlMaxAge, $futureSkew, $allowedTypes, $otherTopic->certificateFetcher());
$r = $foreignValidator->validate($foreign);
check('TopicArn 불일치 거부', $r['valid'] === false && $r['reason'] === SnsMessageValidator::REASON_TOPIC_MISMATCH, (string) $r['reason']);

$wrongRegionArn = 'arn:aws:sns:us-east-1:123456789012:yutiv-ses-events';
$wrongRegionFactory = new SnsFixtureFactory($wrongRegionArn, $certUrl);
$wrongRegionValidator = new SnsMessageValidator($wrongRegionArn, $region, $controlMaxAge, $futureSkew, $allowedTypes, $wrongRegionFactory->certificateFetcher());
$r = $wrongRegionValidator->validate($wrongRegionFactory->notification(SnsFixtureFactory::bounceEvent()));
check('ARN 리전 불일치 거부', $r['valid'] === false && $r['reason'] === SnsMessageValidator::REASON_REGION_MISMATCH, (string) $r['reason']);

$emptyTopicValidator = new SnsMessageValidator('', $region, $controlMaxAge, $futureSkew, $allowedTypes, $factory->certificateFetcher());
$r = $emptyTopicValidator->validate($factory->notification(SnsFixtureFactory::bounceEvent()));
check('TopicArn 미설정이면 전부 거부 (허용 아님)', $r['valid'] === false && $r['reason'] === SnsMessageValidator::REASON_TOPIC_MISMATCH, (string) $r['reason']);

// ── 5. Timestamp 정책 — 메시지 종류별로 다르다 ──────────────────────────────
//
// Notification 에 과거 상한을 걸면 장애·배포로 밀린 정상 SES 재시도가 403 이 되어
// 영구 유실된다. replay 방어는 sns_message_id unique 가 담당한다. 제어 메시지는
// 오래된 Token 으로 구독 상태를 바꾸는 위험이 있어 상한을 유지한다.

// 5-a. Notification — 과거 상한 없음
foreach ([3600, 259200, 2592000] as $ago) {
    $late = $factory->notificationAt(SnsFixtureFactory::bounceEvent(), time() - $ago);
    $r = $validator->validate($late);
    check("Notification: {$ago}초 전 메시지도 통과 (과거 상한 없음)", $r['valid'] === true, (string) $r['reason']);
}

$slightlyOld = $factory->notificationAt(SnsFixtureFactory::bounceEvent(), time() - 60);
$r = $validator->validate($slightlyOld);
check('Notification: 60초 전 메시지 통과', $r['valid'] === true, (string) $r['reason']);

// 5-b. 미래 — 전 타입 공통 거부
$future = $factory->notificationAt(SnsFixtureFactory::bounceEvent(), time() + 3600);
$r = $validator->validate($future);
check('미래 Notification 거부', $r['valid'] === false && $r['reason'] === SnsMessageValidator::REASON_TIMESTAMP_FUTURE, (string) $r['reason']);

$withinSkew = $factory->notificationAt(SnsFixtureFactory::bounceEvent(), time() + 60);
$r = $validator->validate($withinSkew);
check('허용 오차 안(60초 뒤) 미래는 통과', $r['valid'] === true, (string) $r['reason']);

$futureControl = $factory->subscriptionConfirmationAt(time() + 3600);
$r = $validator->validate($futureControl);
check('미래 SubscriptionConfirmation 거부', $r['valid'] === false && $r['reason'] === SnsMessageValidator::REASON_TIMESTAMP_FUTURE, (string) $r['reason']);

// 5-c. 제어 메시지 — 과거 상한 유지
$expiredSub = $factory->subscriptionConfirmationAt(time() - 7200);
$r = $validator->validate($expiredSub);
check('만료된 SubscriptionConfirmation 거부', $r['valid'] === false && $r['reason'] === SnsMessageValidator::REASON_TIMESTAMP_EXPIRED, (string) $r['reason']);

$freshSub = $factory->subscriptionConfirmationAt(time() - 60);
$r = $validator->validate($freshSub);
check('상한 안의 SubscriptionConfirmation 통과', $r['valid'] === true, (string) $r['reason']);

$expiredUnsub = $factory->unsubscribeConfirmationAt(time() - 7200);
$r = $validator->validate($expiredUnsub);
check('만료된 UnsubscribeConfirmation 거부', $r['valid'] === false && $r['reason'] === SnsMessageValidator::REASON_TIMESTAMP_EXPIRED, (string) $r['reason']);

$freshUnsub = $factory->unsubscribeConfirmationAt(time() - 60);
$r = $validator->validate($freshUnsub);
check('상한 안의 UnsubscribeConfirmation 통과', $r['valid'] === true, (string) $r['reason']);

// 5-d. 경계값 — 상한이 실제로 그 값에서 갈리는가
$justInside = $factory->subscriptionConfirmationAt(time() - ($controlMaxAge - 30));
check('제어 메시지: 상한 직전은 통과', $validator->validate($justInside)['valid'] === true);
$justOutside = $factory->subscriptionConfirmationAt(time() - ($controlMaxAge + 30));
check('제어 메시지: 상한 직후는 거부', $validator->validate($justOutside)['reason'] === SnsMessageValidator::REASON_TIMESTAMP_EXPIRED);

// 5-e. 파싱 불가
$garbageTs = $factory->notification(SnsFixtureFactory::bounceEvent(), ['Timestamp' => 'not-a-date']);
$r = $validator->validate($garbageTs);
check('파싱 불가 Timestamp 거부', $r['valid'] === false && $r['reason'] === SnsMessageValidator::REASON_TIMESTAMP_INVALID, (string) $r['reason']);

check('isControlType: 제어 메시지 판별', $validator->isControlType('SubscriptionConfirmation') === true
    && $validator->isControlType('UnsubscribeConfirmation') === true
    && $validator->isControlType('Notification') === false);

// ── 6. 구조·타입 검증 ───────────────────────────────────────────────────────
$badType = $factory->notification(SnsFixtureFactory::bounceEvent(), ['Type' => 'SomethingElse']);
$r = $validator->validate($badType);
check('미허용 SNS Type 거부', $r['valid'] === false && $r['reason'] === SnsMessageValidator::REASON_TYPE_NOT_ALLOWED, (string) $r['reason']);

foreach (['MessageId', 'TopicArn', 'Timestamp', 'Signature', 'SigningCertURL'] as $field) {
    $missing = $factory->notification(SnsFixtureFactory::bounceEvent(), [$field => null]);
    $r = $validator->validate($missing);
    check("필수 필드 누락 거부: {$field}", $r['valid'] === false && $r['reason'] === SnsMessageValidator::REASON_MALFORMED, (string) $r['reason']);
}

foreach (['Token', 'SubscribeURL'] as $field) {
    $missing = $factory->subscriptionConfirmation([$field => null]);
    $r = $validator->validate($missing);
    check("구독확인 필수 필드 누락 거부: {$field}", $r['valid'] === false && $r['reason'] === SnsMessageValidator::REASON_MALFORMED, (string) $r['reason']);
}

$badVersion = $factory->notification(SnsFixtureFactory::bounceEvent(), ['SignatureVersion' => '3']);
$r = $validator->validate($badVersion);
check('미지원 SignatureVersion 거부', $r['valid'] === false && $r['reason'] === SnsMessageValidator::REASON_SIGNATURE_VERSION, (string) $r['reason']);

check('signatureAlgorithm: 1 → SHA1', $validator->signatureAlgorithm('1') === OPENSSL_ALGO_SHA1);
check('signatureAlgorithm: 2 → SHA256', $validator->signatureAlgorithm('2') === OPENSSL_ALGO_SHA256);
check('signatureAlgorithm: 그 외 → null', $validator->signatureAlgorithm('0') === null && $validator->signatureAlgorithm(null) === null);

// ── 7. string-to-sign 계약 ──────────────────────────────────────────────────
$sts = $validator->stringToSign([
    'Type' => 'Notification',
    'MessageId' => 'm1',
    'TopicArn' => 't1',
    'Message' => 'body',
    'Timestamp' => 'ts',
], 'Notification');
check(
    'string-to-sign: Subject 없으면 건너뛴다',
    $sts === "Message\nbody\nMessageId\nm1\nTimestamp\nts\nTopicArn\nt1\nType\nNotification\n",
    json_encode($sts)
);

$stsWithSubject = $validator->stringToSign([
    'Type' => 'Notification',
    'MessageId' => 'm1',
    'TopicArn' => 't1',
    'Message' => 'body',
    'Subject' => 'sub',
    'Timestamp' => 'ts',
], 'Notification');
check(
    'string-to-sign: Subject 있으면 MessageId 다음 자리',
    $stsWithSubject === "Message\nbody\nMessageId\nm1\nSubject\nsub\nTimestamp\nts\nTopicArn\nt1\nType\nNotification\n",
    json_encode($stsWithSubject)
);

// ── 8. SES 이벤트 파싱 ──────────────────────────────────────────────────────
$parser = new SesEventParser($allowedEvents);

$bounce = SnsFixtureFactory::bounceEvent();
check('파싱: Bounce → bounce', $parser->eventType($bounce) === 'bounce');
$attrs = $parser->toAttributes($bounce, 'bounce', false);
check('파싱: bounce ses_message_id', $attrs['ses_message_id'] === '0100018abc-bounce', (string) $attrs['ses_message_id']);
check('파싱: bounce source', $attrs['source'] === 'no-reply@yutiv.com');
check('파싱: bounce recipients', $attrs['recipients'] === ['bounced@example.com'], json_encode($attrs['recipients']));
check('파싱: bounce_type/subtype', $attrs['bounce_type'] === 'Permanent' && $attrs['bounce_subtype'] === 'General');
check('파싱: diagnostic_code', $attrs['diagnostic_code'] === 'smtp; 550 5.1.1 user unknown', (string) $attrs['diagnostic_code']);
check('파싱: occurred_at 은 이벤트 timestamp 우선', $attrs['occurred_at'] === '2026-09-09 01:00:05', (string) $attrs['occurred_at']);
check('파싱: raw_payload 기본 미저장', $attrs['raw_payload'] === null);

$attrsRaw = $parser->toAttributes($bounce, 'bounce', true);
check('파싱: raw_payload 켜면 원문 저장', is_array($attrsRaw['raw_payload']) && $attrsRaw['raw_payload']['eventType'] === 'Bounce');

$complaint = SnsFixtureFactory::complaintEvent();
check('파싱: Complaint → complaint', $parser->eventType($complaint) === 'complaint');
$cAttrs = $parser->toAttributes($complaint, 'complaint', false);
check('파싱: complaint_feedback_type', $cAttrs['complaint_feedback_type'] === 'abuse');
check('파싱: complaint recipients', $cAttrs['recipients'] === ['angry@example.com'], json_encode($cAttrs['recipients']));
check('파싱: complaint 은 bounce 필드가 비어 있다', $cAttrs['bounce_type'] === null && $cAttrs['diagnostic_code'] === null);

$delivery = SnsFixtureFactory::deliveryEvent();
check('파싱: Delivery → delivery', $parser->eventType($delivery) === 'delivery');
$dAttrs = $parser->toAttributes($delivery, 'delivery', false);
check('파싱: delivery recipients', $dAttrs['recipients'] === ['ok@example.com'], json_encode($dAttrs['recipients']));
check('파싱: delivery occurred_at', $dAttrs['occurred_at'] === '2026-09-09 03:00:02', (string) $dAttrs['occurred_at']);

// 7종 전부 매핑되는지
$typeCases = [
    ['eventType' => 'Send', 'expect' => 'send'],
    ['eventType' => 'Reject', 'expect' => 'reject'],
    ['eventType' => 'Bounce', 'expect' => 'bounce'],
    ['eventType' => 'Complaint', 'expect' => 'complaint'],
    ['eventType' => 'Delivery', 'expect' => 'delivery'],
    ['eventType' => 'DeliveryDelay', 'expect' => 'deliveryDelay'],
    ['eventType' => 'RenderingFailure', 'expect' => 'renderingFailure'],
];
$mapped = 0;
foreach ($typeCases as $case) {
    if ($parser->eventType(['eventType' => $case['eventType']]) === $case['expect']) {
        $mapped++;
    }
}
check('파싱: 요구된 7종 이벤트가 모두 정규화된다', $mapped === 7, "매핑 {$mapped}/7");

// 구형 notificationType 형식도 받는다
check('파싱: notificationType(구형) 도 인식', $parser->eventType(['notificationType' => 'Bounce']) === 'bounce');

// 미지원 이벤트는 null
check('파싱: Open 이벤트는 무시(null)', $parser->eventType(SnsFixtureFactory::openEvent()) === null);
check('파싱: Click 이벤트는 무시(null)', $parser->eventType(['eventType' => 'Click']) === null);
check('파싱: 타입 없으면 null', $parser->eventType(['mail' => []]) === null);
check('파싱: 타입이 문자열이 아니면 null', $parser->eventType(['eventType' => ['x']]) === null);

// allowlist 를 좁히면 그 밖의 타입은 거부된다
$narrow = new SesEventParser(['bounce']);
check('파싱: allowlist 밖 이벤트는 null', $narrow->eventType(['eventType' => 'Delivery']) === null);
check('파싱: allowlist 안 이벤트는 통과', $narrow->eventType(['eventType' => 'Bounce']) === 'bounce');

// deliveryDelay 수신자 추출
$delay = [
    'eventType' => 'DeliveryDelay',
    'mail' => ['messageId' => 'id-delay', 'timestamp' => '2026-09-09T05:00:00.000Z', 'source' => 'no-reply@yutiv.com'],
    'deliveryDelay' => [
        'timestamp' => '2026-09-09T05:01:00.000Z',
        'delayType' => 'MailboxFull',
        'delayedRecipients' => [['emailAddress' => 'slow@example.com']],
    ],
];
$delayAttrs = $parser->toAttributes($delay, 'deliveryDelay', false);
check('파싱: deliveryDelay 수신자 추출', $delayAttrs['recipients'] === ['slow@example.com'], json_encode($delayAttrs['recipients']));

// renderingFailure 는 수신자 블록이 없어 mail.destination 으로 폴백
$render = [
    'eventType' => 'RenderingFailure',
    'mail' => [
        'messageId' => 'id-render',
        'timestamp' => '2026-09-09T06:00:00.000Z',
        'source' => 'no-reply@yutiv.com',
        'destination' => ['tpl@example.com'],
    ],
    'failure' => ['timestamp' => '2026-09-09T06:00:01.000Z', 'errorMessage' => 'Missing template attribute'],
];
$renderAttrs = $parser->toAttributes($render, 'renderingFailure', false);
check('파싱: renderingFailure 는 destination 으로 폴백', $renderAttrs['recipients'] === ['tpl@example.com'], json_encode($renderAttrs['recipients']));
check('파싱: renderingFailure occurred_at', $renderAttrs['occurred_at'] === '2026-09-09 06:00:01', (string) $renderAttrs['occurred_at']);

// 수신자 상한 20
$many = ['eventType' => 'Delivery', 'mail' => ['messageId' => 'bulk'], 'delivery' => ['recipients' => []]];
for ($i = 0; $i < 50; $i++) {
    $many['delivery']['recipients'][] = "u{$i}@example.com";
}
$manyAttrs = $parser->toAttributes($many, 'delivery', false);
check('파싱: 수신자는 20개로 잘린다', count($manyAttrs['recipients']) === 20, (string) count($manyAttrs['recipients']));

// 이벤트 본문이 비어도 죽지 않는다
$emptyAttrs = $parser->toAttributes(['eventType' => 'Send'], 'send', false);
check('파싱: 빈 이벤트도 예외 없이 처리', $emptyAttrs['ses_message_id'] === null && $emptyAttrs['recipients'] === []);

// ── 9. 설정 파일 계약 ───────────────────────────────────────────────────────
$configPath = $pluginDir.'/config/ses-monitor.php';
$configSource = file_get_contents($configPath);
check('설정: SES_CONFIGURATION_SET 기본값이 빈 문자열', strpos($configSource, "env('SES_CONFIGURATION_SET', '')") !== false);
check('설정: SES_SNS_TOPIC_ARN 기본값이 빈 문자열', strpos($configSource, "env('SES_SNS_TOPIC_ARN', '')") !== false);
check('설정: SES_SNS_AUTO_CONFIRM 기본값이 false', strpos($configSource, "env('SES_SNS_AUTO_CONFIRM', false)") !== false);
check('설정: 미래 오차 기본 300초', strpos($configSource, "env('SES_SNS_MAX_FUTURE_SKEW_SECONDS', 300)") !== false);
check('설정: 제어 메시지 과거 상한 기본 3600초', strpos($configSource, "env('SES_SNS_CONTROL_MAX_AGE_SECONDS', 3600)") !== false);
check('설정: 폐기된 SES_SNS_MAX_AGE_SECONDS 를 읽지 않는다', strpos($configSource, "env('SES_SNS_MAX_AGE_SECONDS'") === false);
check('설정: 기본값 근거가 문서화되어 있다', strpos($configSource, '근거:') !== false);
check('설정: SES_EVENT_RAW_PAYLOAD_ENABLED 기본값이 false', strpos($configSource, "env('SES_EVENT_RAW_PAYLOAD_ENABLED', false)") !== false);
check('설정: endpoint 경로가 webhooks/aws/ses', strpos($configSource, "'endpoint_path' => 'webhooks/aws/ses'") !== false);

// 설정 파일은 Laravel 의 env() 를 쓴다. 하네스에는 프레임워크가 없으므로
// "환경값이 하나도 없는 서버" 를 그대로 재현하는 최소 shim 을 둔다 —
// 이 상태에서 기본값이 안전한지(비활성) 확인하는 것이 이 절의 목적이다.
if (! function_exists('env')) {
    function env($key, $default = null)
    {
        $value = getenv($key);

        return $value === false ? $default : $value;
    }
}

$config = require $configPath;
check('설정: 허용 SNS Type 3종', $config['allowed_sns_types'] === $allowedTypes, json_encode($config['allowed_sns_types']));
check('설정: 허용 이벤트 7종', $config['allowed_event_types'] === $allowedEvents, json_encode($config['allowed_event_types']));
check('설정: max_future_skew_seconds 존재', isset($config['max_future_skew_seconds']) && $config['max_future_skew_seconds'] === 300);
check('설정: control_max_age_seconds 존재', isset($config['control_max_age_seconds']) && $config['control_max_age_seconds'] === 3600);
check('설정: 폐기 키 max_age_seconds 가 없다', ! array_key_exists('max_age_seconds', $config));

// 이 절에서 쓰는 소스들 (뒤 절과 중복 로드해도 무해하다)
$controllerSrc = file_get_contents($pluginDir.'/src/Http/Controllers/SesWebhookController.php');
$confirmerSrc = file_get_contents($pluginDir.'/src/Support/SubscriptionConfirmer.php');

// 두 정책이 코드에서도 분리돼 있는지 (모호한 동시 적용 방지)
$validatorSrc = file_get_contents($pluginDir.'/src/Support/SnsMessageValidator.php');
check('소스: 검증기에 maxAgeSeconds 잔재가 없다', strpos($validatorSrc, 'maxAgeSeconds') === false);
check('소스: 과거 상한은 제어 메시지에만 적용',
    strpos($validatorSrc, 'if ($this->isControlType($type) && $age > $this->controlMaxAgeSeconds)') !== false);
check('소스: 미래 검사는 타입 무관',
    strpos($validatorSrc, 'if ($age < -1 * $this->maxFutureSkewSeconds)') !== false);

$sesConfigSrc = file_get_contents($pluginDir.'/src/Support/SesConfig.php');
check('소스: SesConfig 에 maxAgeSeconds 접근자가 없다', strpos($sesConfigSrc, 'function maxAgeSeconds') === false);
check('소스: 폐기 env 잔존 감지기가 있다', strpos($sesConfigSrc, 'legacyMaxAgeEnvPresent') !== false);

$statusSrc2 = file_get_contents($pluginDir.'/src/Console/Commands/SesMonitorStatusCommand.php');
check('status: 폐기 설정이 남아 있으면 경고', strpos($statusSrc2, 'SES_SNS_MAX_AGE_SECONDS 가 .env 에 남아 있습니다') !== false);
check('status: 두 정책을 각각 표시', strpos($statusSrc2, '미래 허용 오차') !== false
    && strpos($statusSrc2, '제어 메시지 과거 상한') !== false
    && strpos($statusSrc2, 'Notification 과거 상한') !== false);

// 지연 노출 — 과거 상한을 없앤 대신 늦게 온 사실을 드러낸다
$modelSrc = file_get_contents($pluginDir.'/src/Models/SesEventLog.php');
check('소스: 지연(delaySeconds) 조회 수단이 있다', strpos($modelSrc, 'function delaySeconds') !== false);
$adminSrc2 = file_get_contents($pluginDir.'/src/Http/Controllers/Admin/SesEventLogController.php');
check('소스: 관리자 응답에 received_at/delay_seconds',
    strpos($adminSrc2, "'received_at'") !== false && strpos($adminSrc2, "'delay_seconds'") !== false);
check('소스: 오래된 이벤트는 거부가 아니라 warning', strpos($controllerSrc, 'ses_monitor.event_delayed') !== false);
check('소스: 지연 warning 에 payload/수신자를 넣지 않는다',
    strpos($controllerSrc, "'recipients' => ") === false
    && strpos($controllerSrc, "'raw_payload' => ") === false);

// 캐시 멱등 키 계약 — fail-closed
check('소스: 멱등 키에 유한 TTL', strpos($confirmerSrc, 'CACHE_TTL = 3600') !== false);
check('소스: claim 결과가 3가지로 구분된다',
    strpos($confirmerSrc, 'CLAIM_CLAIMED') !== false
    && strpos($confirmerSrc, 'CLAIM_DUPLICATE') !== false
    && strpos($confirmerSrc, 'CLAIM_UNAVAILABLE') !== false);
check('소스: add() false 는 get() 으로 중복 여부를 확인한다',
    strpos($confirmerSrc, 'Cache::get($cacheKey) !== null') !== false);
check('소스: add() 예외도 UNAVAILABLE',
    preg_match('/Cache::add\\(.*?\\} catch \\(\\\\Throwable \\$e\\) \\{.*?return self::CLAIM_UNAVAILABLE;/s', $confirmerSrc) === 1);
check('소스: get() 예외도 UNAVAILABLE',
    preg_match('/Cache::get\\(.*?\\} catch \\(\\\\Throwable \\$e\\) \\{.*?return self::CLAIM_UNAVAILABLE;/s', $confirmerSrc) === 1);
check('소스: UNAVAILABLE 이면 호출하지 않고 즉시 반환 (fail-closed)',
    strpos($confirmerSrc, 'if ($claim === self::CLAIM_UNAVAILABLE) {') !== false
    && strpos($confirmerSrc, 'if ($claim === self::CLAIM_UNAVAILABLE) {') < strpos($confirmerSrc, 'Http::withoutRedirecting()'));
check('소스: fail-open 잔재(DEDUPE_*) 가 없다', strpos($confirmerSrc, 'DEDUPE_') === false);
check('소스: 선점 실패는 500 으로 재시도를 유도',
    strpos($controllerSrc, 'RESULT_CLAIM_UNAVAILABLE') !== false
    && strpos($controllerSrc, "'claim_unavailable'], 500") !== false);
check('소스: 선점 실패 로그는 error 등급이고 미호출을 명시',
    strpos($controllerSrc, "Log::error('ses_monitor.subscription_claim_unavailable'") !== false
    && preg_match('/subscription_claim_unavailable.*?subscribe_url_called. => false/s', $controllerSrc) === 1);
check('소스: HTTP 실패 시 선점을 해제해 재시도를 허용',
    substr_count($confirmerSrc, '$this->release($cacheKey);') >= 2);
check('소스: 성공 경로는 선점을 해제하지 않는다',
    preg_match('/successful\\(\\)\\) \\{(?:(?!release).)*?RESULT_CONFIRMED/s', $confirmerSrc) === 1);

// 프로바이더 캡슐화 — 테스트 편의로 운영 API 를 넓히지 않았는가
$providerSrc2 = file_get_contents($pluginDir.'/src/Providers/SesMonitorServiceProvider.php');
check('소스: 라우트 등록은 protected 유지', strpos($providerSrc2, 'protected function registerWebhookRoute') !== false);
check('소스: 활성 판정은 protected 유지', strpos($providerSrc2, 'protected function pluginIsActive') !== false);
check('테스트: 전용 서브클래스로 접근', is_file($pluginDir.'/tests/Support/TestableSesMonitorServiceProvider.php'));


// ── 10. 소스 계약 (Laravel 없이 실행 불가한 부분의 최소 고정) ──────────────
$controllerSrc = file_get_contents($pluginDir.'/src/Http/Controllers/SesWebhookController.php');
check('소스: 서명 검증 실패 시 저장하지 않는다', strpos($controllerSrc, "if (! \$result['valid'])") !== false);
check('소스: body 크기 제한을 먼저 본다', strpos($controllerSrc, 'body_too_large') !== false
    && strpos($controllerSrc, 'body_too_large') < strpos($controllerSrc, 'json_decode'));
check('소스: 중복 MessageId 는 200 duplicate', strpos($controllerSrc, "'status' => 'duplicate'") !== false);
check('소스: 거부 로그에 payload 전체를 넣지 않는다', strpos($controllerSrc, '$raw') !== false
    && strpos($controllerSrc, "'payload' => \$raw") === false
    && strpos($controllerSrc, "'body' => \$raw") === false);
check('소스: MessageId 는 잘라서 로그', strpos($controllerSrc, 'private function safeId') !== false);

$providerSrc = file_get_contents($pluginDir.'/src/Providers/SesMonitorServiceProvider.php');
check('소스: CSRF 면제는 withoutMiddleware 로 이 라우트 하나만', strpos($providerSrc, 'withoutMiddleware([ValidateCsrfToken::class])') !== false);
check('소스: TopicArn 없으면 라우트를 등록하지 않는다', strpos($providerSrc, 'if (! SesConfig::webhookEnabled())') !== false);
check('소스: 비활성 플러그인은 리스너·라우트를 붙이지 않는다', strpos($providerSrc, 'if (! $this->pluginIsActive())') !== false);

$headerSrc = file_get_contents($pluginDir.'/src/Listeners/AttachSesConfigurationSet.php');
check('소스: 헤더는 MessageSending 에서 1회만', strpos($headerSrc, 'if ($headers->has(self::HEADER))') !== false);
check('소스: configuration set 없으면 헤더 미삽입', strpos($headerSrc, 'if (! SesConfig::headerEnabled())') !== false);

$pluginSrc = file_get_contents($pluginDir.'/plugin.php');
check('소스: 자체 권한을 선언하지 않는다 (코어 권한 재사용)', strpos($pluginSrc, 'public function getPermissions(): array') !== false
    && strpos($pluginSrc, 'return [];') !== false);

$apiSrc = file_get_contents($pluginDir.'/src/routes/api.php');
check('소스: 관리자 API 는 core.notification-logs.read 로 게이트', strpos($apiSrc, 'permission:admin,core.notification-logs.read') !== false);

$adminSrc = file_get_contents($pluginDir.'/src/Http/Controllers/Admin/SesEventLogController.php');
check('소스: 목록 응답은 수신자를 마스킹', strpos($adminSrc, "'recipients_masked' => \$event->maskedRecipients()") !== false);
check('소스: 목록 응답에 raw_payload 없음', strpos($adminSrc, "'raw_payload' => \$event->raw_payload") === false
    || strpos($adminSrc, 'private function presentRow') > strpos($adminSrc, "'raw_payload' => \$event->raw_payload"));

// ── 11. SubscribeURL 호출 계약 ──────────────────────────────────────────────
// SNS HTTPS 구독은 엔드포인트가 SubscribeURL 을 호출해야 확정된다. 호출 여부와
// 호출 대상 검증이 이 기능의 핵심 계약이라, URL 허용 규칙은 실행 검증한다.
require_once $pluginDir.'/src/Support/SubscriptionConfirmer.php';

$badSubscribeUrls = [
    'http 스킴' => 'http://sns.ap-northeast-2.amazonaws.com/?Action=ConfirmSubscription',
    '공격자 호스트' => 'https://evil.example.com/?Action=ConfirmSubscription',
    '접미사 위장' => 'https://sns.ap-northeast-2.amazonaws.com.evil.com/x',
    '서브도메인 위장' => 'https://evil-sns.ap-northeast-2.amazonaws.com/x',
    '다른 리전' => 'https://sns.us-east-1.amazonaws.com/x',
    '명시 포트' => 'https://sns.ap-northeast-2.amazonaws.com:8443/x',
    '사용자정보 포함' => 'https://u:p@sns.ap-northeast-2.amazonaws.com/x',
];

foreach ($badSubscribeUrls as $label => $url) {
    check(
        "SubscribeURL 거부: {$label}",
        \Plugins\Yutiv\SesMonitor\Support\SubscriptionConfirmer::isAllowedSubscribeUrl($url, $region) === false,
        $url
    );
}

check(
    'SubscribeURL 허용: 정상 SNS URL',
    \Plugins\Yutiv\SesMonitor\Support\SubscriptionConfirmer::isAllowedSubscribeUrl(
        'https://sns.'.$region.'.amazonaws.com/?Action=ConfirmSubscription&Token=abc',
        $region
    ) === true
);

check(
    'SubscribeURL 허용: 중국 리전 표기',
    \Plugins\Yutiv\SesMonitor\Support\SubscriptionConfirmer::isAllowedSubscribeUrl(
        'https://sns.cn-north-1.amazonaws.com.cn/?Action=ConfirmSubscription',
        'cn-north-1'
    ) === true
);

// 인증서 URL 규칙(.pem 필요)과 구독 URL 규칙(.pem 불필요)이 서로 새지 않는지
check(
    'SubscribeURL 규칙은 .pem 을 요구하지 않는다',
    \Plugins\Yutiv\SesMonitor\Support\SubscriptionConfirmer::isAllowedSubscribeUrl(
        'https://sns.'.$region.'.amazonaws.com/?Action=ConfirmSubscription',
        $region
    ) === true
);
check(
    '인증서 URL 규칙은 여전히 .pem 을 요구한다',
    $validator->isAllowedCertificateUrl('https://sns.'.$region.'.amazonaws.com/?Action=ConfirmSubscription') === false
);

$confirmerSrc = file_get_contents($pluginDir.'/src/Support/SubscriptionConfirmer.php');
check('소스: redirect 를 따라가지 않는다', strpos($confirmerSrc, 'withoutRedirecting()') !== false);
check('소스: connect/read timeout 을 건다',
    strpos($confirmerSrc, 'connectTimeout(') !== false && strpos($confirmerSrc, 'timeout(') !== false);
check('소스: 같은 MessageId 는 캐시로 1회만 호출', strpos($confirmerSrc, 'Cache::add(') !== false);
check('소스: 실패하면 중복 표시를 해제해 재시도를 허용', strpos($confirmerSrc, 'private function release') !== false);
check('소스: 호출기는 로그를 남기지 않는다 (Token/URL 유출 차단)',
    strpos($confirmerSrc, 'Log::') === false);

check('소스: auto-confirm 이 꺼져 있으면 confirm() 을 부르지 않는다',
    strpos($controllerSrc, 'if (! SesConfig::autoConfirm()) {') !== false
    && strpos($controllerSrc, 'if (! SesConfig::autoConfirm()) {') < strpos($controllerSrc, 'SubscriptionConfirmer::class)->confirm('));
check('소스: pending 로그에 subscribe_url_called=false 를 남긴다',
    strpos($controllerSrc, "'subscribe_url_called' => false") !== false);
check('소스: 확인 실패는 500 으로 재시도를 허용', strpos($controllerSrc, "'confirm_failed'], 500") !== false);
check('소스: 중복 확인 메시지는 재호출 없이 200', strpos($controllerSrc, "'already_confirmed'], 200") !== false);
check('소스: 컨트롤러 로그에 Token/SubscribeURL 을 넣지 않는다',
    strpos($controllerSrc, "'token'") === false
    && strpos($controllerSrc, "\$message['Token']") === false
    && strpos($controllerSrc, "'subscribe_url' => ") === false);

// ── 12. 문서 계약 — 잘못된 절차 문구 제거 ──────────────────────────────────
$docs = [
    'README.md' => $pluginDir.'/README.md',
    'CHANGELOG.md' => $pluginDir.'/CHANGELOG.md',
    'config/ses-monitor.php' => $pluginDir.'/config/ses-monitor.php',
    'SesWebhookController.php' => $pluginDir.'/src/Http/Controllers/SesWebhookController.php',
    'SubscriptionConfirmer.php' => $pluginDir.'/src/Support/SubscriptionConfirmer.php',
];

foreach ($docs as $label => $path) {
    $body = file_get_contents($path);
    check("문서: '콘솔에서 수동 확인' 문구 없음 — {$label}", strpos($body, '콘솔에서 수동 확인') === false);
    check("문서: '수동으로 연다' 문구 없음 — {$label}", strpos($body, '수동으로 연다') === false);
}

// fail-open 문구가 저장소에 남지 않았는지 (계약이 바뀌면 문서도 바뀌어야 한다)
$failOpenPhrases = ['멱등 보호 없이', '보호 없이 진행', '중복 호출 방지 없이'];
$failOpenTargets = [
    'README.md' => $pluginDir.'/README.md',
    'CHANGELOG.md' => $pluginDir.'/CHANGELOG.md',
    'SubscriptionConfirmer.php' => $pluginDir.'/src/Support/SubscriptionConfirmer.php',
    'SesWebhookController.php' => $pluginDir.'/src/Http/Controllers/SesWebhookController.php',
];
foreach ($failOpenTargets as $label => $path) {
    $body = file_get_contents($path);
    foreach ($failOpenPhrases as $phrase) {
        check("문서: fail-open 문구 없음 ({$phrase}) — {$label}", strpos($body, $phrase) === false);
    }
}

$readme = file_get_contents($pluginDir.'/README.md');
check('문서: 캐시 장애가 fail-closed 라고 명시', strpos($readme, 'fail-closed') !== false);
check('문서: 선점 실패 시 미호출 + 500 을 표로 설명',
    strpos($readme, 'UNAVAILABLE') !== false && strpos($readme, '미호출') !== false);
check('문서: SNS 재시도로 복구된다고 명시', strpos($readme, '재시도') !== false);
check('문서: 최초 구독 절차가 있다', strpos($readme, '최초 구독 절차') !== false);
check('문서: auto-confirm 을 켜는 단계가 있다', strpos($readme, 'SES_SNS_AUTO_CONFIRM=true') !== false);
check('문서: config:cache 단계가 있다', substr_count($readme, 'php artisan config:cache') >= 2);
check('문서: PendingConfirmation 확인 안내가 있다', strpos($readme, 'PendingConfirmation') !== false);
check('문서: 즉시 false 복귀 안내가 있다', strpos($readme, 'SES_SNS_AUTO_CONFIRM=false') !== false);
check('문서: 콘솔 클릭만으로 확정되지 않는다는 사실을 명시', strpos($readme, 'Request confirmation') !== false);
check('문서: 두 Timestamp 설정이 모두 적혀 있다',
    strpos($readme, 'SES_SNS_MAX_FUTURE_SKEW_SECONDS') !== false
    && strpos($readme, 'SES_SNS_CONTROL_MAX_AGE_SECONDS') !== false);
check('문서: Notification 에 과거 상한이 없다는 사실을 명시', strpos($readme, '과거 상한') !== false);
check('문서: 폐기된 SES_SNS_MAX_AGE_SECONDS 안내가 있다', strpos($readme, 'SES_SNS_MAX_AGE_SECONDS') !== false);
check('문서: 만료 confirmation 재발급 절차가 있다', strpos($readme, '만료') !== false);

$statusSrc = file_get_contents($pluginDir.'/src/Console/Commands/SesMonitorStatusCommand.php');
check('status: auto-confirm 상태를 명시적으로 표시', strpos($statusSrc, 'auto-confirm') !== false);
check('status: auto-confirm 이 켜져 있으면 경고', strpos($statusSrc, "if (\$payload['auto_confirm']) {") !== false);
check('status: TopicArn 은 마스킹해서만 출력', strpos($statusSrc, "\$summary['topic_arn']") !== false
    && strpos($statusSrc, 'SesConfig::topicArn()') === false);

// ── 출력 ────────────────────────────────────────────────────────────────────
if ($verbose) {
    foreach ($passes as $p) {
        echo "  OK   {$p}\n";
    }
}

foreach ($violations as $v) {
    echo "  FAIL {$v}\n";
}

echo "\n";

if ($violations === []) {
    echo 'RESULT: PASS — 통과 '.count($passes)."건, 위반 0건\n";
    exit(0);
}

echo 'RESULT: FAIL — 통과 '.count($passes).'건, 위반 '.count($violations)."건\n";
exit(1);
