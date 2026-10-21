<?php
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Plugins\Yutiv\LiveCommerce\Contracts\LiveRepositoryInterface;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Models\{LiveTenant,TeeWideUser};
use Plugins\Yutiv\LiveCommerce\Services\LiveAccountService;
$root=dirname(__DIR__,5);require $root.'/vendor/autoload.php';$app=require $root.'/bootstrap/app.php';$app->make(Kernel::class)->bootstrap();
if(basename(base_path())!=='live-accounting060-browser')throw new RuntimeException('isolated checkout required');
$db=DB::connection();foreach([$db->getPdo(),$db->getReadPdo()] as $pdo){$scope=$pdo->query('SELECT DATABASE() db,@@port port')->fetch(PDO::FETCH_ASSOC);if($scope['db']!=='live_accounting060_isolated_browser'||(int)$scope['port']!==33319)throw new RuntimeException('isolated DB required');}
$repo=app(LiveRepositoryInterface::class);$accounts=app(LiveAccountService::class);
$empty=LiveTenant::firstOrCreate(['slug'=>'ui-empty080'],['name'=>'빈 데이터 검증','status'=>'active','timezone'=>'Asia/Seoul']);
$domain=$repo->query(E::Domain,$empty->id)->where('host','empty080.test')->first();if(!$domain)$repo->create(E::Domain,$empty->id,['host'=>'empty080.test','active'=>true]);
foreach([[$empty,'ui-empty-owner','owner'],[LiveTenant::where('slug','golfif')->firstOrFail(),'ui-sales080','sales']] as [$tenant,$name,$role]){
 if(!TeeWideUser::where('tenant_id',$tenant->id)->where('username',$name)->exists()){$issued=$accounts->issue($tenant->id,0,['name'=>'격리 UI '.$role,'username'=>$name,'role'=>$role]);$accounts->changePassword(TeeWideUser::findOrFail($issued['user_id']),'Synthetic-only-1234!');}
}
$counts=[];foreach([E::FinanceState,E::Operation,E::Audit,E::Expense,E::FundEntry,E::Report] as $e)$counts[$e->value]=$repo->query($e,$empty->id)->count();
echo json_encode(['empty_tenant'=>$empty->id,'counts'=>$counts],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
