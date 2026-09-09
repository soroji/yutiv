<?php

namespace Plugins\Yutiv\SesMonitor\Tests\Support;

/**
 * 테스트용 SNS 메시지 fixture 생성기.
 *
 * **실제 AWS 계정도 네트워크도 쓰지 않는다.** 테스트 시작 시 RSA 키쌍과 자체 서명
 * 인증서를 만들어, AWS 가 하는 것과 같은 방식으로 string-to-sign 에 서명한다.
 * 검증기에는 이 인증서를 돌려주는 fetcher 를 주입한다.
 *
 * 이렇게 하면 "서명이 맞으면 통과, 한 글자라도 바뀌면 거부" 를 실제 openssl 연산으로
 * 확인할 수 있다 — 서명 검증을 모킹으로 우회하면 검사 자체가 무의미해진다.
 *
 * PHP 7.4 파서로도 읽히는 문법만 쓴다 (tests/Ses 독립 하네스가 같은 파일을 쓴다).
 */
class SnsFixtureFactory
{
    /** @var resource|\OpenSSLAsymmetricKey */
    private $privateKey;

    /** @var string PEM 인코딩된 인증서 */
    private $certificatePem;

    /** @var string */
    private $topicArn;

    /** @var string */
    private $certUrl;

    public function __construct(
        string $topicArn = 'arn:aws:sns:ap-northeast-2:123456789012:yutiv-ses-events',
        string $certUrl = 'https://sns.ap-northeast-2.amazonaws.com/SimpleNotificationService-abc123.pem'
    ) {
        $this->topicArn = $topicArn;
        $this->certUrl = $certUrl;

        $config = [
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        // 리눅스 서버에서는 openssl.cnf 가 기본 경로에 있어 이 항목이 필요 없다.
        // Windows 개발기처럼 기본 경로가 비어 있는 환경에서만 명시한다.
        $opensslConf = self::resolveOpensslConfig();
        if ($opensslConf !== null) {
            $config['config'] = $opensslConf;
        }

        $key = openssl_pkey_new($config);
        if ($key === false) {
            throw new \RuntimeException('테스트용 RSA 키를 만들지 못했습니다: '.openssl_error_string());
        }
        $this->privateKey = $key;

        $dn = ['countryName' => 'KR', 'organizationName' => 'YUTIV Test', 'commonName' => 'sns.amazonaws.com'];
        $csr = openssl_csr_new($dn, $key, $config);
        $cert = openssl_csr_sign($csr, null, $key, 365, $config);

        $pem = '';
        openssl_x509_export($cert, $pem);
        $this->certificatePem = $pem;
    }

    /**
     * openssl.cnf 경로를 찾는다. 찾지 못하면 null (기본 경로에 맡긴다).
     */
    private static function resolveOpensslConfig(): ?string
    {
        $candidates = [];

        $fromEnv = getenv('OPENSSL_CONF');
        if (is_string($fromEnv) && $fromEnv !== '') {
            $candidates[] = $fromEnv;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            $phpDir = dirname(PHP_BINARY);
            $candidates[] = $phpDir.'/extras/ssl/openssl.cnf';
            $candidates[] = $phpDir.'/extras/openssl/openssl.cnf';
            $candidates[] = 'C:/Program Files/Git/mingw64/etc/ssl/openssl.cnf';
        }

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    public function topicArn(): string
    {
        return $this->topicArn;
    }

    public function certUrl(): string
    {
        return $this->certUrl;
    }

    public function certificatePem(): string
    {
        return $this->certificatePem;
    }

    /**
     * 검증기에 주입할 인증서 fetcher.
     *
     * 등록된 URL 에만 인증서를 돌려준다 — 다른 URL 로는 네트워크를 타지 않고 null.
     */
    public function certificateFetcher(): callable
    {
        $url = $this->certUrl;
        $pem = $this->certificatePem;

        return function ($requested) use ($url, $pem) {
            return $requested === $url ? $pem : null;
        };
    }

    /**
     * 서명된 Notification 메시지를 만든다.
     *
     * @param  array<string, mixed>  $sesEvent  SNS Message 에 담을 SES 이벤트
     * @param  array<string, mixed>  $overrides  서명 **후** 덮어쓸 필드 (변조 시뮬레이션용)
     * @return array<string, mixed>
     */
    public function notification(array $sesEvent, array $overrides = [], string $signatureVersion = '1'): array
    {
        $message = [
            'Type' => 'Notification',
            'MessageId' => 'msg-'.bin2hex(random_bytes(8)),
            'TopicArn' => $this->topicArn,
            'Message' => json_encode($sesEvent),
            'Timestamp' => gmdate('Y-m-d\TH:i:s.000\Z'),
            'SignatureVersion' => $signatureVersion,
            'SigningCertURL' => $this->certUrl,
        ];

        return $this->sign($message, $overrides, $signatureVersion);
    }

    /**
     * 지정한 Timestamp 로 **서명까지 마친** Notification.
     *
     * `notification()` 의 overrides 는 서명 **후** 적용되므로 시각을 바꾸면 서명이 깨진다
     * (변조 시뮬레이션용으로는 그게 맞다). "오래됐지만 서명은 유효한" 메시지가 필요할 때는
     * 이 메서드를 쓴다.
     *
     * @param  array<string, mixed>  $sesEvent
     * @return array<string, mixed>
     */
    public function notificationAt(array $sesEvent, int $unixTime): array
    {
        $message = [
            'Type' => 'Notification',
            'MessageId' => 'msg-'.bin2hex(random_bytes(8)),
            'TopicArn' => $this->topicArn,
            'Message' => json_encode($sesEvent),
            'Timestamp' => gmdate('Y-m-d\TH:i:s.000\Z', $unixTime),
            'SignatureVersion' => '1',
            'SigningCertURL' => $this->certUrl,
        ];

        return $this->sign($message, [], '1');
    }

    /**
     * 서명된 SubscriptionConfirmation 메시지.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function subscriptionConfirmation(array $overrides = [], string $signatureVersion = '1'): array
    {
        $message = [
            'Type' => 'SubscriptionConfirmation',
            'MessageId' => 'msg-'.bin2hex(random_bytes(8)),
            'Token' => bin2hex(random_bytes(16)),
            'TopicArn' => $this->topicArn,
            'Message' => 'You have chosen to subscribe to the topic '.$this->topicArn,
            'SubscribeURL' => 'https://sns.ap-northeast-2.amazonaws.com/?Action=ConfirmSubscription&Token=abc',
            'Timestamp' => gmdate('Y-m-d\TH:i:s.000\Z'),
            'SignatureVersion' => $signatureVersion,
            'SigningCertURL' => $this->certUrl,
        ];

        return $this->sign($message, $overrides, $signatureVersion);
    }

    /**
     * 지정한 Timestamp 로 **서명까지 마친** SubscriptionConfirmation.
     *
     * 제어 메시지의 과거 상한을 시험하려면 "오래됐지만 서명은 유효한" 메시지가 필요하다.
     *
     * @return array<string, mixed>
     */
    public function subscriptionConfirmationAt(int $unixTime): array
    {
        return $this->subscriptionConfirmation([
            '__presign_timestamp' => gmdate('Y-m-d\TH:i:s.000\Z', $unixTime),
        ]);
    }

    /**
     * 지정한 Timestamp 로 **서명까지 마친** UnsubscribeConfirmation.
     *
     * @return array<string, mixed>
     */
    public function unsubscribeConfirmationAt(int $unixTime): array
    {
        return $this->unsubscribeConfirmation([
            '__presign_timestamp' => gmdate('Y-m-d\TH:i:s.000\Z', $unixTime),
        ]);
    }

    /**
     * 서명된 UnsubscribeConfirmation 메시지.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function unsubscribeConfirmation(array $overrides = []): array
    {
        $message = [
            'Type' => 'UnsubscribeConfirmation',
            'MessageId' => 'msg-'.bin2hex(random_bytes(8)),
            'Token' => bin2hex(random_bytes(16)),
            'TopicArn' => $this->topicArn,
            'Message' => 'You have chosen to deactivate subscription',
            'SubscribeURL' => 'https://sns.ap-northeast-2.amazonaws.com/?Action=ConfirmSubscription&Token=xyz',
            'Timestamp' => gmdate('Y-m-d\TH:i:s.000\Z'),
            'SignatureVersion' => '1',
            'SigningCertURL' => $this->certUrl,
        ];

        return $this->sign($message, $overrides, '1');
    }

    /**
     * AWS 규정 순서로 서명하고, 그 뒤 overrides 를 덮어쓴다.
     *
     * overrides 를 **서명 후에** 적용하는 것이 핵심이다 — 이렇게 해야 "서명은 유효한데
     * 본문이 변조된" 메시지를 만들 수 있고, 검증기가 그걸 잡는지 확인할 수 있다.
     *
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function sign(array $message, array $overrides, string $signatureVersion): array
    {
        // `__presign_timestamp` 는 서명 **전에** 적용되는 특수 키다. 서명 후 시각을 바꾸면
        // 서명이 깨져 timestamp 정책이 아니라 서명 검증을 시험하게 된다.
        if (isset($overrides['__presign_timestamp'])) {
            $message['Timestamp'] = $overrides['__presign_timestamp'];
            unset($overrides['__presign_timestamp']);
        }

        $order = $message['Type'] === 'Notification'
            ? ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type']
            : ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];

        $stringToSign = '';
        foreach ($order as $field) {
            if (! array_key_exists($field, $message) || ! is_string($message[$field])) {
                continue;
            }
            $stringToSign .= $field."\n".$message[$field]."\n";
        }

        $algorithm = $signatureVersion === '2' ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1;

        $signature = '';
        openssl_sign($stringToSign, $signature, $this->privateKey, $algorithm);

        $message['Signature'] = base64_encode($signature);

        foreach ($overrides as $key => $value) {
            if ($value === null) {
                unset($message[$key]);

                continue;
            }
            $message[$key] = $value;
        }

        return $message;
    }

    /**
     * SES bounce 이벤트 fixture.
     *
     * @return array<string, mixed>
     */
    public static function bounceEvent(string $sesMessageId = '0100018abc-bounce', ?string $occurredAt = null): array
    {
        // 기본값은 고정 날짜(다른 테스트가 정확한 보존 값을 단언한다).
        // 지연 도착처럼 **현재 시각과의 거리**가 중요한 테스트만 값을 넘긴다.
        $mailAt = $occurredAt ?? '2026-09-09T01:00:00.000Z';
        $bounceAt = $occurredAt ?? '2026-09-09T01:00:05.000Z';

        return [
            'eventType' => 'Bounce',
            'mail' => [
                'timestamp' => $mailAt,
                'messageId' => $sesMessageId,
                'source' => 'no-reply@yutiv.com',
                'destination' => ['bounced@example.com'],
            ],
            'bounce' => [
                'bounceType' => 'Permanent',
                'bounceSubType' => 'General',
                'timestamp' => $bounceAt,
                'bouncedRecipients' => [
                    [
                        'emailAddress' => 'bounced@example.com',
                        'diagnosticCode' => 'smtp; 550 5.1.1 user unknown',
                    ],
                ],
            ],
        ];
    }

    /**
     * SES complaint 이벤트 fixture.
     *
     * @return array<string, mixed>
     */
    public static function complaintEvent(string $sesMessageId = '0100018abc-complaint'): array
    {
        return [
            'eventType' => 'Complaint',
            'mail' => [
                'timestamp' => '2026-09-09T02:00:00.000Z',
                'messageId' => $sesMessageId,
                'source' => 'no-reply@yutiv.com',
                'destination' => ['angry@example.com'],
            ],
            'complaint' => [
                'complaintFeedbackType' => 'abuse',
                'timestamp' => '2026-09-09T02:00:10.000Z',
                'complainedRecipients' => [
                    ['emailAddress' => 'angry@example.com'],
                ],
            ],
        ];
    }

    /**
     * SES delivery 이벤트 fixture.
     *
     * @return array<string, mixed>
     */
    public static function deliveryEvent(string $sesMessageId = '0100018abc-delivery'): array
    {
        return [
            'eventType' => 'Delivery',
            'mail' => [
                'timestamp' => '2026-09-09T03:00:00.000Z',
                'messageId' => $sesMessageId,
                'source' => 'no-reply@yutiv.com',
                'destination' => ['ok@example.com'],
            ],
            'delivery' => [
                'timestamp' => '2026-09-09T03:00:02.000Z',
                'recipients' => ['ok@example.com'],
                'processingTimeMillis' => 1200,
                'smtpResponse' => '250 2.0.0 OK',
            ],
        ];
    }

    /**
     * 우리가 저장하지 않는 이벤트 (Open) fixture.
     *
     * @return array<string, mixed>
     */
    public static function openEvent(): array
    {
        return [
            'eventType' => 'Open',
            'mail' => [
                'timestamp' => '2026-09-09T04:00:00.000Z',
                'messageId' => '0100018abc-open',
                'source' => 'no-reply@yutiv.com',
                'destination' => ['reader@example.com'],
            ],
            'open' => ['timestamp' => '2026-09-09T04:00:30.000Z'],
        ];
    }
}
