<?php

// Standalone, DB-free verification; no Composer, Laravel, .env or repository bootstrap.
require_once __DIR__.'/../src/Support/PublicBusinessInfo.php';

use Plugins\Yutiv\StorefrontSupport\Support\PublicBusinessInfo;

$checks = 0;
$verify = function ($condition, $message) use (&$checks) {
    ++$checks;
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

$data = PublicBusinessInfo::project(['company' => ' Public ', 'phone' => '', 'address' => [], 'api_secret' => 'secret', 'admin_id' => 7]);
$verify(array_keys($data) === PublicBusinessInfo::FIELDS, 'Nine exact public fields');
$verify($data['company'] === 'Public', 'Trim public value');
$verify($data['phone'] === null && $data['address'] === null, 'Empty or non-string values are null');
$verify(! isset($data['api_secret']) && ! isset($data['admin_id']), 'Sensitive fields absent');
foreach (['javascript:alert(1)', '//example.test', 'http://example.test', 'https://u:p@example.test', ''] as $url) {
    $verify(PublicBusinessInfo::project(['verification_url' => $url])['verification_url'] === null, 'Unsafe URL omitted');
}
$verify(PublicBusinessInfo::project(['verification_url' => 'https://example.test/check'])['verification_url'] === 'https://example.test/check', 'Valid public verification link');
echo "PASS: {$checks} DB-free projection assertions\n";
