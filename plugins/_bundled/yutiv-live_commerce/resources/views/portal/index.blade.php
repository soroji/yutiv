@extends('teewide::layouts.teewide', ['title' => $sellerBrand.' — 골프 라이브', 'area' => 'portal', 'footerNote' => ''])
@include('teewide::partials.main-styles', ['golfLanding' => true])
@section('header')
    @include('teewide::partials.portal-header', ['teeWideUser' => $teeWideUser, 'golfLanding' => true])
@endsection
@section('content')
    <section class="tw-golf-hero" aria-labelledby="tw-golf-title">
        <p class="tw-golf-eyebrow">{{ $sellerBrand }} LIVE</p>
        <h1 class="tw-golf-title" id="tw-golf-title">
            <span>방송으로 만나는 상품,</span>
            <strong>더 가까이 즐기는 라이브.</strong>
        </h1>
        <p class="tw-golf-description">관심 있는 상품과 이야기를<br>{{ $sellerBrand }} 라이브에서 만나보세요.</p>
        <div class="tw-golf-actions">
            <a class="tw-golf-action tw-golf-action--live" href="{{ $liveUrl }}">
                <span>라이브 보러가기</span><span aria-hidden="true">↗</span>
            </a>
            @if ($teeWideUser === null)
                <a class="tw-golf-action tw-golf-action--account" href="{{ \Plugins\Yutiv\LiveCommerce\Support\TeeWidePresenter::route('teewide.register') }}">
                    <span>회원가입</span><span aria-hidden="true">→</span>
                </a>
            @else
                <a class="tw-golf-action tw-golf-action--account" href="{{ \Plugins\Yutiv\LiveCommerce\Support\TeeWidePresenter::route('teewide.account') }}">
                    <span>내 계정</span><span aria-hidden="true">→</span>
                </a>
            @endif
        </div>
        @if(count($sellingBroadcasts ?? []))<div class="tw-golf-selling" aria-label="현재 판매 중인 방송"><p>현재 판매 중인 방송</p>@foreach($sellingBroadcasts as $broadcast)<a href="{{ $portalUrl }}/shop/broadcasts/{{ $broadcast->id }}">{{ $broadcast->name }} · 상품 보기 →</a>@endforeach</div>@endif
    </section>
@endsection
