# 판매자 상품 작업 계약 0.5.0

승인 업체 호스트의 TeeWide web 세션 및 기존 직원 권한을 사용한다. POST는 CSRF 필수. 업체 ID를 요청으로 변경할 수 없다. JSON 변경 성공은 ResponseHelper `{success:true,message:...,data:...}`이며 타 업체404, 권한403, 입력422, 멱등 충돌409이다. 읽기/미리보기는 HTML이다. HEAD/파라미터 검증은 Feature 및 설치 브라우저에서 확인했다.

| 메서드·경로 | 이름 (root/live 각각 teewide.ops.* 접두사) | 권한·요청 | 응답 |
|---|---|---|---|
| GET /seller/products | products.index | catalog 또는 inventory 또는 labels. search, category_id(0 미분류), date_basis(created/received), from/to(Y-m-d), stock(yes/no), unit_state(기존 enum), sort(newest/oldest/name), per_page(20/50/100), page | 정확한 전체 수와 페이지 HTML |
| GET /seller/products/{product} | products.show | 동일, 업체 상품. list=목록 쿼리 문자열 | 상세/입고/물품 페이지. 과거 단가는 settlement만 |
| GET /seller/product-receive | products.receive | inventory, product_id면 해당 상품 고정. 신규/복사 catalog 추가. copy=1, list, search/page | 입고 폼. 신규 권한 없는 무선택 창고는 목록302 |
| POST /seller/receipt | seller.receipt (기존) | inventory, 신규/복사 catalog 추가. 아래 입고 본문 | receipt_id, product_id, quantity, return_url |
| GET /seller/product-qr | products.qr | labels. products[] 현재 페이지 선택 ID, batch, unit UUID, original_id, legacy_audit_id, search, unit_state, list | 실제 물품50개 페이지, 전체 대상 수, 최근 배치/이력 |
| POST /seller/product-actions/category | products.action | catalog. id 선택, name<=100, active boolean | saved=true |
| POST /seller/product-actions/product | products.action | catalog. id,name,code,description,category_id,published/sellable boolean | saved=true |
| POST /seller/product-actions/preview | products.action | labels. qrs[] 1~200 고유 UUID, width30~100, height20~100, copies1~10, original_id/legacy_audit_id 선택. 총장수<=200 | QR 미리보기 HTML, 기록 없음 |
| POST /seller/product-actions/print | products.action | preview와 동일 + operation_key 영숫자/하이픈<=64 | request_id,total_labels,physical_print_verified=false |
| GET /seller/labels | products.legacy-labels | labels | 조건 보존하여 QR 작업302 |
| GET /seller/receive | products.legacy-receive | inventory | 상품 목록302 |

입고 예 (상품정보 신규/복사만, 기존은 product_id+option_id 사용):

```json
{"operation_key":"example-050","mode":"new","name":"셔츠","code":"SHIRT-050","published":false,"sellable":true,"location":"A 선반","axes":[{"name":"색상","values":["검정"]}],"items":[{"name":"색상: 검정","attributes":{"색상":"검정"},"price":15000,"discount":1000,"unit_cost":7000,"quantity":2}],"list":"search=SHIRT&page=2"}
```

입고 단가 누락/음수/소수는422, 0은 유효. 단가 최대10억 원, 총200개/50옵션. axes 최대4, 각20값, 전체50조합. 기존 option_id가 있으면 가격·명칭 변경을 입고에 적용하지 않는다. 기존 옵션을 제외하거나 수량0으로 두어도 DB 옵션은 삭제되지 않는다. 입고 후 목록 return_url은 고정 경로와 허용 목록 필터만 사용한다.

인쇄 예 (실제 UUID로 치환):

```json
{"operation_key":"print-050","qrs":["b27684e0-30fd-413b-acb4-5fb12762db13"],"width":50,"height":30,"copies":2}
```

응답 예: `{"success":true,"message":"...","data":{"request_id":3,"total_labels":2,"physical_print_verified":false}}`. 동일 키/동일 입력은 동일 요청 번호. 대상/매수 등이 바뀌면409. 재출력은 새 키와 original_id 또는 과거 legacy_audit_id를 전달하며 원본 대상 밖 UUID는 거절한다. 별도 비용/수익/세금 필드나 비용 수정 API는 없다.
