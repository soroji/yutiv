<?php

namespace Plugins\Yutiv\LiveCommerce\Services;

use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;

/** 화면용 통화별 비용 분해. 기존 손익·환산·저장 보고서를 변경하지 않습니다. */
final class LiveFinanceDashboardService extends LiveWorkflow
{
    public function forReport(int $tenant, array $report): array
    {
        $bucket = match ($report['period']) {
            'month' => "DATE_FORMAT(occurred_on,'%Y-%m')",
            'week' => 'DATE_SUB(occurred_on,INTERVAL WEEKDAY(occurred_on) DAY)',
            default => 'occurred_on',
        };
        $sign = "CASE WHEN source_kind='reversal' THEN -1.0 ELSE 1.0 END";
        // 기존 보고서의 fixed/variable과 동일한 발생일·처리·역분개 기준.
        // 원금과 저장 amount_krw를 각각 합산하며 환율을 새로 곱하지 않습니다.
        $rows = $this->records->query(E::Expense, $tenant)
            ->whereBetween('occurred_on', [$report['from'], $report['to']])
            ->whereIn('treatment', ['operating', 'fee'])->whereIn('cost_group', ['fixed', 'variable'])
            ->select('currency')->selectRaw($bucket.' bucket')
            ->selectRaw("COALESCE(SUM(amount_original*100*($sign)),0) original_minor,COALESCE(SUM(amount_krw*($sign)),0) known_krw,SUM(CASE WHEN amount_krw IS NULL AND status!='void' AND source_kind!='reversal' THEN 1 ELSE 0 END) unknown_rows")
            ->groupBy('bucket', 'currency')->get();
        $empty = ['original_minor' => 0, 'known_krw' => 0, 'unknown_rows' => 0];
        $totals = ['KRW' => $empty, 'CNY' => $empty];
        $periods = [];
        foreach ($report['periods'] as $key => $values) {
            $periods[$key] = ['KRW' => $empty, 'CNY' => $empty];
        }
        foreach ($rows as $row) {
            foreach (array_keys($empty) as $field) {
                $periods[$row->bucket][$row->currency][$field] = (int) $row->$field;
                $totals[$row->currency][$field] += (int) $row->$field;
            }
        }

        return ['totals' => $totals, 'periods' => $periods,
            'reconciled' => array_sum(array_column($totals, 'known_krw')) === $report['totals']['fixed'] + $report['totals']['variable']];
    }
}
