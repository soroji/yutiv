@extends('teewide::layouts.teewide', ['title' => 'TeeWide — 라이브로 만나다', 'area' => 'portal', 'footerNote' => '첫 방송과 판매자 등록을 준비하고 있습니다.'])
@include('teewide::partials.main-styles')
@section('header')
    @include('teewide::partials.portal-header', ['teeWideUser' => $teeWideUser])
@endsection
@section('content')
    <section class="tw-hero">
        <div class="tw-wrap tw-hero__grid">
            <div>
                <p class="tw-hero__eyebrow">A CLOSER CONNECTION, LIVE</p>
                <h1 class="tw-hero__title">라이브로 만나다.<br><em>더 가까워지는 순간.</em></h1>
                <p class="tw-hero__lead">화면 너머의 이야기를 만나고,<br>궁금한 순간에 함께 대화하세요.</p>
                <div class="tw-hero__cta">
                    <a class="tw-btn tw-btn--primary" href="{{ $liveUrl }}">라이브 둘러보기 ↗</a>
                    @if ($teeWideUser === null)
                        <a class="tw-btn tw-btn--ghost" href="{{ route('teewide.register') }}">TeeWide 시작하기</a>
                    @else
                        <a class="tw-btn tw-btn--ghost" href="{{ route('teewide.account') }}">내 계정</a>
                    @endif
                </div>
                <p class="tw-hint" id="tw-cta-note">첫 방송을 준비하고 있습니다. 편성이 확정되면 안내합니다.</p>
            </div>
            @include('teewide::partials.broadcast-preview')
        </div>
    </section>
    <div class="tw-wrap">
        <section class="tw-service-strip" aria-label="TeeWide 소개">
            <article><span>01 / WATCH</span><h2>이야기가 있는 방송</h2><p>방송과 판매자의 이야기를 한 화면에서.</p></article>
            <article><span>02 / CONNECT</span><h2>실시간으로 가까워지다</h2><p>보고, 궁금해하고, 대화하는 라이브 경험.</p></article>
            <article><span>03 / COMING NEXT</span><h2>첫 만남을 준비합니다</h2><p>지금은 준비 중입니다. 다음 방송을 기다려 주세요.</p></article>
        </section>
    </div>
@endsection
