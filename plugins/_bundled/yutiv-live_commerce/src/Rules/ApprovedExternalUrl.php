<?php

namespace Plugins\Yutiv\LiveCommerce\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ApprovedExternalUrl implements ValidationRule
{
    public function __construct(private readonly array $hosts) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $parts = parse_url((string) $value);
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || ! in_array(strtolower($parts['host'] ?? ''), $this->hosts, true)) {
            $fail('승인된 HTTPS 주소만 사용할 수 있습니다.');
        }
    }
}
