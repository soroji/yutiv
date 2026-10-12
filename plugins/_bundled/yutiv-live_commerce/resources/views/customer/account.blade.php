@extends('teewide::layouts.operations', ['title' => $sellerBrand.' — 마이페이지'])
@section('content')
<h1>마이페이지</h1><p>{{ $user->displayName() }} 님, 반갑습니다.</p>
@if($errors->any())<p role="alert">{{ $errors->first() }}</p>@endif
@if(request()->session()->get('brand_saved'))<p role="status">{{ request()->session()->get('brand_saved') }}</p>@endif
@if(request()->session()->get('brand_error'))<p role="alert">{{ request()->session()->get('brand_error') }}</p>@endif
<p>이메일 인증: {{ $user->email_verified_at ? '인증 완료' : '미인증' }}</p>
<section class="live-panel"><h2>내 판매 채널</h2>@forelse($ownedChannels as $channel)<p>{{ $channel['name'] }} · /{{ $channel['slug'] }} · {{ $channel['status_label'] }}</p>@empty<p class="live-help">아직 소유한 채널이 없습니다</p>@endforelse
@if($canOperate)<a href="/seller/brand">고객에게 표시할 브랜드 이름 설정</a>@elseif(count($ownedChannels))<p class="live-help">기존 소유 관계를 보존했습니다. 업무 계정 연결은 본사에서 확인해 주세요.</p>@endif</section>
<div class="live-grid"><section class="live-panel"><h2>계정 정보</h2><p>이메일: {{ $user->email }}</p><p>이름: {{ $user->name }}</p><a href="/password/change">비밀번호 변경</a>
<form data-live-form method="POST" action="/customer/tasks/profile">@csrf
<div class="live-field"><label for="name">이름</label><input name="name" id="name" maxlength="100" value="{{ $user->name }}" required></div>
<div class="live-field"><label for="customs-code">개인통관고유부호</label><input name="customs_code" id="customs-code" placeholder="{{ $customsMask }}" pattern="P[0-9]{12}" maxlength="13" autocomplete="off"><span class="live-help">현재: {{ $customsMask }}. P와 숫자 12자리 형식만 확인합니다. 외부 유효성은 미확인입니다.</span></div>
<button type="submit" class="live-button">기본 정보 저장</button><p class="live-status" data-live-message role="status"></p></form></section>
<section class="live-panel"><h2>배송지 등록</h2><form data-live-form method="POST" action="/customer/tasks/address">@csrf
@foreach (['name' => '받는 분', 'phone' => '연락처', 'postal_code' => '우편번호', 'address' => '배송 주소'] as $field => $label)<div class="live-field"><label for="delivery-{{ $field }}">{{ $label }}</label><input id="delivery-{{ $field }}" name="{{ $field }}" required maxlength="{{ $field === 'address' ? 500 : ($field === 'name' ? 100 : 30) }}"></div>@endforeach
<button type="submit" class="live-button">배송지 등록</button><p class="live-status" data-live-message role="status"></p></form>
@if ($addresses->isEmpty())<p class="live-help">등록한 배송지가 없습니다.</p>@else<ul>@foreach ($addresses as $address)<li>{{ $address->delivery['name'] }} · {{ $address->delivery['address'] }}</li>@endforeach</ul>@endif</section></div>
<section class="live-panel"><h2>나의 주문</h2>@if ($orders->isEmpty())<p class="live-empty">아직 주문 내역이 없습니다. <a href="/shop">라이브 상품 보기</a></p>@else
<div class="live-table-wrap"><table class="live-table"><thead><tr><th>주문일</th><th>주문</th><th>금액</th><th>입금</th><th>배송</th></tr></thead><tbody>@foreach ($orders as $order)<tr><td>{{ $order->created_at->format('Y-m-d') }}</td><td><a href="/orders/{{ $order->uuid }}">주문 확인</a></td><td>{{ number_format($order->total) }}원</td><td>{{ $order->payment_state === 'paid' ? '입금 완료' : ($order->payment_state === 'review' ? '검토 중' : ($order->payment_state === 'expired' ? '예약 만료' : '입금 대기')) }}</td><td>{{ $order->delivery_state === 'delivered' ? '배송 완료' : ($order->delivery_state === 'shipped' ? '출고 완료' : ($order->delivery_state === 'partial' ? '부분 출고' : '출고 대기')) }}</td></tr>@endforeach</tbody></table></div>{{ $orders->links() }}@endif</section>
@if ($canOperate)<a class="live-button" href="/seller">판매자 업무로</a>@endif
@endsection
