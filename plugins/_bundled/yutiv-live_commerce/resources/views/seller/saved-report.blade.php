@extends('teewide::layouts.operations')
@section('content')
@php($ledger = $report->result)
<p><a href="/seller/ledger">장부 조회 및 저장 목록</a></p>
<article class="live-ledger" data-saved-report="{{ $report->uuid }}">
<h1>{{ $ledger['tenant_name'] }} · 저장 운영 보고서</h1>
<p>보고서 버전 {{ $report->uuid }} · 생성 {{ $ledger['generated_at'] }} · 생성자 {{ $ledger['creator']['name'] }} (#{{ $ledger['creator']['id'] }})</p>
<p>{{ $ledger['start'] }} 이상 ~ {{ $ledger['end'] }} 미만 · {{ $ledger['timezone'] }} · 기준 {{ $ledger['basis_version'] }} · 상품 조건 {{ $ledger['product_id'] ?? '전체' }}</p>
<p>정식 회계 마감·세무 장부가 아닌 업무 기록입니다. 입금 변동, 취소·반품, 환불은 각각 표시하며 합쳐 차감하지 않습니다. 기간 순매출은 산출하지 않습니다. {{ str_starts_with($ledger['basis_version'],'operational-events-3')?'입고단가 기준 매입금액은 별도로 표시합니다. 재고평가·매출원가·세금·이익 계산은 제공하지 않습니다.':'원가·세금·이익 계산은 제공하지 않습니다.' }}</p>
@include('teewide::seller.ledger-periods')
<h2>전체 조건 합계 및 생성 당시 현재 분석</h2>
<p>현재 미입금 {{ number_format($ledger['outstanding']) }}원 · 입금 완료 주문의 잔여 상품금액 {{ number_format($ledger['sales_totals']['cohort_net_sales']) }}원 (조회 기간 생성 주문의 생성 당시 상태, 환불 중복 차감 없음)</p>
<p>재고 기초 {{ $ledger['stock_totals']['complete'] ? $ledger['stock_totals']['opening'] : '산출 불가/기준 재고 필요' }} · 기말 {{ $ledger['stock_totals']['complete'] ? $ledger['stock_totals']['closing'] : '산출 불가/기준 재고 필요' }}</p>
<p>전체 조건 재고 증감: @foreach(['receive'=>'입고','ship'=>'판매 출고','return'=>'반품 재입고','loss'=>'망실·폐기','as_in'=>'외부 A/S 접수','as_out'=>'외부 A/S 반출','adjustment'=>'조정'] as $field=>$label){{ $label }} {{ $ledger['stock_totals']['complete'] ? $ledger['stock_totals'][$field] : '산출 불가' }} · @endforeach</p>
<p>생성 당시 주문 상태: @foreach($ledger['order_states'] as $state){{ (['pending'=>'미입금','paid'=>'입금 완료','review'=>'입금 검토','expired'=>'예약 만료','cancelled'=>'취소'][$state['payment_state']] ?? $state['payment_state']) }} / {{ (['pending'=>'출고 대기','partial'=>'부분 출고','shipped'=>'출고','delivered'=>'배송 완료','cancelled'=>'취소'][$state['delivery_state']] ?? $state['delivery_state']) }} {{ $state['quantity'] }}건 · @endforeach</p>
@foreach(['channels'=>'채널별 현재 잔여 상품금액','hosts'=>'쇼호스트별 현재 참여 잔여 상품금액 (중복 합산 금지)'] as $key=>$label)<p>{{ $label }}: @foreach($ledger[$key] as $id=>$amount){{ $key==='channels' ? ($ledger['channel_names'][$id] ?? '직접/미지정') : ($ledger['host_names'][$id] ?? '미지정') }} {{ number_format($amount) }}원 · @endforeach</p>@endforeach
<h2>상세 분할 {{ $part }} / {{ $parts }} · 전체 {{ number_format($report->row_count) }}행</h2>
<p>각 분할은 최대 100행입니다. 모든 분할을 출력하면 저장 상세 전체가 포함됩니다. 전체 합계는 모든 분할에서 동일합니다. 아래 순번은 중복·누락 확인용입니다.</p>
<button type="button" class="live-button" data-ledger-print>이 분할 인쇄 / PDF</button>
<nav class="live-report-parts" aria-label="보고서 분할">
@if($part>1)<a href="?part={{ $part-1 }}">이전 분할</a>@endif
@for($i=max(1,$part-3);$i<=min($parts,$part+3);$i++)<a href="?part={{ $i }}" @if($i===$part) aria-current="page" @endif>{{ $i }}</a> @endfor
@if($part<$parts)<a href="?part={{ $part+1 }}">다음 분할</a>@endif
<form method="GET"><label>분할 번호 <input type="number" name="part" min="1" max="{{ $parts }}" value="{{ $part }}"></label><button type="submit">이동</button></form>
</nav>
<table class="live-table live-report-detail"><colgroup><col style="width:6%"><col style="width:16%"><col style="width:78%"></colgroup><thead><tr><th>순번</th><th>종류</th><th>저장 당시 상세 (금액 원 / 수량 개)</th></tr></thead><tbody>
@foreach($rows as $row)<tr data-report-sequence="{{ $row->sequence }}"><td>{{ $row->sequence }}</td><td>{{ ['option'=>'상품·재고·현재 분석','order'=>'주문 발생','event'=>'입금·차감·환불 기록','movement'=>'재고 이동','purchase'=>'입고 매입 기록'][$row->kind] ?? $row->kind }}</td><td>@include('teewide::seller.report-detail')</td></tr>@endforeach
</tbody></table>
<p>분할 {{ $part }}/{{ $parts }} · 저장 보고서 {{ $report->uuid }}</p>
</article>
@endsection
