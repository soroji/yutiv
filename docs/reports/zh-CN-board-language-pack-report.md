# g7-module-sirsoft-board-zh-CN 게시판 모듈 중국어 간체 언어팩 — 구현 보고서

- **작성일**: 2026-09-08
- **대상**: G7 7.0.9 (YUTIV)
- **범위**: 게시판 모듈(`sirsoft-board`) zh-CN 언어팩 신규 제작 + 문서 반영 + 커밋 전 감사
- **저장소 상태**: branch `main` / HEAD `aa9570a5632b6df555023f7a55538fb66dc6b42d`
- **커밋 상태**: **미커밋** (commit·push·서버 접속·서버 배포 없음)

---

## 1. 실제 확인한 게시판 모듈 identifier와 기존 일본어팩 identifier

저장소 조사로 확정한 값입니다(추정 없음).

| 항목 | 값 | 확인 근거 |
|---|---|---|
| **게시판 모듈 identifier** | **`sirsoft-board`** | `modules/_bundled/sirsoft-board/module.json` → `"identifier": "sirsoft-board"` |
| 모듈 vendor / version | `sirsoft` / `1.1.0` | 동일 파일 |
| 모듈 표시명 (ko/en) | `게시판` / `Board` | 동일 파일 |
| **기존 일본어팩 identifier** | **`g7-module-sirsoft-board-ja`** | `lang-packs/_bundled/` 디렉터리 실재, 33개 파일 |
| ja 팩 버전 / scope | `1.0.3` / `module` | `g7-module-sirsoft-board-ja/language-pack.json` |
| ja 팩 target_identifier | `sirsoft-board` | 동일 파일 |
| ja 팩 `depends_on_core_locale` | `true` | 동일 파일 |

`modules/_bundled/` 전체는 `gnuboard7-hello_module`, `sirsoft-board`, `sirsoft-ecommerce`, `sirsoft-page`
4종이며, 게시판에 해당하는 것은 `sirsoft-board` 하나뿐임을 확인했습니다.

### 사전 점검 결과 (전부 일치 — 중단 사유 없음)

| 항목 | 기대 | 실제 | 판정 |
|---|---|---|---|
| `git branch --show-current` | main | main | ✅ |
| `git rev-parse HEAD` | `aa9570a5…` | `aa9570a5632b6df555023f7a55538fb66dc6b42d` | ✅ |
| `git tag --points-at HEAD` | — | (없음) | ℹ️ 7.0.9 태그는 직전 커밋에 있고 HEAD 는 zh-CN 코어팩 커밋 — 정상 |
| `git status --short` | clean | (빈 출력) | ✅ |
| 작업 트리 | clean | clean | ✅ |

---

## 2. 구현 완료 여부

**완료.** `lang-packs/_bundled/g7-module-sirsoft-board-zh-CN/` 에 33개 파일을 생성했습니다.

- 일본어팩의 **디렉터리·파일 구성을 정확히 미러링**(경로 1:1, 33 = 33)
- 번역 원문은 **게시판 모듈의 한국어 원본**만 사용
  (`src/lang/ko/`, `resources/lang/ko.json`, `resources/lang/partial/ko/`, `module.php`, `module.json`, `BoardTypeSeeder`)
- **일본어를 중국어로 재번역하지 않았습니다** — ja 팩은 구조·매니페스트 형식·HTML 골격 참조로만 사용
- 코어·모듈·플러그인·템플릿·기존 언어팩 파일 **수정 0건**
- 게시판 외 확장(이커머스·페이지·플러그인·템플릿) 언어팩 **생성 0건**
- DB·라우트·권한 키·설정 키·비즈니스 로직 **변경 0건**

---

## 3. GO / NO-GO

## ✅ **GO** — BLOCKER 없음

| 판정 축 | 결과 |
|---|---|
| 파일 수 / 구조 미러링 | 33 = 33, 경로 1:1 완전 일치 |
| 원본 파일 목록 parity | backend 7=7, frontend 19=19 (admin/ 재귀 포함) |
| 키 누락 / 초과 / 순서 / 자료형 | **0 / 0 / 0 / 0** |
| placeholder / HTML / URL | 불일치 **0** |
| 빈 값 / 개행 구조 | 불일치 **0** |
| 한글 잔존 / 일본어 가나 잔존 | **0 / 0** (3,212개 값 전수) |
| `php -l` | **7/7** 통과 |
| JSON 파싱 | **25/25** 통과 |
| manifest validator | 전 규칙 통과 |
| 보안 규칙 | 위반 **0** |
| `git diff --check` | clean |
| 허용 경로 밖 변경 | **0건** |
| 기존 파일 수정·삭제 | 허용 4개 문서 외 **0건**, 삭제 **0건** |
| package/composer 변경 | **0건** |
| frontend build | **불필요** (증거 §9-6) |

유일한 미완은 PHPUnit 로컬 실행 불가(§10) — 환경 제약이며 결함이 아닙니다.

---

## 4. 생성·수정·삭제 파일 수

| 구분 | 개수 |
|---|---|
| **생성** | **36** |
| **수정** | **4** |
| **삭제** | **0** (이름변경도 0) |

`git diff --stat` — 수정 4개 파일 **98 insertions(+), 6 deletions(-)**:

```
 CHANGELOG.md                     |   1 +
 README.ko.md                     |   1 +
 README.md                        |   1 +
 docs/extension/language-packs.md | 101 ++++++++++++++++++++++++++++++++++++---
 4 files changed, 98 insertions(+), 6 deletions(-)
```

> `docs/extension/language-packs.md` 의 6줄 삭제는 기존 zh-CN 섹션의 제목·도입·구성표를
> 「코어 전용」에서 「코어 + 게시판」으로 갱신하면서 대체된 줄입니다(내용 손실 없음, §11).

### 생성 파일 36개

| 경로 | 개수 |
|---|---|
| `lang-packs/_bundled/g7-module-sirsoft-board-zh-CN/**` | **33** |
| `tests/Unit/Services/LanguagePack/BundledSimplifiedChineseBoardPackTest.php` | 1 |
| `tests/Translations/zh-CN-board-parity-check.php` | 1 |
| `docs/reports/zh-CN-board-language-pack-report.md` (본 문서) | 1 |

---

## 5. 생성한 언어팩 identifier, target, version

| 필드 | 값 |
|---|---|
| `identifier` | **`g7-module-sirsoft-board-zh-CN`** |
| `namespace` | `g7` |
| `vendor` | `sirsoft` |
| `scope` | `module` |
| **`target_identifier`** | **`sirsoft-board`** |
| `version` | **`1.0.0`** |
| `license` | `MIT` |
| `locale` | `zh-CN` |
| `locale_name` | `Simplified Chinese` |
| `locale_native_name` | `简体中文` |
| `text_direction` | `ltr` |
| `g7_version` | `>=7.0.0` |
| `requires.target_version` | `null` |
| **`requires.depends_on_core_locale`** | **`true`** |
| `name` / `description` 로케일 | `ko`, `en`, `zh-CN` |

### 네이밍 규칙 준수 근거

`LanguagePackManifestValidator::validateIdentifierNaming()` 이 강제하는 공식
`{namespace}-{scope}-{target}-{locale}` → `g7` + `module` + `sirsoft-board` + `zh-CN`
= **`g7-module-sirsoft-board-zh-CN`**. 폴더명·매니페스트 identifier 동일.
`backend/zh-CN/`·`frontend/zh-CN.json` 의 로케일 세그먼트도 대소문자 포함 일치.

### `depends_on_core_locale: true` 결정 근거 (요구사항 — 코드 조사 결과)

`app/Services/LanguagePackService.php:1386` `resolveCoreLocaleBlockedReason()`:

```php
$scope = $manifest['scope'] ?? null;
if ($scope === LanguagePackScope::Core->value) { return null; }   // 코어는 면제
$dependsOn = $manifest['requires']['depends_on_core_locale'] ?? true;  // 기본값 true
if (! $dependsOn) { return null; }
return $this->registry->hasActiveCoreLocale($locale) ? null : 'core_locale_missing';
```

- scope ≠ core 인 팩은 이 검사를 받으며, `assertDependencies()`(:1322)가 미충족 시
  `LanguagePackOperationException('language_packs.errors.core_locale_missing')` 을 던진다.
- 필드를 생략해도 기본값이 `true` 이므로 동작은 같지만, **기존 module 팩
  `g7-module-sirsoft-board-ja` 가 `true` 를 명시**하고 있어 계약을 명시적으로 맞췄다.
- 실무적 의미: `g7-core-zh-CN` 이 활성이 아니면 이 팩은 설치가 차단된다.
  게시판 팩만 활성화되면 코어 문구는 ko 로, 게시판 문구만 zh-CN 으로 나오는
  혼종 화면이 생기므로 이 강제가 옳다.

---

## 6. 번역 파일 수와 leaf key 수

| 영역 | 파일 | leaf 키 |
|---|---:|---:|
| backend (`backend/zh-CN/*.php`) | 7 | 724 |
| frontend (`frontend/zh-CN.json` + `partial/**`) | 19 | 2,403 |
| seed (`seed/*.json`) | 5 | 85 |
| **번역 자산 계** | **31** | **3,212** |
| 메타데이터 (`language-pack.json`, `CHANGELOG.md`) | 2 | — |
| **패키지 총계** | **33** | |

### backend 7파일 (모듈 `src/lang/ko` 와 1:1)

`activity_log`, `admin`, `enums`, `messages`, `notification`, `seo`, `validation` (`.php`)

### frontend 19파일

- 엔트리 1: `zh-CN.json`
- partial 루트 6: `attributes`, `board`, `common`, `enums`, `messages`, `report_types`, `validation` → 7
- `partial/admin.json` 1
- `partial/admin/` 10: `board`, `board_types`, `dashboard`, `ecommerce_settings`, `form`, `modals`, `posts`, `reports`, `settings`, `users`

### seed 5파일과 한국어 원본

| 파일 | 항목 | 원본 |
|---|---:|---|
| `permissions.json` | 17 | `module.php::getPermissions()` |
| `notifications.json` | 7 정의 × (definition + mail/database) | `module.php::getNotificationDefinitions()` |
| `menus.json` | 4 | `module.php::getAdminMenus()` |
| `board_types.json` | 3 | `database/seeders/BoardTypeSeeder.php` |
| `manifest.json` | 1 | `module.json` 의 `name`/`description` |

---

## 7. 파일·키·placeholder·HTML parity 결과

검증 도구: `tests/Translations/zh-CN-board-parity-check.php` (standalone, vendor·Laravel 불필요)

```
=== g7-module-sirsoft-board-zh-CN parity check ===
검사 파일 33개 (PHP 7 / JSON 24), 대조 키 3127개

파일 누락          OK (0)      초과 파일 OK (0)      구조 오류 OK (0)
JSON 구문          OK (0)      키 누락   OK (0)      초과 키   OK (0)
키 순서 불일치     OK (0)      자료형    OK (0)      placeholder OK (0)
HTML 태그 불일치   OK (0)      URL       OK (0)      빈 값     OK (0)
개행 개수 불일치   OK (0)      한글 잔존 OK (0)      가나 잔존 OK (0)
인코딩 오류        OK (0)      BOM       OK (0)      $partial  OK (0)

--- 표기 스타일 경고 (실패 아님) ---
없음

RESULT: PASS — 위반 0건        (exit code 0)
```

### 파일 목록 parity (독립 확인)

```
backend         ko=7  zh=7   → 파일명 완전 일치
frontend partial ko=18 zh=18 → 파일명 완전 일치 (admin/ 서브디렉토리 재귀 포함)
ja 팩 구조 대조  33 = 33     → 경로 1:1 완전 일치
```

> 검사기의 「대조 키 3,127」은 한국어 원본과 1:1로 짝지어 비교한 키 수입니다.
> §6 의 3,212는 팩이 실제 보유한 leaf 키 총수이며, 차이(85)는 seed입니다.
> seed 원본은 `module.php`·시더에 분산되어 기계적 1:1 대조 대상이 아니므로
> ja 팩과의 키 대칭 + 구문·인코딩·잔존 문자로 검증합니다.

### 보존한 형태 (모두 원문 그대로)

| 형태 | 예시 |
|---|---|
| Laravel `:placeholder` | `:board_name`, `:post_title`, `:comment_author`, `:count`, `:min`, `:max`, `:action_type` |
| 결합형 placeholder | `:maxKB`(`validation.json`), `:maxMB` — 붙여 쓴 형태 유지 |
| 공백 분리형 | `:max KB`, `:min MB`(`validation.php`) |
| Mustache `{{var}}` | `{{count}}`, `{{name}}`, `{{total}}`, `{{from}}`, `{{to}}`, `{{date}}`, `{{status}}`, `{{author}}`, `{{maxFiles}}`, `{{maxSize}}`, `{{category}}`, `{{cancelled_at}}`, `{{posts_count}}` |
| 단일 중괄호 `{var}` | `{count}`(`reports.detail.report_count_with_unit`), `{minutes}`, `{reason}`, `{board_name}`, `{post_url}`, `{site_url}`, `{app_name}`, `{site_name}` |
| 메일 인라인 HTML | `<table role="presentation" …>`, `<blockquote style="…">`, `<a href="{post_url}" style="…">`, `<strong>`, `<h1>`, `<br>` — 태그·속성·인라인 CSS 전부 원문 유지, 텍스트 노드만 번역 |
| 이모지·기호 | `⚠️`, `ℹ`, `※` |
| 후행 공백 | `"report_target": "举报对象： "` (원문의 끝 공백 보존) |
| route/permission/설정 키 | `sirsoft-board.reports.manage`, `basic_defaults.*`, `admin.posts.read-secret` 등 — 키는 번역 대상 아님 |

### 검사기가 실제로 잡은 결함 1건 (수정 완료)

`frontend/partial/admin.json` 초안 작성 시, 같은 dot-path 를 분할 partial
(`admin/settings.json`)에서 가져오는 경로 매핑을 썼는데 —
`admin.json` 의 `settings.fields.descriptions.*` **11개 키는 ko 원문에 `({{min}}~{{max}})` 접미가
없고** 분할 파일에는 있습니다. 매핑 결과가 원본에 없는 placeholder를 갖게 되어
`placeholder 불일치 FAIL (11)` 로 검출되었고, 11건 모두 `admin.json` 의 ko 원문 기준으로
다시 번역해 해소했습니다. **경로 매핑을 맹신하지 않고 원본과 대조한 것이 결함을 잡았습니다.**

---

## 8. 한글·가나 잔존 결과

```
검사 대상 문자열 값: 3,212개 (backend + frontend + seed 전수)
한글 잔존:            0
일본어 가나 잔존:     0
RESULT: PASS
```

- 한글 검출 범위: 음절 `U+AC00–U+D7A3`, 자모 `U+1100–U+11FF`, 호환 자모 `U+3130–U+318F`
- 가나 검출 범위: 히라가나 `U+3040–U+309F`, 가타카나 `U+30A0–U+30FF`

일본어팩을 재번역하지 않고 한국어 원본에서 직접 번역했으므로 가나 오염이 원리적으로
발생하지 않았고, 전수 검사로도 확인했습니다.

> **검사 제외 대상**(의도적): `language-pack.json` 의 `name.ko`/`description.ko` 와 `CHANGELOG.md` 본문.
> 번들 언어팩 규약상 매니페스트 다국어 객체는 `ko` 키를 포함하고 CHANGELOG 는 한국어로 작성합니다.

### 게시판 도메인 용어집 (지정 용어 전수 반영)

| 한국어 | 채택 역어 | 지정 | 비고 |
|---|---|---|---|
| 게시판 | **版块** | ✅ | 문맥 무관 통일. `论坛`(포럼 전체)·`留言板`(방명록)은 이 모듈의 의미와 달라 미사용 |
| 게시글 | 帖子 | ✅ | |
| 댓글 | 评论 | ✅ | |
| 답글 / 답변글 | 回复 / 回复帖 | ✅ | 게시글에 달리는 답변 게시물 |
| 대댓글 | 评论回复 | — | 댓글에 달리는 답글 — `帖子回复`(답변글)와 구분하기 위해 도입 |
| 작성자 | 作者 | ✅ | |
| 조회수 | 浏览量 | ✅ | |
| 공지 | 公告 | ✅ | |
| 비밀글 | 私密帖 | ✅ | |
| 첨부파일 | 附件 | ✅ | |
| 신고 | 举报 | ✅ | |
| 권한 | 权限 | ✅ | |
| 관리자 | 管理员 | ✅ | |
| 사용자 | 用户 | ✅ | |
| 회원 | 会员 | ✅ | `permissions_table.role_user` 등 역할 라벨에서 사용 |
| 검색 | 搜索 | ✅ | |
| 카테고리 / 분류 | 分类 | ✅ | |
| 스킨·템플릿 | 模板 | ✅ | 코어 zh-CN 팩과 동일 |

#### 코어 zh-CN 팩에서 승계한 표기 (일관성)

`설정 → 设置`, `환경설정 → 系统设置`, `역할 → 角色`, `알림 → 通知`, `모듈 → 模块`,
`슬러그 → 别名`, `활성화/비활성화 → 启用/停用`, `비회원 → 游客`, `본인인증 → 实名认证`,
`복원 → 恢复`, `코어 → 核心`.

#### 이 팩에서 새로 고정한 표기

| 한국어 | 역어 | 사유 |
|---|---|---|
| 블라인드 | **屏蔽** (상태 라벨 `已屏蔽`) | 중국 커뮤니티에서 통용되는 표기. `隐藏`은 일반 숨김과 혼동 |
| 스텝 (게시판 보조 관리자) | **协管** | 중국 포럼의 표준 호칭. `步骤`(단계)와의 오역 방지 |
| 게시중단 | **下架** | 콘텐츠 노출 중단의 표준 표현 |
| 반려 | **驳回** | |
| 금지어 / 차단 키워드 | **违禁词** / **屏蔽关键词** | 원문이 두 표기를 구분하므로 그대로 분리 |

---

## 9. PHP / JSON / manifest / 인코딩 / 보안 테스트 결과

### 9-1. PHP 문법 (`php -l`)

```
7/7 — No syntax errors detected
tests/Translations/zh-CN-board-parity-check.php                     → OK
tests/Unit/.../BundledSimplifiedChineseBoardPackTest.php            → OK
```

### 9-2. JSON 파싱

```
25/25 valid JSON
(language-pack.json, frontend 20종, seed 5종 — admin/ 서브디렉토리 재귀 포함)
```

### 9-3. manifest validator

```
identifier          g7-module-sirsoft-board-zh-CN
naming formula      g7-module-sirsoft-board-zh-CN  [MATCH]
scope / target      module / sirsoft-board
locale / BCP-47     zh-CN  [PASS]
version / license   1.0.0 / MIT
g7_version          >=7.0.0
locale_name/native  Simplified Chinese / 简体中文
text_direction      ltr
depends_on_core     true
name locales        ko,en,zh-CN
RESULT: PASS — 전 규칙 충족
```

교차 확인: `module.json identifier = sirsoft-board` ↔ manifest `target_identifier` 일치.
디렉터리 `g7-module-sirsoft-board-zh-CN`, `backend/zh-CN`, `frontend/zh-CN.json`
— 대소문자 포함 일치(Windows→Linux 배포 시 `zh-cn` 으로 눕는 사고 없음).

### 9-4. 인코딩

```
files=33  BOM=0  invalid-UTF8=0  CRLF=0  → PASS (UTF-8 without BOM, LF)
```

### 9-5. 저장소 보안 규칙

```
scanned: 7 php + 26 json/md
RESULT: PASS
  - 확장자 화이트리스트 (.php/.json/.md) 준수
  - PHP 위치 강제 backend/zh-CN/{group}.php 준수
  - 심볼릭 링크 0건
  - 변수·함수호출·백틱·heredoc·new·match 등 비허용 토큰 0건
```

PHP 실행 코드·동적 함수 호출·외부 네트워크 요청·심볼릭 링크 **0건**.
모든 PHP 파일이 `<?php return [ … ];` 단일 형태입니다.

### 9-6. frontend build 필요 여부 — **불필요 (증거 기반)**

추정하지 않고 다음 4가지를 실제로 확인했습니다.

| # | 증거 | 확인 결과 |
|---|---|---|
| 1 | `modules/_bundled/sirsoft-board/package.json` 의 `scripts` | `test`, `test:run`, `test:e2e`, `test:e2e:ui` — **build 스크립트 자체가 없음** |
| 2 | 모듈 루트의 빌드 설정 파일 | `vitest.config.ts` 뿐 (테스트용). vite 빌드 설정 없음 |
| 3 | `vite.config*.js`(루트)에서 모듈 lang 참조 | **0건** — 언어 JSON 은 번들에 포함되지 않음 |
| 4 | 언어팩 frontend 로딩 경로 | `app/Listeners/LanguagePack/MergeFrontendLanguage.php:143,158,180` 이 `$pack->resolveDirectory()` 아래 JSON 을 `File::get()` 으로 **런타임에 디스크에서 읽어 병합**. 서빙 라우트는 `routes/api.php:103` `templates/{identifier}/lang/{locale}.json` |
| 5 | `AGENTS.md:1120` 빌드 매트릭스 | `lang-packs/_bundled/**` → `language-pack:update {id} --force` **(빌드 불필요)** |

→ `npm install/update`, `composer install/update`, `npm run build` **모두 수행하지 않았습니다.**

---

## 10. 실행하지 못한 테스트와 이유

### PHPUnit — **실행 불가 (환경 제약, 성공으로 간주하지 않음)**

| 사유 | 상세 |
|---|---|
| Composer 의존성 부재 | `vendor/autoload.php` 없음, `vendor/bin/phpunit` 없음 |
| PHP 버전 미달 | 로컬 `PHP 7.4.22` / `composer.json` 요구 `^8.2` |

지시에 따라 **패키지를 설치하지 않았습니다.**
같은 제약으로 프로덕션 `LanguagePackPhpArrayValidator` 클래스도 직접 호출할 수 없어
(PHP 8 문법 `catch (ParseError)` 사용), §9-5 의 토큰 화이트리스트 검사로 동일 계약을 근사 검증했습니다.

**로컬에서 실제 실행한 것**: parity 검사기(exit 0), `php -l` 7/7, JSON 파싱 25/25,
manifest 규칙 재현 검증, 보안 규칙 근사 검증, 인코딩 검사 — 모두 통과.

### 서버에서 실행할 정확한 명령

```bash
composer install --no-interaction --prefer-dist

# 게시판 zh-CN 팩 전용 (프로덕션 validator 실호출)
php artisan test --filter=BundledSimplifiedChineseBoardPackTest

# 코어 zh-CN 팩 (기존)
php artisan test --filter=BundledSimplifiedChinesePackTest

# 언어팩 시스템 회귀 전체
php artisan test tests/Unit/Services/LanguagePack
php artisan test tests/Feature/LanguagePack

# 게시판 모듈 자체 회귀 (언어팩 시더 트리거 포함)
php artisan test modules/_bundled/sirsoft-board/tests

# 기존 ja 팩이 깨지지 않았는지
php artisan test --filter=BundledJapanesePacksTest

# 원본 대조 검사기 (vendor 불필요 — 어디서나 단독 실행)
php tests/Translations/zh-CN-board-parity-check.php --verbose --style

# 코드 스타일
./vendor/bin/pint --test tests/Unit/Services/LanguagePack/BundledSimplifiedChineseBoardPackTest.php
./vendor/bin/pint --test tests/Translations/zh-CN-board-parity-check.php
```

기대 결과: 테스트 전부 green, 검사기 `RESULT: PASS — 위반 0건` (exit 0).

> 테스트를 위해 운영 DB·로컬 개발 DB를 수정하지 않았습니다. 위 PHPUnit 명령 중
> `tests/Feature/LanguagePack` 와 모듈 테스트는 `RefreshDatabase` 를 쓰는 테스트 DB 대상입니다.

---

## 11. 기존 파일 수정 목록과 변경 이유

허용된 4개 문서만 수정했습니다.

| 파일 | 변경 | 이유 |
|---|---|---|
| `README.md` | +1 | 번들 언어팩 인벤토리 표에 신규 팩 1행 추가. 표는 식별자 알파벳 순이므로 `g7-module-sirsoft-board-ja` 다음(`ja` < `zh-CN`), `g7-module-sirsoft-ecommerce-ja` 앞에 삽입 |
| `README.ko.md` | +1 | 동일 위치·동일 정렬 규칙 |
| `CHANGELOG.md` | +1 | 기존 `[Unreleased] → Added` 아래에 1줄 추가. **7.0.9 이하 릴리스 내용은 소급 변경 0** (삭제 0줄) |
| `docs/extension/language-packs.md` | +101 / −6 | 기존 zh-CN 섹션을 「코어 전용」에서 「코어 + 게시판」으로 확장 |

### `docs/extension/language-packs.md` 의 −6줄 상세 (내용 손실 없음)

교체된 6줄은 zh-CN 섹션의 **제목·도입 문단·패키지 구성 표 헤더/행**입니다.

- 제목 `## 공식 중국어 간체 번들 언어팩 (g7-core-zh-CN)` → `(g7-*-zh-CN)`
- 도입 「제공 범위는 **코어 1종**이며, 템플릿/모듈/플러그인 zh-CN 팩은 아직 없다」
  → 「**코어 1종 + 게시판 모듈 1종**이며, 이커머스/페이지/플러그인/템플릿 zh-CN 팩은 아직 없다」
- 구성 표에 `파일 수` 열과 module 행 추가
- 「기존 ko/en 변경 시 zh-CN 동기화 의무」를 두 팩 모두 커버하도록 갱신

신규 추가한 내용: `### 게시판 모듈 팩 (g7-module-sirsoft-board-zh-CN)` 서브섹션
— 입력→산출 매핑, 게시판 도메인 용어집, ja 팩과의 의도적 차이, 설치·활성화 명령, 검증 명령,
frontend build 불필요 근거.

---

## 12. 예상하지 못한 변경

**예상 밖의 변경 없음.** 다만 조사 중 발견해 기록해 둘 사항 2건이 있습니다.

### 12-1. ja 팩의 `permissions.json` 이 ko 원본보다 오래됨 — zh-CN 은 원본 기준으로 작성

`module.php::getPermissions()` 는 현재 **5개 카테고리 / 17개 권한 키**를 선언합니다
(`boards`, `settings`, `identity.policies`, **`dashboard`**, `reports`).
그런데 `g7-module-sirsoft-board-ja/seed/permissions.json` 은 **15개 키**뿐이고
`sirsoft-board.dashboard`, `sirsoft-board.dashboard.view` 2개가 **누락**되어 있습니다.

- 지시(「번역 원문은 반드시 게시판 모듈의 한국어 원본을 사용」)에 따라
  **zh-CN 팩은 ko 원본 기준 17개 키**를 갖습니다 — ja 팩보다 완전합니다.
- parity 검사기는 이 차이를 `$intentionalSeedExtras` 상수에 **명시적으로 선언**해
  「초과 키」로 오탐하지 않으며, 그 이유를 주석으로 코드에 남겼습니다.
- **ja 팩 동기화는 이번 범위 밖**이며 손대지 않았습니다. 별도 이슈 권장.

### 12-2. `partial/admin.json` 과 `partial/admin/*.json` 의 내용 불일치 (모듈 원본의 상태)

모듈의 ko 원본에서 `admin.json` 은 `admin/*.json` 분할 파일들의 상위 묶음이지만
**완전히 동일하지 않습니다** — `admin.json` 에만 있는 키(`modals.copy.info`,
`form.modals.category_remove.*`, `settings.notification_definitions.btn_*` 등 15개)가 있고,
같은 경로라도 값이 다른 키(`settings.fields.descriptions.*` 11개)가 있습니다.

- zh-CN 팩은 **각 파일의 ko 원문을 각각 기준**으로 번역했으므로 이 차이를 그대로 보존합니다.
- 모듈 원본 쪽 정리는 이번 범위 밖입니다(모듈 파일 수정 금지).

### 12-3. 저장소에 포함하지 않은 임시 파일

`build_admin_json.php`, `emit_admin_json.php`, `seccheck_board.php` 는 `admin.json` 초안 생성과
보안 규칙 사전 점검용 **일회성 스크립트**로, 세션 스크래치패드(`%TEMP%` 하위)에만 존재하며
저장소에 **포함되지 않습니다**(신규 파일 35건에 미포함 확인).

---

## 13. `git status --short` 전체

```
 M CHANGELOG.md
 M README.ko.md
 M README.md
 M docs/extension/language-packs.md
?? docs/reports/zh-CN-board-language-pack-report.md
?? lang-packs/_bundled/g7-module-sirsoft-board-zh-CN/
?? tests/Translations/zh-CN-board-parity-check.php
?? tests/Unit/Services/LanguagePack/BundledSimplifiedChineseBoardPackTest.php
```

- 수정(`M`) **4건** — 전부 허용된 문서
- 신규(`??`) — 언어팩은 디렉터리 단위 표기이며 실제 파일 **36개**
- 삭제(`D`) **0건**, 이름변경(`R`) **0건**

---

## 14. GitHub Desktop 에서 커밋 가능 여부

## ✅ **커밋 가능**

| 확인 항목 | 결과 |
|---|---|
| 허용 경로 밖 신규 파일 | 0건 |
| 허용 4개 문서 외 기존 파일 수정 | 0건 |
| 삭제·이름변경 | 0건 |
| package/composer/빌드 설정 변경 | 0건 |
| 코어·모듈·플러그인·템플릿·기존 언어팩 수정 | 0건 |
| `git diff --check` | clean |
| 인코딩 (UTF-8 / BOM / CRLF) | 33파일 전부 정상 |
| PHP·JSON 구문 | 7/7, 25/25 |
| parity | 위반 0 |
| 보안 규칙 | 위반 0 |

**스테이징 대상**:

```
M  CHANGELOG.md
M  README.ko.md
M  README.md
M  docs/extension/language-packs.md
A  docs/reports/zh-CN-board-language-pack-report.md
A  lang-packs/_bundled/g7-module-sirsoft-board-zh-CN/**          (33 files)
A  tests/Translations/zh-CN-board-parity-check.php
A  tests/Unit/Services/LanguagePack/BundledSimplifiedChineseBoardPackTest.php
```

**주의**: 언어팩은 신규 디렉터리라 GitHub Desktop 이 접어서 보여줄 수 있습니다.
펼쳐서 임시 스크립트가 섞이지 않았는지 확인하십시오(현재 저장소에는 없습니다).

---

## 15. 권장 커밋 제목

```
feat(lang-packs): 게시판 모듈 중국어 간체(zh-CN) 언어팩 추가
```

전체 커밋 메시지 예시:

```
feat(lang-packs): 게시판 모듈 중국어 간체(zh-CN) 언어팩 추가

게시판 모듈(sirsoft-board)의 중국어 간체 언어팩
g7-module-sirsoft-board-zh-CN 을 신규 제공한다. 관리자 화면의 게시판·
게시글·신고 관리와 사용자 화면 문구, 게시판 알림 메일이 중국 본토 표기
기준의 간체 중국어로 표시된다.

번역 기준은 게시판 모듈의 한국어 원본(src/lang/ko, resources/lang/ko.json,
resources/lang/partial/ko, module.php, BoardTypeSeeder)이다. 일본어 팩은
디렉터리 구조와 매니페스트 형식의 참조로만 사용했고 재번역 소스로 쓰지
않았다.

- backend 7종·frontend 19종·seed 5종, 총 33파일 (ja 팩과 경로 1:1)
- 키 3,212개. 원본 대비 키 누락·초과·순서·자료형 불일치 0
- placeholder·HTML·URL·개행 보존 검증 통과, 한글/가나 잔존 0
- scope=module 이므로 depends_on_core_locale=true — g7-core-zh-CN 선행 필요
- 모듈 코드·DB·라우트·권한 키·설정 키 변경 0, frontend build 불필요

seed/permissions.json 은 ko 원본 기준 17개 키를 담는다. 기존 ja 팩은
dashboard 권한 2개가 빠진 15개로 원본보다 오래된 상태이며, parity 검사기가
이 차이를 의도된 것으로 명시해 오탐하지 않는다.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
```

> 커밋·푸시는 수행하지 않았습니다. 위 메시지는 제안이며 실행은 담당자 판단입니다.

---

## 16. 서버에서 실행할 검증 · 설치 명령

### 16-1. 검증 (설치 전) — §10 참조

### 16-2. 설치 · 활성화

```bash
# 1) 코어 zh-CN 팩이 먼저 활성이어야 한다 (depends_on_core_locale: true)
php artisan language-pack:list --scope=core       # g7-core-zh-CN 이 active 인지 확인

# 2) 대상 모듈이 active 여야 한다 (아니면 target_inactive 로 차단)
php artisan module:list                           # sirsoft-board 가 active 인지 확인

# 3) 게시판 zh-CN 팩 설치 (자동 활성)
php artisan language-pack:install g7-module-sirsoft-board-zh-CN --source=bundled

# 확인
php artisan language-pack:list --scope=module

# 콘텐츠 수정 후 설치본 재반영 (프론트엔드 빌드 불필요)
php artisan language-pack:update g7-module-sirsoft-board-zh-CN --force
```

> `_bundled` 는 배포 원본일 뿐입니다. 설치본(`lang-packs/g7-module-sirsoft-board-zh-CN/`)을
> 갱신하지 않으면 새 번역 키가 런타임에 존재하지 않아 **오류 없이 조용히 ko 로 폴백**합니다.

### 16-3. 설치 차단 사유별 대응

| 사유 키 | 원인 | 대응 |
|---|---|---|
| `core_locale_missing` | `g7-core-zh-CN` 미활성 | 코어 팩 먼저 활성화 |
| `target_not_installed` | `sirsoft-board` 가 DB 에 없음 | 모듈 설치 |
| `target_inactive` | 모듈이 active 아님 | `module:activate sirsoft-board` |
| `target_version_too_old` | 해당 없음 | manifest `requires.target_version` 이 `null` 이라 미적용 |

### 16-4. 빌드 · 코어 수정 필요 여부

| 항목 | 판정 |
|---|---|
| frontend build (`npm run build`) | **불필요** (§9-6 증거 5종) |
| `composer install/update`, `npm install/update` | **불필요** (신규 의존성 0) |
| 코어·모듈 코드 수정 | **불필요 (0건)** |
| `config/app.php` 수정 | **불필요** — provider 가 활성 코어 팩 기준으로 동적 갱신 |
| migration / DB 스키마 | **불필요** |

---

## 17. 남은 위험 요소

| # | 등급 | 내용 | 대응 |
|---|---|---|---|
| 1 | 🟠 | PHPUnit 미실행 — 프로덕션 클래스 호출 경로가 로컬에서 미검증 | 서버에서 §10 실행 |
| 2 | 🟡 | 번역의 **문맥 적합성**은 정적 검사로 확인 불가 (키·placeholder·구조만 보장) | 설치 후 게시판 관리·게시글·신고 화면 육안 검수 |
| 3 | 🟡 | ja 팩 `permissions.json` 의 dashboard 권한 누락 (§12-1) 미해결 | 별도 이슈 |
| 4 | 🟢 | 모듈 원본의 `admin.json` ↔ `admin/*.json` 불일치 (§12-2) | 모듈 측 정리 과제 |
| 5 | 🟢 | 다음 단계(이커머스·페이지·플러그인·템플릿 zh-CN) 착수 시 §8 용어집 승계 필요 | 본 보고서와 `language-packs.md` 를 SSoT 로 사용 |

**BLOCKER 없음.**

---

## 18. 보고서 파일 경로

```
docs/reports/zh-CN-board-language-pack-report.md
```

UTF-8 without BOM, LF 줄바꿈.

---

## 19. PowerShell 클립보드 복사 명령

```powershell
Get-Content -Raw -Encoding UTF8 "docs\reports\zh-CN-board-language-pack-report.md" | Set-Clipboard
```
