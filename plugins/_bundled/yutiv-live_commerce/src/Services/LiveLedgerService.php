<?php

namespace Plugins\Yutiv\LiveCommerce\Services;

use Carbon\CarbonImmutable as Date;
use Illuminate\Support\Str;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Enums\ReportRowKind;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;
use Plugins\Yutiv\LiveCommerce\Models\TeeWideUser;

/** Consistent, non-locking operational ledger. No accounting revenue recognition is implied. */
class LiveLedgerService extends LiveWorkflow
{
    public const BASIS = 'operational-events-3-purchases';

    public const PAGE_SIZE = 20;

    private const STOCK = ['opening', 'receive', 'ship', 'return', 'loss', 'as_in', 'as_out', 'adjustment', 'closing'];

    private function db()
    {
        return $this->records->query(E::Order, 0)->getModel()->getConnection();
    }

    private function table(E $e): string
    {
        return '`'.$this->db()->getTablePrefix().$e->value.'`';
    }

    public function report(int $tenant, int $actor, string $from, string $to, string $period, ?int $product = null, int $page = 1): array
    {
        if ($this->db()->transactionLevel() === 0) {
            $this->db()->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        $result = $this->records->transaction(fn () => $this->calculate($tenant, $from, $to, $period, $product, $page));
        $this->audit($tenant, $actor, 'ledger.viewed', compact('from', 'to', 'period', 'product'));

        return $result;
    }

    private function calculate(int $tenant, string $from, string $to, string $period, ?int $product, int $page): array
    {
        $company = LiveTenant::findOrFail($tenant);
        $start = Date::parse($from, $company->timezone)->startOfDay();
        $end = Date::parse($to, $company->timezone)->startOfDay()->addDay();
        abort_if($start >= $end || $start->diffInDays($end) > 366, 422, __('yutiv-live_commerce::live.ledger_limit'));
        if ($product) {
            $this->records->query(E::Product, $tenant)->findOrFail($product);
        }
        $bounds = [$start->utc()->toDateTimeString(), $end->utc()->toDateTimeString()];
        $options = $this->records->query(E::Option, $tenant)->when($product, fn ($q) => $q->where('product_id', $product));
        $total = (clone $options)->count();
        $page = min(max(1, $page), max(1, (int) ceil($total / self::PAGE_SIZE)));
        $ids = (clone $options)->orderBy('id')->offset(($page - 1) * self::PAGE_SIZE)->limit(self::PAGE_SIZE)->pluck('id')->all();
        $stock = $this->stock($tenant, $bounds, $product, $ids);
        $stockSql = $this->stockSql($tenant, $bounds, $product);
        $sums = implode(',', array_map(fn ($f) => "COALESCE(SUM(`$f`),0) `$f`", self::STOCK));
        $stockTotals = array_map('intval', (array) $this->db()->selectOne("SELECT $sums,COALESCE(MIN(complete),1) complete FROM ($stockSql) s"));
        $stockTotals['complete'] = (bool) $stockTotals['complete'];
        $o = $this->table(E::Order);
        $l = $this->table(E::OrderLine);
        $e = $this->table(E::Event);
        $u = $this->table(E::Unit);
        $m = $this->table(E::Movement);
        $op = $this->table(E::Option);
        $lineFilter = $product ? " AND l.option_id IN (SELECT id FROM $op WHERE tenant_id=$tenant AND product_id=".(int) $product.')' : '';
        $orderFilter = $product ? " AND EXISTS (SELECT 1 FROM $l l WHERE l.tenant_id=$tenant AND l.order_id=o.id $lineFilter)" : '';
        $periods = [];
        $buckets = [];
        for ($date = $start; $date < $end;) {
            $key = match ($period) {
                'week' => $date->startOfWeek(1)->format('Y-m-d'), 'month' => $date->format('Y-m'), default => $date->format('Y-m-d')
            };
            $next = match ($period) {
                'week' => $date->startOfWeek(1)->addWeek(), 'month' => $date->startOfMonth()->addMonth(), default => $date->addDay()
            };
            $next = $next->min($end);
            $buckets[] = [$date->utc()->toDateTimeString(), $next->utc()->toDateTimeString(), $key];
            $date = $next;
        }
        $aggregate = function (string $source, string $time, array $fields, string $where) use (&$periods, $buckets, $bounds) {
            $case = 'CASE';
            $bindings = [];
            foreach ($buckets as [$a, $b, $key]) {
                $case .= " WHEN $time>=? AND $time<? THEN ?";
                array_push($bindings, $a, $b, $key);
            }
            $case .= ' END';
            $sums = implode(',', array_map(fn ($f, $expr) => "SUM($expr) `$f`", array_keys($fields), $fields));
            foreach ($this->db()->select("SELECT $case bucket,$sums FROM $source WHERE $where AND $time>=? AND $time<? GROUP BY bucket", [...$bindings, ...$bounds]) as $row) {
                $periods[$row->bucket] ??= array_fill_keys(['quantity', 'ordered', 'deposit', 'cancel_quantity', 'cancel', 'return_quantity', 'return', 'refund', 'shipped'], 0);
                foreach ($fields as $f => $expr) {
                    $periods[$row->bucket][$f] = ($periods[$row->bucket][$f] ?? 0) + (int) $row->$f;
                }
            }
        };
        $aggregate("$l l JOIN $o o ON o.id=l.order_id AND o.tenant_id=l.tenant_id", 'o.created_at', ['quantity' => 'l.quantity', 'ordered' => 'l.quantity*l.unit_price'], "l.tenant_id=$tenant $lineFilter");
        $previous = "COALESCE((SELECT p.amount FROM $e p WHERE p.tenant_id=e.tenant_id AND p.order_id=e.order_id AND p.type='deposit' AND p.id<e.id ORDER BY p.id DESC LIMIT 1),0)";
        $fields = ['deposit' => "CASE WHEN e.type='deposit' THEN CAST(e.amount AS SIGNED)-CAST($previous AS SIGNED) ELSE 0 END"];
        foreach (['cancel', 'return', 'refund'] as $type) {
            $fields[$type] = "CASE WHEN e.type='$type' THEN e.amount ELSE 0 END";
        }
        foreach (['cancel', 'return'] as $type) {
            $fields[$type.'_quantity'] = "CASE WHEN e.type='$type' THEN COALESCE(JSON_EXTRACT(e.data,'$.quantity'),0) ELSE 0 END";
        }
        $eventFilter = $product ? " AND (e.type NOT IN ('cancel','return') OR JSON_EXTRACT(e.data,'$.line_id') IN (SELECT l.id FROM $l l WHERE l.tenant_id=$tenant $lineFilter))" : '';
        $aggregate("$e e JOIN $o o ON o.id=e.order_id AND o.tenant_id=e.tenant_id", 'e.created_at', $fields, "e.tenant_id=$tenant AND e.type IN ('deposit','cancel','return','refund') $orderFilter $eventFilter");
        $unitFilter = $product ? " AND u.option_id IN (SELECT id FROM $op WHERE tenant_id=$tenant AND product_id=".(int) $product.')' : '';
        $aggregate("$m m JOIN $u u ON u.id=m.unit_id AND u.tenant_id=m.tenant_id", 'm.created_at', ['shipped' => '1'], "m.tenant_id=$tenant AND m.type='ship' $unitFilter");
        $costTable = $this->table(E::ReceiptItem);
        $receiptTable = $this->table(E::Receipt);
        $costFilter = $product ? ' AND i.product_id='.(int) $product : '';
        $aggregate("$costTable i JOIN $receiptTable r ON r.id=i.receipt_id AND r.tenant_id=i.tenant_id", 'r.received_at', [
            'purchase_amount' => 'COALESCE(i.purchase_amount,0)',
            'purchase_known_quantity' => 'CASE WHEN i.unit_cost IS NOT NULL THEN i.quantity ELSE 0 END',
            'purchase_unknown_quantity' => 'CASE WHEN i.unit_cost IS NULL THEN i.quantity ELSE 0 END',
        ], "i.tenant_id=$tenant $costFilter");
        $aggregate("$m m JOIN $u u ON u.id=m.unit_id AND u.tenant_id=m.tenant_id", 'm.created_at', [
            'purchase_unknown_quantity' => '1',
        ], "m.tenant_id=$tenant AND m.type='receive' AND u.receipt_item_id IS NULL $unitFilter");
        $outstanding = (int) $this->db()->selectOne("SELECT COALESCE(SUM(GREATEST(0,COALESCE(d.due,0)+o.shipping-o.received)),0) amount FROM $o o LEFT JOIN (SELECT order_id,SUM((quantity-cancelled-returned)*unit_price) due FROM $l WHERE tenant_id=$tenant GROUP BY order_id) d ON d.order_id=o.id WHERE o.tenant_id=$tenant $orderFilter")->amount;
        $orderStates = $this->db()->select("SELECT o.payment_state,o.delivery_state,COUNT(*) quantity FROM $o o WHERE o.tenant_id=$tenant $orderFilter GROUP BY o.payment_state,o.delivery_state");
        $sales = $this->sales($tenant, $bounds, $product, $ids);
        $salesTotals = $this->sales($tenant, $bounds, $product, null, true);
        $channels = [];
        $hosts = [];
        $this->records->query(E::OrderLine, $tenant)->whereIn('order_id', $this->records->query(E::Order, $tenant)->where('payment_state', 'paid')->where('created_at', '>=', $bounds[0])->where('created_at', '<', $bounds[1])->select('id'))
            ->when($product, fn ($q) => $q->whereIn('option_id', (clone $options)->select('id')))->chunkById(250, function ($batch) use ($tenant, &$channels, &$hosts) {
                $corrections = [];
                $this->records->query(E::Event, $tenant)->where('type', 'attribution_correction')->whereIn('data->line_id', $batch->pluck('id'))->chunkById(250, function ($events) use (&$corrections) {
                    foreach ($events as $event) {
                        $corrections[$event->data['line_id']] = $event->data['after'];
                    }
                });
                foreach ($batch as $line) {
                    $a = $corrections[$line->id] ?? $line->snapshot;
                    $net = ($line->quantity - $line->cancelled - $line->returned) * $line->unit_price;
                    $id = $a['channel_id'] ?? 0;
                    $channels[$id] = ($channels[$id] ?? 0) + $net;
                    foreach (! empty($a['showhost_id']) ? [$a['showhost_id']] : array_keys($a['host_shares'] ?? []) as $id) {
                        $hosts[$id] = ($hosts[$id] ?? 0) + $net;
                    }
                }
            });
        ksort($periods);

        return ['basis_version' => self::BASIS, 'tenant_name' => $company->name, 'timezone' => $company->timezone, 'from' => $from, 'to' => $to, 'period' => $period, 'product_id' => $product,
            'start' => $start->toIso8601String(), 'end' => $end->toIso8601String(), 'generated_at' => now($company->timezone)->toIso8601String(), 'bounds' => $bounds,
            'stock' => $stock, 'stock_totals' => $stockTotals, 'periods' => $periods, 'sales' => $sales, 'sales_totals' => $salesTotals, 'outstanding' => $outstanding, 'order_states' => array_map(fn ($row) => (array) $row, $orderStates), 'channels' => $channels, 'hosts' => $hosts,
            'page' => $page, 'total' => $total, 'pages' => max(1, (int) ceil($total / self::PAGE_SIZE)),
            'channel_names' => $this->records->query(E::Channel, $tenant)->whereIn('id', array_keys($channels))->pluck('name', 'id')->all(), 'host_names' => $this->records->query(E::Showhost, $tenant)->whereIn('id', array_keys($hosts))->pluck('name', 'id')->all()];
    }

    private function stockSql(int $tenant, array $bounds, ?int $product, ?array $ids = null): string
    {
        [$a, $b] = $bounds;
        $o = $this->table(E::Option);
        $p = $this->table(E::Product);
        $u = $this->table(E::Unit);
        $m = $this->table(E::Movement);
        $filter = $product ? ' AND o.product_id='.(int) $product : '';
        if ($ids !== null) {
            $filter .= ' AND o.id IN ('.implode(',', array_map('intval', $ids ?: [0])).')';
        }
        $held = "('available','reserved','returned','as','defective')";
        $delta = "CAST(COALESCE(CAST(to_state AS BINARY) IN $held,0) AS SIGNED)-CAST(COALESCE(CAST(from_state AS BINARY) IN $held,0) AS SIGNED)";
        $column = "CASE WHEN CAST(type AS BINARY)='receive' THEN 'receive' WHEN CAST(type AS BINARY)='ship' THEN 'ship' WHEN CAST(type AS BINARY)='return' THEN 'return' WHEN CAST(to_state AS BINARY) IN ('lost','disposed') THEN 'loss' WHEN CAST(type AS BINARY)='as_receive' THEN 'as_in' WHEN CAST(type AS BINARY)='as_dispatch' THEN 'as_out' ELSE 'adjustment' END";
        $sums = [];
        foreach (self::STOCK as $f) {
            $condition = match ($f) {
                'opening' => "created_at<'$a'", 'closing' => "created_at<'$b'", default => "created_at>='$a' AND created_at<'$b' AND ($column)='$f'"
            };
            $sums[] = "SUM(CASE WHEN $condition THEN ($delta) ELSE 0 END) `$f`";
        }
        $history = "SELECT m.*,ROW_NUMBER() OVER(PARTITION BY m.unit_id ORDER BY m.id) rn,ROW_NUMBER() OVER(PARTITION BY m.unit_id ORDER BY m.id DESC) last_rn,LAG(m.to_state) OVER(PARTITION BY m.unit_id ORDER BY m.id) prior_state FROM $m m JOIN $u u ON u.id=m.unit_id AND u.tenant_id=m.tenant_id JOIN $o o ON o.id=u.option_id AND o.tenant_id=u.tenant_id WHERE m.tenant_id=$tenant $filter";
        // Match PHP's former strict checks regardless of the database's CI collation.
        $unit = 'SELECT unit_id,'.implode(',', $sums).",MIN(CASE WHEN rn=1 THEN CAST(type AS BINARY)='receive' AND from_state IS NULL ELSE CAST(from_state AS BINARY) <=> CAST(prior_state AS BINARY) END) chain_ok,MAX(CASE WHEN last_rn=1 THEN to_state END) final_state,MAX(CASE WHEN last_rn=1 THEN to_location END) final_location FROM ($history) h GROUP BY unit_id";
        $sums = implode(',', array_map(fn ($f) => "COALESCE(SUM(h.`$f`),0) `$f`", self::STOCK));

        return "SELECT o.id,p.code,p.name product,o.name `option`,$sums,MIN(CASE WHEN u.id IS NULL THEN 1 ELSE COALESCE(h.chain_ok,0) AND (CAST(h.final_state AS BINARY) <=> CAST(u.state AS BINARY)) AND (CAST(h.final_location AS BINARY) <=> CAST(u.location AS BINARY)) END) complete FROM $o o JOIN $p p ON p.id=o.product_id AND p.tenant_id=o.tenant_id LEFT JOIN $u u ON u.option_id=o.id AND u.tenant_id=o.tenant_id LEFT JOIN ($unit) h ON h.unit_id=u.id WHERE o.tenant_id=$tenant $filter GROUP BY o.id,p.code,p.name,o.name";
    }

    private function stock(int $tenant, array $bounds, ?int $product, array $ids): array
    {
        $rows = [];
        foreach ($this->db()->select($this->stockSql($tenant, $bounds, $product, $ids)) as $item) {
            $row = (array) $item;
            foreach (self::STOCK as $f) {
                $row[$f] = (int) $row[$f];
            }
            $row['complete'] = (bool) $row['complete'];
            $row['states'] = [];
            $rows[$item->id] = $row;
        }
        foreach ($this->records->query(E::Unit, $tenant)->whereIn('option_id', $ids)->selectRaw('option_id,state,COUNT(*) quantity')->groupBy('option_id', 'state')->get() as $state) {
            $rows[$state->option_id]['states'][$state->state] = (int) $state->quantity;
        }

        return $rows;
    }

    private function sales(int $tenant, array $bounds, ?int $product, ?array $ids, bool $totals = false): array
    {
        [$a, $b] = $bounds;
        $l = $this->table(E::OrderLine);
        $o = $this->table(E::Order);
        $e = $this->table(E::Event);
        $m = $this->table(E::Movement);
        $u = $this->table(E::Unit);
        $op = $this->table(E::Option);
        $filter = $product ? ' AND x.product_id='.(int) $product : '';
        if ($ids !== null) {
            $filter .= ' AND x.id IN ('.implode(',', array_map('intval', $ids ?: [0])).')';
        }
        $sql = "SELECT x.id,COALESCE(a.quantity,0) quantity,COALESCE(a.ordered,0) ordered,COALESCE(a.cohort_net_sales,0) cohort_net_sales,COALESCE(b.cancel_quantity,0) cancel_quantity,COALESCE(b.cancel,0) cancel,COALESCE(b.return_quantity,0) return_quantity,COALESCE(b.ret,0) `return`,COALESCE(c.shipped,0) shipped FROM $op x LEFT JOIN (SELECT l.option_id,SUM(l.quantity) quantity,SUM(l.quantity*l.unit_price) ordered,SUM(CASE WHEN o.payment_state='paid' THEN (l.quantity-l.cancelled-l.returned)*l.unit_price ELSE 0 END) cohort_net_sales FROM $l l JOIN $o o ON o.id=l.order_id AND o.tenant_id=l.tenant_id WHERE l.tenant_id=$tenant AND o.created_at>='$a' AND o.created_at<'$b' GROUP BY l.option_id) a ON a.option_id=x.id LEFT JOIN (SELECT l.option_id,SUM(CASE WHEN e.type='cancel' THEN e.amount ELSE 0 END) cancel,SUM(CASE WHEN e.type='cancel' THEN COALESCE(JSON_EXTRACT(e.data,'$.quantity'),0) ELSE 0 END) cancel_quantity,SUM(CASE WHEN e.type='return' THEN e.amount ELSE 0 END) ret,SUM(CASE WHEN e.type='return' THEN COALESCE(JSON_EXTRACT(e.data,'$.quantity'),0) ELSE 0 END) return_quantity FROM $e e JOIN $l l ON l.id=JSON_EXTRACT(e.data,'$.line_id') AND l.tenant_id=e.tenant_id WHERE e.tenant_id=$tenant AND e.type IN ('cancel','return') AND e.created_at>='$a' AND e.created_at<'$b' GROUP BY l.option_id) b ON b.option_id=x.id LEFT JOIN (SELECT u.option_id,COUNT(*) shipped FROM $m m JOIN $u u ON u.id=m.unit_id AND u.tenant_id=m.tenant_id WHERE m.tenant_id=$tenant AND m.type='ship' AND m.created_at>='$a' AND m.created_at<'$b' GROUP BY u.option_id) c ON c.option_id=x.id WHERE x.tenant_id=$tenant $filter";
        if ($totals) {
            $fields = ['quantity', 'ordered', 'cohort_net_sales', 'cancel_quantity', 'cancel', 'return_quantity', 'return', 'shipped'];

            return array_map('intval', (array) $this->db()->selectOne('SELECT '.implode(',', array_map(fn ($f) => "COALESCE(SUM(`$f`),0) `$f`", $fields))." FROM ($sql) s"));
        }
        $rows = [];
        foreach ($this->db()->select($sql) as $row) {
            $rows[$row->id] = array_map('intval', (array) $row);
        }

        return $rows;
    }

    /** Atomically publish a complete report. Inserts target new report rows only, never INSERT SELECT. */
    public function save(int $tenant, int $actor, string $from, string $to, string $period, ?int $product = null): string
    {
        if ($this->db()->transactionLevel() === 0) {
            $this->db()->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        $uuid = $this->records->transaction(function () use ($tenant, $actor, $from, $to, $period, $product) {
            $result = $this->calculate($tenant, $from, $to, $period, $product, 1);
            $result['creator'] = ['id' => $actor, 'name' => TeeWideUser::find($actor)?->name ?? (string) $actor];
            unset($result['stock'], $result['sales']);
            $report = $this->records->create(E::Report, $tenant, ['uuid' => (string) Str::uuid(), 'actor_id' => $actor, 'result' => $result, 'row_count' => 0, 'digest' => $this->digest($result)]);
            $sequence = 0;
            $buffer = [];
            $flush = function () use ($tenant, &$buffer) {
                if ($buffer) {
                    $this->records->query(E::ReportRow, $tenant)->insert($buffer);
                    $buffer = [];
                }
            };
            $append = function (ReportRowKind $kind, array $data) use ($tenant, $report, &$sequence, &$buffer, $flush) {
                $buffer[] = ['tenant_id' => $tenant, 'report_id' => $report->id, 'sequence' => ++$sequence, 'kind' => $kind->value, 'data' => json_encode($data, JSON_THROW_ON_ERROR), 'digest' => $this->digest($data), 'created_at' => now(), 'updated_at' => now()];
                if (count($buffer) === 100) {
                    $flush();
                }
            };
            $this->records->query(E::Option, $tenant)->when($product, fn ($q) => $q->where('product_id', $product))->chunkById(100, function ($options) use ($tenant, $result, $product, $append) {
                $ids = $options->pluck('id')->all();
                $sales = $this->sales($tenant, $result['bounds'], $product, $ids);
                foreach ($this->stock($tenant, $result['bounds'], $product, $ids) as $id => $stock) {
                    $append(ReportRowKind::Option, ['stock' => $stock, 'sales' => $sales[$id]]);
                }
            });
            [$a, $b] = $result['bounds'];
            $optionIds = $this->records->query(E::Option, $tenant)->when($product, fn ($q) => $q->where('product_id', $product))->select('id');
            $orderIds = $this->records->query(E::OrderLine, $tenant)->whereIn('option_id', clone $optionIds)->select('order_id');
            $this->records->query(E::OrderLine, $tenant)->whereIn('option_id', clone $optionIds)->whereIn('order_id', $this->records->query(E::Order, $tenant)->where('created_at', '>=', $a)->where('created_at', '<', $b)->select('id'))->chunkById(100, function ($batch) use ($tenant, $append) {
                $orders = $this->records->query(E::Order, $tenant)->whereIn('id', $batch->pluck('order_id'))->get(['id', 'uuid', 'created_at', 'payment_state', 'delivery_state', 'received'])->keyBy('id');
                foreach ($batch as $line) {
                    $append(ReportRowKind::Order, ['order' => $orders[$line->order_id]->toArray(), 'line' => $line->toArray()]);
                }
            });
            $this->records->query(E::Event, $tenant)->whereIn('order_id', $orderIds)->where('created_at', '>=', $a)->where('created_at', '<', $b)->chunkById(100, function ($batch) use ($tenant, $append, $optionIds) {
                $previous = [];
                $depositIds = $batch->where('type', 'deposit')->pluck('id')->all();
                if ($depositIds) {
                    $events = $this->table(E::Event);
                    foreach ($this->db()->select("SELECT e.id,COALESCE((SELECT p.amount FROM $events p WHERE p.tenant_id=e.tenant_id AND p.order_id=e.order_id AND p.type='deposit' AND p.id<e.id ORDER BY p.id DESC LIMIT 1),0) amount FROM $events e WHERE e.tenant_id=$tenant AND e.id IN (".implode(',', array_map('intval', $depositIds)).')') as $row) {
                        $previous[$row->id] = (int) $row->amount;
                    }
                }
                $selectedLines = $this->records->query(E::OrderLine, $tenant)->whereIn('option_id', clone $optionIds)->whereIn('id', $batch->pluck('data.line_id')->filter())->pluck('id')->all();
                foreach ($batch as $event) {
                    $data = $event->toArray();
                    $data['included_in_period_totals'] = in_array($event->type, ['deposit', 'refund'], true) || (in_array($event->type, ['cancel', 'return'], true) && in_array((int) ($event->data['line_id'] ?? 0), $selectedLines, true));
                    if ($event->type === 'deposit') {
                        $data['previous_confirmation'] = ['amount' => $previous[$event->id]];
                        $data['deposit_delta'] = $event->amount - $previous[$event->id];
                    }
                    $append(ReportRowKind::Event, $data);
                }
            });
            $this->records->query(E::Movement, $tenant)->whereIn('unit_id', $this->records->query(E::Unit, $tenant)->whereIn('option_id', clone $optionIds)->select('id'))->where('created_at', '>=', $a)->where('created_at', '<', $b)->chunkById(100, function ($batch) use ($append) {
                foreach ($batch as $move) {
                    $append(ReportRowKind::Movement, $move->toArray());
                }
            });
            $this->records->query(E::ReceiptItem, $tenant)->whereIn('receipt_id', $this->records->query(E::Receipt, $tenant)->where('received_at', '>=', $a)->where('received_at', '<', $b)->select('id'))->when($product, fn ($q) => $q->where('product_id', $product))->chunkById(100, function ($batch) use ($append) {
                foreach ($batch as $item) {
                    $append(ReportRowKind::Purchase, ['receipt_id' => $item->receipt_id, 'option_id' => $item->option_id, 'product_id' => $item->product_id, 'unit_cost' => $item->unit_cost, 'quantity' => $item->quantity, 'purchase_amount' => $item->purchase_amount, 'currency' => $item->currency]);
                }
            });
            $flush();
            $report->update(['row_count' => $sequence]);

            return $report->uuid;
        });
        $this->audit($tenant, $actor, 'ledger.saved', compact('uuid', 'from', 'to', 'period', 'product'));

        return $uuid;
    }

    private function digest(array $data): string
    {
        // MySQL JSON canonicalizes object key order; canonicalize recursively on both paths.
        $normalize = function ($value) use (&$normalize) {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($normalize, $value);
        };

        return hash('sha256', json_encode($normalize($data), JSON_THROW_ON_ERROR));
    }

    public function saved(int $tenant, int $actor, string $uuid, int $part = 1): array
    {
        $report = $this->records->query(E::Report, $tenant)->where('report_kind',\Plugins\Yutiv\LiveCommerce\Enums\ReportKind::Operational->value)->where('uuid', $uuid)->firstOrFail();
        abort_unless(hash_equals($report->digest, $this->digest($report->result)), 409, __('yutiv-live_commerce::live.report_corrupt'));
        $parts = max(1, (int) ceil($report->row_count / 100));
        abort_if($part < 1 || $part > $parts, 404);
        $rows = $this->records->query(E::ReportRow, $tenant)->where('report_id', $report->id)->whereBetween('sequence', [($part - 1) * 100 + 1, $part * 100])->orderBy('sequence')->get();
        foreach ($rows as $row) {
            abort_unless(hash_equals($row->digest, $this->digest($row->data)), 409, __('yutiv-live_commerce::live.report_corrupt'));
        }
        $this->audit($tenant, $actor, 'ledger.reprinted', ['uuid' => $uuid, 'part' => $part]);

        return compact('report', 'rows', 'parts', 'part');
    }
}
