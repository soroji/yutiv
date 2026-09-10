{{--
    포털 헤더 — 로그인 상태에 따라 우측이 달라진다.

    ⚠ 인증 판정은 반드시 `teewide` guard 로만 한다. `auth()->user()` 나 `Auth::check()` 는
      **기본 guard(YUTIV web)** 를 보므로, 여기서 쓰면 YUTIV 로그인 사용자가 TeeWide
      회원으로 표시된다. 그래서 컨트롤러가 넘겨 준 `$teeWideUser` 만 본다.
--}}
<header class="tw-header">
    <div class="tw-wrap tw-header__inner">
        <a class="tw-logo" href="{{ $portalUrl }}">
            <span class="tw-logo__mark" aria-hidden="true"></span>TeeWide
        </a>

        <nav class="tw-nav" aria-label="주요 메뉴">
            <a class="tw-btn tw-btn--quiet" href="{{ $liveUrl }}">라이브 둘러보기</a>

            @if ($teeWideUser !== null)
                <a class="tw-btn tw-btn--quiet" href="{{ route('teewide.account') }}">
                    {{ $teeWideUser->displayName() }} 님
                </a>

                {{-- 로그아웃은 POST — 링크로 두면 이미지·프리페치로 강제 실행된다. --}}
                <form class="tw-logout" method="POST" action="{{ route('teewide.logout') }}">
                    @csrf
                    <button type="submit" class="tw-btn tw-btn--ghost">로그아웃</button>
                </form>
            @else
                <a class="tw-btn tw-btn--ghost" href="{{ route('teewide.login') }}">로그인</a>
            @endif

            {{--
                판매 시작하기는 아직 백엔드가 없다. 링크처럼 보이게 두면 404 로 이어지므로
                누를 수 없는 버튼으로 두고, 상태를 aria-describedby 로 함께 알린다.
            --}}
            <button type="button" class="tw-btn tw-btn--primary" disabled aria-describedby="tw-cta-note">
                판매 시작하기
            </button>
        </nav>
    </div>
</header>
