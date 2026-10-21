# 0.8.0 최종 변경 범위

최신 표시 보완: [캡처 검토 반영·영향 검증](ADMIN_UI_080_POLISH.md). 이번 지시 시작 시 미커밋 backend/database 128개 파일은 모두 동일하며 Blade/CSS/표시 JS·빌드/검증/문서만 보완했다.


추가 최종 보완: [4개 KPI·기간 장부·최신 검증](ADMIN_UI_080_FINAL.md). LiveFinanceDashboardService의 화면용 읽기 집계와 같은 snapshot 연결, Report Service의 역분개 SQL 부호 형식만 추가 보완했다. 기존 금융 계산 규칙과 저장 스냅샷은 유지한다. 앞선 미커밋 변경을 보존했다.

기준/종료 HEAD `d222ad35d0ef23e6f010dc0c34fb79a9207e390b`, main. 시작 작업 트리 깨끗함. 기존 구현을 보존하고 변경을 `plugins/_bundled/yutiv-live_commerce/` 안으로 한정. commit·push·운영 접속·배포 없음.

공통 operations 레이아웃과 seller/navigation, 기존 Office 메뉴 원본을 사용하는 LiveSellerNavigation, 재무 표시용 LiveDisplay, 방송/회계 Blade, admin CSS와 finance/index JS, 메뉴/역할 전달 Controller, 미설정 보고서 조회 조건, 0.8.0 manifest 5종과 공식 dist, 회귀 테스트·브라우저 스크립트·검증 자료를 포함한다. 금융 Contract/Enum과 migration 변경 없음. Report Service의 SQL 부호 연산 보완 외 금융 계산 규칙 변경 없음. 원장·계산·기존 데이터 변환 없음. 코어·다른 확장·공개 Golfif·YUTIV 쇼핑몰·개인 스토어 변경 없음.

[원인·구현·검증·배포 자료](ADMIN_UI_080.md) · [릴리스 검증](verification/admin080/release.json)

---

아래는 이전 작업 당시의 기록이다.

# 0.7.0 최종 변경 범위

시작 HEAD `09389ffbea5691593dd536ecff7c46ec1352fb63`, main. 시작 작업 트리 깨끗함. 변경은 `plugins/_bundled/yutiv-live_commerce/` 내부, commit/push 없음.

방송 Workspace/Controller/Request와 기존 Catalog/Office 연결, 방송 목록·상세·참여자 선택·상품 관리 Blade/JS/CSS, 메뉴/기존 URL 연계, 고객 품절 표시·정렬, 판매자 PC 화면 밀도, 5종 manifest·dist·API/적용/검증 문서·회귀 테스트·스크린샷을 포함한다. 신규 migration 없음. 주문·재고·회계·인센티브 원장 데이터 변환 없음. 코어·타 확장·공개 Golfif 메인 변경 없음.

[집계/흐름과 배포 대상](BROADCAST_070.md) · [검증과 전후 화면](VERIFICATION_070.md)

---

아래는 이전 작업 당시의 기록이다.

# 0.6.0 최종 변경 범위

시작/종료 HEAD `783906c3fd531c350102baba5c701198c7bdad73`, branch main. 기존 미커밋 0.5 작업을 보존했다. 변경은 `plugins/_bundled/yutiv-live_commerce/` 내부에 한정되며 commit/push 없음.

배포 대상: 플러그인 전체. 재고 실사·관리 회계 Service/Contract/Enum/Controller/Request, migration 000008~000011, 기존 입고·출고·취소·반품·인센티브 연계, Blade 화면·기존 JS/CSS, 0.6.0 manifest 및 dist, 테스트·API/집계/복구 문서·검증 증거 포함. 코어/다른 확장 변경 없음. 다른 번들 소비 확장 최소 버전 변경 대상 없음.

[검증 결과](VERIFICATION_060.md) · [집계/적용 기준](ACCOUNTING_060.md) · [릴리스 해시](verification/accounting060/release.json)

---

아래는 이전 작업의 변경 기록으로 당시 HEAD/건수이다.

# 최종 변경 파일

시작/종료 HEAD: `88ca7956eb5bdb676f581270626e02003247ae0a`, branch `main`. commit/push 없음.

추적 변경 33개, 미추적 신규 106개. 신규 목록에는 구현·빌드 자산·검증 캡처/로그·이 문서가 포함됩니다.
Git에 무시되는 로컬 파일 전체의 무변경을 증명하지 않습니다.

## git diff --stat (미추적 제외)

```text
plugins/_bundled/yutiv-live_commerce/CHANGELOG.md  | 14 ++++++
 plugins/_bundled/yutiv-live_commerce/composer.json | 46 +++++++++---------
 .../yutiv-live_commerce/config/live-commerce.php   |  1 +
 plugins/_bundled/yutiv-live_commerce/plugin.json   | 26 +++++++---
 plugins/_bundled/yutiv-live_commerce/plugin.php    | 37 ++++++---------
 .../resources/views/account/index.blade.php        |  2 +-
 .../resources/views/auth/login.blade.php           | 11 +++--
 .../resources/views/auth/register.blade.php        |  4 +-
 .../views/partials/portal-golf-styles.blade.php    |  8 +++-
 .../views/partials/portal-header.blade.php         | 24 +++++-----
 .../resources/views/portal/index.blade.php         |  5 +-
 .../src/Http/Controllers/AccountController.php     | 17 +------
 .../src/Http/Controllers/Auth/LoginController.php  | 15 +++---
 .../Http/Controllers/Auth/RegisterController.php   | 24 ++++------
 .../src/Http/Controllers/LiveController.php        | 14 ++++++
 .../src/Http/Controllers/PortalController.php      |  4 ++
 .../Http/Middleware/ConfigureTeeWideSession.php    |  9 ++--
 .../src/Http/Middleware/RequireTeeWideUser.php     |  6 ++-
 .../src/Http/Requests/UpdateBrandRequest.php       |  6 +++
 .../yutiv-live_commerce/src/Models/LiveTenant.php  | 10 ++++
 .../yutiv-live_commerce/src/Models/TeeWideUser.php | 17 ++++++-
 .../src/Providers/LiveCommerceServiceProvider.php  | 34 ++++++++++++-
 .../src/Support/SellerBrand.php                    |  8 ++--
 .../src/Support/TeeWideAuth.php                    |  2 +-
 .../src/Support/TeeWideConfig.php                  | 17 ++++++-
 .../src/Support/TeeWidePresenter.php               | 19 ++++++++
 .../tests/Feature/SellerBrandTest.php              | 30 ++++++------
 .../tests/Feature/TeeWideSafetyTest.php            | 31 ++++++++----
 .../Feature/TeeWideSessionDriverLifecycleTest.php  | 26 +++++-----
 .../tests/Feature/TeeWideSessionIsolationTest.php  | 35 +++++++-------
 .../tests/Feature/TeeWideTenantDatabaseTest.php    | 36 ++++++++------
 .../yutiv-live_commerce/tests/PluginTestCase.php   | 55 +++++++++++++++-------
 routes/web.php                                     |  7 +++
 33 files changed, 389 insertions(+), 211 deletions(-)
```

## git status --short --untracked-files=all

```text
M plugins/_bundled/yutiv-live_commerce/CHANGELOG.md
 M plugins/_bundled/yutiv-live_commerce/composer.json
 M plugins/_bundled/yutiv-live_commerce/config/live-commerce.php
 M plugins/_bundled/yutiv-live_commerce/plugin.json
 M plugins/_bundled/yutiv-live_commerce/plugin.php
 M plugins/_bundled/yutiv-live_commerce/resources/views/account/index.blade.php
 M plugins/_bundled/yutiv-live_commerce/resources/views/auth/login.blade.php
 M plugins/_bundled/yutiv-live_commerce/resources/views/auth/register.blade.php
 M plugins/_bundled/yutiv-live_commerce/resources/views/partials/portal-golf-styles.blade.php
 M plugins/_bundled/yutiv-live_commerce/resources/views/partials/portal-header.blade.php
 M plugins/_bundled/yutiv-live_commerce/resources/views/portal/index.blade.php
 M plugins/_bundled/yutiv-live_commerce/src/Http/Controllers/AccountController.php
 M plugins/_bundled/yutiv-live_commerce/src/Http/Controllers/Auth/LoginController.php
 M plugins/_bundled/yutiv-live_commerce/src/Http/Controllers/Auth/RegisterController.php
 M plugins/_bundled/yutiv-live_commerce/src/Http/Controllers/LiveController.php
 M plugins/_bundled/yutiv-live_commerce/src/Http/Controllers/PortalController.php
 M plugins/_bundled/yutiv-live_commerce/src/Http/Middleware/ConfigureTeeWideSession.php
 M plugins/_bundled/yutiv-live_commerce/src/Http/Middleware/RequireTeeWideUser.php
 M plugins/_bundled/yutiv-live_commerce/src/Http/Requests/UpdateBrandRequest.php
 M plugins/_bundled/yutiv-live_commerce/src/Models/LiveTenant.php
 M plugins/_bundled/yutiv-live_commerce/src/Models/TeeWideUser.php
 M plugins/_bundled/yutiv-live_commerce/src/Providers/LiveCommerceServiceProvider.php
 M plugins/_bundled/yutiv-live_commerce/src/Support/SellerBrand.php
 M plugins/_bundled/yutiv-live_commerce/src/Support/TeeWideAuth.php
 M plugins/_bundled/yutiv-live_commerce/src/Support/TeeWideConfig.php
 M plugins/_bundled/yutiv-live_commerce/src/Support/TeeWidePresenter.php
 M plugins/_bundled/yutiv-live_commerce/tests/Feature/SellerBrandTest.php
 M plugins/_bundled/yutiv-live_commerce/tests/Feature/TeeWideSafetyTest.php
 M plugins/_bundled/yutiv-live_commerce/tests/Feature/TeeWideSessionDriverLifecycleTest.php
 M plugins/_bundled/yutiv-live_commerce/tests/Feature/TeeWideSessionIsolationTest.php
 M plugins/_bundled/yutiv-live_commerce/tests/Feature/TeeWideTenantDatabaseTest.php
 M plugins/_bundled/yutiv-live_commerce/tests/PluginTestCase.php
 M routes/web.php
?? plugins/_bundled/yutiv-live_commerce/components.json
?? plugins/_bundled/yutiv-live_commerce/database/migrations/2026_10_13_000004_create_live_operations.php
?? plugins/_bundled/yutiv-live_commerce/dist/css/plugin.css
?? plugins/_bundled/yutiv-live_commerce/dist/js/plugin.iife.js
?? plugins/_bundled/yutiv-live_commerce/docs/CHANGED_FILES.md
?? plugins/_bundled/yutiv-live_commerce/docs/LIVE_OPERATIONS.md
?? plugins/_bundled/yutiv-live_commerce/docs/VERIFICATION.md
?? plugins/_bundled/yutiv-live_commerce/docs/api/README.md
?? plugins/_bundled/yutiv-live_commerce/docs/verification/camera-fallback-390.png
?? plugins/_bundled/yutiv-live_commerce/docs/verification/customer-order-final-390.png
?? plugins/_bundled/yutiv-live_commerce/docs/verification/empty-shop-390.png
?? plugins/_bundled/yutiv-live_commerce/docs/verification/final-network-results.json
?? plugins/_bundled/yutiv-live_commerce/docs/verification/flow-results.json
?? plugins/_bundled/yutiv-live_commerce/docs/verification/headquarters-final-1440.png
?? plugins/_bundled/yutiv-live_commerce/docs/verification/headquarters-final-1920.png
?? plugins/_bundled/yutiv-live_commerce/docs/verification/headquarters-final-390.png
?? plugins/_bundled/yutiv-live_commerce/docs/verification/headquarters-logo-mobile.png
?? plugins/_bundled/yutiv-live_commerce/docs/verification/labels-50x30.pdf
?? plugins/_bundled/yutiv-live_commerce/docs/verification/labels-final-1440.png
?? plugins/_bundled/yutiv-live_commerce/docs/verification/main-1440.png
?? plugins/_bundled/yutiv-live_commerce/docs/verification/main-1920.png
?? plugins/_bundled/yutiv-live_commerce/docs/verification/main-390.png
?? plugins/_bundled/yutiv-live_commerce/docs/verification/offline-390.png
?? plugins/_bundled/yutiv-live_commerce/docs/verification/repair-final-390.png
?? plugins/_bundled/yutiv-live_commerce/docs/verification/second-brand-final.png
?? plugins/_bundled/yutiv-live_commerce/docs/verification/seller-final-1440.png
?? plugins/_bundled/yutiv-live_commerce/docs/verification/seller-final-1920.png
?? plugins/_bundled/yutiv-live_commerce/docs/verification/seller-final-390.png
?? plugins/_bundled/yutiv-live_commerce/docs/verification/states-results.json
?? plugins/_bundled/yutiv-live_commerce/docs/verification/validation-error-390.png
?? plugins/_bundled/yutiv-live_commerce/lang/en/live.php
?? plugins/_bundled/yutiv-live_commerce/lang/ko/live.php
?? plugins/_bundled/yutiv-live_commerce/package-lock.json
?? plugins/_bundled/yutiv-live_commerce/package.json
?? plugins/_bundled/yutiv-live_commerce/resources/css/live.css
?? plugins/_bundled/yutiv-live_commerce/resources/js/index.js
?? plugins/_bundled/yutiv-live_commerce/resources/layouts/admin/live_office.json
?? plugins/_bundled/yutiv-live_commerce/resources/routes.json
?? plugins/_bundled/yutiv-live_commerce/resources/views/customer/account.blade.php
?? plugins/_bundled/yutiv-live_commerce/resources/views/customer/cart.blade.php
?? plugins/_bundled/yutiv-live_commerce/resources/views/customer/order.blade.php
?? plugins/_bundled/yutiv-live_commerce/resources/views/customer/password.blade.php
?? plugins/_bundled/yutiv-live_commerce/resources/views/customer/shop.blade.php
?? plugins/_bundled/yutiv-live_commerce/resources/views/layouts/operations.blade.php
?? plugins/_bundled/yutiv-live_commerce/resources/views/mail/reset.blade.php
?? plugins/_bundled/yutiv-live_commerce/resources/views/seller/labels.blade.php
?? plugins/_bundled/yutiv-live_commerce/resources/views/seller/office.blade.php
?? plugins/_bundled/yutiv-live_commerce/resources/views/seller/order.blade.php
?? plugins/_bundled/yutiv-live_commerce/resources/views/seller/service-forms.blade.php
?? plugins/_bundled/yutiv-live_commerce/resources/views/seller/staff.blade.php
?? plugins/_bundled/yutiv-live_commerce/resources/views/seller/stock-forms.blade.php
?? plugins/_bundled/yutiv-live_commerce/resources/views/seller/unit-history.blade.php
?? plugins/_bundled/yutiv-live_commerce/src/Console/LiveMaintainCommand.php
?? plugins/_bundled/yutiv-live_commerce/src/Contracts/LiveRepositoryInterface.php
?? plugins/_bundled/yutiv-live_commerce/src/Enums/BroadcastState.php
?? plugins/_bundled/yutiv-live_commerce/src/Enums/DeliveryState.php
?? plugins/_bundled/yutiv-live_commerce/src/Enums/LiveEntity.php
?? plugins/_bundled/yutiv-live_commerce/src/Enums/PaymentState.php
?? plugins/_bundled/yutiv-live_commerce/src/Enums/StaffRole.php
?? plugins/_bundled/yutiv-live_commerce/src/Enums/StockState.php
?? plugins/_bundled/yutiv-live_commerce/src/Http/Controllers/Admin/LiveManagementController.php
?? plugins/_bundled/yutiv-live_commerce/src/Http/Controllers/Customer/LiveCustomerController.php
?? plugins/_bundled/yutiv-live_commerce/src/Http/Controllers/Customer/LiveShopController.php
?? plugins/_bundled/yutiv-live_commerce/src/Http/Controllers/LiveAssetController.php
?? plugins/_bundled/yutiv-live_commerce/src/Http/Controllers/LiveMediaController.php
?? plugins/_bundled/yutiv-live_commerce/src/Http/Controllers/Seller/LiveOfficeController.php
?? plugins/_bundled/yutiv-live_commerce/src/Http/Controllers/Seller/LiveWorkflowController.php
?? plugins/_bundled/yutiv-live_commerce/src/Http/Middleware/ResolveLiveTenant.php
?? plugins/_bundled/yutiv-live_commerce/src/Http/Requests/LiveCatalogRequest.php
?? plugins/_bundled/yutiv-live_commerce/src/Http/Requests/LiveCustomerRequest.php
?? plugins/_bundled/yutiv-live_commerce/src/Http/Requests/LiveHeadquartersMediaRequest.php
?? plugins/_bundled/yutiv-live_commerce/src/Http/Requests/LiveHeadquartersRequest.php
?? plugins/_bundled/yutiv-live_commerce/src/Http/Requests/LiveLoginRequest.php
?? plugins/_bundled/yutiv-live_commerce/src/Http/Requests/LiveMediaRequest.php
?? plugins/_bundled/yutiv-live_commerce/src/Http/Requests/LiveOfficeRequest.php
?? plugins/_bundled/yutiv-live_commerce/src/Http/Requests/LiveRegistrationRequest.php
?? plugins/_bundled/yutiv-live_commerce/src/Http/Requests/LiveWorkflowRequest.php
?? plugins/_bundled/yutiv-live_commerce/src/Mail/LivePasswordResetMail.php
?? plugins/_bundled/yutiv-live_commerce/src/Models/LiveRecord.php
?? plugins/_bundled/yutiv-live_commerce/src/Repositories/LiveRepository.php
?? plugins/_bundled/yutiv-live_commerce/src/Rules/ApprovedExternalUrl.php
?? plugins/_bundled/yutiv-live_commerce/src/Services/IncentiveCalculator.php
?? plugins/_bundled/yutiv-live_commerce/src/Services/LiveAccountService.php
?? plugins/_bundled/yutiv-live_commerce/src/Services/LiveCatalogService.php
?? plugins/_bundled/yutiv-live_commerce/src/Services/LiveCustomerService.php
?? plugins/_bundled/yutiv-live_commerce/src/Services/LiveDomainService.php
?? plugins/_bundled/yutiv-live_commerce/src/Services/LiveHeadquartersService.php
?? plugins/_bundled/yutiv-live_commerce/src/Services/LiveIncentiveService.php
?? plugins/_bundled/yutiv-live_commerce/src/Services/LiveInventoryService.php
?? plugins/_bundled/yutiv-live_commerce/src/Services/LiveMediaService.php
?? plugins/_bundled/yutiv-live_commerce/src/Services/LiveOfficeService.php
?? plugins/_bundled/yutiv-live_commerce/src/Services/LiveOrderAdjustmentService.php
?? plugins/_bundled/yutiv-live_commerce/src/Services/LiveOrderService.php
?? plugins/_bundled/yutiv-live_commerce/src/Services/LivePasswordResetService.php
?? plugins/_bundled/yutiv-live_commerce/src/Services/LiveReturnService.php
?? plugins/_bundled/yutiv-live_commerce/src/Services/LiveStatisticsService.php
?? plugins/_bundled/yutiv-live_commerce/src/Services/LiveWorkflow.php
?? plugins/_bundled/yutiv-live_commerce/src/Support/LiveContext.php
?? plugins/_bundled/yutiv-live_commerce/src/Support/LiveForms.php
?? plugins/_bundled/yutiv-live_commerce/src/Support/LiveRoutes.php
?? plugins/_bundled/yutiv-live_commerce/src/Support/ScopedLiveUserProvider.php
?? plugins/_bundled/yutiv-live_commerce/src/routes/api.php
?? plugins/_bundled/yutiv-live_commerce/tests/Feature/LiveOperationsTest.php
?? plugins/_bundled/yutiv-live_commerce/tests/Support/live-concurrency-worker.php
?? plugins/_bundled/yutiv-live_commerce/vite.config.js
?? tests/scenarios/live-independent-operations.yaml
```
