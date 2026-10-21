<?php

namespace Plugins\Yutiv\LiveCommerce\Support;

/** 저장된 문자열에 자릿수 구분만 더합니다. 환산·반올림·집계는 하지 않습니다. */
final class LiveDisplay
{
    public static function number(string|int|null $value): string
    {
        if ($value === null) {
            return '미확정';
        }
        [$whole,$fraction] = array_pad(explode('.', (string) $value, 2), 2, null);
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole);

        return $whole.($fraction === null ? '' : '.'.$fraction);
    }
}
