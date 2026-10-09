# 상품 일괄등록 API

코드의 라우트·FormRequest를 ApiRouteInventory/FormRequestIntrospector/ApiDocScaffolder로 추출했습니다. 개발 서버 HTTP 실측은 하지 않았으며 아래 예시는 코드 계약을 설명합니다. 실제 HTTP 권한·확정 및 저장 동작은 SQLite 테스트에서 확인했습니다.

모든 요청은 Bearer 토큰과 본사 일괄등록·기존 상품등록의 범위 없는 관리자 권한이 필요합니다. 일반 회원/개인 범위 관리자는 403, 다른 관리자의 작업은 404입니다(최고관리자 제외). 성공 JSON은 기존 ResponseHelper 봉투를 따릅니다. 상세 사용법: [README](../../README.md).

### GET /api/plugins/yutiv-product_import/admin/product-imports
<!-- @generated:start:api.plugins.yutiv-product_import.admin.product-imports.index -->
- **라우트명**: `api.plugins.yutiv-product_import.admin.product-imports.index`
- **컨트롤러**: `Plugins\Yutiv\ProductImport\Http\Controllers\ImportController@index`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/yutiv-product_import/admin/product-imports HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 설명 |
| --- | --- | --- |
| items[] | object | id/filename/status/created_at, 최대 30건 |

**응답 예시**

```json
{
  "success": true,
  "message": "성공",
  "data": {
    "items": [
      {
        "id": "00000000-0000-4000-8000-000000000001",
        "filename": "products.xlsx",
        "status": "preview",
        "created_at": "2026-10-09T00:00:00.000000Z"
      }
    ]
  }
}
```

**에러 응답**

공통: 403 권한 없음, 작업 경로의 404 소유권/존재 오류, 확정/재시도 422 재검증 또는 큐 설정 오류, 업로드 429 빈도 제한.

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |

<!-- @generated:end -->

**설명**

요청자의 최근 작업 30개. 최고관리자는 전체 관리자 작업을 조회합니다.

### POST /api/plugins/yutiv-product_import/admin/product-imports
<!-- @generated:start:api.plugins.yutiv-product_import.admin.product-imports.preview -->
- **라우트명**: `api.plugins.yutiv-product_import.admin.product-imports.preview`
- **컨트롤러**: `Plugins\Yutiv\ProductImport\Http\Controllers\ImportController@preview`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| file | body | file | 예 | max 10240 | 업로드 파일 |

**요청 예시**

```http
POST /api/plugins/yutiv-product_import/admin/product-imports HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: multipart/form-data; boundary=----G7ExampleBoundary

------G7ExampleBoundary
Content-Disposition: form-data; name="file"; filename="products.xlsx"
Content-Type: application/octet-stream

(바이너리 파일 내용)
------G7ExampleBoundary--
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 설명 |
| --- | --- | --- |
| id | string(UUID) | 작업 ID |
| filename | string | 원본 이름 |
| status | string | preview / invalid / queued(확정됨); 완료 여부는 상품별 건수로 판단 |
| counts | object | total/succeeded/failed/queued/processing/preview 건수 |
| errors[] | object | sheet, row(원본 행), column, message, fix |
| rows[] | object | row/management_code/name/price/stock/category_codes/image_count/options/status/product_code/error |
| rows[].options[] | object | row/identifier/values/adjustment/stock |

**응답 예시**

```json
{
  "success": true,
  "message": "성공",
  "data": {
    "id": "00000000-0000-4000-8000-000000000001",
    "filename": "products.xlsx",
    "status": "preview",
    "counts": {
      "total": 1,
      "succeeded": 0,
      "failed": 0,
      "queued": 0,
      "processing": 0,
      "preview": 1
    },
    "errors": [],
    "rows": [
      {
        "row": 2,
        "management_code": "000123",
        "name": "예시 상품",
        "price": "12000",
        "stock": "5",
        "category_codes": "12",
        "image_count": 0,
        "options": [],
        "status": "preview",
        "product_code": null,
        "error": null
      }
    ]
  }
}
```

**에러 응답**

공통: 403 권한 없음, 작업 경로의 404 소유권/존재 오류, 확정/재시도 422 재검증 또는 큐 설정 오류, 업로드 429 빈도 제한.

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명**

multipart file 하나만 받습니다. 정상 파일의 행 오류는 HTTP 200, status=invalid 및 errors로 반환합니다. 손상/확장자/용량 오류는 422입니다. 상품 생성과 이미지 다운로드는 하지 않습니다.

### GET /api/plugins/yutiv-product_import/admin/product-imports/template
<!-- @generated:start:api.plugins.yutiv-product_import.admin.product-imports.template -->
- **라우트명**: `api.plugins.yutiv-product_import.admin.product-imports.template`
- **컨트롤러**: `Plugins\Yutiv\ProductImport\Http\Controllers\ImportController@template`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/yutiv-product_import/admin/product-imports/template HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet; Content-Disposition: attachment.

**응답 예시**

```http
HTTP/1.1 200 OK
Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet
Content-Disposition: attachment; filename="yutiv-products-template.xlsx"

(XLSX 바이너리)
```

**에러 응답**

공통: 403 권한 없음, 작업 경로의 404 소유권/존재 오류, 확정/재시도 422 재검증 또는 큐 설정 오류, 업로드 429 빈도 제한.

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |

<!-- @generated:end -->

**설명**

현재 실제 카테고리 경로가 포함된 빈 입력 양식. HTTP 200 XLSX 바이너리이며 JSON 봉투는 없습니다.

### GET /api/plugins/yutiv-product_import/admin/product-imports/{id}
<!-- @generated:start:api.plugins.yutiv-product_import.admin.product-imports.show -->
- **라우트명**: `api.plugins.yutiv-product_import.admin.product-imports.show`
- **컨트롤러**: `Plugins\Yutiv\ProductImport\Http\Controllers\ImportController@show`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
GET /api/plugins/yutiv-product_import/admin/product-imports/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 설명 |
| --- | --- | --- |
| id | string(UUID) | 작업 ID |
| filename | string | 원본 이름 |
| status | string | preview / invalid / queued(확정됨); 완료 여부는 상품별 건수로 판단 |
| counts | object | total/succeeded/failed/queued/processing/preview 건수 |
| errors[] | object | sheet, row(원본 행), column, message, fix |
| rows[] | object | row/management_code/name/price/stock/category_codes/image_count/options/status/product_code/error |
| rows[].options[] | object | row/identifier/values/adjustment/stock |

**응답 예시**

```json
{
  "success": true,
  "message": "성공",
  "data": {
    "id": "00000000-0000-4000-8000-000000000001",
    "filename": "products.xlsx",
    "status": "preview",
    "counts": {
      "total": 1,
      "succeeded": 0,
      "failed": 0,
      "queued": 0,
      "processing": 0,
      "preview": 1
    },
    "errors": [],
    "rows": [
      {
        "row": 2,
        "management_code": "000123",
        "name": "예시 상품",
        "price": "12000",
        "stock": "5",
        "category_codes": "12",
        "image_count": 0,
        "options": [],
        "status": "preview",
        "product_code": null,
        "error": null
      }
    ]
  }
}
```

**에러 응답**

공통: 403 권한 없음, 작업 경로의 404 소유권/존재 오류, 확정/재시도 422 재검증 또는 큐 설정 오류, 업로드 429 빈도 제한.

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명**

서버에 저장된 검증/진행 상태를 반환합니다. id는 UUID입니다.

### POST /api/plugins/yutiv-product_import/admin/product-imports/{id}/confirm
<!-- @generated:start:api.plugins.yutiv-product_import.admin.product-imports.confirm -->
- **라우트명**: `api.plugins.yutiv-product_import.admin.product-imports.confirm`
- **컨트롤러**: `Plugins\Yutiv\ProductImport\Http\Controllers\ImportController@confirm`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
POST /api/plugins/yutiv-product_import/admin/product-imports/{id}/confirm HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 설명 |
| --- | --- | --- |
| id | string(UUID) | 작업 ID |
| filename | string | 원본 이름 |
| status | string | preview / invalid / queued(확정됨); 완료 여부는 상품별 건수로 판단 |
| counts | object | total/succeeded/failed/queued/processing/preview 건수 |
| errors[] | object | sheet, row(원본 행), column, message, fix |
| rows[] | object | row/management_code/name/price/stock/category_codes/image_count/options/status/product_code/error |
| rows[].options[] | object | row/identifier/values/adjustment/stock |

**응답 예시**

```json
{
  "success": true,
  "message": "성공",
  "data": {
    "id": "00000000-0000-4000-8000-000000000001",
    "filename": "products.xlsx",
    "status": "queued",
    "counts": {
      "total": 1,
      "succeeded": 0,
      "failed": 0,
      "queued": 1,
      "processing": 0,
      "preview": 0
    },
    "errors": [],
    "rows": [
      {
        "row": 2,
        "management_code": "000123",
        "name": "예시 상품",
        "price": "12000",
        "stock": "5",
        "category_codes": "12",
        "image_count": 0,
        "options": [],
        "status": "queued",
        "product_code": null,
        "error": null
      }
    ]
  }
}
```

**에러 응답**

공통: 403 권한 없음, 작업 경로의 404 소유권/존재 오류, 확정/재시도 422 재검증 또는 큐 설정 오류, 업로드 429 빈도 제한.

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명**

빈 POST입니다. 브라우저 가격/행 입력은 무시합니다. 모든 행 재검증과 코드 예약 후 큐에 전송합니다. 반복 확정은 기존 작업을 반환합니다. 오류는 422이며 전체 확정 트랜잭션을 롤백합니다.

### GET /api/plugins/yutiv-product_import/admin/product-imports/{id}/result
<!-- @generated:start:api.plugins.yutiv-product_import.admin.product-imports.result -->
- **라우트명**: `api.plugins.yutiv-product_import.admin.product-imports.result`
- **컨트롤러**: `Plugins\Yutiv\ProductImport\Http\Controllers\ImportController@result`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
GET /api/plugins/yutiv-product_import/admin/product-imports/{id}/result HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet; Content-Disposition: attachment.

**응답 예시**

```http
HTTP/1.1 200 OK
Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet
Content-Disposition: attachment; filename="yutiv-import-result.xlsx"

(XLSX 바이너리)
```

**에러 응답**

공통: 403 권한 없음, 작업 경로의 404 소유권/존재 오류, 확정/재시도 422 재검증 또는 큐 설정 오류, 업로드 429 빈도 제한.

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명**

상품 결과/옵션 결과/검증 오류 XLSX. 원본 행과 문자열 입력을 유지합니다. HTTP 200 XLSX 바이너리이며 JSON 봉투는 없습니다.

### POST /api/plugins/yutiv-product_import/admin/product-imports/{id}/retry
<!-- @generated:start:api.plugins.yutiv-product_import.admin.product-imports.retry -->
- **라우트명**: `api.plugins.yutiv-product_import.admin.product-imports.retry`
- **컨트롤러**: `Plugins\Yutiv\ProductImport\Http\Controllers\ImportController@retry`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
POST /api/plugins/yutiv-product_import/admin/product-imports/{id}/retry HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 설명 |
| --- | --- | --- |
| id | string(UUID) | 작업 ID |
| filename | string | 원본 이름 |
| status | string | preview / invalid / queued(확정됨); 완료 여부는 상품별 건수로 판단 |
| counts | object | total/succeeded/failed/queued/processing/preview 건수 |
| errors[] | object | sheet, row(원본 행), column, message, fix |
| rows[] | object | row/management_code/name/price/stock/category_codes/image_count/options/status/product_code/error |
| rows[].options[] | object | row/identifier/values/adjustment/stock |

**응답 예시**

```json
{
  "success": true,
  "message": "성공",
  "data": {
    "id": "00000000-0000-4000-8000-000000000001",
    "filename": "products.xlsx",
    "status": "queued",
    "counts": {
      "total": 1,
      "succeeded": 0,
      "failed": 0,
      "queued": 1,
      "processing": 0,
      "preview": 0
    },
    "errors": [],
    "rows": [
      {
        "row": 2,
        "management_code": "000123",
        "name": "예시 상품",
        "price": "12000",
        "stock": "5",
        "category_codes": "12",
        "image_count": 0,
        "options": [],
        "status": "queued",
        "product_code": null,
        "error": null
      }
    ]
  }
}
```

**에러 응답**

공통: 403 권한 없음, 작업 경로의 404 소유권/존재 오류, 확정/재시도 422 재검증 또는 큐 설정 오류, 업로드 429 빈도 제한.

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명**

빈 POST입니다. 실패 행만 현재 권한/카테고리/중복/상품 규칙을 다시 검증하여 전송합니다. 성공 행은 변경하지 않습니다.

