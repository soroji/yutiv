# Changelog

## [1.0.0] - 2026-10-08

### Added
- Independent YUTIV Still Form user template with the 40 original routes, plus story, store notices, shipping/returns and coupon wallet.
- Four locales, dark palette, locally drawn SVG icons, configurable media slots and approved-storage image filtering.
- Null-safe public business information supplied by yutiv-storefront_support >=1.0.0.
- Inert extension points for future store branding, filters, rewards and server-validated order context.

### Changed
- Still Form paper/ivory surfaces, hairlines, generous spacing and consistent commerce/account styling replace the baseline visual system.
- Dynamic route prefix and existing ecommerce >=1.2.0 contracts are preserved; fallback currency formatting uses Intl.

### Security
- Pre-commit audit (2026-10-09): enforce sanitizer policy after custom options, sanitize editor initialization, replace icon-picker raw HTML with authored SVG, restrict public URLs, remove SEO/palette CDN dependencies and disable compression CDN workers.
- Exclude test setup declarations from dist and keep PHPUnit cache inside the new plugin; add local cache/output ignore rules.
- No demo data, external theme CDNs, installation, activation, database access or migration.
- Dependency scan: this is a new independent template and API consumer; no existing extension depends on it. Existing public core/module/plugin APIs are unchanged, so existing constraints remain unchanged.
