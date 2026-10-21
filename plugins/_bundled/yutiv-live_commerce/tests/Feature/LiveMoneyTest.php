<?php
namespace Plugins\Yutiv\LiveCommerce\Tests\Feature;
use Plugins\Yutiv\LiveCommerce\Support\LiveMoney;use Plugins\Yutiv\LiveCommerce\Enums\LiveCurrency;
class LiveMoneyTest extends TeeWideMainDesignTest {
 public function test_currency_conversion_and_partial_allocations_use_exact_decimal_rounding():void {
  foreach([['100','1',LiveCurrency::KRW,100],['100.00','195.123456',LiveCurrency::CNY,19512],['0.01','50',LiveCurrency::CNY,1],['999999999999.99','0.000001',LiveCurrency::CNY,1000000]] as [$original,$rate,$currency,$expected]){$result=LiveMoney::convert($original,$currency,$rate);$this->assertSame($expected,$result['amount_krw']);}
  $payments=[LiveMoney::allocation(100,3,0,1),LiveMoney::allocation(100,3,1,1),LiveMoney::allocation(100,3,2,1)];$this->assertSame([33,34,33],$payments);$this->assertSame(100,array_sum($payments));
  foreach([['1.5','1',LiveCurrency::KRW],['1','2',LiveCurrency::KRW],['-1','195',LiveCurrency::CNY],['1.001','195',LiveCurrency::CNY],['1','0',LiveCurrency::CNY],['01','195',LiveCurrency::CNY]] as [$amount,$rate,$currency]){try{LiveMoney::convert($amount,$currency,$rate);$this->fail('통화 검증 누락');}catch(\DomainException $e){$this->assertNotSame('',$e->getMessage());}}
 }
}
