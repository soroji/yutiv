<?php
namespace Plugins\Yutiv\LiveCommerce\Tests\Feature;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Services\LiveStockCountService;
use Plugins\Yutiv\LiveCommerce\Services\LiveReceiptService;
use Plugins\Yutiv\LiveCommerce\Services\LiveInventoryService;
use Symfony\Component\HttpKernel\Exception\HttpException;
class LiveStockCountTest extends TeeWideMainDesignTest {
 public function test_stock_workspace_http_preserves_product_list_and_confirm_history():void {
  [$tenant,$counts,$option,$units]=$this->stock();$accounts=app(\Plugins\Yutiv\LiveCommerce\Services\LiveAccountService::class);$issued=$accounts->issue($tenant->id,0,['name'=>'격리 창고','username'=>'count-operator','role'=>'warehouse']);$user=\Plugins\Yutiv\LiveCommerce\Models\TeeWideUser::findOrFail($issued['user_id']);$accounts->changePassword($user,'Synthetic-only-1234!');$login=$this->post('http://'.self::ROOT_HOST.'/login',['email'=>$issued['username'],'password'=>'Synthetic-only-1234!']);$login->assertRedirect();$this->withUnencryptedCookie('teewide_session',$login->getCookie('teewide_session',false)->getValue());
  $this->get('http://'.self::ROOT_HOST.'/seller/products')->assertOk()->assertDontSee('href="/seller/inventory"',false)->assertSee('실사·조정');
  $this->get('http://'.self::ROOT_HOST.'/seller/product-stock?option_id='.$option->id.'&location='.rawurlencode('격리 선반'))->assertOk()->assertSee('초안 저장');
  $d=$this->draft($option->id,[$units[0]->uuid]);$d['list']='search=COUNT&page=2';$result=$this->withCredentials()->postJson('http://'.self::ROOT_HOST.'/seller/product-stock/draft',$d)->assertOk()->json('data');
  $this->get('http://'.self::ROOT_HOST.$result['return_url'])->assertOk()->assertSee('search=COUNT&amp;page=2',false)->assertSee('시스템 2개');
  $this->postJson('http://'.self::ROOT_HOST.'/seller/product-stock/confirm',['operation_key'=>'http-confirm','id'=>$result['count_id'],'revision'=>1])->assertOk();$this->get('http://'.self::ROOT_HOST.'/seller/product-stock?count='.$result['count_id'])->assertOk()->assertSee('확정');
  $received=$this->postJson('http://'.self::ROOT_HOST.'/seller/receipt',['operation_key'=>'empty-list-cny','list'=>'','mode'=>'existing','product_id'=>$option->product_id,'location'=>'CNY shelf','currency'=>'CNY','exchange_rate'=>'3333.333333','rate_date'=>now($tenant->timezone)->format('Y-m-d'),'rate_source'=>'Synthetic manual','items'=>[['option_id'=>$option->id,'name'=>$option->name,'price'=>10000,'discount'=>0,'quantity'=>3,'unit_cost'=>'0.01']]])->assertOk()->json('data');
  $this->assertSame('/seller/products?completed='.$received['receipt_id'],$received['return_url']);$this->assertSame(100,(int)$counts->query(E::ReceiptItem,$tenant->id)->where('receipt_id',$received['receipt_id'])->sum('purchase_amount'));
  $latest=$counts->query(E::Unit,$tenant->id)->where('option_id',$option->id)->orderByDesc('id')->first();$this->get('http://'.self::ROOT_HOST.'/seller/product-stock?unit='.$latest->uuid)->assertOk()->assertSee('개별 물품 이력')->assertSee('/seller/tasks/transition',false)->assertSee($latest->uuid);
  $this->postJson('http://'.self::ROOT_HOST.'/seller/tasks/transition',['operation_key'=>'selected-unit-defective','qr'=>$latest->uuid,'state'=>'defective','location'=>'CNY shelf','reason'=>'Identified quality inspection'])->assertOk();$this->get('http://'.self::ROOT_HOST.'/seller/product-stock?unit='.$latest->uuid)->assertOk()->assertSee('Identified quality inspection');
 }
 private function stock():array {
  $tenant=$this->seedGolfifTenant();$result=app(LiveReceiptService::class)->receive($tenant->id,0,['operation_key'=>'stock-count-input','mode'=>'new','name'=>'실사 합성 상품','code'=>'COUNT-060','published'=>false,'sellable'=>true,'location'=>'격리 선반','items'=>[['name'=>'기본','price'=>10000,'discount'=>0,'unit_cost'=>6000,'quantity'=>2]]]);
  $counts=app(LiveStockCountService::class);$option=$counts->query(E::Option,$tenant->id)->where('product_id',$result['product_id'])->first();$units=$counts->query(E::Unit,$tenant->id)->where('option_id',$option->id)->orderBy('id')->get();return [$tenant,$counts,$option,$units];
 }
 private function draft(int $option,array $qrs,int $quantity=1):array {return ['operation_key'=>'count-draft','option_id'=>$option,'location'=>'격리 선반','counted_quantity'=>$quantity,'qrs'=>$qrs,'target_state'=>'lost','reason'=>'합성 실사에서 식별한 부족'];}
 public function test_count_draft_confirm_and_retries_keep_original_quantity_and_qr():void {
  [$tenant,$counts,$option,$units]=$this->stock();$d=$this->draft($option->id,[$units[0]->uuid]);$result=$counts->draft($tenant->id,0,$d);$this->assertSame(2,$counts->query(E::StockCount,$tenant->id)->find($result['count_id'])->system_quantity);$this->assertSame('available',$units[0]->fresh()->state);
  $confirmed=$counts->confirm($tenant->id,0,$result['count_id'],1,'count-confirm');$this->assertSame(-1,$confirmed['difference']);$this->assertSame($confirmed,$counts->confirm($tenant->id,0,$result['count_id'],1,'count-confirm'));$this->assertSame('lost',$units[0]->fresh()->state);$this->assertSame('available',$units[1]->fresh()->state);
  $saved=$counts->query(E::StockCount,$tenant->id)->find($result['count_id']);$this->assertSame(2,$saved->adjustments['system_quantity']);$this->assertSame($units[0]->uuid,$saved->adjustments['units'][0]['uuid']);
 }
 public function test_receiving_during_count_requires_recheck_before_any_adjustment():void {
  [$tenant,$counts,$option,$units]=$this->stock();$draft=$counts->draft($tenant->id,0,$this->draft($option->id,[$units[0]->uuid]));app(LiveInventoryService::class)->receive($tenant->id,0,$option->id,1,'격리 선반','concurrent-intake');
  try{$counts->confirm($tenant->id,0,$draft['count_id'],1,'stale-confirm');$this->fail('실사 충돌 누락');}catch(HttpException $e){$this->assertSame(409,$e->getStatusCode());}$this->assertSame('available',$units[0]->fresh()->state);$this->assertSame('draft',$counts->query(E::StockCount,$tenant->id)->find($draft['count_id'])->status);
 }
 public function test_count_never_assigns_difference_to_unidentified_or_reserved_units():void {
  [$tenant,$counts,$option,$units]=$this->stock();$units[0]->update(['state'=>'reserved']);app(LiveInventoryService::class)->movement($units[0],0,'reserve-test','reserve',['state'=>'available','location'=>'격리 선반'],'합성 주문 예약');$draft=$counts->draft($tenant->id,0,$this->draft($option->id,[$units[0]->uuid]));
  try{$counts->confirm($tenant->id,0,$draft['count_id'],1,'reserved-confirm');$this->fail('예약 보호 누락');}catch(HttpException $e){$this->assertSame(409,$e->getStatusCode());}$this->assertSame('reserved',$units[0]->fresh()->state);
  $d=$this->draft($option->id,[],3);$d['operation_key']='unidentified-surplus';$d['target_state']='available';$draft=$counts->draft($tenant->id,0,$d);try{$counts->confirm($tenant->id,0,$draft['count_id'],1,'unknown-confirm');$this->fail('미식별 초과 배정');}catch(HttpException $e){$this->assertSame(409,$e->getStatusCode());}$this->assertSame(2,$counts->query(E::Unit,$tenant->id)->where('option_id',$option->id)->count());
 }
}
