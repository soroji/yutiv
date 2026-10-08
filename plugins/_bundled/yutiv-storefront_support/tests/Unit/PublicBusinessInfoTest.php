<?php

namespace Plugins\Yutiv\StorefrontSupport\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\Yutiv\StorefrontSupport\Support\PublicBusinessInfo;

require_once __DIR__.'/../../src/Support/PublicBusinessInfo.php';

/** This test boots neither Laravel nor a database connection. */
final class PublicBusinessInfoTest extends TestCase
{
    public function test_allowlist_and_empty_values(): void
    {
        $result = PublicBusinessInfo::project(['company' => '  Public name  ', 'email' => '', 'address' => [], 'admin_id' => 7, 'api_secret' => 'secret']);
        self::assertSame(PublicBusinessInfo::FIELDS, array_keys($result));
        self::assertSame('Public name', $result['company']);
        self::assertNull($result['email']);
        self::assertNull($result['address']);
        self::assertArrayNotHasKey('api_secret', $result);
    }

    public function test_only_https_without_credentials_is_a_verification_link(): void
    {
        foreach (['javascript:alert(1)', '//example.test', 'http://example.test', 'https://user:pass@example.test', ''] as $url) {
            self::assertNull(PublicBusinessInfo::project(['verification_url' => $url])['verification_url']);
        }
        self::assertSame('https://example.test/check', PublicBusinessInfo::project(['verification_url' => 'https://example.test/check'])['verification_url']);
    }
}
