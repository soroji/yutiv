<?php

namespace Modules\Yutiv\PersonalStore\Services;

/** 정수 P·basis point(0~10000)만 사용합니다. 중간 곱은 BCMath로 오버플로를 방지합니다. */
final class RewardCalculator
{
    public const VERSION = '1';

    public function floorRatio(int $amount, int $numerator, int $denominator): int
    {
        if ($amount < 0 || $numerator < 0 || $denominator <= 0 || $numerator > $denominator) {
            throw new \InvalidArgumentException(__('yutiv-personal_store::messages.invalid_ratio'));
        }

        return (int) bcdiv(bcmul((string) $amount, (string) $numerator, 0), (string) $denominator, 0);
    }

    /** 경로는 최상위부터 판매 스토어까지. pass_bps는 해당 노드가 자식에게 전달하는 비율입니다. */
    public function allocate(int $base, int $rateBps, array $path): array
    {
        if ($path === []) {
            throw new \InvalidArgumentException(__('yutiv-personal_store::messages.invalid_path'));
        }
        $pool = $this->floorRatio($base, $rateBps, 10000);
        $incoming = $pool;
        $result = [];
        $seen = [];
        foreach (array_values($path) as $index => $node) {
            $id = (int) $node['store_id'];
            if (isset($seen[$id])) {
                throw new \InvalidArgumentException(__('yutiv-personal_store::messages.invalid_path'));
            }
            $seen[$id] = true;
            $outgoing = $index === count($path) - 1 ? 0 : $this->floorRatio($incoming, (int) $node['pass_bps'], 10000);
            $result[] = ['store_id' => $id, 'incoming' => $incoming, 'outgoing' => $outgoing, 'amount' => $incoming - $outgoing];
            $incoming = $outgoing;
        }

        return ['version' => self::VERSION, 'pool' => $pool, 'allocations' => $result];
    }

    /** 누적 목표를 계산하므로 여러 번의 부분 회수도 최초 지급액을 넘지 않습니다. */
    public function recoveryTargets(array $allocations, int $cancelledBase, int $originalBase): array
    {
        $result = [];
        foreach ($allocations as $allocation) {
            $result[$allocation['store_id']] = $originalBase === 0 ? 0 : $this->floorRatio((int) $allocation['amount'], $cancelledBase, $originalBase);
        }

        return $result;
    }
}
