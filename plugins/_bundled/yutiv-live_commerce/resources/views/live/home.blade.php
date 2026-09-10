@extends('teewide::layouts.teewide', [
    'title' => 'TeeWide Live — 라이브 판매 채널',
    'area' => 'live',
    'footerNote' => '첫 라이브를 준비하고 있습니다.',
])

@push('styles')
    .tw-live-head { padding: 48px 0 8px; }
    .tw-live-head__title { margin: 14px 0 8px; font-size: 32px; letter-spacing: -0.02em; }
    .tw-live-head__lead { margin: 0; color: var(--tw-muted); max-width: 56ch; }

    .tw-channel {
        display: flex;
        flex-direction: column;
        gap: 14px;
        text-decoration: none;
        border: 1px solid var(--tw-border);
        border-radius: var(--tw-radius);
        background: var(--tw-surface);
        padding: 18px;
    }
    .tw-channel:hover { background: var(--tw-surface-2); border-color: #35414f; }

    /* 채널 썸네일 — 외부 이미지 없이 CSS 로만 만든 플레이스홀더. */
    .tw-channel__thumb {
        aspect-ratio: 16 / 9;
        border-radius: 8px;
        background:
            linear-gradient(135deg, #1d2732 0%, #131a22 100%);
        border: 1px solid var(--tw-border);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 26px;
        font-weight: 700;
        letter-spacing: 0.06em;
        color: #5d6b7c;
    }
    .tw-channel__name { margin: 0; font-size: 17px; }
    .tw-channel__desc { margin: 0; color: var(--tw-muted); font-size: 14px; }
    .tw-channel__foot { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
    .tw-channel__go { font-size: 14px; font-weight: 600; color: var(--tw-text); }

    @media (max-width: 640px) {
        .tw-live-head { padding: 36px 0 4px; }
        .tw-live-head__title { font-size: 26px; }
    }
@endpush

@section('header')
    <header class="tw-header">
        <div class="tw-wrap tw-header__inner">
            <a class="tw-logo" href="{{ $liveUrl }}">
                <span class="tw-logo__mark" aria-hidden="true"></span>TeeWide
                <span class="tw-logo__sub">LIVE</span>
            </a>

            <nav class="tw-nav" aria-label="주요 메뉴">
                <a class="tw-btn tw-btn--quiet" href="{{ $portalUrl }}">TeeWide 홈</a>
            </nav>
        </div>
    </header>
@endsection

@section('content')
    <section class="tw-live-head">
        <div class="tw-wrap">
            <span class="tw-badge"><span class="tw-badge__dot" aria-hidden="true"></span>첫 라이브 준비 중</span>
            <h1 class="tw-live-head__title">TeeWide Live</h1>
            <p class="tw-live-head__lead">
                판매자가 직접 진행하는 라이브 방송을 모았습니다. 방송이 시작되면 이 화면에서 바로 입장할 수 있습니다.
            </p>
        </div>
    </section>

    <section class="tw-section" aria-labelledby="tw-onair-title">
        <div class="tw-wrap">
            <div class="tw-section__head">
                <h2 class="tw-section__title" id="tw-onair-title">현재 방송</h2>
                <p class="tw-section__desc">지금 진행 중인 라이브입니다.</p>
            </div>

            @if ($onAir === [])
                <div class="tw-empty">
                    <p class="tw-empty__title">진행 중인 라이브가 없습니다</p>
                    <p class="tw-empty__desc">첫 방송을 준비하고 있습니다. 시작되면 이 자리에 표시됩니다.</p>
                </div>
            @endif
        </div>
    </section>

    <section class="tw-section" aria-labelledby="tw-upcoming-title">
        <div class="tw-wrap">
            <div class="tw-section__head">
                <h2 class="tw-section__title" id="tw-upcoming-title">예정된 방송</h2>
                <p class="tw-section__desc">편성이 확정되면 시간과 함께 안내합니다.</p>
            </div>

            @if ($upcoming === [])
                <div class="tw-empty">
                    <p class="tw-empty__title">예정된 방송이 아직 없습니다</p>
                    <p class="tw-empty__desc">편성표는 준비되는 대로 공개합니다.</p>
                </div>
            @endif
        </div>
    </section>

    <section class="tw-section" aria-labelledby="tw-channels-title">
        <div class="tw-wrap">
            <div class="tw-section__head">
                <h2 class="tw-section__title" id="tw-channels-title">판매 채널</h2>
                <p class="tw-section__desc">TeeWide 에 입점한 판매자 채널입니다.</p>
            </div>

            @if ($channels === [])
                <div class="tw-empty">
                    <p class="tw-empty__title">공개된 채널이 없습니다</p>
                    <p class="tw-empty__desc">입점 판매자가 준비되면 이 자리에 표시됩니다.</p>
                </div>
            @else
                <div class="tw-grid tw-grid--2">
                    @foreach ($channels as $channel)
                        <a class="tw-channel" href="{{ $channel['url'] }}">
                            <span class="tw-channel__thumb" aria-hidden="true">{{ $channel['initials'] }}</span>
                            <h3 class="tw-channel__name">{{ $channel['name'] }}</h3>
                            <p class="tw-channel__desc">{{ $channel['description'] }}</p>
                            <span class="tw-channel__foot">
                                <span class="tw-badge">
                                    <span class="tw-badge__dot" aria-hidden="true"></span>{{ $channel['status_label'] }}
                                </span>
                                <span class="tw-channel__go" aria-hidden="true">채널 열기 →</span>
                            </span>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </section>
@endsection
