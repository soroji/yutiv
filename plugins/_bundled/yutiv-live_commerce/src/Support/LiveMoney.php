<?php

namespace Plugins\Yutiv\LiveCommerce\Support;

use Plugins\Yutiv\LiveCommerce\Enums\LiveCurrency;

/** 원통화 2자리, 환율 6자리. 곱셈은 십진 문자열, KRW는 거래 단위 half-up 정수. */
final class LiveMoney
{
    public static function minor(string $value, int $scale = 2): int
    {
        if (! preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]{1,'.$scale.'}))?$/D', $value, $m)) {
            throw new \DomainException(__('yutiv-live_commerce::live.money_invalid'));
        }
        $digits = ltrim($m[1].str_pad($m[2] ?? '', $scale, '0'), '0');
        if (strlen($digits) > 14) {
            throw new \DomainException(__('yutiv-live_commerce::live.money_limit'));
        }

return (int) ($digits === '' ? '0' : $digits);
    }

    public static function decimal(int $minor, int $scale = 2): string
    {
        if ($minor < 0) {
            throw new \DomainException(__('yutiv-live_commerce::live.money_invalid'));
        }$digits = str_pad((string) $minor, $scale + 1, '0', STR_PAD_LEFT);

        return substr($digits, 0, -$scale).'.'.substr($digits, -$scale);
    }

    private static function product(int $a, int $b): string
    {
        $left = strrev((string) $a);
        $right = strrev((string) $b);
        $digits = array_fill(0, strlen($left) + strlen($right) + 1, 0);
        for ($i = 0; $i < strlen($left); $i++) {
            for ($j = 0; $j < strlen($right); $j++) {
                $digits[$i + $j] += (int) $left[$i] * (int) $right[$j];
            }
        }for ($i = 0; $i < count($digits) - 1; $i++) {
            $digits[$i + 1] += intdiv($digits[$i], 10);
            $digits[$i] %= 10;
        }

return ltrim(implode('', array_reverse($digits)), '0') ?: '0';
    }

    public static function prorate(int $total, int $part, int $whole): int
    {
        if ($total < 0 || $part < 0 || $whole <= 0 || $part > $whole || $whole > 99999999999999) {
            throw new \DomainException(__('yutiv-live_commerce::live.money_invalid'));
        }
        $quotient = '';
        $remainder = 0;
        foreach (str_split(self::product($total, $part)) as $digit) {
            $remainder = $remainder * 10 + (int) $digit;
            $quotient .= (string) intdiv($remainder, $whole);
            $remainder %= $whole;
        }$q = ltrim($quotient, '0') ?: '0';
        if (strlen($q) > 14) {
            throw new \DomainException(__('yutiv-live_commerce::live.money_limit'));
        }

return (int) $q + ($remainder * 2 >= $whole ? 1 : 0);
    }

    public static function convert(string $amount, LiveCurrency $currency, string $rate): array
    {
        $minor = self::minor($amount);
        $scaledRate = self::minor($rate, 6);
        if ($scaledRate <= 0 || $scaledRate > 10000000000 || ($currency === LiveCurrency::KRW && ($minor % 100 !== 0 || $scaledRate !== 1000000))) {
            throw new \DomainException(__('yutiv-live_commerce::live.money_invalid'));
        }
        $digits = str_pad(self::product($minor, $scaledRate), 9, '0', STR_PAD_LEFT);
        $whole = substr($digits, 0, -8);
        if (strlen($whole) > 13) {
            throw new \DomainException(__('yutiv-live_commerce::live.money_limit'));
        }$krw = (int) $whole + ((int) $digits[strlen($digits) - 8] >= 5 ? 1 : 0);
        if ($krw > 1000000000000) {
            throw new \DomainException(__('yutiv-live_commerce::live.money_limit'));
        }

        return ['currency' => $currency->value, 'amount_original' => self::decimal($minor), 'amount_minor' => $minor, 'exchange_rate' => self::decimal($scaledRate, 6), 'amount_krw' => $krw];
    }

    /** 누적 비례 배분을 차분하여 마지막 지급까지 합계가 원금과 일치하게 한다. */
    public static function allocation(int $totalKrw, int $originalMinor, int $alreadyPaidMinor, int $paymentMinor): int
    {
        if ($paymentMinor <= 0 || $alreadyPaidMinor < 0 || $alreadyPaidMinor + $paymentMinor > $originalMinor) {
            throw new \DomainException(__('yutiv-live_commerce::live.money_invalid'));
        }

        return self::prorate($totalKrw,$alreadyPaidMinor + $paymentMinor,$originalMinor) - self::prorate($totalKrw,$alreadyPaidMinor,$originalMinor);
    }
}
