# g7-core-zh-CN 중국어 간체 코어 언어팩 — 구현 및 문서 반영 보고서

- **작성일**: 2026-09-07
- **대상**: G7 7.0.9 (YUTIV)
- **범위**: `g7-core-zh-CN` 코어 언어팩 구현 + 문서 반영 + 커밋 전 최종 감사
- **저장소 상태**: branch `main` / HEAD `56836a2b66c3bc4c3ae84ab1ae983248bb6d40b0` / tag `7.0.9`
- **커밋 상태**: **미커밋** (commit·push·pull·checkout·branch 생성 없음)

---

## 1. 구현 및 문서 반영 완료 여부

**완료.**

| 단계 | 내용 | 상태 |
|---|---|---|
| 1단계 | `lang-packs/_bundled/g7-core-zh-CN/` 코어 언어팩 50개 파일 생성 | ✅ |
| 1단계 | 검증 파일 2종 (PHPUnit 테스트 + standalone parity 검사기) | ✅ |
| 2단계 | `README.md` · `README.ko.md` 인벤토리 반영 | ✅ |
| 2단계 | `docs/extension/language-packs.md` zh-CN 섹션 신설 | ✅ |
| 2단계 | `CHANGELOG.md` `[Unreleased]` 섹션 신설 및 Added 기재 | ✅ |
| 2단계 | 커밋 전 최종 감사 19항목 | ✅ 전부 통과 |

구조 기준은 `lang-packs/_bundled/g7-core-ja` 이지만, 번역 내용의 기준은 일본어팩이 아니라
**한국어 코어 원본**(`lang/ko/`, `lang/ko.json`, `lang/partial/ko/`, `config/core.php`)입니다.

기존 일본어팩의 오역은 지시에 따라 **수정하지 않았습니다**(§7-1).
템플릿·모듈·플러그인 중국어팩은 생성하지 않았고, 언어팩 설치·활성화도 실행하지 않았습니다.

---

## 2. 최종 GO / NO-GO

## ✅ **GO** — BLOCKER 없음

| 판정 축 | 결과 |
|---|---|
| 언어팩 파일 수 | **정확히 50개** |
| ja 팩 구조 미러링 | 경로 1:1 완전 일치 |
| 원본 키 대칭 | 누락 0 · 초과 0 · 순서 0 · 자료형 0 |
| placeholder / HTML / URL | 불일치 **0** |
| 한글·일본어 잔존 | **0** (4,199개 값 전수) |
| PHP lint | **40/40** 통과 |
| JSON 파싱 | **9/9** 통과 |
| manifest validator | 전 규칙 통과 |
| 보안 규칙 | 위반 **0** |
| 인코딩 | 57파일 UTF-8 / BOM 0 / CRLF 0 |
| 허용 경로 밖 신규 파일 | **0건** |
| 허용 4개 문서 외 기존 파일 수정 | **0건** |
| package / composer 변경 | **0건** |
| 비밀정보 | **0건** |
| 대용량·바이너리 파일 | **0건** |
| 코어 수정 | **0건** |
| frontend build | **불필요** |

유일한 미완 항목은 PHPUnit 로컬 실행 불가(§6-15) — 환경 제약이며 결함이 아닙니다.
서버에서 1회 실행 후 최종 확정을 권장합니다.

---

## 3. 생성·수정·삭제 파일 수

| 구분 | 개수 |
|---|---|
| **생성** | **53** |
| **수정** | **4** |
| **삭제** | **0** |
| 이름변경 | 0 |

`git diff --stat` — 수정 4개 파일 **115 insertions(+), 0 deletions(-)** (전부 순수 추가):

```
 CHANGELOG.md                     |   6 +++
 README.ko.md                     |   1 +
 README.md                        |   1 +
 docs/extension/language-packs.md | 107 +++++++++++++++++++++++++++++++++++++++
 4 files changed, 115 insertions(+)
```

### 생성 파일 53개 내역

| 경로 | 개수 |
|---|---|
| `lang-packs/_bundled/g7-core-zh-CN/language-pack.json` | 1 |
| `lang-packs/_bundled/g7-core-zh-CN/CHANGELOG.md` | 1 |
| `lang-packs/_bundled/g7-core-zh-CN/backend/zh-CN/*.php` | 40 |
| `lang-packs/_bundled/g7-core-zh-CN/frontend/zh-CN.json` | 1 |
| `lang-packs/_bundled/g7-core-zh-CN/frontend/partial/*.json` | 2 |
| `lang-packs/_bundled/g7-core-zh-CN/seed/*.json` | 5 |
| **언어팩 소계** | **50** |
| `tests/Unit/Services/LanguagePack/BundledSimplifiedChinesePackTest.php` | 1 |
| `tests/Translations/zh-CN-core-parity-check.php` | 1 |
| `docs/reports/zh-CN-core-language-pack-report.md` (본 문서) | 1 |
| **총계** | **53** |

**코어·애플리케이션 로직, `config/app.php`, migration, 한국어·영어·일본어 번역 파일은 한 줄도 수정하지 않았습니다.**

---

## 4. 수정한 기존 파일 목록

허용된 4개 문서만 수정했으며, 전부 **순수 추가**입니다(삭제 0줄).

### 4-1. `README.md` (+1줄)

`### Bundled language packs` 인벤토리 표에 1행 추가. 기존 표는 식별자 알파벳 순이므로
`g7-core-ja` 다음, `g7-module-*` 앞에 삽입했습니다.

```diff
 | **g7-core-ja** | Core, Japanese |
+| **g7-core-zh-CN** | Core, Simplified Chinese |
 | **g7-module-sirsoft-board-ja** | Board module, Japanese |
```

### 4-2. `README.ko.md` (+1줄)

동일 위치·동일 정렬 규칙.

```diff
 | **g7-core-ja** | 코어 일본어 |
+| **g7-core-zh-CN** | 코어 중국어 간체 |
 | **g7-module-sirsoft-board-ja** | 게시판 모듈 일본어 |
```

> 두 표 모두 열 수(파이프 3개) 균일, 행 20개, 정합성 불일치 0으로 확인했습니다.

### 4-3. `docs/extension/language-packs.md` (+107줄)

`## 공식 중국어 간체 번들 언어팩 (g7-core-zh-CN)` 섹션을 신설했습니다.
**삽입 위치**: 일본어팩 `##` 섹션(그 하위의 「외부 언어팩 패키지 개발 · 배포」 포함)이 끝난 직후,
`## 의존성 검증 — 설치 차단 사유` 앞. 기존 `###` 소제목의 소속을 바꾸지 않는 유일한 위치입니다.

수록 내용 (모두 **실제 구현된 것만**):

- 패키지 구성 (코어 1종, 템플릿/모듈/플러그인 zh-CN 팩은 아직 없음을 명시)
- locale 코드 근거 — `zh_CN`·`zh-cn`·`zh-Hans` 가 거부되는 정확한 코드 위치, `zh-TW` 병행 가능성
- 입력 → 산출 매핑 (ko 원본 → 산출 경로, 50파일 구성, `$partial` 경로 정규화 규칙)
- 번역 규칙 (보존 대상, 결합형 placeholder, 이모지, 전각 문장부호 규칙, URL 경계 규칙)
- 용어집 22항목 + 판단이 갈린 2항목의 근거
- 정적 검사 2종의 역할 구분과 「새 로케일 팩 추가 시 검사도 함께 추가」 의무
- 설치·활성화 명령, `config/app.php` 미편집 근거
- 기존 ko/en 변경 시 zh-CN 동기화 의무

일본어팩 관련 기존 서술은 **한 줄도 변경하지 않았습니다**(§7-2 참조).

### 4-4. `CHANGELOG.md` (+6줄)

`[Unreleased]` 섹션이 **존재하지 않아** 신규 생성했습니다.

```diff
 [Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.
 
+## [Unreleased]
+
+### Added
+
+- 중국어 간체(Simplified Chinese) 코어 언어팩(`g7-core-zh-CN`)이 추가되었습니다. …
+
 ## [7.0.9] - 2026-08-24
```

**위치 선정 이유** (요구사항 3):

1. 파일 머리말이 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/) 준수를 명시하고 있고,
   해당 표준은 미출시 변경을 최상단 `## [Unreleased]` 에 모으도록 규정합니다.
2. 기존 파일은 버전 섹션이 **내림차순**(7.0.9 → 7.0.8 → 7.0.7 …)입니다.
   머리말 블록과 최신 릴리스 사이가 내림차순을 유지하는 유일한 삽입점입니다.
3. **7.0.9 섹션을 포함해 기존 내용은 소급 변경하지 않았습니다** — `git diff --numstat` 기준
   `CHANGELOG.md` 의 삭제 줄 수 **0**, 추가 6줄뿐이며 섹션 순서는
   `[Unreleased] → [7.0.9] → [7.0.8] → [7.0.7]` 로 정상입니다.
4. 본문은 파일의 기존 톤(한국어·사용자 관점·1~2줄 불릿·내부 경로/함수명/이슈번호 미기재)을 따랐습니다.

---

## 5. 신규 파일 전체 분류

총 **53개**, 전부 허용 경로 안 (허용 경로 밖 **0건**).

### 5-1. 언어팩 (50개) — `lang-packs/_bundled/g7-core-zh-CN/`

| 계층 | 파일 |
|---|---|
| 루트 (2) | `language-pack.json`, `CHANGELOG.md` |
| `backend/zh-CN/` (40) | `activity_log`, `admin_layout`, `asset_url_mode`, `attachment`, `auth`, `common`, `dashboard`, `database_credential`, `devtools`, `errors`, `exceptions`, `extension_owner_type`, `extensions`, `hooks`, `identity`, `identity_message`, `language_packs`, `layout_extension`, `layouts`, `maintenance`, `menu`, `module`, `modules`, `nav`, `notification`, `notification_log`, `pagination`, `permission`, `plugins`, `role`, `schedule`, `search`, `seo`, `settings`, `templates`, `theme`, `themes`, `user`, `validation`, `vendor` (`.php`) |
| `frontend/` (3) | `zh-CN.json`, `partial/errors.json`, `partial/layout_editor.json` |
| `seed/` (5) | `permissions.json`, `roles.json`, `menus.json`, `notifications.json`, `identity_messages.json` |

### 5-2. 검증 파일 (2개)

| 파일 | 성격 | 용도 |
|---|---|---|
| `tests/Unit/Services/LanguagePack/BundledSimplifiedChinesePackTest.php` | PHPUnit | **프로덕션 클래스 실호출** (`LanguagePackManifestValidator`, `LanguagePackPhpArrayValidator`). manifest 검증 · 필드 사양 · 콘텐츠 존재 · PHP 보안 검사 · ko 키 대칭 · 한글 잔존 · `$partial` 실재. 릴리즈 게이트 역할 |
| `tests/Translations/zh-CN-core-parity-check.php` | standalone CLI | Laravel 부팅·Composer autoload 불필요. 파일 인벤토리 · 키 누락/초과 · 키 순서 · 자료형 · placeholder · HTML · URL · 빈 값 · 개행 · 한글/가나 잔존 · UTF-8/BOM/CRLF · `$partial` 경로. 옵션 `--verbose`, `--style`. PHP 8 문자열 헬퍼 폴리필 포함 |

### 5-3. 보고서 (1개)

`docs/reports/zh-CN-core-language-pack-report.md` — 본 문서.

### 5-4. 저장소에 포함되지 않은 임시 파일

`extract_seed_ko.php`, `extract_notif_ko.php`, `extract_idm_ko.php`, `seccheck.php` 는
seed 한국어 원문 추출 및 보안 규칙 사전 점검용 **일회성 스크립트**로,
세션 스크래치패드(`%TEMP%` 하위)에만 존재하며 저장소에 **포함되지 않습니다**
(`git status` 신규 53건에 미포함 확인).

---

## 6. 재검증 결과

커밋 전 최종 감사 **19항목 전부 통과**.

### 6-1. `git diff --check`

```
clean — no whitespace errors / conflict markers
```
`git diff --cached --check` 도 clean.

### 6-2. `git status --short`

§8 참조. 수정 4 (`M`) + 신규 4 디렉터리/파일 (`??`).

### 6-3. `git diff --stat`

4 files changed, **115 insertions(+), 0 deletions(-)**.

### 6-4. `git diff -- README.md README.ko.md docs/extension/language-packs.md CHANGELOG.md`

전부 순수 추가. 표 행 삽입 2건, `[Unreleased]` 블록 1건, zh-CN 섹션 1건. 삭제 0줄. §4 에 diff 수록.

### 6-5. 언어팩 파일 수

```
count: 50  →  OK (== 50)
    2 (root)  /  40 backend/  /  3 frontend/  /  5 seed/
```

### 6-6. 신규 파일 전체 목록

```
총 신규 파일: 53
├ 언어팩:      50
├ 검증 파일:    2
└ 보고서:       1
```

### 6-7. 허용 경로 밖 신규 파일

```
0건 — 모든 신규 파일이 허용 경로 안
```

### 6-8. 허용 4개 문서 외 기존 추적 파일 수정

```
수정된 추적 파일: CHANGELOG.md / README.ko.md / README.md / docs/extension/language-packs.md
→ 허용 4개 문서 외 수정 0건 OK
삭제된 추적 파일: 0    이름변경: 0
```

### 6-9. package / composer 관련 파일 변경

`composer.json`, `composer.lock`, `package.json`, `package-lock.json`, `vendor-bundle.json`,
`vendor-bundle.zip`, `phpunit.xml`, `pint.json`, `vite.config.*.js`, `tsconfig.json`,
`vitest.config.ts`, `playwright.config.ts` 전수 확인 → **변경 0건**.

### 6-10. `.env` 및 비밀정보

```
.env 관련 신규/변경 파일 0건
비밀정보 패턴 0건 (APP_KEY / DB_PASSWORD / SECRET= / API_KEY= / PRIVATE KEY / ghp_ / AKIA / xox*)
```

번역문 안의 `DB_WRITE_USERNAME`·`COMPOSER_BINARY` 2건은 **환경변수 이름만** 등장하는
사용자 안내 문구로, 한국어 원본과 동일합니다(값 없음).

### 6-11. 대용량 / 바이너리 파일

```
1MB 초과 파일:  0건 (신규 파일 최대 69,943 B — validation.php)
바이너리 파일:  0건 — 전부 UTF-8 텍스트
git numstat 바이너리 diff: 0건
확장자 분포: php 42 / json 9 / md 6
```

### 6-12. manifest / identifier 재검증

```
identifier          g7-core-zh-CN
naming formula      g7-core-zh-CN  [MATCH]
locale / BCP-47     zh-CN  [PASS]
namespace / vendor  g7 / sirsoft
scope / target      core / NULL
version / license   1.0.0 / MIT
g7_version          >=7.0.0
locale_name         Simplified Chinese
locale_native_name  简体中文
text_direction      ltr
depends_on_core     false
name / desc locales ko,en,zh-CN | ko,en,zh-CN
RESULT: PASS — LanguagePackManifestValidator 전 규칙 충족
```

식별자 일관성 교차 확인: manifest `g7-core-zh-CN` ↔ 디렉터리명 `g7-core-zh-CN` ↔
`backend/zh-CN/` ↔ `frontend/zh-CN.json` ↔ README 2종 각 1행. **대소문자 `zh-CN` 정상**
(git 인덱스 기준 확인 — Windows→Linux 배포 시 `zh-cn` 으로 눕는 사고 없음).

### 6-13. PHP 40개 `php -l`

```
40/40 checked — No syntax errors detected
```
검증 파일 2종도 통과.

### 6-14. JSON 전체 파싱

```
9/9 valid JSON
(language-pack.json, frontend/zh-CN.json, partial/errors.json, partial/layout_editor.json,
 seed/{permissions,roles,menus,notifications,identity_messages}.json)
```

### 6-15. 번역 parity / placeholder / HTML / 잔존 검사

```
$ php tests/Translations/zh-CN-core-parity-check.php --verbose --style

=== g7-core-zh-CN parity check ===
검사 파일 50개 (PHP 40 / JSON 8), 대조 키 3961개

파일 누락 OK(0)        초과 파일 OK(0)      구조 오류 OK(0)
JSON 구문 OK(0)        키 누락 OK(0)        초과 키 OK(0)
키 순서 불일치 OK(0)   자료형 불일치 OK(0)  placeholder 불일치 OK(0)
HTML 태그 불일치 OK(0) URL 불일치 OK(0)     빈 값 불일치 OK(0)
개행 개수 불일치 OK(0) 한글 잔존 OK(0)      일본어 가나 잔존 OK(0)
인코딩 오류 OK(0)      BOM 검출 OK(0)       $partial 경로 오류 OK(0)

--- 표기 스타일 경고 (실패 아님) ---
없음

RESULT: PASS — 위반 0건        (exit code 0)
```

독립 전수 재검사(검사기와 별개 구현):

```
string values scanned: 4,199
hangul residue: 0
japanese kana residue: 0
RESULT: PASS
```

### 6-16. 인코딩 (언어팩 50 + 수정 4문서 + 검증 2 + 보고서)

```
files=57  BOM=0  invalid-UTF8=0  CRLF=0
RESULT: PASS — UTF-8 without BOM, LF
```

### 6-17. 보안 규칙

```
scanned: 40 php + 10 json/md
RESULT: PASS
  - 확장자 화이트리스트 (.php/.json/.md) 준수
  - PHP 위치 강제 backend/zh-CN/{group}.php 준수
  - 심볼릭 링크 0건
  - 변수·함수호출·백틱·heredoc·new·match 등 비허용 토큰 0건
```

### 6-18. CHANGELOG 소급 변경 없음

```
CHANGELOG.md 삭제 줄 수: 0
섹션 순서: [Unreleased] → [7.0.9] → [7.0.8] → [7.0.7]
```

### 6-19. PHPUnit — **실행 불가 (환경 제약)**

요구사항 7에 따라 **패키지를 설치하지 않았습니다.**

| 사유 | 상세 |
|---|---|
| Composer 의존성 부재 | `vendor/autoload.php` 없음, `vendor/bin/phpunit` 없음 (`vendor/` 자체가 비어 있음) |
| PHP 버전 미달 | 로컬 `PHP 7.4.22` / `composer.json` 요구 `^8.2` |

이 두 번째 제약 때문에 프로덕션 `LanguagePackPhpArrayValidator` 클래스도 직접 호출할 수 없었습니다
— 해당 클래스는 PHP 8 문법(인수 없는 `catch (ParseError)`)을 사용해 PHP 7.4 파서가 거부합니다.
대신 §6-17 의 토큰 화이트리스트 검사로 같은 계약을 근사 검증했습니다.
서버 실행 명령은 §11 참조.

---

## 7. 예상하지 못한 변경 여부

**예상 밖의 변경 없음.** 의도적으로 **수정하지 않은** 기존 결함 2건과 후속 과제 1건을 기록합니다.

### 7-1. 기존 `g7-core-ja` 팩의 언어명 오역 — 지시에 따라 미수정

`lang-packs/_bundled/g7-core-ja/frontend/partial/layout_editor.json` 의 `locale` 블록이
`"ko": "日本語"` 로 되어 있습니다. 한국어 원본은 `"ko": "한국어"` 이므로,
**일본어 화면의 언어 선택기에서 「한국어」 항목이 「日本語」로 표시되는 결함**입니다.
같은 팩의 `backend/ja/user.php` 는 `'ko' => '韓国語'` 로 올바라, 팩 내부에서도 불일치합니다.

- 요구사항 4에 따라 **수정하지 않았습니다.**
- zh-CN 팩은 이 결함을 반복하지 않았습니다 (`"ko": "韩语"`).
- **별도 이슈로 처리 권장** — 이번 커밋에는 포함되지 않습니다.

### 7-2. `docs/extension/language-packs.md` 의 「일본어 번들 12종」 기술 — 미수정

문서는 공식 일본어 번들을 「12종」으로 기술하나 `lang-packs/_bundled/` 실제 디렉터리는
**21종**(학습용 샘플 4종 포함)입니다.

- 이번 요구사항은 「중국어 간체 코어팩 정보를 추가」로 범위가 한정되어 있어,
  일본어팩 관련 기존 서술은 손대지 않았습니다.
- zh-CN 섹션은 독립된 `##` 블록으로 신설했으므로 이 불일치와 간섭하지 않습니다.
- **별도 이슈로 처리 권장.**

### 7-3. 정적 검사 사각지대 — 일부 해소, 통합은 후속 과제

`tests/Unit/Services/LanguagePack/BundledJapanesePacksTest.php` 는 검증 대상을
`private const PACKS = [...]` 로 **ja 12종 하드코딩**하고 있어, 새 로케일 팩은
manifest 검증조차 받지 못한 채 릴리즈될 수 있었습니다.
이번에 추가한 `BundledSimplifiedChinesePackTest` 가 zh-CN 에 대해 그 사각을 닫았고,
동일 취지를 `docs/extension/language-packs.md` 의 zh-CN 「정적 검사」 절에 명문화했습니다.

향후 로케일이 더 늘어나면 두 테스트를 **로케일 파라미터화된 단일 테스트로 통합**하는 편이
같은 사각의 재발을 막습니다 — 다음 단계 과제입니다.

---

## 8. `git status --short` 전체

```
 M CHANGELOG.md
 M README.ko.md
 M README.md
 M docs/extension/language-packs.md
?? docs/reports/
?? lang-packs/_bundled/g7-core-zh-CN/
?? tests/Translations/
?? tests/Unit/Services/LanguagePack/BundledSimplifiedChinesePackTest.php
```

- 수정(`M`) **4건** — 전부 허용된 문서
- 신규(`??`) — 디렉터리 단위 표기이며 실제 파일 **53개**
- 삭제(`D`) **0건**, 이름변경(`R`) **0건**

---

## 9. GitHub Desktop 에서 커밋해도 되는지

## ✅ **커밋 가능**

| 확인 항목 | 결과 |
|---|---|
| 허용 경로 밖 신규 파일 | 0건 |
| 허용 4개 문서 외 기존 파일 수정 | 0건 |
| 삭제·이름변경 | 0건 |
| package / composer / 빌드 설정 변경 | 0건 |
| `.env`·비밀정보·토큰·개인키 | 0건 |
| 대용량(1MB↑)·바이너리 파일 | 0건 |
| 공백 오류·충돌 마커 (`git diff --check`) | clean |
| 인코딩 (UTF-8 / BOM / CRLF) | 57파일 전부 정상 |
| PHP·JSON 구문 | 40/40, 9/9 |
| 번역 parity | 위반 0 |

**커밋 시 스테이징할 항목** (GitHub Desktop 변경 목록에 그대로 표시됩니다):

```
M  CHANGELOG.md
M  README.ko.md
M  README.md
M  docs/extension/language-packs.md
A  docs/reports/zh-CN-core-language-pack-report.md
A  lang-packs/_bundled/g7-core-zh-CN/**            (50 files)
A  tests/Translations/zh-CN-core-parity-check.php
A  tests/Unit/Services/LanguagePack/BundledSimplifiedChinesePackTest.php
```

**주의**: `docs/reports/` 와 `tests/Translations/` 는 신규 디렉터리라
GitHub Desktop 이 디렉터리 단위로 접어 보여줄 수 있습니다. 커밋 전 펼쳐서
`extract_*.php`·`seccheck.php` 같은 임시 파일이 섞이지 않았는지 확인하십시오
(현재 저장소에는 없으며, 스크래치패드에만 존재합니다).

---

## 10. 권장 commit 제목

```
feat(lang-packs): 중국어 간체(zh-CN) 코어 언어팩 추가
```

전체 커밋 메시지 예시:

```
feat(lang-packs): 중국어 간체(zh-CN) 코어 언어팩 추가

G7 7.0.9 코어의 중국어 간체 언어팩 g7-core-zh-CN 을 신규 제공한다.
관리자·사용자 화면의 코어 문구, 검증 메시지, 오류 안내가 중국 본토
표기 기준의 간체 중국어로 표시된다.

번역 기준은 한국어 코어 원본(lang/ko, lang/ko.json, lang/partial/ko,
config/core.php)이다. 일본어 팩은 디렉터리 구조와 매니페스트 형식의
참조로만 사용했고 재번역 소스로 쓰지 않았다.

- backend 40종·frontend 3종·seed 5종, 총 50파일 (g7-core-ja 와 경로 1:1)
- 키 4,199개. 원본 대비 키 누락·초과·순서·자료형 불일치 0
- placeholder·HTML·URL·개행 보존 검증 통과, 한글/가나 잔존 0
- 코어 코드 변경 0 — supported_locales 는 활성화 시 provider 가 동적 갱신

로케일 팩이 정적 검사에서 누락되던 사각을 함께 닫는다. 기존
BundledJapanesePacksTest 는 대상을 상수로 나열해 새 로케일을 검증하지
않으므로, zh-CN 전용 무결성 테스트와 원본 대조 검사기를 추가했다.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>
```

> 커밋·푸시는 수행하지 않았습니다. 위 메시지는 제안이며, 실행은 담당자 판단입니다.

---

## 11. 서버에서 실행할 검증 · 설치 명령

### 11-1. 검증 (설치 전)

```bash
composer install --no-interaction --prefer-dist

# 신규 zh-CN 팩 무결성 (프로덕션 클래스 실호출)
php artisan test --filter=BundledSimplifiedChinesePackTest

# 언어팩 시스템 회귀 전체
php artisan test tests/Unit/Services/LanguagePack
php artisan test tests/Feature/LanguagePack

# 기존 ja 팩이 깨지지 않았는지
php artisan test --filter=BundledJapanesePacksTest

# 원본 대조 검사기 (vendor 불필요 — 어디서나 단독 실행)
php tests/Translations/zh-CN-core-parity-check.php --verbose --style

# 코드 스타일
./vendor/bin/pint --test tests/Unit/Services/LanguagePack/BundledSimplifiedChinesePackTest.php
./vendor/bin/pint --test tests/Translations/zh-CN-core-parity-check.php
```

기대 결과: 테스트 전부 green, 검사기 `RESULT: PASS — 위반 0건` (exit 0).

### 11-2. 설치 · 활성화

```bash
# 번들 소스에서 설치 (자동 활성화)
php artisan language-pack:install g7-core-zh-CN --source=bundled

# 확인
php artisan language-pack:list --scope=core

# 콘텐츠 수정 후 설치본 재반영 (프론트엔드 빌드 불필요)
php artisan language-pack:update g7-core-zh-CN --force
```

> `_bundled` 는 배포 원본일 뿐입니다. 설치본(`lang-packs/g7-core-zh-CN/`)을 갱신하지 않으면
> 새 번역 키가 런타임에 존재하지 않아 **오류 없이 조용히 `ko` 로 폴백**합니다.

### 11-3. 빌드 · 코어 수정 필요 여부

| 항목 | 판정 | 근거 |
|---|---|---|
| frontend build (`npm run build`) | **불필요** | `AGENTS.md` 빌드 매트릭스가 `lang-packs/_bundled/**` 를 「빌드 불필요」로 분류. 프론트엔드 번역은 `/api/templates/{id}/lang/{locale}.json` 런타임 fetch 로 서빙되며 vite 번들에 미포함 |
| `config/app.php` 수정 | **불필요** | `LanguagePackServiceProvider::refreshSupportedLocales()` 가 활성 코어 팩 기준으로 `supported_locales`/`translatable_locales`/`locale_names` 를 boot 시 동적 갱신 |
| 코어 코드 수정 | **불필요 (0건)** | `locale_country_fallback` 의 `'zh' => 'CN'`, `resolveNativeName()` 의 `'zh-CN', 'zh' => '中文'` 이 이미 존재 |
| migration | **불필요** | 스키마 변경 없음 (`locale VARCHAR(20)` 충분) |

### 11-4. 문서 반영 — **이번 단계에서 완료**

| 파일 | 상태 |
|---|---|
| `README.md` | ✅ 반영 완료 |
| `README.ko.md` | ✅ 반영 완료 |
| `docs/extension/language-packs.md` | ✅ 반영 완료 |
| `CHANGELOG.md` | ✅ `[Unreleased]` 신설 후 반영 완료 |

> 커밋 `78bb192b` 는 「신규 번들 확장이 README 인벤토리에서 누락되던 문제」를 수정한 이력입니다.
> 누락되어도 오류가 나지 않고 릴리즈 절차도 그대로 통과하므로, 이번에 선반영했습니다.
> 검출 스크립트(`check-readme-bundled-inventory.cjs`)는 이 저장소에 포함되어 있지 않습니다(메인테이너 로컬 도구).

---

## 12. 용어집 (후속 zh-CN 패키지 승계용 SSoT)

`docs/extension/language-packs.md` 의 zh-CN 섹션에도 동일 표를 수록했습니다.

| 한국어 | 중국어 간체 | 코어 내 사용 |
|---|---|---:|
| 관리자 | 管理员 | 50 |
| 사용자 | 用户 | 75 |
| 설정 | 设置 | 142 |
| 환경설정 | 系统设置 | 8 |
| 권한 | 权限 | 87 |
| 역할 | 角色 | 58 |
| 알림 | 通知 | 85 |
| 언어팩 | 语言包 | 39 |
| 모듈 | 模块 | 171 |
| 플러그인 | 插件 | 143 |
| 템플릿 | 模板 | 197 |
| 테마 | 主题 | 34 |
| 코어 | 核心 | — |
| 레이아웃 | 布局 | — |
| 첨부파일 | 附件 | — |
| 스케줄 | 计划任务 | — |
| 본인인증 | 实名认证 | — |
| 프로바이더(IDV) | 服务商 | — |
| 활성화 / 비활성화 | 启用 / 停用 | — |
| 비회원 | 游客 | 3 |
| 결제 | 支付 | 1 |
| 환불 | 退款 | 1 |

### 코어 범위에 값으로 등장하지 않은 지정 용어 (2·3단계용 예약)

| 용어 | 지정 역어 | 코어 내 출현 |
|---|---|---|
| 게시판 | 论坛版块 | 주석 1건뿐 — 주석은 ja 팩 규약대로 산출물에서 제거됨 |
| 게시글 | 帖子 | 0 |
| 댓글 | 评论 | 0 |
| 장바구니 | 购物车 | 0 |
| 주문 | 订单 | 0 |
| 배송 | 配送 | 0 |

### 판단이 갈린 항목

| # | 한국어 | 채택 역어 | 사유 |
|---|---|---|---|
| 1 | 회원가입 / 회원 탈퇴 | `注册` / `注销账号` (**`会员` 미사용**) | 코어의 「회원」은 전부 복합어이고 코어는 `사용자`(→`用户`)로 일원화되어 있어, `会员` 을 함께 쓰면 같은 대상에 두 용어가 생깁니다. `会员注册` 은 유료 멤버십 뉘앙스도 강합니다. 등급 회원 개념이 있는 **이커머스 팩에서 도입 검토** |
| 2 | 비회원 | `游客` | 권한 시스템의 `guest` 역할과 대응 |
| 3 | 본인인증 | `实名认证` | 일반 인증(`身份验证`)과 구분. 인증코드 발송 등 일반 확인 문맥은 `身份验证` 사용 |
| 4 | 스케줄 / 스케쥴 | `计划任务` | 원본 표기가 혼재하나 역어는 하나로 통일 |
| 5 | 매니저 (역할) | `经理` | `管理者` 는 `管理员` 과 혼동되므로 회피 |
| 6 | 언어명 목록 | `ko`→`韩语`, `ja`→`日语`, `en`→`English`, `zh`→`中文`, `vi`→`Tiếng Việt` | CJK 언어명은 중국어 역어, 라틴 문자 표기는 원문 유지(한글 미포함 값 번역 스킵 규약) |

---

## 13. 남은 위험 요소

| # | 등급 | 내용 | 대응 |
|---|---|---|---|
| 1 | 🟠 | PHPUnit 미실행 — 프로덕션 클래스 호출 경로가 로컬에서 검증되지 않음 | 서버에서 §11-1 실행 |
| 2 | 🟡 | 번역의 **문맥 적합성**은 정적 검사로 확인 불가 (키·placeholder·구조만 보장) | 설치 후 관리자 화면 육안 검수 |
| 3 | 🟡 | 기존 ja 팩 언어명 오역 (§7-1) 미해결 | 별도 이슈 |
| 4 | 🟢 | 문서의 「ja 12종」 기술과 실제 21종 불일치 (§7-2) 미해결 | 별도 이슈 |
| 5 | 🟢 | 2·3단계 착수 시 §12 용어집 승계 필요 | 본 표를 SSoT 로 사용 |

**BLOCKER 없음.**

---

## 14. 보고서 경로

```
docs/reports/zh-CN-core-language-pack-report.md
```

UTF-8 without BOM, LF 줄바꿈.

---

## 15. PowerShell 클립보드 복사 명령

```powershell
Get-Content -Raw -Encoding UTF8 "docs\reports\zh-CN-core-language-pack-report.md" | Set-Clipboard
```
