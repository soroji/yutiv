# Admin API 레퍼런스

> **소유**: plugin `yutiv-live_commerce` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Admin 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### POST /api/plugins/yutiv-live_commerce/admin/{tenant}/{action}
<!-- @generated:start:api.plugins.yutiv-live_commerce.admin.store -->
- **라우트명**: `api.plugins.yutiv-live_commerce.admin.store`
- **컨트롤러**: `Plugins\Yutiv\LiveCommerce\Http\Controllers\Admin\LiveManagementController@store`
- **인증/권한**: `auth:sanctum` + `permission:yutiv-live_commerce.management.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| tenant | path | string | 예 | — | 대상 tenant의 식별자 |
| action | path | string | 예 | — | 대상 action의 식별자 |

**요청 예시**

```http
POST /api/plugins/yutiv-live_commerce/admin/{tenant}/{action} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
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
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명**

action은 tenant/domain/account/reset-password/permission/lock/legacy다. action별 요청·응답은 [공통 계약](README.md)을 따른다.
예를 들어 기존 직원의 프로필/권한을 유지한 비밀번호 재설정은 다음과 같다.

```http
POST /api/plugins/yutiv-live_commerce/admin/1/reset-password
Authorization: Bearer <본사 관리자 토큰>
Content-Type: application/json

{"user_id":7}
```

```json
{"success":true,"message":"...","data":{"user_id":7,"username":"operator","temporary_password":"<일회 표시>"}}
```

운영 비밀번호 원문은 예시에 기록하지 않는다. tenant=0은 신규 업체 생성에만 사용한다. 필드 검증 실패는422이며 errors에 필드별 메시지를 담는다.
