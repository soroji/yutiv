<?php

namespace Plugins\Yutiv\SesMonitor\Tests;

use Plugins\Yutiv\SesMonitor\Tests\Support\SnsFixtureFactory;

/**
 * 테스트 전체가 공유하는 fixture 팩토리 홀더.
 *
 * RSA 2048 키 생성은 비싸므로 프로세스당 한 번만 만들고 재사용한다.
 * (키가 매 테스트마다 달라져야 할 이유가 없다 — 검증하는 것은 "이 키로 서명한 것만
 *  통과한다" 는 성질이고, 그건 키가 고정이어도 동일하게 확인된다.)
 */
class SnsFixtureBag
{
    public const TOPIC_ARN = 'arn:aws:sns:ap-northeast-2:123456789012:yutiv-ses-events';

    public const CERT_URL = 'https://sns.ap-northeast-2.amazonaws.com/SimpleNotificationService-test.pem';

    private static ?SnsFixtureFactory $shared = null;

    public function factory(): SnsFixtureFactory
    {
        if (self::$shared === null) {
            self::$shared = new SnsFixtureFactory(self::TOPIC_ARN, self::CERT_URL);
        }

        return self::$shared;
    }

    /**
     * 서명된 Notification 을 JSON 문자열로.
     *
     * @param  array<string, mixed>  $sesEvent
     * @param  array<string, mixed>  $overrides
     */
    public function notificationJson(array $sesEvent, array $overrides = [], string $signatureVersion = '1'): string
    {
        return (string) json_encode($this->factory()->notification($sesEvent, $overrides, $signatureVersion));
    }
}
