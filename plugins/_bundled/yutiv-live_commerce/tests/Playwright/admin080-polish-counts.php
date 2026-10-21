<?php
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Date;
use Plugins\Yutiv\LiveCommerce\Contracts\LiveRepositoryInterface;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Models\{LiveTenant,TeeWideUser};
use Plugins\Yutiv\LiveCommerce\Services\{LiveAccountService,LiveCatalogService,LiveFinanceSetupService,LiveFinanceReportService,LiveFinanceDashboardService,LiveExpenseService,LiveFundService,LiveReceiptService,LiveOrderService,LiveCashService};
$root=dirname(__DIR__,5);require $root.'/vendor/autoload.php';$app=require $root.'/bootstrap/app.php';$app->make(Kernel::class)->bootstrap();
if(basename(base_path())!=='live-accounting060-browser')throw new RuntimeException('isolated checkout required');
$db=DB::connection();foreach([$db->getPdo(),$db->getReadPdo()] as $pdo){$scope=$pdo->query('SELECT DATABASE() db,@@port port')->fetch(PDO::FETCH_ASSOC);if($scope['db']!=='live_accounting060_isolated_browser'||(int)$scope['port']!==33319)throw new RuntimeException('isolated DB required');}
$repo=app(LiveRepositoryInterface::class);$tenant=LiveTenant::where('slug','ui-final-data')->firstOrFail();$counts=[];foreach([E::FinanceState,E::Operation,E::Audit,E::Expense,E::ExpensePayment,E::ExpenseRecovery,E::ExpenseCategory,E::RecurringExpense,E::FundEntry,E::Report,E::ReportRow] as $entity)$counts[$entity->value]=$repo->query($entity,$tenant->id)->count();echo json_encode($counts,JSON_THROW_ON_ERROR);
