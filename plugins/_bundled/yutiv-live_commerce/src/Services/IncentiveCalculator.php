<?php

namespace Plugins\Yutiv\LiveCommerce\Services;

final class IncentiveCalculator
{
    /** UI 퍼센트를 정수 10000분율로 변환합니다. */
    public static function rate(string $percent): int
    {
        [$whole, $fraction] = array_pad(explode('.', $percent, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    public static function amounts(int $basis, int $channel, int $host, int $channelId, array $shares): array
    {
        abort_if($basis < 0 || $channel < 0 || $host < 0 || $channel + $host > 10000, 422);
        foreach ($shares as $id => $share) {
            abort_unless((int) $id > 0 && is_int($share) && $share >= 0 && $share <= 10000, 422, __('yutiv-live_commerce::live.errors.e_90cacd0ef3'));
        }
        $result = ['channel:'.$channelId => intdiv($basis * $channel, 10000)];
        $pool = intdiv($basis * $host, 10000);
        $sum = array_sum($shares);
        abort_if($host > 0 && $sum !== 10000, 422, __('yutiv-live_commerce::live.errors.e_ca1e32c347'));
        $remainders = [];
        foreach ($shares as $id => $share) {
            $result['host:'.$id] = intdiv($pool * $share, 10000);
            $remainders[$id] = ($pool * $share) % 10000;
        }
        $left = $pool - array_sum(array_filter($result, fn ($key) => str_starts_with($key, 'host:'), ARRAY_FILTER_USE_KEY));
        uksort($remainders, fn ($a, $b) => ($remainders[$b] <=> $remainders[$a]) ?: ((int) $a <=> (int) $b));
        foreach (array_keys($remainders) as $id) {
            if ($left-- <= 0) {
                break;
            }
            $result['host:'.$id]++;
        }

        return $result;
    }
}
