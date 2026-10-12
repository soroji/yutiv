<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_tenants', function (Blueprint $t) {
            $t->string('timezone', 64)->default('Asia/Seoul')->comment('업체 시간대');
            $t->unsignedInteger('reservation_minutes')->default(60)->comment('입금 대기 재고 예약 분');
            $t->unsignedInteger('return_days')->default(7)->comment('배송 완료 후 반품 일수');
            $t->string('logo_path')->nullable()->comment('검증된 브랜드 이미지');
            $t->json('contact_links')->nullable()->comment('승인된 상담 URL');
        });
        Schema::table('teewide_users', function (Blueprint $t) {
            $t->unsignedBigInteger('tenant_id')->nullable()->index()->comment('NULL은 기존 계정 귀속 확인 대기');
            $t->string('username', 100)->nullable()->comment('운영자 발급 로그인 ID');
            $t->unsignedInteger('session_version')->default(1)->comment('기존 세션 무효화 버전');
            $t->boolean('must_change_password')->default(false)->comment('최초 및 재설정 후 비밀번호 변경 의무');
            $t->text('customs_code')->nullable()->comment('암호화 개인통관고유부호');
            $t->dropUnique(['email']);
            $t->unique(['tenant_id', 'email'], 'live_user_tenant_email');
            $t->unique(['tenant_id', 'username'], 'live_user_tenant_username');
            $t->foreign('tenant_id')->references('id')->on('live_tenants')->restrictOnDelete();
        });
        $this->create('live_domains', function (Blueprint $t) {
            $t->string('host', 191)->unique()->comment('정규화된 정확한 호스트');
            $t->boolean('active')->default(false)->comment('DB 공개 활성 상태, DNS/TLS 확인과 별개');
            $t->boolean('primary')->default(false)->comment('고객 링크 대표 도메인');
        });
        $this->create('live_staff', function (Blueprint $t) {
            $t->unsignedBigInteger('user_id')->comment('라이브 회원');
            $t->string('role', 24)->comment('owner/sales/warehouse/finance/customs');
            $t->boolean('active')->default(true)->comment('권한 활성 상태');
            $t->unique(['tenant_id', 'user_id']);
            $t->foreign('user_id')->references('id')->on('teewide_users')->restrictOnDelete();
        });
        $this->create('live_addresses', function (Blueprint $t) {
            $t->unsignedBigInteger('user_id')->index()->comment('소유 고객');
            $t->text('delivery')->comment('암호화 배송 정보');
        });
        $this->create('live_password_resets', function (Blueprint $t) {
            $t->unsignedBigInteger('user_id')->comment('재설정 대상');
            $t->char('token_hash', 64)->unique()->comment('일회용 토큰 SHA256');
            $t->timestamp('expires_at')->comment('만료 시각');
            $t->timestamp('used_at')->nullable()->comment('소비 시각');
        });
        $this->create('live_bank_accounts', function (Blueprint $t) {
            $t->string('bank', 100)->comment('은행명');
            $t->text('account_number')->comment('암호화 계좌번호');
            $t->string('holder', 100)->comment('예금주');
            $t->boolean('active')->default(true)->comment('신규 주문 사용 여부');
            $t->boolean('default')->default(false)->comment('업체 기본 계좌');
        });
        $this->create('live_products', function (Blueprint $t) {
            $t->string('code', 100)->comment('업체 상품 코드');
            $t->string('name', 200)->comment('상품명');
            $t->text('description')->nullable()->comment('상품 설명');
            $t->string('image_path')->nullable()->comment('업체 상품 이미지');
            $t->boolean('published')->default(false)->comment('공개 노출');
            $t->boolean('sellable')->default(false)->comment('판매 가능');
            $t->unique(['tenant_id', 'code']);
        });
        $this->create('live_options', function (Blueprint $t) {
            $t->unsignedBigInteger('product_id')->index()->comment('원본 상품');
            $t->string('name', 200)->comment('옵션명');
            $t->unsignedBigInteger('price')->comment('KRW 정수 가격');
            $t->unsignedBigInteger('discount')->default(0)->comment('단위 할인 금액');
            $t->char('qr_code', 36)->unique()->comment('불투명 품목 QR');
            $t->boolean('active')->default(true)->comment('판매 옵션 활성');
            $t->foreign('product_id')->references('id')->on('live_products')->restrictOnDelete();
        });
        $this->create('live_channels', function (Blueprint $t) {
            $t->string('name', 100)->comment('유튜브 채널명');
            $t->string('url', 500)->comment('검증된 유튜브 URL');
            $t->boolean('active')->default(true)->comment('활성');
        });
        $this->create('live_showhosts', function (Blueprint $t) {
            $t->string('name', 100)->comment('쇼호스트 표시 이름');
            $t->boolean('active')->default(true)->comment('활성');
        });
        $this->create('live_broadcasts', function (Blueprint $t) {
            $t->string('name', 200)->comment('방송명');
            $t->string('state', 20)->default('draft')->index()->comment('draft/selling/closed');
            $t->unsignedBigInteger('channel_id')->comment('유튜브 채널');
            $t->unsignedBigInteger('bank_account_id')->nullable()->comment('방송 지정 계좌');
            $t->string('video_url', 500)->nullable()->comment('검증된 유튜브 방송 주소');
            $t->timestamp('starts_at')->comment('예정 시작 UTC');
            $t->timestamp('ends_at')->comment('예정 종료 UTC');
            $t->timestamp('sale_starts_at')->comment('판매 시작 UTC');
            $t->timestamp('sale_ends_at')->comment('판매 종료 UTC');
            $t->unsignedInteger('policy_version')->default(1)->comment('정책 버전');
            $t->unsignedInteger('channel_rate')->default(0)->comment('채널 배분율 10000분율');
            $t->unsignedInteger('host_rate')->default(0)->comment('쇼호스트 총 배분율 10000분율');
            $t->json('host_shares')->comment('공동 진행 ID 및 정수 배분율');
        });
        $this->create('live_broadcast_items', function (Blueprint $t) {
            $t->unsignedBigInteger('broadcast_id')->comment('방송');
            $t->unsignedBigInteger('option_id')->comment('공유 재고 옵션');
            $t->unsignedInteger('position')->default(0)->comment('공개 노출 순서');
            $t->boolean('active')->default(true)->comment('방송 판매 대상');
            $t->unsignedInteger('channel_rate')->nullable()->comment('명시적 채널율 예외');
            $t->unsignedInteger('host_rate')->nullable()->comment('명시적 호스트율 예외');
            $t->unique(['broadcast_id', 'option_id']);
        });
        $this->create('live_sale_links', function (Blueprint $t) {
            $t->string('code', 64)->unique()->comment('불투명 판매 코드');
            $t->unsignedBigInteger('broadcast_id')->comment('방송');
            $t->unsignedBigInteger('channel_id')->comment('귀속 채널');
            $t->unsignedBigInteger('showhost_id')->nullable()->comment('개인 진행 링크, NULL은 공동');
            $t->unsignedBigInteger('option_id')->nullable()->comment('NULL은 방송 상품 목록');
            $t->string('contact', 24)->default('other')->comment('kakao/telegram/other');
            $t->boolean('active')->default(true)->comment('활성');
            $t->timestamp('expires_at')->nullable()->comment('링크 만료 UTC');
        });
        $this->create('live_cart_lines', function (Blueprint $t) {
            $t->char('uuid', 36)->unique()->comment('변경 없는 원본 라인');
            $t->string('cart_key', 64)->index()->comment('세션 또는 회원 카트');
            $t->unsignedBigInteger('user_id')->nullable()->index()->comment('NULL은 비회원');
            $t->unsignedBigInteger('option_id')->comment('공유 옵션');
            $t->unsignedInteger('quantity')->comment('수량');
            $t->json('attribution')->comment('서버 검증된 판매 귀속');
        });
        $this->create('live_orders', function (Blueprint $t) {
            $t->char('uuid', 36)->unique()->comment('고객 주문 식별자');
            $t->unsignedBigInteger('user_id')->index()->comment('주문 고객');
            $t->string('operation_key', 100)->comment('제출 멱등키');
            $t->char('request_hash', 64)->comment('같은 키의 다른 입력 차단');
            $t->string('payment_state', 24)->default('pending')->index()->comment('pending/paid/review/expired/cancelled');
            $t->string('delivery_state', 24)->default('pending')->comment('pending/partial/shipped/delivered');
            $t->unsignedBigInteger('subtotal')->comment('할인 후 상품 총액');
            $t->unsignedBigInteger('shipping')->default(0)->comment('배송비');
            $t->unsignedBigInteger('total')->comment('입금 안내 금액');
            $t->unsignedBigInteger('received')->default(0)->comment('수동 확인 실제 입금액');
            $t->text('bank_snapshot')->comment('암호화 주문 당시 안내 계좌');
            $t->text('delivery')->comment('암호화 배송 정보');
            $t->timestamp('expires_at')->index()->comment('예약 만료 UTC');
            $t->timestamp('reservation_released_at')->nullable()->comment('예약 해제 완료, 검토 주문 반복 처리 방지');
            $t->timestamp('paid_at')->nullable()->comment('입금 확인 UTC');
            $t->timestamp('delivered_at')->nullable()->comment('배송 완료 UTC');
            $t->timestamp('confirmed_at')->nullable()->comment('고객 구매확정 UTC');
            $t->unique(['tenant_id', 'user_id', 'operation_key'], 'live_order_operation');
        });
        $this->create('live_order_lines', function (Blueprint $t) {
            $t->char('uuid', 36)->unique()->comment('원본 카트 UUID 유지');
            $t->unsignedBigInteger('order_id')->index()->comment('주문');
            $t->unsignedBigInteger('option_id')->index()->comment('옵션');
            $t->unsignedInteger('quantity')->comment('원본 수량');
            $t->unsignedInteger('cancelled')->default(0)->comment('누적 취소 수량');
            $t->unsignedInteger('returned')->default(0)->comment('누적 반품 수량');
            $t->unsignedInteger('shipped')->default(0)->comment('누적 출고 수량');
            $t->unsignedBigInteger('unit_price')->comment('할인 후 원본 단가');
            $t->json('snapshot')->comment('상품 가격 귀속 정책 불변 스냅샷');
            $t->boolean('disputed')->default(false)->comment('분쟁/반품 진행, 자동 확정 제외');
            $t->foreign('order_id')->references('id')->on('live_orders')->restrictOnDelete();
        });
        $this->create('live_stock_units', function (Blueprint $t) {
            $t->char('uuid', 36)->unique()->comment('개별 물품 불변 QR 식별자');
            $t->unsignedBigInteger('option_id')->index()->comment('상품 옵션');
            $t->string('state', 24)->default('available')->comment('available/reserved/shipped/returned/as/defective/lost/disposed');
            $t->string('location', 100)->comment('현재 위치');
            $t->unsignedBigInteger('order_line_id')->nullable()->index()->comment('예약/출고 주문 라인');
            $t->string('receipt_key', 100)->comment('입고 작업 키');
            $t->index(['tenant_id', 'option_id', 'state'], 'live_unit_stock');
        });
        $this->create('live_stock_movements', function (Blueprint $t) {
            $t->unsignedBigInteger('unit_id')->index()->comment('물품');
            $t->string('operation_key', 100)->comment('작업 멱등키');
            $t->string('type', 24)->comment('입고/출고/이동/반품/AS/망실/정정');
            $t->string('from_state', 24)->nullable()->comment('이전 상태');
            $t->string('to_state', 24)->comment('전이 상태');
            $t->string('from_location', 100)->nullable()->comment('이전 위치');
            $t->string('to_location', 100)->comment('전이 위치');
            $t->unsignedBigInteger('actor_id')->comment('담당자');
            $t->unsignedBigInteger('order_line_id')->nullable()->comment('해당 시점의 주문 연결, 재판매 이후에도 이력 보존');
            $t->text('reason')->comment('작업/정정 사유');
            $t->unique(['tenant_id', 'unit_id', 'operation_key'], 'live_movement_operation');
        });
        $this->create('live_order_events', function (Blueprint $t) {
            $t->unsignedBigInteger('order_id')->index()->comment('주문');
            $t->string('operation_key', 100)->comment('작업 키');
            $t->string('type', 24)->comment('입금/취소/반품/환불/계좌정정/귀속정정');
            $t->unsignedBigInteger('amount')->default(0)->comment('기록 금액, 실제 은행 송금과 별개');
            $t->json('data')->comment('비밀값 없는 변경 기록');
            $t->unsignedBigInteger('actor_id')->comment('작업자');
            $t->unique(['tenant_id', 'order_id', 'operation_key'], 'live_event_operation');
        });
        $this->create('live_incentive_ledger', function (Blueprint $t) {
            $t->unsignedBigInteger('order_line_id')->index()->comment('원본 주문 라인');
            $t->string('beneficiary', 100)->comment('channel:ID 또는 host:ID');
            $t->string('kind', 24)->comment('expected/confirmed/payment/adjustment');
            $t->bigInteger('amount')->comment('KRW 정수, 음수는 조정');
            $t->string('operation_key', 150)->comment('누적 목표 멱등키');
            $t->unsignedBigInteger('actor_id')->nullable()->comment('승인/지급 담당자');
            $t->unique(['tenant_id', 'operation_key'], 'live_incentive_operation');
        });
        $this->create('live_audit', function (Blueprint $t) {
            $t->unsignedBigInteger('actor_id')->nullable()->comment('작업자');
            $t->string('actor_type', 20)->comment('headquarters/staff/customer/system');
            $t->string('action', 100)->comment('업무 작업명');
            $t->json('data')->comment('민감값 제외 감사 자료');
        });
        $this->create('live_operations', function (Blueprint $t) {
            $t->string('operation_key', 150)->comment('업무 재전송 키');
            $t->char('request_hash', 64)->comment('같은 키 다른 입력 차단');
            $t->json('result')->comment('비밀값 없는 작업 결과');
            $t->unique(['tenant_id', 'operation_key']);
        });
    }

    private function create(string $name, Closure $columns): void
    {
        Schema::create($name, function (Blueprint $t) use ($columns) {
            $t->id()->comment('라이브 업무 ID');
            $t->unsignedBigInteger('tenant_id')->index()->comment('소유 업체');
            $columns($t);
            $t->timestamps();
            $t->foreign('tenant_id')->references('id')->on('live_tenants')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['live_operations', 'live_audit', 'live_incentive_ledger', 'live_order_events', 'live_stock_movements', 'live_stock_units', 'live_order_lines', 'live_orders', 'live_cart_lines', 'live_sale_links', 'live_broadcast_items', 'live_broadcasts', 'live_showhosts', 'live_channels', 'live_options', 'live_products', 'live_bank_accounts', 'live_password_resets', 'live_addresses', 'live_staff', 'live_domains'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('업무 데이터 생성 이후에는 rollback 대신 forward 복구를 사용하세요.');
            }
        }
        if (DB::table('live_orders')->exists()
            || DB::table('live_stock_units')->exists()
            || DB::table('teewide_users')->whereNotNull('tenant_id')->exists()) {
            throw new RuntimeException('업무 데이터 생성 이후에는 rollback 대신 forward 복구를 사용하세요.');
        }
        Schema::dropIfExists('live_operations');
        foreach (['live_audit', 'live_incentive_ledger', 'live_order_events', 'live_stock_movements', 'live_stock_units', 'live_order_lines', 'live_orders', 'live_cart_lines', 'live_sale_links', 'live_broadcast_items', 'live_broadcasts', 'live_showhosts', 'live_channels', 'live_options', 'live_products', 'live_bank_accounts', 'live_password_resets', 'live_addresses', 'live_staff', 'live_domains'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('teewide_users', function (Blueprint $t) {
            $t->dropForeign(['tenant_id']);
            $t->dropUnique('live_user_tenant_email');
            $t->dropUnique('live_user_tenant_username');
            $t->dropColumn(['tenant_id', 'username', 'session_version', 'must_change_password', 'customs_code']);
            // 업체 분리 이후 이메일 중복이 있으면 실패합니다. 운영 복구는 forward fix를 사용합니다.
            $t->unique('email');
        });
        Schema::table('live_tenants', function (Blueprint $t) {
            $t->dropColumn(['timezone', 'reservation_minutes', 'return_days', 'logo_path', 'contact_links']);
        });
    }
};
