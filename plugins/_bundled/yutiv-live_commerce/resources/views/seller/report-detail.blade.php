@php($item=$row->data)
@if($row->kind==='purchase')
    <strong>입고 당시 매입 기록 · 입고 #{{ $item['receipt_id'] }} · 옵션 #{{ $item['option_id'] }}</strong><p>입고단가 {{ $item['unit_cost']===null?'미등록':number_format($item['unit_cost']).'원' }} × {{ $item['quantity'] }}개 · 매입금액 {{ $item['purchase_amount']===null?'미등록':number_format($item['purchase_amount']).'원' }} · {{ $item['currency'] }} (매출원가 아님)</p>
@elseif($row->kind==='option')
    <strong>{{ $item['stock']['code'] }} · {{ $item['stock']['product'] }} · {{ $item['stock']['option'] }}</strong>
    <p>재고 @foreach(['opening'=>'기초','receive'=>'입고','ship'=>'출고','return'=>'반품','loss'=>'망실·폐기','as_in'=>'A/S 접수','as_out'=>'A/S 반출','adjustment'=>'조정','closing'=>'기말'] as $field=>$label){{ $label }} {{ $item['stock']['complete'] ? $item['stock'][$field] : '산출 불가' }} · @endforeach</p>
    <p>주문 {{ $item['sales']['quantity'] }}개 / {{ number_format($item['sales']['ordered']) }}원 · 취소 {{ $item['sales']['cancel_quantity'] }}개 / {{ number_format($item['sales']['cancel']) }}원 · 반품 {{ $item['sales']['return_quantity'] }}개 / {{ number_format($item['sales']['return']) }}원 · 출고 {{ $item['sales']['shipped'] }}개 · 현재 잔여 상품금액 {{ number_format($item['sales']['cohort_net_sales']) }}원</p>
    <p>생성 당시 상태: @foreach($item['stock']['states'] as $state=>$quantity){{ ['available'=>'판매 가능','reserved'=>'예약','shipped'=>'출고','as'=>'A/S','defective'=>'불량','returned'=>'반품 대기','lost'=>'망실','disposed'=>'폐기'][$state] ?? $state }} {{ $quantity }}개 · @endforeach</p>
@elseif($row->kind==='order')
    <strong>주문 {{ $item['order']['uuid'] }} · {{ $item['order']['created_at'] }}</strong>
    <p>항목 #{{ $item['line']['id'] }} · 옵션 #{{ $item['line']['option_id'] }} · {{ $item['line']['quantity'] }}개 × {{ number_format($item['line']['unit_price']) }}원 · 주문액 {{ number_format($item['line']['quantity']*$item['line']['unit_price']) }}원</p>
    <p>생성 당시 주문 상태 {{ (['pending'=>'미입금','paid'=>'입금 완료','review'=>'입금 검토','expired'=>'예약 만료','cancelled'=>'취소'][$item['order']['payment_state']] ?? $item['order']['payment_state']) }} / {{ (['pending'=>'출고 대기','partial'=>'부분 출고','shipped'=>'출고','delivered'=>'배송 완료','cancelled'=>'취소'][$item['order']['delivery_state']] ?? $item['order']['delivery_state']) }} · 누적 취소 {{ $item['line']['cancelled'] }}개 · 누적 반품 {{ $item['line']['returned'] }}개</p>
@elseif($row->kind==='event')
    <strong>{{ ['deposit'=>'입금 누적 확인','deposit_review'=>'입금 검토 완료 (입금 변동 제외)','cancel'=>'취소','return'=>'반품','refund'=>'환불 기록','bank_correction'=>'계좌 안내 정정','attribution_correction'=>'판매 귀속 정정'][$item['type']] ?? $item['type'] }} · {{ $item['created_at'] }}</strong>
    <p>기록 #{{ $item['id'] }} · 주문 #{{ $item['order_id'] }} · 기록액 {{ number_format($item['amount']) }}원 · 기간 집계 {{ $item['included_in_period_totals'] ? '포함' : '제외 (참고 기록/다른 상품)' }}
    @if($item['type']==='deposit') · 이전 누적 확인 {{ number_format($item['previous_confirmation']['amount'] ?? 0) }}원 · 입금 변동 {{ number_format($item['deposit_delta']) }}원 @endif
    @if(isset($item['data']['line_id'])) · 항목 #{{ $item['data']['line_id'] }} · {{ $item['data']['quantity'] ?? 0 }}개 @endif</p>
    @if(isset($item['data']['reason']))<p>사유 {{ $item['data']['reason'] }}</p>@endif
@elseif($row->kind==='movement')
    <strong>재고 이동 #{{ $item['id'] }} · {{ $item['created_at'] }} · {{ ['receive'=>'입고','reserve'=>'예약','rereserve'=>'재예약','cancel'=>'예약 해제','expire'=>'예약 만료 해제','ship'=>'판매 출고','return'=>'반품 재입고','move'=>'위치 이동','adjust'=>'재고 조정','correction'=>'원장 정정','as_receive'=>'A/S 접수','as_dispatch'=>'A/S 반출'][$item['type']] ?? $item['type'] }}</strong>
    <p>물품 #{{ $item['unit_id'] }} · {{ $item['from_state'] ?? '최초 입고' }} → {{ $item['to_state'] }} · {{ $item['from_location'] ?? '' }} → {{ $item['to_location'] }} · {{ $item['reason'] }}</p>
@endif
