# YUTIV Still Form local preview

Fork of the repository's safe YUTIV preview harness. Uses the new template's real built IIFE and CSS with synthetic local data and the four template dictionaries. No PHP/app boot, network fetch or DB writes. SVG icons are part of the template; no font/Icon CDN is required.

```powershell
node tests/Preview/yutiv-stillform/build.cjs --umd tests/Preview/yutiv-commerce/.umd
node tests/Preview/yutiv-stillform/browser-probe.cjs
node tests/Preview/yutiv-stillform/check.cjs
node tests/Preview/yutiv-stillform/interaction-probe.cjs
```

UMD files are reused read-only from the existing local preview cache. They are React 18.3.1 rendering helpers (React 19 has no UMD), not deployed theme assets. If that cache is absent, prepare local React/ReactDOM/server UMD files in a separate temporary directory and pass `--umd` / `G7_PREVIEW_UMD`. No cache or generated output is Git tracked.

`output/index.html` links to clean/diagnostic fixed-width iframe previews. Widths: 360, 390, 430, 768, 1440. Scenarios include home, loading, empty, four locales, product list/detail, empty cart/checkout, account/profile, coupons, notice and login. Browser probe reports actual image loads, overflow and element geometry. Rendering fixtures are never supplied to an installed template.

Limits: action callbacks, live API fetch, module slot injection, auth/PG/order lifecycle are not exercised by the SSR harness. Actual G7 DynamicRenderer and action tests are in the template's Vitest suite; purchase E2E requires a separately authorized disposable server/DB.

The separate interaction probe mounts the production Header/ProductCard with local React UMD and synthetic state in Chrome at 390px. It checks menu open/close, inert/focus containment/restoration, keyboard wrap/ESC, detail/cart URL dispatch and light/dark switching. It never calls APIs. The browser probe also captures console errors, uncaught errors, rejected promises, resource failures and HTTP(S) resource requests; an uninstrumented report fails validation.
