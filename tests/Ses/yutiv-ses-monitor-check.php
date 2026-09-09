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

// ── 13. 테스트 helper 이름이 부모 API 와 충돌하지 않는가 ───────────────────
//
// PHP 는 부모의 public 메서드를 더 낮은 가시성으로 재정의하면 **클래스 로딩 시점에**
// Fatal Error 를 낸다. 이건 단일 파일 문법 오류가 아니라 상속 해석 오류라 `php -l` 로는
// 잡히지 않는다 — 실제로 서버 PHPUnit 첫 실행에서야 드러났다
// ("Access level to ...::post() must be public").
//
// 그래서 이름 충돌 자체를 여기서 정적으로 막는다. 가시성을 public 으로 올려 우연히
// 부모를 override 하는 것도 금지한다 — 부모 동작을 조용히 가로채기 때문이다.

$laravelTestCaseApi = [
    'get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'call', 'json',
    'getJson', 'postJson', 'putJson', 'patchJson', 'deleteJson', 'optionsJson',
    'createApplication', 'refreshApplication', 'withoutMiddleware', 'withMiddleware',
    'withHeaders', 'withHeader', 'withToken', 'actingAs', 'be', 'from',
    'travel', 'travelTo', 'travelBack', 'freezeTime', 'freezeSecond',
    'mock', 'spy', 'partialMock', 'instance', 'swap', 'seed', 'artisan',
    'followingRedirects', 'withSession', 'flushSession', 'withCookie', 'withCookies',
    'assertStatus', 'assertJson', 'assertDatabaseHas', 'assertDatabaseMissing',
];

// TestCase 계열을 상속한 클래스만 검사한다 (ArrayStore 서브클래스의 get/add 는 정상 override).
$testCaseParents = ['TestCase', 'PluginTestCase'];

$testFiles = [];
foreach (['/tests/Feature', '/tests', '/tests/Support'] as $dir) {
    $path = $pluginDir.$dir;
    if (! is_dir($path)) {
        continue;
    }
    foreach (glob($path.'/*.php') as $file) {
        $testFiles[$file] = true;
    }
}

$collisions = [];
$scanned = 0;

foreach (array_keys($testFiles) as $file) {
    $body = file_get_contents($file);

    // 파일 안의 클래스별 블록을 나눈다 (한 파일에 보조 클래스가 같이 있을 수 있다).
    if (preg_match_all('/class\s+(\w+)\s+extends\s+(\w+)/', $body, $classes, PREG_OFFSET_CAPTURE) === 0) {
        continue;
    }

    $count = count($classes[0]);
    for ($i = 0; $i < $count; $i++) {
        $className = $classes[1][$i][0];
        $parent = $classes[2][$i][0];
        $start = $classes[0][$i][1];
        $end = ($i + 1 < $count) ? $classes[0][$i + 1][1] : strlen($body);

        if (! in_array($parent, $testCaseParents, true)) {
            continue;
        }

        $scanned++;
        $block = substr($body, $start, $end - $start);

        preg_match_all('/(public|protected|private)\s+function\s+(\w+)\s*\(/', $block, $methods, PREG_SET_ORDER);

        foreach ($methods as $m) {
            $visibility = $m[1];
            $name = $m[2];

            if (in_array($name, $laravelTestCaseApi, true)) {
                $collisions[] = basename($file).": {$className}::{$name}() ({$visibility})";
            }
        }
    }
}

check('테스트 helper 이름이 Laravel TestCase API 와 충돌하지 않음',
    $collisions === [],
    implode(' / ', $collisions));
check('TestCase 파생 클래스를 실제로 스캔했다', $scanned >= 5, "스캔 {$scanned}개");

// 이름이 실제로 바뀌었는지 (되돌아가면 즉시 잡힌다)
$renamed = [
    'SesSubscriptionClaimTest.php' => 'postSubscriptionConfirmation',
    'SesSubscriptionConfirmationTest.php' => 'postSnsMessage',
    'SesTimestampPolicyTest.php' => 'postSnsMessage',
    'SesWebhookTest.php' => 'postSnsPayload',
];
foreach ($renamed as $file => $method) {
    $body = file_get_contents($pluginDir.'/tests/Feature/'.$file);
    check("테스트 helper 이름: {$file} → {$method}()",
        strpos($body, "function {$method}(") !== false
        && strpos($body, '$this->post(') === false);
}

// ── 14. 테스트의 클래스 참조가 실제로 해석되는가 ───────────────────────────
//
// `php -l` 은 단일 파일 문법만 본다. "존재하지 않는 클래스를 정적 호출" 은 문법상
// 완전하므로 통과하고, 런타임에야 Class not found 로 터진다. 실제로 서버 PHPUnit
// 2차 실행에서 이 유형이 드러났다 — `Plugins\Yutiv\...\SesMonitorServiceProvider` 의
// 백슬래시가 통째로 사라져 하나의 긴 토큰이 되어 버린 경우다.
// (여기에 그 토큰을 그대로 적으면 아래 검사가 자기 자신을 잡으므로 적지 않는다.)
//
// 그래서 여기서 두 가지를 본다.
//   (1) 구분자가 사라진 클래스 토큰이 없는가 (백슬래시 유실 탐지)
//   (2) 플러그인 소유 클래스 참조가 디스크의 실제 선언과 맞는가
//
// (2) 는 PSR-4 경로 규칙을 가정하지 않고, 파일을 훑어 만든 **실제 심볼 표**와 대조한다.

/**
 * PHP 소스에서 주석을 걷어낸다.
 *
 * "이 코드는 X 를 하지 않는다" 고 설명하는 주석 때문에 검사가 자기 문서를 벌하는 일이
 * 반복돼서, 금지 토큰 검사는 **실행되는 코드**만 본다. token_get_all 은 파서가 아니라
 * 토크나이저라 PHP 8 문법 파일도 7.4 에서 안전하게 처리한다.
 */
function sesStripComments($source)
{
    $out = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }
            $out .= $token[1];

            continue;
        }

        $out .= $token;
    }

    return $out;
}

/** 디렉토리 아래 PHP 파일 목록. */
function sesPhpFiles($dir)
{
    if (! is_dir($dir)) {
        return [];
    }

    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && substr($file->getFilename(), -4) === '.php') {
            $out[] = str_replace('\\', '/', $file->getPathname());
        }
    }
    sort($out);

    return $out;
}

// (0) 심볼 표 — 플러그인이 선언하는 모든 클래스의 FQCN
$declared = [];
foreach (sesPhpFiles($pluginDir) as $file) {
    $body = file_get_contents($file);

    if (preg_match('/^namespace\s+([^;]+);/m', $body, $ns) !== 1) {
        continue;
    }

    if (preg_match_all('/^(?:abstract\s+|final\s+)?(?:class|interface|trait)\s+(\w+)/m', $body, $types)) {
        foreach ($types[1] as $type) {
            $declared[trim($ns[1]).'\\'.$type] = $file;
        }
    }
}

check('심볼 표 구축: 플러그인 클래스를 찾았다', count($declared) >= 20, '찾은 클래스 '.count($declared).'개');

// 검사 대상 — SES 테스트 전부 + 하네스 자신
$referenceFiles = array_merge(
    sesPhpFiles($pluginDir.'/tests'),
    [$root.'/tests/Ses/yutiv-ses-monitor-check.php']
);

// (1) 구분자 유실 토큰
$mangled = [];
foreach ($referenceFiles as $file) {
    $body = file_get_contents($file);

    // 'Plugins' 'Illuminate' 'App' 'Tests' 'Symfony' 뒤에 곧바로 대문자가 붙는 토큰.
    // 정상 코드라면 그 자리에는 백슬래시가 있어야 한다.
    if (preg_match_all('/\b(Plugins|Illuminate|Symfony|Tests)(?=[A-Z])[A-Za-z]{6,}/', $body, $hits)) {
        foreach (array_unique($hits[0]) as $token) {
            $mangled[] = basename($file).': '.$token;
        }
    }
}

check('SES 테스트에 구분자 유실 클래스 토큰이 없다', $mangled === [], implode(' / ', $mangled));

// (2) 플러그인 소유 클래스 참조가 실제 선언과 맞는가
$unresolved = [];
$checkedRefs = 0;
$pluginNs = 'Plugins\\Yutiv\\SesMonitor\\';

foreach ($referenceFiles as $file) {
    $body = file_get_contents($file);

    $refs = [];

    // use 문
    if (preg_match_all('/^use\s+([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)(?:\s+as\s+\w+)?\s*;/m', $body, $uses)) {
        foreach ($uses[1] as $fqcn) {
            $refs[] = $fqcn;
        }
    }

    // 인라인 FQCN (\Plugins\... 형태)
    if (preg_match_all('/\\\\(Plugins\\\\Yutiv\\\\SesMonitor\\\\[A-Za-z0-9_\\\\]+)/', $body, $inline)) {
        foreach ($inline[1] as $fqcn) {
            $refs[] = $fqcn;
        }
    }

    foreach (array_unique($refs) as $fqcn) {
        $fqcn = ltrim($fqcn, '\\');

        // 플러그인 소유가 아닌 것(App\, Illuminate\, Tests\)은 vendor/코어라 여기서 판정하지 않는다.
        if (strpos($fqcn, $pluginNs) !== 0) {
            continue;
        }

        $checkedRefs++;

        // 인라인 FQCN 은 뒤에 ::method 가 붙어 잘릴 수 있으니 정확 일치만 본다.
        if (! isset($declared[$fqcn])) {
            $unresolved[] = basename($file).': '.$fqcn;
        }
    }
}

check('플러그인 소유 클래스 참조가 모두 실제 선언과 일치', $unresolved === [], implode(' / ', $unresolved));
check('참조 검사가 실제로 수행됐다', $checkedRefs >= 10, "검사한 참조 {$checkedRefs}건");

// (3) PluginTestCase 의 프로바이더 참조를 콕 집어 고정
$ptc = file_get_contents($pluginDir.'/tests/PluginTestCase.php');

check('PluginTestCase: 프로바이더를 정상 FQCN 으로 import',
    strpos($ptc, 'use Plugins\Yutiv\SesMonitor\Providers\SesMonitorServiceProvider;') !== false);
check('PluginTestCase: import 한 짧은 이름으로 정적 호출',
    strpos($ptc, 'SesMonitorServiceProvider::invalidatePluginStatusCache();') !== false);
check('PluginTestCase: 캐시 무효화를 활성/비활성 양쪽에서 호출',
    substr_count($ptc, 'SesMonitorServiceProvider::invalidatePluginStatusCache();') === 2,
    '호출 '.substr_count($ptc, 'SesMonitorServiceProvider::invalidatePluginStatusCache();').'회');
check('PluginTestCase: 참조하는 프로바이더가 실제로 존재',
    isset($declared['Plugins\Yutiv\SesMonitor\Providers\SesMonitorServiceProvider']));
check('PluginTestCase: invalidatePluginStatusCache 를 제공하는 trait 이 붙어 있다',
    strpos(file_get_contents($pluginDir.'/src/Providers/SesMonitorServiceProvider.php'), 'use CachesPluginStatus;') !== false);

// ── 15. 테스트 격리 계약 (메일 전송기 · 관리자 API 라우트) ─────────────────
//
// 서버 PHPUnit 3차 실행에서 드러난 두 결함을 소스 계약으로 고정한다.
//   A. phpunit.xml 의 MAIL_MAILER=array 가 SettingsServiceProvider 의 DB 설정 주입에
//      덮여 실제 SMTP(127.0.0.1:587) 로 나갔다.
//   B. PluginRouteServiceProvider 가 부팅 시점의 빈 plugins 테이블을 읽어
//      api/plugins/... 라우트를 등록하지 않아 권한 검사 전에 404 가 났다.
//
// 둘 다 `php -l` 로도, 서명·파싱 검증으로도 잡히지 않는 부팅 순서 문제다.

$ptcSrc = file_get_contents($pluginDir.'/tests/PluginTestCase.php');
$integrationSrc = file_get_contents($pluginDir.'/tests/Feature/SesMonitorIntegrationTest.php');

// A. 메일 격리
check('메일: 테스트 베이스가 mail.default 를 array 로 강제한다',
    strpos($ptcSrc, "'mail.default' => 'array'") !== false);
check('메일: array transport 설정도 함께 고정',
    strpos($ptcSrc, "'mail.mailers.array' => ['transport' => 'array']") !== false);
check('메일: 이미 resolve 된 mailer 를 버린다 (purge)',
    strpos($ptcSrc, "forgetInstance('mail.manager')") !== false
    && strpos($ptcSrc, "forgetInstance('mailer')") !== false);
check('메일: forgetMailers 도 호출한다', strpos($ptcSrc, 'forgetMailers()') !== false);
check('메일: 파사드 캐시도 비운다', strpos($ptcSrc, 'Mail::clearResolvedInstances()') !== false);
check('메일: setUp 에서 격리가 활성화 이전에 수행된다',
    strpos($ptcSrc, '$this->forceArrayMailer();') !== false
    && strpos($ptcSrc, '$this->forceArrayMailer();') < strpos($ptcSrc, '$this->activatePlugin();'));

check('메일: effective config 를 단언하는 헬퍼가 있다',
    strpos($ptcSrc, 'function assertArrayMailerActive') !== false);
check('메일: 헬퍼가 config 와 실제 transport 인스턴스를 둘 다 본다',
    strpos($ptcSrc, "config('mail.default')") !== false
    && strpos($ptcSrc, 'ArrayTransport::class') !== false
    && strpos($ptcSrc, 'getSymfonyTransport()') !== false);

check('메일: 메일 전송 헬퍼가 보내기 전에 격리를 단언한다',
    preg_match('/function captureHeadersOfSentMail.*?assertArrayMailerActive\(\).*?Mail::raw/s', $integrationSrc) === 1);
check('메일: 헤더 덮어쓰기 테스트도 같은 단언을 한다',
    substr_count($integrationSrc, '$this->assertArrayMailerActive();') >= 3,
    '단언 '.substr_count($integrationSrc, '$this->assertArrayMailerActive();').'회');
check('메일: SMTP 전송기가 아님을 명시적으로 단언',
    strpos($integrationSrc, 'assertNotInstanceOf(EsmtpTransport::class') !== false);
// 산문(주석)에 이름이 등장하는 것과 **실제 호출**을 구분한다 — 줄 첫머리의 호출문만 본다.
// (왜 안 쓰는지 설명하는 주석까지 잡으면 검사가 자기 문서를 벌하게 된다.)
check('메일: Mail::fake() 로 전송 파이프라인을 우회하지 않는다',
    preg_match('/^\s*(Mail::fake|\$this->\w*[Mm]ail\w*->fake)\s*\(/m', $integrationSrc) === 0
    && preg_match('/^\s*(Mail::fake|\$this->\w*[Mm]ail\w*->fake)\s*\(/m', $ptcSrc) === 0);
check('메일: MessageSending 리스너가 실제로 붙는 경로를 태운다',
    strpos($ptcSrc, 'bootPluginAsActive') !== false
    && strpos($ptcSrc, '$this->app->register(SesMonitorServiceProvider::class);') !== false);

// B. 관리자 API 라우트
check('라우트: 테스트 베이스가 플러그인 API 라우트를 명시적으로 로드한다',
    strpos($ptcSrc, 'function registerPluginApiRoutes') !== false);
check('라우트: 운영과 동일한 prefix 를 쓴다',
    strpos($ptcSrc, "Route::prefix('api/plugins/yutiv-ses_monitor')") !== false);
check('라우트: 운영과 동일한 name prefix 를 쓴다',
    strpos($ptcSrc, "->name('api.plugins.yutiv-ses_monitor.')") !== false);
check('라우트: 운영과 동일한 middleware 그룹을 쓴다',
    strpos($ptcSrc, "->middleware('api')") !== false);
check('라우트: 중복 등록을 막는 가드가 있다',
    strpos($ptcSrc, 'if ($this->countRoutesForUri(self::ADMIN_API_URI') !== false);
check('라우트: 프로바이더 생명주기 재실행도 1회로 제한',
    strpos($ptcSrc, '$this->pluginRegistered') !== false);
check('라우트: setUp 이 API 라우트를 등록한다',
    strpos($ptcSrc, '$this->registerPluginApiRoutes();') !== false);

check('라우트: webhook 과 관리자 API 를 서로 다른 경로로 다룬다',
    strpos($ptcSrc, "'api/plugins/yutiv-ses_monitor/admin/ses-events'") !== false
    && strpos($ptcSrc, "SesConfig::endpointPath()") === false);

// 404 를 성공으로 허용하지 않는가
check('라우트: 라우트 존재를 먼저 단언하는 테스트가 있다',
    strpos($integrationSrc, '관리자_API_라우트가_테스트_앱에_등록되어_있다') !== false);
check('라우트: 중복 등록 방지 테스트가 있다',
    strpos($integrationSrc, '관리자_API_라우트를_두_번_등록해도_중복되지_않는다') !== false);
check('라우트: 권한 테스트가 404 를 통과로 인정하지 않는다',
    substr_count($integrationSrc, "assertNotSame(404, \$response->getStatusCode()") >= 3,
    '가드 '.substr_count($integrationSrc, "assertNotSame(404, \$response->getStatusCode()").'곳');
check('라우트: 비로그인은 redirect 가 아니라 401 을 요구한다',
    strpos($integrationSrc, 'assertNotSame(302, $response->getStatusCode()') !== false
    && strpos($integrationSrc, 'assertUnauthorized()') !== false);
check('라우트: 권한 없음 403 · 권한 있음 200 을 각각 검증',
    strpos($integrationSrc, '$response->assertForbidden();') !== false
    && strpos($integrationSrc, '$response->assertOk()->assertJsonPath') !== false);
check('라우트: 테스트가 URI 를 상수로 공유한다 (오타·표류 방지)',
    strpos($integrationSrc, "'/api/plugins/yutiv-ses_monitor/admin/ses-events'") === false);

// ── 16. PHPUnit 11 · 암호화 키 계약 ────────────────────────────────────────
//
// 서버 4차 실행에서 드러난 두 결함을 고정한다.
//   A. Symfony `Headers::all()` 은 Generator 를 돌려주는데 이를 PHPUnit haystack 으로
//      직접 넘겨 `GeneratorNotSupportedException` 이 났다 (PHPUnit 11 계약).
//   B. webhook 은 web 그룹이라 쿠키 암호화를 거치는데 APP_KEY 가 없어
//      `MissingAppKeyException` 이 났다.
//
// 둘 다 문법 오류가 아니라 런타임 계약 위반이라 `php -l` 로 잡히지 않는다.

$ptcSrc = file_get_contents($pluginDir.'/tests/PluginTestCase.php');
$integrationSrc = file_get_contents($pluginDir.'/tests/Feature/SesMonitorIntegrationTest.php');

// A. Generator 를 haystack 으로 넘기지 않는가 — SES 테스트 전수
$generatorHaystacks = [];
foreach (sesPhpFiles($pluginDir.'/tests') as $file) {
    $body = file_get_contents($file);

    // assertXxx( ... ->all(...) ... ) 처럼 iterable 을 그대로 넘기는 형태.
    // 줄바꿈을 포함한 인자도 잡도록 s 플래그로 assert 호출 한 덩어리를 본다.
    if (preg_match_all('/\$this->assert(Count|Contains|NotContains|ContainsEquals|Empty|NotEmpty)\s*\((?:[^();]|\([^()]*\))*\)/s', $body, $calls)) {
        foreach ($calls[0] as $call) {
            // materialize 를 거친 호출은 안전하다.
            if (strpos($call, 'headerValues(') !== false
                || strpos($call, 'headerBodies(') !== false
                || strpos($call, 'iterator_to_array(') !== false) {
                continue;
            }

            // 헤더 컬렉션·이터레이터를 직접 넘기는 형태만 위반으로 본다.
            if (preg_match('/->(all|getIterator)\s*\(/', $call) === 1) {
                $generatorHaystacks[] = basename($file).': '.preg_replace('/\s+/', ' ', substr($call, 0, 70));
            }
        }
    }
}

check('PHPUnit: assertion haystack 에 Generator/이터레이터를 직접 넘기지 않는다',
    $generatorHaystacks === [], implode(' / ', $generatorHaystacks));

check('PHPUnit: 헤더를 배열로 확정하는 타입 안전 헬퍼가 있다',
    strpos($ptcSrc, 'function headerValues(Headers $headers, string $name): array') !== false);
check('PHPUnit: 헬퍼가 array/Traversable 양쪽을 안전하게 다룬다',
    strpos($ptcSrc, 'is_array($all) ? array_values($all) : iterator_to_array($all, false)') !== false);
check('PHPUnit: 본문 문자열 목록 헬퍼도 있다',
    strpos($ptcSrc, 'function headerBodies(Headers $headers, string $name): array') !== false);

// 원래 계약이 약해지지 않았는가 — 개수 1회 + 비덮어쓰기
check('계약 유지: configuration-set 헤더 정확히 1회 단언',
    substr_count($integrationSrc, "\$this->assertCount(1, \$values, '헤더가 없거나 두 번 이상 붙었습니다')") === 1);
check('계약 유지: 개수와 값을 함께 못박는 단언',
    strpos($integrationSrc, "\$this->assertSame(\n            ['yutiv-production'],") !== false);
check('계약 유지: 기존 헤더 비덮어쓰기 단언 (개수 1 + 값 보존)',
    strpos($integrationSrc, "\$this->assertCount(1, \$this->headerValues(\$headers, AttachSesConfigurationSet::HEADER));") !== false
    && strpos($integrationSrc, "['already-set'],") !== false
    && strpos($integrationSrc, "\$this->assertSame('already-set',") !== false);
check('계약 유지: 헤더 단언을 문자열 전체 검색으로 약화하지 않았다',
    strpos($integrationSrc, 'assertStringContainsString(AttachSesConfigurationSet::HEADER') === false);

// B. 테스트 전용 APP_KEY
check('APP_KEY: 결정적 테스트 키 상수가 있다',
    strpos($ptcSrc, 'TEST_APP_KEY_PLAINTEXT') !== false);
check('APP_KEY: 키가 AES-256 에 맞는 32바이트',
    preg_match("/TEST_APP_KEY_PLAINTEXT = '([^']+)'/", $ptcSrc, $km) === 1 && strlen($km[1]) === 32,
    isset($km[1]) ? strlen($km[1]).'바이트' : '상수 없음');
check('APP_KEY: base64: 접두로 config 에 넣는다',
    strpos($ptcSrc, "config(['app.key' => 'base64:'.base64_encode(self::TEST_APP_KEY_PLAINTEXT)]);") !== false);
check('APP_KEY: 앱 생성 직후(부팅 단계)에 설정한다',
    strpos($ptcSrc, '$this->afterApplicationCreated(function () {') !== false
    && strpos($ptcSrc, '$this->useDeterministicTestAppKey();') !== false
    && strpos($ptcSrc, '$this->useDeterministicTestAppKey();') < strpos($ptcSrc, 'parent::setUp();'));
check('APP_KEY: 이미 만들어진 encrypter 를 버린다',
    strpos($ptcSrc, "forgetInstance('encrypter')") !== false
    && strpos($ptcSrc, 'Crypt::clearResolvedInstances()') !== false);

// 운영 키·파일을 건드리지 않는가
$envTouch = [];
foreach (sesPhpFiles($pluginDir.'/tests') as $file) {
    // 주석은 제외하고 **실행되는 코드**만 본다.
    $body = sesStripComments(file_get_contents($file));

    foreach (["env('APP_KEY')", 'getenv(\'APP_KEY\')', '$_ENV[\'APP_KEY\']', 'key:generate', 'base_path(\'.env', 'file_put_contents'] as $forbidden) {
        if (strpos($body, $forbidden) !== false) {
            $envTouch[] = basename($file).': '.$forbidden;
        }
    }
}

check('APP_KEY: 운영 키나 .env 를 읽거나 쓰지 않는다', $envTouch === [], implode(' / ', $envTouch));
check('APP_KEY: key:generate 를 실행하지 않는다',
    strpos(sesStripComments($ptcSrc), 'key:generate') === false
    && strpos(sesStripComments($integrationSrc), 'key:generate') === false);

// ── 17. 컨테이너 바인딩 · 프로바이더 생명주기 계약 ────────────────────────
//
// 서버 5차 실행에서 드러난 결함: `app(SesEventParser::class)` 가
// `Unresolvable dependency [array $allowedEventTypes]` 로 터졌다.
//
// 원인은 운영이 아니라 테스트였다. `App\Providers\PluginServiceProvider` 는
// `plugins/` **바로 아래**만 훑는데(비재귀) 이 저장소에는 `_bundled`·`_pending` 뿐이라
// 플러그인 프로바이더가 자동 등록되지 않는다. 테스트가 boot() 만 다시 불러서
// register() 의 바인딩이 전부 빠져 있었다.
//
// 여기서는 (1) 필수 primitive 를 받는 클래스가 전부 바인딩돼 있는지 (2) 바인딩이 공식
// config 값을 쓰는지 (3) 테스트가 운영 생명주기를 따르는지를 소스로 고정한다.

$providerSrc = file_get_contents($pluginDir.'/src/Providers/SesMonitorServiceProvider.php');
$ptcSrc = file_get_contents($pluginDir.'/tests/PluginTestCase.php');
$integrationSrc = file_get_contents($pluginDir.'/tests/Feature/SesMonitorIntegrationTest.php');

// (1) app()/resolve() 대상 중 필수 primitive 를 받는 클래스는 바인딩이 있어야 한다.
$productionFiles = array_merge(
    sesPhpFiles($pluginDir.'/src'),
    [$pluginDir.'/plugin.php']
);

$resolvedTargets = [];
foreach ($productionFiles as $file) {
    $code = sesStripComments(file_get_contents($file));

    if (preg_match_all('/\b(?:app|resolve)\(\s*([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)::class\s*\)/', $code, $hits)) {
        foreach ($hits[1] as $short) {
            $resolvedTargets[$short] = basename($file);
        }
    }
}

check('컨테이너: 운영 코드의 app()/resolve() 대상을 찾았다',
    count($resolvedTargets) >= 3, '찾은 대상 '.count($resolvedTargets).'개');

// 플러그인 소유 클래스의 생성자에 필수 primitive 가 있는지 조사
$needsBinding = [];
foreach (sesPhpFiles($pluginDir.'/src') as $file) {
    $code = sesStripComments(file_get_contents($file));

    if (preg_match('/^(?:abstract\s+|final\s+)?class\s+(\w+)/m', $code, $cls) !== 1) {
        continue;
    }
    $className = $cls[1];

    if (preg_match('/public function __construct\s*\((.*?)\)\s*[:{]/s', $code, $ctor) !== 1) {
        continue;
    }

    $params = trim($ctor[1]);
    if ($params === '') {
        continue;
    }

    // 필수(기본값 없음) primitive 타입 인자가 하나라도 있는가
    $hasRequiredPrimitive = false;
    foreach (explode(',', $params) as $param) {
        $param = trim($param);
        if ($param === '' || strpos($param, '=') !== false) {
            continue; // 기본값 있음 → 자동 해석 가능
        }
        if (preg_match('/\b(array|string|int|float|bool|iterable)\s+\$/', $param) === 1) {
            $hasRequiredPrimitive = true;
            break;
        }
    }

    if ($hasRequiredPrimitive) {
        $needsBinding[$className] = basename($file);
    }
}

check('컨테이너: 필수 primitive 생성자를 가진 클래스를 찾았다',
    count($needsBinding) >= 2, implode(', ', array_keys($needsBinding)));

$missingBindings = [];
foreach ($needsBinding as $className => $file) {
    // 해석 대상이 아니면 컨테이너를 거치지 않으므로 바인딩이 없어도 된다.
    if (! isset($resolvedTargets[$className])) {
        continue;
    }

    if (strpos($providerSrc, 'bind('.$className.'::class') === false
        && strpos($providerSrc, 'singleton('.$className.'::class') === false) {
        $missingBindings[] = $className.' ('.$file.')';
    }
}

check('컨테이너: app() 으로 해석되는 primitive 생성자 클래스가 모두 바인딩돼 있다',
    $missingBindings === [], implode(' / ', $missingBindings));

// (2) 바인딩이 공식 config 값을 쓰는가
check('바인딩: SesEventParser 가 SesConfig 의 허용 이벤트를 쓴다',
    preg_match('/bind\(SesEventParser::class.*?SesConfig::allowedEventTypes\(\)/s', $providerSrc) === 1);
check('바인딩: SnsMessageValidator 가 SesConfig 값을 쓴다',
    preg_match('/bind\(SnsMessageValidator::class.*?SesConfig::topicArn\(\).*?SesConfig::allowedSnsTypes\(\)/s', $providerSrc) === 1);
check('바인딩: PendingSentMessage 는 싱글턴',
    strpos($providerSrc, 'singleton(PendingSentMessage::class)') !== false);
check('바인딩: 하드코딩 배열로 우회하지 않는다',
    preg_match("/bind\(SesEventParser::class.*?\[\s*'send'/s", $providerSrc) !== 1);

// (3) 테스트가 운영 생명주기를 따르는가
check('생명주기: 테스트가 register() → boot() 를 운영과 같은 방식으로 실행',
    strpos($ptcSrc, '$this->app->register(SesMonitorServiceProvider::class);') !== false);
check('생명주기: boot() 만 따로 부르지 않는다',
    strpos($ptcSrc, '(new SesMonitorServiceProvider($this->app))->boot();') === false);
// 메서드가 존재하는 것과 setUp 이 실제로 부르는 것은 다르다 — 호출까지 고정한다.
$ptcSetUp = '';
if (preg_match('/protected function setUp\\(\\): void\\s*\\{(.*?)\\n    \\}/s', $ptcSrc, $setUpMatch) === 1) {
    $ptcSetUp = $setUpMatch[1];
}

check('생명주기: setUp 본문을 읽었다', $ptcSetUp !== '');
check('생명주기: setUp 이 프로바이더 생명주기를 실제로 호출한다',
    strpos($ptcSetUp, '$this->bootPluginAsActive();') !== false);
check('생명주기: 활성 시드가 프로바이더 등록보다 앞선다',
    strpos($ptcSetUp, '$this->activatePlugin();') !== false
    && strpos($ptcSetUp, '$this->activatePlugin();') < strpos($ptcSetUp, '$this->bootPluginAsActive();'));
check('생명주기: setUp 이 API 라우트 등록까지 호출한다',
    strpos($ptcSetUp, '$this->registerPluginApiRoutes();') !== false);
check('생명주기: 반복 실행을 막는 플래그가 있다',
    strpos($ptcSrc, '$this->pluginRegistered') !== false);
check('생명주기: fixture 검증기 바인딩이 프로바이더 등록 뒤에 온다',
    strpos($ptcSrc, '$this->bootPluginAsActive();') < strpos($ptcSrc, '$this->bindFixtureValidator();'),
    '순서가 뒤바뀌면 서명 검증이 실제 인증서를 가지러 나간다');

// 테스트가 운영 계약을 복제하지 않는가
$parserBypass = [];
foreach (sesPhpFiles($pluginDir.'/tests') as $file) {
    $code = sesStripComments(file_get_contents($file));

    foreach (['new SesEventParser(', 'bind(SesEventParser::class'] as $bypass) {
        if (strpos($code, $bypass) !== false) {
            $parserBypass[] = basename($file).': '.$bypass;
        }
    }
}

check('생명주기: 테스트가 parser 를 직접 new 하거나 임의 배열로 재바인딩하지 않는다',
    $parserBypass === [], implode(' / ', $parserBypass));

// (4) 런타임 계약 테스트가 존재하는가
check('런타임 계약: 컨테이너 해석 테스트가 있다',
    strpos($integrationSrc, '플러그인_서비스가_컨테이너에서_해석된다') !== false);
check('런타임 계약: parser 허용 이벤트가 설정과 일치하는지 본다',
    strpos($integrationSrc, '해석된_parser_의_허용_이벤트가_설정과_일치한다') !== false
    && strpos($integrationSrc, 'SesConfig::allowedEventTypes()') !== false);
check('런타임 계약: 싱글턴 계약 테스트가 있다',
    strpos($integrationSrc, 'PendingSentMessage_는_요청_범위_싱글턴이다') !== false);
check('런타임 계약: 생명주기 반복 시 중복 등록 없음 테스트가 있다',
    strpos($integrationSrc, '프로바이더_생명주기를_반복해도_중복_등록되지_않는다') !== false);
check('런타임 계약: 회귀 테스트가 저장 1건도 함께 단언한다',
    strpos($integrationSrc, "SesEventLog::where('ses_message_id', 'regress-1')->count()") !== false);
check('런타임 계약: 500 을 기대값으로 바꾸거나 예외를 숨기지 않는다',
    strpos($integrationSrc, 'assertStatus(500)') === false
    && preg_match('/try\s*\{[^}]*webhooks\/aws\/ses/s', $integrationSrc) !== 1);

// ── 18. 테스트 격리 계약 (전체 실행에서 드러난 오염) ──────────────────────
//
// 104종 전체 실행에서 13건이 깨졌고, 대부분 개별 결함이 아니라 **공유 상태 오염**이었다.
// 재발을 막을 최소 계약만 고정한다.

$ptcSrc = file_get_contents($pluginDir.'/tests/PluginTestCase.php');
$featureFiles = sesPhpFiles($pluginDir.'/tests/Feature');

// (1) 인자 없는 Http::fake() 금지
//     Laravel 은 stub 을 등록 순서대로 훑어 첫 일치를 쓴다. 인자 없는 fake 는 모든 URL 에
//     매칭되는 catch-all(200) 이라, 뒤에 선언한 500/302 stub 이 영영 쓰이지 않는다.
$catchAllFakes = [];
foreach (array_merge($featureFiles, [$pluginDir.'/tests/PluginTestCase.php']) as $file) {
    $code = sesStripComments(file_get_contents($file));

    if (preg_match('/Http::fake\(\s*\)/', $code) === 1) {
        $catchAllFakes[] = basename($file);
    }
}

check('격리: 인자 없는 Http::fake() catch-all 을 쓰지 않는다',
    $catchAllFakes === [], implode(' / ', $catchAllFakes));
check('격리: 그래도 stray 요청은 막는다',
    strpos($ptcSrc, 'Http::preventStrayRequests();') !== false);

// 한 테스트 안에서 Http::fake() 를 두 번 부르면 stub 이 **병합**되어 먼저 등록한 응답이
// 계속 이긴다(첫 일치 우선). 게다가 두 번째 fake() 가 recorded 를 비워 전송 횟수 단언까지
// 가린다 — 재시도 시나리오가 조용히 거짓 통과한다. 순서가 필요한 곳은 sequence 로 쓴다.
$multiFakeMethods = [];
$scannedTestMethods = 0;
foreach ($featureFiles as $file) {
    $code = sesStripComments(file_get_contents($file));

    // /u 없이는 \w 가 한글 메서드명을 매칭하지 못해 아무것도 검사하지 못한다.
    if (preg_match_all('/public function (test_\\w+)\\(\\): void\\s*\\{(.*?)\\n    \\}/su', $code, $methods, PREG_SET_ORDER) === 0) {
        continue;
    }

    $scannedTestMethods += count($methods);

    foreach ($methods as $method) {
        if (substr_count($method[2], 'Http::fake(') > 1) {
            $multiFakeMethods[] = basename($file).'::'.$method[1];
        }
    }
}

check('격리: 한 테스트에서 Http::fake() 를 두 번 부르지 않는다 (순서는 sequence 로)',
    $multiFakeMethods === [], implode(' / ', $multiFakeMethods));
check('격리: 테스트 메서드 스캔이 실제로 동작했다', $scannedTestMethods >= 100, "스캔 {$scannedTestMethods}종");

// 재시도 계약이 실제로 sequence + 전송 2회로 고정돼 있는가
$retryFiles = [
    'SesSubscriptionClaimTest.php' => $pluginDir.'/tests/Feature/SesSubscriptionClaimTest.php',
    'SesSubscriptionConfirmationTest.php' => $pluginDir.'/tests/Feature/SesSubscriptionConfirmationTest.php',
];
foreach ($retryFiles as $label => $path) {
    $code = file_get_contents($path);

    check("재시도: {$label} 이 sequence 로 500 → 200 을 표현한다",
        strpos($code, 'Http::sequence()') !== false
        && strpos($code, "->push('err', 500)") !== false
        && strpos($code, "->push('ok', 200)") !== false);
    check("재시도: {$label} 이 전송 2회를 단언한다",
        strpos($code, 'Http::assertSentCount(2);') !== false);
    check("재시도: {$label} 이 실패 후 선점 해제·성공 후 유지를 단언한다",
        strpos($code, 'assertNull(Cache::get($claimKey)') !== false
        && strpos($code, 'assertNotNull(Cache::get($claimKey)') !== false);
    check("재시도: {$label} 이 실제 SubscribeURL 로 호출했는지 확인한다",
        strpos($code, "\$request->url() === \$message['SubscribeURL']") !== false);
    check("재시도: {$label} 이 sequence 소진 후 임의 응답을 허용하지 않는다",
        strpos($code, 'whenEmpty(') === false
        && strpos($code, 'dontFailWhenEmpty(') === false);
}


// (2) Cache 파사드 root 를 Repository 로 바꾸지 않는다 (Cache::store() 계약 유지)
$badCacheSwaps = [];
foreach ($featureFiles as $file) {
    $code = sesStripComments(file_get_contents($file));

    if (preg_match('/Cache::swap\(\s*new\s+\\\\?(Illuminate\\\\Cache\\\\)?(Cache)?Repository/', $code) === 1) {
        $badCacheSwaps[] = basename($file);
    }
}

check('격리: Cache root 를 Repository 로 바꾸지 않는다 (store() 계약 유지)',
    $badCacheSwaps === [], implode(' / ', $badCacheSwaps));
check('격리: 기본 스토어만 고장 내는 CacheManager 를 쓴다',
    is_file($pluginDir.'/tests/Support/BrokenDefaultCacheManager.php')
    && strpos($ptcSrc, 'function swapBrokenDefaultCache') !== false);

$brokenManagerSrc = is_file($pluginDir.'/tests/Support/BrokenDefaultCacheManager.php')
    ? file_get_contents($pluginDir.'/tests/Support/BrokenDefaultCacheManager.php')
    : '';

check('격리: 이름 지정 스토어는 진짜 저장소로 위임한다',
    strpos($brokenManagerSrc, 'extends CacheManager') !== false
    && strpos($brokenManagerSrc, 'return parent::store($name);') !== false);
check('격리: 이름 없는 기본 접근만 고장 낸다',
    strpos($brokenManagerSrc, 'if ($name === null)') !== false);

$claimSrc = is_file($pluginDir.'/tests/Feature/SesSubscriptionClaimTest.php')
    ? file_get_contents($pluginDir.'/tests/Feature/SesSubscriptionClaimTest.php')
    : '';

check('격리: 두 경로가 다른 저장소를 본다는 것을 테스트가 증명한다',
    strpos($claimSrc, "Cache::store(config('cache.default'))") !== false
    && strpos($claimSrc, 'assertNotInstanceOf') !== false);
check('격리: BrokenCacheStore 에 store() 를 덧붙여 오류만 숨기지 않았다',
    strpos($claimSrc, 'public function store(') === false);

// (3) 구조화 로그는 이벤트가 아니라 결정적 기록기로 확인한다
$listenUsers = [];
foreach ($featureFiles as $file) {
    $code = sesStripComments(file_get_contents($file));

    if (strpos($code, 'Log::listen(') !== false) {
        $listenUsers[] = basename($file);
    }
}

check('격리: 로그 단언에 Log::listen() 을 쓰지 않는다',
    $listenUsers === [], implode(' / ', $listenUsers));
check('격리: 결정적 로그 기록기가 있다',
    is_file($pluginDir.'/tests/Support/RecordingLogSpy.php')
    && strpos($ptcSrc, "\$this->app->instance('log', \$this->logSpy);") !== false
    && strpos($ptcSrc, 'Log::swap($this->logSpy);') !== false);

// (4) tearDown 상태 복원
check('격리: tearDown 이 시간 여행을 되돌린다',
    strpos($ptcSrc, 'Carbon::setTestNow();') !== false);
check('격리: tearDown 이 파사드 root 를 복원한다',
    strpos($ptcSrc, 'Cache::clearResolvedInstances();') !== false
    && strpos($ptcSrc, 'Log::clearResolvedInstances();') !== false
    && strpos($ptcSrc, 'Http::clearResolvedInstances();') !== false);
check('격리: tearDown 이 프로바이더 재등록 플래그를 되돌린다',
    preg_match('/protected function tearDown.*?\$this->pluginRegistered = false;/s', $ptcSrc) === 1);

// (5) 테스트 수 동결 — 증상마다 테스트를 늘리지 않는다
$testCount = 0;
foreach ($featureFiles as $file) {
    $testCount += preg_match_all('/public function test_/', file_get_contents($file));
}

check('격리: SES 테스트 수가 104종으로 유지된다', $testCount === 104, "현재 {$testCount}종");

// (6) 낡은 정책 잔재가 없는가
$webhookSrc = is_file($pluginDir.'/tests/Feature/SesWebhookTest.php')
    ? file_get_contents($pluginDir.'/tests/Feature/SesWebhookTest.php')
    : '';

check('정책: 오래된 Notification 을 거부한다는 낡은 기대가 없다',
    strpos($webhookSrc, 'test_오래된_timestamp_를_거부한다') === false);
check('정책: 그 자리는 control 메시지 만료 검사로 재목적화됐다',
    strpos($webhookSrc, 'test_만료된_control_메시지_timestamp_를_거부한다') !== false);
check('정책: GET 검사가 405 응답코드가 아니라 라우팅 사실을 본다',
    strpos($webhookSrc, "assertStatus(405)") === false
    && strpos($webhookSrc, "assertSame(['POST'], \$methods") !== false
    && strpos($webhookSrc, "'yutiv.ses-monitor.webhook'") !== false);

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
