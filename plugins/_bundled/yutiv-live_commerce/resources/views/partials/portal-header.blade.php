{{--
    포털 헤더 — 로그인 상태에 따라 우측이 달라진다.

    ⚠ 인증 판정은 반드시 `teewide` guard 로만 한다. `auth()->user()` 나 `Auth::check()` 는
      **기본 guard(YUTIV web)** 를 보므로, 여기서 쓰면 YUTIV 로그인 사용자가 TeeWide
      회원으로 표시된다. 그래서 컨트롤러가 넘겨 준 `$teeWideUser` 만 본다.
--}}
@php($teeWideUser = $teeWideUser ?? \Plugins\Yutiv\LiveCommerce\Support\TeeWideAuth::user())
@if ($golfLanding ?? false)
<header class="tw-golf-header">
    <a class="tw-golf-logo" href="{{ $portalUrl }}">{{ $sellerBrand }}<span aria-hidden="true">.</span></a>
    <nav class="tw-golf-member-menu" aria-label="계정 메뉴">
        @if ($teeWideUser === null)
            <a href="{{ route('teewide.login') }}">로그인</a>
            <a href="{{ route('teewide.register') }}">회원가입</a>
        @else
            <a href="{{ route('teewide.account') }}">내 계정</a>
            <form method="POST" action="{{ route('teewide.logout') }}">
                @csrf
                <button type="submit">로그아웃</button>
            </form>
        @endif
    </nav>
</header>
@else
<header class="tw-header">
    <div class="tw-wrap tw-header__inner">
        <a class="tw-logo" href="{{ $portalUrl }}">
            <span class="tw-logo__mark" aria-hidden="true"></span>{{ $sellerBrand }}
        </a>

        <a class="tw-btn tw-btn--primary tw-mobile-live" href="{{ $liveUrl }}">라이브 보기</a>
        <details class="tw-mobile-menu">
            <summary>메뉴</summary>
            <nav aria-label="모바일 계정 메뉴">
                @if ($teeWideUser !== null)
                    <a class="tw-btn tw-btn--quiet" href="{{ route('teewide.account') }}">내 계정</a>
                    <form class="tw-logout" method="POST" action="{{ route('teewide.logout') }}">@csrf<button type="submit" class="tw-btn tw-btn--ghost">로그아웃</button></form>
                @else
                    <a class="tw-btn tw-btn--quiet" href="{{ route('teewide.login') }}">로그인</a>
                    <a class="tw-btn tw-btn--ghost" href="{{ route('teewide.register') }}">회원가입</a>
                @endif
            </nav>
        </details>
        <nav class="tw-nav tw-nav--desktop" aria-label="주요 메뉴">
            <a class="tw-btn tw-btn--quiet" href="{{ $liveUrl }}">라이브 둘러보기</a>

            @if ($teeWideUser !== null)
                <a class="tw-btn tw-btn--quiet tw-account-label" href="{{ route('teewide.account') }}">
                    {{ $teeWideUser->displayName() }} 님
                </a>

                {{-- 로그아웃은 POST — 링크로 두면 이미지·프리페치로 강제 실행된다. --}}
                <form class="tw-logout" method="POST" action="{{ route('teewide.logout') }}">
                    @csrf
                    <button type="submit" class="tw-btn tw-btn--ghost">로그아웃</button>
                </form>
            @else
                <a class="tw-btn tw-btn--ghost" href="{{ route('teewide.login') }}">로그인</a>
                <a class="tw-btn tw-btn--primary" href="{{ route('teewide.register') }}">회원가입</a>
            @endif

        </nav>
    </div>
</header>
@endif
