@if ($golfLanding ?? false)
    @include('teewide::partials.portal-golf-styles')
@else
@push('styles')
    html[data-teewide-area="portal"],html[data-teewide-area="live"] {
        --tw-bg:#f6f5f1;--tw-surface:#ffffff;--tw-surface-2:#e9ece7;--tw-border:#d9ded7;
        --tw-text:#182922;--tw-muted:#5d7065;--tw-accent:#e6533e;--tw-maxw:1240px;
    }
    html[data-teewide-area="portal"] .tw-header,html[data-teewide-area="live"] .tw-header {background:rgba(246,245,241,.96);}
    .tw-mobile-menu {display:none;position:relative;}
    .tw-mobile-menu summary {cursor:pointer;list-style:none;padding:10px 12px;border:1px solid var(--tw-border);border-radius:8px;font-size:13px;}
    .tw-mobile-menu summary::-webkit-details-marker {display:none;}
    .tw-mobile-menu nav {position:absolute;right:0;top:calc(100% + 12px);width:220px;background:var(--tw-surface);border:1px solid var(--tw-border);padding:12px;border-radius:12px;box-shadow:0 12px 32px #18292218;display:grid;gap:8px;}
    .tw-mobile-menu .tw-btn,.tw-mobile-menu .tw-logout,.tw-mobile-menu button {width:100%;}
    .tw-account-label {max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
    .tw-hero {padding:80px 0 64px;}
    .tw-hero__grid {display:grid;grid-template-columns:minmax(0,.85fr) minmax(0,1.15fr);gap:64px;align-items:center;}
    .tw-hero__grid>* {min-width:0;}
    .tw-hero__eyebrow {font-size:11px;font-weight:700;letter-spacing:.17em;color:var(--tw-muted);margin:0 0 24px;}
    .tw-hero__title {font-size:clamp(38px,4.1vw,62px);line-height:1.18;letter-spacing:-.045em;margin:0 0 24px;font-weight:650;word-break:keep-all;}
    .tw-hero__title em {font-style:normal;color:var(--tw-accent);}
    .tw-hero__lead {font-size:16px;line-height:1.9;color:var(--tw-muted);max-width:36ch;margin:0 0 30px;}
    .tw-hero__cta {display:flex;gap:10px;flex-wrap:wrap;}
    .tw-stage {border-radius:18px;overflow:hidden;background:#173c30;color:#ecf0e6;box-shadow:0 24px 60px #18292214;border:1px solid #23483b;transform:rotate(-1deg);}
    .tw-stage__bar,.tw-stage__caption {display:flex;justify-content:space-between;align-items:center;gap:12px;padding:18px 22px;font-size:10px;letter-spacing:.08em;}
    .tw-stage__label {padding:5px 8px;background:#ffffff12;border:1px solid #ffffff24;border-radius:4px;font-size:10px;letter-spacing:0;}
    .tw-stage__scene {position:relative;aspect-ratio:16/10;overflow:hidden;background:radial-gradient(ellipse at 50% 40%,#416b55 0%,#204a39 47%,#173c30 80%);}
    .tw-stage__halo {position:absolute;width:62%;aspect-ratio:1;border:1px solid #ffffff30;border-radius:50%;left:19%;top:0;box-shadow:0 0 0 36px #ffffff04,0 0 0 72px #ffffff03;}
    .tw-stage__frame {position:absolute;inset:18% 18% 20%;border:1px solid #f2eee33d;border-radius:14px;background:linear-gradient(150deg,#ffffff12,#102c2350);transform:rotate(5deg);}
    .tw-stage__play {position:absolute;left:calc(50% - 36px);top:calc(50% - 36px);width:72px;height:72px;display:grid;place-items:center;border:1px solid #fff8;border-radius:50%;background:#e6533e;color:white;font-size:38px;box-shadow:0 10px 30px #0002;}
    .tw-stage__line {position:absolute;left:25%;right:25%;height:1px;background:#e8ece440;bottom:26%;}
    .tw-stage__caption {border-top:1px solid #ffffff18;color:#c5d7c8;}
    .tw-service-strip {display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:32px;border-top:1px solid var(--tw-border);padding:32px 0 52px;}
    .tw-service-strip article {min-width:0;}
    .tw-service-strip span {font-size:11px;color:var(--tw-accent);letter-spacing:.1em;}
    .tw-service-strip h2 {font-size:17px;font-weight:600;margin:10px 0;}
    .tw-service-strip p {font-size:13px;color:var(--tw-muted);margin:0;}
    .tw-empty--broadcast {display:grid;grid-template-columns:minmax(0,.8fr) minmax(0,1fr);align-items:center;gap:48px;padding:36px;background:var(--tw-surface);text-align:left;}
    .tw-empty--broadcast .tw-stage {transform:none;}
    .tw-empty--broadcast .tw-stage__scene {aspect-ratio:16/7;}
    @media(max-width:800px){.tw-hero{padding:48px 0;}.tw-hero__grid{grid-template-columns:1fr;gap:36px;}.tw-hero__lead{max-width:46ch;}.tw-stage{transform:none;}.tw-empty--broadcast{grid-template-columns:1fr;gap:24px;}}
    @media(max-width:640px){.tw-header__inner{gap:8px;flex-wrap:nowrap;min-height:68px;}.tw-logo{font-size:19px;gap:6px;}.tw-nav--desktop{display:none;}.tw-mobile-menu{display:block;}.tw-mobile-live{font-size:12px;padding:9px 12px!important;}.tw-hero__title{font-size:40px;}.tw-hero__cta .tw-btn{flex:1 1 auto;font-size:13px;}.tw-service-strip{grid-template-columns:1fr;gap:24px;padding:28px 0 40px;}.tw-stage__bar,.tw-stage__caption{padding:14px;font-size:9px;}.tw-stage__caption span:last-child{display:none;}.tw-empty--broadcast{padding:22px;}}
    @media(min-width:641px){.tw-mobile-live{display:none;}}
@endpush
@endif
