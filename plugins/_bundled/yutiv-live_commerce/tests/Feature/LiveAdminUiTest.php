<?php

namespace Plugins\Yutiv\LiveCommerce\Tests\Feature;

use Plugins\Yutiv\LiveCommerce\Contracts\LiveRepositoryInterface;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Enums\StaffRole;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;
use Plugins\Yutiv\LiveCommerce\Models\TeeWideUser;
use Plugins\Yutiv\LiveCommerce\Services\LiveAccountService;
use Plugins\Yutiv\LiveCommerce\Services\LiveExpenseService;
use Plugins\Yutiv\LiveCommerce\Services\LiveFinanceDashboardService;
use Plugins\Yutiv\LiveCommerce\Services\LiveFinanceReportService;
use Plugins\Yutiv\LiveCommerce\Services\LiveFinanceSetupService;
use Plugins\Yutiv\LiveCommerce\Support\LiveDisplay;
use Plugins\Yutiv\LiveCommerce\Support\LiveSellerNavigation;

class LiveAdminUiTest extends TeeWideMainDesignTest
{
    private function operator(string $role = 'owner'): array
    {
        $tenant = $this->seedGolfifTenant();
        $service = app(LiveAccountService::class);
        $issued = $service->issue($tenant->id, 0, ['name' => '격리 관리자 UI', 'username' => 'admin080', 'role' => $role]);
        $user = TeeWideUser::findOrFail($issued['user_id']);
        $service->changePassword($user, 'Synthetic-only-1234!');
        $login = $this->post('http://'.self::ROOT_HOST.'/login', ['email' => 'admin080', 'password' => 'Synthetic-only-1234!'])->assertRedirect();
        $this->withUnencryptedCookie('teewide_session', $login->getCookie('teewide_session', false)->getValue())->withCredentials();

        return [$tenant, $user, app(LiveRepositoryInterface::class)];
    }

    private function menu(string $html): array
    {
        preg_match('/<nav id="seller-navigation".*?<\/nav>/s', $html, $m);
        preg_match_all('/<a href="([^"]+)"[^>]*>(.*?)<\/a>/s', $m[0] ?? '', $links, PREG_SET_ORDER);

        return array_map(fn ($v) => [$v[1], trim(strip_tags($v[2]))], $links);
    }

    public function test_common_menu_and_finance_tabs_are_consistent_and_readonly(): void
    {
        [$tenant,$user,$repo] = $this->operator();
        $base = 'http://'.self::ROOT_HOST;
        $expected = null;
        $counts = fn () => array_map(fn ($e) => $repo->query($e, $tenant->id)->count(), [E::FinanceState, E::Operation, E::Audit, E::Expense, E::FundEntry, E::Report]);
        $before = $counts();
        foreach (['broadcasts', 'broadcasts/channels', 'broadcasts/showhosts', 'products', 'orders', 'ship', 'accounting/overview', 'accounting/sales', 'accounting/expenses', 'accounting/accounts', 'accounting/assets', 'accounting/reports', 'product-stock'] as $section) {
            $response = $this->get($base.'/seller/'.$section)->assertOk();
            $menu = $this->menu($response->getContent());
            $this->assertNotEmpty($menu);
            $expected ??= $menu;
            $this->assertSame($expected, $menu);
            if (str_starts_with($section, 'accounting')) {
                $this->assertMatchesRegularExpression('/href="\\/seller\\/accounting"\\s+aria-current="page"/', $response->getContent());
            }
        }
        $overview = $this->get($base.'/seller/accounting/overview')->assertOk();
        $overview->assertSee('회계 초기 설정이 필요합니다')->assertSee('id="finance-setup"', false)->assertSee('미설정')->assertSee('finance-kpis');
        $this->assertSame($before, $counts());
        $this->postJson($base.'/seller/accounting/actions/activate', ['operation_key' => 'setup-080-invalid', 'reason' => ''])->assertUnprocessable();
        $this->assertSame($before, $counts());
        $this->postJson($base.'/seller/accounting/actions/activate', ['operation_key' => 'setup-080', 'reason' => '합성 UI 검증 적용 시작'])->assertOk();
        $this->assertSame(1, $repo->query(E::FinanceState, $tenant->id)->count());
        $this->get($base.'/seller/accounting')->assertOk()->assertSee('적용 중')->assertDontSee('id="finance-setup"', false);
    }

    public function test_sales_staff_keeps_one_menu_and_cannot_open_cost_or_setup(): void
    {
        [$tenant,$user,$repo] = $this->operator('sales');
        $base = 'http://'.self::ROOT_HOST;
        $expected = null;
        foreach (['broadcasts', 'products', 'orders', 'accounting/sales'] as $section) {
            $r = $this->get($base.'/seller/'.$section)->assertOk();
            $menu = $this->menu($r->getContent());
            $expected ??= $menu;
            $this->assertSame($expected, $menu);
        }
        $this->get($base.'/seller/accounting/sales')->assertOk()->assertDontSee('finance-kpis')->assertDontSee('data-finance-open="finance-setup"', false);
        $this->get($base.'/seller/accounting/overview')->assertForbidden();
        $this->postJson($base.'/seller/accounting/actions/activate', ['operation_key' => 'sales-setup', 'reason' => '권한 없음'])->assertForbidden();
        $this->assertSame(0, $repo->query(E::FinanceState, $tenant->id)->count());
        $this->assertSame('회계관리', LiveSellerNavigation::menu(StaffRole::Sales)['accounting/sales'][0]);
        $this->assertSame('상품 관리', LiveSellerNavigation::menu(StaffRole::Warehouse)['products'][0]);
        $this->assertSame('accounting', LiveSellerNavigation::active('seller/accounting/reports/example'));
    }

    public function test_display_grouping_does_not_convert_or_round_money(): void
    {
        $this->assertSame('999,999,999,999.99', LiveDisplay::number('999999999999.99'));
        $this->assertSame('-1,234.567890', LiveDisplay::number('-1234.567890'));
        $this->assertSame('0.00', LiveDisplay::number('0.00'));
        $this->assertSame('미확정', LiveDisplay::number(null));
    }

    public function test_currency_breakdown_uses_stored_amounts_reversals_and_existing_cost_scope(): void
    {
        [$tenant,$user,$repo] = $this->operator();
        app(LiveFinanceSetupService::class)->activate($tenant->id, $user->id, ['operation_key' => 'dashboard-start', 'reason' => '격리 통화 집계 검증']);
        $expenses = app(LiveExpenseService::class);
        $category = $repo->query(E::ExpenseCategory, $tenant->id)->where('code', 'rent')->firstOrFail();
        $date = now($tenant->timezone)->format('Y-m-d');
        $make = function ($key, $currency, $amount, $rate, $extra = []) use ($tenant, $user, $expenses, $category, $date) {
            return $expenses->expense($tenant->id, $user->id, array_replace(['operation_key' => $key, 'category_id' => $category->id, 'occurred_on' => $date, 'currency' => $currency, 'amount_original' => $amount, 'exchange_rate' => $rate, 'rate_date' => $date, 'rate_source' => 'synthetic saved rate', 'payment_status' => 'unpaid'], $extra));
        };
        $make('cny-a', 'CNY', '100', '190');
        $make('cny-b', 'CNY', '100', '210');
        $make('cny-round-a', 'CNY', '0.01', '150');
        $make('cny-round-b', 'CNY', '0.01', '150');
        $void = $make('cny-void', 'CNY', '50', '200');
        $expenses->void($tenant->id, $user->id, ['operation_key' => 'void-test', 'expense_id' => $void['expense_id'], 'occurred_on' => $date, 'reason' => '역분개 통화 집계 검증']);
        $make('krw', 'KRW', '12345', '1');
        $other = LiveTenant::create(['slug' => 'currency-other', 'name' => '격리 다른 업체', 'status' => 'active', 'timezone' => 'Asia/Seoul']);
        $expenses->defaults($other->id);
        $otherCategory = $repo->query(E::ExpenseCategory, $other->id)->where('code', 'rent')->firstOrFail();
        $expenses->expense($other->id, 0, ['operation_key' => 'other-currency', 'category_id' => $otherCategory->id, 'occurred_on' => $date, 'currency' => 'CNY', 'amount_original' => '999', 'exchange_rate' => '999', 'rate_date' => $date, 'rate_source' => 'synthetic', 'payment_status' => 'unpaid']);
        $reports = app(LiveFinanceReportService::class);
        $ui = app(LiveFinanceDashboardService::class);
        foreach (['day', 'week', 'month', 'custom'] as $period) {
            $r = $reports->report($tenant->id, $user->id, $date, $date, $period);
            $d = $ui->forReport($tenant->id, $r);
            $this->assertTrue($d['reconciled']);
            $this->assertSame(20002, $d['totals']['CNY']['original_minor']);
            $this->assertSame(40004, $d['totals']['CNY']['known_krw']);
            $this->assertSame(12345, $d['totals']['KRW']['known_krw']);
            $this->assertSame(array_keys($r['periods']), array_keys($d['periods']));
            $this->assertSame(52349, $r['totals']['fixed'] + $r['totals']['variable']);
            $this->assertSame(40004, array_sum(array_map(fn ($v) => $v['CNY']['known_krw'], $d['periods'])));
        }
        $purchase = $repo->query(E::ExpenseCategory, $tenant->id)->where('code', 'purchase')->firstOrFail();
        $make('cny-purchase', 'CNY', '999', '200', ['category_id' => $purchase->id]);
        $pending = $make('cny-pending', 'CNY', '12.34', '1');
        // 기존 CNY 정기 지출이 만드는 환율 대기 상태의 격리 fixture.
        $repo->query(E::Expense, $tenant->id)->whereKey($pending['expense_id'])->update(['status' => 'pending', 'amount_krw' => null, 'exchange_rate' => null, 'rate_date' => null, 'rate_source' => 'pending']);
        $r = $reports->report($tenant->id, $user->id, $date, $date);
        $d = $ui->forReport($tenant->id, $r);
        $this->assertSame(21236, $d['totals']['CNY']['original_minor']);
        $this->assertSame(40004, $d['totals']['CNY']['known_krw']);
        $this->assertSame(1, $d['totals']['CNY']['unknown_rows']);
        $this->assertTrue($d['reconciled']);
        $this->assertNull($r['totals']['management_subtotal']);
        $this->assertSame(0, $repo->query(E::FundEntry, $tenant->id)->where('kind', 'payment')->count());
        $expenses->void($tenant->id, $user->id, ['operation_key' => 'pending-void', 'expense_id' => $pending['expense_id'], 'occurred_on' => $date, 'reason' => '미확정 지출 역분개 검증']);
        $afterVoid = $ui->forReport($tenant->id, $reports->report($tenant->id, $user->id, $date, $date));
        $this->assertSame(0, $afterVoid['totals']['CNY']['unknown_rows']);
        $this->assertSame(20002, $afterVoid['totals']['CNY']['original_minor']);
        $this->assertSame(40004, $afterVoid['totals']['CNY']['known_krw']);
        $this->get('http://'.self::ROOT_HOST.'/seller/accounting/overview?from='.$date.'&to='.$date)->assertOk()->assertSee('현재 전체 주문 잔액')->assertSee('미확정')->assertSee('KRW 운영비용')->assertSee('계산 기준 보기');
    }
}
