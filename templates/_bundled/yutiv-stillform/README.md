# YUTIV Still Form

Independent user template, `yutiv-stillform` 1.0.0, IIFE `YutivStillform`. See NOTICE.md and LICENSE for design-source attribution.

Runtime dependency license texts are retained in THIRD_PARTY_LICENSES.txt and included in the frontend package file list. CMS HTML retains semantic formatting; inline CSS/background attributes are removed so they cannot bypass the media-origin policy. Compression uses the bundled implementation on the main thread, avoiding the library's default CDN worker.

## Local checks

Run from this directory in PowerShell:

```powershell
npm ci --ignore-scripts --no-audit --no-fund
npm run type-check
npm run test:run
npm run build
npm run check:static
```

The checked-in dist is a production build without source maps. These commands do not install or activate the template in G7. Preview tooling lives in `tests/Preview/yutiv-stillform` at the repository root and uses generated fixtures only.

## Runtime contracts

The 40 YUTIV routes retain their layouts, authentication gates and redirects, including `/mypage` → `/mypage/profile`. Ecommerce links use `sirsoft-ecommerce.basic_info.route_path/no_route`; product detail links use product_code while internal mutations retain numeric IDs. Existing module APIs own currencies, shipping, stock, coupons, cart merging, order totals, PG dispatch and guest access.

`/story` reads CMS `about`; `/shipping` reads CMS `refund`. Terms/privacy/refund links read their respective CMS pages; no policy text is supplied. `/notice` reads the board slug `_global.storefront.noticeSlug` (default `store-notice`). A deployment must supply a real published board/page; missing data produces the inherited errors or empty state. No seed is supplied.

The new optional presentation bag `_global.storefront` can be supplied by an authorized extension/bootstrap or by editing layout props:

- `noticeSlug`: existing board slug; never a route fragment.
- `allowedStorageOrigins`: explicit HTTPS storage origins (e.g. an administrator-approved object-storage origin), empty by default. Same-origin media is allowed. Unapproved images show the local geometric placeholder. This does not alter existing PG/postcode scripts.
- `logoUrl`: optional store logo for the existing Header logo prop; site logo is the fallback.

Hero, story and editorial media use extension points `stillform_hero_media`, `stillform_story_media`, `stillform_editorial_media`; replace their defaults with approved Img content through the layout editor/provider. `stillform_store_banner`, `stillform_store_product_filter`, `stillform_product_reward`, `stillform_order_context` are empty. `StorefrontSlots` documents future branding/filter/reward/context props. These slots neither apply a filter nor compute a reward nor send order context. A future implementation must validate store selection and context on the server. No TeeWide data is referenced.

## Public business information

`yutiv-storefront_support` supplies nine allowlisted fields. Existing ecommerce settings provide company, representative, registration numbers, public address and public contact; plugin settings provide hosting/HTTPS verification URL. Empty or failed data renders no rows. Privacy-officer/admin/internal/secret settings are excluded. Do not publish private contact details in these public settings.

## Deferred verification

Installation/activation, migrations, seeds, checkout/purchase E2E and DB-backed PHP tests are intentionally not run in this local task. Before any deployment, use a separate disposable G7 environment and test actual module slot injection (currency/shipping country), auth/IDV, guest cart merge, foreign/domestic shipping, coupon/point recalculation, all PG gateways, guest order tokens, cancellation/refund/confirmation/reorder and address persistence. Obtain separate deployment authorization. Preview is rendering evidence, not a purchase E2E.
