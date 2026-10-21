<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ request()->session()->token() }}">
<meta name="description" content="{{ $sellerBrand }} 라이브"><title>{{ $title ?? $sellerBrand }}</title>
<link rel="stylesheet" href="/live-styles/css?v=0.6.0"><script defer src="/live-assets/js?v=0.6.0"></script>@yield('head')</head>
<body class="live-root"><header class="live-header"><a class="live-brand" href="{{ $portalUrl }}">@if(($tenant ?? null)?->logo_path)<img src="/live-media/logo/logo" alt="" style="max-width:60px;max-height:36px;vertical-align:middle">@endif{{ $sellerBrand }}</a><button type="button" class="live-button live-button--quiet live-nav-toggle" data-live-menu aria-expanded="false" aria-controls="live-global-navigation">메뉴</button><nav id="live-global-navigation" aria-label="주요 메뉴">
<a href="{{ $portalUrl }}/shop">라이브 상품</a><a href="{{ $portalUrl }}/cart">장바구니</a>
@if ($teeWideUser ?? null)<a href="{{ $portalUrl }}/my">내 계정</a><form method="POST" action="{{ $portalUrl }}/logout">@csrf<button type="submit" class="live-button live-button--quiet">로그아웃</button></form>
@else<a href="{{ $portalUrl }}/login">로그인</a>@endif
</nav></header><div class="live-frame @hasSection('navigation') @else live-frame--customer @endif">
@hasSection('navigation')<nav class="live-nav" aria-label="업무 메뉴">@yield('navigation')</nav>@endif
<main class="live-content" id="live-main">@yield('content')</main></div><footer class="live-footer">{{ $sellerBrand }}</footer></body></html>
