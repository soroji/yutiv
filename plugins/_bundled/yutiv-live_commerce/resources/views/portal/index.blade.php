@extends('teewide::layouts.teewide', [
    'title' => 'TeeWide — 라이브로 연결되는 새로운 쇼핑',
    'area' => 'portal',
    'footerNote' => '로그인·판매자 등록은 준비 중입니다.',
])

@push('styles')
    .tw-hero { padding: 84px 0 72px; border-bottom: 1px solid var(--tw-border); }
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
    <header class="tw-header">
        <div class="tw-wrap tw-header__inner">
            <a class="tw-logo" href="{{ $portalUrl }}">
                <span class="tw-logo__mark" aria-hidden="true"></span>TeeWide
            </a>

            <nav class="tw-nav" aria-label="주요 메뉴">
                <a class="tw-btn tw-btn--quiet" href="{{ $liveUrl }}">라이브 둘러보기</a>

                {{--
                    로그인·판매 시작은 아직 백엔드가 없다. 링크처럼 보이게 두면 404 로 이어지므로
                    누를 수 없는 버튼으로 두고, 상태를 aria-describedby 로 함께 알린다.
                --}}
                <button type="button" class="tw-btn tw-btn--ghost" disabled aria-describedby="tw-cta-note">
                    로그인
                </button>
                <button type="button" class="tw-btn tw-btn--primary" disabled aria-describedby="tw-cta-note">
                    판매 시작하기
                </button>
            </nav>
        </div>
    </header>
@endsection

@section('content')
    <section class="tw-hero">
        <div class="tw-wrap">
            <p class="tw-hero__eyebrow">
                <span class="tw-badge"><span class="tw-badge__dot" aria-hidden="true"></span>서비스 준비 중</span>
            </p>

            <h1 class="tw-hero__title">라이브로 연결되는 새로운 쇼핑</h1>

            <p class="tw-hero__lead">
                TeeWide 는 판매자와 고객이 실시간으로 만나는 라이브커머스 플랫폼입니다.
                방송을 보면서 묻고, 바로 주문합니다.
            </p>

            <div class="tw-hero__cta">
                <a class="tw-btn tw-btn--primary" href="{{ $liveUrl }}">라이브 보러가기</a>
                <button type="button" class="tw-btn tw-btn--ghost" disabled aria-describedby="tw-cta-note">
                    판매자로 시작하기
                </button>
            </div>

            <p class="tw-hint" id="tw-cta-note">
                로그인과 판매자 등록은 아직 열지 않았습니다. 준비되는 대로 이 화면에서 안내합니다.
            </p>
        </div>
    </section>

    <section class="tw-section" aria-labelledby="tw-features-title">
        <div class="tw-wrap">
            <div class="tw-section__head">
                <h2 class="tw-section__title" id="tw-features-title">TeeWide 가 준비하는 것</h2>
                <p class="tw-section__desc">라이브 방송과 주문을 하나의 흐름으로 잇습니다.</p>
            </div>

            <div class="tw-grid tw-grid--4">
                @foreach ($features as $feature)
                    <article class="tw-card">
                        <h3 class="tw-card__title">{{ $feature['title'] }}</h3>
                        <p class="tw-card__desc">{{ $feature['description'] }}</p>
                    </article>
                @endforeach
            </div>
        </div>
    </section>
@endsection
