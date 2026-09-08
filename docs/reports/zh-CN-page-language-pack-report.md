# 페이지 모듈 중국어 간체(zh-CN) 언어팩 구현 보고서

- 대상 패키지: `g7-module-sirsoft-page-zh-CN` (v1.0.0)
- 대상 모듈: `sirsoft-page` (v1.1.0, vendor `sirsoft`, MIT)
- 기준 커밋: `1b56bf98bdec88c4fec4adb9cfb379189f6b0846`
- 작성일: 2026-09-08

---

## 1. 사전 점검 결과

| 항목 | 결과 |
|---|---|
| `git branch --show-current` | `main` |
| `git rev-parse HEAD` | `1b56bf98bdec88c4fec4adb9cfb379189f6b0846` — **지정 커밋과 일치** |
| `git status --short` | 출력 없음 — **clean** |
| `git remote -v` | `origin` → `https://github.com/soroji/yutiv.git`, `upstream` → `https://github.com/gnuboard/g7.git` |
| `modules/_bundled` | `gnuboard7-hello_module`, `sirsoft-board`, `sirsoft-ecommerce`, `sirsoft-page` |

작업 시작 조건을 모두 충족하여 진행했다.

### 1-1. 실제 페이지 모듈 identifier

`modules/_bundled/sirsoft-page/module.json` 에서 확인.

| 필드 | 값 |
|---|---|
| `identifier` | `sirsoft-page` |
| `vendor` | `sirsoft` |
| `version` | `1.1.0` |
| `license` | `MIT` |
| `g7_version` | `>=7.0.9` |
| `name.ko` / `name.en` | 페이지 / Page |
| `description.ko` | 정적 페이지(정보/정책/안내) 관리 모듈 |

### 1-2. 기존 일본어 팩 identifier

`lang-packs/_bundled/g7-module-sirsoft-page-ja/language-pack.json`.

| 필드 | 값 |
|---|---|
| `identifier` | `g7-module-sirsoft-page-ja` |
| `version` | `1.0.2` |
| `scope` / `target_identifier` | `module` / `sirsoft-page` |
| `namespace` / `vendor` / `license` | `g7` / `sirsoft` / `MIT` |
| `requires.target_version` | `null` |
| `requires.depends_on_core_locale` | `true` |
| `g7_version` | `>=7.0.0` |
| 파일 수 | 10 |

### 1-3. 한국어 원본 번역 자산 구조

| 경로 | 파일 | 줄 수 |
|---|---|---|
| `src/lang/ko/activity_log.php` | 1 | 42 |
| `src/lang/ko/messages.php` | 1 | 51 |
| `src/lang/ko/validation.php` | 1 | 107 |
| `resources/lang/ko.json` | 1 | 13 |
| `resources/lang/partial/ko/admin.json` | 1 | 173 |

seed 원본은 `module.php` 의 `getPermissions()`(권한 6항목)·`getAdminMenus()`(메뉴 1종)와
`module.json`(모듈 표시명·설명)이다. `getRoles()` 는 **빈 배열**이며 알림 정의·본인인증
메시지 선언은 없다 — 따라서 `roles`/`notifications`/`identity_messages` seed 는 존재하지 않는다
(ja 팩도 동일).

### 1-4. GO / NO-GO 판정

**GO.** 근거:

1. 네이밍 공식 `{namespace}-{scope}-{target}-{locale}` 로 `g7-module-sirsoft-page-zh-CN` 이 유효 (`LanguagePackManifestValidator::validateIdentifierNaming()`).
2. `zh-CN` 은 매니페스트 BCP-47 패턴과 `LanguagePackBundledRegistrar` 의 locale 디렉토리 스캔 패턴(`/^[a-z]{2,3}(-[A-Z]{2})?$/`)을 모두 통과.
3. 코어 zh-CN 팩(`g7-core-zh-CN`)이 이미 존재하므로 `depends_on_core_locale` 전제 충족 가능.
4. ko 원본이 완비되어 있고 ja 팩이 구조 참조로 존재하며, 코어·모듈 코드 변경이 필요 없다.

---

## 2. 생성한 zh-CN 언어팩

| 항목 | 값 |
|---|---|
| `identifier` | `g7-module-sirsoft-page-zh-CN` |
| `namespace` / `vendor` | `g7` / `sirsoft` |
| `scope` / `target_identifier` | `module` / `sirsoft-page` |
| `locale` / `locale_name` / `locale_native_name` | `zh-CN` / `Simplified Chinese` / `简体中文` |
| `text_direction` | `ltr` |
| `version` / `license` | `1.0.0` / `MIT` |
| `g7_version` | `>=7.0.0` |
| `requires.target_version` | `null` |
| `requires.depends_on_core_locale` | `true` |
| `name` / `description` 로케일 | `ko`, `en`, `zh-CN` |

`vendor`·`license`·`target_version` 은 대상 모듈(`module.json`)과 기존 ja 팩 계약을 조사해
결정했으며 셋 다 일치한다. `g7_version` 은 기존 번들 언어팩 공통값 `>=7.0.0` 을 따랐다
(모듈 자체의 `>=7.0.9` 와 다른 것은 ja 팩·게시판·이커머스 zh-CN 팩과 동일한 기존 관행이다).

---

## 3. 파일 인벤토리

| 구분 | ko 원문 소스 | 산출 위치 | 원본 | 산출 |
|---|---|---|---|---|
| backend | `src/lang/ko/*.php` | `backend/zh-CN/*.php` | 3 | 3 |
| frontend 엔트리 | `resources/lang/ko.json` | `frontend/zh-CN.json` | 1 | 1 |
| frontend partial | `resources/lang/partial/ko/admin.json` | `frontend/partial/admin.json` | 1 | 1 |
| seed | `module.php` + `module.json` | `seed/{permissions,menus,manifest}.json` | — | 3 |
| 매니페스트 / CHANGELOG | — | `language-pack.json`, `CHANGELOG.md` | — | 2 |
| **합계** | | | | **10** |

ja 팩(10)과 상대 경로 구성이 **완전히 일치**한다(로케일 세그먼트 정규화 후 diff 0).

### 번역 규모

| 구분 | 파일 | 리프 키 |
|---|---|---|
| backend PHP | 3 | 87 |
| frontend JSON | 2 | 134 |
| seed JSON | 3 | 15 |
| **합계** | **8** | **236** |

---

## 4. 정합성 검사 결과

```
php tests/Translations/zh-CN-page-parity-check.php --verbose --style
```

```
검사 파일 10개 (PHP 3 / JSON 5), 대조 키 221개

파일 누락 OK(0)          초과 파일 OK(0)        구조 오류 OK(0)
JSON 구문 OK(0)          키 누락 OK(0)          초과 키 OK(0)
키 순서 불일치 OK(0)     자료형 불일치 OK(0)    placeholder 불일치 OK(0)
HTML 태그 불일치 OK(0)   URL 불일치 OK(0)       빈 값 불일치 OK(0)
개행 개수 불일치 OK(0)   한글 잔존 OK(0)        일본어 가나 잔존 OK(0)
인코딩 오류 OK(0)        BOM 검출 OK(0)         $partial 경로 오류 OK(0)
ja 팩 구조 차이 OK(0)

--- 표기 스타일 경고 (실패 아님) ---
  ~ backend/zh-CN/validation.php — ja 팩과 키 순서가 다름 (키 집합은 동일). zh 는 ko 순서를 따른다
  ~ frontend/zh-CN.json — ja 팩과 키 순서가 다름 (키 집합은 동일). zh 는 ko 순서를 따른다
  ~ frontend/partial/admin.json — ja 팩과 키 순서가 다름 (키 집합은 동일). zh 는 ko 순서를 따른다
  총 3건

RESULT: PASS — 위반 0건   (exit 0)
```

backend/frontend 는 **모듈의 ko 원본**과, seed 는 **ja 팩**과 대조한다(seed 원본이 `module.php`·
`module.json` 에 분산되어 기계적 1:1 대조 대상이 아니기 때문). 위 3건은 실패가 아니라 §8 에
설명한 의도된 차이를 표면화한 정보성 경고다.

### 4-1. placeholder / HTML / URL

| 항목 | 결과 |
|---|---|
| placeholder 종류·**개수** 일치 | 236개 리프 전부 일치, 불일치 0 (검사기 + PHPUnit `test_placeholders_match_korean_origin` 양쪽) |
| 검출된 placeholder | `:title` `:count` `:limit` `:attempted` `:max` `:min` `:locale` `:maxKB`, `{{count}}` `{{slug}}` `{{version}}` `{{fields}}` |
| HTML 태그 | 원본에 HTML 태그 사용 없음 — 양쪽 0개로 일치 |
| URL | `URL: /page/{{slug}}` 는 상대 경로라 `http(s)://` 토큰 0개, 문자열은 원본 그대로 보존 |

### 4-2. 한글 / 가나 잔존

| 항목 | 결과 |
|---|---|
| 한글(음절/자모) 잔존 | **0건** (`[\x{AC00}-\x{D7A3}\x{1100}-\x{11FF}\x{3130}-\x{318F}]`) |
| 일본어 가나 잔존 | **0건** (`[\x{3040}-\x{309F}\x{30A0}-\x{30FF}]`) |

### 4-3. PHP / JSON / 매니페스트 / 인코딩

| 항목 | 결과 |
|---|---|
| `php -l` | 3/3 통과 (구문 오류 0) |
| PHP 구조 | 전 파일 `return <배열 리터럴>;` 단일 구조, 리프 87개 전부 `string` |
| JSON 파싱 | 6/6 성공 (오류 0) |
| BCP-47 locale | OK |
| identifier 네이밍 공식 | OK |
| target ↔ `module.json` identifier 일치 | OK (`sirsoft-page`) |
| SemVer | OK (`1.0.0`) |
| 매니페스트 필수 필드 누락 | 0 |
| UTF-8 / BOM / CRLF | 10개 파일 전부 UTF-8, **BOM 0 / CRLF 0 / 비-UTF8 0** |
| 바이너리·대용량(>512KB) 파일 | 0 |

### 4-4. 보안 검사

| 규칙 | 결과 |
|---|---|
| 허용 확장자 외 파일 | 0 (실제 확장자: `php`, `json`, `md`) |
| 심볼릭 링크 | 0 |
| PHP 파일 위치 `#^backend/(?:zh-CN/)?[A-Za-z0-9_-]+\.php$#` | 3/3 통과 |
| PHP 정적 배열 반환 구조만 허용 | 통과 (토큰 화이트리스트 검사) |
| `eval`/`exec`/`shell_exec`/`system`/`passthru`/`proc_open` | 0 |
| `include`/`require` 및 동적 코드 로딩 | 0 |
| 네트워크 접근(`curl_*`, `file_get_contents(http…)`) | 0 |
| 파일 쓰기(`file_put_contents`, `fopen`) | 0 |
| 환경변수 접근(`getenv`/`putenv`) | 0 |
| API key / password / token / 자격증명 패턴 | 0 |
| `.env` 또는 운영 설정 포함 | 0 |

### 4-5. seed 검증

| 파일 | 키 | 검증 |
|---|---|---|
| `seed/permissions.json` | 6 (`sirsoft-page`, `.pages`, `.pages.read/create/update/delete`) | `module.php::getPermissions()` 가 생성하는 식별자·순서와 일치 |
| `seed/menus.json` | 1 (`sirsoft-page`) | `module.php::getAdminMenus()` 의 slug 와 일치 |
| `seed/manifest.json` | 2 (`name`, `description`) | `module.json` 의 `name.ko`/`description.ko` 대응 |
| `seed/roles.json` | 없음 | `getRoles()` 가 빈 배열이므로 **의도적 부재** (PHPUnit 이 부재를 단언) |

ja 팩 seed 3종과 키 집합·순서가 완전히 일치하므로 `$intentionalSeedExtras` 예외가 필요 없었다.

---

## 5. frontend build 필요 여부

**불필요.** 코드 근거:

1. `modules/_bundled/sirsoft-page/package.json` 의 `scripts` 에 **`build` 가 없다** — `test`, `test:run`, `test:e2e`, `test:e2e:ui` 뿐이다.
2. 번들러 설정 파일은 `vitest.config.ts`(테스트 러너 전용) 하나뿐이며 `vite.config.ts` 는 존재하지 않는다.
3. `dist/`·`*.iife.js` 등 빌드 산출물이 저장소에 존재하지 않는다(`find` 결과 0건).
4. `resources/js/**` 의 프로덕션 코드가 `resources/lang/**` 를 import 하지 않는다.
5. 언어팩 frontend JSON 은 `app/Listeners/LanguagePack/MergeFrontendLanguage.php` 가 **런타임에 디스크에서 읽어** 병합한다(`File::get()`, `frontend/partial/*.json` 직접 로드 + 루트 `{locale}.json` 의 `$partial` 해석).

따라서 이 언어팩 배포에 `npm install` / `npm run build` 는 필요 없고, 실행하지 않았다.

---

## 6. ja 팩과의 의도적 차이

파일 구성과 키 집합은 ja 팩과 **완전히 일치**한다(누락·초과 0). 차이는 **키 순서** 3건뿐이다.

| # | 파일 | 차이 | 근거 | 처리 |
|---|---|---|---|---|
| 1 | `validation.php` | ja 는 `attributes.search` 를 파일 맨 뒤에 둠. ko 는 `search` 와 `search_field` 사이 | ko 원본이 단일 출처 | **ko 순서 채택** |
| 2 | `frontend/{locale}.json` | ja 는 `admin` → `editor` 순. ko 는 `editor` → `admin` 순 | 동일 | **ko 순서 채택** |
| 3 | `frontend/partial/admin.json` | ja 는 `page.detail.versions.preview_modal_editor_label` 을 그룹 맨 뒤에 둠. ko 는 `preview_modal_title` 바로 뒤 | 동일 | **ko 순서 채택** |

세 건 모두 **키 집합은 동일**하므로 기능 영향은 없고, ja 팩이 ko 원본 대비 재정렬된 상태다.
`zh-CN-page-parity-check.php --style` 이 이 차이를 정보성 경고로 출력하도록 §4-c 를 추가했다.
**ja 팩 자체는 수정하지 않았다.**

ja 팩에만 있고 ko 원본·모듈 코드에 없는 파일/키는 **없었다**(게시판·이커머스 팩과 달리
이 팩에서는 `$intentionalSeedExtras`·`$intentionalSeedValueDrift`·`$intentionalJaOnlyPartials`
가 모두 빈 배열이며, 그 이유를 검사기 주석에 남겼다).

---

## 7. 번역 판단표

| 한국어 원문 | 선택한 중국어 | 대안 | 선택 이유 | 기존 zh-CN 팩과의 일관성 |
|---|---|---|---|---|
| 슬러그 | `别名` | `Slug`, `路径别名` | 코어 팩(`请输入别名。`, `该别名已被使用。`)과 게시판 팩(`slug: 别名`)이 이미 `别名` 으로 고정. 제품 UI 전반에서 동일 표기를 쓰는 편이 학습 비용이 낮다 | **일치** (core·board 동일. ecommerce 는 브랜드/분류 폼에서 `别名（slug）` 로 괄호 병기 — 이 팩은 필드 폭이 좁은 목록 컬럼이 많아 병기 없이 통일) |
| 발행 / 미발행 | `已发布` / `未发布` | `发行`, `公开` / `不公开` | 페이지의 published 는 "공개 여부"가 아니라 "게시 상태"다. 게시판 팩이 `published => 已发布` 로 고정 | **일치** (board `enums.published = 已发布`) |
| 발행 취소 | `取消发布` | `撤销发布`, `下架` | `unpublish` 를 되돌리는 동작. `下架` 는 상품 문맥이라 부적절 | 신규 (충돌 없음) |
| 발행 여부 | `是否发布` | `发布状态` | ko 원문이 `여부`(whether). 이커머스 팩의 `是否启用`·`是否使用` 패턴 승계 | **일치** |
| 미발행 처리 (`unpublish_action`) | `设为未发布` | `取消发布` | 같은 화면에 `publish_action = 发布` 와 나란히 놓이는 버튼. 상태를 지정하는 표현이 대칭적 | 신규 |
| 복원 | `恢复` | `还原` | 기존 zh-CN 팩 3종에서 `恢复` 166회 : `还原` 1회 | **일치** |
| 저장자 (`versions.creator`) | `保存者` | `作者` | 같은 파일에 `creator = 作者`(페이지 작성자)가 따로 있어 구분이 필요. 버전을 저장한 주체를 가리킴 | 신규 (원문 구분 유지) |
| 콘텐츠 모드 / 편집 모드 | `内容模式` / `编辑模式` | 하나로 통일 | ko 원본이 activity_log 는 `콘텐츠 모드`, frontend 는 `편집 모드` 로 구분해 씀 — 원문 구분을 임의로 합치지 않음 | 신규 |
| SEO 제목/설명/키워드 | `SEO 标题` / `SEO 说明` / `SEO 关键词` | `元标题` / `元描述` | ko 원문이 `메타 제목`이 아니라 `SEO 제목`. 이커머스 팩 상품 필드가 동일 표기 | **일치** |
| SEO 정보 (`field_labels.seo_meta`) | `SEO 信息` | `元信息` | 이커머스 카테고리 정보 섹션과 동일 | **일치** |
| 생성일 / 수정일 | `创建日期` / `修改日期` | `登记日期` | 기존 팩에 `创建日期`·`修改日期`·`登记日期` 가 혼재하나, ko 원문이 `생성일`이므로 `创建日期` 가 정확 | **일치** (core·ecommerce 동일 표기 존재) |
| 발행일시 | `发布时间` | `发布日期` | `published_at` 은 시각까지 포함 | 신규 |
| 한국어 / English (`lang_tabs`) | `韩语` / `英语` | 자기 언어 표기 유지 | 한글을 남길 수 없고, zh-CN 화면에서 혼합 표기는 일관성이 깨진다 | **일치** (ecommerce 팩과 동일 결정) |
| 임시 키 (`temp_key`) | `临时键` | `临时密钥` | 업로드 세션 식별자이지 보안 키가 아니다 | **일치** (ecommerce `临时键`) |
| 별명 중복 확인 (`slug_check`) | `重复检查` | `查重` | 버튼 라벨로 의미가 분명하고 격식이 맞음 | 신규 |

### 번역하지 않고 보존한 것

- 슬러그 예시 `url-slug`, URL 경로 `URL: /page/{{slug}}`
- in-rule 리터럴: `html`/`text`, `asc`/`desc`, `created_at`/`published_at`, `all`/`title`/`slug`, `true`/`false`
- 권한 키 `sirsoft-page.pages.*`, 메뉴 slug `sirsoft-page`, 컬럼 라벨 `ID`
- 모든 placeholder(종류·개수·순서)

---

## 8. 검증 도구

| 파일 | 성격 | 실행 |
|---|---|---|
| `tests/Translations/zh-CN-page-parity-check.php` | standalone CLI (vendor·Laravel 불필요, PHP 7.x 폴리필 포함) | **실행 완료 — PASS** |
| `tests/Unit/Services/LanguagePack/BundledSimplifiedChinesePagePackTest.php` | PHPUnit (프로덕션 클래스 실호출) | **로컬 실행 불가** — §9 |

PHPUnit 테스트가 검증하는 항목: manifest validator 실호출, PHP array validator 실호출,
팩 탐색, identifier/locale/scope/target/버전 필드, 파일 구조, ko 대비 키 parity(backend·frontend),
placeholder 종류·개수, seed 식별자 ↔ `module.php` 선언 대응, `roles.json` 의도적 부재,
한글 잔존 0, `$partial` 실재, 대상 모듈 존재, `depends_on_core_locale` 설치 의존성 계약.

---

## 9. 실행하지 못한 테스트와 이유

| 항목 | 사유 | 서버에서 실행할 명령 |
|---|---|---|
| PHPUnit `BundledSimplifiedChinesePagePackTest` | 로컬 PHP **7.4.22** 이며 `composer.json` 은 `^8.2` 요구. `vendor/` 디렉토리는 존재하나 **비어 있음**(`autoload.php` 없음) → Laravel 부팅 불가 | `php artisan test --filter=BundledSimplifiedChinesePagePackTest` |
| 프로덕션 `LanguagePackManifestValidator` 실호출 | 위와 동일 (규칙만 스크립트로 재현 검증) | 위 PHPUnit 에 포함 |
| 프로덕션 `LanguagePackPhpArrayValidator` 실호출 | 위와 동일. 해당 클래스는 PHP 8 의 `catch (ParseError)` 를 사용해 7.4 에서 로드 불가 (토큰 화이트리스트로 근사 검증) | 위 PHPUnit 에 포함 |
| 실제 설치·활성화 후 화면 확인 | DB 변경 금지 · 서버 접속 금지 범위 | §12 설치 명령 후 관리자 화면 확인 |

로컬에서 가능한 정적 검사(파서·인코딩·키·순서·placeholder·매니페스트·보안 규칙 재현)는 전부
통과했으나, **위 4건은 성공으로 간주하지 않는다.**

---

## 10. 변경 파일

### 신규 (13개 파일)

- `lang-packs/_bundled/g7-module-sirsoft-page-zh-CN/` — 10개 파일
  - `language-pack.json`, `CHANGELOG.md`
  - `backend/zh-CN/{activity_log,messages,validation}.php`
  - `frontend/zh-CN.json`, `frontend/partial/admin.json`
  - `seed/{manifest,menus,permissions}.json`
- `tests/Translations/zh-CN-page-parity-check.php`
- `tests/Unit/Services/LanguagePack/BundledSimplifiedChinesePagePackTest.php`
- `docs/reports/zh-CN-page-language-pack-report.md` (이 문서)

### 수정 (4개 파일, 허용 범위 내)

- `README.md` — 번들 언어팩 인벤토리에 1행 추가
- `README.ko.md` — 동일
- `CHANGELOG.md` — `[Unreleased] > Added` 에 1행 추가
- `docs/extension/language-packs.md` — zh-CN 섹션에 페이지 모듈 팩 항목 추가 (기존 3개 팩 내용 보존)

### 삭제 / 이름 변경

**0건.**

### 변경하지 않은 것

코어, 모듈(`sirsoft-page` 포함), 플러그인, 템플릿, 기존 언어팩(ja 3종 포함), 기존 zh-CN 코어·게시판·
이커머스 팩과 그 보고서·검사기, DB 스키마·마이그레이션, 라우트, 권한 키, 설정 키,
`package.json`/`composer.json`/lock 파일, `config/app.php`, `.env`.

---

## 11. 예상하지 못한 변경

**없음.**

- HEAD 는 시작 시점과 동일한 `1b56bf98bdec88c4fec4adb9cfb379189f6b0846`
- 추적 파일 변경은 허용된 4개 문서뿐이며, diff 의 삭제 4줄은 모두 제자리 재작성(문장 갱신)이다
- `git diff --check` 통과 (공백 오류·충돌 마커 0)
- `package.json` / `composer.json` / lock 파일 변경 0
- 의존성 설치·업데이트·frontend build 미실행

### `git status --short`

```
 M CHANGELOG.md
 M README.ko.md
 M README.md
 M docs/extension/language-packs.md
?? docs/reports/zh-CN-page-language-pack-report.md
?? lang-packs/_bundled/g7-module-sirsoft-page-zh-CN/
?? tests/Translations/zh-CN-page-parity-check.php
?? tests/Unit/Services/LanguagePack/BundledSimplifiedChinesePagePackTest.php
```

### `git diff --stat`

```
 CHANGELOG.md                     |   1 +
 README.ko.md                     |   1 +
 README.md                        |   1 +
 docs/extension/language-packs.md | 107 +++++++++++++++++++++++++++++++++++++--
 4 files changed, 106 insertions(+), 4 deletions(-)
```

---

## 12. 권장 commit 제목 / 서버 명령

### 권장 commit 제목

```
feat(lang-packs): 페이지 모듈 중국어 간체(zh-CN) 언어팩 추가
```

### 서버 검증·백업·설치 명령

```bash
# 검증
php tests/Translations/zh-CN-page-parity-check.php --verbose --style
php artisan test --filter=BundledSimplifiedChinesePagePackTest

# 백업 (설치 전)
mysqldump -u <user> -p <db> language_packs > backup_language_packs_$(date +%F).sql
tar czf backup_langpacks_$(date +%F).tgz lang-packs/

# 설치 (코어 zh-CN 팩이 먼저 활성이어야 함)
php artisan language-pack:list --scope=core
php artisan language-pack:install g7-core-zh-CN --source=bundled     # 미설치인 경우만
php artisan module:list                                              # sirsoft-page active 확인
php artisan language-pack:install g7-module-sirsoft-page-zh-CN --source=bundled
php artisan language-pack:list --scope=module

# 콘텐츠 수정 후 재반영 (frontend build 불필요)
php artisan language-pack:update g7-module-sirsoft-page-zh-CN --force
```

---

## 13. 후속 과제

1. **ja 팩 키 순서 동기화** — §6 의 3건은 `g7-module-sirsoft-page-ja` 가 ko 원본 대비 재정렬된 상태다. 기능 영향은 없으나 정렬을 맞추면 검사기의 정보성 경고 3건이 사라진다.
2. **ko/en 변경 시 zh-CN 동기화** — 페이지 모듈의 다국어 키를 바꿀 때마다 이 팩의 키 셋·순서도 갱신하고 `zh-CN-page-parity-check.php` 로 확인해야 한다. 누락되면 중국어 화면에서 오류 없이 ko/en 폴백이 노출된다.
3. **플러그인·템플릿 zh-CN 팩** — 아직 없음.

---

## 14. 보고서 클립보드 복사

```powershell
Get-Content -Raw -Encoding UTF8 "docs\reports\zh-CN-page-language-pack-report.md" | Set-Clipboard
```
