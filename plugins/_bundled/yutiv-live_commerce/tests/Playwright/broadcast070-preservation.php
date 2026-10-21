<?php
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity;
$root=dirname(__DIR__,5);require $root.'/vendor/autoload.php';$app=require $root.'/bootstrap/app.php';$app->make(Kernel::class)->bootstrap();
if(basename(base_path())!=='live-accounting060-browser')throw new RuntimeException('isolated checkout required');
$db=DB::connection();foreach([$db->getPdo(),$db->getReadPdo()] as $pdo){$scope=$pdo->query('SELECT DATABASE() db,@@port port')->fetch(PDO::FETCH_ASSOC);if($scope['db']!=='live_accounting060_isolated_browser'||(int)$scope['port']!==33319)throw new RuntimeException('isolated DB required');}
$hashes=[];foreach(LiveEntity::cases() as $entity){$hash=hash_init('sha256');$count=0;DB::table($entity->value)->orderBy('id')->chunkById(1000,function($rows)use($hash,&$count){foreach($rows as $row){hash_update($hash,json_encode($row,JSON_THROW_ON_ERROR)."\n");$count++;}});$hashes[$entity->value]=['rows'=>$count,'sha256'=>hash_final($hash)];}
$file=base_path('broadcast070-before-hashes.json');if(($argv[1]??'')==='before'){file_put_contents($file,json_encode($hashes,JSON_THROW_ON_ERROR));echo "Synthetic 0.6.0 baseline hashes saved\n";}else{$before=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);if($before!==$hashes)throw new RuntimeException('preservation mismatch');echo json_encode(['from'=>'0.6.0','to'=>'0.7.0','all_live_entity_tables_unchanged'=>true,'tables'=>$hashes],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);}
