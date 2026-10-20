# Office API 레퍼런스

> **소유**: plugin `yutiv-live_commerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Office 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/plugins/yutiv-live_commerce/admin/office
<!-- @generated:start:api.plugins.yutiv-live_commerce.admin.office -->
- **라우트명**: `api.plugins.yutiv-live_commerce.admin.office`
- **컨트롤러**: `Plugins\Yutiv\LiveCommerce\Http\Controllers\Admin\LiveManagementController@index`
- **인증/권한**: `auth:sanctum` + `permission:yutiv-live_commerce.management.manage`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/plugins/yutiv-live_commerce/admin/office HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: request-failed: cURL error 7: Failed to connect to 127.0.0.1:8189 after 1 ms: Could not connect to server (see https://curl.se/libcurl/c/libcurl-errors.html) for http://127.0.0.1:8189/api/plugins/yutiv-live_commerce/admin/office?per_page=25 — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: request-failed: cURL error 7: Failed to connect to 127.0.0.1:8189 after 1 ms: Could not connect to server (see https://curl.se/libcurl/c/libcurl-errors.html) for http://127.0.0.1:8189/api/plugins/yutiv-live_commerce/admin/office?per_page=25 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`yutiv-live_commerce.management.manage`)이 없는 경우 |

<!-- @generated:end -->

**설명**

선택 업체와 초기 폼 데이터를 본사 권한으로 읽는다. query tenant(양의 정수)는 선택 사항이며 없는 경우 selected=null이다.

```http
GET /api/plugins/yutiv-live_commerce/admin/office?tenant=1
Authorization: Bearer <본사 관리자 토큰>
Accept: application/json
```

```json
{"success":true,"message":"...","data":{"tenants":[{"id":1,"name":"Golfif","slug":"golfif","status":"active"}],"selected":{"id":1,"name":"Golfif","slug":"golfif","status":"active","timezone":"Asia/Seoul","reservation_minutes":60,"return_days":7},"accounts":[],"staff":[],"domains":[],"audit":[],"summary":{},"legacy_users":[]}}
```

selected는 현재 업체 정보, accounts/staff/domains/audit/summary는 선택 업체 범위다. legacy_users는 본사 확인 대기 계정이며 자동 귀속하지 않는다. 인증401, 권한403, 없는 업체404다.
