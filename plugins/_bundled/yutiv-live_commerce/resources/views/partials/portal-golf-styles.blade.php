@php
    // TeeWide 호스트는 코어 /api/plugins 자산 라우트도 차단합니다. 게이트를 넓히지 않고
    // 설치된 플러그인의 원본 PNG를 그대로 CSS에 포함해 동일 호스트에서 로드합니다.
    $pluginRoot = dirname((new \ReflectionClass(\Plugins\Yutiv\LiveCommerce\Providers\LiveCommerceServiceProvider::class))->getFileName(), 3);
    $useGolfBackground = \Plugins\Yutiv\LiveCommerce\Support\LiveContext::requireTenant()->slug === config('yutiv_live_commerce.brand_tenant');
    $golfBackground = $useGolfBackground ? 'data:image/png;base64,'.base64_encode(file_get_contents($pluginRoot.'/resources/assets/golfif-pc.png')) : '';
@endphp
@push('styles')
    html[data-teewide-area="portal"] {
        --tw-bg:#f3f6f1;--tw-text:#111b16;--tw-muted:#394f45;--tw-focus:#07583e;
    }
    html[data-teewide-area="portal"] .tw-shell {
        min-height:100vh;min-height:100svh;position:relative;isolation:isolate;
    }
    html[data-teewide-area="portal"] .tw-shell::before,
    html[data-teewide-area="portal"] .tw-shell::after {content:"";position:absolute;inset:0;pointer-events:none;}
    html[data-teewide-area="portal"] .tw-shell::before {
        z-index:-2;@if($useGolfBackground) background-image:url('{!! $golfBackground !!}'); @else background:linear-gradient(120deg,#f6f8f1 20%,#c4d9c8 65%,#2f6950); @endif
        background-size:cover;background-position:center center;
    }
    html[data-teewide-area="portal"] .tw-shell::after {
        z-index:-1;background:linear-gradient(90deg,rgba(255,255,255,.94) 0%,rgba(255,255,255,.80) 24%,rgba(255,255,255,.36) 46%,rgba(255,255,255,0) 66%);
    }
    .tw-golf-header {display:flex;align-items:center;justify-content:space-between;gap:20px;padding:30px 5.5%;}
    .tw-golf-logo {font-size:46px;line-height:1;font-weight:900;letter-spacing:-2px;color:#111;text-decoration:none;white-space:nowrap;min-width:0;overflow:hidden;text-overflow:ellipsis;}
    .tw-golf-logo span {color:#13724f;}
    .tw-golf-eyebrow,.tw-golf-description {overflow-wrap:anywhere;}
    .tw-golf-member-menu {display:flex;flex-shrink:0;align-items:center;gap:8px;padding:7px;background:rgba(255,255,255,.92);border-radius:30px;}
    .tw-golf-member-menu a,.tw-golf-member-menu button {display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:0 15px;border:0;border-radius:24px;background:#176b51;color:#fff;font-family:inherit;font-size:13px;font-weight:700;line-height:1.3;text-decoration:none;white-space:nowrap;cursor:pointer;}
    .tw-golf-member-menu form {margin:0;display:flex;}
    .tw-golf-member-menu a:hover,.tw-golf-member-menu button:hover {background:#074c36;}
    html[data-teewide-area="portal"] #tw-main {display:flex;align-items:flex-start;flex:1 0 auto;}
    .tw-golf-hero {width:55%;max-width:880px;margin:0;padding:65px 0 54px 5.5%;}
    .tw-golf-eyebrow {margin:0 0 16px;color:#166044;font-size:13px;font-weight:800;letter-spacing:3px;}
    .tw-golf-title {margin:0;font-size:clamp(40px,4vw,66px);font-weight:900;line-height:1.22;letter-spacing:-2.5px;word-break:keep-all;overflow-wrap:normal;}
    .tw-golf-title span,.tw-golf-title strong {display:block;}
    .tw-golf-title strong {color:#0d6045;font-weight:900;}
    .tw-golf-description {margin:22px 0 0;font-size:20px;line-height:1.65;color:#394f45;font-weight:500;}
    .tw-golf-actions {display:grid;gap:14px;width:100%;max-width:590px;margin-top:38px;}
    .tw-golf-action {display:flex;align-items:center;justify-content:space-between;gap:16px;min-height:90px;padding:22px 30px;border-radius:14px;color:#fff;font-size:25px;font-weight:800;line-height:1.35;text-decoration:none;}
    .tw-golf-action--live {background:#f01d2d;}
    .tw-golf-action--account {background:#0b563f;}
    .tw-golf-action--live:hover {background:#d81625;}
    .tw-golf-action--account:hover {background:#064330;}
    html[data-teewide-area="portal"] .tw-footer {border:0;background:transparent;padding:18px 5.5%;font-size:11px;color:#264a38;}
    html[data-teewide-area="portal"] .tw-footer__inner {width:100%;max-width:none;margin:0;padding:0;}
    html[data-teewide-area="portal"] .tw-footer__inner span:empty {display:none;}
    @media(min-width:801px) and (max-height:800px) {
        .tw-golf-header {padding-top:24px;padding-bottom:24px;}
        .tw-golf-hero {padding-top:26px;padding-bottom:30px;}
        .tw-golf-title {font-size:clamp(40px,3.6vw,60px);}
        .tw-golf-actions {margin-top:26px;}
        .tw-golf-action {min-height:76px;}
    }
    @media(max-width:800px) {
        html[data-teewide-area="portal"] .tw-shell::before {background-position:82% center;}
        html[data-teewide-area="portal"] .tw-shell::after {background:linear-gradient(180deg,rgba(255,255,255,.96) 0%,rgba(255,255,255,.87) 31%,rgba(255,255,255,.42) 57%,rgba(255,255,255,.10) 100%);}
        .tw-golf-header {padding:calc(22px + env(safe-area-inset-top)) 24px 0;gap:12px;}
        .tw-golf-logo {font-size:32px;letter-spacing:-1.4px;}
        .tw-golf-member-menu {gap:5px;padding:5px;}
        .tw-golf-member-menu a,.tw-golf-member-menu button {min-height:32px;padding:0 10px;font-size:12px;}
        html[data-teewide-area="portal"] #tw-main {flex-direction:column;}
        .tw-golf-hero {display:flex;flex-direction:column;flex:1;width:100%;max-width:none;min-height:650px;padding:30px 24px 28px;}
        .tw-golf-eyebrow {font-size:11px;letter-spacing:2px;margin-bottom:14px;}
        .tw-golf-title {font-size:32px;letter-spacing:-1.4px;line-height:1.3;}
        .tw-golf-description {font-size:15px;line-height:1.65;margin-top:16px;}
        .tw-golf-actions {max-width:none;margin-top:auto;padding-top:40px;gap:12px;}
        .tw-golf-action {min-height:68px;padding:18px 22px;font-size:20px;border-radius:12px;}
        html[data-teewide-area="portal"] .tw-footer {padding:10px 24px calc(12px + env(safe-area-inset-bottom));font-size:10px;background:rgba(255,255,255,.82);}
    }
    @media(max-width:359px) {
        .tw-golf-header {padding-left:18px;padding-right:18px;gap:6px;}
        .tw-golf-logo {font-size:28px;}
        .tw-golf-hero {padding-left:18px;padding-right:18px;}
        .tw-golf-title {font-size:28px;}
        .tw-golf-member-menu a,.tw-golf-member-menu button {padding:0 8px;}
    }
    .tw-golf-selling {margin-top:24px;display:grid;gap:8px;max-width:520px;color:#153e29;}
    .tw-golf-selling p {font-size:13px;font-weight:700;margin:0;}
    .tw-golf-selling a {padding:10px 16px;border-radius:6px;background:#ffffffc9;color:#153e29;text-decoration:none;overflow-wrap:anywhere;}
@endpush
