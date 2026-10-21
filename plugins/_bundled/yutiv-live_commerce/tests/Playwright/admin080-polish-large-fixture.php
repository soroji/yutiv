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
$repo=app(LiveRepositoryInterface::class);$accounts=app(LiveAccountService::class);
$companies=[];
foreach([['ui-polish-large','큰 금액 UI 합성 검증','large-polish.test','ui-polish-large']] as [$slug,$name,$host,$username]){
 $tenant=LiveTenant::firstOrCreate(['slug'=>$slug],['name'=>$name,'status'=>'active','timezone'=>'Asia/Seoul']);if(!$repo->query(E::Domain,$tenant->id)->where('host',$host)->exists())$repo->create(E::Domain,$tenant->id,['host'=>$host,'active'=>true]);
 $user=TeeWideUser::where('tenant_id',$tenant->id)->where('username',$username)->first();if(!$user){$issued=$accounts->issue($tenant->id,0,['name'=>'격리 UI 담당자','username'=>$username,'role'=>'owner']);$user=TeeWideUser::findOrFail($issued['user_id']);$accounts->changePassword($user,'Synthetic-only-1234!');}$companies[$slug]=[$tenant,$user];
}
[$tenant,$user]=$companies['ui-polish-large'];$t=$tenant->id;$a=$user->id;
$at=function($date){$time=\Carbon\Carbon::parse($date.' 03:00:00','UTC');Date::setTestNow($time);\Carbon\CarbonImmutable::setTestNow($time);};
if(!$repo->query(E::Operation,$t)->where('operation_key','ui-final-fixture-complete')->exists())$repo->transaction(function()use($repo,$t,$a,$at){
 $at('2026-09-30');app(LiveFinanceSetupService::class)->activate($t,$a,['operation_key'=>'final-start','reason'=>'격리 합성 업무 흐름 검증']);
 $funds=app(LiveFundService::class);$krw=$funds->fund($t,$a,['operation_key'=>'final-krw','name'=>'검증 KRW 운영 계좌','currency'=>'KRW','active'=>true])['fund_id'];$cny=$funds->fund($t,$a,['operation_key'=>'final-cny','name'=>'검증 CNY 자금','currency'=>'CNY','active'=>true])['fund_id'];
 foreach([[$krw,'1000000','1'],[$cny,'1000','200']] as [$id,$amount,$rate])$funds->adjust($t,$a,['operation_key'=>'final-opening-'.$id,'fund_id'=>$id,'kind'=>'opening','direction'=>'in','amount_original'=>$amount,'exchange_rate'=>$rate,'occurred_on'=>'2026-09-30','reason'=>'격리 기초 자금']);
 $catalog=app(LiveCatalogService::class);$product=$catalog->save(E::Product,$t,$a,['name'=>'검증 골프 클럽','code'=>'FINAL-SYNTHETIC','published'=>true,'sellable'=>true]);$option=$catalog->save(E::Option,$t,$a,['product_id'=>$product->id,'name'=>'기본','price'=>1000000000,'discount'=>0]);
 $channel=$catalog->save(E::Channel,$t,$a,['name'=>'격리 검증 채널','url'=>'https://www.youtube.com/@synthetic']);$host=$catalog->save(E::Showhost,$t,$a,['name'=>'격리 검증 진행자']);
 $bank=$catalog->save(E::Bank,$t,$a,['bank'=>'합성 은행','account_number'=>'000000000000','holder'=>'검증 예금주','default'=>true,'active'=>true,'fund_id'=>$krw]);
 $broadcast=$catalog->save(E::Broadcast,$t,$a,['name'=>'격리 검증 방송','channel_id'=>$channel->id,'state'=>'selling','starts_at'=>'2026-10-01 00:00:00','ends_at'=>'2026-11-01 00:00:00','sale_starts_at'=>'2026-10-01 00:00:00','sale_ends_at'=>'2026-11-01 00:00:00','channel_rate'=>300,'host_rate'=>500,'host_shares'=>[$host->id=>10000]]);$catalog->save(E::BroadcastItem,$t,$a,['broadcast_id'=>$broadcast->id,'option_id'=>$option->id]);
 $at('2026-10-02');app(LiveReceiptService::class)->receive($t,$a,['operation_key'=>'final-receipt','mode'=>'existing','product_id'=>$product->id,'location'=>'검증 창고','items'=>[['option_id'=>$option->id,'name'=>'기본','price'=>1000000000,'discount'=>0,'quantity'=>3,'unit_cost'=>20000]]]);
 $orders=app(LiveOrderService::class);$at('2026-10-03');$cart=$orders->addCart($t,$a,'final-cart',$broadcast->id,$option->id,1,null,'final-reserve');$created=$orders->create($t,$a,[$cart['line']],['name'=>'합성 고객'],'final-order');$order=$repo->query(E::Order,$t)->where('uuid',$created['order'])->firstOrFail();
 app(LiveCashService::class)->receipt($t,$a,['operation_key'=>'final-deposit','order_id'=>$order->id,'fund_id'=>$krw,'bank_account_id'=>$bank->id,'amount'=>$created['total']+5000,'occurred_on'=>'2026-10-03','reason'=>'합성 입금·예수금 검증']);
 $at('2026-10-04');$line=$repo->query(E::OrderLine,$t)->where('order_id',$order->id)->firstOrFail();$unit=$repo->query(E::Unit,$t)->where('order_line_id',$line->id)->firstOrFail();$orders->ship($t,$a,$order->id,[$unit->uuid],'final-ship');
 $expenses=app(LiveExpenseService::class);$rent=$repo->query(E::ExpenseCategory,$t)->where('code','rent')->firstOrFail();$shipping=$repo->query(E::ExpenseCategory,$t)->where('code','shipping')->firstOrFail();
 $make=function($key,$date,$currency,$amount,$rate,$category)use($expenses,$t,$a,$at){$at($date);return $expenses->expense($t,$a,['operation_key'=>$key,'category_id'=>$category->id,'occurred_on'=>$date,'currency'=>$currency,'amount_original'=>$amount,'exchange_rate'=>$rate,'rate_date'=>$date,'rate_source'=>'격리 저장 환율','payment_status'=>'unpaid','memo'=>'UI 합성 검증']);};
 $first=$make('final-cny-190','2026-10-05','CNY','100','190',$rent);$make('final-cny-210','2026-10-05','CNY','100','210',$rent);$make('final-krw-rent','2026-10-06','KRW','10000','1',$rent);
 $at('2026-10-07');$expenses->pay($t,$a,['operation_key'=>'final-partial-pay','expense_id'=>$first['expense_id'],'fund_id'=>$cny,'paid_on'=>'2026-10-07','amount_original'=>'20']);
 $reverse=$make('final-cny-reverse','2026-10-15','CNY','50','200',$shipping);
 $at('2026-10-16');$cart=$orders->addCart($t,$a,'final-unpaid-cart',$broadcast->id,$option->id,1,null,'final-unpaid-reserve');$orders->create($t,$a,[$cart['line']],['name'=>'합성 미수 고객'],'final-unpaid-order');
 $at('2026-10-18');$expenses->void($t,$a,['operation_key'=>'final-void','expense_id'=>$reverse['expense_id'],'occurred_on'=>'2026-10-18','reason'=>'발생일과 다른 역분개 검증']);
 $make('polish-large-cost','2026-10-20','CNY','123456789.12','999',$rent);
 $repo->create(E::Operation,$t,['operation_key'=>'ui-final-fixture-complete','request_hash'=>hash('sha256','synthetic-only'),'result'=>['done'=>true]]);
});
Date::setTestNow();\Carbon\CarbonImmutable::setTestNow();
[$unset]=$companies['ui-polish-large'];$counts=[];foreach([E::FinanceState,E::Operation,E::Audit,E::Expense,E::FundEntry,E::Report] as $e)$counts[$e->value]=$repo->query($e,$unset->id)->count();
$r=app(LiveFinanceReportService::class)->report($t,$a,'2026-10-01','2026-10-21','day');$d=app(LiveFinanceDashboardService::class)->forReport($t,$r);
echo json_encode(['unset_tenant'=>$unset->id,'unset_counts'=>$counts,'data_tenant'=>$t,'totals'=>$r['totals'],'customers'=>$r['current_analysis']['balances']['customers'],'currency_costs'=>$d['totals'],'flags'=>$r['flags']],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
