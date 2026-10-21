@extends('teewide::layouts.operations', ['title' => $sellerBrand.' — 라이브 상품'])
@section('content')
<h1>라이브 상품</h1>
@if ($broadcasts->isEmpty())<section class="live-empty"><h2>다음 라이브를 준비하고 있습니다</h2><p>판매가 시작되면 방송과 상품을 여기에서 확인할 수 있습니다.</p></section>@endif
@foreach ($broadcasts as $broadcast)<section class="live-panel"><h2>{{ $broadcast->name }}</h2><span class="live-badge">판매 중</span>
<p>{{ $broadcast->sale_ends_at->copy()->setTimezone($tenant->timezone)->format('m월 d일 H:i') }}까지 판매</p>
@if ($broadcast->video_url)<a class="live-button live-button--quiet" href="{{ $broadcast->video_url }}" target="_blank" rel="noopener noreferrer">유튜브 방송 보기</a>@endif</section>@endforeach
@if ($cards === [] && $broadcasts->isNotEmpty())<p class="live-empty">현재 노출할 판매 상품이 없습니다.</p>@endif
<div class="live-grid">@foreach ($cards as $card)<article class="live-panel live-card"><p class="live-product-code">{{ $card['broadcast']->name }}</p><h2>{{ $card['product']->name }}</h2>
@if ($card['product']->image_path)<img src="/live-media/product/{{ $card['product']->id }}" alt="{{ $card['product']->name }}">@endif
<p>{{ $card['option']->name }}</p><p class="live-price">{{ number_format($card['option']->price - $card['option']->discount) }}원</p><p>{{ $card['product']->description }}</p>
<form data-live-form method="POST" action="/customer/tasks/cart">@csrf
<input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><input type="hidden" name="broadcast_id" value="{{ $card['broadcast']->id }}"><input type="hidden" name="option_id" value="{{ $card['option']->id }}">
@if ($saleCode)<input type="hidden" name="code" value="{{ $saleCode }}">@endif
<div class="live-field"><label for="quantity-{{ $card['broadcast']->id }}-{{ $card['option']->id }}">수량</label><input id="quantity-{{ $card['broadcast']->id }}-{{ $card['option']->id }}" name="quantity" type="number" min="1" max="200" value="1" required></div>
<button type="submit" class="live-button" @disabled($card['available']<1)>{{ $card['available']<1 ? '품절' : '장바구니에 담기' }}</button><p class="live-status" data-live-message role="status"></p><a href="/cart">장바구니·주문으로</a>
</form></article>@endforeach</div>
@foreach ($tenant->contact_links ?? [] as $name => $url)@if ($url)<a class="live-button live-button--quiet" href="{{ $url }}" target="_blank" rel="noopener noreferrer">{{ $name === 'kakao' ? '카카오' : '텔레그램' }} 상담</a>@endif @endforeach
@endsection
