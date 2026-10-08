# Storefront Support API

Route source: `src/routes/api.php`, controller `BusinessInfoController::show`. One public read-only endpoint. G7 automatically applies the plugin URI/name prefix; Bearer token is not required. No parameters or request body. Rate limit: 60 requests/minute via Laravel throttle, 429 when exceeded. Inactive plugin: endpoint unavailable; template omits business rows.

## GET /api/plugins/yutiv-storefront_support/business-info

```http
GET /api/plugins/yutiv-storefront_support/business-info
Accept: application/json
```

Successful response uses the common ResponseHelper envelope. `data` always has exactly these string-or-null fields:

| Field | Source |
| --- | --- |
| company | basic_info.company_name |
| representative | basic_info.ceo_name |
| business_number | all three basic_info.business_number_* parts, joined with hyphens |
| mail_order_number | basic_info.mail_order_number |
| address | public base_address + detail_address |
| phone | all three public phone_* parts |
| email | public email_id + email_domain |
| hosting | plugin hosting setting |
| verification_url | plugin HTTPS URL, no credentials |

```json
{"success":true,"message":"Success","data":{"company":null,"representative":null,"business_number":null,"mail_order_number":null,"address":null,"phone":null,"email":null,"hosting":null,"verification_url":null}}
```

No admin IDs, privacy-officer contact, authentication settings, internal settings or secrets are returned. Whitespace-only/non-string values return null. Resource repeats the allowlist at serialization. HTTP 500 follows core exception handling; no fallback business identity is generated. Standard public throttle headers follow the core middleware configuration.

The route/controller scaffold above is derived directly from the new source. The global Artisan doc generator was not executed because this task forbids application/DB lifecycle work; regenerate the extension index in an isolated application before release.
