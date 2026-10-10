# 상품·카테고리 AI 다국어 번역

## 관리자 사용

- 상품 관리 → 상품 등록/수정(`/admin/ecommerce/products/create`, 기존 상품의 수정 버튼) → **AI 다국어 번역**.
- 카테고리 관리(`/admin/ecommerce/categories`) → 새 카테고리 또는 수정 패널 → **AI 다국어 번역**.
- 연결 상태: YUTIV 메뉴 **쇼핑몰 설정 → 환경설정 → 언어/통화 → AI 번역 연결 상태**. URL: `/admin/ecommerce/settings?tab=language_currency`. 기본 관리자 메뉴에서는 이커머스 → 환경설정입니다.
- 한국어 작성 → 대상 en/ja/zh-CN·항목 선택 → 필요하면 보존 문구 입력 → 번역 → 결과 수정/검토 → 폼에 적용 → 기존 저장 버튼.
- 비어 있는 번역만 채우기가 기본입니다. 기존 번역 덮어쓰기는 체크해야 합니다. 빈 한국어는 건너뜁니다. 번역/적용만으로 상품이나 카테고리를 생성·공개하지 않습니다.
- 실패한 항목만 재시도합니다. 원문/대상 번역을 작업 중 수정한 항목은 적용하지 않고 보호 항목 수를 표시합니다. 새 결과는 다시 번역해 검토하세요. 적용 시 기록한 한국어가 변경되면 ‘번역 갱신 필요’를 표시합니다. 기존 수동 번역에는 이 기록이 없으므로 과거 번역의 생성 당시 원문은 추정하지 않습니다.
- 선택 옵션명은 색상, 옵션값은 로즈핑크, 코랄, 레드처럼 입력합니다. 언어 선택기는 입력 위의 작은 선택기입니다. PC 2열, 좁은 화면 1열입니다. 조합 생성은 기존 버튼만 사용하며 AI는 조합을 생성하지 않습니다.

## 지원 필드와 저장 호환성

| 대상 | 번역 항목 | 저장 |
| --- | --- | --- |
| 상품 | 이름, 상세설명(텍스트/HTML), SEO 제목·설명 | 기존 JSON 다국어 필드 |
| 상품 | SEO 키워드 | 새 nullable JSON `meta_keywords_translations`; 기존 `meta_keywords` 배열 유지 |
| 상품 | 선택 옵션 그룹명·값, 판매 단위 표시명 | 기존 `option_groups`, `options.option_name/option_values` |
| 상품 | 상품추가옵션 그룹명·선택값 | 기존 추가옵션/선택값 name JSON |
| 카테고리 | 이름·설명 | 기존 JSON 필드 |
| 카테고리 | SEO 제목·설명 | 새 nullable JSON `meta_title_translations`, `meta_description_translations`; 기존 문자열 유지 |

별도 간단 설명 입력 필드는 실제 상품 모델/저장 요청에 없습니다. AI용 가짜 필드를 추가하지 않습니다. 검색 요약은 기존 상세설명에서 파생됩니다. 이미지의 alt/속성·이미지 내 글자·slug·코드·SKU·ID·가격·통화·재고·정렬·연결 관계는 번역하지 않습니다. SEO 자동 동기화가 켜져 있으면 해당 SEO 항목을 별도 번역하지 않고 기존 저장 시 상품명/설명 동기화 규칙을 따릅니다.

한국어 브랜드명(폼에 있는 경우), 입력한 보존 문구, ASCII 모델번호/숫자/단위/URL은 자리표시자로 보존합니다. 다른 고유명은 보존 문구에 명시하고 결과를 검토하세요. 품질과 사실의 의미적 동등성은 자동으로 증명하지 않으므로 사람의 검토가 필요합니다.

추가 migration `2026_10_10_000001_add_catalog_translation_support.php`: 위 nullable 컬럼, 두 모델의 `translation_sources`(원문 변경 표시), 임시 작업 테이블 `ecommerce_translation_jobs`. 기존 데이터를 변환하거나 삭제하지 않습니다. 실제 번역은 기존 상품/카테고리/옵션에 저장하고 작업 테이블은 24시간 보관하는 임시 원문·결과·상태입니다. 공개 출력 fallback은 현재 언어 → 설정 fallback → 한국어 → 첫 비어 있지 않은 이름입니다. SEO 새 맵이 비어 있으면 기존 값으로 돌아갑니다. Still Form의 메뉴·목록·breadcrumb은 기존 `name_localized` 응답을 사용합니다.

## 서버 연결 설정

기존 AI 연결 기능이 없어 제공자 교체 가능한 `TranslationProviderInterface`와 HTTPS chat-completions 호환 어댑터를 추가했습니다. 새 유료 제공자나 모델을 지정하지 않았습니다. 실제 제공자가 JSON response format과 아래 응답 계약을 지원하는지 선택/확인이 필요합니다. 다른 프로토콜은 인터페이스 어댑터로 교체하세요.

서버 `.env`에 관리자가 선택한 값만 설정합니다(실제 비밀키는 Git·브라우저·로그에 기록 금지).

```dotenv
YUTIV_TRANSLATION_DRIVER=compatible
YUTIV_TRANSLATION_ENDPOINT=https://YOUR_PROVIDER_HOST/YOUR_CHAT_COMPLETIONS_PATH
YUTIV_TRANSLATION_MODEL=YOUR_SELECTED_MODEL
YUTIV_TRANSLATION_API_KEY=YOUR_SERVER_SECRET
```

기본 driver는 `disabled`. 관리자 화면은 연결 설정 유무만 표시하고 비밀키 편집/조회 API는 제공하지 않습니다. 연결 유무는 값의 존재/형식 검사이며 실제 공급자 연결 성공을 의미하지 않습니다. endpoint는 서버 설정에만 있고 관리자의 번역 요청으로 변경할 수 없습니다. HTTPS만 허용하며 redirect는 사용하지 않습니다. 키 변경 후 config 캐시와 전용 워커를 갱신하세요.

모델 응답: `{"locale":"en","translations":{"t0":"..."}}`. 서버는 locale, 정확한 ID 집합, 문자열, 길이, HTML 금지, 보존 토큰을 검증합니다. HTML 태그/속성/이미지 주소/링크는 모델에 전달하지 않고 원본 그대로 복원합니다. 코드·pre·script·style 내용은 번역하지 않습니다. 제품 설명 저장은 기존 ProductService의 HTMLPurifier를 그대로 거칩니다. 카테고리 설명은 기존 저장 정책을 유지합니다.

## 제한·작업 처리

- HTTP body 최대 1 MiB, items JSON UTF-8 최대 512 KiB, 언어별 항목 합계 200, 원문 항목 65,535자.
- 보존 문구 100개, 각각 100자. 결과는 실제 필드 제한(name 상품200/카테고리100, SEO 제목200/설명·키워드500, 옵션100/판매 단위 표시명200, 설명 상품65,535/카테고리255)에 맞춰 검증합니다.
- 텍스트 노드 1,500자 분할, 공급자 호출당 8조각, 응답128 KiB. 연결5초/호출25초, 항목 총150초(마지막 호출 포함 최대175초), 워커180초, reservation240초.
- 시작/재시도 각 분당5회. 관리자당 활성 작업 하나, 항목 동시 실행 최대2, 작업15분. 항목의 명시적 시도 최대3회(최초 포함). 동일 원문/항목/언어는 같은 작업 내 검증된 결과를 재사용합니다.
- request UUID + 서버 fingerprint, DB 행 잠금/항목 상태로 중복 클릭·큐 중복 실행을 보호합니다. 유료 요청을 자동 재시도하지 않습니다. 워커 강제 종료/공급자 통신 장애 후 실제 공급자 과금 여부는 알 수 없으므로 명시적 재시도가 별도 비용을 만들 수 있습니다. 취소는 이미 진행 중인 공급자 요청/과금을 취소하지 않지만 결과 적용을 막습니다.
- 저장된 옵션 ID·SKU·조합·금액·재고 불변. 새 표시 객체에는 세션 UUID 사용, 적용은 ID/객체 식별자와 원문·대상 스냅샷으로 보호하며 번역 문구나 배열 순서로 매칭하지 않습니다. 기존 legacy object 조합은 기존 편집기의 변환 경로를 거쳐 사용하세요. 옵션값의 쉼표는 기존 CSV 입력과 혼동되어 결과 검증에서 거부합니다.
- 전용 database connection/queue `ecommerce-translation`; 기존 큐 설정을 재사용하지만 retry_after만240으로 분리합니다. 기존 TeeWide/엑셀 워커를 수정하지 않습니다. 조회/취소/재시도는 요청자 소유권과 현재 상품/카테고리 create/update 권한을 다시 검사합니다.

## 배포 명령(사용자가 실행)

소스/빌드 산출물을 서버에 반영한 뒤:

```bash
php artisan module:vendor-verify sirsoft-ecommerce
php artisan module:update sirsoft-ecommerce --source=bundled --vendor-mode=bundled --force --layout-strategy=overwrite --no-interaction
php artisan template:update sirsoft-admin_basic --source=bundled --force --layout-strategy=overwrite --no-interaction
php artisan template:update yutiv-stillform --source=bundled --force --layout-strategy=overwrite --no-interaction
php artisan optimize:clear
```

module:update의 기존 경로가 신규 module migration을 실행합니다. 적용 확인:

```bash
php artisan migrate:status --path=modules/sirsoft-ecommerce/database/migrations
```

전용 프로세스 관리자에 다음 워커를 추가/재시작합니다. 기존 워커는 그대로 유지하세요. 이 작업은 concurrency release가 있으므로 `--tries=1`로 제한하지 마세요.

```bash
php artisan queue:work ecommerce-translation --queue=ecommerce-translation --timeout=180 --sleep=2
```

기존 `php artisan schedule:run` 분당 실행이 있으면 새 cron은 필요 없습니다. 모듈의 daily 작업이 24시간 지난 임시 작업을 삭제합니다. 수동 정리: `php artisan ecommerce:prune-catalog-translations`.

개발 빌드:

```bash
cd templates/_bundled/sirsoft-admin_basic && npm run build
cd modules/_bundled/sirsoft-ecommerce && npm run build
# 프로젝트 루트(로컬에 활성 확장 디렉터리가 있는 빌드 환경)
php artisan module:vendor-bundle sirsoft-ecommerce --force
php artisan module:vendor-verify sirsoft-ecommerce
```

추가 Composer/npm 의존성 없음. 모듈1.2.2 composer 버전만 동기화하고 공식 번들 생성 절차로 zip/manifest를 재생성했습니다. composer.lock 및 의존성 버전은 그대로입니다. 템플릿1.0.9의 신규 컴포넌트와 모듈1.2.2 레이아웃을 함께 갱신해야 합니다. 신규 공개 필드는 선택적이고 기존 API 시그니처/필드 자료형은 유지되어 기존 소비 확장의 최소 버전은 유지하고 신규 category SEO 응답을 사용하는 Still Form만 >=1.2.2로 동기화합니다. 새 기능을 사용하는 관리자는 두 산출물을 같이 배포해야 합니다.

## 검증 기록

작업 전 HEAD: `11970a18cfe29d1992137ab4c4636ac04e83cca7`, Git 변경 없음. 이번 작업은 bundled ecommerce/admin_basic과 Still Form 카테고리 SEO 연결만 수정하며 기존 checkout·상품 목록 fitColumns·숫자 편집·엑셀·TeeWide 소스를 보존합니다.

PHP는 PHP8.4 + SQLite `:memory:` 격리 DB, 실제 migration/model/ProductService/CategoryService를 사용합니다. 공급자는 fake/HTTP fake이고 stray HTTP를 금지합니다. 고객의 ja/zh-CN 언어 선택 노출에는 기존 언어팩/활성 언어 설정이 필요하며 AI 번역은 통화·언어 활성 설정을 변경하지 않습니다.

브라우저 런타임은 연결 가능한 브라우저가 없어 실제 화면/스크린샷·실제 IME·실제 유료 공급자·실제 DB(MySQL)/서버/워커 장기 동작은 미검증입니다. 컴포넌트 DOM/IME 이벤트 모킹 검사는 실제 브라우저 검사와 구분합니다. 서버 접근·상품 변경·주문/결제·commit/push/deploy는 수행하지 않았습니다.

테스트 구성은 `tests/catalog-translation.phpunit.xml`에 보관합니다. DB 설정을 강제로 SQLite 메모리로 지정하며 테스트 bootstrap의 운영 DB 보호를 유지합니다.

```bash
php vendor/phpunit/phpunit/phpunit --configuration modules/_bundled/sirsoft-ecommerce/tests/catalog-translation.phpunit.xml
# 관리자 템플릿 디렉터리
npm run test:run -- src/components/composite/__tests__/catalogTranslation.test.ts src/components/composite/__tests__/CatalogTranslationPanel.test.tsx src/components/composite/__tests__/MultilingualInput.test.tsx src/components/composite/__tests__/DataGrid.test.tsx src/components/basic/__tests__/Select.test.tsx src/components/composite/__tests__/Dropdown.test.tsx
```
## 주요 변경 파일

- 서버 설정/접속: `config/translation.php`, `src/Services/Translation/TranslationProviderInterface.php`, `CompatibleTranslationProvider.php`, `StructuredTextTranslator.php`.
- 권한/작업/API: `src/Http/Requests/Admin/CatalogTranslationRequest.php`, `src/Http/Middleware/LimitCatalogTranslationBody.php`, `src/Http/Controllers/Admin/CatalogTranslationController.php`, `src/Services/Translation/CatalogTranslationService.php`, `src/Repositories/{TranslationJobRepository.php,Contracts/TranslationJobRepositoryInterface.php}`, `src/Models/CatalogTranslationJob.php`, `src/Jobs/TranslateCatalogItem.php`, 번역 종류/상태 Enum, `src/routes/api.php`, `src/Providers/EcommerceServiceProvider.php`.
- 기존 저장/공개 응답: Product/Category/ProductOption/ProductAdditionalOption/ProductAdditionalOptionValue 모델, 기존 StoreProduct/CreateCategory/UpdateCategory 요청, Product/Category/PublicProduct/PublicCategoryDetail 리소스, `src/Support/CatalogLocalizedText.php`. 기존 ProductService/CategoryService 저장 로직 파일은 수정하지 않습니다.
- migration/보관: `database/migrations/2026_10_10_000001_add_catalog_translation_support.php`, `src/Console/Commands/PruneCatalogTranslationsCommand.php`, `module.php`의 schedule 등록.
- 관리자: 상품/카테고리/SEO/언어·통화 설정 layout JSON, ko/en 상품 옵션·AI 번역 언어 파일, `templates/_bundled/sirsoft-admin_basic/src/components/composite/{CatalogTranslationPanel.tsx,catalogTranslation.ts,index.ts}`, `components.json`, template.json, 빌드 JS/타입 선언. 기존 MultilingualInput 구현은 수정하지 않고 list 표시 옵션을 재사용합니다.
- 고객 SEO 연결: `templates/_bundled/yutiv-stillform/layouts/shop/category.json`, template.json, CHANGELOG. 기존 checkout 및 본문 카테고리 이름은 변경하지 않습니다.
- 패키징/회귀: ecommerce module.json/composer.json/package.json/package-lock.json/vendor-bundle.json/vendor-bundle.zip/dist, 양쪽 CHANGELOG, 신규 PHP/컴포넌트 검사, 모듈 Vitest의 React dedupe(모듈에서 관리자 템플릿을 가져오는 기존 테스트의 이중 React 해결; 런타임 번들에 영향 없음).

최종 검사: PHP 45개/263 assertions(신규 번역 및 기존 엑셀·상품 옵션 회귀), 관리자 컴포넌트 179개(최종 추가 호환성 검사 포함), 기존 모듈 옵션 검사131개(96+35), 소스 TypeScript 검사, PHP Pint, 변경 JSON 19개 파싱, npm/Composer 의존성 잠금 보존, 두 빌드 및 공식 module vendor 무결성 검사. 테스트에서 기존 tabs 레이아웃의 중첩 button 경고와 DataGrid mock renderer 경고가 나타나며 이 작업에서는 그 기존 경로를 변경하지 않았습니다.

소스 TypeScript 검사는 테스트 파일을 제외해 실행했습니다. 기존 테스트 타입 오류를 포함한 전체 프로젝트 타입 검사는 통과로 보고하지 않습니다. 브라우저 실제 입력 폭/스크린샷 검사는 미실행입니다. 로컬 vendor 빌드 첫 명령은 활성 모듈 디렉터리 부재로 skip되어, 비어 있는 임시 빌드 자리 디렉터리를 만든 뒤 같은 공식 CLI를 실행하고 빈 디렉터리를 제거했습니다. 입력은 _bundled composer.json/lock, 빌드는 실제 staging composer install이며 해시는 수동 수정하지 않았습니다. DB 설치/업데이트는 수행하지 않았습니다.
