# Catalog Translations API

추가 API의 소스 계약 및 격리 SQLite/HTTP fake 테스트 레퍼런스입니다. 실제 서버/유료 API를 호출해 측정한 문서가 아닙니다. 공통 봉투는 `ResponseHelper::moduleSuccess/moduleError`, 인증은 Sanctum Bearer + admin입니다.

기본 URI: `/api/modules/sirsoft-ecommerce/admin/catalog-translations`.

| Method / URI | 권한·입력 | 응답 data |
| --- | --- | --- |
| GET `/configuration` | products.create/update, categories.create/update, settings.read 중 하나 | configured boolean, status(config_missing/disabled/not_configured/ready), settings_url string, queue string. 키/endpoint/모델 비공개 |
| POST 기본 URI | kind별 create 권한, entity_id 지정 시 update 권한 + 실제 존재 | 작업 객체. 상품/카테고리는 저장하지 않음 |
| GET `/{id}` | UUID, 본인 작업 + 현재 동일 권한 + 대상 존재 | 최신 작업 상태. killed worker는 timeout 처리 |
| POST `/{id}/retry` | 본인 작업 + 현재 권한, body 없음 | 실패 항목만 pending으로 변경. 성공/건너뜀 보존, 최대3회, 취소/만료 작업 재시도 안 함 |
| POST `/{id}/cancel` | 본인 작업 + 현재 권한, body 없음 | cancelled=true. 이미 시작한 공급자 요청은 취소하지 못하지만 결과 적용 금지 |

POST 시작 입력:

| 필드 | 자료형/검증 |
| --- | --- |
| request_id | 필수 UUID. 동일 요청의 재전송은 동일값. 동일 UUID에 다른 내용은422 |
| kind | product/category |
| entity_id | nullable integer, 실제 상품/카테고리 ID. 신규 폼은 null |
| terms | 필수 array, 최대100개 string, 각각1~100자 |
| items | 필수 array 1~200개(언어별 합계), JSON UTF-8 최대512KiB, 전체 HTTP 최대1MiB |
| items[].id | 필수 distinct string 최대100자, `[A-Za-z0-9:_-]`만. 표시 문구가 아닌 안정적 식별자 |
| items[].field | name/description/meta_title/meta_description; product만 meta_keywords/option_group_name/option_value/option_name/additional_option_name/additional_option_value |
| items[].source | present nullable string 최대65535자. 한국어 원문, 빈값은 skipped |
| items[].current | present nullable string 최대65535자. 대상 언어의 현재 값 |
| items[].locale | en/ja/zh-CN |
| items[].html | 필수 boolean. description만 true 가능 |
| items[].overwrite | 필수 boolean. 기본 false, existing current는 skipped |

허용되지 않은 item 필드는 거부합니다. 금액·ID·SKU·분류 연결 등은 번역 입력/결과로 받지 않습니다.

시작 예시(격리 fake 검사에 대응, 예시 토큰/키는 실제 비밀 아님):

```http
POST /api/modules/sirsoft-ecommerce/admin/catalog-translations
Authorization: Bearer {ADMIN_TOKEN}
Content-Type: application/json

{"request_id":"01d1717e-8b38-4c6f-8536-71eebc60ec03","kind":"product","entity_id":null,"terms":["YUTIV"],"items":[{"id":"name:field-name:en","field":"name","source":"한국어 상품","current":"","locale":"en","html":false,"overwrite":false}]}
```

작업 응답 필드: id/request_id UUID, owner_id integer, fingerprint SHA256, kind, entity_id nullable integer, terms array, cancelled boolean, created_at/updated_at, items array. items는 요청 필드와 status(pending/processing/completed/failed/skipped), attempts integer, result nullable string, error nullable enum(not_configured/timeout/invalid_response/provider_failed/provider_rate_limited), processing 후 started_at Unix timestamp를 포함합니다. HTML 결과는 원본 태그/속성을 유지합니다. 결과는 관리자 검토 후 **기존 상품/카테고리 저장 API**에 명시적으로 저장합니다.

생성/재시도는 인증된 관리자별 합계 분당 5회입니다. 상품·카테고리·작업 ID와 무관한 전용 `ecommerce-catalog-translation` limiter를 사용하며 조회/설정/취소는 이 예산을 소모하지 않습니다. 6번째 응답은 HTTP 429, `Retry-After`, `X-RateLimit-Limit: 5`, `X-RateLimit-Remaining: 0`과 다음 안전한 봉투를 제공합니다.

```json
{"success":false,"message":"요청이 많습니다. 28초 후 다시 시도해 주세요.","errors":{"code":"catalog_translation_rate_limited","retry_after":28}}
```

시간은 실제 남은 대기 시간에 따라 달라집니다. 프런트는 헤더의 Retry-After 동안 생성/재시도를 잠그고 자동 재전송하지 않습니다. 제공자 HTTP 429는 작업 항목의 `provider_rate_limited` 실패로 기록하며 위 YUTIV API 응답과 구분합니다.

```json
{"success":true,"message":"번역 작업 조회 완료","data":{"id":"01d1717e-8b38-4c6f-8536-71eebc60ec03","kind":"product","entity_id":null,"cancelled":false,"items":[{"id":"name:field-name:en","field":"name","source":"한국어 상품","current":"","locale":"en","html":false,"overwrite":false,"status":"completed","attempts":1,"result":"Translated 상품","error":null}]}}
```

위 봉투는 주요 필드만 보여주는 fake 결과 예시입니다. GET configuration 예시:

```json
{"success":true,"message":"번역 작업 조회 완료","data":{"configured":false,"status":"disabled","settings_url":"/admin/ecommerce/settings?tab=language_currency","queue":"ecommerce-translation"}}
```

오류: 401 미인증, 403 일반회원/권한부족/타인작업/대상삭제, 404 작업없음, 413 body 초과, 422 입력·키 미설정·활성작업·UUID 충돌, 429 시작/재시도 분당5회 초과. 권한 오류는 기존 exception envelope를 사용합니다. 공급자 오류는 원문 입력을 지우지 않고 items[].error로 반환하며 raw 오류/비밀키를 노출하지 않습니다.

기존 저장 API의 선택적 추가 필드:
- products: `meta_keywords_translations` locale=>string(각500자), `translation_sources` field:locale=>8자리hex map(100개 이하).
- categories: `meta_title_translations` locale=>string(각200자), `meta_description_translations` locale=>string(각500자), `translation_sources` 같은 형식.
- 기존 name/description/선택·추가옵션 JSON 저장 API와 서비스는 동일합니다. categories의 기존 meta_title/meta_description 문자열, products의 기존 meta_keywords string[]은 유지합니다.
- 공개 category detail은 meta_title/meta_description을 현재 언어와 legacy fallback으로 추가 반환합니다. 공개 product의 meta_keywords는 현재 언어 CSV를 기존 string[] 형태로 반환하고 없으면 기존 배열을 사용합니다. name_localized/description_localized/옵션 표시 fallback은 빈 번역을 건너뜁니다.

제공자 설정·워커·migration·사용법·미검증 범위는 [catalog-ai-translation.md](../catalog-ai-translation.md)를 참조하세요.
