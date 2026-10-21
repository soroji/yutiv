# 재고 실사·회계관리 0.6.0

업체 호스트 web 세션·CSRF·기존 LiveContext의 역할 권한을 사용한다. 요청 tenant_id는 무시하며 모든 ID/UUID는 현재 업체 안에서 조회한다. 금융 조회·입력은 수동 업무 기록이며 은행 실행 API가 아니다. 금액 문자열·통화 enum·일자·요청 키는 FormRequest에서 검증한다.

## GET /seller/product-stock

권한 inventory. product_id/option_id(양의 정수), count(실사 ID), unit(UUID), location(100자), list(기존 목록 query, 최대4000자), page. HTML: 현재 옵션/위치, 실사 기준·QR·변경 내역, 물품 50개/페이지·실사 20개/페이지. `/seller/inventory`는 query를 유지해 이 경로로 이동한다.

## POST /seller/product-stock/{action}

공통 operation_key(영숫자/하이픈64자), list(선택). draft는 inventory, confirm은 inventory.count-confirm.

| action | 필수 입력 | 응답 data |
|---|---|---|
| draft | option_id, location, counted_quantity(0~1000000), target_state(lost/disposed/available), reason; 선택 id/revision, qrs(UUID배열≤200), refresh(boolean) | count_id, revision, return_url |
| confirm | id, revision | count_id, difference, return_url |

확정 때 재고 revision·초안 revision·위치·QR 실제 상태·차이 수량을 확인한다. 409는 다시 조회/기준 갱신. 미식별 차이를 임의 물품에 배정하지 않는다.

## GET /seller/accounting/{section?}

overview/sales/expenses/accounts/assets/reports. 기본 overview. sales는 statistics, 나머지는 finance.read. overview/assets/reports에는 finance.cost도 필요하다. from/to(Y-m-d, 포함), period(day/week/month/custom), search≤200, product_id(자산), order_id(현재 주문), fund_id(자금 원장), location≤100, aging_bucket(normal/warning/risk), page. 기간 최대366일. 일자 기본은 업체 시간대 당월 첫날~오늘. HTML 목록20건/페이지, 합계는 전체 조건. 여러 목록의 page 이름은 payments_page/rules_page/closings_page/evidence_page/entries_page. 출고 기준 손익·기간 입출금·현재 상태를 분리해 표시한다. 관리손익은 불완전 자료가 있으면 미확정이며 등록분 참고소계와 구분한다.

## POST /seller/accounting/actions/{action}

JSON·Accept application/json·CSRF. 모든 action에 operation_key(영숫자/하이픈≤64) 필수. 원통화 금액은 문자열 소수≤2, 환율은 문자열 소수≤6. KRW는 정수 원/환율1. CNY 환율 양수≤10000, 거래 환산 지원 한도 KRW 1조. 실제 지급/수령/환불 확인일은 미래일 금지. 활성 계정 통화 일치와 잔액·관리 잠금은 서버에서 다시 확인한다.

| action / 권한 | 입력(공통 키 제외) |
|---|---|
| activate / finance.configure | reason |
| settings / finance.configure | aging_warning_days(1~3650), aging_risk_days(주의초과~7300), labor_ratio_bps(0~10000), reason |
| category / finance.expense | name, cost_group(fixed/variable), treatment(inventory/operating/fee/asset), active(boolean); 선택 id,parent_id |
| expense / finance.expense | category_id,occurred_on,currency(KRW/CNY),amount_original,exchange_rate,rate_date,rate_source,payment_status(paid/unpaid); paid면 fund_id/paid_on, 선택 counterparty/memo |
| payment / finance.payment | expense_id 또는 receipt_id, fund_id,paid_on,amount_original; 선택 memo |
| payment-recovery / finance.payment | payment_id,fund_id,recovered_on,amount_original,reason |
| expense-link-receipt / finance.expense | expense_id,receipt_id,reason |
| rate-confirm / finance.expense | expense_id,exchange_rate,rate_date,rate_source |
| expense-void / finance.expense | expense_id,occurred_on(역분개일),reason |
| fund / finance.accounts | name,currency,active; 선택 id(기존 통화 변경 금지) |
| fund-adjust / finance.accounts | fund_id,kind(opening/adjustment),direction(in/out),amount_original,exchange_rate,occurred_on,reason |
| bank / finance.accounts | bank,holder,active,default,customer_visible; 선택 id,fund_id,account_number(신규 필수·수정 공백은 기존 암호문 유지) |
| transfer / finance.transfer | from_fund_id,to_fund_id,amount_out(수수료 제외 원금),amount_in(예상 순수령액),exchange_rate(CNY 1당 KRW),fee_original,fee_currency,requested_on; 수수료 있으면 fee_fund_id, 선택 reference/memo |
| withdraw / finance.transfer | id,occurred_on,reason |
| receive / finance.transfer | id,occurred_on,reason; 선택 amount_original(실제 순수령액),exchange_rate(미입력은 요청값의 실제 확인). 수수료 지급 자금이 수령 자금이면 총수령·수수료를 분리해 순액만 잔액에 반영 |
| cancel / finance.transfer | id,occurred_on,reason |
| cancel-confirm / finance.transfer | id,occurred_on,amount_original(전체 실제 반환 원금과 일치),reason |
| cash-receipt / deposit | order_id,fund_id,amount(이번 KRW 정수),occurred_on,reason; 선택 bank_account_id(자금과 연결 일치) |
| cash-recheck / deposit | order_id,reason |
| cash-refund / refund | order_id,fund_id,amount(이번 KRW 정수),occurred_on,reason; 선택 bank_account_id |
| incentive-payment / finance.payment | line_id,beneficiary(channel:ID/host:ID),amount(KRW),fund_id,occurred_on,reason |
| incentive-recovery / finance.payment | 위와 같음 |
| recurring / finance.expense | category_id,currency,amount_original,day(1~31),starts_on,active; 선택 id,ends_on,memo |
| fifo-prepare / finance.cost | option_id |
| logistics / inventory | unit(UUID),logistics_stage(china_warehouse/international_transit/korea_customs/korea_warehouse/other/unspecified),reason |
| report / finance.read + finance.cost | from,to,period |
| close / finance.close | report_uuid,reason,acknowledge_incomplete(boolean) |
| reopen / finance.reopen | id(관리 잠금),reason |

응답은 ResponseHelper success/message/data. data에는 해당 ID(expense_id/payment_id/recovery_id/fund_id/transfer_id/count_id/closing_id), 저장/상태 결과와 return_url. report는 report_uuid와 저장 조회 URL. cash-receipt는 state(paid/review),received(서버 누적),order_id. cash-refund/incentive는 bank_transfer=false. 잘못된 권한403, 타업체/없는 자원404, 관리 잠금/잔액/상태/멱등 충돌409, 검증422, CSRF419.

```json
{"operation_key":"manual-part-002","order_id":31,"fund_id":4,"bank_account_id":2,"amount":40000,"occurred_on":"2026-10-21","reason":"이번 실제 입금 확인"}
```

```json
{"success":true,"message":"...","data":{"state":"review","received":40000,"order_id":31,"return_url":"..."}}
```

회계 적용 후 `/seller/tasks/deposit|resolve-deposit|refund|incentive|recovery`로 새 누적 덮어쓰기/자금 없는 지급 요청을 보내면409. 적용 전 완료 키의 재시도는 기존 결과 유지. 회계관리의 이번 금액 방식으로 전환해야 한다. 이전 주문 안내 계좌 스냅샷은 실제 입금 계좌 변경으로 재작성하지 않는다.

실제 계좌 확인 스냅샷/HMAC은 서버가 생성한다. 클라이언트가 보내는 임의 스냅샷을 저장하지 않는다. 금융 보고서 상세에는 마스킹한 확인 당시 계좌만 보존하며 계좌 번호 원문은 출력하지 않는다.

## GET /seller/accounting/reports/{uuid}

finance.read + finance.cost. part(기본1, 상세100건), orientation(portrait/landscape). HTML: 생성 당시 업체·기간·기준·생성자/시각·전체 합계·상세·무결성 확인, 모든 분할 링크. 마지막 분할도 전체 합계. 첫 분할에 전체 분석. 파일을 재생성하는 현재 주문/상품 조회가 아니다. 보고서 생성 새 요청 키는 새 UUID, 같은 키 재시도는 같은 UUID.

## POST /seller/accounting/evidence

multipart/form-data. source_kind(expense/transfer), source_id,operation_key,evidence(PDF/JPEG/PNG/WebP≤5MB). 해당 finance.expense 또는 finance.transfer. 응답 data.attachment_id. 암호화 보관, 공개 URL 없음. 같은 키·같은 내용은 같은 첨부, 내용 변경409.

## GET /seller/accounting/evidence/{id}

finance.read, 업체 격리. 첨부 다운로드 Content-Disposition:attachment, Cache-Control:no-store, nosniff. 복호화 후 digest 검사. 외부 URL/임의 경로를 입력받지 않는다.

## 입고 POST 확장

기존 `/seller/receipt` 계약을 유지하고 currency(KRW기본/CNY), exchange_rate, rate_date, rate_source를 추가한다. CNY는 환율 정보 필수, items.*.unit_cost는 소수2자리. 판매가격/할인은 KRW 정수. 기존 KRW 요청과 멱등 입력 해시의 기본 경로는 보존한다. 입고원가/원통화·물품별 분배 원가는 고객 JSON·QR·라벨에 반환하지 않는다.
