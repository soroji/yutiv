<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = ['live_finance_states', 'live_finance_attachments', 'live_expense_categories', 'live_expenses', 'live_expense_payments', 'live_expense_recoveries', 'live_funds', 'live_fund_entries', 'live_money_transfers', 'live_recurring_expenses', 'live_finance_closings', 'live_fifo_options', 'live_fifo_layers', 'live_fifo_allocations'];

    private function table(string $name, callable $fields): void
    {
        Schema::create($name, function (Blueprint $t) use ($fields, $name) {
            $t->id()->comment('라이브 재무 기록 번호');
            $t->unsignedBigInteger('tenant_id')->comment('소유 업체');
            $fields($t);
            $t->timestamps();
            $t->index(['tenant_id', 'id']);
            foreach ($t->getCommands() as $command) {
                if (isset($command->index) && strlen($command->index) > 48) {
                    $command->index = 'lf_'.substr(hash('sha256', $name.':'.$command->index), 0, 24);
                }
            }
        });
    }

    public function up(): void
    {
        $this->table('live_finance_states', function (Blueprint $t) {
            $t->unique('tenant_id');
            $t->timestamp('started_at')->comment('FIFO 관리 기준 시작 시각');
            $t->unsignedBigInteger('actor_id')->comment('기초 적용 승인자');
            $t->json('data')->comment('노후화 기준과 진단 설정');
        });
        $this->table('live_finance_attachments', function (Blueprint $t) {
            $t->string('source_kind', 24)->comment('expense 또는 transfer 증빙');
            $t->unsignedBigInteger('source_id')->comment('업체 원본 업무');
            $t->string('path', 255)->comment('암호화한 비공개 증빙 경로');
            $t->string('name', 200)->comment('원래 파일명');
            $t->string('mime', 100)->comment('허용 MIME');
            $t->char('digest', 64)->comment('파일 SHA256');
            $t->unsignedBigInteger('actor_id')->comment('첨부자');
            $t->index(['tenant_id', 'source_kind', 'source_id']);
        });
        $this->table('live_expense_categories', function (Blueprint $t) {
            $t->string('code', 60)->comment('업체별 고유 항목 코드');
            $t->unsignedBigInteger('parent_id')->nullable()->comment('같은 업체 상위 항목');
            $t->string('name', 100)->comment('항목명');
            $t->string('cost_group', 20)->comment('fixed 고정비 variable 유동비');
            $t->string('treatment', 20)->comment('inventory 매입 operating 운영 fee 수수료 asset 자산 이동');
            $t->boolean('active')->default(true)->comment('신규 입력 선택 가능');
            $t->unique(['tenant_id', 'code']);
        });
        $this->table('live_expenses', function (Blueprint $t) {
            $t->unsignedBigInteger('category_id')->comment('비용 항목');
            $t->string('cost_group', 20)->comment('당시 관리 분류');
            $t->string('treatment', 20)->comment('당시 손익과 현금 처리 분류');
            $t->string('category_code', 60)->comment('당시 최상위 진단 항목 코드');
            $t->string('category_name', 100)->comment('당시 항목명');
            $t->date('occurred_on')->comment('업체 시간대 발생일');
            $t->char('currency', 3)->comment('KRW 또는 CNY');
            $t->decimal('amount_original', 18, 2)->comment('원통화 원금');
            $t->decimal('exchange_rate', 18, 6)->nullable()->comment('1 원통화당 KRW 6자리');
            $t->unsignedBigInteger('amount_krw')->nullable()->comment('거래별 half-up 환산액 미확정 null');
            $t->date('rate_date')->nullable()->comment('환율 기준일');
            $t->string('rate_source', 100)->comment('manual 또는 pending 확인 대기');
            $t->string('source_kind', 24)->comment('manual receipt recurring reversal');
            $t->string('source_key', 100)->comment('중복 원본 연결 방지 키');
            $t->unsignedBigInteger('receipt_id')->nullable()->comment('원래 입고 배치');
            $t->unique(['tenant_id', 'receipt_id']);
            $t->unsignedBigInteger('reversal_of')->nullable()->comment('취소 대상 원본 지출');
            $t->string('status', 20)->default('posted')->comment('posted 확정 pending 환율 대기 void 취소');
            $t->string('counterparty', 200)->nullable()->comment('거래처');
            $t->text('memo')->nullable()->comment('사유 메모');
            $t->unsignedBigInteger('actor_id')->comment('기록자');
            $t->unique(['tenant_id', 'source_kind', 'source_key']);
            $t->unique(['tenant_id', 'reversal_of']);
            $t->index(['tenant_id', 'occurred_on', 'id']);
        });
        $this->table('live_expense_payments', function (Blueprint $t) {
            $t->unsignedBigInteger('expense_id')->comment('확정 지출 또는 매입 대금');
            $t->unsignedBigInteger('fund_id')->comment('출금 자금 계정');
            $t->date('paid_on')->comment('업체 시간대 지급일');
            $t->decimal('amount_original', 18, 2)->comment('이번 지급 원통화액');
            $t->unsignedBigInteger('amount_krw')->comment('원금 고정 환율의 누적 비례 배분');
            $t->bigInteger('rounding_delta')->default(0)->comment('독립 반올림과 누적 배분 차이');
            $t->string('operation_key', 100)->comment('지급 요청 키');
            $t->unsignedBigInteger('actor_id')->comment('지급 확인자');
            $t->text('memo')->nullable()->comment('지급 메모');
            $t->unique(['tenant_id', 'operation_key']);
            $t->index(['tenant_id', 'expense_id', 'id']);
            $t->index(['tenant_id', 'paid_on', 'id']);
        });
        $this->table('live_expense_recoveries', function (Blueprint $t) {
            $t->unsignedBigInteger('payment_id')->comment('원래 지급 기록');
            $t->unsignedBigInteger('expense_id')->comment('원래 지출');
            $t->unsignedBigInteger('fund_id')->comment('실제 회수 계정');
            $t->date('recovered_on')->comment('회수 확인일');
            $t->decimal('amount_original', 18, 2)->comment('부분 회수 원통화액');
            $t->unsignedBigInteger('amount_krw')->comment('원 지급 환산액 비례 복원');
            $t->bigInteger('rounding_delta')->comment('독립 반올림과 차이');
            $t->string('operation_key', 100)->comment('멱등 요청');
            $t->text('reason')->comment('회수 사유');
            $t->unsignedBigInteger('actor_id')->comment('확인자');
            $t->unique(['tenant_id', 'operation_key']);
            $t->index(['tenant_id', 'payment_id', 'id']);
            $t->index(['tenant_id', 'recovered_on', 'id']);
        });
        $this->table('live_funds', function (Blueprint $t) {
            $t->string('name', 100)->comment('은행 현금 외화 지갑 명칭');
            $t->char('currency', 3)->comment('자금 계정 원통화');
            $t->boolean('active')->default(true)->comment('신규 자금 거래 가능');
            $t->unsignedBigInteger('actor_id')->comment('등록자');
        });
        $this->table('live_fund_entries', function (Blueprint $t) {
            $t->unsignedBigInteger('fund_id')->comment('자금 계정');
            $t->date('occurred_on')->comment('실제 확인 자금일');
            $t->string('kind', 24)->comment('opening adjustment deposit refund payment transfer_out transfer_in fee');
            $t->bigInteger('amount_minor')->comment('부호 있는 원통화 최소단위 KRW도 100분의1 단위');
            $t->bigInteger('amount_krw')->nullable()->comment('부호 있는 당시 환산 기록액 평가액 아님');
            $t->string('source_key', 120)->comment('원본 거래 고유 연결');
            $t->unsignedBigInteger('incentive_id')->nullable()->comment('기존 인센티브 지급 회수 원본');
            $t->unsignedBigInteger('order_id')->nullable()->comment('원래 주문');
            $t->unsignedBigInteger('bank_account_id')->nullable()->comment('실제 입출금 은행 계좌');
            $t->unsignedBigInteger('actor_id')->comment('기록자');
            $t->text('memo')->comment('확인 사유');
            $t->unique(['tenant_id', 'source_key']);
            $t->index(['tenant_id', 'fund_id', 'id']);
            $t->index(['tenant_id', 'occurred_on', 'kind']);
            $t->index(['tenant_id', 'order_id', 'id']);
        });
        $this->table('live_money_transfers', function (Blueprint $t) {
            $t->unsignedBigInteger('from_fund_id')->comment('출금 계정');
            $t->unsignedBigInteger('to_fund_id')->comment('수령 계정');
            $t->decimal('amount_out', 18, 2)->comment('출금 원통화 원금');
            $t->decimal('amount_in', 18, 2)->comment('수령 예정 또는 확정 원통화액');
            $t->decimal('exchange_rate', 18, 6)->comment('1 CNY당 KRW 실제 적용 환율 같은 통화 1');
            $t->decimal('fee_original', 18, 2)->default(0)->comment('별도 수수료');
            $t->char('fee_currency', 3)->comment('수수료 통화');
            $t->unsignedBigInteger('fee_fund_id')->nullable()->comment('수수료 출금 계정');
            $t->unsignedBigInteger('fee_krw')->nullable()->comment('수수료 고정 환산액');
            $t->date('requested_on')->comment('요청일');
            $t->date('withdrawn_on')->nullable()->comment('출금 확인일');
            $t->decimal('received_original', 18, 2)->nullable()->comment('실제 확인 수령액');
            $t->decimal('received_rate', 18, 6)->nullable()->comment('실제 수령 환율');
            $t->date('received_on')->nullable()->comment('수령 확인일');
            $t->string('status', 20)->comment('requested withdrawn received cancelled');
            $t->string('reference', 200)->nullable()->comment('증빙 참조번호');
            $t->text('memo')->nullable()->comment('송금 메모 자동 실행 없음');
            $t->unsignedBigInteger('actor_id')->comment('작업자');
        });
        $this->table('live_recurring_expenses', function (Blueprint $t) {
            $t->unsignedBigInteger('category_id')->comment('비용 항목');
            $t->char('currency', 3)->comment('원통화');
            $t->decimal('amount_original', 18, 2)->comment('반복 원금');
            $t->unsignedTinyInteger('day')->comment('매월 등록일 1부터31 말일 보정');
            $t->date('starts_on')->comment('시작일');
            $t->date('ends_on')->nullable()->comment('종료일');
            $t->date('next_on')->comment('다음 확인 월 날짜');
            $t->boolean('active')->default(true)->comment('자동 미지급 생성 여부');
            $t->string('rate_policy', 20)->comment('KRW one CNY manual_pending');
            $t->text('memo')->nullable()->comment('자동 생성 메모');
            $t->unsignedBigInteger('actor_id')->comment('규칙 작업자');
            $t->index(['active', 'next_on', 'id']);
        });
        $this->table('live_finance_closings', function (Blueprint $t) {
            $t->date('from_on')->comment('마감 시작일');
            $t->date('to_on')->comment('마감 종료일 포함');
            $t->string('report_uuid', 36)->comment('마감 당시 불변 보고서');
            $t->boolean('active')->default(true)->comment('마감 잠금 유지 여부');
            $t->unsignedBigInteger('actor_id')->comment('마감자');
            $t->unsignedBigInteger('reopened_by')->nullable()->comment('별도 권한 해제자');
            $t->timestamp('reopened_at')->nullable()->comment('해제 시각');
            $t->text('reason')->comment('마감 또는 해제 사유');
            $t->index(['tenant_id', 'active', 'from_on', 'to_on']);
        });
        $this->table('live_fifo_options', function (Blueprint $t) {
            $t->unsignedBigInteger('option_id')->comment('기초층 생성 옵션');
            $t->timestamp('baseline_at')->comment('최초 후속 거래 직전 기준 시각');
            $t->unique(['tenant_id', 'option_id']);
        });
        $this->table('live_fifo_layers', function (Blueprint $t) {
            $t->unsignedBigInteger('option_id')->comment('FIFO 옵션');
            $t->unsignedBigInteger('receipt_item_id')->nullable()->comment('원래 입고 원가 행');
            $t->string('source_kind', 24)->comment('opening receipt legacy_return unknown');
            $t->string('source_key', 100)->comment('기초 또는 입고 원본');
            $t->timestamp('received_at')->comment('층 원래 입고 시각');
            $t->unsignedInteger('quantity')->comment('원본 층 수량');
            $t->unsignedInteger('remaining_quantity')->comment('미배분 잔량');
            $t->char('currency', 3)->comment('원통화');
            $t->decimal('unit_original', 18, 2)->nullable()->comment('당시 원통화 단가 미등록 null');
            $t->decimal('exchange_rate', 18, 6)->nullable()->comment('당시 고정 환율');
            $t->unsignedBigInteger('amount_krw')->nullable()->comment('원본 층 총 원가');
            $t->unsignedBigInteger('remaining_krw')->nullable()->comment('반올림 잔액 포함 미배분 원가');
            $t->unique(['tenant_id', 'source_kind', 'source_key']);
            $t->index(['tenant_id', 'option_id', 'received_at', 'id']);
        });
        $this->table('live_fifo_allocations', function (Blueprint $t) {
            $t->unsignedBigInteger('movement_id')->comment('원래 물리 이동');
            $t->unsignedBigInteger('unit_id')->comment('실제 스캔 물품');
            $t->uuid('unit_uuid')->comment('실제 QR 불변 사본');
            $t->unsignedBigInteger('option_id')->comment('옵션');
            $t->unsignedBigInteger('order_line_id')->nullable()->comment('원래 판매 항목');
            $t->unsignedBigInteger('layer_id')->nullable()->comment('실제 QR과 별도인 FIFO 원가층');
            $t->unsignedBigInteger('reversal_of')->nullable()->comment('원 배분 복원');
            $t->string('kind', 24)->comment('sale loss return restore legacy_return');
            $t->bigInteger('quantity')->comment('소모 양수 복원 음수');
            $t->bigInteger('amount_krw')->nullable()->comment('소모 양수 복원 음수 미등록 null');
            $t->bigInteger('revenue_krw')->default(0)->comment('출고 관리매출 반품 음수');
            $t->timestamp('recognized_at')->comment('출고 반환 손실 발생 시각');
            $t->unique(['tenant_id', 'movement_id']);
            $t->unique(['tenant_id', 'reversal_of']);
            $t->index(['tenant_id', 'unit_id', 'id']);
            $t->index(['tenant_id', 'recognized_at', 'kind']);
        });
        Schema::table('live_bank_accounts', function (Blueprint $t) {
            $t->boolean('customer_visible')->default(true)->comment('활성 계좌의 신규 주문 안내 허용');
            $t->unsignedBigInteger('fund_id')->nullable()->comment('기존 은행 계좌와 자금 계정 연결');
        });
        Schema::table('live_stock_units', function (Blueprint $t) {
            $t->unsignedBigInteger('original_cost_krw')->nullable()->comment('신규 입고 때 배분한 개별 원가 과거 자료는 원 입고 원가 참조');
            $t->string('logistics_stage', 40)->nullable()->comment('중국 창고 국제 운송 한국 통관 등 재고 상태와 별도 물류 단계');
        });
        Schema::table('live_receipt_items', function (Blueprint $t) {
            $t->decimal('unit_original', 18, 2)->nullable()->comment('당시 원통화 단가 기존 KRW는 unit_cost 사용');
            $t->decimal('exchange_rate', 18, 6)->nullable()->comment('당시 고정 환율');
            $t->date('rate_date')->nullable()->comment('환율 기준일');
            $t->string('rate_source', 100)->nullable()->comment('수동 환율 출처');
        });
        Schema::table('live_reports', fn (Blueprint $t) => $t->string('report_kind', 24)->default('operational')->comment('기존 운영 보고서 또는 finance 통합 재무'));
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('재무 기록이 있어 전진 복구가 필요합니다.');
            }
        }
        if (Schema::hasColumn('live_receipt_items', 'unit_original') && DB::table('live_receipt_items')->whereNotNull('unit_original')->exists()) {
            throw new RuntimeException('원통화 입고 자료를 보존하기 위해 전진 복구가 필요합니다.');
        }
        if (Schema::hasColumn('live_stock_units', 'logistics_stage') && DB::table('live_stock_units')->whereNotNull('logistics_stage')->exists()) {
            throw new RuntimeException('물류 기록을 보존하기 위해 전진 복구가 필요합니다.');
        }
        if (Schema::hasColumn('live_reports', 'report_kind') && DB::table('live_reports')->where('report_kind', 'finance')->exists()) {
            throw new RuntimeException('저장 관리 보고서를 보존하기 위해 전진 복구가 필요합니다.');
        }
        if (Schema::hasColumn('live_reports', 'report_kind')) {
            Schema::table('live_reports', fn (Blueprint $t) => $t->dropColumn('report_kind'));
        }
        if (Schema::hasColumn('live_receipt_items', 'unit_original')) {
            Schema::table('live_receipt_items', fn (Blueprint $t) => $t->dropColumn(['unit_original', 'exchange_rate', 'rate_date', 'rate_source']));
        }
        if (Schema::hasColumn('live_stock_units', 'logistics_stage')) {
            Schema::table('live_stock_units',fn (Blueprint $t) => $t->dropColumn(['logistics_stage', 'original_cost_krw']));
        }
        if (Schema::hasColumn('live_bank_accounts','customer_visible')) {
            Schema::table('live_bank_accounts',fn (Blueprint $t) => $t->dropColumn(['customer_visible', 'fund_id']));
        }
        foreach (array_reverse($this->tables) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
