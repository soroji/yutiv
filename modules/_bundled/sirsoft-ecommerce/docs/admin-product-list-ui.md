# 관리자 상품 목록 UI 수정

## 추가 간격 조정 (2026-10-10)

이번 작업 시작 HEAD는 `c7fe4bba9c2a9add3354c7dff93d45e6576549cf`이고 작업 트리는 깨끗했다. 사용자가 서버에서 확인한 Select 메뉴 및 sticky 동작은 유지했다.

| 영역 | 이번 설정 |
|---|---|
| 관리 | 108px, 좌우 padding 8px, 수정 + 아이콘 더보기. 더보기 문자열은 aria-label·title에만 표시 |
| 상품명 | 292px, 좌우 padding 8px. 첫 줄은 선택 언어와 코드, 둘째 줄은 이름 입력 전체 폭 |
| 정가·판매가 | 열 112px, 입력 기본 96px, 입력 좌우 padding 4px |
| 재고 | 열 84px, 입력 기본 68px, 입력 좌우 padding 4px |

`MultilingualInput.layout=list`와 `identifierText`는 이번 상품 목록에만 적용한다. 다른 inline/tabs/compact 화면의 배치는 유지하며 지원 언어 전체, 필수 언어 표시, 로컬 번역 버퍼와 기존 change/debounce 이벤트를 재사용한다. 긴 코드는 생략하고 title로 전체값을 제공한다. 코드가 열의 intrinsic 폭을 늘리지 않도록 이름 셀 내부를 276px/최대 100%로 제한한다.

가격 저장 컬럼은 `decimal(15,2)`로 정수 13자리와 소수 2자리, 재고는 signed integer로 비음수 최대 2,147,483,647까지 저장할 수 있다. 이는 DB 저장 범위이며 새 API 제한을 도입한 것이 아니다. 기존 통화별 소수점 규칙과 API 검증을 변경하지 않았다. 작은 고정 입력 폭으로 최대 자릿수를 가릴 수 있어 monospace 14px 글꼴과 `max(기본폭, 문자수 × 8.5 + 24)px` 표시 폭을 사용한다. 긴 숫자나 다통화 표시·재고 경고가 있으면 표의 해당 열이 더 넓어질 수 있다. 숫자는 생략하지 않고 표 내부 가로 스크롤과 관리 열 접근성을 유지한다.

브라우저 연결 목록이 비어 있어 1920/1440/1280px의 실제 화면, 사이드바 펼침/접힘, 스크롤 전후 sticky 위치, 작은 화면 메뉴 펼침과 전후 캡처는 미실행이다. JSDOM 테스트에서 첫 줄/둘째 줄 DOM 구분, 4개 언어 전환과 번역 보존, 기본 언어 필수 표시, 코드 tooltip, 관리 열 폭과 compact padding, 아이콘 메뉴의 접근 가능한 이름과 body portal을 확인한다. 기존 테스트가 실제 화면 검증을 대신하지 않는다. 서버 값·상품 상태는 변경하지 않았다.

아래 이전 검증 기록 및 서버 반영 명령은 유지한다. 이번에도 관리자 템플릿과 이커머스 모듈 둘 다 갱신해야 한다. 추가 migration·composer 변경은 없다.

이번 실행 결과: Select 67, Input 46 통과/3 기존 skip, DataGrid 65, ActionMenu 9, MultilingualInput 14, ProductListDensity 4로 총 205 통과/3 skip. 마지막 언어 선택 값의 문자열 타입 보정 후 MultilingualInput 14개를 다시 실행하고 최종 빌드 및 테스트 파일을 제외한 프로덕션 소스 TypeScript 검사를 통과했다. JSON 가격 편집 계약 테스트에서 type/value/disabled/change/debounce와 숫자 폭 확장 정책을 확인했다. `module:vendor-verify sirsoft-ecommerce`는 OK(0.4 MB, 1 package)이며 composer·lock 파일은 변경하지 않았다. 기존 tabs 테스트의 중첩 button 경고는 남아 있고 이번 list 경로에서는 해당 tabs 마크업을 사용하지 않는다.

변경 파일: 관리자 `src/components/composite/{ActionMenu,DataGrid,MultilingualInput}.tsx`, `__tests__/{DataGrid,MultilingualInput}.test.tsx`, 신규 `__tests__/ProductListDensity.test.ts`, `components.json`, `CHANGELOG.md`, `dist/js/components.iife.js`, 위 3개 컴포넌트의 `dist/src/components/composite/*.d.ts`; 모듈 상품 목록 `_partial_product_datagrid.json`, `CHANGELOG.md`, 이 문서. CSS는 정식 빌드로 재생성했으며 기존 산출물과 동일하다. 기존 checkout 및 고객용 템플릿은 수정하지 않았다.

대상: `/admin/ecommerce/products`. 작업 시작 HEAD: `602e84973eeb694fa6d12dc9dca95779345a9a5d`.
기존 checkout·계좌 설정 관련 변경 및 사용자 파일을 보존했다. 상품 값이나 상태, 주문·결제를 서버에서 변경하지 않았다.

## 실제 구현과 원인

실제 서버 관리자 HTML의 `data-template-id` 및 CSS/JS 자산 URL에서 `sirsoft-admin_basic`을 확인했다. 이는 HTML 확인이며 브라우저 렌더링 확인은 아니다.

- 상품 목록은 `DataGrid`, 필터 및 상태 선택은 `Select`, 행 더보기는 `ActionMenu`, 상품명은 `MultilingualInput`을 사용한다. 일반 `Table`/`Dropdown`도 추적했으나 해당 행 작업 메뉴의 구현은 `ActionMenu`다.
- 공통 Select 메뉴가 트리거 폭에 고정되어 패딩·체크 표시를 제외한 한글 표시 공간이 부족했다. 메뉴 폭을 내용 기반으로 분리하고 트리거 이상으로 유지하되 화면 폭으로 제한했다. 항목은 한 줄 생략과 전체 텍스트 title을 제공한다. 체크 표시와 스크롤바 공간은 별도로 확보한다.
- DataGrid 작업 열은 스크롤하는 일반 셀이었다. 상품 목록에서만 sticky를 활성화하고 불투명 배경·경계·겹침 순서를 설정했다. 수정 버튼은 기존 edit 이벤트 및 disabledField 권한 판정을 그대로 호출한다. 복사·삭제와 확인 모달은 기존 이벤트를 사용한다.
- 상품명 열 200px와 전체 언어명 tabs 표시가 함께 행 높이를 늘렸다. 340px와 기존 compact 언어 코드 버튼으로 변경했다. 상품코드는 상품명 아래에 유지하고 썸네일은 64px, 이미지 열은 112px로 정리했다. 가격·재고 등 나머지 열을 무리하게 줄이지 않았다.
- Select와 ActionMenu는 기존 document.body portal을 유지해 표 overflow 및 sticky 셀에 잘리지 않는다. 화면 경계를 보정하고 키보드 이동·선택·ESC·포커스 복원을 검증했다. 고객용 Still Form CSS는 수정하지 않았다.

## 변경 파일과 산출물

- 관리자 템플릿: `src/components/basic/Select.tsx`, `src/components/composite/{DataGrid,ActionMenu}.tsx`, 관련 컴포넌트 테스트, `components.json`, `CHANGELOG.md`.
- 관리자 빌드: `dist/js/components.iife.js`, `dist/src/components/composite/DataGrid.d.ts`. CSS도 정식 빌드로 재생성했으며 기존 산출물과 동일하다. 소스맵은 기존 Git ignore 정책을 따른다.
- 이커머스 모듈: `resources/layouts/admin/partials/admin_ecommerce_product_list/_partial_product_datagrid.json`, `resources/lang/partial/{ko,en}/admin/product.json`, `CHANGELOG.md`, 이 문서.
- composer 파일·잠금 파일·의존성·버전은 변경하지 않았다. 새 optional DataGrid 속성은 관리자 컴포넌트 manifest에도 기록했다.

## 검증

- Select 67, DataGrid 64, ActionMenu 9, Dropdown 10, MultilingualInput 13: 총 163개 테스트 통과.
- 1920/1440/1280/768/390px 경계 테스트는 JSDOM의 viewport와 DOM 좌표를 모킹한 Select 회귀 테스트다. 실제 화면 캡처나 전체 상품 페이지 렌더링 검증으로 해석하면 안 된다.
- 관리 열 sticky, 원래 행 식별자를 전달하는 수정 이벤트, 수정 권한 차단, portal 메뉴, 키보드 비활성 항목 건너뛰기 및 포커스 복원, compact 다국어 변경값 보존을 검증했다.
- `npm run build` 및 테스트 파일을 제외한 프로덕션 소스 TypeScript 검사 통과. `module:vendor-verify sirsoft-ecommerce` 통과(0.4 MB, 1 package).
- 브라우저 연결이 없어 실제 viewport별 화면, 사이드바 펼침/접힘, 표 스크롤 양 끝, 필터·행/일괄 상태·정렬·페이지 크기 메뉴 실제 펼침, 수정 화면 이동과 수정 전후 스크린샷은 미검증이다. 서버 응답을 로컬 코드로 덮어쓴 검증도 하지 않았다.
- 기존 tabs 다국어 컴포넌트 테스트에서 중첩 button 경고가 발생한다. 상품 목록은 해당 tabs 경로 대신 기존 compact 경로를 사용한다. 전체 프로젝트 type-check에는 기존 테스트 파일의 타입 오류가 남아 있으므로 빌드 및 테스트 통과와 구분한다.

## 서버 반영

사용자가 변경 파일과 빌드 산출물을 서버 작업 트리에 반영한 뒤 실행한다. 이번 수정에는 관리자 템플릿과 모듈을 모두 갱신해야 한다. 기존 로컬 checkout 변경의 배포 절차는 별도다.

```sh
php artisan module:vendor-verify sirsoft-ecommerce
php artisan template:update sirsoft-admin_basic --source=bundled --force --layout-strategy=overwrite --no-interaction
php artisan module:update sirsoft-ecommerce --source=bundled --vendor-mode=bundled --force --layout-strategy=overwrite --no-interaction
php artisan template:cache-clear sirsoft-admin_basic
php artisan module:cache-clear sirsoft-ecommerce
```

`--layout-strategy=overwrite`는 설치된 DB 레이아웃에 번들 레이아웃을 반영한다. 해당 확장에 별도 서버 사용자 레이아웃 수정이 있다면 먼저 백업한다. DB migration·워커 재시작·의존성 업데이트는 필요하지 않다.

로컬 재빌드/회귀 명령:

```sh
cd templates/_bundled/sirsoft-admin_basic
npm ci --ignore-scripts --no-audit --no-fund
npm run test:run -- src/components/basic/__tests__/Select.test.tsx src/components/composite/__tests__/DataGrid.test.tsx src/components/composite/__tests__/ActionMenu.test.tsx src/components/composite/__tests__/Dropdown.test.tsx src/components/composite/__tests__/MultilingualInput.test.tsx --maxWorkers=1 --pool=forks
npm run build
```
