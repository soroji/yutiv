# Logo API 레퍼런스

> **소유**: plugin `yutiv-live_commerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Logo 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### POST /api/plugins/yutiv-live_commerce/admin/{tenant}/logo
<!-- @generated:start:api.plugins.yutiv-live_commerce.admin.logo -->
- **라우트명**: `api.plugins.yutiv-live_commerce.admin.logo`
- **컨트롤러**: `Plugins\Yutiv\LiveCommerce\Http\Controllers\Admin\LiveManagementController@logo`
- **인증/권한**: `auth:sanctum` + `permission:yutiv-live_commerce.management.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| tenant | path | string | 예 | — | 대상 tenant의 식별자 |
| image | body | image | 예 | max 5120 | <!-- TODO: 용도 --> |

**요청 예시**

```http
POST /api/plugins/yutiv-live_commerce/admin/{tenant}/logo HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: multipart/form-data; boundary=----G7ExampleBoundary

------G7ExampleBoundary
Content-Disposition: form-data; name="image"; filename="example.png"
Content-Type: image/png

(바이너리 파일 내용)
------G7ExampleBoundary--
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: unresolved-path-param — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`yutiv-live_commerce.management.manage`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명**

본사에서 선택 업체 로고를 기존 업로드 서비스로 저장한다. image는 PNG/JPEG/WebP, 최대5MB다. 인증401, 권한403, 없는 업체404, 파일 검증 실패422다.

```http
POST /api/plugins/yutiv-live_commerce/admin/1/logo
Authorization: Bearer <본사 관리자 토큰>
Content-Type: multipart/form-data; boundary=example

--example
Content-Disposition: form-data; name="image"; filename="logo.png"
Content-Type: image/png

<PNG 바이너리>
--example--
```

```json
{"success":true,"message":"...","data":{"saved":true,"image_url":"/live-media/logo/logo"}}
```
