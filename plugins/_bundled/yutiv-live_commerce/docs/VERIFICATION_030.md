# 0.3.0 검증 보고 (2026-10-20, 한국 시간)

## 1. 구현 흐름과 남은 제한

상품 목록/초안 → 신규·복사·기존 상품 선택 → 색상×사이즈 조합/기본 옵션 → 옵션별 수량 → 원자적 입고 확정 → 서버 배치 번호로 조회 → 해당 물품 QR 선택/재출력을 연결했다.
기존 옵션의 가격·할인·활성 설정도 통합 화면에서 관리하며 상품 이미지는 입고 완료 후 기존 상품 수정/업로드를 사용한다.
독립 QR 사이드 메뉴를 제거하고 기존 QR 주소·권한은 유지했다. 매출정산과 인센티브·정산을 구분했다.

제한: 소급 입고, 확정 배치 수정/일괄 취소, 과거 마감 보고서 저장, 원가·세금·이익은 제공하지 않는다.
재고 보정은 기존 물품별 사유 있는 조정·정정과 감사 절차를 사용한다. 예약·출고 물품을 입고 취소로 되돌릴 수 없다.
현재 미입금과 주문일 기준 현재 누적 순매출을 과거 기말 잔액/마감 순매출로 표시하지 않는다.
보고서는 최대366일 및 업체 전체 데이터 안전 상한 안에서만 조회한다. 대형 업체의 분할/비동기 보고서는 별도 구현이 필요하다.

## 2. 원인과 변경 파일

- 기존 입고는 옵션 단위 멱등키만 있어 여러 옵션의 업무 배치·처리자·메모를 보존하지 못했다. `LiveReceiptRequest`, `LiveReceiptService`, 추가 migration과 입고 화면을 추가했다.
- 기존 QR 조회가 200개를 조용히 잘랐다. `LiveOfficeService`에서 초과 조회를 거절하고 배치 소유권/물품 혼입을 서버에서 확인한다. 기존 입고 키를 새 배치 키로 재사용하는 경로도 회귀 테스트 후 차단했다.
- 기존 통계는 선택 주문의 현재 누적 상태여서 기간 입금 흐름/과거 재고와 달랐다. `LiveLedgerService`에서 이동·이벤트 발생일 장부를 별도로 제공한다. 기존 통계 서비스는 보존했다.
- 본사 폼에 기존 업체 값을 수동으로 불러와야 했고 발급/재설정이 같은 동작이었다. `live_office.json`의 G7 data source initLocal 매핑·로딩/저장 가드·오류 표시와 `LiveAccountService::resetPassword()`를 적용했다.
- 공식 신규 설치의 초기 시드 migration이 오토로드 갱신보다 먼저 실행됐다. `plugin.php::install()`에서 기존 공식 런타임 오토로드 등록 API를 호출하도록 보완했다.
- 사이드바는 1440/1920/390px에서 DOM이 각각1개였다. 중복 DOM 생성 수정은 하지 않았다.

변경은 `plugins/_bundled/yutiv-live_commerce/` 안에만 있다. 전체 파일 목록은 [git status](verification/receipts-ledger/git-status.txt)를 참조한다.

## 3. DB와 기존 데이터

`2026_10_20_000005_create_live_receipts_table.php`: 입고 헤더 `live_receipts`와 물품의 업체/receipt_key 인덱스를 추가한다.
헤더는 업체·상품 FK, 담당자, 입고 확정/기록 시각, 위치·메모, 옵션별 수량 스냅샷과 전체 수량을 가진다. 개별 물품은 공통 receipt_key로 연결한다.
기존 상품·옵션 ID/QR·개별 물품 UUID·주문/이동 원장을 갱신하거나 추정 이관하지 않는다. 기존 입고는 헤더를 임의 생성하지 않는다.

별도 빈 DB에서 공식 0.3.0 설치 및 추가 migration 실행을 확인했다.
또 다른 DB의 원본0.2.0 스키마에 합성 상품·옵션·물품3개·이동 원장을 만든 뒤 공식 `plugin:update --source=bundled --force`로0.3.0을 적용했다.
업데이트 전후 원본 행/QR/원장 배열이 동일하고, 새 입고 헤더는 빈 상태이며, 기존 옵션 추가 입고2개 후 총5개가 되는 것을 검증했다.

## 4. 집계 기준

업체 시간대의 시작일00시 포함/종료일 다음날00시 제외. 주간은 월요일 시작이다.
재고는 물품의 최초 입고부터 이동 전이를 검증해 기초/기말 물리적 보유량을 복원하고 현재 상태를 별도로 표시한다.
보유 상태는 available/reserved/returned/as/defective, 비보유는 shipped/lost/disposed다. 예약·해제·내부 이동·내부 A/S는 보유 증감0이다.
기초 + 외부 유입 − 외부 유출 ± 조정 = 기말을 검증하며, 원장 누락/전이 단절/최신 상태·위치 불일치는 산출 불가로 표시한다.

주문은 생성일, 입금은 누적 수동 확인액의 이벤트 기록일별 차액, 취소·반품·환불은 각각 이벤트 기록일, 출고는 물품 이동일 기준이다.
입금 검토 완료를 새 입금으로 더하지 않으며 환불 기록은 실제 송금 완료가 아니다. 주문 단위 입금/환불은 상품별로 임의 배분하지 않는다.
순매출은 기간 생성 주문의 현재 paid 상태에서 누적 취소·반품을 차감한 상품금액이다. 채널과 쇼호스트는 동일 매출의 다른 분석이며 공동 참여 매출도 합산하지 않는다.

## 5. 테스트·빌드·브라우저·인쇄

| 검증 | 결과 |
| --- | --- |
| 플러그인 전체 PHPUnit | 186 tests / 31,367 assertions 통과 |
| 마지막 원장 최적화·권한·입고 집중 검증 | 15 tests / 3,016 assertions 통과 (전체 테스트의 부분집합) |
| `composer test-smoke` | Installation 2 tests / 29 assertions 통과 |
| 플러그인 독립 Vitest2.1.9 | 입력 흐름3 + G7 DynamicRenderer 레이아웃3 = 6 tests 통과 |
| 공식 `plugin:build` | Vite5.4.21 성공, JS/CSS/components.json 갱신 |
| `plugin:vendor-verify` | PHP 외부 의존 번들 없음으로 SKIP, 무결성 검증 성공 |
| 공식 설치·0.2.0 업데이트 | 별도 DB에서 신규 설치 및 기존 데이터/QR 보존 확인 |
| 브라우저 | 실제 Chrome·G7 렌더러·설치본 빌드 자산, 1440/1920/390px |
| 상품 흐름 | 조합4행·옵션별1/2/3/4개·총10개 입고 → 해당 배치만 조회 → 새로고침 유지 → 고유QR10개 |
| 본사 | 업체 자동 표시/신규 빈 폼,422오류,저장,신규 계정,프로필 유지 재설정,30초 후 비밀번호 숨김·타업체 지연 응답 차단·계정 없는 동작 잠금 |
| 일계·주계·월계 | 비영 매출 장부 조회 및 각 PDF 생성 확인 |
| 라벨 PDF | 10페이지, 약50.12×29.97mm. PDF를300dpi로 렌더링해 QR10개를 재해독, 저장 UUID와 전부 일치 |
| 장부 PDF | A4가로 약297.01×209.89mm·10페이지. 표 머리글 반복·모든 텍스트의 페이지 범위·행 분할을 확인 |

브라우저 합성 장부는 주문27,000원 / 입금27,000원 / 판매출고2개 / 취소9,000원 / 반품9,000원 / 환불기록18,000원 / 현재 순매출9,000원을 표시했다.
라벨과 장부는 PDF 스킬로 렌더링해 확인했다. 실물 프린터 출력 성공을 의미하지 않는다.
증거: [라벨 PDF](verification/receipts-ledger/labels.pdf), [장부 PDF](verification/receipts-ledger/ledger.pdf), [화면/결과 파일](verification/receipts-ledger/results.json).

실행 경로 정정: 최초 한 번 작업 폴더에서 잘못 시작한 PHPUnit은 로컬3306의 테스트 DB 인증 단계에서 거절되었고 assertions0이었다. 해당 실행은 성공 검증에서 제외했다.
설정 파일의 SQLite :memory:와 달리 PHPUnit의 MySQL 환경값이 우선했다. 이후 모든 성공 검증은 아래의 전용 사본/DB/캐시/저장소에서 수행했다.

## 6. 환경과 미검증

- Windows / PHP8.4.26 / MySQL8.4.11 / prefix it_. 전용 로컬 MySQL127.0.0.1:33319.
- PHPUnit: `C:/Temp/live-workflow-isolated`, 브라우저: `C:/Temp/live-workflow-browser`, 설치/업데이트: `C:/Temp/live-workflow-upgrade`.
- 독립 DB: `live_workflow_isolated_test`, `live_workflow_isolated_browser`, `live_workflow_isolated_upgrade`, `live_workflow_isolated_fresh`. 브라우저는 해당 격리 DB 권한만 가진 계정을 사용했다.
- 대상 PHP8.3.6 / MySQL8.0.46 실행 검증은 미완료다. 실물 라벨 프린터, 실제 은행 송금, 외부 메일/카메라/운영 규모 부하, Safari/Firefox는 이번 검증 대상에 포함하지 않았다.
- 운영 서버 접속·cron/systemd 조회/수정·commit·push·배포는 하지 않았다. 운영 회원·주문·재고를 사용하지 않았다.

## 7. 서버 갱신과 자동 실행

플러그인0.3.0의 소스·뷰·레이아웃·언어·manifest·dist·components.json·추가 migration을 함께 반영하고 공식 업데이트/route:cache를 실행한다.
기존 백업·업무 중지·전진 복구 기준과 중복 실행 방지 cron/systemd 확인·service/timer 설치/중지 예시는 [운영 지침](RECEIPTS_LEDGER.md)에 있다.
28개 번들 manifest 스캔에서 이 플러그인의 소비 확장이 없어 다른 확장 버전 제약 갱신은 필요하지 않다. 코어 공개 API는 변경하지 않았다.

## 8. Git 상태

시작/종료 branch=`main`, HEAD=`e3e54760dc7e4996c5670a5816e17683d6510c2e`. 시작 작업 트리는 깨끗했다.
최종 작업 트리는 이 플러그인 변경과 새 파일이 남아 있으며 스테이징/커밋/푸시하지 않았다.
[전체 status](verification/receipts-ledger/git-status.txt), [추적 파일 diff stat](verification/receipts-ledger/git-diff-stat.txt), [새 파일 포함 요약](verification/receipts-ledger/git-summary.json), [버전/빌드 해시](verification/receipts-ledger/release-results.json)를 제공한다.
