# 사이트 설명 다국어 지원 · 환영 카드 하드코딩 제거 보고서

- 작성일: 2026-09-08
- 운영 배포 기준 커밋: `cb46fa156ce90cc74d1b40de6a1454a61f3af0c4` (branch `main`, 작업 시작 시 tree clean — 3항목 모두 일치 확인)
- commit·push 미수행

---

## 1. 원인

증상은 두 가지이고 원인도 서로 다르다.

### 1-1. 사이트 소개가 zh-CN 화면에서 한국어로 남는다

`general.site_description` 은 **로케일 개념이 없는 단일 string** 설정이었다.

```
config/settings/defaults.json   "site_description": ""            ← 단일 string
        ↓ SettingsService::getFrontendSettings() → castValue(type: string)
_global.settings.general.site_description = "<저장된 한국어 원문>"   ← 로케일 무관
        ↓
_welcome_card.json  {{_global.settings?.general?.site_description ?? '$t:home.welcome_description'}}
```

메뉴·언어팩은 로케일을 타지만, 이 값만은 **한 벌뿐**이라 어느 언어 화면에서도 같은 문자열이 나갔다. 예외도 로그도 없다.

부수 결함 두 개가 같은 지점에 얽혀 있었다.

- **폴백이 사실상 죽어 있었다.** `castValue()` 는 값이 없으면 `''` 를 돌려주는데 `??` 는 `null` 에만 폴백한다. 즉 설명이 비어 있어도 `$t:home.welcome_description` 은 **한 번도 쓰이지 않고** 빈 문단이 남았다.
- **배열을 넣으면 화면이 깨진다.** `castValue()` 의 `(string) $value` 캐스팅은 로케일 맵을 만나면 `"Array"` 를 렌더한다. 예외가 아니라 렌더 결과라 로그에도 남지 않는다.

### 1-2. "Welcome" 배지

`_welcome_card.json` 에 영어 리터럴로 박혀 있었다.

```json
{ "type": "basic", "name": "Span", ..., "text": "Welcome" }
```

같은 카드의 다른 문구는 전부 `$t:` 를 쓰는데 이 하나만 예외였다.

---

## 2. 구현 방식과 fallback 정책

### 2-1. 기존 계약 재사용

새 체계를 만들지 않고 저장소에 이미 있는 세 가지를 그대로 썼다.

| 재사용 대상 | 용도 |
| --- | --- |
| `app/Helpers/locale_helpers.php` (`localized_label` 계열) | 로케일 값 해석 헬퍼가 모여 있는 파일 — 여기에 설정 전용 두 함수를 형제로 추가 |
| `App\Rules\TranslatableField` | 역할·메뉴 폼이 쓰는 다국어 검증 규칙 (로케일별 `nullable\|string\|max` + 지원 목록 밖 키 거부) |
| `MultilingualInput` (composite) | 관리자 다국어 입력 컴포넌트 (언어 탭). 이미 빌드 산출물에 포함되어 있음 |

지원 로케일은 하드코딩하지 않는다. `config('app.supported_locales')` / `app.translatable_locales` 는 부팅 시 `LanguagePackServiceProvider::refreshSupportedLocales()` 가 **활성 코어 언어팩 기준으로 덮어쓰는 동적 값**이며, 프론트 컴포넌트도 `G7Core.locale.supported()` 로 같은 출처를 본다. 언어팩을 켜고 끄면 입력 탭이 따라 늘고 준다.

### 2-2. 스키마가 정책을 선언한다

`config/settings/defaults.json` 의 `frontend_schema` 에 `localized` 플래그를 추가했다.

```json
"site_description": { "type": "string", "sensitive": false, "localized": "strict" }
```

| 정책 | 해석 순서 | 적용 |
| --- | --- | --- |
| `strict` | 요청 로케일 값이 없으면 **빈 문자열** (폴백 없음) | `site_description` — 여기서 ko 로 폴백하면 고치려던 증상이 그대로 재현된다. **현재 유일한 선언 필드** |
| `fallback` | 요청 로케일 → `app.fallback_locale` → 첫 비어 있지 않은 값 | 헬퍼가 지원하는 다른 모드. 현재 이 값을 선언한 코어 필드는 없다 |

**이 플래그는 관리자 입력 컴포넌트와 한 쌍이다.** 표시 경로만 바꾸는 것이 아니라 `getAllSettings()` 가 같은 선언을 보고 관리자 조회값을 로케일 맵으로 정규화하므로, 해당 입력이 `MultilingualInput` 이 아닌 필드에 붙이면 일반 `Input` 이 객체를 받아 `[object Object]` 로 표시된다. `general.site_name` 이 그 경우라 플래그를 선언하지 않는다 (§12 회귀 이력 참조).

### 2-3. fallback 정책 (확정)

**표시(사용자 화면·Footer·SEO 봇 렌더)**

```
1. 값이 로케일 맵이고 map[현재 로케일] 이 비어 있지 않다  → 그 값
2. 값이 단일 string 이고 현재 로케일 == app.fallback_locale → 그 string   (레거시 호환)
3. 그 외                                                  → ""          (폴백 없음)
        ↓
4. 레이아웃: "" || '$t:home.welcome_description'           → 번역키 기본 문구
```

`??` 를 `||` 로 바꾼 이유가 4번이다. 서버가 내려주는 "번역 없음"은 `null` 이 아니라 빈 문자열이라 `??` 로는 폴백이 걸리지 않는다.

**관리자 조회(편집용)**

```
레거시 string  → { "<app.fallback_locale>": "원문" }   ← 한 칸만. 다른 로케일로 복제하지 않는다
로케일 맵      → 그대로 통과 (운영자 입력 보존)
빈 값 / null   → {}
```

전 로케일 복제를 하지 않는 것이 계약의 핵심이다. 복제하면 한국어 원문이 중국어 자리에 **데이터로 굳어** 증상이 영구화된다.

### 2-4. 지나는 지점

| 지점 | 파일 | 변경 |
| --- | --- | --- |
| 검증 | `SaveSettingsRequest::rules()` | `is_array()` 로 분기 — 배열이면 `TranslatableField(500, strictLocales: true)`, string 이면 기존 `nullable\|string\|max:500` |
| 저장 | (변경 없음) | `saveSettings()` 의 `array_merge` 가 값을 그대로 보존 |
| 관리자 재로드 | `SettingsService::getAllSettings()` | `localized` 선언 필드는 `localized_setting_map()` 으로 편집용 맵 정규화 |
| 프론트 노출 | `SettingsService::castValue()` | `localized` 선언 필드는 `localized_setting_value()` 로 현재 로케일 문자열 축약 (`(string)` 캐스팅 이전에 早期 반환) |
| 화면 | `_welcome_card.json` | `??` → `||`, `"Welcome"` → `$t:home.welcome_badge` |

`_global.settings` 를 만드는 곳이 `getFrontendSettings()` 한 곳이므로 **환영 카드·Footer·SEO 봇 렌더가 자동으로 같은 정책**을 받는다. 레이아웃에 로케일 선택 로직을 넣지 않았다.

---

## 3. 생성·수정·삭제 파일 수

| 구분 | 수 |
| --- | ---: |
| 신규 | 3 (테스트 2 + 보고서 1) |
| 수정 | 34 |
| 삭제 | 0 |

---

## 4. 전체 수정 파일 목록

### 백엔드 (3)

| 파일 | 변경 |
| --- | --- |
| `app/Helpers/locale_helpers.php` | `localized_setting_base_locale()` · `localized_setting_map()` · `localized_setting_value()` 추가 |
| `app/Services/SettingsService.php` | `castValue()` 에 로케일 축약 분기, `getAllSettings()` 에 편집용 맵 정규화 분기 |
| `app/Http/Requests/Settings/SaveSettingsRequest.php` | `general.site_description` 을 string / 로케일 맵 양쪽 허용으로 분기 |

### 설정 (1)

| 파일 | 변경 |
| --- | --- |
| `config/settings/defaults.json` | `site_description: localized=strict`, `frontend_schema._localized` 설명 추가. `site_name` 은 기존 string 계약 유지(플래그 없음). **기본값은 `""` 그대로 — 운영 사이트 문구 하드코딩 없음** |

### 관리자 템플릿 sirsoft-admin_basic (7)

| 파일 | 변경 |
| --- | --- |
| `layouts/partials/admin_settings/_tab_general.json` | Textarea → `MultilingualInput`(언어 탭) + 오류/힌트 표시 |
| `lang/partial/ko/admin.json`, `lang/partial/en/admin.json` | `settings.general.site_description_hint` 추가 |
| `lang/partial/ko/common.json`, `lang/partial/en/common.json` | `language_ko/en/ja/zh-CN` 추가 (언어 탭 라벨) |
| `template.json` | 1.0.7 → **1.0.8** |
| `CHANGELOG.md` | 1.0.8 항목 |

### 사용자 템플릿 sirsoft-basic (5)

| 파일 | 변경 |
| --- | --- |
| `layouts/partials/home/_welcome_card.json` | `"Welcome"` → `$t:home.welcome_badge`, 설명 폴백 `??` → `||` |
| `lang/partial/ko/home.json`, `lang/partial/en/home.json` | `welcome_badge` 추가 |
| `template.json` | 1.1.2 → **1.1.3** |
| `CHANGELOG.md` | 1.1.3 항목 |

### 언어팩 (12)

| 팩 | 버전 | 변경 |
| --- | --- | --- |
| `g7-template-sirsoft-basic-ja` | 1.1.2 → **1.1.3** | `home.welcome_badge` (`ようこそ`) |
| `g7-template-sirsoft-basic-zh-CN` | 1.0.0 → **1.0.1** | `home.welcome_badge` (`欢迎`) |
| `g7-template-sirsoft-admin_basic-ja` | 1.0.7 → **1.0.8** | `common.language_*`, `admin.settings.general.site_description_hint` |
| `g7-template-sirsoft-admin_basic-zh-CN` | 1.0.0 → **1.0.1** | 동일 |

각 팩마다 `language-pack.json` · `CHANGELOG.md` · 해당 `frontend/partial/*.json`.

### 검증 (4)

| 파일 | 변경 |
| --- | --- |
| `tests/Feature/Settings/SiteDescriptionLocalizationTest.php` | **신규** — 저장·검증·해석 계약 + `site_name` 회귀 방지 17케이스 |
| `tests/Unit/Layouts/WelcomeCardLocalizationTest.php` | **신규** — 레이아웃·4개 로케일 자원 동기 7케이스 |
| `tests/Translations/lib/zh-CN-template-parity-lib.php` | `version === '1.0.0'` 상수 → `manifest version == CHANGELOG 최신 항목` 불변식 |
| `tests/Unit/Services/LanguagePack/BundledSimplifiedChineseTemplatePacksTest.php` | 동일 |

> 이 두 곳은 팩을 한 번이라도 갱신하면 반드시 틀리는 상수였다. 실제로 지켜야 하는 계약은 "번들을 고쳤으면 버전과 이력을 함께 올린다" 이고, `language-pack:update` 의 비강제 경로가 버전 비교로 갱신 여부를 판단하므로 이 불변식이 운영 설치본이 조용히 낡는 것을 막는다.

### 문서 (2)

| 파일 | 변경 |
| --- | --- |
| `docs/backend/admin-settings-access.md` | "새 admin 환경설정 키 추가 시 점검" 6번 — `localized` 플래그 규칙 |
| `CHANGELOG.md` | `[Unreleased]` Added 1건 + Fixed 3건 |

---

## 5. 데이터 호환 및 migration 여부

**migration 불필요. 코드 배포만으로 기존 값이 그대로 동작한다.**

| 기존 저장 형태 | 배포 후 동작 |
| --- | --- |
| `"site_description": "<한국어 원문>"` | ko 화면: 그대로 표시 / 관리자: 한국어 탭에 표시 / en·ja·zh-CN 화면: 템플릿 기본 문구 |
| `"site_description": ""` | 전 로케일: 템플릿 기본 문구 (기존에는 빈 문단이 남았음 — 개선) |
| `"site_description": {...}` | 각 로케일 값 표시 |

- 저장된 문자열은 **읽지도 지우지도 않는다.** 관리자가 저장하기 전까지 파일 내용은 그대로다.
- 관리자가 한 번 저장하면 로케일 맵으로 바뀌며, 기존 원문은 기준 로케일(`app.fallback_locale`, 운영 기본 `ko`) 칸에 그대로 들어간다.
- 롤백해도 데이터가 깨지지 않는다 — 구버전 코드는 로케일 맵을 만나면 `castValue` 가 `"Array"` 를 렌더하므로, **롤백 시에는 설정을 단일 문자열로 되돌려야 한다** (18항 롤백 절차에 포함).

**주의해야 할 행동 변화 하나** — 기존에 en·ja 화면에서 보이던 한국어 설명이 배포 직후 템플릿 기본 문구로 바뀐다. 이는 이번 수정의 의도(외국어 화면에 한국어를 내보내지 않는다)지만, 운영상 공백이 생기지 않도록 **배포 직후 19항의 4개 로케일 값을 입력**하는 것을 권장한다.

특정 사이트(YUTIV) 문구는 저장소 기본값·migration·테스트 fixture 어디에도 넣지 않았다. 19항의 운영자 입력 절차로만 제시한다.

---

## 6. 관리자 UI 동작

환경설정 > 일반 > 사이트 설명

- 기존 단일 Textarea → `MultilingualInput`(`layout: "tabs"`, `inputType: "textarea"`, `rows: 3`, `maxLength: 500`)
- 언어 탭은 활성 언어팩에서 동적으로 산출된다 (`G7Core.locale.supported()`). 언어팩을 추가·제거하면 탭이 따라 늘고 준다 — 목록을 코드에 적어 두지 않았다.
- 탭 라벨은 `common.language_{code}` 번역키를 쓴다. 컴포넌트 내장 폴백 맵에 `zh-CN` 이 없어 그대로 두면 탭이 **`ZH-CN`** 으로 표시되므로, 4개 로케일 자원에 라벨을 채웠다 (한국어 / 영어 / 일본어 / 중국어 간체).
- 기존 폼 규칙 유지: `disabled="{{_computed.isReadOnly}}"` (읽기 전용), `_local.errors?.['general.site_description']` (오류 표시), placeholder 동일.
- 안내 문구(`site_description_hint`) 추가: "언어별로 입력합니다. 비워 둔 언어는 해당 화면에서 템플릿 기본 문구로 표시됩니다."
- 기존 단일 문자열이 로드되면 기준 언어 탭에 값이 채워진 채 열리고, 저장해도 유실되지 않는다.

새 UI 체계는 만들지 않았다.

---

## 7. ko / en / ja / zh-CN 실제 선택 결과

로케일 맵 `{ko, en, ja, zh-CN}` 이 모두 채워진 경우 (하네스 검증 완료):

| 화면 로케일 | `_global.settings.general.site_description` | 환영 카드 표시 |
| --- | --- | --- |
| ko | `map['ko']` | 저장된 한국어 설명 |
| en | `map['en']` | 저장된 영어 설명 |
| ja | `map['ja']` | 저장된 일본어 설명 |
| zh-CN | `map['zh-CN']` | 저장된 중국어 간체 설명 |

일부만 채워진 경우 (`{ko: "한국어 설명", zh-CN: ""}`):

| 화면 로케일 | 전달값 | 환영 카드 표시 |
| --- | --- | --- |
| ko | `"한국어 설명"` | 한국어 설명 |
| zh-CN | `""` (빈 값 — ko 로 폴백하지 않음) | `$t:home.welcome_description` → `在共同成长的社区里分享各种话题吧。` |
| ja | `""` (키 없음) | `$t:home.welcome_description` → `一緒に成長するコミュニティでさまざまなお話を共有してください。` |

레거시 단일 string 인 경우:

| 화면 로케일 | 전달값 |
| --- | --- |
| ko (= `app.fallback_locale`) | 저장된 원문 |
| en · ja · zh-CN | `""` → 각 언어의 `home.welcome_description` |

`site_name` 은 이번 변경 대상이 아니다 — `localized` 플래그를 선언하지 않으므로 관리자 조회·프론트 노출 모두 기존 string 계약 그대로다. 다국어 배열이 저장돼 있을 경우의 방어는 종전처럼 `SettingsServiceProvider::localizeSettingValue()`(`config('app.name')`)와 `LocalizesSeoValues::resolveLocalizedValue()`(og:site_name)가 각자 담당한다.

---

## 8. Welcome 번역 결과

`home.welcome_badge`

| 로케일 | 값 | 공급 |
| --- | --- | --- |
| ko | 환영합니다 | `templates/_bundled/sirsoft-basic/lang/partial/ko/home.json` |
| en | Welcome | `.../lang/partial/en/home.json` |
| ja | ようこそ | `g7-template-sirsoft-basic-ja` 1.1.3 |
| zh-CN | 欢迎 | `g7-template-sirsoft-basic-zh-CN` 1.0.1 |

키 이름·위치 근거: 같은 환영 카드 묶음의 `welcome_suffix` · `welcome_description` 과 나란히 `home` 최상위에 두었다. 이미 있는 `home.welcome.title`(`환영합니다!` / `Welcome!`)은 느낌표가 붙은 별개 문구라 재사용하지 않았다. 4개 소스의 **키 순서**까지 동일하게 맞췄다 (zh-CN 템플릿 팩 parity 검사기가 ko 원본 기준 키 순서를 대조한다).

---

## 9. 테스트별 실제 실행 결과

### 로컬 환경 제약

로컬 PHP는 **7.4.22**, `composer.json` 은 `^8.2` 요구, `vendor/autoload.php` **없음**. 따라서 `php artisan test` / `phpunit` / `pint` / `phpstan` 을 **실행할 수 없었다.** 의존성 설치는 하지 않았다.

### 실제로 실행한 것

| # | 검사 | 명령 | 결과 |
| --- | --- | --- | --- |
| 1 | zh-CN 템플릿 팩 정합성 | `php tests/Translations/zh-CN-templates-parity-check.php --verbose --style` | **PASS** (2팩 / 35파일 / 6,383키 / 위반 0) |
| 2 | zh-CN 코어 팩 | `php tests/Translations/zh-CN-core-parity-check.php` | **PASS** |
| 3 | zh-CN 게시판 | `zh-CN-board-parity-check.php` | **PASS** |
| 4 | zh-CN 이커머스 | `zh-CN-ecommerce-parity-check.php` | **PASS** |
| 5 | zh-CN 페이지 | `zh-CN-page-parity-check.php` | **PASS** |
| 6 | zh-CN 플러그인 11종 | `zh-CN-plugins-parity-check.php` | **PASS** |
| 7 | PHP 구문 (7종) | PHP8→7.4 정규화 후 `php -l` | **전부 OK** |
| 8 | 변경 JSON 전수 파싱 | `json_decode` | **전부 OK** |
| 9 | BOM / CRLF / UTF-8 | 변경 파일 전수 | **위반 0** |
| 10 | 오프라인 재현 하네스 | 저장소 실제 코드 로드 후 89단언 | **ALL PASS (89)** |
| 11 | 템플릿 팩 PHPUnit 재현 | 34단언 × 2팩 | **ALL PASS** |
| 12 | `git diff --check` | 공백 오류 | **0건** |

### 10번 하네스가 검증한 것 (G-1 ~ G-13)

재구현이 아니라 **저장소의 실제 코드**(`app/Helpers/locale_helpers.php`, `app/Rules/TranslatableField.php`)를 문법 정규화만 거쳐 include 해 실행했다. `getAllSettings()` / `castValue()` 의 `localized` 분기는 실제 스키마(`defaults.json`) + 실제 헬퍼로 같은 조건을 재현했다.

| 요구 | 검증 내용 | 결과 |
| --- | --- | --- |
| G-1 | 기존 string 읽기 | PASS |
| G-2 | 기존 string 이 기준 로케일 한 칸에만 보존 · 타 로케일 미복제 | PASS |
| G-3 | 로케일 맵 통과 보존 | PASS |
| G-4 | 로케일별 max:500 (500 통과 / 501 거부) | PASS |
| G-5 | 지원 목록 밖 로케일 키 거부 · 중첩 구조 거부 | PASS |
| G-6 | ko/en/ja/zh-CN 각 로케일이 자기 값 선택 | PASS |
| G-7 | 요청 로케일 값이 비면 폴백 없이 빈 문자열 | PASS |
| G-8 | 모든 (로케일 × 입력 × 정책) 조합에서 반환값이 항상 string, `"Array"` 아님 | PASS |
| G-9 | `site_name` 문자열 계약 회귀 없음(관리자 조회·프론트 노출 양쪽) · defaults 기본값 회귀 없음 | PASS |
| G-10 | `"Welcome"` 제거 · 4개 로케일 `welcome_badge` 존재 및 각 언어 문자 체계 확인 | PASS |
| G-11 | 기존 zh-CN parity 6종 | PASS (표의 1~6번) |
| G-12 | 템플릿 ↔ 번들/언어팩 키 집합·순서 동기 | PASS |
| G-13 | JSON 구문 · placeholder · key parity · UTF-8/BOM/LF | PASS |

### 실행하지 못한 것 (통과로 보고하지 않음)

| 명령 | 이유 |
| --- | --- |
| `php artisan test --filter=SiteDescriptionLocalizationTest` | vendor 없음 / PHP 7.4. **HTTP 왕복·컨테이너 결선(스키마 플래그 → `castValue`)은 서버에서 확인해야 한다** |
| `php artisan test --filter=WelcomeCardLocalizationTest` | 동일 (단, 단언 내용 전부를 10번 하네스가 파일 단위로 재현함) |
| `php artisan test --filter=BundledSimplifiedChineseTemplatePacksTest` | 동일 (11번 하네스가 34단언 재현) |
| `./vendor/bin/pint --test`, `./vendor/bin/phpstan analyse` | vendor 없음 |

새로 추가한 두 테스트는 **아직 한 번도 실행되지 않았다.** 18항의 배포 검증 단계에서 반드시 돌려야 한다.

---

## 10. frontend build 필요 여부와 근거

**불필요.** 근거:

1. **JS/TS/CSS 변경 0건.** `git status` 에 `.ts` / `.tsx` / `.js` / `.css` 가 하나도 없다.
2. **`MultilingualInput` 은 이미 빌드 산출물에 있다.** `templates/_bundled/sirsoft-admin_basic/dist/js/components.iife.js` 안에 컴포넌트와 `common.language_` 키 조회 코드가 이미 컴파일되어 있다 (확인: `grep -o "common.language_" dist/js/components.iife.js` → 매치).
3. **레이아웃 JSON 은 번들에 인라인되지 않는다.** 런타임에 DB → API 로 내려온다.
4. **언어 자원 JSON 도 런타임 병합이다.** `app/Listeners/LanguagePack/MergeFrontendLanguage.php` 가 디스크에서 읽어 합친다.
5. **`_global.settings` 는 서버가 만든다.** `UserTemplateComposer` / `TemplateComposer` 가 `SettingsService::getFrontendSettings()` 결과를 뷰에 주입한다.

`resources/js/core/TemplateApp.ts` 의 `GeneralSettings.site_description: string` 타입도 **건드리지 않았다** — 서버가 항상 문자열을 내려주도록 설계했으므로(=`null` 을 쓰지 않았으므로) 타입이 여전히 정확하다. 이것이 `??` 대신 `||` 를 택한 이유 중 하나이기도 하다. 타입만 바꿔도 `public/build` 가 git 추적 대상(6파일)이라 빌드·재커밋이 딸려 온다.

`package.json` · `composer.json` · lock 파일 모두 무변경.

---

## 11. 기존 zh-CN 언어팩 parity 회귀 결과

| 검사기 | 결과 |
| --- | --- |
| `zh-CN-core-parity-check.php` | PASS |
| `zh-CN-board-parity-check.php` | PASS |
| `zh-CN-ecommerce-parity-check.php` | PASS |
| `zh-CN-page-parity-check.php` | PASS |
| `zh-CN-plugins-parity-check.php` | PASS |
| `zh-CN-templates-parity-check.php` | PASS — 2팩 / 35파일 / **6,383키** (변경 전 6,377키, +6) |

증가한 6키: `home.welcome_badge` 1 + `common.language_*` 4 + `admin.settings.general.site_description_hint` 1.

스타일 경고 11건은 전부 **변경 전과 동일한 기존 항목**(ja 팩 키 순서 드리프트, 실패 아님)이며 이번 작업으로 늘거나 줄지 않았다.

ja 팩과 템플릿 ko 원본의 키 집합도 대조했다 — 이번에 손댄 `home.json` · `common.json` · `admin.json` 전부 누락 0 / 잉여 0.

---

## 12. 예상하지 못한 변경

**허용 범위 밖 변경 없음.** 메일/SES · DB 접속정보 · `.env` · 사용자/주문/결제 데이터 · 의존성 파일 모두 무변경.

### 12-1. 이 작업이 만든 회귀 (후속 커밋에서 수정)

최초 구현에서 `general.site_name` 에도 `localized: "fallback"` 을 붙였다. `castValue()` 의 배열 → `"Array"` 위험만 보고 판단했는데, **같은 플래그를 `getAllSettings()` 도 읽어 관리자 조회값을 로케일 맵으로 바꾼다는 점을 놓쳤다.** 사이트 이름의 관리자 입력은 `MultilingualInput` 이 아니라 단일 `Input` 이라, 환경설정 > 일반의 사이트 이름 입력란에 `[object Object]` 가 표시됐다.

운영 화면 검증에서 발견됐고 저장 전이라 데이터 훼손은 없었다. 조치:

- `defaults.json` 의 `site_name` 에서 `localized` 제거 (기존 string 계약 복원). `site_description` 의 `strict` 는 유지.
- 플래그가 **표시 경로만이 아니라 관리자 조회 형태까지 바꾼다**는 점을 `castValue()` 주석 · `defaults.json._localized` · `docs/backend/admin-settings-access.md` 6번에 명시.
- `site_name` 로케일 맵 폴백 테스트를 제거하고, 문자열 유지 회귀 테스트 3종으로 교체.

교훈은 주석으로 남겼다 — `localized` 플래그 추가는 관리자 입력 컴포넌트 교체와 **한 쌍**이다.

### 12-2. 발견했지만 고치지 않고 그대로 둔 것 2건

1. **`tests/Translations/lib/zh-CN-template-parity-lib.php` 의 `version === '1.0.0'` 상수** — 이건 고쳤다. 팩 버전을 올리는 순간 반드시 실패하는 단언이라, 이번 변경을 막고 있었다. 상수를 지운 게 아니라 "manifest version == CHANGELOG 최신 항목" 이라는 더 강한 불변식으로 **교체**했다. 같은 이유로 대응 PHPUnit 단언도 함께 바꿨다.
2. **`templates/_bundled/sirsoft-admin_basic/lang/partial/en/admin.json` 의 기존 키 누락 2건** (`dashboard.activities.user_registered`, `dashboard.refreshed`) — HEAD 시점에도 동일하게 없었음을 `git show HEAD:...` 로 대조 확인했다. 이번 작업과 무관한 선재 결함이라 **손대지 않았다.**

`git diff --check` 0건. 삭제 파일 0건.

---

## 13. git diff --check

```
$ git diff --check
(출력 없음)
```

---

## 14. git status --short 전체

```
 M CHANGELOG.md
 M app/Helpers/locale_helpers.php
 M app/Http/Requests/Settings/SaveSettingsRequest.php
 M app/Services/SettingsService.php
 M config/settings/defaults.json
 M docs/backend/admin-settings-access.md
 M lang-packs/_bundled/g7-template-sirsoft-admin_basic-ja/CHANGELOG.md
 M lang-packs/_bundled/g7-template-sirsoft-admin_basic-ja/frontend/partial/admin.json
 M lang-packs/_bundled/g7-template-sirsoft-admin_basic-ja/frontend/partial/common.json
 M lang-packs/_bundled/g7-template-sirsoft-admin_basic-ja/language-pack.json
 M lang-packs/_bundled/g7-template-sirsoft-admin_basic-zh-CN/CHANGELOG.md
 M lang-packs/_bundled/g7-template-sirsoft-admin_basic-zh-CN/frontend/partial/admin.json
 M lang-packs/_bundled/g7-template-sirsoft-admin_basic-zh-CN/frontend/partial/common.json
 M lang-packs/_bundled/g7-template-sirsoft-admin_basic-zh-CN/language-pack.json
 M lang-packs/_bundled/g7-template-sirsoft-basic-ja/CHANGELOG.md
 M lang-packs/_bundled/g7-template-sirsoft-basic-ja/frontend/partial/home.json
 M lang-packs/_bundled/g7-template-sirsoft-basic-ja/language-pack.json
 M lang-packs/_bundled/g7-template-sirsoft-basic-zh-CN/CHANGELOG.md
 M lang-packs/_bundled/g7-template-sirsoft-basic-zh-CN/frontend/partial/home.json
 M lang-packs/_bundled/g7-template-sirsoft-basic-zh-CN/language-pack.json
 M templates/_bundled/sirsoft-admin_basic/CHANGELOG.md
 M templates/_bundled/sirsoft-admin_basic/lang/partial/en/admin.json
 M templates/_bundled/sirsoft-admin_basic/lang/partial/en/common.json
 M templates/_bundled/sirsoft-admin_basic/lang/partial/ko/admin.json
 M templates/_bundled/sirsoft-admin_basic/lang/partial/ko/common.json
 M templates/_bundled/sirsoft-admin_basic/layouts/partials/admin_settings/_tab_general.json
 M templates/_bundled/sirsoft-admin_basic/template.json
 M templates/_bundled/sirsoft-basic/CHANGELOG.md
 M templates/_bundled/sirsoft-basic/lang/partial/en/home.json
 M templates/_bundled/sirsoft-basic/lang/partial/ko/home.json
 M templates/_bundled/sirsoft-basic/layouts/partials/home/_welcome_card.json
 M templates/_bundled/sirsoft-basic/template.json
 M tests/Translations/lib/zh-CN-template-parity-lib.php
 M tests/Unit/Services/LanguagePack/BundledSimplifiedChineseTemplatePacksTest.php
?? docs/reports/site-description-i18n-and-welcome-badge-report.md
?? tests/Feature/Settings/SiteDescriptionLocalizationTest.php
?? tests/Unit/Layouts/WelcomeCardLocalizationTest.php
```

> `templates/sirsoft-basic/` · `templates/sirsoft-admin_basic/` (설치본) 과 `lang-packs/{identifier}/` (설치본) 은 `.gitignore` 대상이라 저장소에 없다. 저장소의 "원본"은 `_bundled` 이며, 설치본 반영은 18항의 배포 명령이 담당한다.

**후속 회귀 수정분** (§12-1 — 위 목록은 `c94ab441` 로 커밋된 최초 구현 시점의 상태다):

```
 M CHANGELOG.md
 M app/Helpers/locale_helpers.php
 M app/Services/SettingsService.php
 M config/settings/defaults.json
 M docs/backend/admin-settings-access.md
 M docs/reports/site-description-i18n-and-welcome-badge-report.md
 M tests/Feature/Settings/SiteDescriptionLocalizationTest.php
```

신규 0 · 수정 7 · 삭제 0. `app/Services/SettingsService.php` · `app/Helpers/locale_helpers.php` 변경은 주석뿐이고 실행 코드는 그대로다.

---

## 15. GitHub Desktop 커밋 가능 여부

**가능하다.**

- 충돌 없음 (기준 커밋에서 직접 작업, tree 는 시작 시 clean 이었음)
- 삭제·이름변경 0건
- `git diff --check` 0건, BOM/CRLF 0건
- 바이너리·대용량 파일 없음 (전부 PHP/JSON/Markdown, 총 +325 / −19 라인)
- 신규 3파일 모두 gitignore 대상 아님 (`tests/**`, `docs/reports/**`)

커밋 전 확인 권장: 36개 항목이 모두 스테이징되는지 (특히 신규 3파일).

---

## 16. 권장 커밋 제목

```
fix(settings,template): 사이트 설명 로케일별 저장·표시 및 환영 카드 하드코딩 문구 제거
```

---

## 17. 서버 배포 전 백업 명령

```bash
cd /path/to/g7
STAMP=$(date +%Y%m%d-%H%M%S)
mkdir -p ~/g7-backup-$STAMP

# 1) 설정 파일 (이번 변경이 실제로 건드리는 유일한 운영 데이터)
cp -a storage/app/settings ~/g7-backup-$STAMP/settings
cat storage/app/settings/general.json | tee ~/g7-backup-$STAMP/general.json.before

# 2) 레이아웃·언어팩 상태가 담긴 DB (template:update 가 layouts 테이블을 갱신한다)
mysqldump -u <USER> -p <DBNAME> \
  --single-transaction --quick --default-character-set=utf8mb4 \
  > ~/g7-backup-$STAMP/db-before.sql

# 3) 설치된 템플릿·언어팩 디렉토리
tar czf ~/g7-backup-$STAMP/templates.tgz templates/sirsoft-basic templates/sirsoft-admin_basic
tar czf ~/g7-backup-$STAMP/langpacks.tgz lang-packs/g7-template-sirsoft-*

# 4) 현재 버전 기록 (롤백 판단용)
php artisan template:list      | tee ~/g7-backup-$STAMP/templates.before.txt
php artisan language-pack:list | tee ~/g7-backup-$STAMP/langpacks.before.txt

ls -la ~/g7-backup-$STAMP
```

---

## 18. 서버 배포 · 설정 데이터 반영 · 캐시 재생성 · 검증 · 롤백

### 18-1. 배포

```bash
cd /path/to/g7
php artisan down            # 레이아웃 재등록 중 부분 상태 노출 방지

git fetch origin && git checkout main && git pull --ff-only

# 의존성 잠금 파일 무변경 — composer install / npm install 불필요
# frontend build 불필요 (10항 근거)
```

### 18-2. 템플릿 반영 (레이아웃은 DB 에 있으므로 필수)

`_welcome_card.json` · `_tab_general.json` 은 설치 시 DB `layouts` 로 인라인 저장된다. **파일만 바꿔서는 화면에 반영되지 않는다.**

```bash
# --layout-strategy=keep : 운영자가 편집한 레이아웃만 보존하고,
#   편집하지 않은 레이아웃은 파일에서 갱신한다 (original_content_hash 비교).
#   → 편집 이력이 없으면 keep 으로도 이번 변경이 그대로 적용된다.
php artisan template:update sirsoft-basic       --source=bundled --force --layout-strategy=keep
php artisan template:update sirsoft-admin_basic --source=bundled --force --layout-strategy=keep
```

> 만약 해당 레이아웃을 관리자 화면에서 편집한 이력이 있으면 `keep` 은 그 레이아웃을 보존하므로 이번 변경이 적용되지 않는다. 그때는 (a) 레이아웃 편집기에서 해당 부분만 수동 반영하거나, (b) 편집 내용을 백업한 뒤 `--layout-strategy=overwrite` 로 다시 실행한다. `overwrite` 는 **모든** 레이아웃 편집 내용을 버리므로 먼저 확인할 것:
> ```bash
> php artisan template:list   # 편집 여부는 관리자 > 템플릿 > 레이아웃 편집에서 확인
> ```

### 18-3. 언어팩 반영

번들 소스만 바꾸면 설치본에 자동 반영되지 **않는다.** 버전을 올렸으므로 일반 update 경로로도 잡히지만, 확실히 하려면 `--source=bundled`:

```bash
php artisan language-pack:update g7-template-sirsoft-basic-ja       --source=bundled
php artisan language-pack:update g7-template-sirsoft-basic-zh-CN    --source=bundled
php artisan language-pack:update g7-template-sirsoft-admin_basic-ja --source=bundled
php artisan language-pack:update g7-template-sirsoft-admin_basic-zh-CN --source=bundled

php artisan language-pack:list --scope=template
```

### 18-4. 캐시 재생성

```bash
php artisan language-pack:cache-clear
php artisan template:clear-cache
php artisan seo:clear                 # 봇 렌더 HTML 에 이전 설명이 캐시돼 있을 수 있다
php artisan config:clear && php artisan config:cache
php artisan route:clear && php artisan route:cache
php artisan queue:restart             # 상주 워커가 옛 설정을 들고 있지 않도록

php artisan up
```

### 18-5. 검증

```bash
# (1) 새 테스트 — 로컬에서 실행하지 못했으므로 여기서 처음 돌아간다
php artisan test --filter=SiteDescriptionLocalizationTest
php artisan test --filter=WelcomeCardLocalizationTest

# (2) 회귀
php artisan test --filter=BundledSimplifiedChineseTemplatePacksTest
php artisan test --filter=BundledJapanesePacksTest
php artisan test --filter=SettingsControllerTest
php artisan test --filter=SaveSettingsRequestSeoTest
php artisan test --filter=ComponentHtmlMapperTest
php artisan test tests/Feature/Settings

# (3) 언어팩 정합성 (vendor 불필요)
php tests/Translations/zh-CN-templates-parity-check.php --verbose --style
php tests/Translations/zh-CN-core-parity-check.php
php tests/Translations/zh-CN-board-parity-check.php
php tests/Translations/zh-CN-ecommerce-parity-check.php
php tests/Translations/zh-CN-page-parity-check.php
php tests/Translations/zh-CN-plugins-parity-check.php --style

# (4) 정적 분석
./vendor/bin/pint --test
./vendor/bin/phpstan analyse
```

**화면 확인** (19항 값 입력 후):

| 확인 | 기대 |
| --- | --- |
| 환경설정 > 일반 | 사이트 설명에 언어 탭 4개(한국어/영어/일본어/중국어 간체), 기존 문구가 한국어 탭에 표시 |
| 홈 (`?lang=ko` 또는 브라우저 ko) | 배지 `환영합니다`, 소개 한국어 |
| 홈 (en) | 배지 `Welcome`, 소개 영어 |
| 홈 (ja) | 배지 `ようこそ`, 소개 일본어 |
| 홈 (zh-CN) | 배지 `欢迎`, 소개 중국어 간체 |
| 각 로케일 Footer | 사이트 설명이 해당 언어로 표시 (미입력 언어는 비어 있음 — 정상) |
| 어느 화면에서도 | `Array` · `$t:` 원문이 보이지 않을 것 |

### 18-6. 롤백

```bash
cd /path/to/g7
php artisan down
STAMP=<백업 스탬프>

# 1) 코드 되돌리기
git checkout cb46fa156ce90cc74d1b40de6a1454a61f3af0c4

# 2) 설정을 단일 문자열로 복원 (중요)
#    구버전 코드는 로케일 맵을 렌더하지 못하고 "Array" 를 출력한다.
cp ~/g7-backup-$STAMP/general.json.before storage/app/settings/general.json

# 3) 템플릿·언어팩 설치본 복원
tar xzf ~/g7-backup-$STAMP/templates.tgz -C .
tar xzf ~/g7-backup-$STAMP/langpacks.tgz -C .

# 4) 레이아웃(DB) 복원 — 둘 중 하나
php artisan template:refresh-layout sirsoft-basic
php artisan template:refresh-layout sirsoft-admin_basic
#   또는 전체 DB 복원
#   mysql -u <USER> -p <DBNAME> < ~/g7-backup-$STAMP/db-before.sql

# 5) 캐시
php artisan language-pack:cache-clear && php artisan template:clear-cache && php artisan seo:clear
php artisan config:clear && php artisan config:cache && php artisan queue:restart
php artisan up
```

---

## 19. 운영 관리자에서 입력해야 하는 값

환경설정 > 일반 > **사이트 설명** — 언어 탭별로 아래 값을 넣고 저장한다. (저장소·migration 에는 넣지 않았다.)

**한국어 (ko)** — 기존 값 그대로 유지

```
YUTIV는 단순히 물건을 파는 공간이 아니라, 라이브 방송과 숏폼으로 제품의 가치를 생생하게 보여주는 '트렌드 라이브 커머스 플랫폼'입니다. 국경을 넘어 가장 핫한 뷰티, 패션, 골프 라이프스타일을 생동감 있게 연결합니다. 눈으로 보고, 감각으로 느끼며, 가장 빠르게 만나는 글로벌 쇼핑의 새로운 표준이 됩니다.
```

**영어 (en)**

```
YUTIV is more than a place to shop. It is a trend-driven live commerce platform that brings products to life through live streaming and short-form content. Across borders, it connects the hottest trends in beauty, fashion, golf, and lifestyle. See it, feel it, and discover a new standard for global shopping—faster than ever.
```

**일본어 (ja)**

```
YUTIVは、単に商品を販売するだけの場所ではありません。ライブ配信とショート動画を通じて商品の魅力をリアルに伝える、トレンド型ライブコマースプラットフォームです。国境を越えて、ビューティー、ファッション、ゴルフ、ライフスタイルの最新トレンドを臨場感豊かにつなぎます。見て、感じて、いち早く出会う、グローバルショッピングの新しいスタンダードです。
```

**중국어 간체 (zh-CN)**

```
YUTIV 不只是一个销售商品的平台，更是一个通过直播和短视频生动展现商品价值的潮流直播电商平台。我们跨越国界，连接美妆、时尚、高尔夫及生活方式领域的热门趋势。让您亲眼看见、真实感受，并以更快的方式体验全球购物的新标准。
```

**입력 시점** — 배포 직후. 입력 전까지는 en·ja·zh-CN 화면에 템플릿 기본 소개 문구가 표시된다 (한국어가 새지는 않는다).

**길이 확인** — 4개 값 모두 500자 이하다 (ko 175 / en 326 / ja 173 / zh-CN 110자 — `mb_strlen` 기준). 검증 상한 `max:500` 에 걸리지 않는다.

---

## 20. 보고서 파일 경로와 클립보드 복사 명령

`docs/reports/site-description-i18n-and-welcome-badge-report.md`

```powershell
Get-Content -Raw -Encoding UTF8 "docs\reports\site-description-i18n-and-welcome-badge-report.md" | Set-Clipboard
```
