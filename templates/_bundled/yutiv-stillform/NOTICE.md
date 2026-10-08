# YUTIV Still Form attribution

This independent derivative combines the YUTIV commerce integration with the Still Form design system.

- Design source: https://github.com/20ft-bahamut/20feet-stillform-template
- Fixed source commit: `b4be98406acc848cb9c8f9c6ea46d56130b8586e`.
- Original copyright: Copyright (c) 2026 SuperBify. The complete original MIT license is included in LICENSE.
- Integration baseline: YUTIV/Gnuboard7 `d697a097bed0e7328a0dc8bb97fd46f2443f9a84`, `templates/_bundled/yutiv-commerce`, itself derived from `sirsoft-basic`. Its MIT attribution is retained in LICENSE-YUTIV.

## Source relationship

`src/styles/stillform.css` adapts the palette and semantic surface roles in the original `src/styles/design-tokens.css` (palette/root token block, approximately lines 1–120): paper, ivory, charcoal, wood, hairlines, 1320px content width, 44px touch targets and editorial spacing. Dark values and YUTIV utility mappings are new.

`layouts/partials/home/_hero.json`, `_brand_story.json` and the editorial section in `layouts/home.json` reinterpret the composition and information hierarchy of original `HeroBanner.tsx`, `BrandStorySection.tsx`, `EditorialBanner.tsx` and `CategoryPreviewStrip.tsx`. They are newly authored G7 layout definitions; no original TSX is copied. Product card spacing and the footer grouping follow the original ProductCard/StoreFooter visual direction, while their API contracts and implementations derive from YUTIV.

Other source, layout, localization, editor specification and extension files were independently copied from the stated YUTIV baseline and then adapted only inside this directory. Authentication, IDV launcher, cart, checkout, PG dispatch, orders, guest tokens, profile, notifications and board activities retain the YUTIV implementation. The template owns its `yutiv-stillform.*` handler namespace and IIFE `YutivStillform`.

## Excluded material

No original demo photographs, logos, trademarks, business identities, addresses, phone numbers, policies, seed scripts or database records are included. No `superbify-commerce-compat` code is included. Hero/story objects and the missing-image SVG are newly authored geometric compositions. Product photographs come from the existing API subject to the configured media-origin policy. No theme font, image or icon CDN is introduced. Existing postcode and PG integrations remain server/module owned.

Neither the protected templates nor TeeWide are modified. There is no relationship to TeeWide tenants, memberships or authentication.
