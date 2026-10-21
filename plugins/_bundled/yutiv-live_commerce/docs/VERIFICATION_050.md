# 0.5.0 구현·격리 검증 보고

기준 `main` / `783906c3fd531c350102baba5c701198c7bdad73`, 시작 작업 트리 clean. 변경은 `plugins/_bundled/yutiv-live_commerce/`에 한정했다. commit/push/운영 서버·DB 접속/배포는 하지 않았다. 구현·단가/출력 이력 구조·집계·권한·적용/복구는 [업무 기준](PRODUCT_WORKSPACE_050.md), 요청 계약은 [상품 작업 API](api/products.md)를 참조한다.

## 결과

상품관리 내부 탭을 제거하고 실제 전체 상품 목록을 첫 화면으로 제공한다. 검색·분류·등록/입고일·판매 가능 재고·상태 필터와 서버 페이지네이션, 상세·선택 상품 추가 입고·동적 옵션·공통/개별 가격·입고 완료 목록 복귀를 구현했다. 34px 입력과 기존 업체 전용 CSS 범위에서 PC/모바일을 조정했다.

배치/옵션별 불변 입고단가·수량·매입금액을 원래 물품 UUID에 연결한다. 새 장부는 입고일 기준 매입금액과 미등록 수량을 구분한다. 단가는 회계상 매출원가/이익이 아니다. QR은 실제 물품을 페이지별로 선택하고 미리보기 후 요청을 저장한 다음 인쇄하며, 이전 감사/새 요청 이력으로 재출력한다. 기존 직원 역할과 고객 비노출 경계를 유지했다.

## 환경과 데이터 보존

새 격리 사본 `C:/Temp/live-products050-test`, `C:/Temp/live-products050-browser`; 합성 DB `live_products050_isolated_test`, `live_products050_isolated_browser`만 사용했다. MySQL은 별도 합성 datadir와 localhost:33319이며 운영 자격정보가 없다.

실제 **PHP 8.4.26 / MySQL 8.4.11 / Windows / Chrome**이다. 운영 대상 **PHP 8.3.6 / MySQL 8.0.46에서 실행 검증하지 않았다**. 실물 프린터도 미검증이며 PDF 및 QR 해독까지 확인했다. 성능은 로컬 측정으로 운영 처리량 보장이 아니다.

0.4.0 설치본과 합성 DB를 복제한 뒤 공식 `plugin:update --source=bundled --force`로0.5.0을 적용했다. 상품48·옵션588·물품4,313·이동25,320·저장 보고서11·저장 상세118,822행의 추가 컬럼 제외 원본 값/순서 SHA-256이 모두 동일했다. 기존 단가 연결은 모두 null이다. [공식 업데이트](verification/products050/official-update-040-050.txt), [원본 해시 대사](verification/products050/upgrade-preservation.json).

새 입고/출력 기록이 있는 DB에서 migration down은 실제로 거절됐고 물품·단가·출력 행 수가 그대로였다. 브라우저의 두 배치 매입 합계49,000원, 원래 물품6개 비용 연결, 기존 판매가격15,000원 유지도 DB에서 대사했다. [복구 가드/입고 대사](verification/products050/recovery.json). 새 migration은000007이며 기존000005/000006을 대체하지 않는다.

## 검증

| 구분 | 실제 결과 |
|---|---|
| 수정 전 재현 | 기존0.4.0에서 새 상품 목록 표시 검증 실패 확인 ([로그](verification/products050/before-regression.txt)) |
| 전체 플러그인 Feature | **204 tests / 44,591 assertions PASS**, 146.861초,136MiB ([로그](verification/products050/phpunit-full.txt)) |
| 추가 분류/과거 출력·총장수 | **2 tests / 515 assertions PASS** ([로그](verification/products050/phpunit-supplement.txt)) |
| 설치 스모크 | **2 tests / 29 assertions PASS** ([로그](verification/products050/smoke.txt)) |
| JS/레이아웃 | **10 tests PASS**. 기존 레이아웃 mock의 autoBinding 경고는 남음 ([로그](verification/products050/vitest.txt)) |
| 브라우저 | 설치된 자산으로1440/1920/390폭, 가로 화면 넘침 없음, 실제 목록/3종류4조합/가격 모드/서로 다른 단가/기존 판매가/추가 입고/검색 조건 복원/필터에 가려진 완료 알림 PASS |
| QR | 실제 배치4개×2장=8장, 미리보기→강제 기록 실패 시 인쇄 호출0회→정상 인쇄/명시적 재출력 PASS, JS 오류0 ([결과](verification/products050/results.json)) |
| 보고서 브라우저 | route:cache 상태에서 기간 매입49,000원 조회·저장·재조회 및 실제 상품명 변경 후 저장 내용 동일 PASS ([결과](verification/products050/saved-report-browser.json)) |
| 라벨 PDF | **8페이지, 약50.123×29.972mm**, 모든 글자 용지 안, QR8개 모두 원래UUID와 일치 ([크기](verification/products050/pdf-results.json), [해독](verification/products050/qr-results.json)) |
| 빌드/등록 | 공식 production 빌드 PASS. manifest5종0.5.0, dist/components 갱신, sourceMappingURL 없음 ([빌드](verification/products050/official-build.txt), [버전/해시](verification/products050/release.json)) |
| 캐시/번들 | route:cache PASS, vendor-verify는 별도vendor번들 없음으로 skip/성공 ([캐시](verification/products050/route-cache.txt), [번들](verification/products050/vendor-verify.txt)) |

서버 검증은 재고 없는 상품 기본 노출·20/5개 페이지·업체 날짜 경계·가용 재고/망실 존재 조건 분리, 옵션 한도/중복/속성, 누락/0/음수/소수/상한 단가, 같은 키 멱등/변경 충돌/실패 롤백, 배치별 원가·원래UUID, 창고/판매/재무 권한, 타업체 상품·분류·배치·인쇄 이력, 총200장 초과 거절, 과거 감사 UUID 재출력을 포함한다. 기존 주문·입금·부분취소·반품·환불·A/S·예약·재고 대사와 대량 장부/저장 불변성 회귀를 포함한다. 테스트 작성만으로 통과라고 보고하지 않았다.

## 목록 성능

동일 업체 **상품50 / 옵션596 / 물품4,323 / 입고49 / 원가행9 / 이동25,330 / 출력요청4**에서 서비스 조회를 측정했다. 페이지 SQL+전체 COUNT의 **2쿼리**이며 HTML 인증/메뉴/카테고리 조회는 별도다. 프로세스 최대 메모리는 부트스트랩 포함40MiB였다.

| 조건 | 페이지 행/전체 | 시간 | 쿼리 |
|---|---:|---:|---:|
| 기본20개 | 20/50 | 12.425ms | 2 |
| 100개 | 50/50 | 12.256ms | 2 |
| 가용 재고 있음 | 20/50 | 16.453ms | 2 |
| 월간 입고일 | 20/50 | 13.894ms | 2 |

[실제 측정 JSON](verification/products050/performance.json). 이전 대량 장부 회귀는 기존2,000개 상한 초과 합성 시나리오를 그대로 실행했다. 옵션/재고 집계가 상품 행 수에 따른 추가 DB 왕복을 만들지 않음을20/100개 비교로 확인했다. 이 표는 대형 운영 DB의 실행계획이나 동시 부하 시험을 대신하지 않는다.

## 화면과 배포

- 목록: [1440 PC](verification/products050/products-1440.png), [1920 PC](verification/products050/products-1920.png), [390 모바일](verification/products050/products-390.png)
- 입고: [PC](verification/products050/intake-1440.png), [모바일](verification/products050/intake-390.png), [완료 알림](verification/products050/receipt-completed.png)
- 출력: [미리보기](verification/products050/qr-preview.png), [이력](verification/products050/qr-history.png), [PDF 렌더](verification/products050/label-first.png)
- [저장 매입 보고서](verification/products050/saved-purchases-report.png)

배포 대상/백업·공식 업데이트·전진 복구 절차는 [업무 기준](PRODUCT_WORKSPACE_050.md)의 마지막 절을 따른다. manifest 의존22개를 스캔했고 소비 확장이 없어 타 확장 제약을 변경하지 않았다. 별도 분실 상태는 만들지 않았으며 기존 lost=망실 의미를 유지했다. 상태 분리를 원하면 업무 전이 정책 결정이 필요하다.
