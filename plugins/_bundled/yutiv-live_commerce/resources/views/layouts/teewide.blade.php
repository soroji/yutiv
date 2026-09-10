{{--
    TeeWide 공통 레이아웃.

    ── 왜 YUTIV SPA 셸(resources/views/app.blade.php)을 쓰지 않는가 ────────────
    TeeWide 는 yutiv.com 과 다른 도메인·다른 세션·다른 브랜드다. YUTIV SPA 셸은
    쇼핑몰 부트스트랩(자산·스토어·라우터)을 함께 싣기 때문에, 그것을 재사용하면
    도메인 격리를 코드 수준에서 다시 흐리게 된다. 그래서 TeeWide 는 독립 문서로 둔다.

    ── 왜 CSS 를 인라인하는가 ────────────────────────────────────────────────
    외부 CDN·외부 폰트·외부 이미지에 의존하지 않는다는 요구를 지키면서, 이 플러그인만을
    위해 별도 빌드 파이프라인을 새로 만들지 않기 위해서다. 화면 세 개가 공유하는 단일
    스타일시트이므로 한곳에 모아 둔다. Phase 1-B 에서 자산이 커지면 플러그인 자산 규약으로
    옮긴다.
--}}
<!DOCTYPE html>
<html lang="ko" data-teewide-area="{{ $area ?? 'portal' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title ?? 'TeeWide' }}</title>
    <style>
        :root {
            --tw-bg: #0b0f14;
            --tw-surface: #151b23;
            --tw-surface-2: #1c242e;
            --tw-border: #2a3441;
            --tw-text: #e8ecf1;
            --tw-muted: #9aa7b6;
            --tw-accent: #ff4655;
            --tw-accent-ink: #ffffff;
            --tw-focus: #7fb2ff;
            --tw-radius: 12px;
            --tw-maxw: 1120px;
        }

        *, *::before, *::after { box-sizing: border-box; }

        html { -webkit-text-size-adjust: 100%; }

        body {
            margin: 0;
            background: var(--tw-bg);
            color: var(--tw-text);
            font-family: 'Pretendard', -apple-system, BlinkMacSystemFont, 'Apple SD Gothic Neo',
                'Malgun Gothic', 'Noto Sans KR', system-ui, sans-serif;
            font-size: 16px;
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
        }

        a { color: inherit; }

        :focus-visible {
            outline: 3px solid var(--tw-focus);
            outline-offset: 2px;
            border-radius: 4px;
        }

        .tw-skip {
            position: absolute;
            left: -9999px;
            top: 0;
            padding: 12px 18px;
            background: var(--tw-accent);
            color: var(--tw-accent-ink);
            z-index: 100;
        }
        .tw-skip:focus { left: 12px; top: 12px; }

        .tw-shell { display: flex; flex-direction: column; min-height: 100vh; }
        .tw-wrap { width: 100%; max-width: var(--tw-maxw); margin: 0 auto; padding: 0 20px; }

        /* ── 헤더 ─────────────────────────────────────────────────────────── */
        .tw-header {
            border-bottom: 1px solid var(--tw-border);
            background: rgba(11, 15, 20, 0.92);
            position: sticky;
            top: 0;
            z-index: 20;
        }
        .tw-header__inner {
            display: flex;
            align-items: center;
            gap: 16px;
            min-height: 64px;
            flex-wrap: wrap;
        }
        .tw-logo {
            font-size: 20px;
            font-weight: 700;
            letter-spacing: -0.01em;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-right: auto;
        }
        .tw-logo__mark {
            width: 10px; height: 10px; border-radius: 3px;
            background: var(--tw-accent);
            display: inline-block;
        }
        .tw-logo__sub {
            font-size: 12px;
            font-weight: 600;
            color: var(--tw-muted);
            border: 1px solid var(--tw-border);
            border-radius: 999px;
            padding: 2px 8px;
            letter-spacing: 0.04em;
        }
        .tw-nav { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

        /* ── 버튼 ─────────────────────────────────────────────────────────── */
        .tw-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 18px;
            border-radius: 10px;
            border: 1px solid transparent;
            font-size: 15px;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            background: none;
            color: inherit;
            font-family: inherit;
        }
        .tw-btn--primary { background: var(--tw-accent); color: var(--tw-accent-ink); }
        .tw-btn--primary:hover { background: #e63d4b; }
        .tw-btn--ghost { border-color: var(--tw-border); color: var(--tw-text); }
        .tw-btn--ghost:hover { background: var(--tw-surface-2); }
        .tw-btn--quiet { color: var(--tw-muted); padding: 10px 12px; }
        .tw-btn--quiet:hover { color: var(--tw-text); }

        /*
            준비 중 CTA — 없는 기능을 있는 것처럼 보이지 않게 한다.
            링크가 아니라 disabled 버튼이므로 키보드 탐색에서도 "누를 수 없음" 이 전달된다.
        */
        .tw-btn[disabled] {
            opacity: 0.55;
            cursor: not-allowed;
        }
        .tw-btn[disabled]:hover { background: none; }
        .tw-btn--primary[disabled] { background: var(--tw-surface-2); color: var(--tw-muted); border-color: var(--tw-border); }

        .tw-hint {
            font-size: 13px;
            color: var(--tw-muted);
            margin: 10px 0 0;
        }

        /* ── 상태 배지 ─────────────────────────────────────────────────────── */
        .tw-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.02em;
            padding: 5px 10px;
            border-radius: 999px;
            border: 1px solid var(--tw-border);
            color: var(--tw-muted);
            background: var(--tw-surface);
        }
        .tw-badge__dot {
            width: 7px; height: 7px; border-radius: 50%;
            background: var(--tw-muted);
            flex: none;
        }
        .tw-badge--live { color: var(--tw-text); border-color: rgba(255, 70, 85, 0.45); }
        .tw-badge--live .tw-badge__dot { background: var(--tw-accent); }

        /* ── 섹션 공통 ─────────────────────────────────────────────────────── */
        main { flex: 1 0 auto; }
        .tw-section { padding: 56px 0; }
        .tw-section__head { margin-bottom: 28px; }
        .tw-section__title { font-size: 22px; margin: 0 0 6px; letter-spacing: -0.01em; }
        .tw-section__desc { margin: 0; color: var(--tw-muted); font-size: 15px; }

        .tw-grid { display: grid; gap: 16px; }
        .tw-grid--4 { grid-template-columns: repeat(4, 1fr); }
        .tw-grid--2 { grid-template-columns: repeat(2, 1fr); }

        .tw-card {
            background: var(--tw-surface);
            border: 1px solid var(--tw-border);
            border-radius: var(--tw-radius);
            padding: 22px;
        }
        .tw-card__title { margin: 0 0 8px; font-size: 16px; }
        .tw-card__desc { margin: 0; color: var(--tw-muted); font-size: 14px; }

        /* ── 빈 상태 ───────────────────────────────────────────────────────── */
        .tw-empty {
            border: 1px dashed var(--tw-border);
            border-radius: var(--tw-radius);
            padding: 40px 24px;
            text-align: center;
            background: rgba(21, 27, 35, 0.5);
        }
        .tw-empty__title { margin: 0 0 6px; font-size: 16px; }
        .tw-empty__desc { margin: 0; color: var(--tw-muted); font-size: 14px; }

        /* ── 푸터 ─────────────────────────────────────────────────────────── */
        .tw-footer {
            border-top: 1px solid var(--tw-border);
            padding: 28px 0;
            color: var(--tw-muted);
            font-size: 13px;
        }
        .tw-footer__inner { display: flex; gap: 12px; justify-content: space-between; flex-wrap: wrap; }

        @media (max-width: 900px) {
            .tw-grid--4 { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 640px) {
            .tw-section { padding: 40px 0; }
            .tw-grid--4, .tw-grid--2 { grid-template-columns: 1fr; }
            .tw-header__inner { min-height: 56px; }
            .tw-logo { font-size: 18px; }
        }

        @media (prefers-reduced-motion: reduce) {
            * { transition: none !important; animation: none !important; }
        }

        @stack('styles')
    </style>
</head>
<body>
    <div class="tw-shell">
        <a class="tw-skip" href="#tw-main">본문으로 건너뛰기</a>

        @yield('header')

        <main id="tw-main" tabindex="-1">
            @yield('content')
        </main>

        <footer class="tw-footer">
            <div class="tw-wrap tw-footer__inner">
                <span>TeeWide — 라이브커머스 플랫폼</span>
                <span>{{ $footerNote ?? '서비스 준비 중입니다.' }}</span>
            </div>
        </footer>
    </div>
</body>
</html>
