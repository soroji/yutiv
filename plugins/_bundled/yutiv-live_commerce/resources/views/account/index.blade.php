@extends('teewide::layouts.teewide', [
    'title' => '마이페이지 — TeeWide',
    'area' => 'account',
    'footerNote' => '판매 채널 신청은 준비 중입니다.',
])

@push('styles')
    .tw-account { padding: 44px 0 64px; }
    .tw-account__title { margin: 0 0 6px; font-size: 26px; letter-spacing: -0.02em; }
    .tw-account__lead { margin: 0 0 28px; color: var(--tw-muted); }

    .tw-account__grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 16px; align-items: start; }

    .tw-profile { margin: 0; display: grid; gap: 14px; }
    .tw-profile__row { display: flex; justify-content: space-between; gap: 14px; font-size: 14px; }
    .tw-profile__key { color: var(--tw-muted); }
    .tw-profile__val { margin: 0; text-align: right; word-break: break-all; }

    .tw-owned { display: grid; gap: 10px; margin: 0; padding: 0; list-style: none; }
    .tw-owned__item {
        display: flex; align-items: center; justify-content: space-between; gap: 12px;
        border: 1px solid var(--tw-border); border-radius: 9px; padding: 13px 15px;
    }
    .tw-owned__name { font-size: 15px; font-weight: 600; }
    .tw-owned__slug { display: block; font-size: 13px; color: var(--tw-muted); }

    @media (max-width: 800px) {
        .tw-account__grid { grid-template-columns: 1fr; }
    }
@endpush

@section('header')
    @include('teewide::partials.portal-header', ['teeWideUser' => \Plugins\Yutiv\LiveCommerce\Support\TeeWideAuth::user()])
@endsection

@section('content')
    <section class="tw-account" aria-labelledby="tw-account-title">
        <div class="tw-wrap">
            <h1 class="tw-account__title" id="tw-account-title">마이페이지</h1>
            <p class="tw-account__lead">{{ $account['display_name'] }} 님, 반갑습니다.</p>

            <div class="tw-account__grid">
                <section class="tw-card" aria-labelledby="tw-profile-title">
                    <h2 class="tw-card__title" id="tw-profile-title">계정 정보</h2>

                    <dl class="tw-profile">
                        <div class="tw-profile__row">
                            <dt class="tw-profile__key">이름</dt>
                            <dd class="tw-profile__val">{{ $account['name'] }}</dd>
                        </div>
                        <div class="tw-profile__row">
                            <dt class="tw-profile__key">이메일</dt>
                            <dd class="tw-profile__val">{{ $account['email'] }}</dd>
                        </div>
                        <div class="tw-profile__row">
                            <dt class="tw-profile__key">가입일</dt>
                            <dd class="tw-profile__val">{{ $account['joined_at'] }}</dd>
                        </div>
                        <div class="tw-profile__row">
                            <dt class="tw-profile__key">이메일 인증</dt>
                            <dd class="tw-profile__val">
                                <span class="tw-badge">
                                    <span class="tw-badge__dot" aria-hidden="true"></span>{{ $account['email_verified_label'] }}
                                </span>
                            </dd>
                        </div>
                    </dl>
                </section>

                <section class="tw-card" aria-labelledby="tw-owned-title">
                    <h2 class="tw-card__title" id="tw-owned-title">내 판매 채널</h2>

                    @if ($ownedChannels === [])
                        {{-- 없는 것을 있는 것처럼 만들지 않는다. 신청 기능도 아직 없다. --}}
                        <div class="tw-empty">
                            <p class="tw-empty__title">아직 소유한 채널이 없습니다</p>
                            <p class="tw-empty__desc">판매 채널 신청은 준비 중입니다. 열리면 이 자리에서 안내합니다.</p>
                        </div>
                    @else
                        <ul class="tw-owned">
                            @foreach ($ownedChannels as $channel)
                                <li class="tw-owned__item">
                                    <span>
                                        <span class="tw-owned__name">{{ $channel['name'] }}</span>
                                        <span class="tw-owned__slug">/{{ $channel['slug'] }}</span>
                                    </span>
                                    <span class="tw-badge">
                                        <span class="tw-badge__dot" aria-hidden="true"></span>{{ $channel['status_label'] }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>
        </div>
    </section>
@endsection
