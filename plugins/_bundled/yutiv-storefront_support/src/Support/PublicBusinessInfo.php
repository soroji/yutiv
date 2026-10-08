<?php

namespace Plugins\Yutiv\StorefrontSupport\Support;

/** Pure projection: caller cannot accidentally expose a full settings bag. */
final class PublicBusinessInfo
{
    public const FIELDS = [
        'company', 'representative', 'business_number', 'mail_order_number',
        'address', 'phone', 'email', 'hosting', 'verification_url',
    ];

    public static function project(array $values): array
    {
        $result = [];
        foreach (self::FIELDS as $field) {
            $value = $values[$field] ?? null;
            $result[$field] = is_string($value) && trim($value) !== '' ? trim($value) : null;
        }
        $url = $result['verification_url'];
        if ($url !== null && (
            filter_var($url, FILTER_VALIDATE_URL) === false
            || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https'
            || parse_url($url, PHP_URL_USER) !== null
            || parse_url($url, PHP_URL_PASS) !== null
        )) {
            $result['verification_url'] = null;
        }

        return $result;
    }
}
