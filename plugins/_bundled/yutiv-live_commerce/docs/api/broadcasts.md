# 방송관리 0.7.0

기존 `docs/api/README.md`의 라이브 세션/업체 격리 및 ResponseHelper 봉투를 따른다. 소스: `LiveRoutes::operations`, `LiveBroadcastRequest`, `LiveCatalogRequest`, `LiveOfficeController::store`. 코어 Sanctum API가 아니라 기존 라이브 전용 세션과 CSRF를 사용하는 웹 경로다. root/live 도메인 스택 모두 등록된다.

## 조회 경로

| 메서드/URI | 파라미터 | 결과 |
|---|---|---|
| GET `/seller/broadcasts` | search(최대 200), channel_id, from/to(Y-m-d, 시작 예정일), state, page | 방송 목록 HTML, 20행/페이지 |
| GET `/seller/broadcasts/create` | list(최대 2000) | 참여자 미선택 신규 폼 |
| GET `/seller/broadcasts/{broadcast}` | list, product_search, category_id, stock=yes, items_page, candidates_page | 업체 소속 방송 상세 HTML |
| GET `/seller/broadcasts/channels` | 기존 edit/page | 기존 채널 등록·수정·목록 |
| GET `/seller/broadcasts/showhosts` | 기존 edit/page | 기존 쇼호스트 등록·수정·목록 |
| GET `/seller/channels`, `/seller/showhosts` | 기존 edit/page | 권한 확인 후 새 탭으로 302 |
| GET `/seller/broadcast-items` | broadcast_id 또는 edit(기존 방송상품 ID) | 업체 스코프 확인 후 방송 상세로 302. 미지정 시 방송 목록 |

전 경로 `broadcast` 권한 필수. 타 업체 ID는 404, 권한 없는 직원은 403, 미인증은 기존 로그인 처리. state는 draft/scheduled/selling/expired/closed만 허용한다. DB에는 기존 draft/selling/closed만 저장하며 scheduled/expired는 판매기간으로 계산한 조회 조건이다. 목록 정렬은 시작 예정 내림차순+고유 ID 내림차순, 상품은 position+ID, 후보는 product_id+ID다. 프로젝트의 총 건수 집계 상한을 적용하고 초과하면 ‘이상’으로 표시하며 다음 페이지 탐색은 계속한다.

`list`는 방송 목록의 search/channel_id/from/to/state/page만 보존한다. 서버가 키를 제한하고 `/seller/broadcasts` 내부 URL만 생성한다. 상세의 상품 검색·페이지는 `detail_query`로 같은 방식으로 보존한다.

## 방송 저장

POST `/seller/catalog/broadcasts/{record?}`. 기존 필드·의미를 유지한다. UI 추가 필드는 저장 컬럼이 아니다.

| 필드 | 검증/의미 |
|---|---|
| name, channel_id | 필수. name 최대 200, 채널은 같은 업체 |
| video_url | 선택, 승인된 HTTPS YouTube URL. 채널 URL을 자동 복사하지 않음 |
| starts_at, ends_at | 업체 현지 시간. ends_at > starts_at, 저장은 UTC |
| sale_starts_at, sale_ends_at | 기존 별도 판매시간. UI 모드 separate_sales=0이면 방송 예정시간으로 서버에서 정규화 |
| bank_account_id | 선택, 같은 업체. 미지정은 기존 업체 기본 계좌 |
| state | draft/selling/closed. 기존 closed→selling 금지 유지 |
| channel_percent, host_percent | 문자열 %, 소수 2자리, 각 0~100 및 합계 ≤100 |
| host_shares | 선택된 쇼호스트 ID→지분 % 객체. 같은 업체, 정규 양의 정수 ID. 최대 100명 |
| broadcast_workspace | 새 UI 모드 boolean. 생략은 기존 호출 계약 유지 |
| channel_payment | 새 UI의 지급 여부. false면 channel_percent=0 |
| separate_sales | 새 UI의 별도 판매시간 여부 |
| list, detail_query | 저장 후 복귀 조건. 검증 후 컬럼에서는 제거 |

UI 모드에서 참여자 없음은 쇼호스트 수수료 0%, 한 명은 지분 100%, 여러 명은 지분 합계 100%여야 한다. 일반 기존 호출도 수수료 계산의 기존 검증을 통과해야 한다. 판매 가능한 상태에만 검증을 미루지 않고 준비 상태에서도 완성된 지분을 요구한다. 참여자 미정 준비 방송은 0%로 저장한다. 활성 방송상품의 예외 요율도 변경된 참여자와 함께 검증하며 주문 진입에서도 동일 계산 검증을 거친다. 기존 주문 스냅샷은 수정하지 않는다.

요청 예:

```json
{"name":"20261021 오후방송","channel_id":1,"starts_at":"2026-10-21T15:00","ends_at":"2026-10-21T17:00","state":"draft","broadcast_workspace":"1","channel_payment":"1","channel_percent":"3.33","host_percent":"5.25","host_shares":{"2":"33.33","9":"66.67"},"separate_sales":"0","list":"search=오후&state=draft&page=2"}
```

성공 시 기존 봉투의 `data`에 `saved=true`, `record_id`, UI 모드에서는 `return_url`이 추가된다. JSON 응답 후 브라우저가 상세로 이동한다. 잘못된 날짜/요율/지분은 422이며 필드별 errors가 표시된다. 전송한 비검증 필드는 Service에 전달하지 않는다.

## 방송상품 저장

POST `/seller/catalog/broadcast-items/{record?}`. broadcast_id/option_id/position/active와 기존 channel_percent/host_percent 예외를 사용한다. position은 0~1,000,000,000 정수, active는 boolean, 예외 요율 빈 값은 방송 기본을 따른다. 새 UI의 broadcast_workspace/list/detail_query 및 응답 return_url도 동일하다. 기존 행을 다른 방송/옵션으로 이동시키는 요청은 409. 같은 방송·옵션 중복 추가는 기존 행을 반환해 중복 생성하지 않는다. 숨김/순서 변경은 공유 상품·옵션·재고를 수정하지 않는다.

## 고객 노출·주문

기존 GET `/shop`, `/shop/broadcasts/{broadcast}`, `/s/{code}`와 POST `/customer/tasks/cart`를 유지한다. 우선순위: 업체/판매 링크 유효성 → 방송 selling 및 판매 시작 포함·종료 미포함 → 해당 방송상품 active → 원본 상품 published/sellable 및 옵션 active → 가용 재고. 품절 상품은 버튼 비활성으로 표시하며 서버의 기존 재고 재확인을 생략하지 않는다. 동시 방송은 방송명을 구분하고, 판매 링크 코드는 다른 방송에 사용할 수 없다. 기존 예약·주문의 입금/출고/취소/반품 처리 규칙은 유지한다.
