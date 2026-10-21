<?php

namespace Plugins\Yutiv\LiveCommerce\Services;

use Carbon\CarbonImmutable as Date;
use Illuminate\Support\Str;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Enums\ReportKind;
use Plugins\Yutiv\LiveCommerce\Enums\ReportRowKind;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;
use Plugins\Yutiv\LiveCommerce\Models\TeeWideUser;

class LiveFinanceReportService extends LiveWorkflow
{
    public const BASIS = 'management-shipment-fifo-opening-2';

    private function db()
    {
        return $this->records->query(E::Expense, 0)->getModel()->getConnection();
    }

    private function digest(array $data): string
    {
        $sort = function ($v) use (&$sort) {
            if (! is_array($v)) {
                return $v;
            }if (! array_is_list($v)) {
                ksort($v);
            }

            return array_map($sort, $v);
        };

        return hash('sha256', json_encode($sort($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function maskedBank(?array $snapshot): ?array
    {
        return $snapshot ? ['bank' => $snapshot['bank'], 'holder' => $snapshot['holder'], 'last4' => substr($snapshot['number'], -4), 'account_id' => $snapshot['account_id']] : null;
    }

    public function snapshot(callable $callback): mixed
    {
        if ($this->db()->transactionLevel() === 0) {
            $this->db()->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        return $this->records->transaction($callback);
    }

    private function buckets(LiveTenant $company, string $from, string $to, string $period): array
    {
        $start = Date::parse($from, $company->timezone)->startOfDay();
        $end = Date::parse($to, $company->timezone)->startOfDay()->addDay();
        abort_if($start >= $end || $start->diffInDays($end) > 366, 422);
        $buckets = [];
        for ($date = $start; $date < $end;) {
            $key = match ($period) {
                'week' => $date->startOfWeek(1)->format('Y-m-d'),'month' => $date->format('Y-m'),default => $date->format('Y-m-d')
            };
            $next = match ($period) {
                'week' => $date->startOfWeek(1)->addWeek(),'month' => $date->startOfMonth()->addMonth(),default => $date->addDay()
            };
            $next = $next->min($end);
            $buckets[] = ['utc_start' => $date->utc()->toDateTimeString(), 'utc_end' => $next->utc()->toDateTimeString(), 'date_start' => $date->format('Y-m-d'), 'date_end' => $next->format('Y-m-d'), 'key' => $key];
            $date = $next;
        }

        return [$start, $end, $buckets];
    }

    private function periodAggregate($q, string $field, array $fields, array $buckets, bool $datetime = true): array
    {
        $case = 'CASE';
        $bindings = [];
        foreach ($buckets as $bucket) {
            $case .= " WHEN $field>=? AND $field<? THEN ?";
            array_push($bindings, $bucket[$datetime ? 'utc_start' : 'date_start'], $bucket[$datetime ? 'utc_end' : 'date_end'], $bucket['key']);
        }$case .= ' END';
        $sums = implode(',', array_map(fn ($name, $expr) => "COALESCE(SUM($expr),0) `$name`", array_keys($fields), $fields));

        return $q->selectRaw($case.' bucket', $bindings)->selectRaw($sums)->groupBy('bucket')->get()->mapWithKeys(fn ($r) => [$r->bucket => array_map(fn ($f) => (int) $r->$f, array_combine(array_keys($fields), array_keys($fields)))])->all();
    }

    public function report(int $tenant, int $actor, string $from, string $to, string $period = 'day'): array
    {
        return $this->snapshot(fn () => $this->calculate($tenant, $actor, $from, $to, $period));
    }

    /** Report-key advisory mutex serializes only duplicate generators, never orders or inventory. */
    public function saveOnce(int $tenant, int $actor, array $data): string
    {
        $connection = $this->db();
        $lock = 'live-report:'.substr(hash('sha256', $connection->getDatabaseName().$connection->getTablePrefix().$tenant.$data['operation_key']), 0, 48);
        abort_unless((int) $connection->selectFromWriteConnection('SELECT GET_LOCK(?,0) acquired', [$lock])[0]->acquired === 1, 409, __('yutiv-live_commerce::live.finance_generating'));
        try {
            return $this->snapshot(function () use ($tenant, $actor, $data) {
                $key = 'finance-report:'.$data['operation_key'];
                $input = ['from' => $data['from'], 'to' => $data['to'], 'period' => $data['period']];
                $hash = $this->digest($input);
                $existing = $this->records->query(E::Operation, $tenant)->where('operation_key', $key)->first();
                if ($existing) {
                    abort_unless(hash_equals($existing->request_hash, $hash), 409, __('yutiv-live_commerce::live.errors.e_d3154a2b33'));

                    return $existing->result['uuid'];
                }$uuid = $this->save($tenant, $actor, $input['from'], $input['to'], $input['period']);
                $this->records->create(E::Operation, $tenant, ['operation_key' => $key, 'request_hash' => $hash, 'result' => ['uuid' => $uuid]]);

                return $uuid;
            });
        } finally {
            $connection->selectFromWriteConnection('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    private function calculate(int $tenant, int $actor, string $from, string $to, string $period): array
    {
        $company = LiveTenant::findOrFail($tenant);
        [$start,$end,$buckets] = $this->buckets($company, $from, $to, $period);
        $utcA = $start->utc()->toDateTimeString();
        $utcB = $end->utc()->toDateTimeString();
        $state = $this->records->query(E::FinanceState, $tenant)->first();
        $reference = Date::now('UTC')->toDateTimeString();
        $operational = app(LiveLedgerService::class)->report($tenant, $actor, $from, $to, $period === 'custom' ? 'day' : $period);
        $periods = [];
        foreach ($buckets as $b) {
            $periods[$b['key']] = ['ordered' => (int) ($operational['periods'][$b['key']]['ordered'] ?? 0), 'cancel' => (int) ($operational['periods'][$b['key']]['cancel'] ?? 0), 'return' => (int) ($operational['periods'][$b['key']]['return'] ?? 0), 'revenue' => 0, 'cogs' => 0, 'loss' => 0, 'fixed' => 0, 'variable' => 0, 'deposit' => 0, 'refund' => 0, 'external_in' => 0, 'external_out' => 0, 'fees' => 0, 'incentive' => 0];
        }
        $alloc = $this->records->query(E::FifoAllocation, $tenant)->where('recognized_at', '>=', $utcA)->where('recognized_at', '<', $utcB);
        $expense = $this->records->query(E::Expense, $tenant)->where('occurred_on', '>=', $from)->where('occurred_on', '<=', $to)->whereNotNull('amount_krw');
        $cash = $this->records->query(E::FundEntry, $tenant)->where('occurred_on', '>=', $from)->where('occurred_on', '<=', $to);
        $incentives = $this->records->query(E::Incentive, $tenant)->where('created_at', '>=', $utcA)->where('created_at', '<', $utcB);
        // DECIMAL 부호로 unsigned 금액의 역분개도 정확히 음수 합산합니다.
        $sign = "CASE WHEN source_kind='reversal' THEN -1.0 ELSE 1.0 END";
        $datasets = [
            $this->periodAggregate(clone $alloc, 'recognized_at', ['revenue' => 'revenue_krw', 'cogs' => "CASE WHEN kind IN ('sale','return') THEN amount_krw ELSE 0 END", 'loss' => "CASE WHEN kind IN ('loss','restore') THEN amount_krw ELSE 0 END"], $buckets),
            $this->periodAggregate(clone $expense, 'occurred_on', ['fixed' => "CASE WHEN cost_group='fixed' AND treatment IN ('operating','fee') THEN amount_krw*($sign) ELSE 0 END", 'variable' => "CASE WHEN cost_group='variable' AND treatment IN ('operating','fee') THEN amount_krw*($sign) ELSE 0 END"], $buckets, false),
            $this->periodAggregate(clone $cash, 'occurred_on', ['deposit' => "CASE WHEN kind='deposit' THEN amount_krw ELSE 0 END", 'refund' => "CASE WHEN kind='refund' THEN -amount_krw ELSE 0 END", 'external_in' => "CASE WHEN kind IN ('deposit','recovery','incentive_recovery') THEN amount_krw ELSE 0 END", 'external_out' => "CASE WHEN kind IN ('refund','payment','incentive_payment','fee') THEN -amount_krw ELSE 0 END", 'fees' => "CASE WHEN kind='fee' THEN -amount_krw ELSE 0 END"], $buckets, false),
            $this->periodAggregate((clone $incentives)->where('kind', 'confirmed'), 'created_at', ['incentive' => 'amount'], $buckets),
        ];
        foreach ($datasets as $dataset) {
            foreach ($dataset as $date => $values) {
                foreach ($values as $key => $value) {
                    $periods[$date][$key] += $value;
                }
            }
        }
        $totals = [];
        foreach (['ordered', 'cancel', 'return', 'revenue', 'cogs', 'loss', 'fixed', 'variable', 'deposit', 'refund', 'external_in', 'external_out', 'fees', 'incentive'] as $field) {
            $totals[$field] = array_sum(array_column($periods, $field));
        }$totals['cash_net'] = $totals['external_in'] - $totals['external_out'];
        $totals['management_subtotal'] = $totals['revenue'] - $totals['cogs'] - $totals['loss'] - $totals['fixed'] - $totals['variable'] - $totals['fees'] - $totals['incentive'];
        $expenseGroups = (clone $expense)->select('currency', 'cost_group', 'treatment', 'category_id', 'category_name')->selectRaw("SUM(amount_original*($sign)) original_sum,SUM(amount_krw*($sign)) krw_sum")->groupBy('currency', 'cost_group', 'treatment', 'category_id', 'category_name')->orderBy('category_id')->get()->map(fn ($r) => $r->getAttributes())->all();
        $cashGroups = (clone $cash)->select('kind')->selectRaw('SUM(amount_minor) original_minor,SUM(amount_krw) krw_sum')->addSelect(['currency' => $this->records->query(E::Fund, $tenant)->whereColumn('id', E::FundEntry->value.'.fund_id')->select('currency')])->groupBy('kind', 'fund_id')->get()->map(fn ($r) => $r->getAttributes())->all();
        $bankGroups = (clone $cash)->whereIn('kind', ['deposit', 'refund'])->select('bank_account_id', 'bank_identity_key', 'kind')->selectRaw('SUM(amount_krw) amount,COUNT(*) records,MIN(id) snapshot_id')->groupBy('bank_account_id', 'bank_identity_key', 'kind')->get()->map(fn ($r) => $r->getAttributes())->all();
        $bankEntries = $this->records->query(E::FundEntry, $tenant)->whereIn('id', array_column($bankGroups, 'snapshot_id'))->get()->keyBy('id');
        $bankNames = [];
        $missingBankSnapshots = 0;
        foreach ($bankGroups as &$group) {
            $group['identity_key'] = $group['bank_identity_key'] ?? ('legacy:'.($group['bank_account_id'] ?? 'unspecified'));
            $snapshot = $bankEntries[$group['snapshot_id']]->bank_snapshot;
            $bankNames[$group['identity_key']] = $this->maskedBank($snapshot) ?? ['bank' => $group['bank_account_id'] ? '확인 당시 계좌 미확인' : '계좌 미지정 · 자금 직접 기록', 'holder' => '', 'last4' => ''];
            if ($group['bank_account_id'] && ! $snapshot) {
                $missingBankSnapshots += (int) $group['records'];
            }
        }unset($group);
        $asset = app(LiveFinanceAssetService::class)->totals($tenant, [], $reference);
        $unknown = (clone $alloc)->whereNull('amount_krw')->count();
        $pendingRates = $this->records->query(E::Expense, $tenant)->where('occurred_on', '<=', $to)->where('status', 'pending')->count();
        $unlinkedInventory = $this->records->query(E::Expense, $tenant)->where('treatment', 'inventory')->whereNull('receipt_id')->where('source_kind', '!=', 'reversal')->where('status', '!=', 'void')->where('occurred_on', '<=', $to)->count();
        $flags = [];
        if ($missingBankSnapshots) {
            $flags['bank_identity_missing'] = $missingBankSnapshots.'건 확인 당시 실제 계좌 스냅샷 없음';
        }
        if (! $operational['stock_totals']['complete']) {
            $flags['stock_reconcile'] = '기초·기말 재고 원장 대사 확인 필요';
        }
        if (! $state) {
            $flags['not_started'] = 'FIFO 기준 적용 전입니다.';
        } elseif ($state->started_at->gt(Date::parse($utcA, 'UTC'))) {
            $flags['partial_recognition_period'] = '관리 기준 적용 시각 이전 출고 매출·원가는 소급 재구성하지 않았습니다. 이 기간 손익은 일부 구간만 포함합니다.';
        }if ($asset['fifo_pending_options']) {
            $flags['fifo_pending'] = $asset['fifo_pending_options'].'개 옵션의 기초층 생성 대기';
        }if ($asset['actual']['unknown_quantity']) {
            $flags['unknown_inventory'] = $asset['actual']['unknown_quantity'].'개 실제 물품의 원가 미등록';
        }if ($unknown) {
            $flags['unknown_allocations'] = $unknown.'개 출고/복원/손실 원가 미확정';
        }if ($pendingRates) {
            $flags['pending_rates'] = $pendingRates.'건 환율 확인 대기';
        }if ($unlinkedInventory) {
            $flags['unlinked_inventory'] = $unlinkedInventory.'건 매입의 입고 연결 미확정';
        }
        $legacyReceipts = $this->records->query(E::Event, $tenant)->whereIn('type', ['deposit', 'refund'])->where('created_at', '>=', $utcA)->where('created_at', '<', $utcB)->whereNull('data->fund_id')->count();
        if ($legacyReceipts) {
            $flags['legacy_cash'] = $legacyReceipts.'건 기존 입금/환불의 실제 자금 계정 미연결';
        }
        $settings = $state?->data ?? [];
        $salary = (int) (clone $expense)->where('category_code', 'salary')->selectRaw("COALESCE(SUM(amount_krw*($sign)),0) total")->value('total');
        $threshold = (int) ($settings['labor_ratio_bps'] ?? 2000);
        $diagnostics = [];
        if ($totals['revenue'] > 0 && $salary * 10000 > $totals['revenue'] * $threshold) {
            $diagnostics[] = ['rule' => 'labor_ratio', 'message' => '기간 인건비가 설정한 관리매출 대비 기준을 초과했습니다.', 'salary_krw' => $salary, 'revenue_krw' => $totals['revenue'], 'threshold_bps' => $threshold];
        }foreach ($asset['aging'] as $bucket) {
            if ($bucket['aging_bucket'] === 'risk') {
                $diagnostics[] = ['rule' => 'aging_risk', 'message' => '위험 체류 기간 이상인 재고가 있습니다.', 'days' => (int) ($settings['aging_risk_days'] ?? 180), 'known_krw' => (int) $bucket['known_krw'], 'quantity' => (int) $bucket['quantity']];
            }
        }
        $fundBalances = $this->records->query(E::Fund, $tenant)->select('id', 'name', 'currency', 'active')->addSelect(['opening_minor' => $this->records->query(E::FundEntry, $tenant)->whereColumn('fund_id', E::Fund->value.'.id')->where('occurred_on', '<', $from)->selectRaw('COALESCE(SUM(amount_minor),0)'), 'closing_minor' => $this->records->query(E::FundEntry, $tenant)->whereColumn('fund_id', E::Fund->value.'.id')->where('occurred_on', '<=', $to)->selectRaw('COALESCE(SUM(amount_minor),0)'), 'closing_recorded_krw' => $this->records->query(E::FundEntry, $tenant)->whereColumn('fund_id', E::Fund->value.'.id')->where('occurred_on', '<=', $to)->selectRaw('COALESCE(SUM(amount_krw),0)')])->orderBy('id')->get()->map(fn ($r) => $r->getAttributes())->all();

        $totals['known_management_subtotal'] = $totals['management_subtotal'];
        if ($flags) {
            $totals['management_subtotal'] = null;
        }

        return ['basis_version' => self::BASIS, 'kind' => 'finance', 'tenant_id' => $tenant, 'tenant_name' => $company->name, 'timezone' => $company->timezone, 'from' => $from, 'to' => $to, 'period' => $period, 'start' => $utcA, 'end' => $utcB, 'source_cutoff' => $reference, 'generated_at' => $reference, 'fifo_started_at' => $state?->started_at?->toISOString(), 'creator' => ['id' => $actor, 'name' => TeeWideUser::find($actor)?->name ?? (string) $actor], 'revenue_basis' => '출고일 관리매출 및 대응 FIFO 원가. 반품일 원 배분 복원. 미출고 취소는 관리매출 없음.', 'currency_basis' => '원통화2자리·환율6자리·거래별 KRW half-up·분할은 누적 비례 차분', 'valuation_policy' => '외화 자금의 거래별 환산 기록액이며 재평가·확정 환차손익이 아님', 'periods' => $periods, 'totals' => $totals, 'expenses' => $expenseGroups, 'cash_groups' => $cashGroups, 'bank_groups' => $bankGroups, 'bank_names' => $bankNames, 'assets_now' => $asset, 'stock_period' => $operational['stock_totals'], 'current_analysis' => ['balances' => $this->currentBalances($tenant), 'cohort_sales' => $operational['sales_totals'], 'outstanding_legacy' => $operational['outstanding'], 'order_states' => $operational['order_states'], 'channels' => $operational['channels'], 'hosts' => $operational['hosts'], 'channel_names' => $operational['channel_names'], 'host_names' => $operational['host_names']], 'fund_balances' => $fundBalances, 'flags' => $flags, 'diagnostics' => $diagnostics, 'exclusions' => ['세금', '감가상각', '외화 자금 재평가·확정 환차손익'], 'watermark' => $this->watermark($tenant, $from, $to, $utcA, $utcB), 'complete' => ! $flags];
    }

    public function save(int $tenant, int $actor, string $from, string $to, string $period): string
    {
        $uuid = $this->snapshot(function () use ($tenant, $actor, $from, $to, $period) {
            $result = $this->calculate($tenant, $actor, $from, $to, $period);
            $report = $this->records->create(E::Report, $tenant, ['uuid' => (string) Str::uuid(), 'actor_id' => $actor, 'report_kind' => ReportKind::Finance->value, 'result' => $result, 'row_count' => 0, 'digest' => $this->digest($result)]);
            $sequence = 0;
            $buffer = [];
            $flush = function () use ($tenant, &$buffer) {
                if ($buffer) {
                    $this->records->query(E::ReportRow, $tenant)->insert($buffer);
                    $buffer = [];
                }
            };
            $append = function (string $kind, array $data) use ($tenant, $report, &$sequence, &$buffer, $flush) {
                $buffer[] = ['tenant_id' => $tenant, 'report_id' => $report->id, 'sequence' => ++$sequence, 'kind' => $kind, 'data' => json_encode($data, JSON_THROW_ON_ERROR), 'digest' => $this->digest($data), 'created_at' => now(), 'updated_at' => now()];
                if (count($buffer) === 100) {
                    $flush();
                }
            };
            foreach ([[E::Expense, 'occurred_on', ReportRowKind::FinanceExpense->value], [E::ExpensePayment, 'paid_on', ReportRowKind::FinancePayment->value], [E::ExpenseRecovery, 'recovered_on', ReportRowKind::FinanceRecovery->value], [E::FundEntry, 'occurred_on', ReportRowKind::FinanceCash->value]] as [$entity,$date,$kind]) {
                $sources = $this->records->query($entity, $tenant);
                // Current payables use historical invoices/payments; fund openings use prior cash entries.
                if ($entity === E::FundEntry) {
                    $sources->where($date, '<=', $to);
                }
                $sources->chunkById(100, function ($rows) use ($kind, $append) {
                    foreach ($rows as $row) {
                        $attributes = $row->getAttributes();
                        if ($kind === ReportRowKind::FinanceCash->value) {
                            unset($attributes['bank_snapshot']);
                            $attributes['actual_bank'] = $this->maskedBank($row->bank_snapshot);
                        }
                        $append($kind, $attributes);
                    }
                });
            }
            $this->records->query(E::MoneyTransfer, $tenant)->chunkById(100, function ($rows) use ($append) {
                foreach ($rows as $row) {
                    $append(ReportRowKind::FinanceTransfer->value, $row->getAttributes());
                }
            });
            // Current balances include all open historical orders; snapshot their sources in bounded chunks.
            $this->records->query(E::Order, $tenant)->select('id', 'uuid', 'created_at', 'payment_state', 'delivery_state', 'received', 'shipping', 'total', 'bank_snapshot')->chunkById(100, function ($rows) use ($append) {
                foreach ($rows as $row) {
                    $data = $row->getAttributes();
                    unset($data['bank_snapshot']);
                    $append(ReportRowKind::FinanceOrder->value, $data);
                }
            });
            $this->records->query(E::OrderLine, $tenant)->select('id', 'order_id', 'option_id', 'quantity', 'cancelled', 'returned', 'shipped', 'unit_price', 'snapshot')->chunkById(100, function ($rows) use ($append) {
                foreach ($rows as $row) {
                    $append(ReportRowKind::FinanceOrderLine->value, $row->getAttributes());
                }
            });
            $this->records->query(E::Event, $tenant)->where(fn ($q) => $q->where('type', 'refund')->orWhere(fn ($q) => $q->where('created_at', '>=', $result['start'])->where('created_at', '<', $result['end'])))->chunkById(100, function ($rows) use ($append) {
                foreach ($rows as $row) {
                    $append(ReportRowKind::FinanceOrderEvent->value, $row->getAttributes());
                }
            });
            $this->records->query(E::ReceiptItem, $tenant)->chunkById(100, function ($rows) use ($append) {
                foreach ($rows as $row) {
                    $append(ReportRowKind::FinancePurchase->value, $row->getAttributes());
                }
            });
            $this->records->query(E::Movement, $tenant)->where('created_at', '>=', $result['start'])->where('created_at', '<', $result['end'])->chunkById(100, function ($rows) use ($append) {
                foreach ($rows as $row) {
                    $append(ReportRowKind::FinanceStockMovement->value, $row->getAttributes());
                }
            });
            $this->records->query(E::FifoAllocation, $tenant)->where('recognized_at', '>=', $result['start'])->where('recognized_at', '<', $result['end'])->chunkById(100, function ($rows) use ($append) {
                foreach ($rows as $row) {
                    $append(ReportRowKind::FinanceFifo->value, $row->getAttributes());
                }
            });
            $this->records->query(E::Incentive, $tenant)->where('created_at', '>=', $result['start'])->where('created_at', '<', $result['end'])->chunkById(100, function ($rows) use ($append) {
                foreach ($rows as $row) {
                    $append(ReportRowKind::FinanceIncentive->value, $row->getAttributes());
                }
            });
            $assets = app(LiveFinanceAssetService::class);
            $page = 1;
            do {
                $rows = $assets->grouped($tenant, [], $result['source_cutoff'])->orderBy(E::Unit->value.'.option_id')->orderBy(E::Unit->value.'.receipt_item_id')->orderBy(E::Unit->value.'.receipt_key')->orderBy(E::Unit->value.'.location')->orderBy(E::Unit->value.'.logistics_stage')->orderBy('aging_bucket')->forPage($page++, 100)->get();
                foreach ($rows as $row) {
                    $append(ReportRowKind::FinanceAsset->value, $row->getAttributes());
                }
            } while ($rows->count() === 100);
            $flush();
            $report->update(['row_count' => $sequence]);

            return $report->uuid;
        });
        $this->audit($tenant, $actor, 'finance.report_saved', ['uuid' => $uuid, 'from' => $from, 'to' => $to]);

        return $uuid;
    }

    public function saved(int $tenant, int $actor, string $uuid, int $part = 1): array
    {
        $report = $this->records->query(E::Report, $tenant)->where('report_kind', ReportKind::Finance->value)->where('uuid', $uuid)->firstOrFail();
        abort_unless(hash_equals($report->digest, $this->digest($report->result)), 409, __('yutiv-live_commerce::live.report_corrupt'));
        $parts = max(1, (int) ceil($report->row_count / 100));
        abort_if($part < 1 || $part > $parts, 404);
        $rows = $this->records->query(E::ReportRow, $tenant)->where('report_id', $report->id)->whereBetween('sequence', [($part - 1) * 100 + 1, $part * 100])->orderBy('sequence')->get();
        foreach ($rows as $row) {
            abort_unless(hash_equals($row->digest, $this->digest($row->data)), 409, __('yutiv-live_commerce::live.report_corrupt'));
        }$this->audit($tenant, $actor, 'finance.report_reprinted', ['uuid' => $uuid, 'part' => $part]);

        return compact('report', 'rows', 'part', 'parts');
    }

    public function currentBalances(int $tenant): array
    {
        $lines = $this->records->query(E::OrderLine, $tenant)->whereColumn('order_id', E::Order->value.'.id')->selectRaw('COALESCE(SUM((quantity-cancelled-returned)*unit_price),0)');
        $refunds = $this->records->query(E::Event, $tenant)->whereColumn('order_id', E::Order->value.'.id')->where('type', 'refund')->selectRaw('COALESCE(SUM(amount),0)');
        $orders = $this->records->query(E::Order, $tenant)->select('id', 'received', 'shipping')->selectSub($lines, 'product_due')->selectSub($refunds, 'refunded');
        $cash = $this->db()->query()->fromSub($orders->toBase(), 'balances')->selectRaw('COUNT(*) orders,COALESCE(SUM(GREATEST(0,product_due+shipping-received+refunded)),0) receivable_krw,COALESCE(SUM(GREATEST(0,received-refunded-product_due-shipping)),0) customer_credit_krw,COALESCE(SUM(refunded),0) refunded_krw')->first();
        $paid = $this->records->query(E::ExpensePayment, $tenant)->whereColumn('expense_id', E::Expense->value.'.id')->selectRaw('COALESCE(SUM(amount_original),0)');
        $recovered = $this->records->query(E::ExpenseRecovery, $tenant)->whereColumn('expense_id', E::Expense->value.'.id')->selectRaw('COALESCE(SUM(amount_original),0)');
        $expenses = $this->records->query(E::Expense, $tenant)->where('status', '!=', 'void')->where('source_kind', '!=', 'reversal')->select('currency', 'amount_original', 'amount_krw')->selectSub($paid, 'paid')->selectSub($recovered, 'recovered');
        $payables = $this->db()->query()->fromSub($expenses->toBase(), 'payables')->select('currency')->selectRaw('SUM(GREATEST(0,amount_original-paid+recovered)) unpaid_original,SUM(CASE WHEN amount_krw IS NULL THEN 1 ELSE 0 END) pending_rate_count')->groupBy('currency')->get()->map(fn ($r) => (array) $r)->all();
        $receiptAmounts = $this->records->query(E::ReceiptItem, $tenant)->whereNotIn('receipt_id', $this->records->query(E::Expense, $tenant)->whereNotNull('receipt_id')->select('receipt_id'))->selectRaw('COALESCE(SUM(purchase_amount),0) known_krw,SUM(CASE WHEN purchase_amount IS NULL THEN 1 ELSE 0 END) unknown_rows')->first();

        $transfers = $this->records->query(E::MoneyTransfer, $tenant)->select('status', 'from_fund_id', 'to_fund_id')->selectRaw('COUNT(*) count,SUM(amount_out) principal_out,SUM(amount_in) expected_in,SUM(received_original) actual_in')->groupBy('status', 'from_fund_id', 'to_fund_id')->get()->map(fn ($r) => $r->getAttributes())->all();

        return ['customers' => array_map('intval', (array) $cash), 'expense_payables' => $payables, 'transfer_groups' => $transfers, 'unlinked_receipt_payables' => ['known_krw' => (int) $receiptAmounts->known_krw, 'unknown_rows' => (int) $receiptAmounts->unknown_rows]];
    }

    private function watermark(int $tenant, string $from, string $to, string $utcStart, string $utcEnd): array
    {
        $result = [];
        foreach ([[E::Expense, 'occurred_on', false], [E::ExpensePayment, 'paid_on', false], [E::ExpenseRecovery, 'recovered_on', false], [E::FundEntry, 'occurred_on', false], [E::FifoAllocation, 'recognized_at', true], [E::Movement, 'created_at', true], [E::Incentive, 'created_at', true]] as [$entity,$time,$utc]) {
            $q = $this->records->query($entity, $tenant)->where($time, '>=', $utc ? $utcStart : $from);
            $utc ? $q->where($time, '<', $utcEnd) : $q->where($time, '<=', $to);
            $fields = 'COUNT(*) count,COALESCE(MAX(id),0) last_id';
            $fields .= match ($entity) {
                E::Expense => ',COALESCE(SUM(amount_krw),0) krw,SUM(CASE WHEN amount_krw IS NULL THEN 1 ELSE 0 END) pending,COALESCE(SUM(exchange_rate),0) rates,COALESCE(SUM(amount_original),0) original',E::ExpensePayment,E::ExpenseRecovery,E::FundEntry,E::FifoAllocation => ',COALESCE(SUM(amount_krw),0) krw',E::Incentive => ',COALESCE(SUM(amount),0) amount',default => ''
            };
            $row = $q->selectRaw($fields)->first();
            $result[$entity->name] = array_map(fn ($v) => (string) ($v ?? 0), $row->getAttributes());
        }

        return $result;
    }

    public function close(int $tenant, int $actor, array $d): array
    {
        return $this->once($tenant, 'finance-close:'.$d['operation_key'], $d, function () use ($tenant, $actor, $d) {
            $report = $this->records->query(E::Report, $tenant)->where('report_kind', ReportKind::Finance->value)->where('uuid', $d['report_uuid'])->firstOrFail();
            $r = $report->result;
            abort_unless(hash_equals($report->digest, $this->digest($r)), 409);
            abort_unless($this->digest($r['watermark']) === $this->digest($this->watermark($tenant, $r['from'], $r['to'], $r['start'], $r['end'])), 409, __('yutiv-live_commerce::live.finance_stale_report'));
            abort_unless($r['complete'] || $d['acknowledge_incomplete'], 409, __('yutiv-live_commerce::live.finance_incomplete'));
            $today = Date::now(LiveTenant::findOrFail($tenant)->timezone)->format('Y-m-d');
            abort_unless($r['to'] <= $today, 422);
            abort_if($this->records->query(E::FinanceClosing, $tenant)->where('active', true)->where('from_on', '<=', $r['to'])->where('to_on', '>=', $r['from'])->exists(), 409);
            $close = $this->records->create(E::FinanceClosing, $tenant, ['from_on' => $r['from'], 'to_on' => $r['to'], 'report_uuid' => $report->uuid, 'active' => true, 'actor_id' => $actor, 'reason' => $d['reason']]);
            $this->audit($tenant, $actor, 'finance.period_closed', ['closing_id' => $close->id, 'reason' => $d['reason'], 'report_uuid' => $report->uuid, 'flags' => $r['flags'], 'acknowledge_incomplete' => $d['acknowledge_incomplete']]);

            return ['closing_id' => $close->id];
        });
    }

    public function reopen(int $tenant, int $actor, array $d): array
    {
        return $this->once($tenant, 'finance-reopen:'.$d['operation_key'], $d, function () use ($tenant, $actor, $d) {
            $row = $this->row(E::FinanceClosing, $tenant, $d['id'], true);
            abort_unless($row->active, 409);
            $row->update(['active' => false, 'reopened_by' => $actor, 'reopened_at' => now()]);
            $this->audit($tenant, $actor, 'finance.period_reopened', ['closing_id' => $row->id, 'reason' => $d['reason']]);

            return ['reopened' => true];
        });
    }
}
