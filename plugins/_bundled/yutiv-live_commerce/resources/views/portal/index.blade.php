@extends('teewide::layouts.teewide', [
    'title' => 'TeeWide — 라이브로 만나다',
    'area' => 'portal',
    'footerNote' => '판매자 등록은 준비 중입니다.',
])

@push('styles')
    .tw-hero { padding: 72px 0; min-height: 55vh; display: flex; align-items: center; }
    .tw-hero__eyebrow { margin: 0 0 18px; }
    .tw-hero__title {
        margin: 0 0 18px;
        font-size: 46px;
        line-height: 1.2;
        letter-spacing: -0.025em;
        max-width: 16ch;
    }
    .tw-hero__lead {
        margin: 0 0 30px;
        font-size: 18px;
        color: var(--tw-muted);
        max-width: 54ch;
    }
    .tw-hero__cta { display: flex; gap: 12px; flex-wrap: wrap; }

    @media (max-width: 640px) {
        .tw-hero { padding: 56px 0 48px; }
        .tw-hero__title { font-size: 32px; }
        .tw-hero__lead { font-size: 16px; }
        .tw-hero__cta .tw-btn { flex: 1 1 100%; }
    }
@endpush

@section('header')
    @include('teewide::partials.portal-header', ['teeWideUser' => $teeWideUser])
@endsection

@section('content')
    <section class="tw-hero">
        <div class="tw-wrap">
            <p class="tw-hero__eyebrow">
                <span class="tw-badge"><span class="tw-badge__dot" aria-hidden="true"></span>서비스 준비 중</span>
            </p>

            <h1 class="tw-hero__title">라이브로 만나다</h1>

            <p class="tw-hero__lead">
                방송을 보고, 판매자와 이야기하세요.
            </p>

            <div class="tw-hero__cta">
                <a class="tw-btn tw-btn--primary" href="{{ $liveUrl }}">라이브 보러가기</a>
                @if ($teeWideUser === null)
                    <a class="tw-btn tw-btn--ghost" href="{{ route('teewide.register') }}">회원가입</a>
                @else
                    <a class="tw-btn tw-btn--ghost" href="{{ route('teewide.account') }}">마이페이지</a>
                @endif
            </div>

            <p class="tw-hint" id="tw-cta-note">
                첫 방송과 판매자 등록을 준비하고 있습니다.
            </p>
        </div>
    </section>

@endsection
