# 관리자 번역 패널 렌더링 수정 (ecommerce1.2.4 / admin_basic1.0.11)

기준 HEAD `8eac79e085c15fdcc9a347b41ded13374ce23ad5`, 작업 시작 Git 변경 없음. commit/push·서버 적용·서버 상품 변경·유료 AI 요청은 수행하지 않습니다.

## 확정 원인

`CatalogTranslationPanel`은 `G7Core.t('sirsoft-ecommerce.admin.translation.title')`을 호출합니다. 점으로 구분된 키 문법은 기존 AdminHeader 등과 동일하며 `$t:`는 JSON 문자열 치환 문법입니다. 컴포넌트 함수 호출에 `$t:`를 덧붙이는 수정은 필요하지 않습니다.

실제 누락은 모듈 `resources/lang/ko.json`, `en.json`의 `admin.translation` 연결입니다. `resources/lang/partial/{locale}/admin/translation.json` 파일만 추가되고 언어 루트 `$partial`에서 참조하지 않아 클라이언트 사전에 포함되지 않았습니다. 정상 product/category 사전은 루트에서 각각 `$partial`로 연결됩니다.

실제 서버 로딩은 `TemplateService::loadActiveModulesLanguageData()` → 활성 모듈 `resources/lang/{locale}.json` → `loadLanguageFileWithFragments()` → `ResolvesLanguageFragments` → 모듈 식별자 아래 병합입니다. 프런트 `TranslationEngine`과 `G7CoreGlobals`의 t는 사전에 없는 문자열을 키 그대로 반환합니다. ko/en 루트에 translation partial 연결을 추가했습니다. 한국어 하드코딩은 하지 않습니다.

미해석 문자열이 나타난 요소는 접힌 패널의 버튼입니다. 기존 `opened=false` 때문에 본문과 실행 버튼은 버튼을 누르기 전에는 DOM에 없었습니다. 단독 컴포넌트 검사는 마지막 키 조각을 반환하는 번역 mock을 써 실제 사전 연결 누락을 잡지 못했습니다. 기존 클릭 자체의 고장을 추측해 이벤트를 교체하지 않습니다. 이번 수정은 폼의 기본 펼침과 명확한 버튼 표시를 제공하고 실제 등록/렌더링 경로에서 클릭을 검증합니다.

## 연결 경로와 변경

- 상품 신규/수정: 동일 `admin_ecommerce_product_form.json` 상단 header 다음 `CatalogTranslationPanel`. form/_local.ui.optionInputs, form.id를 전달하며 수정 권한은 기존 product.data.abilities.can_update를 사용합니다.
- 카테고리 신규/수정: `_panel_form.json` 상단 header 다음 동일 컴포넌트. panelMode=create/edit, selectedCategoryId와 기존 categories.data.abilities를 사용합니다.
- 두 폼에 선택적 `defaultExpanded=true`를 선언합니다. 이전 레이아웃은 기본 접힘을 유지합니다. 설정 화면의 configurationOnly는 기존처럼 상태만 표시합니다.
- `components.json`의 composite 등록에 boolean prop 추가 → composite/index.ts 기존 export → src/index.ts export → Vite IIFE `SirsoftAdminBasic.CatalogTranslationPanel` → 실제 ComponentRegistry manifest 등록 → DynamicRenderer의 props 바인딩·custom action·__componentContext 전달. 이름·기존 export는 정상이라 불필요하게 바꾸지 않았습니다.
- 상단 버튼을 기존 관리자 btn-primary 스타일로 표시하고 접기/펼치기 문구, aria-expanded, aria-controls와 본문 region을 제공합니다. 읽기 전용도 안내를 열 수 있으나 실행/적용의 disabled와 서버 권한 검증은 그대로입니다.
- 열기/페이지 로드는 설정 GET만 수행합니다. 번역 POST는 명시적 실행 버튼만, 결과 적용은 기존 onChange/setState, 상품 저장은 기존 저장 버튼만 사용합니다. 옵션 생성·SKU·가격·재고·주문·카테고리 연결을 변경하지 않습니다.
- ready는 실행 가능, disabled/not_configured/config_missing은 각각의 안내와 진입점을 유지하고 실행을 막습니다. API 오류 안내도 기존대로 유지합니다.

## 검사 방법과 한계

`resources/js/__tests__/layouts/catalogTranslationRendering.test.tsx`는 실제 폼 JSON의 상단 header와 번역 진입 영역을 읽습니다(무관한 하단 섹션은 제외). 실제 빌드 IIFE를 Node VM에 로드하고 **실제 components.json을 ComponentRegistry.loadComponents로 등록**한 뒤 실제 DynamicRenderer/DataBindingEngine/ActionDispatcher로 렌더링합니다. source 컴포넌트 직접 등록으로 성공 처리하지 않습니다.

ko/en 사전은 `tests/fixtures/catalog-language.php`에서 **실제 PHP ResolvesLanguageFragments**를 거쳐 가져옵니다. 실제 TranslationEngine을 사용하므로 언어 루트 연결이 빠지면 번역 키 검사/제목 검사에서 실패합니다. 신규·수정 4가지 폼, ready 및 미연결3상태, 접기/펼치기, 입력·대상 선택, 실행·결과 검토·폼 적용, 비공개 읽기 권한 제한 및 값/옵션 식별자 보존을 검사합니다. fetch는 manifest/configuration/가짜 작업 응답만 허용하고 상품 저장/AI 네트워크 호출을 허용하지 않습니다.

이는 jsdom의 G7 렌더링 통합 검사입니다. 전체 페이지/웹서버/실제 브라우저 화면·좌표·스크린샷 검사는 아닙니다. browser 스킬로 연결을 확인했으나 사용 가능한 브라우저 목록이 비어 있어 실제 화면 캡처는 미실행입니다. 기존 DB/환경 파일은 변경하지 않으며 PHP 회귀는 기존 격리 SQLite 구성만 사용합니다.

```bash
# 관리자 템플릿 디렉터리: IIFE를 먼저 갱신
npm run build
# ecommerce 모듈 디렉터리. php가 PATH에 없으면 실제 로컬 PHP 경로를 G7_TEST_PHP로 지정
npm run test:run -- resources/js/__tests__/layouts/catalogTranslationRendering.test.tsx
# 프로젝트 루트의 기존 격리 PHP 검사
php vendor/phpunit/phpunit/phpunit --configuration modules/_bundled/sirsoft-ecommerce/tests/catalog-translation.phpunit.xml
```

PHP 전체 회귀49개/461 assertions 통과. 관리자 소스 컴포넌트/보호 로직 검사와 실제 IIFE 렌더링 검사는 구분해 기록합니다. 모듈 JS 구현 변경은 없고 관리자 JS/타입 선언을 재빌드합니다. composer 버전 동기화 후 공식 `module:vendor-bundle` staging composer install과 vendor 무결성 검사 통과, composer.lock/의존성은 보존합니다. 로컬 활성 모듈 디렉터리가 없어 빈 임시 빌드 자리만 만들고 공식 빌드 후 제거했습니다. 해시를 수동 수정하지 않았습니다.

빌드를 처음에 루트에서 실행한 실수는 core Vite 변환 중 중단했고, diff에 코어 산출물 변경이 없음을 확인한 뒤 관리자 템플릿 빌드로 바로잡았습니다.

## 서버 갱신 대상

**sirsoft-ecommerce1.2.4**(ko/en 루트·partial·상품/카테고리 DB 레이아웃·manifest/vendor)와 **sirsoft-admin_basic1.0.11**(패널 소스·등록 manifest·dist)을 함께 갱신합니다. Still Form/TeeWide/checkout/fitColumns/엑셀 소스는 변경하지 않습니다. API·저장·권한 계약의 변경이 없고 새 prop은 선택적이라 다른 확장 최소 버전 제약은 유지합니다. 새 migration/큐/스케줄러/의존성은 없습니다.

소스/빌드 산출물을 사용자가 반영한 후:

```bash
php artisan module:vendor-verify sirsoft-ecommerce
php artisan module:update sirsoft-ecommerce --source=bundled --vendor-mode=bundled --force --layout-strategy=overwrite --no-interaction
php artisan template:update sirsoft-admin_basic --source=bundled --force --layout-strategy=overwrite --no-interaction
php artisan config:cache
php artisan ecommerce:translation-status
```

기존 업데이트 경로는 template language/layout 캐시를 무효화합니다. 관리자 브라우저 새로고침 후 상품 신규/수정 및 카테고리 신규/수정 상단에서 번역 제목, 본문, 대상 언어/항목, 실행 버튼을 확인하세요. 준비된 연결은 ready이며, 페이지 진입만으로 번역 작업이 생성되면 안 됩니다. 기존 번역 워커 코드/접속 설정은 변경하지 않았습니다.

주요 수정: 언어 루트2개·partial2개·폼 JSON2개, CatalogTranslationPanel.tsx/components.json/template.json/dist, 모듈 버전 manifest/composer/package/vendor, 테스트2개, CHANGELOG와 이 문서.

## 최종 실행 결과

- 실제 JSON/manifest/빌드 IIFE/PHP 언어 partial 경로의 jsdom 통합 검사: 17개 통과. 네 폼의 ready, disabled, not_configured, config_missing 및 영어/읽기 전용 접근 검사. 테스트 간 페이지별 pending 상태를 초기화한 후 통과했습니다.
- 기존 패널·번역 보호 헬퍼: 21개 통과. 격리 PHP 회귀: 49개 / 461 assertions 통과.
- 관리자 공식 빌드 성공: IIFE 740.78 kB, CSS 590.39 kB. 운영 소스 TypeScript 검사 오류 0개. 전체 type-check는 기존 __tests__ 파일의 타입 오류로 실패했습니다(이번 범위 밖의 LayoutEditor, DataGrid, TabNavigationScroll 등).
- PHP 문법, Pint, git diff --check 통과. 공식 vendor 번들 재생성 및 module:vendor-verify 통과(425.0 KB, 1 package). 의존성/잠금 파일은 변경하지 않았습니다.
- 실제 브라우저 목록이 비어 있어 화면 캡처·전체 페이지 배치·실제 서버 렌더링은 미검증입니다. 서버 접근/적용, 실상품 저장, 유료 AI 호출, commit/push는 수행하지 않았습니다.
