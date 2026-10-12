# 독립 라이브 API 0.2.0

## 공통 계약

본사 API prefix: `/api/plugins/yutiv-live_commerce`.
Bearer Sanctum + G7 관리자 + `yutiv-live_commerce.management.manage`가 필요합니다.
판매자/고객 업무 POST는 **승인된 업체 호스트**의 web 세션 + CSRF입니다.
업체 ID를 URL query/body로 보내도 판매자 컨텍스트를 바꾸지 못합니다.
본사만 승인된 관리자 권한으로 경로의 업체를 선택합니다.
읽기 목록은 ResponseHelper `success/message/data`, 오류는 해당 HTTP 상태와 `message/errors`입니다.
401 미인증(HTML은 로그인302),403 권한,404 없는/타업체 자원,409 상태/멱등 입력 충돌,422 입력 오류,419 CSRF.
직원·재설정·통관 응답은 no-store. 비밀 필드는 일반 모델 JSON·감사 기록에서 제외합니다.

```http
POST /seller/tasks/deposit
Content-Type: application/json
Accept: application/json
X-CSRF-TOKEN: <해당 세션의 토큰>

{"order_id":12,"amount":100000,"reason":"입금 내역 대사","operation_key":"9a871fb0-52f1-4442-af42-2543ae81f093"}
```

```json
{"success":true,"message":"...","data":{"state":"paid"}}
```

`operation_key`는 1~100자, 같은 작업·같은 키·같은 입력은 원래 결과를 반환합니다.
다른 입력으로 재사용하면409입니다. URL/UUID/ID는 응답·선택 UI에서 받은 현재 업체 자원만 사용합니다.

## 본사

| 메서드 / 경로 | 입력 | data |
|---|---|---|
| GET `/admin/office` | query tenant 선택(없으면목록) | 업체 목록, 선택업체, 직원/도메인/감사/요약 |
| POST `/admin/{tenant}/tenant` | name,slug,status=draft/active/suspended,timezone,reservation_minutes(1..10080),return_days(0..365); 새 업체 tenant=0 | tenant |
| POST `/admin/{tenant}/domain` | host(정확한 DNS 호스트),active:boolean,primary:boolean | domain |
| POST `/admin/{tenant}/account` | name,username,email?,role=owner/sales/warehouse/finance/customs,user_id?(재발급) | user_id,username,temporary_password |
| POST `/admin/{tenant}/permission` | user_id,role,active:boolean | saved |
| POST `/admin/{tenant}/lock` | user_id,locked:boolean | saved |
| POST `/admin/{tenant}/legacy` | user_id,reason(3..1000자) | saved |
| POST `/admin/{tenant}/logo` | multipart image PNG/JPEG/WebP <=5MB | saved, URL 등 미디어 결과 |

도메인 저장은 루트 캐시를 갱신하지만 DNS/TLS는 변경하지 않습니다.
임시 비밀번호 재조회 API는 없습니다. 계정 발급 응답은 로그로 남기지 마세요.

## 판매자 카탈로그

`POST /seller/catalog/{entity}/{record?}`. record 생략은 생성, 있으면 같은 업체 수정.
응답 `data={saved:true,record_id:123}`. FormRequest `LiveCatalogRequest`, UI 필드정의 `LiveForms::fields()`.

| entity | 주요 입력 | 권한 |
|---|---|---|
| products | name,code,description?,published:boolean,sellable:boolean | catalog |
| options | product_id,name,price:정수원,discount:정수원,active:boolean | catalog |
| channels | name,url(YouTube HTTPS),active:boolean | broadcast |
| showhosts | name,active:boolean | broadcast |
| broadcasts | name,channel_id,state,starts_at,ends_at,sale_starts_at,sale_ends_at,channel_percent,host_percent,host_shares[진행자ID],bank_account_id? | broadcast |
| broadcast-items | broadcast_id,option_id,channel_percent?,host_percent?,active:boolean | broadcast |
| sale-links | broadcast_id,channel_id,showhost_id?,option_id?,contact,expires_at?,active:boolean | broadcast |
| banks | bank,account_number,holder,default:boolean,active:boolean | banks |

퍼센트는 `"3.25"`처럼 0..100 소수2자리 문자열입니다. 합계 및 공동 진행 지분은 서버에서 검사합니다.
지역 시각 datetime-local은 업체 시간대로 해석하여 UTC 저장합니다. 종료 방송 재개·옵션의 상품 변경은409입니다.
`POST /seller/media/{logo|product}`는 multipart image(제품은product_id), 미디어 요청은 해당업무권한.

## 판매자 작업

모두 `POST /seller/tasks/{action}`. 아래의 쓰기에는 별도 표시가 없으면 operation_key가 필요합니다.
QR은 UUID 또는 입력기에서 정규화하는 `U:UUID`이며, 일반 사용자는 내부 DB ID를 직접 입력하지 않습니다.

| action | 입력 | 권한 / 결과 |
|---|---|---|
| receive | option_id,quantity(1..200),location | inventory / units:[UUID] |
| scan | qrs[1..200],order_id?; 키 불필요 | inventory 또는 shipping / items,scanned,remaining,partial,changed=false |
| ship | order_id,qrs[1..200] 중복불가 | shipping / 출고 결과 |
| transition | qr,state=available/as/defective/lost/disposed,location,reason | inventory / unit,state |
| as-receive / as-dispatch | qr,location,reason | inventory / unit,state,sales_unchanged=true |
| stock-correction | qr,movement_id,reason | inventory / unit,state |
| deposit | order_id,amount(0..1조),reason | deposit / state=paid/review |
| resolve-deposit | order_id,amount,reason | deposit / state=paid |
| delivered | order_id | shipping / delivered |
| return | line_id,qrs,reason | inventory / 반품 결과 |
| return-review | line_id,resolved=true,reason | inventory / saved |
| refund | order_id,amount(1..1조),reason | refund / 환불기록,실제송금false |
| incentive | line_id,beneficiary=channel:ID 또는 host:ID,amount | settlement / recorded,bank_transfer=false |
| recovery | line_id,beneficiary,amount,reason | settlement / recorded,bank_transfer=false |
| attribution | line_id,channel_id,showhost_id?,reason | settlement / saved,original_snapshot_unchanged |
| bank-notice | order_id,bank_id,reason | banks / saved,external_message_sent=false |
| label-print | qrs,width(30..100mm),height(20..100mm) | labels / recorded,physical_print_verified=false |
| brand | name,kakao_url?,telegram_url?; 키 불필요 | brand / saved |
| staff | name,username,role(소유자제외); 키 불필요 | staff / 임시비밀번호 한번 |
| staff-permission | user_id,role,active; 키 불필요 | staff / saved |
| customs | user_id; 키 불필요 | customs / 통관번호 업무 조회,감사 기록 |

reason은 최대1000자이며 통관번호 원문 등 개인정보를 넣지 않습니다.
미리보기는 읽기 전용입니다. 요청 성공 응답을 받기 전에는 재고가 바뀌었다고 가정하지 마세요.
실패 재시도는 같은 작업 키를 유지하고 성공 후 새 업무에 새 키를 사용합니다.

## 고객

`POST /customer/tasks/{action}`, ResponseHelper. forgot/reset/cart 외에는 로그인 필요.

| action | 입력 | data |
|---|---|---|
| forgot | email | 일반 안내(계정 존재여부 비노출) |
| reset | token(64hex),password,password_confirmation | saved |
| password | current_password,password,password_confirmation | saved |
| profile | name,customs_code?(P+12숫자) | saved |
| address | name,phone,address,postal_code | saved |
| cart | broadcast_id,option_id,quantity(1..200),code?(48hex),operation_key | line UUID |
| order | lines(UUID배열1..50),address_id,operation_key | order UUID |

`POST /orders/{uuid}/{cancel|return|confirm}`: 본인 주문만 접근.
cancel은 line_id,quantity,reason,operation_key; return은 line_id,reason,operation_key;
confirm은 operation_key. 성공 data.saved=true. 출고 후 취소는 거절하고 반품 요청을 사용합니다.

```json
{"lines":["9a871fb0-52f1-4442-af42-2543ae81f093"],"address_id":7,"operation_key":"checkout-f523"}
```

```json
{"success":true,"message":"...","data":{"order":"c0f135a7-a330-481d-91f1-54aad470bce6"}}
```

## 화면 및 조회

GET `/seller/{section}` (dashboard,broadcasts,channels,showhosts,products,options,broadcast-items,
sale-links,orders,banks,receive,ship,inventory,returns,statistics,incentives,labels,brand,staff,audit),
`/seller/order/{uuid}`. HTML이며 해당 역할만 접근합니다.
statistics: from/to(날짜),basis=created_at/paid_at,period=day/week/month,
broadcast_id/channel_id/showhost_id/product_id/option_id 선택.
labels: receipt=입고물품UUID,unit=단일UUID,width,height. inventory: unit=물품UUID.
GET `/shop`, `/shop/products/{product}`, `/shop/broadcasts/{broadcast}`, `/s/{code}`, `/cart`, `/orders/{uuid}`, `/my`.
공개 상품은 공개/판매 가능 여부와 방송 판매 기간을 다시 검사합니다. 주문은 가격과 재고를 서버에서 다시 확인합니다.
