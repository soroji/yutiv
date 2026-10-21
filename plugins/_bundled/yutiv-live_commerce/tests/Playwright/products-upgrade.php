<?php
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
$root=dirname(__DIR__,5);require $root.'/vendor/autoload.php';$app=require $root.'/bootstrap/app.php';$app->make(Kernel::class)->bootstrap();
if(!str_contains(str_replace('\\','/',base_path()),'/live-products050-browser'))throw new RuntimeException('isolated copy required');
$db=DB::connection();foreach([$db->getPdo(),$db->getReadPdo()] as $pdo){$r=$pdo->query('SELECT DATABASE() db,@@port port')->fetch(PDO::FETCH_ASSOC);if($r['db']!=='live_products050_isolated_browser'||(int)$r['port']!==33319)throw new RuntimeException('isolated DB required');}
$file=base_path('products050-baseline.json');$tables=['live_products','live_options','live_stock_units','live_stock_movements','live_reports','live_report_rows'];$before=($argv[1]??'')==='before';
if($before&&file_exists($file))throw new RuntimeException('baseline exists');
$baseline=$before?[]:json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);$result=[];
foreach($tables as $table){$hash=hash_init('sha256');$count=0;foreach($db->table($table)->orderBy('id')->cursor() as $row){$row=(array)$row;foreach(['category_id','receipt_item_id','attributes'] as $new)unset($row[$new]);hash_update($hash,json_encode($row,JSON_THROW_ON_ERROR));$count++;}$result[$table]=['count'=>$count,'sha256'=>hash_final($hash)];}
if($before){file_put_contents($file,json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));echo "0.4.0 synthetic baseline saved\n";}else{if($baseline!==$result)throw new RuntimeException('existing data changed');foreach(['live_categories','live_receipt_items','live_label_requests','live_label_request_units'] as $table)if(!Schema::hasTable($table))throw new RuntimeException('new migration missing');echo json_encode(['from'=>'0.4.0','to'=>'0.5.0','unchanged'=>$result,'legacy_cost_link_null'=>$db->table('live_stock_units')->whereNotNull('receipt_item_id')->count()===0],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);}
