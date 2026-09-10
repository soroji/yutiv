@extends('teewide::layouts.teewide', [
    'title' => $channel['name'].' — TeeWide Live',
    'area' => 'live-channel',
    'footerNote' => '라이브 상품을 준비하고 있습니다.',
])

@push('styles')
    .tw-channel-head { padding: 32px 0 0; }
    .tw-channel-head__row { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
    .tw-channel-head__mark {
        width: 56px; height: 56px; border-radius: 14px;
        background: linear-gradient(135deg, #222d3a 0%, #151c25 100%);
        border: 1px solid var(--tw-border);
        display: flex; align-items: center; justify-content: center;
        font-weight: 700; font-size: 18px; color: #6c7b8d; flex: none;
    }
    .tw-channel-head__name { margin: 0; font-size: 26px; letter-spacing: -0.02em; }
    .tw-channel-head__desc { margin: 6px 0 0; color: var(--tw-muted); font-size: 15px; }

    /*
        무대 + 사이드 2열. 모바일에서는 한 열이 되고 DOM 순서 그대로
        영상 → 방송 정보 → 상품 으로 읽힌다.
    */
    .tw-stage-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 340px;
        gap: 20px;
        align-items: start;
        padding: 24px 0 8px;
    }

    .tw-stage {
        aspect-ratio: 16 / 9;
        border-radius: var(--tw-radius);
        border: 1px solid var(--tw-border);
        background: linear-gradient(135deg, #161e28 0%, #0f151c 100%);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 12px;
        text-align: center;
        padding: 24px;
    }
    .tw-stage__title { margin: 0; font-size: 17px; }
    .tw-stage__desc { margin: 0; color: var(--tw-muted); font-size: 14px; max-width: 40ch; }

    .tw-panel {
        border: 1px solid var(--tw-border);
        border-radius: var(--tw-radius);
        background: var(--tw-surface);
        padding: 20px;
    }
    .tw-panel + .tw-panel { margin-top: 16px; }
    .tw-panel__title { margin: 0 0 12px; font-size: 15px; }

    .tw-meta { margin: 0; display: grid; gap: 10px; }
    .tw-meta__row { display: flex; justify-content: space-between; gap: 12px; font-size: 14px; }
    .tw-meta__key { color: var(--tw-muted); }
    .tw-meta__val { color: var(--tw-text); text-align: right; }

    @media (max-width: 900px) {
        .tw-stage-grid { grid-template-columns: 1fr; }
    }

    @media (max-width: 640px) {
        .tw-channel-head { padding: 24px 0 0; }
        .tw-channel-head__name { font-size: 22px; }
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
                <a class="tw-btn tw-btn--quiet" href="{{ $liveUrl }}">채널 목록</a>
                <a class="tw-btn tw-btn--quiet" href="{{ $portalUrl }}">TeeWide 홈</a>
            </nav>
        </div>
    </header>
@endsection

@section('content')
    <section class="tw-channel-head" aria-labelledby="tw-channel-name">
        <div class="tw-wrap tw-channel-head__row">
            <span class="tw-channel-head__mark" aria-hidden="true">{{ $channel['initials'] }}</span>
            <div>
                <h1 class="tw-channel-head__name" id="tw-channel-name">{{ $channel['name'] }}</h1>
                <p class="tw-channel-head__desc">{{ $channel['description'] }}</p>
            </div>
            <span class="tw-badge" style="margin-left:auto">
                <span class="tw-badge__dot" aria-hidden="true"></span>{{ $channel['status_label'] }}
            </span>
        </div>
    </section>

    <div class="tw-wrap tw-stage-grid">
        {{-- 영상 무대 — Phase 1-B 에서 플레이어가 이 자리에 들어간다. --}}
        <section aria-labelledby="tw-stage-title">
            <div class="tw-stage">
                <span class="tw-badge"><span class="tw-badge__dot" aria-hidden="true"></span>방송 준비 중</span>
                <h2 class="tw-stage__title" id="tw-stage-title">아직 방송이 시작되지 않았습니다</h2>
                <p class="tw-stage__desc">
                    방송이 시작되면 이 자리에서 바로 시청할 수 있습니다.
                </p>
            </div>
        </section>

        {{-- 방송 정보 + 상품. 모바일에서는 무대 아래로 한 열이 된다. --}}
        <div>
            <section class="tw-panel" aria-labelledby="tw-info-title">
                <h2 class="tw-panel__title" id="tw-info-title">방송 정보</h2>
                <dl class="tw-meta">
                    @foreach ($broadcast as $row)
                        <div class="tw-meta__row">
                            <dt class="tw-meta__key">{{ $row['label'] }}</dt>
                            <dd class="tw-meta__val" style="margin:0">{{ $row['value'] }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>

            <section class="tw-panel" aria-labelledby="tw-products-title">
                <h2 class="tw-panel__title" id="tw-products-title">라이브 상품</h2>

                @if ($products === [])
                    {{-- 가짜 상품 카드나 가짜 가격을 만들지 않는다. --}}
                    <div class="tw-empty">
                        <p class="tw-empty__title">라이브 상품을 준비하고 있습니다</p>
                        <p class="tw-empty__desc">방송에서 소개할 상품이 등록되면 여기에 표시됩니다.</p>
                    </div>
                @endif
            </section>
        </div>
    </div>
@endsection
