<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_store_revisions', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary()->comment('계층 전체 쓰기·스냅샷 잠금 키');
            $t->unsignedBigInteger('revision')->default(0)->comment('계층·선택·리워드 정책 revision');
        });
        DB::table('personal_store_revisions')->insert(['id' => 1, 'revision' => 0]);
        Schema::create('personal_stores', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->comment('YUTIV 운영자')->constrained('users')->restrictOnDelete();
            $t->foreignId('parent_id')->nullable()->comment('승인된 부모')->constrained('personal_stores')->restrictOnDelete();
            $t->string('slug', 80)->unique()->comment('공개 주소');
            $t->string('status', 20)->default('pending')->index()->comment('pending 신청, active 운영, suspended 중지');
            $t->string('logo_url', 2048)->nullable()->comment('검증된 로고 URL');
            $t->string('banner_url', 2048)->nullable()->comment('검증된 배너 URL');
            $t->text('introduction')->nullable()->comment('소개');
            $t->timestamps();
        });
        Schema::create('personal_store_closure', function (Blueprint $t) {
            $t->foreignId('ancestor_id')->comment('조상')->constrained('personal_stores')->restrictOnDelete();
            $t->foreignId('descendant_id')->comment('후손')->constrained('personal_stores')->restrictOnDelete();
            $t->unsignedBigInteger('depth')->comment('자기 관계 0, 업무 깊이 제한 없음');
            $t->primary(['ancestor_id', 'descendant_id']);
            $t->index(['descendant_id', 'depth']);
        });
        Schema::create('personal_store_allocations', function (Blueprint $t) {
            $t->foreignId('child_id')->primary()->comment('직계 자식')->constrained('personal_stores')->restrictOnDelete();
            $t->foreignId('parent_id')->comment('배분 부모')->constrained('personal_stores')->restrictOnDelete();
            $t->unsignedSmallInteger('rate_bps')->default(0)->comment('0~10000 basis point');
            $t->index('parent_id');
        });
        Schema::create('personal_store_memberships', function (Blueprint $t) {
            $t->foreignId('user_id')->primary()->comment('귀속 회원')->constrained('users')->restrictOnDelete();
            $t->foreignId('store_id')->comment('검증된 상위 스토어')->constrained('personal_stores')->restrictOnDelete();
            $t->uuid('proof_id')->comment('귀속 검증 참조');
            $t->timestamp('created_at')->comment('최초 귀속 시각');
        });
        Schema::create('personal_store_applications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->comment('대상')->constrained('personal_stores')->restrictOnDelete();
            $t->foreignId('parent_id')->nullable()->comment('이동 목적지, NULL 최상위')->constrained('personal_stores')->restrictOnDelete();
            $t->string('kind', 20)->comment('open 개설, move 이동');
            $t->string('status', 20)->default('pending')->index()->comment('pending, approved, rejected');
            $t->foreignId('approved_by')->nullable()->comment('본사 승인자')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('personal_store_history', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->nullable()->comment('대상 스토어')->constrained('personal_stores')->restrictOnDelete();
            $t->foreignId('actor_id')->comment('변경 주체')->constrained('users')->restrictOnDelete();
            $t->string('kind', 30)->comment('변경 종류');
            $t->unsignedBigInteger('revision')->index()->comment('변경 revision');
            $t->json('payload')->comment('변경 전후');
            $t->timestamp('created_at')->comment('변경 시각');
        });
        Schema::create('personal_store_product_rates', function (Blueprint $t) {
            $t->foreignId('product_id')->primary()->comment('원본 상품')->constrained('ecommerce_products')->restrictOnDelete();
            $t->unsignedSmallInteger('rate_bps')->comment('본사 기본 리워드율 0~10000');
        });
        Schema::create('personal_store_products', function (Blueprint $t) {
            $t->foreignId('store_id')->comment('선택 스토어')->constrained('personal_stores')->restrictOnDelete();
            $t->foreignId('product_id')->comment('선택 상품')->constrained('ecommerce_products')->restrictOnDelete();
            $t->boolean('excluded')->default(false)->comment('true 제외 우선, false 개별 포함');
            $t->primary(['store_id', 'product_id']);
            $t->index(['product_id', 'store_id']);
        });
        Schema::create('personal_store_categories', function (Blueprint $t) {
            $t->foreignId('store_id')->comment('선택 스토어')->constrained('personal_stores')->restrictOnDelete();
            $t->foreignId('category_id')->comment('하위까지 포함')->constrained('ecommerce_categories')->restrictOnDelete();
            $t->primary(['store_id', 'category_id']);
        });
        Schema::create('personal_store_attributions', function (Blueprint $t) {
            $t->uuid('id')->primary()->comment('서버 발급 불투명 귀속 참조');
            $t->foreignId('store_id')->unique()->comment('판매 유입')->constrained('personal_stores')->restrictOnDelete();
            $t->timestamp('expires_at')->comment('신규 담기·가입 증명 만료');
            $t->timestamp('created_at')->comment('발급 시각');
        });
        Schema::create('personal_store_line_snapshots', function (Blueprint $t) {
            $t->id();
            $t->uuid('line_uuid')->unique()->comment('불변 원본 라인');
            $t->foreignId('order_id')->comment('원본 주문')->constrained('ecommerce_orders')->restrictOnDelete();
            $t->foreignId('store_id')->comment('판매 스토어')->constrained('personal_stores')->restrictOnDelete();
            $t->json('payload')->comment('개인정보 없는 불변 계산·계보·옵션 스냅샷');
            $t->foreignId('product_id')->nullable()->comment('통계용 원본 상품')->constrained('ecommerce_products')->restrictOnDelete();
            $t->unsignedBigInteger('eligible_base')->default(0)->comment('할인 후 포인트 사용 전 기준액');
            $t->unsignedBigInteger('quantity')->default(1)->comment('원본 수량');
            $t->timestamp('created_at')->comment('동결 시각');
            $t->index(['store_id', 'created_at']);
            $t->index(['store_id', 'product_id', 'created_at'], 'ps_lines_product_date');
            $t->index('order_id');
        });
        Schema::create('personal_store_beneficiaries', function (Blueprint $t) {
            $t->foreignId('snapshot_id')->comment('불변 원본')->constrained('personal_store_line_snapshots')->restrictOnDelete();
            $t->foreignId('store_id')->comment('수혜 스토어')->constrained('personal_stores')->restrictOnDelete();
            $t->unsignedBigInteger('amount')->comment('원래 배분 P');
            $t->primary(['snapshot_id', 'store_id']);
            $t->index(['store_id', 'snapshot_id']);
        });
        Schema::create('personal_store_wallets', function (Blueprint $t) {
            $t->foreignId('store_id')->primary()->comment('지갑 및 직렬화 잠금')->constrained('personal_stores')->restrictOnDelete();
            $t->unsignedBigInteger('available')->default(0)->comment('가용 정수 P');
            $t->unsignedBigInteger('debt')->default(0)->comment('회수 부족 P');
        });
        Schema::create('personal_store_ledger', function (Blueprint $t) {
            $t->id();
            $t->string('idempotency_key', 191)->unique()->comment('경제 사실 멱등 키');
            $t->foreignId('store_id')->comment('수혜')->constrained('personal_stores')->restrictOnDelete();
            $t->foreignId('snapshot_id')->comment('원본')->constrained('personal_store_line_snapshots')->restrictOnDelete();
            $t->string('kind', 20)->comment('earn, recover, shortfall, offset');
            $t->unsignedBigInteger('amount')->comment('절대 정수 P');
            $t->bigInteger('available_delta')->comment('가용 변동');
            $t->bigInteger('debt_delta')->comment('부족 변동');
            $t->timestamp('created_at')->comment('기록 시각');
            $t->index(['store_id', 'id']);
            $t->index(['snapshot_id', 'store_id']);
        });
        Schema::create('personal_store_line_states', function (Blueprint $t) {
            $t->foreignId('snapshot_id')->primary()->comment('집계 잠금')->constrained('personal_store_line_snapshots')->restrictOnDelete();
            $t->unsignedBigInteger('confirmed_base')->default(0)->comment('누적 구매확정 수량');
            $t->unsignedBigInteger('cancelled_base')->default(0)->comment('누적 경제적 취소 확정 수량');
            $t->boolean('review_required')->default(false)->comment('판단 불가능한 환불 수동 검토');
            $t->unsignedBigInteger('last_event_id')->default(0)->comment('검토 상태 이벤트 순서');
            $t->unsignedBigInteger('capture_version')->default(0)->comment('상태 전환 순번');
            $t->string('capture_hash', 64)->nullable()->comment('마지막 캡처 상태 지문');
        });
        Schema::create('personal_store_outbox', function (Blueprint $t) {
            $t->id();
            $t->string('event_key', 191)->unique()->comment('이벤트 멱등 키');
            $t->string('type', 40)->comment('이벤트 종류');
            $t->json('payload')->comment('불변 개인정보 없는 이벤트');
            $t->timestamp('processed_at')->nullable()->comment('원장 반영 시각');
            $t->timestamp('created_at')->comment('생성 시각');
            $t->index(['processed_at', 'id']);
        });
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            foreach (['allocations', 'product_rates'] as $suffix) {
                $table = DB::connection()->getQueryGrammar()->wrapTable('personal_store_'.$suffix);
                $constraint = DB::connection()->getQueryGrammar()->wrap(DB::connection()->getTablePrefix().'ps_'.$suffix.'_bps');
                DB::statement('ALTER TABLE '.$table.' ADD CONSTRAINT '.$constraint.' CHECK (rate_bps <= 10000)');
            }
        }
    }

    public function down(): void
    {
        foreach (['outbox', 'line_states', 'ledger', 'wallets', 'beneficiaries', 'line_snapshots', 'attributions', 'categories', 'products', 'product_rates', 'history', 'applications', 'memberships', 'allocations', 'closure'] as $suffix) {
            Schema::dropIfExists('personal_store_'.$suffix);
        }
        Schema::dropIfExists('personal_stores');
        Schema::dropIfExists('personal_store_revisions');
    }
};
