# 이커머스 모듈 중국어 간체(zh-CN) 언어팩 구현 보고서

- 대상 패키지: `g7-module-sirsoft-ecommerce-zh-CN` (v1.0.0)
- 대상 모듈: `sirsoft-ecommerce` (v1.2.0, vendor `sirsoft`, MIT)
- 기준 커밋: `3c8764d463ce67d9ba3bb1743a8d1333905dbe3f`
- 작성일: 2026-09-08

---

## 1. 사전 조사 결과

### 1-1. 모듈 식별자

`modules/_bundled/sirsoft-ecommerce/module.json` 에서 확인.

| 필드 | 값 |
|---|---|
| `identifier` | `sirsoft-ecommerce` |
| `vendor` | `sirsoft` |
| `version` | `1.2.0` |
| `license` | `MIT` |
| `name.ko` / `name.en` | 이커머스 / Ecommerce |

### 1-2. 기존 일본어 팩

`lang-packs/_bundled/g7-module-sirsoft-ecommerce-ja/language-pack.json`.

| 필드 | 값 |
|---|---|
| `identifier` | `g7-module-sirsoft-ecommerce-ja` |
| `version` | `1.1.3` |
| `scope` / `target_identifier` | `module` / `sirsoft-ecommerce` |
| `requires.depends_on_core_locale` | `true` |
| `g7_version` | `>=7.0.0` |
| 파일 수 | 61 |

### 1-3. GO / NO-GO 판정

**GO.** 근거:

1. 네이밍 공식 `{namespace}-{scope}-{target}-{locale}` 로 `g7-module-sirsoft-ecommerce-zh-CN` 이 유효 (`LanguagePackManifestValidator::validateIdentifierNaming()`).
2. `zh-CN` 은 매니페스트 BCP-47 패턴과 `LanguagePackBundledRegistrar` 의 locale 디렉토리 스캔 패턴(`/^[a-z]{2,3}(-[A-Z]{2})?$/`)을 모두 통과. 코어 zh-CN 팩(`g7-core-zh-CN`)이 이미 존재하므로 `depends_on_core_locale` 전제도 충족 가능.
3. ko 원본이 완비되어 있고(backend 13 + frontend 36 + seed 소스), ja 팩이 구조 참조로 존재.
4. 코어·모듈 코드 변경이 전혀 필요 없다 — 언어팩은 `lang-packs/_bundled/` 외부 패키지이며, `supported_locales`/`translatable_locales`/`locale_names` 는 활성화 시 `LanguagePackServiceProvider::refreshSupportedLocales()` 가 자동 갱신한다.

---

## 2. 파일 인벤토리

### 2-1. 원본(ko) 대비 산출물

| 구분 | ko 원문 소스 | 산출 위치 | 원본 | 산출 |
|---|---|---|---|---|
| backend | `src/lang/ko/*.php` | `backend/zh-CN/*.php` | 13 | 13 |
| frontend 엔트리 | `resources/lang/ko.json` | `frontend/zh-CN.json` | 1 | 1 |
| frontend partial | `resources/lang/partial/ko/**/*.json` | `frontend/partial/**/*.json` | 35 | 35 |
| seed | `module.php` + 3개 시더 + `module.json` | `seed/*.json` | — | 9 |
| 매니페스트 / CHANGELOG | — | `language-pack.json`, `CHANGELOG.md` | — | 2 |
| **합계** | | | | **60** |

### 2-2. ja 팩(61) 대비

`admin/mileage_deposit_settings.json` 1건만 차이. 상세는 §7.

---

## 3. 패키지 사양

| 항목 | 값 |
|---|---|
| `identifier` | `g7-module-sirsoft-ecommerce-zh-CN` |
| `namespace` / `vendor` | `g7` / `sirsoft` |
| `scope` / `target_identifier` | `module` / `sirsoft-ecommerce` |
| `locale` / `locale_name` / `locale_native_name` | `zh-CN` / `Simplified Chinese` / `简体中文` |
| `text_direction` | `ltr` |
| `version` / `license` | `1.0.0` / `MIT` |
| `g7_version` | `>=7.0.0` |
| `requires.target_version` | `null` |
| `requires.depends_on_core_locale` | `true` |
| `name` / `description` 로케일 | `ko`, `en`, `zh-CN` |

`depends_on_core_locale: true` 이므로 `g7-core-zh-CN` 이 먼저 활성이어야 한다
(`LanguagePackService::resolveCoreLocaleBlockedReason()` 이 `core_locale_missing` 으로 차단).

---

## 4. 번역 규모

| 구분 | 파일 | 리프 키 |
|---|---|---|
| backend PHP | 13 | 2,567 |
| frontend JSON | 36 | 4,651 |
| seed JSON | 9 | 258 |
| **합계** | **58** | **7,476** |

---

## 5. 정합성 검사 결과

### 5-1. 원본 대조 검사기

```
php tests/Translations/zh-CN-ecommerce-parity-check.php --verbose --style
```

```
검사 파일 60개 (PHP 13 / JSON 45), 대조 키 7218개

파일 누락 OK(0)          초과 파일 OK(0)        구조 오류 OK(0)
JSON 구문 OK(0)          키 누락 OK(0)          초과 키 OK(0)
키 순서 불일치 OK(0)     자료형 불일치 OK(0)    placeholder 불일치 OK(0)
HTML 태그 불일치 OK(0)   URL 불일치 OK(0)       빈 값 불일치 OK(0)
개행 개수 불일치 OK(0)   한글 잔존 OK(0)        일본어 가나 잔존 OK(0)
인코딩 오류 OK(0)        BOM 검출 OK(0)         $partial 경로 오류 OK(0)
ja 팩 구조 차이 OK(0)

표기 스타일 경고: 없음
RESULT: PASS — 위반 0건   (exit 0)
```

backend/frontend 는 **모듈의 ko 원본**과, seed 는 **ja 팩**과 대조한다(seed 원본이 `module.php`·시더에 분산되어 기계적 1:1 대조 대상이 아니기 때문). ja 대비 의도적 차이는 검사기 상수(`$intentionalSeedExtras`, `$intentionalSeedValueDrift`, `$intentionalJaOnlyPartials`)에 명시했다.

### 5-2. PHP 구문·구조

- `php -l` 13/13 통과 (구문 오류 0).
- 토큰 화이트리스트 검사(프로덕션 `LanguagePackPhpArrayValidator` 근사): 허용 외 토큰·식별자 0건.
- 전 파일 `return <배열 리터럴>;` 단일 구조, 리프 2,567개 전부 `string`.

### 5-3. JSON 구문

45개 JSON 전부 `json_decode` 성공, 오류 0.

### 5-4. 인코딩

60개 파일 전부 UTF-8, **BOM 0 / CRLF 0 / 비-UTF8 0**.

### 5-5. 매니페스트 규칙 재현 검사

| 규칙 | 결과 |
|---|---|
| BCP-47 `/^[a-z]{2,3}(-[A-Z][a-z]{3})?(-[A-Z]{2}\|-[0-9]{3})?(-[a-zA-Z0-9]{5,8})?$/` | OK |
| 네이밍 공식 `{ns}-{scope}-{target}-{locale}` | OK |
| Registrar locale 디렉토리 스캔 `/^[a-z]{2,3}(-[A-Z]{2})?$/` | OK |
| SemVer `1.0.0` | OK |
| 필수 필드 누락 | 0 |

### 5-6. 보안 규칙 재현 검사

| 규칙 | 결과 |
|---|---|
| 확장자 화이트리스트 (`.php` / `.json` / `.md`) | 위반 0 (실제 확장자: json, md, php) |
| PHP 위치 정규식 `#^backend/(?:zh-CN/)?[A-Za-z0-9_-]+\.php$#` | 13/13 통과 |
| 심볼릭 링크 | 0 |
| 동적 호출·외부 명령·네트워크·파일 쓰기·eval/include/require | 0 (토큰 검사로 확인) |
| 비밀정보·실제 고객정보·운영 DB 값·자격증명 | 0 |

---

## 6. 보존 항목 검증

| 대상 | 확인 |
|---|---|
| 내부 enum key/value | `enums.php` 의 `order_status`/`payment_status`/`shipping_status`/`option_status`/`refund_status` 등 **키 전부 원본 유지**, 표시 문자열만 번역 |
| HTTP 메서드 | `shipping_api_http_method` = `['GET' => 'GET', 'POST' => 'POST']` — 값까지 원본 유지 |
| 통화 코드 | `KRW`/`USD`/`JPY`/`CNY`/`EUR` 키·표시명 내 코드 보존 (`KRW（韩元）` 등) |
| 통화 접두/접미 기호 | `messages.php` 의 `currency.prefix`/`suffix` — 빈 문자열 포함 원본값 그대로 |
| 기술 식별자 | `SKU`, `PG`, `API`, `URL`, `VAT`, `HS`, `ISO 4217`, `OG`, `EMS`, `DHL`, `FedEx`, `SF Express`, `UPS` 미번역 |
| in-rule 리터럴 | `floor, round, ceil` / `asc, desc` / `text, html` / `domestic, international` / `main, detail, additional` 원문 유지 |
| placeholder | `:count` `:max` `:min` `:amount` `:fee` `:status` `{{count}}` `{count}` `{tracking_number}` `{app_name}` 등 검사기 대조 0건 불일치 |
| 숫자·날짜·금액 포맷 | 포맷 문자열 미변경 |
| 계산식 | 번역 파일에는 계산 로직이 없음(순수 배열) — 변경 0 |

---

## 7. ja 팩과의 차이 및 판단

`g7-module-sirsoft-ecommerce-ja` (v1.1.3) 가 현재 ko 원본보다 오래되어 5건의 구조 차이가 있다.
이 팩은 **ja 재번역이 아니라 ko 원본 기준**이므로, 각 차이를 개별 검증한 뒤 아래처럼 처리했다.
**ja 팩은 이번 작업에서 수정하지 않았다.**

| # | 차이 | 근거 | 처리 |
|---|---|---|---|
| 1 | `frontend/partial/admin/mileage_deposit_settings.json` 이 ja 에만 존재 | ko·en 원본 어디에도 없음, 모듈 코드 참조 0건 (ja 의 `ja.json` 에는 `$partial` 항목이 남아 있는 사문) | zh-CN 에서 **제외**. `$intentionalJaOnlyPartials` 에 명시 |
| 2 | `seed/permissions.json` — ja 에 `dashboard`/`mileage`/`user-currency`/`user-shipping-country` 없음 | `module.php::getPermissions()` 가 4개 카테고리를 선언 | ko 기준 **포함** (총 20개 카테고리). `$intentionalSeedExtras` 에 18개 dot-path 명시 |
| 3 | `seed/menus.json` — ja 에 `sirsoft-ecommerce-mileage-transactions` 없음 | `module.php::getAdminMenus()` 가 선언 | ko 기준 **포함** (총 12개) |
| 4 | `seed/notifications.json` — ja 에 `order_pending_deposit`/`mileage_expiring_soon`/`order_delivered` 없음 | `module.php::getNotificationDefinitions()` 가 10종 선언 | ko 기준 **포함** (총 10종) |
| 5 | `seed/notifications.json :: order_confirmed.templates.mail.body` — ja 본문에 수취인·배송국가·배송지 3행 없음 | `module.php::orderConfirmedDefinition()` 이 `{shipping_recipient_name}`/`{shipping_country_name}`/`{shipping_address}` 를 추가 | ko 기준. placeholder·HTML 태그 대조에서만 제외하도록 `$intentionalSeedValueDrift` 에 명시 |

각 예외는 검사기 상수에 주석과 함께 남겼으므로, ja 팩이 나중에 동기화되면 이 상수를 지우는 것으로 되돌릴 수 있다.

---

## 8. 번역 판단이 필요했던 항목

| 원문 | 채택 표기 | 판단 근거 |
|---|---|---|
| 무통장입금 | `银行汇款` (결제수단 설명 `直接汇款至指定账户`) | 한국 고유 제도. 중국의 특정 결제수단명으로 치환하지 않고 "지정 계좌로 직접 송금" 이라는 동작을 서술 |
| 현금영수증 | `现金收据` (`소득공제용`→`用于个人所得税抵扣`, `지출증빙용`→`用于支出凭证`) | 중국 `发票` 제도와 동일시하지 않음. 용도 라벨은 서술형으로 풀어 씀 |
| 세금계산서 | `税务发票` | 용어집 지정 |
| `원` (금액 단위) | `韩元` | ko 원문이 원화 기준임을 유지. 삭제하거나 `元`(CNY)로 바꾸면 다중통화 상점에서 오해를 낳는다. ja 팩은 `円` 으로 현지화했으나 채택하지 않았다 |
| 은행명 (국민/신한/우리/하나/IBK기업/NH농협/우체국) | `国民银行`/`新韩银行`/`友利银行`/`韩亚银行`/`IBK企业银行`/`NH农协银行`/`邮政局` | 표준 중문 외래 표기. 은행 코드(`004` 등)는 미변경 |
| 택배사 (CJ대한통운/한진/롯데/로젠/야마토/사가와) | `CJ大韩通运`/`韩进快递`/`乐天快递`/`Logen 快递`/`大和运输`/`佐川急便` | 표준 중문 표기. 코드(`cj`, `hanjin` …)는 미변경 |
| 네이버페이 / 카카오페이 / 토스페이 | `Naver Pay` / `Kakao Pay` / `Toss Pay` | 고유 브랜드명 — 음역하지 않음 |
| 다음 우편번호 플러그인 | `Daum 邮政编码插件` | `다음` 은 지시어가 아니라 브랜드 Daum. en 원본 `"Daum Postcode plugin"` 및 플러그인 `sirsoft-daum_postcode` 로 확인 |
| 포인트 / 마일리지 | 둘 다 `积分` | 이 모듈은 두 개념을 구분해 쓰지 않는다(같은 잔액을 가리킴). 하나로 통일해야 화면이 일관된다 |
| 도서산간 | `偏远及岛屿地区` | 한국 배송비 제도 용어. 의미(도서+산간)를 풀어 씀 |
| 상품정보제공고시 | `商品信息告示` | 한국 전자상거래법상 고시. 중국 제도명으로 바꾸지 않고 명칭을 옮김 |
| 언어 표시명 (`한국어`/`English`/`日本語`/`中文`) | `韩语`/`英语`/`日语`/`中文` | ko 원본은 각 언어를 자기 언어로 적는 방식이나, 한글을 남길 수 없고 혼합 표기는 일관성이 깨진다. zh-CN 사용자 기준 중문 표기로 통일 |
| 단순 변심 | `单纯改变心意` | 취소/반품 사유. `无理由退换` 는 중국 반품제도(7일 무리유)를 연상시켜 의미를 확대하므로 쓰지 않음 |
| `을(를) 선택해주세요` (옵션명 뒤 접미) | `：请选择` | 한국어 조사 결합형 접미사. 중국어에는 대응 조사가 없어 구분자 + 지시문 형태로 옮김 |
| 통신판매업 신고번호 예시 `제 2024-서울강남-12345 호` | `第 2024-首尔江南-12345 号` | 예시 문자열의 지명만 중문 표기로 전환 |
| 국세청 의무발행 업종 안내 | `韩国国税厅强制开具行业指南` | 한국 국세청임을 명시해 오해를 막음 |

---

## 9. 잔존 문자 검사

| 항목 | 결과 |
|---|---|
| 한글(음절/자모) 잔존 | **0건** (`[\x{AC00}-\x{D7A3}\x{1100}-\x{11FF}\x{3130}-\x{318F}]`) |
| 일본어 가나 잔존 | **0건** (`[\x{3040}-\x{309F}\x{30A0}-\x{30FF}]`) |

---

## 10. frontend build 필요 여부

**불필요.** 근거(코드·빌드 구조):

1. `modules/_bundled/sirsoft-ecommerce/package.json` 의 `build` 는 `vite build` 뿐이다.
2. `vite.config.ts` 는 **라이브러리 모드**로 `resources/js/index.ts` 하나만 진입점으로 잡고 IIFE 포맷으로 `dist/js/module.iife.js` + `dist/css/module.css` 만 산출한다. `resources/lang/**` 는 빌드 그래프에 없다.
3. 프로덕션 TS/Vue 코드 중 `resources/lang/**` 를 import 하는 파일은 없다(참조는 `__tests__/` 뿐).
4. 현재 `dist/js/module.iife.js` 에 남은 한국어 문자열은 1개이며, i18n 함수가 없을 때만 쓰이는 하드코딩 폴백이다:
   `t?.('sirsoft-ecommerce.admin.shipping_policy.form.range_error_min_one') ?? '구간별 배송비 정책은 구간을 1개 이상 등록해야 합니다.'`
   (`resources/js/handlers/shippingPolicyFormHandlers.ts:790-791`). 언어팩은 이 키를 런타임에 공급하므로 폴백은 사용되지 않는다.
5. 언어팩 frontend JSON 은 `MergeFrontendLanguage` 가 **런타임에 디스크에서 읽어** 병합한다(`File::get()`), 서빙 경로는 `routes/api.php` 의 `templates/{identifier}/lang/{locale}.json`.

따라서 이 언어팩 배포에 `npm install` / `npm run build` 는 필요 없다.

---

## 11. 이번 단계에서 검증하지 못한 항목

| 항목 | 사유 | 서버에서 실행할 명령 |
|---|---|---|
| PHPUnit (`BundledSimplifiedChineseEcommercePackTest`) | 로컬 PHP 7.4.22 이며 `composer.json` 은 `^8.2` 요구. `vendor/` 디렉토리는 존재하나 **비어 있음**(autoload.php 없음) → Laravel 부팅 불가 | `php artisan test --filter=BundledSimplifiedChineseEcommercePackTest` |
| 프로덕션 `LanguagePackManifestValidator` 실호출 | 위와 동일 (규칙만 재현 검사) | 위 PHPUnit 에 포함 |
| 프로덕션 `LanguagePackPhpArrayValidator` 실호출 | 위와 동일. 해당 클래스는 PHP 8 의 `catch (ParseError)` 를 사용해 7.4 에서 로드 불가 (토큰 화이트리스트로 근사 검증) | 위 PHPUnit 에 포함 |
| 실제 설치·활성화 후 화면 확인 | DB 변경 금지 · 서버 접속 금지 범위 | `php artisan language-pack:install g7-module-sirsoft-ecommerce-zh-CN --source=bundled` 후 관리자 화면 확인 |

로컬에서 실행 가능한 정적 검사(파서·인코딩·키·placeholder·보안 규칙 재현)는 전부 통과했으나,
**위 4건은 성공으로 간주하지 않는다.**

---

## 12. 변경 파일 목록

### 신규

- `lang-packs/_bundled/g7-module-sirsoft-ecommerce-zh-CN/` (60개 파일)
- `tests/Translations/zh-CN-ecommerce-parity-check.php`
- `tests/Unit/Services/LanguagePack/BundledSimplifiedChineseEcommercePackTest.php`
- `docs/reports/zh-CN-ecommerce-language-pack-report.md` (이 문서)

### 수정 (허용 범위 내)

- `README.md` — 번들 언어팩 인벤토리에 1행 추가
- `README.ko.md` — 동일
- `CHANGELOG.md` — `[Unreleased] > Added` 에 1행 추가
- `docs/extension/language-packs.md` — zh-CN 섹션에 이커머스 팩 항목 추가

### 변경하지 않은 것

코어, 모듈(`sirsoft-ecommerce` 포함), 플러그인, 템플릿, 기존 언어팩(ja 포함), 기존 zh-CN 코어·게시판 팩과
그 보고서, DB 스키마·마이그레이션, 라우트, 권한 키, 설정 키, `config/app.php`, `.env`.

---

## 13. 후속 과제

1. **ja 팩 동기화** — §7 의 5건은 `g7-module-sirsoft-ecommerce-ja` 가 낡아 생긴 차이다. ja 팩을 현재 ko 원본에 맞추면 검사기의 예외 상수 3종을 제거할 수 있다.
2. **ko/en 변경 시 zh-CN 동기화** — 이커머스 모듈의 다국어 키를 바꿀 때마다 이 팩의 키 셋도 갱신하고 `zh-CN-ecommerce-parity-check.php` 로 확인해야 한다. 누락되면 중국어 화면에서 오류 없이 ko/en 폴백이 노출된다.
3. **페이지 모듈·플러그인·템플릿 zh-CN 팩** — 아직 없음.

---

## 14. 보고서 클립보드 복사

```powershell
Get-Content -Raw -Encoding UTF8 "docs\reports\zh-CN-ecommerce-language-pack-report.md" | Set-Clipboard
```
