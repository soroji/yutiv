# 0.4.0 보완·격리 검증 보고

2026-10-20. 기존 0.3.0 구현을 보존하고 장부 조회·집계·보존·출력을 보완했다. 변경은 `plugins/_bundled/yutiv-live_commerce/` 안에만 있다. commit/push/운영 서버 접속/배포 및 운영 DB·자격정보 사용은 하지 않았다.

## 보완 결과와 집계 기준

- 누적 물품/주문 수로 하루 조회까지 차단하던 전역 상한을 DB 조건 조회/집계로 교체했다. 화면은 20개 옵션씩, 전체 합계는 모든 페이지 대상이다. 기초 재고는 전체 이력의 최초 입고·전이·최종 상태/위치를 DB에서 검증한다. 대소문자 무시 collation에 의한 검증 완화도 실패 재현 후 바이트 비교로 보완했다.
- 업체 행 잠금을 제거하고 REPEATABLE READ 비잠금 일관 읽기를 적용했다. 저장 중 업체 FK 공유 잠금도 피한다. 보고서 생성 중 별도 프로세스의 실제 입고가 651.61ms에 완료됐고, 저장 집계/상세는 기말 4,200개, 다음 조회는 4,201개로 일치했다. [동시성 결과](verification/ledger040/concurrency.json).
- 주문 발생액은 생성일, 입금 변동은 수동 누적 확인액의 기록일별 차액, 취소·반품·환불은 각각의 기록일이다. 검토 완료는 추가 입금이 아니다. 입금·취소·반품·환불을 합쳐 중복 차감하지 않는다. 기간 순매출은 제공하지 않는다.
- 기존 현재 paid 주문의 잔여 금액은 ‘조회 기간 생성·입금 완료 주문의 현재 잔여 상품금액’으로 표시한다. 현재 미입금/현재 결제·배송 상태도 과거 기말과 구분한다. 혼합 주문의 입금·환불·미입금은 선택 상품 포함 주문 전체이며 상품에 임의 배분하지 않는다. 정확한 계산식은 [집계 기준](LEDGER_040.md)을 따른다.
- 보고서마다 업체/기간/시간대/기준 버전/생성 시각/생성자와 전체 집계·당시 상세를 보존한다. 원본 이름/주문/반품 변경 후에도 저장 결과는 같고 재생성은 별도 UUID 버전이다. 저장 목록·재조회·재출력은 기존 재무 권한과 업체 격리를 유지한다. 정식 회계 마감·세무 장부가 아니며 원가·세금·이익 계산은 추가하지 않았다.

## 성능과 실제 데이터 규모

모든 기존 누적 상한을 초과하는 합성 데이터: 옵션 520개, 물품 4,200개, 이동 25,200건, 주문 2,601건, 주문 항목 10,404건, 이벤트 20,808건. 해당 데이터로 하루/주간/월간 조회와 26개 화면 페이지 합산을 검증했다. 동시 입고 후 독립 벤치마크의 실제 조회 원본은 물품 4,201개/이동 25,201건이며 나머지 규모는 동일하다.

| 독립 서비스 호출 | 기간 | 실행 시간 | 쿼리 수 | PHP 최대 할당량 |
| --- | --- | ---: | ---: | ---: |
| 하루 조회 | 10/1 | 512.55ms | 20 | 40MiB |
| 주간 조회 | 10/1~10/7 | 470.08ms | 20 | 40MiB |
| 월간 조회 | 10/1~10/31 | 468.63ms | 20 | 40MiB |
| 월간 전체 저장 | 상세 533행/6분할 | 1,020.32ms | 59 | 44MiB |
| 대형 전체 저장 | 1/1~1/31, 56,920행/570분할 | 16,045.13ms | 1,578 | 52MiB |

월간 저장의 PHP 추가 최대 할당은 4MiB, 대형 저장은 14MiB였다. 조회의 추가 할당은 allocator 측정 단위에서 0이며 메모리 사용이 없다는 뜻이 아니다. 메모리는 PHP 프로세스 기준이며 MySQL 서버 메모리는 별도 측정하지 않았다. 서비스 호출 측정으로 웹 인증/메뉴 등의 부가 쿼리는 제외한다. 버퍼가 있는 로컬 반복 검증 결과이며 운영 응답시간 보장은 아니다.

대형 저장의 실제 상세는 옵션 520 + 주문 항목 10,400 + 이벤트 20,800 + 이동 25,200 = 56,920행이다. DB 종류별 COUNT와 순번 1~56,920, 처음/마지막 분할을 확인했다. 570개 분할 전부를 PDF로 생성하지는 않았다. 별도의 532행 보고서는 6분할 전체 PDF에서 순번과 합계를 대사했다.

근거: [독립 측정](verification/ledger040/benchmark.json), [PHPUnit 측정](verification/ledger040/performance.json), [대형 저장](verification/ledger040/large-report.json), [DB 상세 행 수](verification/ledger040/large-stored-counts.txt). 재현 스크립트는 `tests/Support/LedgerSynthetic.php`, `tests/Playwright/ledger-benchmark.php`, `ledger-large.php`, `ledger-lock.php`이며 격리 사본/DB/포트 검사를 통과해야 실행된다.

## 회귀·브라우저·출력 검증

| 검증 | 결과 |
| --- | --- |
| 최종 플러그인 전체 PHPUnit | **194 tests / 35,037 assertions 통과**, 2분23.493초, 전체 테스트 프로세스 최대134MiB |
| 공식 `composer test-smoke` | 2 tests / 29 assertions 통과 |
| 독립 Vitest | 6 tests 통과 (입고 입력3/G7 본사 레이아웃3) |
| 날짜·금액 | 월 경계를 넘는 주문/입금/부분취소/반품/환불, 기간 밖 직전 입금, 음수 입금 정정, 검토 완료 제외, 혼합 상품 주문을 검증 |
| 재고 | 기초+부호 있는 증감=기말, 예약/해제/내부·외부 A/S, 이력 누락/전이 단절/최종 상태·위치 불일치 및 대소문자 불일치 검증 |
| 저장·권한 | 원본 상품명/반품 상태 변경 후 불변, 별도 재생성, 손상 해시409, owner/finance 허용 및 기타 역할403, 타 업체 UUID404 |
| 실제 Chrome | 최종 설치본·route cache 사용. 하루/주간/월간, 20개 목록, 다음 페이지 전체 합계 유지, 페이지2에서 전체 저장, 6개 분할 재조회·재출력 |
| 기존 상품/입고/QR | 색상×사이즈4개 조합, 수량1/2/3/4 총10개 입고, 배치 조회·새로고침, 고유QR10개 및 재출력. PDF 렌더링 후 QR10개 재해독·저장 UUID 일치 |
| 저장 PDF | 532행의 전체6분할, A4 가로 총75페이지. 순번1~532 전부 포함, 텍스트 페이지 경계 정상, 분할1 재출력 텍스트 동일. 1440/390px 화면 가로 넘침 없음 |
| 저장 이후 재고 변경 | 이전 보고서 기말4,200 유지, 다시 저장한 별도 버전은4,201. 실제 브라우저에서 재조회 확인 |
| 공식 production 빌드 | Vite5.4.21 성공. manifest5종/배포 JS·CSS·components.json 0.4.0 동기화, sourceMappingURL 참조 없음 |
| vendor/의존성 | 외부 PHP 번들 없음으로 SKIP/무결성 성공. 번들 manifest28개 스캔, 소비 확장0개, g7_version >=7.0.9 유지 |

[최종 전체 테스트](verification/ledger040/phpunit-full.txt), [브라우저](verification/ledger040/browser.json), [불변 재출력](verification/ledger040/reprint-final.json), [PDF 검증](verification/ledger040/pdf-final.json), [QR](verification/ledger040/qr-results.json), [빌드/버전 해시](verification/ledger040/release.json). PDF는 `verification/ledger040/saved-part-1.pdf`~`saved-part-6.pdf`, 라벨은 `labels.pdf`다. 실제 프린터 완료를 검증한 것은 아니다. Vitest의 기존 React `autoBinding` DOM 경고는 남지만 테스트 실패는 없다.

## Migration 및 배포 대상

새 migration `2026_10_20_000006_create_live_reports_tables.php`: `live_reports`, `live_report_rows`와 기간/원장 조회 복합 인덱스를 추가한다. 기존 데이터 업데이트/삭제는 없다. 저장 보고서가 있으면 down()은 거절한다. 0.2.0→0.4.0에는 이전 000005 입고 헤더 migration도 필요하고, 0.3.0→0.4.0에는 000006만 추가된다.

별도 DB에서 0.2.0 상품·옵션·물품3개·QR·이동 원장을 만든 후 공식 `plugin:update --source=bundled --force`로0.4.0을 적용했다. 원본 행은 동일하고 추가 입고2개 후5개, 저장 보고서 기말5개 및 재출력 일치, 데이터 있는 rollback 거절을 확인했다. 원본0.2.0 신규 설치를 재현할 때 기존 초기 시드 오토로드 문제가 다시 나타나 이미0.3.0에 포함된 install 부트스트랩 보완만 먼저 적용했다. 업무 코드/기준 스키마는0.2.0으로 유지한 뒤 업데이트를 검증했다. [공식 업데이트 로그](verification/ledger040/official-update-020-040.txt), [최종 데이터 보존/rollback 검사](verification/ledger040/upgrade-final-check.txt).

배포 대상: 이 플러그인의 PHP·Blade·언어·레이아웃·migration·plugin/composer/package manifest·lock·components.json·dist 및 문서 전체. 기존 입고/QR 변경도 포함한 누적0.4.0 패키지이며 코어/다른 확장 변경은 없다. 담당자용 백업/공식 업데이트/route cache/전진 복구 절차는 [운영 지침](RECEIPTS_LEDGER.md)과 [0.4.0 기준](LEDGER_040.md)에 있다. 실제 배포는 수행하지 않았다.

## 환경 차이와 Git

- 검증: Windows, PHP **8.4.26**, MySQL **8.4.11**, prefix `it_`, Node20.20.0, 로컬127.0.0.1:33319. 전용 사본 `C:/Temp/live-workflow-isolated`, `live-workflow-browser`, `live-workflow-upgrade040`; DB `live_workflow_isolated_test`, `live_workflow_isolated_browser`, `live_workflow_isolated_upgrade040`.
- 운영 대상 **PHP8.3.6 / MySQL8.0.46에서 직접 실행한 검증은 미완료**다. 운영 DB/자격정보, 외부 은행·메일, 실물 프린터 및 운영 서버를 사용하지 않았다.
- branch `main`, HEAD `e3e54760dc7e4996c5670a5816e17683d6510c2e` 유지. 기존 미커밋0.3.0 변경을 보존했으며 staging/commit/push하지 않았다.
