<?php

namespace Plugins\Yutiv\SesMonitor\Support;

/**
 * AWS SNS 메시지 서명 검증기.
 *
 * AWS SDK 없이 SNS 의 공개 계약만으로 구현한다. 검증 순서는 "싼 검사부터" 로,
 * 서명 검증(인증서 fetch + RSA)은 구조·출처·시각 검사를 모두 통과한 뒤에만 한다.
 *
 * ── 검증 항목 ───────────────────────────────────────────────────────────────
 *   1. 필수 필드 존재 / Type allowlist
 *   2. TopicArn 정확 일치 + ARN 리전이 허용 리전과 일치
 *   3. Timestamp 가 허용 폭 안 (replay 거부, 미래 시각도 거부)
 *   4. SigningCertURL 이 HTTPS + AWS SNS 호스트 + 허용 리전 + .pem
 *   5. SignatureVersion 1(SHA1) / 2(SHA256) RSA 서명 검증
 *
 * ── string-to-sign ──────────────────────────────────────────────────────────
 * AWS 문서가 정한 필드 순서대로 `키\n값\n` 을 이어 붙인다. 타입별 필드 집합이 다르며,
 * 존재하지 않는 선택 필드(Subject)는 통째로 건너뛴다.
 *
 * ── 네트워크 ────────────────────────────────────────────────────────────────
 * 인증서 로딩은 생성자로 주입되는 fetcher 에 위임한다. 테스트는 fixture 인증서를
 * 돌려주는 fetcher 를 넣어 네트워크 없이 검증 경로 전체를 실행한다.
 *
 * ── 문법 주의 ───────────────────────────────────────────────────────────────
 * 이 클래스는 **PHP 7.4 파서로도 읽히는 문법**만 쓴다 (프로퍼티 승격·match·readonly 미사용).
 * 프로덕션은 PHP 8.3 이지만, 로컬 검증 환경에 PHP 8 이 없어 `tests/Ses/*` 독립 하네스가
 * 이 파일을 그대로 require 해 서명 검증 경로를 실행 검증한다. 로직 복제를 피하기 위한
 * 의도적 제약이며, 이 파일과 SesEventParser 두 개에만 적용한다.
 */
class SnsMessageValidator
{
    /** 검증 실패 사유 — 로그·응답에 쓰는 안정적인 코드. */
    const REASON_MALFORMED = 'malformed';

    const REASON_TYPE_NOT_ALLOWED = 'type_not_allowed';

    const REASON_TOPIC_MISMATCH = 'topic_mismatch';

    const REASON_REGION_MISMATCH = 'region_mismatch';

    const REASON_TIMESTAMP_INVALID = 'timestamp_invalid';

    const REASON_TIMESTAMP_EXPIRED = 'timestamp_expired';

    const REASON_TIMESTAMP_FUTURE = 'timestamp_future';

    const REASON_CERT_URL_REJECTED = 'cert_url_rejected';

    const REASON_CERT_UNAVAILABLE = 'cert_unavailable';

    const REASON_SIGNATURE_VERSION = 'signature_version_unsupported';

    const REASON_SIGNATURE_INVALID = 'signature_invalid';

    /**
     * 서명 대상 필드 순서 (AWS 규정).
     *
     * @var array<string, array<int, string>>
     */
    const SIGNABLE_FIELDS = [
        'Notification' => ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'],
        'SubscriptionConfirmation' => ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'],
        'UnsubscribeConfirmation' => ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'],
    ];

    /** @var string */
    private $expectedTopicArn;

    /** @var string */
    private $expectedRegion;

    /** @var int 제어 메시지(구독 확인/해지)에만 적용하는 과거 최대 나이(초) */
    private $controlMaxAgeSeconds;

    /** @var int 모든 타입에 적용하는 미래 시각 허용 오차(초) */
    private $maxFutureSkewSeconds;

    /** @var array<int, string> */
    private $allowedTypes;

    /** @var callable */
    private $certificateFetcher;

    /**
     * @param  string  $expectedTopicArn  허용할 TopicArn (빈 문자열이면 모든 메시지를 거부)
     * @param  string  $expectedRegion  허용 리전
     * @param  int  $controlMaxAgeSeconds  제어 메시지의 과거 최대 나이(초)
     * @param  int  $maxFutureSkewSeconds  미래 시각 허용 오차(초, 전 타입 공통)
     * @param  array<int, string>  $allowedTypes  허용 SNS Type
     * @param  callable|null  $certificateFetcher  인증서 PEM 로더 (테스트 주입 지점)
     */
    public function __construct(
        string $expectedTopicArn,
        string $expectedRegion,
        int $controlMaxAgeSeconds,
        int $maxFutureSkewSeconds,
        array $allowedTypes,
        $certificateFetcher = null
    ) {
        $this->expectedTopicArn = $expectedTopicArn;
        $this->expectedRegion = $expectedRegion;
        $this->controlMaxAgeSeconds = $controlMaxAgeSeconds;
        $this->maxFutureSkewSeconds = $maxFutureSkewSeconds;
        $this->allowedTypes = $allowedTypes;
        $this->certificateFetcher = $certificateFetcher !== null
            ? $certificateFetcher
            : function ($url) {
                // 검증용 인증서라 실패는 조용히 null. 호출 측이 거부 사유로 변환한다.
                $context = stream_context_create([
                    'http' => ['timeout' => 5, 'ignore_errors' => true],
                    'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
                ]);

                $pem = @file_get_contents($url, false, $context);

                return is_string($pem) && $pem !== '' ? $pem : null;
            };
    }

    /**
     * 메시지를 검증한다.
     *
     * @param  array<string, mixed>  $message  파싱된 SNS 메시지 본문
     * @return array{valid: bool, reason: string|null}
     */
    public function validate(array $message): array
    {
        $type = isset($message['Type']) && is_string($message['Type']) ? $message['Type'] : '';

        if (! in_array($type, $this->allowedTypes, true) || ! isset(self::SIGNABLE_FIELDS[$type])) {
            return $this->fail(self::REASON_TYPE_NOT_ALLOWED);
        }

        foreach (['MessageId', 'TopicArn', 'Timestamp', 'Signature', 'SigningCertURL'] as $required) {
            if (! isset($message[$required]) || ! is_string($message[$required]) || $message[$required] === '') {
                return $this->fail(self::REASON_MALFORMED);
            }
        }

        // 확인/해지 메시지는 Token 과 SubscribeURL 이 서명 대상이라 반드시 있어야 한다.
        if ($type !== 'Notification') {
            foreach (['Token', 'SubscribeURL'] as $required) {
                if (! isset($message[$required]) || ! is_string($message[$required]) || $message[$required] === '') {
                    return $this->fail(self::REASON_MALFORMED);
                }
            }
        }

        $topicArn = (string) $message['TopicArn'];

        // TopicArn 미설정은 "아무거나 허용" 이 아니라 "전부 거부" 다.
        if ($this->expectedTopicArn === '' || ! hash_equals($this->expectedTopicArn, $topicArn)) {
            return $this->fail(self::REASON_TOPIC_MISMATCH);
        }

        if ($this->arnRegion($topicArn) !== $this->expectedRegion) {
            return $this->fail(self::REASON_REGION_MISMATCH);
        }

        $timestampReason = $this->checkTimestamp((string) $message['Timestamp'], $type);
        if ($timestampReason !== null) {
            return $this->fail($timestampReason);
        }

        $certUrl = (string) $message['SigningCertURL'];
        if (! $this->isAllowedCertificateUrl($certUrl)) {
            return $this->fail(self::REASON_CERT_URL_REJECTED);
        }

        $algorithm = $this->signatureAlgorithm(isset($message['SignatureVersion']) ? $message['SignatureVersion'] : null);
        if ($algorithm === null) {
            return $this->fail(self::REASON_SIGNATURE_VERSION);
        }

        $fetcher = $this->certificateFetcher;
        $pem = $fetcher($certUrl);
        if (! is_string($pem) || $pem === '') {
            return $this->fail(self::REASON_CERT_UNAVAILABLE);
        }

        $publicKey = @openssl_pkey_get_public($pem);
        if ($publicKey === false) {
            return $this->fail(self::REASON_CERT_UNAVAILABLE);
        }

        $signature = base64_decode((string) $message['Signature'], true);
        if ($signature === false) {
            return $this->fail(self::REASON_SIGNATURE_INVALID);
        }

        $verified = openssl_verify(
            $this->stringToSign($message, $type),
            $signature,
            $publicKey,
            $algorithm
        );

        return $verified === 1
            ? ['valid' => true, 'reason' => null]
            : $this->fail(self::REASON_SIGNATURE_INVALID);
    }

    /**
     * AWS 규정 순서로 서명 대상 문자열을 만든다.
     *
     * @param  array<string, mixed>  $message
     */
    public function stringToSign(array $message, string $type): string
    {
        $out = '';
        $fields = isset(self::SIGNABLE_FIELDS[$type]) ? self::SIGNABLE_FIELDS[$type] : [];

        foreach ($fields as $field) {
            // Subject 는 선택 필드 — 없으면 건너뛴다 (빈 값으로 넣으면 서명이 어긋난다).
            if (! array_key_exists($field, $message) || ! is_string($message[$field])) {
                continue;
            }

            $out .= $field."\n".$message[$field]."\n";
        }

        return $out;
    }

    /**
     * SigningCertURL 허용 여부.
     *
     * HTTPS 이고, 호스트가 `sns.{region}.amazonaws.com`(중국 리전은 `.com.cn`)이며,
     * 리전이 허용 리전과 같고, 경로가 `.pem` 으로 끝나야 한다.
     * 사용자 정보(user:pass@)나 명시 포트가 붙은 URL 은 거부한다.
     */
    public function isAllowedCertificateUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return false;
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'] : '';
        if ($scheme !== 'https') {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }

        $host = strtolower(isset($parts['host']) ? (string) $parts['host'] : '');
        if ($host === '') {
            return false;
        }

        if (preg_match('/^sns\.([a-z0-9-]+)\.amazonaws\.com(\.cn)?$/', $host, $m) !== 1) {
            return false;
        }

        if ($m[1] !== $this->expectedRegion) {
            return false;
        }

        $path = strtolower(isset($parts['path']) ? (string) $parts['path'] : '');

        return substr($path, -4) === '.pem';
    }

    /**
     * SignatureVersion → openssl 알고리즘 상수.
     *
     * @param  mixed  $version
     * @return int|null 지원하지 않으면 null
     */
    public function signatureAlgorithm($version): ?int
    {
        $value = (string) $version;

        if ($value === '1') {
            return OPENSSL_ALGO_SHA1;
        }

        if ($value === '2') {
            return OPENSSL_ALGO_SHA256;
        }

        return null;
    }

    /**
     * Timestamp 검사. 통과하면 null, 실패하면 사유 코드.
     */
    private function checkTimestamp(string $timestamp, string $type): ?string
    {
        $parsed = strtotime($timestamp);
        if ($parsed === false) {
            return self::REASON_TIMESTAMP_INVALID;
        }

        $age = time() - $parsed;

        // 미래 시각은 타입과 무관하게 거부한다. 정상 SNS 는 미래에서 오지 않으며,
        // 허용하면 "만료되지 않는 메시지" 를 만들 수 있다. 시계 오차만 인정한다.
        if ($age < -1 * $this->maxFutureSkewSeconds) {
            return self::REASON_TIMESTAMP_FUTURE;
        }

        // 과거 나이 제한은 **제어 메시지에만** 적용한다.
        //
        // Notification 에 과거 상한을 걸면, 서버 장애·배포로 못 받은 정상 SES 이벤트가
        // SNS 재시도로 뒤늦게 도착했을 때 403 이 되어 **영구 유실**된다. replay 방어는
        // 이미 ses_event_logs.sns_message_id unique 가 담당하므로, 시각으로 또 막을
        // 이유가 없다. (구독 확인/해지는 다르다 — 오래된 Token 으로 구독 상태를 바꾸는
        //  것은 실제 위험이라 상한을 유지한다.)
        if ($this->isControlType($type) && $age > $this->controlMaxAgeSeconds) {
            return self::REASON_TIMESTAMP_EXPIRED;
        }

        return null;
    }

    /**
     * 제어 메시지(구독 상태를 바꾸는 메시지)인가.
     */
    public function isControlType(string $type): bool
    {
        return $type === 'SubscriptionConfirmation' || $type === 'UnsubscribeConfirmation';
    }

    /**
     * `arn:aws:sns:ap-northeast-2:123456789012:topic` → `ap-northeast-2`.
     */
    private function arnRegion(string $arn): ?string
    {
        $parts = explode(':', $arn);

        return isset($parts[3]) && $parts[3] !== '' ? $parts[3] : null;
    }

    /**
     * @return array{valid: bool, reason: string}
     */
    private function fail(string $reason): array
    {
        return ['valid' => false, 'reason' => $reason];
    }
}
