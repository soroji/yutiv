<h3>발생일 기준 기간 장부 (금액: 원, 수량: 개)</h3>
@include('teewide::seller.ledger-purchases')
<p>주문은 생성일, 입금은 수동 누적 확인액의 변경 기록일·차액 기준입니다. 검토 중 확인액을 포함하며 검토 완료는 재입금이 아닙니다. 취소·반품은 발생 기록일, 환불은 환불 기록일 기준입니다. 환불 기록은 은행 송금 완료를 의미하지 않습니다. 입금액을 회계상 매출로 인식하거나 차감 기록을 중복 공제하지 않습니다.</p>
@if($ledger['product_id'])<p>상품 조건 적용: 주문액·취소·반품·재고는 선택 상품 기준. 입금·환불·현재 미입금은 해당 상품을 포함한 주문 전체 금액이며 상품별로 임의 배분하지 않습니다.</p>@endif
<div class="live-table-wrap"><table class="live-table"><thead><tr><th>기간</th>@foreach(['주문 수량','주문 발생액','입금 확인액 변동','취소 수량','취소 금액','반품 수량','반품 금액','환불 기록','판매 출고'] as $label)<th>{{ $label }}</th>@endforeach</tr></thead><tbody>
@forelse($ledger['periods'] as $date=>$values)<tr><th>{{ $date }}</th>@foreach(['quantity','ordered','deposit','cancel_quantity','cancel','return_quantity','return','refund','shipped'] as $field)<td>{{ number_format($values[$field]) }}</td>@endforeach</tr>@empty<tr><td colspan="10">기간 내 발생 기록이 없습니다.</td></tr>@endforelse
</tbody><tfoot><tr data-period-total><th>조회 조건 전체 합계</th>@foreach(['quantity','ordered','deposit','cancel_quantity','cancel','return_quantity','return','refund','shipped'] as $field)<td>{{ number_format(array_sum(array_column($ledger['periods'],$field))) }}</td>@endforeach</tr></tfoot></table></div>
