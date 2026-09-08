# 언어팩 시스템 (Language Packs)

## TL;DR (5초 요약)

```text
1. 코어/번들 확장의 lang/{ko,en}/ 는 가상 보호 행으로 자동 노출 (DB 없이 항상 active+protected, 수정 불가)
2. lang-packs/_bundled/ 패키지(ja, fr 등)는 비보호 — 사용자가 install/uninstall/update/activate/deactivate 자유
3. 활성화 시 의존성 + 버전 호환성 자동 검사 (호스트 확장 active + requires.target_version 제약)
4. 호스트 확장 비활성화 시 종속 언어팩 자동 비활성화. 재활성화 시 모달로 "다음 언어팩도 활성화 하시겠습니까" 질의
5. 업데이트 우선순위: GitHub 1순위 + bundled 폴백 (force 시 bundled 우선) — 모듈/플러그인 패턴 동일
```

## 개요

G7 의 언어팩 시스템은 모듈/플러그인/템플릿 시스템과 동일한 멘탈 모델을 재사용한 **설치 가능한 다국어 아티팩트** 입니다. `lang-packs/` 디렉토리가 쓰기 가능 영역으로 분리되어 있어 코어 업데이트 시에도 언어팩이 유실되지 않습니다.

## 디렉토리 구조

```
lang-packs/
├── _bundled/                                   # Git 추적 (번들 메타데이터)
│   ├── g7-core-ko/
│   │   └── language-pack.json
│   ├── g7-core-en/
│   ├── g7-template-sirsoft-admin_basic-ko/
│   ├── g7-template-sirsoft-admin_basic-en/
│   ├── g7-template-sirsoft-basic-ko/
│   └── g7-template-sirsoft-basic-en/
├── _pending/                                   # Git 제외, 업로드 임시
└── {identifier}/                               # Git 제외, 활성 설치본
    ├── language-pack.json
    ├── backend/{locale}/*.php                  # Laravel trans 파일
    ├── frontend/*.json                         # 템플릿 다국어 fragment
    └── seed/*.json                             # 시드 다국어 데이터
```

## 코어 다국어 자원의 위치

코어 자체의 다국어 자원은 모듈/플러그인/템플릿과 동일한 `lang/` 트리 구조를
사용한다 (언어팩 디렉토리 구조와는 별개). 어떤 템플릿이 부팅되든 자동 노출된다.

```text
lang/
├── ko/                  # 백엔드 .php (Laravel __() / trans())
│   └── activity_log.php, admin_layout.php, auth.php, ...
├── en/                  # 백엔드 .php
│   └── activity_log.php, admin_layout.php, ...
├── partial/             # 프론트엔드 .json 분할 (선택)
│   ├── ko/
│   │   └── (영역별 .json — 필요 시)
│   └── en/
├── ko.json              # 프론트엔드 엔트리 ($t: 프리픽스)
└── en.json              # 프론트엔드 엔트리
```

| 축 | 코어 | 모듈 (sirsoft-board) | 동일 패턴 |
|---|---|---|---|
| 백엔드 .php | `lang/{ko,en}/*.php` | `modules/_bundled/sirsoft-board/resources/lang/{ko,en}/*.php` | ✓ |
| 프론트엔드 엔트리 | `lang/{ko,en}.json` | `modules/_bundled/sirsoft-board/resources/lang/{ko,en}.json` | ✓ |
| 프론트엔드 partial | `lang/partial/{ko,en}/*.json` | `modules/_bundled/sirsoft-board/resources/lang/partial/{ko,en}/*.json` | ✓ |

### 코어 키 공간 컨벤션

코어 프론트엔드 lang JSON 의 root 키는 `core.*` prefix 로 일원화한다. 영역별 세분화:

- `core.errors.*` — 템플릿 엔진 에러 메시지 (`TemplateNotFoundError` 등)
- `core.toast.*` — 코어가 발화하는 시스템 토스트
- `core.layout_editor.*` — 레이아웃 편집기 chrome
- `core.<영역>.*` — 향후 코어 UI 추가 시

호스팅 템플릿/모듈/플러그인의 lang JSON 은 root 에 `core` 키를 정의하지 않는다.
정적 검사가 차단한다.

### 런타임 병합 흐름

`TemplateService::getLanguageDataWithModules` 가 다음 순서로 병합:

1. 코어 자체 (`lang/{locale}.json` + partial) — 가장 베이스
2. 템플릿 (`templates/{id}/lang/{locale}.json`)
3. 모듈 (`modules/{id}/resources/lang/{locale}.json`) — 식별자 wrap
4. 플러그인 (`plugins/{id}/lang/{locale}.json`) — 식별자 wrap
5. 활성 언어팩 (`lang-packs/{id}/frontend/*.json` — `MergeFrontendLanguage` 필터)

코어가 베이스로 들어가 가장 낮은 우선순위 — 템플릿/모듈/플러그인/언어팩이 코어 키를
덮어쓸 수 있다 (예: `g7-core-ja/frontend/ko.json` 이 활성화되면 일본어로 번역).

### 병합 정책 — Deep Merge (재귀 병합)

`TemplateService::getLanguageDataWithModules` 는 `array_merge` 가 아닌 재귀 deep merge
(`deepMergeLanguageData`) 로 4개 레이어를 합친다. 동일 top-level 키(예: `layout_editor`,
`core`, `auth`) 의 하위 트리를 leaf 까지 내려가며 합치고, 어느 한쪽이 다른 레이어의
부분 트리만 정의해도 나머지 형제 키가 보존된다.

- assoc 트리끼리 충돌 → 재귀 병합 (양쪽 leaf 모두 보존, 동일 leaf 는 뒤가 우선)
- list / scalar / 한쪽만 array → 뒤 입력으로 덮어쓰기
- 우선순위(낮음 → 높음): 코어 < 템플릿 < 모듈 < 플러그인 < 활성 언어팩(filter 훅)

이 정책으로 템플릿이 `layout_editor.palette` 만 정의해도 코어의 `layout_editor.chrome /
device / zoom / preview / save` 가 살아남는다. 과거 shallow `array_merge` 는 동일
top-level 키 시 트리 전체가 교체되어, 템플릿이 한 sub-key 만 정의해도 코어의 다른
sub-key 가 통째 누락되는 결함이 발생했다.

확장 작성 시 권장 패턴:

- 코어가 정의한 도메인 네임스페이스(예: `layout_editor.*`, `core.*`, `auth.*`) 안에
  자신의 sub-key 만 정의하면 deep merge 가 자동으로 양쪽 보존
- 코어 leaf 를 의도적으로 오버라이드하려면 동일 키 경로에 leaf 만 정의 (트리 교체
  의도가 아니라 leaf override 의도임을 코드로 표현)
- 모듈/플러그인은 식별자 wrap (`module.{id}.*`, `plugin.{id}.*`) 사용을 우선 — 코어
  도메인과 충돌할 일이 없고, deep merge 의 잠재적 부작용도 회피

### 호스팅 템플릿 무관성

코어가 자체 자원을 베이스로 제공하므로, 호스팅 템플릿이 바뀌어도 `$t:core.errors.*`
같은 키는 항상 해석된다. 코어가 발화하는 에러/토스트/편집기 등이 호스팅 템플릿
종속이 되지 않는다.

## Manifest 명세 (language-pack.json)

언어팩 매니페스트는 모듈/플러그인/템플릿 매니페스트와 동일한 필드 구조로 정렬되어 있어 외부 작성자가 다른 확장과 동일한 표준으로 언어팩을 만들 수 있습니다.

```json
{
    "identifier": "g7-core-ja",
    "namespace": "g7",
    "vendor": "sirsoft",
    "name": {
        "ko": "G7 코어 일본어 언어팩",
        "en": "G7 core Japanese language pack",
        "ja": "G7 コア 日本語 言語パック"
    },
    "description": {
        "ko": "G7 코어 일본어 언어팩 (번들)",
        "en": "G7 core Japanese language pack (bundled)",
        "ja": "G7 コア 日本語 言語パック(バンドル)"
    },
    "version": "1.0.0",
    "license": "MIT",
    "scope": "core",
    "target_identifier": null,
    "locale": "ja",
    "locale_name": "Japanese",
    "locale_native_name": "日本語",
    "text_direction": "ltr",
    "g7_version": ">=7.0.0",
    "requires": {
        "target_version": null,
        "depends_on_core_locale": false
    },
    "github_url": "",
    "github_changelog_url": ""
}
```

### 필드

| 필드 | 필수 | 설명 |
|---|---|---|
| `identifier` | ✓ | 전역 고유 — 네이밍 공식: `{vendor-or-namespace}-{scope}-{target?}-{locale}` |
| `namespace` | ✓ | 식별자 prefix (예: `g7`, `acme`). `identifier` 의 prefix 와 일치해야 함 |
| `vendor` | ✓ | 제작자 식별자 (kebab-case). `namespace` 와는 의미 분리 — 동일 namespace 의 패키지를 여러 벤더가 발행 가능 |
| `name` | ✓ | 다국어 객체 — 최소 `ko`/`en` 키 + 자체 로케일 키. 카드 UI 표시명 |
| `description` | ✓ | 다국어 객체 — 동일 키 규칙. 상세 모달 설명 |
| `version` | ✓ | semver. 번들 패키지는 패치 단위 bump 후 CHANGELOG 항목 작성 |
| `license` | ✓ | SPDX 식별자 (예: `MIT`) |
| `scope` | ✓ | `core` / `module` / `plugin` / `template` |
| `target_identifier` | ✓ | scope=core 면 `null`, 그 외 호스트 확장 식별자 |
| `locale` | ✓ | IETF BCP-47 (예: `ja`, `zh-CN`, `pt-BR`) |
| `locale_name` | ✓ | 영문 언어명 (예: `Japanese`, `Simplified Chinese`) |
| `locale_native_name` | ✓ | 언어 선택 UI 표시명 (예: `日本語`) |
| `text_direction` | ✓ | `ltr` / `rtl` |
| `g7_version` | ✓ | 코어 호환 버전 제약 (top-level — 모듈/플러그인 매니페스트와 일관). 모듈 매니페스트의 `g7_version` 과 동일 의미 |
| `requires.target_version` | ✓ | scope=module/plugin/template 일 때 호스트 확장 버전 제약. scope=core 면 `null` |
| `requires.depends_on_core_locale` | ✓ | true 일 때 활성화 시 동일 locale 의 코어 언어팩 활성 상태를 강제 |
| `github_url` | ✓ | GitHub 저장소 URL (없으면 빈 문자열). 비어있지 않으면 GitHub 기반 업데이트 경로 활성화 — 모듈/플러그인 매니페스트와 동일 동작 |
| `github_changelog_url` | ✓ | GitHub release/CHANGELOG URL (없으면 빈 문자열) |

### 콘텐츠 파일

매니페스트의 `contents` 필드는 사용하지 않습니다. 패키지 디렉토리 (`lang-packs/{identifier}/`) 의 `backend/{locale}/`, `frontend/`, `seed/` 하위 파일이 자동 발견됩니다.

- `backend/{locale}/*.php` — Laravel `trans()` 파일 (코어/모듈/플러그인 PHP 다국어)
- `frontend/{locale}.json` — 템플릿 풀 페이로드
- `frontend/partial/{locale}/*.json` — 템플릿/모듈 partial 페이로드
- `seed/{entity}.json` — 다국어 시드 (NotificationDefinition, IdentityMessageDefinition 등)

### 들여쓰기 규칙

- `language-pack.json` (매니페스트) — **4-space** (모듈/플러그인/템플릿 매니페스트와 일관)
- 콘텐츠 JSON (`frontend/*.json`, `seed/*.json`) — 2-space

## 슬롯과 다중 벤더

**슬롯** = `(scope, target_identifier, locale)` 튜플. 동일 슬롯에 여러 벤더가 공존할 수 있으나 **active 는 1개만 허용** (`language_packs_slot_active_unique` functional index).

```
slot (core, null, ja):
  - sirsoft-core-ja  (active, vendor=sirsoft)
  - acme-core-ja     (installed, vendor=acme)
```

UI 에서 라디오로 즉시 전환 가능. 활성 팩이 제거되면 slot 의 다음 후보가 자동 승격됩니다.

## 설치 흐름

1. ZIP/GitHub/URL → `_pending/{tmp}/` 추출
2. `LanguagePackManifestValidator` → manifest 구조 검증
3. 보안 검사: `backend/` 외 PHP 파일 차단, `eval`/`include` 패턴 차단
4. 의존성 검사: `depends_on_core_locale=true` 면 코어 언어팩 active 여부 확인
5. 대상 확장 존재 확인 (modules/plugins/templates 테이블)
6. 다운그레이드 차단 (version_compare)
7. `_pending` → `lang-packs/{identifier}/` 이동
8. DB 레코드 생성/업데이트, 슬롯이 비어있으면 자동 active 승격
9. `HookManager::doAction('core.language_packs.after_activate', $pack)` → `SyncDatabaseTranslations` 리스너가 DB JSON 컬럼 갱신

### 웹 인스톨러 (`public/install/`) 동반 설치

신규 사이트 초기 설치 시 4단계 확장 선택 화면에서 번들 언어팩(`lang-packs/_bundled/*`)을 함께 선택할 수 있습니다.

- 인스톨러는 Laravel 부팅 전 단계로 동작하므로 `lang-packs/_bundled/*/language-pack.json` 매니페스트를 직접 스캔하여 카드를 노출합니다.
- 카드는 `locale` 별 서브헤딩으로 그룹핑되며, `scope` 가 `module`/`plugin`/`template` 인 항목은 종속 확장(`target_identifier`)이 함께 선택되어야 활성화됩니다. 종속 확장을 해제하면 그 언어팩 카드는 자동으로 비활성화 + 선택 해제됩니다.
- 코어 언어팩(`scope: "core"`)은 `target_identifier` 가 없으므로 항상 활성화 상태입니다.
- default 선택 정책은 모두 OFF — 사용자가 명시 선택. (관리자 설치 모달의 "모든 후보 ON" 정책과 차별화하여, 신규 사이트가 불필요한 언어팩으로 시작하지 않도록 함.)
- 설치 실행 시 5단계의 모든 확장 install/activate 가 완료된 뒤 `php artisan language-pack:install {identifier} --source=bundled` 가 선택된 각 언어팩에 대해 호출됩니다(자동 활성화 default).
- 언어팩 1건 설치 실패는 best-effort 처리 — 전체 설치를 중단하지 않고 경고 로그만 남긴 뒤 다음 언어팩으로 진행합니다. 코어/모듈/플러그인 install 실패와 달리 rollback 을 발생시키지 않습니다.

## 프로비저닝과 드리프트 발견 (supported_locales)

코어의 `ko`/`en` 은 가상 보호 행으로 DB 설치 없이 항상 서빙되지만, 그 외 로케일(예: `ja`)의 번들 언어팩은 `lang-packs/_bundled/` 에 소스가 있어도 설치본 디렉토리로 복사·등록되어야 서빙됩니다. 이 비대칭 때문에 "설치본이 없거나 어긋난" 상태가 오류 없이 조용히 `ko` 로 폴백할 수 있어, 다음 도구로 프로비저닝·발견을 지원합니다.

### `language-pack:provision` (멱등 프로비저닝)

```bash
php artisan language-pack:provision                 # supported_locales 의 비-base 로케일을 대상으로 미설치 번들 팩 설치
php artisan language-pack:provision --locale=ja      # 특정 로케일만
php artisan language-pack:provision --scope=core     # 스코프 한정
```

- 대상 로케일 기본값은 `config('app.supported_locales')` 에서 base locale(ko/en)을 뺀 집합입니다. 즉 "사이트가 쓰겠다고 선언한 로케일" 만 채웁니다(불필요한 로케일 대량 설치 없음).
- 미설치 번들 팩(신규 설치)과 설치본 파일이 사라진 드리프트 팩(복구)을 함께 대상으로 삼습니다. 정상 설치된 팩은 어느 쪽에도 해당하지 않아 재실행해도 신규 설치가 0 건으로 수렴합니다(완전 멱등). fresh install · 복구 · 시더가 공유하는 단일 프로비저닝 경로입니다.
- 대상 확장 미설치/미활성 등 설치 차단 사유가 있는 팩은 건너뛰고 경고만 남깁니다(best-effort).

### 미설치·드리프트 발견

- `language-pack:list` 는 설치된 DB 행뿐 아니라 "번들에 있으나 미설치"(`uninstalled`) 및 "active 로 기록됐으나 설치본 파일 부재(드리프트)" 를 함께 표시합니다. 드리프트 행의 Status 는 `active (파일 없음)` 처럼 표기됩니다.
- 관리자 언어팩 목록 화면도 동일하게 드리프트 행에 "파일 없음" 배지와 원클릭 재설치 버튼을 노출합니다(번들 소스 재설치).
- 재설치 버튼은 **번들 소스가 실재하는 팩**에만 뜹니다. 설치 경로(zip/GitHub/URL)와 무관하게 동일 식별자의 `lang-packs/_bundled/` 소스가 있으면 복구할 수 있고, 소스가 없는 서드파티 팩은 배지로 발견만 되고 복구 버튼은 뜨지 않습니다(복구할 원본이 없기 때문).

### supported_locales ↔ 번들 소스 정합

`config/app.php` 의 `supported_locales` 에 비-base 로케일을 선언했다면, 대응하는 코어 번들 소스(`lang-packs/_bundled/g7-core-{locale}/`)가 존재해야 프로비저닝으로 채울 수 있습니다. 대응 소스가 없는 로케일을 선언하면 설치할 팩 자체가 없어 그 로케일이 조용히 `ko` 로 폴백합니다(복구 경로 없음). 이 정합은 정적 검사로 확인됩니다 — 소스를 추가하거나 미지원 로케일을 선언에서 제거하세요.

## 시더 통합 (HookManager 필터)

기존 시더에 `applyFilters` 1줄만 추가하면 자동으로 다국어 키가 병합됩니다.

```php
// database/seeders/RolePermissionSeeder.php
$config = config('core.permissions');
$config = HookManager::applyFilters('core.permissions.config', $config);
foreach ($config['categories'] as $cat) { ... }
```

| 필터명 | 적용 위치 |
|---|---|
| `core.permissions.config` | RolePermissionSeeder |
| `core.roles.config` | RolePermissionSeeder |
| `core.menus.config` | CoreAdminMenuSeeder |
| `seed.notifications.translations` | NotificationDefinitionSeeder |
| `seed.identity_messages.translations` | IdentityMessageDefinitionSeeder (본인인증 메일 메시지 정의 — provider × scope × scope_value 복합 키) |
| `seed.{vendor-module}.{entity}.translations` | 모듈 시더 (예: `seed.sirsoft-ecommerce.shipping_types.translations`, `seed.sirsoft-ecommerce.notifications.translations`, `seed.sirsoft-board.board_types.translations`) |
| `seed.{vendor-module}.identity_messages.translations` | 모듈/플러그인 IDV 메시지 정의 — `ModuleManager::syncModuleIdentityMessages` / `PluginManager::syncPluginIdentityMessages` 가 발화 |
| `module.{id}.admin_menus.translations` | ModuleManager::createModuleMenus() — 모듈 admin_menus 동기화 시 |
| `module.{id}.roles.translations` | ModuleManager::createModuleRoles() — 모듈 roles 동기화 시 |
| `module.{id}.permissions.translations` | ModuleManager::createModulePermissions() — 모듈 권한 트리 동기화 시 |
| `plugin.{id}.roles.translations` | PluginManager::createPluginRoles() — 플러그인 roles 동기화 시 |
| `plugin.{id}.permissions.translations` | PluginManager::createPluginPermissions() — 플러그인 권한 트리 동기화 시 |
| `template.language.merge` | TemplateService::getLanguageDataWithModules() |

**확장 시드 필터 자동 결선** (`LanguagePackServiceProvider::registerExtensionSeedFilters`): 활성 모듈/플러그인 언어팩의 `seed/*.json` 마다 위 패턴의 필터에 listener 가 자동 등록됩니다. 매칭 키는 entry 의 컬럼을 우선순위(`code` > `slug` > `key` > `identifier` > `id`) 로 자동 감지합니다.

엔티티별 결선 정책:
- `notifications.json` → `seed.{target}.notifications.translations` 필터 (Definition × Template 3-tier 구조)
- `identity_messages.json` → `seed.{target}.identity_messages.translations` 필터 (Definition × Template × Channel — provider/scope_type/scope_value 복합 키 매칭)
- `menus.json` → `module.{target}.admin_menus.translations` 필터 (모듈 전용)
- `roles.json` → `{scope}.{target}.roles.translations` 필터 (module/plugin)
- `permissions.json` → `{scope}.{target}.permissions.translations` 필터 (3-레벨 트리: module/categories/permissions)
- 기타 → `seed.{target}.{entity}.translations` 필터 (단순 entity 시드)

### 복원 경로의 언어팩 병합

[기본값 복원](알림 템플릿·본인인증 메시지)은 위 시딩 필터가 아니라 자체 기본값 필터
(`core.notification.filter_default_definitions` / `core.identity.filter_default_message_definitions`)를
탄다. 복원 기본값에는 **활성 언어팩 seed 로케일이 반드시 병합**되어야 한다 — 병합하지 않으면
복원이 팩이 주입해 둔 로케일(ja 등)을 config 의 ko/en 만으로 대체해 영구 소실시키며,
오류도 로그도 남지 않는다. 코어 서비스(NotificationTemplateService·IdentityMessageTemplateService)가
필터 적용 후 시딩과 같은 주입기(SSoT)로 병합하며, 정적 검사(테스트)가 이 계약을 고정한다.

시딩 필터의 페이로드는 **발화 주체의 원형 키를 보존**해야 한다 — 코어 알림 시더는 config
원형(연관 배열, 키가 곧 type), 본인인증은 복합 키(`mail.purpose.signup` 등)가 매칭 키다.
키를 버리고 리스트로 만들면 주입기가 전 항목을 스킵해 팩 로케일이 오류 없이 주입되지 않는다.
알림 주입기는 두 형태(문자열 키 우선, 없으면 type 필드)를 수용한다.

### 사용자 수정 보존 판정과 시딩 컨텍스트

언어팩 병합·제거의 사용자 수정 보존 판정은 운영자 수정의 실제 기록 형식인 **dot-path 항목**
(`user_overrides` 의 `"{컬럼}.{로케일}"`)을 기준으로 한다. 컬럼 전체 항목(`"subject"`)은 시더
재실행의 컬럼 보호 선언이며 팩 병합을 막지 않는다 — 막으면 팩 업데이트가 자기 로케일 번역을
갱신하지 못한다.

이를 위해 두 가지가 함께 지켜져야 한다:

- 다국어 JSON 컬럼을 추적하는 모델은 `translatableTrackableFields` 를 선언한다 — 미선언 시
  운영자 수정이 컬럼 전체 항목으로 기록되어 로케일 단위 보존이 불가능해진다.
- 언어팩 주입·제거처럼 시스템이 수행하는 저장은 시딩 컨텍스트(`user_overrides.seeding` 바인딩)
  안에서 실행한다 — 바인딩 없이 저장하면 주입 자체가 "사용자 수정"으로 오인 기록되어 이후
  시더 재실행의 컬럼 갱신이 영구히 얼어붙는다.

### 실패한 업데이트의 상태 복원

`language-pack:update` 가 설치 트랜잭션 이후(활성화 단계)에 실패하면 팩 상태를 이전 상태로
복원한다. 복원하지 않으면 active 였던 팩이 installed 로 방치되어 해당 로케일의 백엔드 번역이
안내 없이 기본 로케일로 폴백된다.

### 같은 프로세스 활성화와 필터 인스턴스

시더 번역 필터 클로저는 boot 시점의 injector→registry **인스턴스**를 캡처한다. 활성 팩 목록
캐시를 갱신할 때는 싱글톤을 유지한 채 `LanguagePackRegistry::invalidate()` 로 내부 캐시만
비운다 — `forgetInstance` 로 바인딩을 교체하면 캡처된 구 인스턴스의 stale 캐시가 남아, 같은
프로세스에서 활성화된 신규 팩이 시더 필터에 보이지 않는다(오류 없이 해당 로케일만 미주입).

### IDV 도메인 lang pack 커버리지

`config/core.php` 의 본인인증 SSoT 4개 블록 중 lang pack seed 대상은 1개:

| SSoT 블록 | lang pack seed | 사유 |
|---|---|---|
| `notification_definitions` | ✓ `seed/notifications.json` | 정의/템플릿이 다국어 데이터 직접 보유 |
| `identity_messages` | ✓ `seed/identity_messages.json` | 메일 본문/제목이 다국어 데이터 직접 보유 |
| `identity_policies` | ❌ | `IdentityPolicy` 모델에 다국어 필드 부재 — 관리자 UI 라벨은 `lang/{locale}/identity.php` 또는 `frontend/partial/admin.json` 의 i18n 키로 처리 |
| `identity_purposes` | ❌ | `label_key`/`description_key` 참조 패턴 — 실제 ja 라벨은 `frontend/partial/admin.json::identity.purposes.*` 키로 처리 |

**`identity_messages.json` 스키마 예시**:

```json
{
  "mail.purpose.signup": {
    "definition": {
      "name": "会員登録認証",
      "description": "会員登録時のメール認証"
    },
    "templates": {
      "mail": {
        "subject": "[アプリ] 会員登録認証コード",
        "body": "<p>認証コード: {code}</p>"
      }
    }
  }
}
```

복합 키는 `{channel}.{scope_type}.{scope_value}` 또는 `{channel}.provider_default` 형태 — config/core.php::identity_messages 의 array key 와 동일.

**확장 권한 트리 시드 키 포맷**: 권한은 ModuleManager 가 DB row identifier 를 `{module-id}.{cat-id}.{perm-id}` 로 prefix 하므로 시드도 동일 포맷을 사용합니다. 권한 노드 식별자는 `action`(이커머스 등) 또는 `identifier`(명시적) 둘 중 존재하는 키를 사용합니다.

## user_overrides 보존 정책

`HasUserOverrides` 사용 모델(Permission/Role/Menu/NotificationDefinition/NotificationTemplate/Module/Plugin/Template)에서 사용자가 직접 수정한 컬럼/locale 키는 언어팩이 덮어쓰지 않습니다.

| 케이스 | 동작 |
|---|---|
| A: 기존 row 에 해당 locale 키 부재 | 추가 (overrides 무관) |
| B: locale 키 존재 + user_overrides 등록 | **건너뜀** (사용자 보존) |
| C: locale 키 존재 + user_overrides 미등록 | 시드 값으로 덮어쓰기 |

비활성화/제거 시에도 user_overrides 등록 locale 은 JSON 에서 제거되지 않습니다. 모든 보존 결정은 `language_pack` 채널에 감사 로그로 기록됩니다.

## 백엔드 번역 namespace fallback 메커니즘

Laravel `Translator` 의 FileLoader 는 `addNamespace($namespace, $hint)` 가 단일 hint 만 보유하며 덮어쓰는 구조이므로, 모듈/플러그인 자체의 `src/lang` namespace 등록 후 같은 namespace 로 언어팩이 추가되면 모듈의 ko/en 등록이 통째로 사라지는 회귀가 발생합니다.

`LanguagePackTranslator` 가 이를 회피하기 위한 namespace fallback 경로를 별도 보유합니다.

- `addNamespaceFallbackPath($namespace, $locale, $path)` — namespace ⇒ locale ⇒ 경로 배열로 누적 등록
- `load($namespace, $group, $locale)` — 표준 hint 로 1차 로드 후 namespace fallback 경로의 PHP 배열 파일을 누락 키만 보완 병합 (기존 번역 우선)

이 메커니즘으로 모듈은 `src/lang/{ko,en}/*.php` 의 표준 namespace 등록을 그대로 유지하고, 활성 ja 언어팩은 fallback 경로(`lang-packs/g7-module-{id}-ja/backend/ja/`) 에서만 ja 키를 보완합니다.

## 프론트엔드 다국어 병합의 wrap 정책

`MergeFrontendLanguage` 가 활성 언어팩의 `frontend/*.json` 을 병합할 때 scope 별로 root key 처리가 다릅니다.

- core / template scope — root 에 평탄 병합 (TemplateService 의 ko 데이터 구조와 일치)
- module / plugin scope — `[$pack->target_identifier => $frontend]` 로 wrap 후 병합

wrap 이 필요한 이유: TemplateService 가 모듈/플러그인 자체 ko 데이터를 `[$identifier => $data]` 로 wrap 하므로, 언어팩 데이터도 동일 구조여야 `$t:sirsoft-ecommerce.admin.*` 같은 표현식이 ja 활성 시에도 정확한 경로로 해석됩니다.

## 권한

| 권한 | 설명 |
|---|---|
| `core.language_packs.read` | 언어팩 목록 및 상세 조회 |
| `core.language_packs.install` | ZIP/GitHub/URL 설치 |
| `core.language_packs.manage` | 활성화/비활성화/제거 |

## REST API

| 엔드포인트 | 메서드 | 권한 |
|---|---|---|
| `/api/admin/language-packs` | GET | `read` |
| `/api/admin/language-packs/{id}` | GET | `read` |
| `/api/admin/language-packs/install-from-file` | POST | `install` |
| `/api/admin/language-packs/install-from-github` | POST | `install` |
| `/api/admin/language-packs/install-from-url` | POST | `install` |
| `/api/admin/language-packs/{id}/activate` | POST | `manage` |
| `/api/admin/language-packs/{id}/deactivate` | POST | `manage` |
| `/api/admin/language-packs/{id}` | DELETE | `manage` |

## 보안

1. **ZIP slip 방지** — `ZipInstallHelper` 의 기존 검증 재사용
2. **PHP 격리** — `backend/` 디렉토리 외 PHP 파일 거부
3. **위험 함수 차단** — `eval/include/require/exec/system/popen` 등 정적 분석으로 거부
4. **파일 크기 제한** — 10MB
5. **체크섬 검증** — URL 설치 시 SHA-256 옵션

## 외부 패키지형 vs 메타데이터 전용 번들

번들 언어팩은 두 가지 형태를 가질 수 있습니다.

| 형태 | `contents` | 실제 번역 자산 위치 | 사용 사례 |
|---|---|---|---|
| **메타데이터 전용** | 빈 배열 | 코어/확장 트리(`lang/{locale}/`, `templates/_bundled/*/lang/{locale}.json`) | 코어 fallback locale (ko/en) — 트리 자체에 포함된 자산을 시스템에 등록만 하기 위함 |
| **외부 패키지형** | 실제 파일 경로 | 패키지 디렉토리 내(`backend/{locale}/*.php`, `frontend/*.json`, `seed/*.json`) | 추가 로케일 (ja, zh-CN 등) — 코어 트리를 건드리지 않고 새 locale 추가 |

기본 ko/en 은 메타데이터 전용 — 실제 PHP/JSON 파일은 코어 트리에 그대로 두고 manifest 1개로 시스템에 등록만 합니다. 새 locale (예: 일본어) 은 외부 패키지형으로 도입하여 **코어 코드 변경 0** 을 유지합니다.

## 공식 일본어 번들 언어팩 (g7-*-ja)

G7 는 일본어 번들 언어팩 12종을 공식 제공합니다. ko 원본을 LLM 으로 자동 번역하여 빌드된 산출물이며, 사용자 환경에서는 일반 번들 확장과 동일하게 `language-pack:install --source=bundled` 로 설치합니다.

### 패키지 구성

| scope | 식별자 | target_identifier |
|---|---|---|
| core | `g7-core-ja` | (null) |
| template | `g7-template-sirsoft-admin_basic-ja` | sirsoft-admin_basic |
| template | `g7-template-sirsoft-basic-ja` | sirsoft-basic |
| template | `g7-template-gnuboard7-hello_admin_template-ja` | gnuboard7-hello_admin_template |
| template | `g7-template-gnuboard7-hello_user_template-ja` | gnuboard7-hello_user_template |
| module | `g7-module-sirsoft-ecommerce-ja` | sirsoft-ecommerce |
| module | `g7-module-sirsoft-board-ja` | sirsoft-board |
| module | `g7-module-sirsoft-page-ja` | sirsoft-page |
| module | `g7-module-gnuboard7-hello_module-ja` | gnuboard7-hello_module |
| plugin | `g7-plugin-sirsoft-ckeditor5-ja` | sirsoft-ckeditor5 |
| plugin | `g7-plugin-sirsoft-marketing-ja` | sirsoft-marketing |
| plugin | `g7-plugin-sirsoft-tosspayments-ja` | sirsoft-tosspayments |

### 입력 → 산출 매핑

| 패키지 | ko 원문 소스 | 산출 위치 |
|---|---|---|
| `g7-core-ja` backend | `lang/ko/*.php` (33개) | `lang-packs/_bundled/g7-core-ja/backend/ja/*.php` |
| `g7-core-ja` seed | `config/core.php` (permissions/roles/menus) + `NotificationDefinitionSeeder` | `seed/{permissions,roles,menus,notifications}.json` |
| `g7-template-{tpl}-ja` | `templates/_bundled/{tpl}/lang/ko.json` + `lang/partial/ko/*.json` | `frontend/ja.json` + `frontend/partial/*.json` |
| `g7-module-{mod}-ja` | `modules/_bundled/{mod}/{src,resources}/lang/ko/*.php` + `resources/lang/ko.json` + `resources/lang/partial/ko/**/*.json` (재귀) | `backend/ja/*.php` + `frontend/ja.json` + `frontend/partial/**/*.json` |
| `g7-module-sirsoft-ecommerce-ja` seed | `ShippingTypeSeeder`, `ClaimReasonSeeder`, `EcommerceNotificationDefinitionSeeder` ko 추출 | `seed/{shipping_types,claim_reasons,notifications}.json` |
| `g7-module-sirsoft-board-ja` seed | `BoardTypeSeeder::DEFAULT_BOARD_TYPES` 상수 추출 | `seed/board_types.json` |
| 모듈 ja 팩 (공통) | `module.php` 의 `getAdminMenus` / `getRoles` / `getPermissions` 추출 | `seed/{menus,roles,permissions}.json` |
| 플러그인 ja 팩 (공통) | `plugin.php` 의 `getRoles` / `getPermissions` 추출 | `seed/{roles,permissions}.json` |
| 모든 확장 ja 팩 | `module.json` / `plugin.json` / `template.json` 의 `name`/`description` ko | `seed/manifest.json` |
| `g7-plugin-{pl}-ja` | `plugins/_bundled/{pl}/lang/ko/*.php` | `backend/ja/*.php` |

### 빌드 source kind

| kind | 동작 |
|---|---|
| `php-dir` | 디렉토리의 모든 *.php 를 ko → ja 로 번역 |
| `json-file` | 단일 JSON 파일 ($partial 디렉티브 경로 정규화 포함) |
| `json-dir` | 디렉토리의 모든 *.json **재귀** (admin/* 같은 서브디렉토리 포함) |
| `core-seed` | `config/core.php` 에서 permissions/roles/menus ko 추출 |
| `notif-seed` | NotificationDefinitionSeeder 의 getDefaultDefinitions 추출 |
| `ext-seed` | 확장 시더의 메서드/상수 추출 (entity 별 매칭 키 자동 감지) |
| `ext-spec-seed` | module.php / plugin.php 의 getAdminMenus / getRoles / getPermissions 결과 추출. 권한은 `{module-id}.{cat-id}.{perm-id}` prefixed identifier 로 출력 |
| `manifest-seed` | manifest 파일의 name/description ko 추출 → seed/manifest.json |

### 번역 규칙

- placeholder 보존: `:attribute`, `{count}`, `{{var}}`, `%s`, `:other`
- HTML 태그 보존: `<a>`, `<br>`, `<strong>`, `<span>`
- 키(key) 변형 금지 — value 만 번역
- 통화/숫자 단위 변환 금지 (런타임 처리)
- 공식체(です·ます) 강제, 친근체 금지
- 한글 미포함 value(영문/숫자/기호만) 는 번역 스킵 → 원문 유지
- 고정 용어집으로 일관성 강제 (예: `관리자 → 管理者`, `장바구니 → カート`)

### 용어집 등록 의무

번역 결과의 표기는 고정 용어집이 정한다. 용어집에 없는 도메인 용어는 어간만 번역되어
**원본 로케일 문자가 값에 그대로 남는다** — 예외도 오류도 나지 않고, 키 대칭 검사도 값은 보지 않으므로
그 로케일로 화면을 열기 전까지 드러나지 않는다.

- 새 도메인 용어를 도입하면 번역 빌드 **전에** 용어집에 등록한다
- 이미 생성된 값을 손으로 고쳤다면 용어집도 함께 고친다 — 용어집을 두면 다음 키에서 같은 오역이 되풀이된다
- 같은 용어는 팩 전체에서 한 표기로 통일한다 (용어집이 단일 출처)

정적 검사가 번역 값의 원본 로케일 문자 잔존을 검출한다. 매니페스트와 CHANGELOG,
그리고 한국어 주석은 대상이 아니다.

### 설치 및 활성화

```bash
# 12개 패키지를 번들 소스로 설치 (자동 활성)
php artisan language-pack:install g7-core-ja --source=bundled
php artisan language-pack:install g7-template-sirsoft-admin_basic-ja --source=bundled
# ... (나머지 10개 동일)

# 확인
php artisan language-pack:list --scope=core
php artisan language-pack:list --scope=module
```

### 기존 ko/en 변경 시 번들 ja 동기화 의무

코어/모듈/플러그인/템플릿의 ko 또는 en 다국어 키를 추가/수정/제거할 때마다 대응하는 번들 ja 패키지(`lang-packs/_bundled/g7-*-ja/`) 의 키 셋을 동기화해야 한다. 동기화하지 않으면 일본어 화면에서 미번역 fallback(ko/en) 이 노출되어 UX 가 손상된다.

번들 ja 동기화/빌드 자동화 도구는 메인테이너 영역이며, 외부 기여자는 ko 원본 변경 후 PR 시 메인테이너가 동기 빌드를 수행한다. 자기 환경에서 검증이 필요하다면 ko 키를 직접 ja 패키지의 대응 위치(`lang-packs/_bundled/g7-*-ja/backend/ja/`, `frontend/`, `seed/`) 에 수동 추가/수정/삭제할 수 있다 — 키만 일치하면 시스템이 정상 fallback 한다.

#### 잉여 키 (ko 에서 제거 후 번들 잔류) 처리

ko 에서 키 제거 시 번들 ja 의 대응 키도 수동 제거 권장 (자동 제거 도구 없음 — 의도치 않은 제거 방지).

#### 번들 언어팩 버전 + CHANGELOG 작성 규정

번들 언어팩 자산을 수정할 때마다 모듈/플러그인/템플릿 확장과 동일한 수준의 버전 + CHANGELOG 관리를 적용한다.

- **버전 bump 시점**: 패키지가 외부에 한 번이라도 출시(릴리즈/배포) 된 이후의 수정에 한해 버전을 올린다. 아직 출시되지 않은 번들 패키지의 사전 보강(키 추가/번역 정정 등) 은 `1.0.0` 그대로 유지한다. 이미 출시된 버전의 콘텐츠를 수정하면 patch bump 가 필수다 (출시 확정 버전의 콘텐츠를 버전 유지한 채 덮어쓰지 않는다).
- **버전 bump 단위**: 출시 후 키 추가/누락 보강은 패치 (1.0.0 → 1.0.1), 의미적으로 큰 변경이나 제거는 마이너 (1.0.0 → 1.1.0).
- **CHANGELOG.md**: 패키지 루트에 `CHANGELOG.md` 작성. Keep a Changelog 표준 (`## [버전] - YYYY-MM-DD` + `### Added/Changed/Fixed/Removed`).
- **톤**: 사용자 관점, 1~2줄 불릿. 내부 파일 경로/내부 함수명/이슈 번호 미기재.
- **신규 기능의 개발 중 결함**: 같은 릴리즈에 처음 도입된 키의 누락 보강 등은 Fixed 가 아닌 Added 로 기록.
- **번역 자체 수정** (예: 어색한 표현 교정): `### Changed` 로 기록.
- **자동화**: 빌드 스크립트는 기존 manifest 의 version 을 읽어 보존하므로, 수동 bump 한 1.0.1 등이 다음 빌드에서 1.0.0 으로 덮어쓰이지 않는다.

### 외부 언어팩 패키지 개발 · 배포

번들 ja 와 동일한 디렉토리 구조의 언어팩 패키지를 자체 작성하여 별도 배포할 수 있다. 코어 트리는 변경하지 않으므로 코어 업데이트와 충돌하지 않는다.

```text
my-lang-pack-zh/
├── language-pack.json          # 매니페스트 (식별자, scope, target_identifier, locale, version, manifest 키 셋)
├── backend/
│   └── zh-CN/                  # 백엔드 PHP 키 (스코프가 코어/모듈/플러그인일 때)
│       └── *.php
├── frontend/                   # 프론트엔드 JSON 키 (스코프가 모듈/플러그인/템플릿일 때)
│   ├── zh-CN.json
│   └── partial/
│       └── *.json
└── seed/                       # 초기 데이터 번역 (스코프별 entity 키 매칭 — permissions/menus/notifications 등)
    └── *.json
```

매니페스트(`language-pack.json`) 필드는 번들 ja 패키지를 참고. `scope` 는 `core`/`module`/`plugin`/`template` 중 하나, `target_identifier` 는 대상 확장 식별자(코어 스코프는 null), `locale` 은 [BCP 47](https://tools.ietf.org/html/bcp47) 코드.

### 설치

```bash
# 로컬 디렉토리에서 설치
php artisan language-pack:install /path/to/my-lang-pack-zh --source=local

# 또는 zip 으로 배포 후 URL 에서 설치
php artisan language-pack:install https://example.com/my-lang-pack-zh.zip --source=url
```

설치 후 `language-pack:list` 로 확인. 다른 로케일 패키지와 동일하게 활성/비활성/제거가 가능하며, 의존성 검증(`core_locale_missing` 등) 도 동일하게 적용된다.

## 공식 중국어 간체 번들 언어팩 (g7-*-zh-CN)

G7 는 중국어 간체(`zh-CN`) 언어팩을 공식 제공한다. 현재 제공 범위는 **코어 1종 + 게시판·이커머스·페이지 모듈 3종**이며, 플러그인/템플릿 zh-CN 팩은 아직 없다. 코어·모듈 트리를 건드리지 않는 외부 패키지형이므로 **코어·모듈 코드 변경 0** 이다.

### 패키지 구성

| scope | 식별자 | target_identifier | 파일 수 |
|---|---|---|---|
| core | `g7-core-zh-CN` | (null) | 50 |
| module | `g7-module-sirsoft-board-zh-CN` | `sirsoft-board` | 33 |
| module | `g7-module-sirsoft-ecommerce-zh-CN` | `sirsoft-ecommerce` | 60 |
| module | `g7-module-sirsoft-page-zh-CN` | `sirsoft-page` | 10 |

### locale 코드

`zh-CN` 을 사용한다. `zh_CN`(언더스코어)·`zh-cn`(소문자 region) 은 `LanguagePackManifestValidator` 의 BCP-47 패턴에서 거부되고, `zh-Hans` 계열은 `LanguagePackBundledRegistrar` 의 locale 디렉토리 스캔 패턴(`/^[a-z]{2,3}(-[A-Z]{2})?$/`) 을 통과하지 못한다. 코어는 이미 `zh-CN` 을 인지한다 — `LanguagePackBundledRegistrar::resolveNativeName()` 의 `'zh-CN', 'zh' => '中文'`, `config/app.php` 의 `locale_country_fallback` 에 `'zh' => 'CN'`.

번체 중국어를 추가할 경우 슬롯 키가 `(scope, target_identifier, locale)` 튜플이므로 `zh-TW` 는 `zh-CN` 과 다른 슬롯이 되어 동시 설치·동시 활성이 가능하다.

### 입력 → 산출 매핑

| 패키지 | ko 원문 소스 | 산출 위치 |
|---|---|---|
| `g7-core-zh-CN` backend | `lang/ko/*.php` (40개) | `backend/zh-CN/*.php` (40개) |
| `g7-core-zh-CN` frontend | `lang/ko.json` + `lang/partial/ko/*.json` | `frontend/zh-CN.json` + `frontend/partial/{errors,layout_editor}.json` |
| `g7-core-zh-CN` seed | `config/core.php` (permissions/roles/menus/notification_definitions/identity_messages) | `seed/{permissions,roles,menus,notifications,identity_messages}.json` |

패키지 총 50개 파일 (매니페스트 1 + CHANGELOG 1 + backend 40 + frontend 3 + seed 5) 로, `g7-core-ja` 와 경로가 1:1 대응한다.

`frontend/zh-CN.json` 의 `$partial` 경로는 원본의 `partial/ko/{name}.json` 에서 로케일 세그먼트를 제거한 `partial/{name}.json` 형태로 정규화한다 (ja 팩과 동일 규칙).

### 번역 규칙

일본어 팩과 동일한 보존 규칙을 적용하되, 의미의 기준은 **한국어 원본**이다 (일본어 팩은 구조·매니페스트 형식의 참조용이며 재번역 소스가 아니다).

- 키(key)·키 순서·자료형·배열 중첩 구조 변형 금지 — value 만 번역
- placeholder 보존: `:attribute`, `:count`, `{current}`, `{{query.page}}`, `%s`
- 접두/접미 결합형 placeholder 는 붙여 쓴 형태 그대로 유지: `v:version`, `v:from → v:to`, `:maxMB`, `:sizeMB` (Laravel `str_replace` 가 그대로 치환한다)
- HTML 태그·속성·인라인 CSS·URL 보존 (알림/본인인증 메일 본문의 `<table role="presentation">`, `<a href="{action_url}" style="…">` 등)
- 이모지 보존: `📊` `🖥` `📱` `📲` `📐` `☀` `🌙` `🔍` `⏎` `🌐`
- 선행 공백·개행 수 보존 (`'  - :target（来源：:source）'` 의 들여쓰기 2칸 등)
- 명령·코드 문자열은 번역하지 않는다: `php artisan config:clear`, `search:index --repair`, `socks5h://127.0.0.1:1080`
- 한글 미포함 value(영문/숫자/기호만) 는 번역 스킵 → 원문 유지
- 중국어 문장부호는 전각(`，。（）：`) 을 쓰되, placeholder 바로 앞에서는 전각 콜론 + ASCII placeholder 로 표기한다 (`要求：:constraint`) — `::constraint` 형태는 만들지 않는다
- URL 뒤에 전각 괄호·구두점을 바로 붙이지 않는다 (링크 인식 경계가 모호해진다) — URL 을 문장 끝에 두거나 공백으로 분리한다

### 용어집

`g7-core-zh-CN` 이 고정한 코어 용어집. 후속 zh-CN 패키지(템플릿/모듈/플러그인)는 이 표기를 승계한다.

| 한국어 | 중국어 간체 |
|---|---|
| 관리자 | 管理员 |
| 사용자 | 用户 |
| 설정 | 设置 |
| 환경설정 | 系统设置 |
| 권한 | 权限 |
| 역할 | 角色 |
| 알림 | 通知 |
| 언어팩 | 语言包 |
| 모듈 | 模块 |
| 플러그인 | 插件 |
| 템플릿 | 模板 |
| 테마 | 主题 |
| 코어 | 核心 |
| 레이아웃 | 布局 |
| 첨부파일 | 附件 |
| 스케줄 | 计划任务 |
| 본인인증 | 实名认证 |
| 프로바이더(IDV) | 服务商 |
| 활성화 / 비활성화 | 启用 / 停用 |
| 비회원 | 游客 |
| 결제 | 支付 |
| 환불 | 退款 |

판단이 갈린 항목:

- **회원가입 / 회원 탈퇴 → `注册` / `注销账号`** (`会员` 미사용). 코어의 「회원」은 전부 복합어이고 코어는 `사용자`(→`用户`)로 일원화되어 있어, `会员` 을 함께 쓰면 같은 대상에 두 용어가 생긴다. 등급 회원 개념이 있는 이커머스 팩에서 도입을 검토한다.
- **언어명 목록** (`user.language`, `layout_editor.locale`) — CJK 언어명은 중국어 역어(`韩语`/`日语`), 라틴 문자 표기는 원문 유지(`English`/`Tiếng Việt`).

### 정적 검사

zh-CN 팩은 다음 두 경로로 검증한다.

| 도구 | 성격 | 검사 내용 |
|---|---|---|
| `tests/Unit/Services/LanguagePack/BundledSimplifiedChinesePackTest.php` | PHPUnit (프로덕션 클래스 실호출) | manifest validator 통과 · 필드 사양 · 콘텐츠 파일 존재 · `LanguagePackPhpArrayValidator` 통과 · ko 키 대칭 · 한글 잔존 0 · `$partial` 실재 |
| `tests/Translations/zh-CN-core-parity-check.php` | standalone CLI (vendor·Laravel 불필요) | 파일 인벤토리 · 키 누락/초과 · 키 순서 · 자료형 · placeholder · HTML · URL · 빈 값 · 개행 수 · 한글/가나 잔존 · UTF-8/BOM/CRLF · `$partial` 경로 |

`BundledJapanesePacksTest` 는 검사 대상을 상수 배열로 나열하므로 새 로케일 팩을 자동으로 포함하지 않는다. **로케일 팩을 추가할 때는 대응하는 정적 검사도 함께 추가**해야 한다 — 누락되어도 오류가 나지 않아 릴리즈까지 통과한다.

### 설치 및 활성화

```bash
# 번들 소스로 설치 (자동 활성)
php artisan language-pack:install g7-core-zh-CN --source=bundled

# 확인
php artisan language-pack:list --scope=core

# 콘텐츠 수정 후 설치본 재반영 (프론트엔드 빌드 불필요)
php artisan language-pack:update g7-core-zh-CN --force
```

`supported_locales` / `translatable_locales` / `locale_names` 는 활성화 시 `LanguagePackServiceProvider::refreshSupportedLocales()` 가 자동 갱신하므로 `config/app.php` 를 편집하지 않는다.

### 게시판 모듈 팩 (g7-module-sirsoft-board-zh-CN)

`scope: module` 팩의 첫 zh-CN 사례. 대상 모듈은 `sirsoft-board` 이며, 코어 팩과 달리
**`requires.depends_on_core_locale: true`** 다 — `LanguagePackService::resolveCoreLocaleBlockedReason()`
가 scope ≠ core 인 팩에 대해 동일 locale 코어 팩의 active 여부를 강제하므로,
`g7-core-zh-CN` 이 활성이 아니면 설치가 `core_locale_missing` 으로 차단된다.

#### 입력 → 산출 매핑

| 구분 | ko 원문 소스 | 산출 위치 | 개수 |
|---|---|---|---|
| backend | `modules/_bundled/sirsoft-board/src/lang/ko/*.php` | `backend/zh-CN/*.php` | 7 |
| frontend 엔트리 | `modules/_bundled/sirsoft-board/resources/lang/ko.json` | `frontend/zh-CN.json` | 1 |
| frontend partial | `.../resources/lang/partial/ko/**/*.json` (재귀) | `frontend/partial/**/*.json` | 18 |
| seed | `module.php` 의 `getPermissions`/`getAdminMenus`/`getNotificationDefinitions`, `BoardTypeSeeder`, `module.json` | `seed/{permissions,menus,notifications,board_types,manifest}.json` | 5 |

`frontend/partial/` 은 `admin/` 서브디렉토리를 포함해 **재귀**로 대응한다.
`$partial` 경로는 원본의 `partial/ko/{name}.json` 에서 로케일 세그먼트를 제거한
`partial/{name}.json` 형태로 정규화한다.

#### 게시판 도메인 용어집

코어 용어집을 승계하되, 게시판 도메인 용어를 다음으로 고정한다.

| 한국어 | 중국어 간체 | 비고 |
|---|---|---|
| 게시판 | 版块 | 문맥과 무관하게 `版块` 로 통일. `论坛`(포럼 전체)·`留言板`(방명록)은 이 모듈의 의미와 다르므로 쓰지 않는다 |
| 게시글 | 帖子 | |
| 댓글 | 评论 | |
| 답글 / 답변글 | 回复 / 回复帖 | 게시글에 달리는 답변 게시물 |
| 대댓글 | 评论回复 | 댓글에 달리는 답글 — `帖子回复`(=답변글)와 구분 |
| 작성자 | 作者 | |
| 조회수 | 浏览量 | |
| 공지 | 公告 | |
| 비밀글 | 私密帖 | |
| 첨부파일 | 附件 | |
| 신고 | 举报 | |
| 블라인드 | 屏蔽 | 상태 라벨은 `已屏蔽` |
| 분류 / 카테고리 | 分类 | |
| 스텝 | 协管 | 게시판 보조 관리자 |
| 슬러그 | 别名 | 코어 팩과 동일 |

#### ja 팩과의 의도적 차이 — dashboard 권한

`g7-module-sirsoft-board-ja` 의 `seed/permissions.json` 은 15개 키뿐이고
`sirsoft-board.dashboard` / `sirsoft-board.dashboard.view` 가 빠져 있다.
현재 `module.php::getPermissions()` 는 `dashboard` 카테고리를 선언하므로 **ja 팩이 원본보다 오래된
상태**다. zh-CN 팩은 ja 재번역이 아니라 ko 원본을 기준으로 만들었으므로 이 2개 키를 포함해
**17개**를 갖는다. `zh-CN-board-parity-check.php` 는 이 차이를 `$intentionalSeedExtras` 에 명시해
"초과 키"로 오탐하지 않는다. ja 팩 동기화는 별도 과제다.

#### 설치 및 활성화

```bash
# 1) 코어 zh-CN 팩이 먼저 활성이어야 한다 (depends_on_core_locale: true)
php artisan language-pack:install g7-core-zh-CN --source=bundled

# 2) 대상 모듈이 active 여야 한다 (아니면 target_inactive 로 차단)
php artisan module:list

# 3) 게시판 팩 설치 (자동 활성)
php artisan language-pack:install g7-module-sirsoft-board-zh-CN --source=bundled

# 확인
php artisan language-pack:list --scope=module

# 콘텐츠 수정 후 설치본 재반영 (프론트엔드 빌드 불필요)
php artisan language-pack:update g7-module-sirsoft-board-zh-CN --force
```

#### 검증

```bash
# 원본 대조 검사기 (vendor·Laravel 불필요, 단독 실행)
php tests/Translations/zh-CN-board-parity-check.php --verbose --style

# PHPUnit — 프로덕션 validator 실호출
php artisan test --filter=BundledSimplifiedChineseBoardPackTest
```

`frontend build 불필요` — 모듈의 `package.json` 에는 build 스크립트가 없고, 언어팩 frontend JSON 은
`MergeFrontendLanguage` 가 런타임에 디스크에서 읽어 병합한다(`/api/templates/{id}/lang/{locale}.json`).

### 이커머스 모듈 팩 (g7-module-sirsoft-ecommerce-zh-CN)

`scope: module`, `target_identifier: sirsoft-ecommerce`, `requires.depends_on_core_locale: true`.
`g7-module-sirsoft-ecommerce-ja` 와 상대 경로 구성이 1:1 이며(아래 "ja 팩과의 의도적 차이" 1건 제외),
번역 원문은 전부 이커머스 모듈의 한국어 원본이다.

#### 입력 → 산출 매핑

| 구분 | ko 원문 소스 | 산출 위치 | 개수 |
|---|---|---|---|
| backend | `modules/_bundled/sirsoft-ecommerce/src/lang/ko/*.php` | `backend/zh-CN/*.php` | 13 |
| frontend 엔트리 | `modules/_bundled/sirsoft-ecommerce/resources/lang/ko.json` | `frontend/zh-CN.json` | 1 |
| frontend partial | `.../resources/lang/partial/ko/**/*.json` (재귀) | `frontend/partial/**/*.json` | 35 |
| seed | `module.php` 의 `getRoles`/`getPermissions`/`getAdminMenus`/`getNotificationDefinitions`/`getIdentityMessages`, `ClaimReasonSeeder`·`ShippingTypeSeeder`·`ShippingCarrierSeeder`, `module.json` | `seed/{roles,permissions,menus,notifications,identity_messages,claim_reasons,shipping_types,shipping_carriers,manifest}.json` | 9 |

패키지 총 60개 파일 (매니페스트 1 + CHANGELOG 1 + backend 13 + frontend 36 + seed 9).

#### 이커머스 도메인 용어집

코어·게시판 용어집을 승계하되, 커머스 도메인 용어를 다음으로 고정한다.

| 한국어 | 중국어 간체 | 비고 |
|---|---|---|
| 쇼핑몰 / 이커머스 | 商城 / 电商 | 상점 UI 는 `商城`, 모듈·권한 이름은 `电商` |
| 상품 / 상품명 / 상품 상세 | 商品 / 商品名称 / 商品详情 | |
| 상품 옵션 / 옵션 / 속성 | 商品选项 / 选项 / 属性 | |
| 카테고리 / 브랜드 | 分类 / 品牌 | |
| 장바구니 / 찜 / 바로 구매 | 购物车 / 收藏 / 立即购买 | |
| 주문 / 주문번호 / 주문자 | 订单 / 订单号 / 下单人 | |
| 결제 / 결제수단 / 결제완료 / 입금대기 | 支付 / 支付方式 / 支付完成 / 待付款 | |
| 배송 / 배송지 / 배송비 | 配送 / 收货地址 / 运费 | |
| 배송준비 / 배송중 / 배송완료 | 备货中 / 配送中 / 已送达 | |
| 송장번호 / 택배사 | 运单号 / 物流公司 | |
| 주문취소 / 취소 / 교환 / 반품 | 取消订单 / 取消 / 换货 / 退货 | |
| 환불 / 환불완료 / 구매확정 | 退款 / 退款完成 / 确认收货 | |
| 재고 / 품절 / 재입고 / 수량 | 库存 / 售罄 / 补货 / 数量 | |
| 가격 / 판매가 / 정상가 | 价格 / 售价 / 原价 | |
| 할인 / 할인금액 / 쿠폰 | 优惠 / 优惠金额 / 优惠券 | 할인코드는 `优惠码` |
| 포인트 / 마일리지 | 积分 | 이 모듈은 두 개념을 구분하지 않는다 |
| 총액 / 소계 / 세금 / 공급가 / 결제금액 | 总额 / 小计 / 税费 / 未税金额 / 支付金额 | |
| 구매자 / 판매자 / 고객 / 관리자 | 买家 / 卖家 / 客户 / 管理员 | |
| 리뷰 / 평점 / 문의 / 답변 | 评价 / 评分 / 咨询 / 回复 | |
| 영수증 / 현금영수증 / 세금계산서 | 收据 / 现金收据 / 税务发票 | 한국 제도 고유 명칭 — 중국 제도와 동일시하지 않는다 |
| 정산 / 배송정책 / 국가별 설정 | 结算 / 配送政策 / 国家/地区设置 | |
| 무통장입금 | 银行汇款 | 결제수단 설명은 `直接汇款至指定账户` — 중국 결제 수단명으로 치환하지 않는다 |

#### 보존 대상 (번역하지 않음)

- 주문·결제·배송·환불 상태의 **내부 enum key/value** (`pending`, `paid`, `shipping`, `completed`, `cancelled`, `refunded` 등) — 표시 문자열만 번역
- 통화 코드 `KRW`/`USD`/`JPY`/`CNY`/`EUR`, 기술 식별자 `SKU`/`PG`/`API`/`URL`/`VAT`/`HS`/`ISO 4217`
- `floor, round, ceil`, `GET`/`POST`, `asc`/`desc`, `text`/`html`, `domestic`/`international` 등 in-rule 리터럴
- 금액 계산식·숫자/날짜 포맷 문자열·소수점 자릿수
- ko 원문이 원화 단위(`원`)를 명시한 문장은 `韩元` 으로 옮긴다 — 삭제하거나 `元`(CNY) 로 바꾸지 않는다

#### ja 팩과의 의도적 차이

| 구분 | 내용 | 판단 |
|---|---|---|
| `frontend/partial/admin/mileage_deposit_settings.json` | ja 팩에만 존재. ko·en 원본 어디에도 없고 모듈 코드 참조 0건 | zh-CN 에서 **제외**. 검사기의 `$intentionalJaOnlyPartials` 에 명시 |
| `seed/permissions.json` | ja 에 `dashboard`/`mileage`/`user-currency`/`user-shipping-country` 카테고리 없음 | ko 원본 기준으로 **포함**(20개 카테고리) |
| `seed/menus.json` | ja 에 `sirsoft-ecommerce-mileage-transactions` 없음 | ko 원본 기준으로 **포함**(12개) |
| `seed/notifications.json` | ja 에 `order_pending_deposit`/`mileage_expiring_soon`/`order_delivered` 없음 | ko 원본 기준으로 **포함**(10종) |
| `seed/notifications.json` :: `order_confirmed` 메일 본문 | ko 원본이 수취인·배송국가·배송지 3행을 추가했으나 ja 미반영 | ko 원본 기준. 검사기의 `$intentionalSeedValueDrift` 에 명시 |

위 5건은 모두 **ja 팩(v1.1.3)이 현재 ko 원본보다 오래된** 결과다. ja 팩 동기화는 별도 과제이며,
이 작업에서 ja 팩은 수정하지 않았다.

#### 설치 및 활성화

```bash
# 1) 코어 zh-CN 팩이 먼저 활성이어야 한다 (depends_on_core_locale: true)
php artisan language-pack:install g7-core-zh-CN --source=bundled

# 2) 대상 모듈이 active 여야 한다 (아니면 target_inactive 로 차단)
php artisan module:list

# 3) 이커머스 팩 설치 (자동 활성)
php artisan language-pack:install g7-module-sirsoft-ecommerce-zh-CN --source=bundled

# 확인
php artisan language-pack:list --scope=module

# 콘텐츠 수정 후 설치본 재반영 (프론트엔드 빌드 불필요)
php artisan language-pack:update g7-module-sirsoft-ecommerce-zh-CN --force
```

#### 검증

```bash
# 원본 대조 검사기 (vendor·Laravel 불필요, 단독 실행)
php tests/Translations/zh-CN-ecommerce-parity-check.php --verbose --style

# PHPUnit — 프로덕션 validator 실호출
php artisan test --filter=BundledSimplifiedChineseEcommercePackTest
```

`frontend build 불필요` — 이커머스 모듈의 `vite.config.ts` 는 라이브러리 모드로 `resources/js/index.ts`
하나만 번들하며(산출물 `dist/js/module.iife.js` + `dist/css/module.css`), 프로덕션 TS 코드는
`resources/lang/**` 를 import 하지 않는다(참조는 `__tests__/` 뿐). 언어팩 frontend JSON 은
`MergeFrontendLanguage` 가 런타임에 디스크에서 읽어 병합한다(`/api/templates/{id}/lang/{locale}.json`).

### 페이지 모듈 팩 (g7-module-sirsoft-page-zh-CN)

`scope: module`, `target_identifier: sirsoft-page`, `requires.depends_on_core_locale: true`.
`g7-module-sirsoft-page-ja` 와 상대 경로 구성이 1:1 이며(파일 10개로 동일), 번역 원문은 전부
페이지 모듈의 한국어 원본이다.

#### 입력 → 산출 매핑

| 구분 | ko 원문 소스 | 산출 위치 | 개수 |
|---|---|---|---|
| backend | `modules/_bundled/sirsoft-page/src/lang/ko/*.php` | `backend/zh-CN/*.php` | 3 |
| frontend 엔트리 | `modules/_bundled/sirsoft-page/resources/lang/ko.json` | `frontend/zh-CN.json` | 1 |
| frontend partial | `.../resources/lang/partial/ko/admin.json` | `frontend/partial/admin.json` | 1 |
| seed | `module.php` 의 `getPermissions`/`getAdminMenus`, `module.json` | `seed/{permissions,menus,manifest}.json` | 3 |

패키지 총 10개 파일 (매니페스트 1 + CHANGELOG 1 + backend 3 + frontend 2 + seed 3).

`module.php::getRoles()` 가 빈 배열이고 알림 정의·본인인증 메시지도 선언하지 않으므로
`seed/roles.json`·`seed/notifications.json`·`seed/identity_messages.json` 은 만들지 않는다
(ja 팩도 동일). 게시판·이커머스 팩과 달리 `frontend/partial/` 에 서브디렉토리가 없다.

#### 페이지 도메인 용어집

코어 용어집을 승계하되, 페이지 도메인 용어를 다음으로 고정한다.

| 한국어 | 중국어 간체 | 비고 |
|---|---|---|
| 페이지 / 페이지 관리 | 页面 / 页面管理 | |
| 제목 / 내용 | 标题 / 内容 | |
| 작성자 / 저장자 | 作者 / 保存者 | 버전 이력의 저장 주체는 `保存者` |
| 슬러그 | 别名 | **코어·게시판 팩과 동일**. `路径别名`·`Slug` 는 쓰지 않는다 |
| 발행 / 미발행 / 발행 취소 | 发布 / 未发布 / 取消发布 | 상태 라벨은 `已发布`·`未发布` |
| 발행 여부 | 是否发布 | 코어의 `是否…` 패턴 승계 |
| 발행일시 | 发布时间 | 목록 컬럼·상세 정보 공통 |
| 버전 / 버전 이력 / 복원 | 版本 / 版本历史 / 恢复 | 복원은 `还原` 이 아닌 `恢复` (기존 팩 166:1) |
| 첨부파일 | 附件 | |
| 미리보기 | 预览 | |
| 편집 모드 / 콘텐츠 모드 | 编辑模式 / 内容模式 | frontend 는 `편집 모드`, activity_log 는 `콘텐츠 모드` — 원문 구분 유지 |
| SEO 제목 / 설명 / 키워드 | SEO 标题 / SEO 说明 / SEO 关键词 | 이커머스 팩과 동일 |
| 생성일 / 수정일 | 创建日期 / 修改日期 | |

#### 보존 대상 (번역하지 않음)

- 슬러그 값 형식(`url-slug`)과 URL 경로 `/page/{{slug}}`
- in-rule 리터럴 `html`/`text`, `asc`/`desc`, `created_at`/`published_at`, `all`/`title`/`slug`
- placeholder `:title` `:count` `:limit` `:attempted` `:max` `:min` `:locale` `:maxKB`,
  `{{count}}` `{{slug}}` `{{version}}` `{{fields}}`
- 권한 키(`sirsoft-page.pages.*`), 메뉴 slug(`sirsoft-page`), route 경로

#### ja 팩과의 차이

파일·키 집합은 ja 팩과 완전히 일치한다. 다만 **키 순서**가 3개 파일에서 다르다.

| 파일 | 차이 | 판단 |
|---|---|---|
| `backend/*/validation.php` | ja 는 `attributes.search` 를 맨 뒤에 둠 | ko 원본 순서(중간)를 따름 |
| `frontend/*.json` | ja 는 `admin` 을 `editor` 앞에 둠 | ko 원본 순서(`editor` → `admin`)를 따름 |
| `frontend/partial/admin.json` | ja 는 `versions.preview_modal_editor_label` 을 맨 뒤에 둠 | ko 원본 순서를 따름 |

zh-CN 은 ko 원본 순서를 따르며(검사기 §1·§2 가 강제), ja 와의 차이는
`zh-CN-page-parity-check.php --style` 에서 정보성 경고로 표면화한다(실패 아님).
**ja 팩은 이번 작업에서 수정하지 않았다.**

#### 설치 및 활성화

```bash
# 1) 코어 zh-CN 팩이 먼저 활성이어야 한다 (depends_on_core_locale: true)
php artisan language-pack:install g7-core-zh-CN --source=bundled

# 2) 대상 모듈이 active 여야 한다 (아니면 target_inactive 로 차단)
php artisan module:list

# 3) 페이지 팩 설치 (자동 활성)
php artisan language-pack:install g7-module-sirsoft-page-zh-CN --source=bundled

# 확인
php artisan language-pack:list --scope=module

# 콘텐츠 수정 후 설치본 재반영 (프론트엔드 빌드 불필요)
php artisan language-pack:update g7-module-sirsoft-page-zh-CN --force
```

#### 검증

```bash
# 원본 대조 검사기 (vendor·Laravel 불필요, 단독 실행)
php tests/Translations/zh-CN-page-parity-check.php --verbose --style

# PHPUnit — 프로덕션 validator 실호출
php artisan test --filter=BundledSimplifiedChinesePagePackTest
```

`frontend build 불필요` — 페이지 모듈의 `package.json` 에는 **build 스크립트가 없고**
(`test`/`test:run`/`test:e2e`/`test:e2e:ui` 뿐), 번들러 설정도 `vitest.config.ts`(테스트 전용)만
있으며 `vite.config.ts` 와 `dist/` 가 존재하지 않는다. 언어팩 frontend JSON 은
`MergeFrontendLanguage` 가 런타임에 디스크에서 읽어 병합한다(`/api/templates/{id}/lang/{locale}.json`).

### 기존 ko/en 변경 시 zh-CN 동기화 의무

코어·게시판·이커머스·페이지 모듈의 ko/en 다국어 키를 추가/수정/제거할 때마다 대응하는 zh-CN 팩
(`lang-packs/_bundled/g7-core-zh-CN/`, `lang-packs/_bundled/g7-module-sirsoft-board-zh-CN/`,
`lang-packs/_bundled/g7-module-sirsoft-ecommerce-zh-CN/`, `lang-packs/_bundled/g7-module-sirsoft-page-zh-CN/`) 의
키 셋도 동기화해야 한다. ja 팩과 동일하게, 동기화하지 않으면 중국어 화면에서 미번역
fallback(ko/en) 이 오류 없이 노출된다. zh-CN 은 자동 빌드 스크립트가 없으므로 대응 위치
(`backend/zh-CN/`, `frontend/`, `seed/`) 에 수동 반영하고 대응 parity 검사기
(`zh-CN-core-parity-check.php`, `zh-CN-board-parity-check.php`, `zh-CN-ecommerce-parity-check.php`,
`zh-CN-page-parity-check.php`) 로 확인한다.

## 의존성 검증 — 설치 차단 사유 (UI 인라인 안내)

미설치 번들 행과 설치 모달은 다음 4가지 사유로 설치 차단을 표시한다 (`LanguagePackService::resolveInstallBlockedReason()` 가 단일 SSoT). 모듈/플러그인 시스템과 동일 강도.

| 사유 키 | 발생 조건 |
| ------- | --------- |
| `core_locale_missing` | scope ≠ core + 코어 동일 locale 팩 미활성 |
| `target_not_installed` | 대상 모듈/플러그인/템플릿이 DB 에 없음 |
| `target_inactive` | 대상은 설치되었지만 status ≠ active |
| `target_version_too_old` | manifest `requires.target_version` 미충족 |

`LanguagePackResource::resourceMeta()` 가 차단 사유 존재 시 `abilities.can_install` 을 false 로 강제 — UI 행/모달 버튼이 권한과 무관하게 disabled.

## is_protected 정책 (모듈/플러그인/템플릿과 일관)

번들 언어팩(`lang-packs/_bundled/*`) 은 manifest 의 `is_protected` 선언을 따른다. 기본값은 `false` — 모듈/플러그인/템플릿이 번들이라도 자유 제거 가능한 것과 동일.

`LanguagePackBundledRegistrar` 가 동기화하는 `bundled_with_extension` 레코드(모듈/플러그인/템플릿 자체에 포함된 lang 디렉토리에서 자동 등록되는 가상 행) 만 `is_protected: true` 로 고정 — 부모 확장 lifecycle 에 종속되어 독립 제거가 불가하기 때문.

## Manifest 미리보기 (3종 확장과 동등)

ZIP 업로드 전 manifest 검증 결과를 사전 확인할 수 있다. 모듈/플러그인/템플릿 시스템에도 동일 패턴으로 추가됨.

- 라우트: `POST /api/admin/{language-packs|modules|plugins|templates}/manifest-preview`
- 응답: `{ manifest, validation: { errors[], is_valid, already_installed, existing_version } }`
- 검증 실패 시에도 HTTP 200 — `validation.is_valid: false` + `errors[]` 노출
- UI: 각 도메인의 수동 설치 모달(`_modal_manual_install.json`) 에 "manifest 미리보기" 버튼 → 별도 드로어 partial(`_drawer_manifest_preview.json`) 로 ZIP 업로드 + 결과 표시
- 드로어 작성 패턴: [modal-usage.md ZIP 파일 업로드 모달/드로어 패턴](../frontend/modal-usage.md#zip-파일-업로드-모달드로어-패턴-multipart-백엔드)

## 관련 파일

- `app/Models/LanguagePack.php`
- `app/Services/LanguagePackService.php`
- `app/Services/LanguagePack/LanguagePackRegistry.php`
- `app/Services/LanguagePack/LanguagePackManifestValidator.php`
- `app/Services/LanguagePack/LanguagePackSeedInjector.php`
- `app/Services/LanguagePack/LanguagePackTranslator.php`
- `app/Listeners/LanguagePack/SyncDatabaseTranslations.php`
- `app/Listeners/LanguagePack/MergeFrontendLanguage.php`
- `app/Providers/LanguagePackServiceProvider.php`
- `app/Http/Controllers/Api/Admin/LanguagePackController.php`
- `database/migrations/2026_04_27_000001_create_language_packs_table.php`
- `templates/_bundled/sirsoft-admin_basic/layouts/admin_language_pack_list.json`
