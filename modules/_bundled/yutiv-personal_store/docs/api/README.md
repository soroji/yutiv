# Personal Store API 0.1.2

Base: `/api/modules/yutiv-personal_store`. JSON requests and ResponseHelper envelopes
(`success`, `message`, `data`) follow the core API conventions. Operator and headquarters
endpoints require a Sanctum Bearer token; the authenticated user determines the store.
Supplying another `store_id` never changes operator scope. Headquarters requires
`yutiv-personal_store.headquarters.manage` (Admin permission) or super admin.

| Method | Path | Request | Response `data` |
|---|---|---|---|
| GET | `/stores/{slug}` | Active public slug | Public slug, introduction, logo/banner URLs |
| POST | `/stores/{slug}/origin` | Same-origin request; no body | `proof`; also HttpOnly SameSite=Lax attribution cookie |
| GET | `/membership-proof` | Optional attribution cookie | Purpose-separated membership `proof`, or null |
| GET | `/stores/{slug}/products` | `page`, `search` | Selected, purchasable product summaries and pagination |
| GET | `/stores/{slug}/products/{code}` | Product code | Existing ecommerce ProductDetailResource, restricted to selected product |
| GET | `/operator` | `page`, `ledger_page`, `children_page`, `statistics_page`, `from`, `to`, `search` | Own profile, wallet, ledger, children, closed order rows, own product statistics, descendant aggregate |
| GET | `/operator/catalog` | `page`, `search` | Purchasable catalog, selected status, integer reward estimates |
| GET | `/operator/categories` | `page`, `search` | Active category choices with localized ancestor `path` |
| GET | `/operator/parents` | `page`, `search` (public store name, <=100 characters) | Active candidates excluding self/descendants; only `id`, `name`; `page`, `has_more` |
| POST | `/operator/media` | Multipart `file`, `collection` (`logo` or `banner`) | AttachmentResource, HTTP 201; active owner only |
| POST | `/operator` | Action below | Updated own dashboard |
| GET | `/admin` | `page`, `rates_page`, `history_page`, `stores_page` | Applications, stores, product rates, policy history and review/outbox counts |
| POST | `/admin` | Headquarters action below | Updated dashboard |

Lists use bounded pagination: `total`, `total_relation`, `has_more`, `current_page`,
`per_page`; totals above the configured cap are lower bounds. A search term is limited
to 100 characters. Dates are ISO calendar dates; `to` must not precede `from`.
No operator download/export endpoint exists.

### Operator selection UI (0.1.2)

Catalog search matches localized product name or product code. Items add `thumbnail_url`
and `selection` (`include`, `exclude`, `none`); `selected` remains the effective category/individual
selection. No buyer information is returned. `/operator` adds `application_status` and
`pending_estimate`: unconfirmed, uncancelled, non-review beneficiary amounts (an estimate,
not an available balance or a guarantee of future payment).

Parent search requires an active owned store. Example request:
`GET /api/modules/yutiv-personal_store/operator/parents?search=sample&page=1`.
Example data: `{"data":[{"id":12,"name":"sample-store"}],"page":1,"has_more":false}`.
Store IDs are transport fields for the selector, never user-entered identifiers.

Media example: multipart POST to `/operator/media` with `collection=logo` and an image
`file`. Only JPEG/PNG/WebP, <=2 MiB and <=4096x4096 are accepted. The response follows
the core AttachmentResource envelope with `data.download_url`, `data.hash`, `data.is_image`.
The server fixes the attachment target to the authenticated user's active store;
`store_id`/attachment target input cannot select another store. 401: unauthenticated;
403: no active owned store; 422: invalid image/collection. This adapter reuses the core
AttachmentService and Still Form FileUploader without granting admin attachment permissions.
The subsequent profile save persists the selected URL; uploaded previews are not profile saves.

Operator actions:

- `apply`: `slug` (lowercase ASCII/hyphens, <=80), optional introduction (<=2000),
  logo_url/banner_url (HTTP/HTTPS, <=2048). One store per user. A root awaits headquarters
  approval; an attributed child derives its parent from server membership.
- `profile`: same profile fields; own store only.
- `products`: existing `product_id`, boolean `excluded`. False explicitly includes;
  true excludes even when a selected category includes it.
- `categories`: `category_ids` array of distinct existing IDs; empty clears selections.
- `allocation`: direct `child_id`, integer `rate_bps` in 0..10000.
- `request_move`: optional existing `parent_id`; creates an approval application, not a move.

Headquarters actions:

- `approve`: positive `application_id`; approved parent and graph are validated under lock.
- `product_rate`: existing `product_id`, integer `rate_bps` in 0..10000.
- `status`: existing `store_id`, status from `StoreStatus`.

Order rows contain only `reference` (per-store HMAC), `ordered_at`, `product`, `quantity`,
`payment_amount`, `currency`, `reward`, `point_status`, `order_status`. They contain no
buyer identity, address/contact, original order number, payment identifier or refund account.
Descendants expose aggregate sales only, not their order rows.

Example: `POST /operator` with `{"action":"allocation","child_id":42,"rate_bps":2000}`.
An operator order row inside the successful response's `data.orders` array is:

```json
{"reference":"opaque-per-store-reference","ordered_at":"2026-10-11 12:00:00","product":"Example product","quantity":1,"payment_amount":9000,"currency":"KRW","reward":200,"point_status":"pending","order_status":"paid"}
```

The example uses synthetic values; status values reflect the stored order and reward state.
Successful requests use HTTP 200. Invalid fields/proofs return 422; unauthenticated requests
401, unauthorized/cross-store access 403, absent or unavailable public resources 404.
The storefront passes the origin proof in `X-Yutiv-Store-Origin` on ecommerce requests;
ordinary shopping without this header remains unattributed. It never trusts a bare store ID.

Rewards have no spend, withdrawal or mileage transfer API. Only KRW snapshots are eligible.
The outbox/reconciliation CLI is documented in [IMPLEMENTATION.md](../IMPLEMENTATION.md).
