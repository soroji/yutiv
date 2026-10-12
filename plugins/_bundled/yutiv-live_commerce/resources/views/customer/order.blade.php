@extends('teewide::layouts.operations', ['title' => $sellerBrand.' — 주문 안내'])
@section('content')
<h1>주문·입금·배송 안내</h1><p class="live-product-code">주문 번호 {{ $order->uuid }}</p>
<section class="live-panel"><h2>무통장입금 안내</h2><p class="live-price">주문 당시 총액 {{ number_format($order->total) }}원</p>
@php($due = $order->shipping + $lines->sum(fn ($line) => ($line->quantity - $line->cancelled) * $line->unit_price))
<p>현재 취소를 반영한 안내 금액: <strong>{{ number_format($due) }}원</strong></p>
<p>{{ $order->bank_snapshot['bank'] }} · {{ $order->bank_snapshot['number'] }} · {{ $order->bank_snapshot['holder'] }}</p>
@if($bankNotice ?? null)<aside role="alert"><h3>담당자가 안내한 변경 계좌</h3><p>{{ $bankNotice['bank'] }} · {{ $bankNotice['number'] }} · {{ $bankNotice['holder'] }}</p><p>원래 주문 계좌는 위에 보존했습니다. 이미 이전 계좌에 입금했다면 추가 입금하지 말고 담당자에게 확인해 주세요.</p></aside>@endif
<p>예약 유효시간: {{ $order->expires_at->copy()->setTimezone($tenant->timezone)->format('Y-m-d H:i') }} ({{ $tenant->timezone }})</p>
<p>{{ $order->payment_state === 'cancelled' ? '주문이 취소되었습니다. 입금하지 마세요.' : ($order->payment_state === 'paid' ? '담당자가 입금을 확인했습니다.' : ($order->payment_state === 'review' ? '금액 또는 예약 상태를 검토 중입니다. 자동 출고되지 않습니다.' : ($order->payment_state === 'expired' ? '예약 시간이 만료되었습니다. 늦은 입금은 재고 확인이 필요합니다.' : '입금 확인을 기다리고 있습니다.'))) }}</p>
<p class="live-help">주문 당시 계좌를 보존합니다. 새로운 계좌를 등록해도 이 주문 안내는 자동 변경되지 않습니다.</p></section>
@foreach ($lines as $line)<section class="live-panel"><h2>{{ $line->snapshot['name'] }}</h2><p>{{ $line->snapshot['option_name'] }} · {{ number_format($line->unit_price) }}원 · {{ $line->quantity }}개</p>
<p>출고 {{ $line->shipped }} · 취소 {{ $line->cancelled }} · 반품 {{ $line->returned }}</p>
@if ($line->quantity - $line->shipped - $line->cancelled > 0)<form data-live-form method="POST" action="/orders/{{ $order->uuid }}/cancel">@csrf<input type="hidden" name="line_id" value="{{ $line->id }}"><input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
<div class="live-field"><label for="cancel-qty-{{ $line->id }}">취소 수량</label><input id="cancel-qty-{{ $line->id }}" name="quantity" type="number" min="1" max="{{ $line->quantity - $line->shipped - $line->cancelled }}" value="1" required></div><div class="live-field"><label for="cancel-reason-{{ $line->id }}">취소 사유</label><input id="cancel-reason-{{ $line->id }}" name="reason" required maxlength="1000"></div><button type="submit" class="live-button live-button--quiet">취소 요청</button><p data-live-message class="live-status" role="status"></p></form>@endif
@if ($line->shipped > $line->returned)<form data-live-form method="POST" action="/orders/{{ $order->uuid }}/return">@csrf<input type="hidden" name="operation_key" value="{{ Str::uuid() }}"><input type="hidden" name="line_id" value="{{ $line->id }}"><div class="live-field"><label for="return-reason-{{ $line->id }}">반품 사유</label><input id="return-reason-{{ $line->id }}" name="reason" required maxlength="1000"></div><button type="submit" class="live-button live-button--quiet">반품 상담 요청</button><p data-live-message class="live-status" role="status"></p></form>@endif</section>@endforeach
@if ($order->delivery_state === 'delivered' && !$order->confirmed_at)<form data-live-form method="POST" action="/orders/{{ $order->uuid }}/confirm" class="live-panel">@csrf<input type="hidden" name="operation_key" value="{{ Str::uuid() }}"><p>배송받은 상품을 확인한 뒤 구매확정해 주세요.</p><button type="submit" class="live-button">구매확정</button><p data-live-message class="live-status" role="status"></p></form>@endif
@endsection
