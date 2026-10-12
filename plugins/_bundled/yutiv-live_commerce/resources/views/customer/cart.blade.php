@extends('teewide::layouts.operations', ['title' => $sellerBrand.' — 장바구니'])
@section('content')
<h1>장바구니</h1>
@if ($lines->isEmpty())<div class="live-empty">아직 담은 상품이 없습니다. <a href="/shop">라이브 상품 보기</a></div>@else
<form data-live-form method="POST" action="/customer/tasks/order" class="live-panel">@csrf<input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
@foreach ($lines as $line)<div class="live-field"><label for="line-{{ $line->uuid }}"><input id="line-{{ $line->uuid }}" type="checkbox" name="lines[]" value="{{ $line->uuid }}" checked> {{ $line->display_option->name }} · {{ $line->quantity }}개 · {{ number_format(($line->display_option->price - $line->display_option->discount) * $line->quantity) }}원</label><span class="live-help">판매 귀속은 이 상품을 담을 때의 링크로 보존됩니다.</span></div>@endforeach
@if (!$teeWideUser)<p>주문하려면 로그인해 주세요. 담은 상품과 판매 귀속은 로그인 후에도 유지됩니다.</p><a class="live-button" href="/login">로그인</a>
@elseif ($addresses->isEmpty())<p>배송지를 먼저 등록해 주세요.</p><a class="live-button" href="/my">배송지 등록</a>
@else<div class="live-field"><label for="address">배송지</label><select name="address_id" id="address" required>@foreach ($addresses as $address)<option value="{{ $address->id }}">{{ $address->delivery['name'] }} · {{ $address->delivery['address'] }}</option>@endforeach</select></div>
<p class="live-help">결제는 무통장입금입니다. 주문 후 안내 계좌와 예약 유효시간을 확인해 주세요. 가격·판매 상태·재고는 주문 시 다시 확인합니다.</p><button type="submit" class="live-button live-button--red">주문하고 입금 안내 보기</button><p class="live-status" data-live-message role="status"></p>@endif</form>@endif
@endsection
