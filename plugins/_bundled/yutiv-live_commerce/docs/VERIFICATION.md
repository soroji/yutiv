# 독립 라이브 운영 구현 검증

2026-10-12, 로컬 합성 데이터만 사용. 운영 서버·DB·실제 메일·송금·유료 API는 사용하지 않았습니다.
시작 및 종료 HEAD: `88ca7956eb5bdb676f581270626e02003247ae0a` (main). commit/push/배포 없음.

## 구현 및 요구사항 대조

| 요구 범위 | 구현 근거 | 검증 |
|---|---|---|
| 업체·승인 도메인·직원·역할·명시적 기존 계정 전환 | LiveHeadquartersService, LiveDomainService, LiveAccountService, LiveHeadquartersRequest, 본사 live_office.json | 실제 본사 API 저장·재조회·임시 비밀번호 표시/숨김; 업체 경계·미귀속 보존 회귀 |
| 업체별 로그인·중지·강제 변경·세션 무효화 | ScopedLiveUserProvider, ResolveLiveTenant, ConfigureTeeWideSession, RequireTeeWideUser | 실제 로그인 진입점, session_version, 잠금/권한 회수, 교차 업체 회귀 |
| 고객·주소·통관·메일 재설정 | LiveCustomerService, LivePasswordResetService | 암호화·마스킹·역할·TTL·단일사용·Mail::fake; 실제 SMTP 미실행 |
| 상품·방송·채널·공동 진행·판매 링크 | LiveCatalogService, LiveCatalogRequest, seller 화면 | 판매 시간·재고 공유·할인·허용 링크; 상품 저장 후 새로고침 유지 |
| 장바구니·주문·계좌·예약·입금 검토 | LiveOrderService, LiveOrderAdjustmentService | UUID·귀속 보존·가격/계좌 스냅샷·중복/동시 주문·늦은 입금·예약 만료 |
| QR·부분 출고·반품·A/S·재고 정정 | LiveInventoryService, LiveReturnService | 실제 스캔 미리보기→부분 출고→배송 완료, A/S 입출고 200; 원본 주문·판매수량 불변 회귀 |
| 인센티브·후속 회수·귀속 정정·통계 | IncentiveCalculator, LiveIncentiveService, LiveStatisticsService | 정수 반올림·합계·중복 회수·지급 후 조정·시간대 경계; 실제 고객 구매확정 |
| 주기 실행·복구 | LiveMaintainCommand, Plugin::getSchedules | 동시 GET_LOCK 획득 차단·만료 재실행·멱등성; 외부 호출 없음 |
| 배포·기존 데이터 | migration 2026_10_13_000004, 루트 routes/web.php | 신규 공식 설치/활성화·기존 스키마 upgrade·prefix it_·route:cache |

UI/API 사용, 정책·계산 예시, 21개 테이블, 초기 계정 전환, 배포/복구/스케줄러는 [운영 설명](LIVE_OPERATIONS.md), 요청 계약은 [API 설명](api/README.md)에 있습니다.

## 중단 전 보고 복구 확인

- 접두사 migration: 기존 이메일 인덱스를 이름 하드코딩 대신 `dropUnique(['email'])`로 삭제하며 `it_` 환경에서 통과했습니다.
- MySQL JSON: LiveOperationsTest에서 연관 배열 스냅샷은 `assertEquals`로 비교하여 JSON 객체 키 순서에 의존하지 않습니다. 금액·UUID 등 개별 값의 엄격 검증은 유지합니다.
- 업체별 로그인과 session_version 무효화, 임시 비밀번호 해시 저장/일회 표시, 본사 API 연결은 파일·완료 로그 및 실제 화면으로 확인했습니다.
- 동일 DB를 쓰는 PHPUnit과 브라우저 단계는 순차 실행했습니다. 완료 시 이번 작업의 로컬 HTTP 프로세스를 종료했으며 PHP 테스트 프로세스는 남아 있지 않습니다. 격리 MySQL과 로그는 보존하고 다른 프로세스는 종료하지 않았습니다.

## 실행 결과

- PHP **8.4.26 / MySQL 8.4.11**, 독립 checkout·전용 `yutiv_isolated_0e497c78`, prefix `it_`.
- [전체 회귀 로그](verification/live-final-regression.log): **180 tests / 29,246 assertions 통과**. 실제 분리 PHP 프로세스의 마지막 재고 경쟁·중복 입금/출고 포함.
- 전체 회귀 후 A/S 경로 보완: [추가 관련 회귀](verification/live-as-regression.log) **3 tests / 586 assertions 통과**. 전체 180개와 중복된 검증도 있어 183개의 고유 테스트라고 표현하지 않습니다.
- PHP 변경 파일 구문검사 및 Pint 통과. `git diff --check` 통과.
- [공식 production 빌드](verification/live-official-build.log) 성공, dist 및 components.json 반영.
- [공식 vendor-verify](verification/live-vendor-verify-final.log)는 **PHP vendor bundle 없음으로 SKIP**. 무결성을 검사한 PHP 번들이 있다는 뜻이 아닙니다. Composer PHP 의존성 추가 없음.
- 공식 plugin install/activate로 본사 레이아웃 1개 및 메뉴 등록. 수동 fixture 라우트나 provider로 브라우저 화면을 대체하지 않았습니다.
- 본사 메뉴 읽기와 관리 API 권한은 별개. 합성 본사 계정에 관리 권한을 명시적으로 부여하여 검증했습니다.
- [본사 저장](verification/live-hq-final.log): 업체 저장 200, 계정 발급 200, 일회 비밀번호 표시/숨김, 새로고침 보존.
- [실제 업무 흐름](verification/flow-results.json): 입금 200 → UUID 스캔 미리보기(쓰기 없음) → 부분 출고 200×2 → 배송 완료 200 → 고객 구매확정 200.
- [오류/업로드/A/S](verification/states-results.json): A/S 입출고 200, 잘못된 브랜드 422, CSRF 없음 419, 오프라인 안내, 로고 multipart 업로드 200.
- 본사 로고는 실제 File을 G7 인증 API 클라이언트로 직접 전달합니다. 레이아웃 액션의 파일 직렬화로 발생한 422를 해결했으며 서버 파일 검증은 유지했습니다.
- [최종 화면/네트워크](verification/final-network-results.json): 본사·판매자 1440/1920/390px 모두 200, 문서 가로 넘침 없음, 예기치 않은 JS/로컬 Network 오류 없음. 외부 CDN 요청은 로컬 204로 차단했으므로 CDN 실제 로딩 검증은 아닙니다.
- 카메라 미지원 안내 확인. 권한 거부는 getUserMedia 합성 거부 테스트이며 실제 스마트폰 검증과 구분합니다.
- [라벨 검증](verification/live-label-verify.log): 203dpi QR 디코딩, 감사 요청 200, 인쇄 대화상자 호출 1회. [50×30mm PDF](verification/labels-50x30.pdf)는 Chromium 출력 페이지 50.12×29.97mm(포인트 반올림), 물리 프린터 검증 없음.

## 실제 캡처

모든 숫자·이름·상품·주소는 합성 데이터입니다. 녹색 정사각형 로고는 업로드 검증용 합성 이미지입니다.

| 화면 | 1440px | 1920px | 390px |
|---|---|---|---|
| 판매자 운영 | [PC](verification/seller-final-1440.png) | [넓은 PC](verification/seller-final-1920.png) | [모바일](verification/seller-final-390.png) |
| 본사 관리 | [PC](verification/headquarters-final-1440.png) | [넓은 PC](verification/headquarters-final-1920.png) | [모바일](verification/headquarters-final-390.png) |
| 보존된 Golfif 메인 | [PC](verification/main-1440.png) | [넓은 PC](verification/main-1920.png) | [모바일](verification/main-390.png) |

[고객 주문](verification/customer-order-final-390.png) · [A/S](verification/repair-final-390.png) · [로고 저장](verification/headquarters-logo-mobile.png) · [다른 업체 빈 상태](verification/empty-shop-390.png) · [입력 오류](verification/validation-error-390.png) · [오프라인](verification/offline-390.png) · [카메라 안내](verification/camera-fallback-390.png) · [라벨](verification/labels-final-1440.png).

Golfif 배경 원본은 변경하지 않았습니다. SHA256 `7a8c7751ce6dc97305e387a34afc73b554c7783fbced3dce1ba74959624bc5c7`.

## 미검증·배포 전 조건

- **PHP 8.3.6 / MySQL 8.0.46 실행 미검증**: 해당 버전의 사용 가능한 로컬 환경이 없으며 새 가상화/테스트 도구를 설치하지 않았습니다. 위 8.4 결과로 대체 통과라고 주장하지 않습니다.
- 실제 프린터·스마트폰·SMTP·DNS/TLS·YouTube 송출·은행 송금은 미검증. 환불/지급 화면은 기록이며 송금 기능이 아닙니다.
- 외부 CDN 글꼴·아이콘은 차단하여 실서비스 로딩 미검증. 기본 글꼴과 실제 로컬 컴포넌트/자산으로 캡처했습니다.
- 번역 파일은 ko 중심이며 en 상세 업무 오류 일부는 한국어 fallback입니다. 전체 다국어 운영 UI 완료로 주장하지 않습니다.
- 기존 NULL 업체 계정의 소속과 도메인 소유권은 본사 확인 후 명시적으로 설정해야 합니다. 운영 데이터 자동 귀속 없음.
- 배포 전 백업/APP_KEY 보존, 권한·정확한 호스트·메일 승인 설정·예약/반품기간·scheduler 실행 계정을 확인하세요. 실제 서버에는 적용하지 않았습니다.
- 남은 실행 환경·장비 확인은 위와 같으며 구현 결함이 없는 운영 환경까지 인증했다는 뜻이 아닙니다.

## Git와 작업 보존

변경 확장 `yutiv-live_commerce` **0.2.0**, 신규 migration 1개, 루트 웹 라우트 연결, 시나리오 문서. YUTIV 쇼핑몰·개인 스토어·리워드 변경 없음.
[전체 변경 파일 및 Git 상태](CHANGED_FILES.md)에 추적 변경과 미추적 신규 파일을 구분했습니다. `git diff --stat`에는 미추적 파일이 포함되지 않습니다.
Git 결과는 무시된 파일까지 포함한 전체 파일시스템 변경 감사가 아닙니다. 격리 DB·로그·작업공간은 보존했습니다.
