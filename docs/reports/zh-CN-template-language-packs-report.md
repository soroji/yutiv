# G7 7.0.9 템플릿 중국어 간체(zh-CN) 언어팩 최종 보고서

- 작성일: 2026-09-08
- 기준 커밋: `df4c8bed46229c3b25c7d8880db3389ff91a61c1` (branch `main`)
- 대상: `sirsoft-admin_basic` (관리자 기본 템플릿) · `sirsoft-basic` (사용자 기본 템플릿)
- 결과: **GO** — 두 팩 모두 정합성 검사 PASS (대조 키 6,377개 / 위반 0건)
- commit·push·서버 접속·서버 배포는 **수행하지 않았다.**

---

## 1. 작업 전 점검 결과

| 항목 | 결과 |
| --- | --- |
| 기준 커밋 일치 | ✅ `git rev-parse HEAD` = `df4c8bed46229c3b25c7d8880db3389ff91a61c1` |
| 브랜치 | `main` |
| 작업 트리 | 시작 시점 clean (uncommitted 변경 0) |
| 기존 zh-CN 검사기 | core · board · ecommerce · page · plugins 5종 모두 시작 시점 PASS |
| 로컬 PHP | 7.4.22 (`composer.json` 은 `^8.2` 요구) |
| `vendor/` | 존재하나 **비어 있음** (`vendor/autoload.php` 없음) → PHPUnit·artisan 실행 불가 |
| 의존성 설치 | 수행하지 않음 (`composer install` / `npm install` / build 모두 미실행) |
| 기존 사용자 변경 | 발견되지 않음 (덮어쓰거나 되돌린 파일 없음) |

---

## 2. 템플릿 전체 조사 매트릭스

`templates/_bundled/` 전수 조사 결과와 zh-CN 팩 제작 여부다.

| 템플릿 | vendor | 버전 | type | ko 번역 자산 | 기존 언어팩 | zh-CN 팩 | 판단 근거 |
| --- | --- | --- | --- | --- | --- | --- | --- |
| `sirsoft-admin_basic` | sirsoft | 1.0.7 | admin | `lang/ko.json` + `lang/partial/ko/*.json` 10종 | ja | ✅ 생성 | 운영 서버 설치·활성 관리자 템플릿 |
| `sirsoft-basic` | sirsoft | 1.1.2 | user | `lang/ko.json` + `lang/partial/ko/*.json` 21종 | ja | ✅ 생성 | 운영 서버 설치·활성 사용자 템플릿 |
| `gnuboard7-hello_admin_template` | gnuboard7 | — | admin | (학습용 최소 구현) | ja / en / ko | ❌ 제외 | **운영 서버에서 설치·활성 템플릿이 아니며 학습용 샘플입니다.** |
| `gnuboard7-hello_user_template` | gnuboard7 | — | user | (학습용 최소 구현) | ja / en / ko | ❌ 제외 | **운영 서버에서 설치·활성 템플릿이 아니며 학습용 샘플입니다.** |

제외한 2종의 기존 ja/en/ko 언어팩은 **읽기만 했고 수정하지 않았다.**

---

## 3. 생성한 zh-CN 팩

| 팩 식별자 | target | 팩 버전 | 파일 수 | 번역 파일 | 대조 키 |
| --- | --- | --- | --- | --- | --- |
| `g7-template-sirsoft-admin_basic-zh-CN` | `sirsoft-admin_basic` | 1.0.0 | 14 | 12 (엔트리 1 + partial 10 + seed 1) | 3,718 |
| `g7-template-sirsoft-basic-zh-CN` | `sirsoft-basic` | 1.0.0 | 25 | 23 (엔트리 1 + partial 21 + seed 1) | 2,659 |
| **합계** | | | **39** | **35** | **6,377** |

디렉토리 구조 (두 팩 공통):

```
lang-packs/_bundled/g7-template-sirsoft-{target}-zh-CN/
├── language-pack.json      # manifest (4-space)
├── CHANGELOG.md            # Keep a Changelog
├── frontend/
│   ├── zh-CN.json          # 엔트리 ($partial 디렉티브)
│   └── partial/*.json      # 부분 번역 (2-space)
└── seed/
    └── manifest.json       # 템플릿 표시명·설명
```

**`backend/` 디렉토리는 만들지 않았다.** 템플릿에는 PHP 번역 원본이 없어 만들 근거가 없고, 언어팩의 PHP 는 활성화 시 `require` 되므로 불필요한 실행 표면을 만들지 않는다.

### 파일별 번역 leaf 수

`sirsoft-admin_basic` (엔트리 10 + partial 3,708 = 3,718)

| 파일 | leaf | 파일 | leaf |
| --- | ---: | --- | ---: |
| `admin.json` | 2,356 | `extensions.json` | 26 |
| `editor.json` | 956 | `errors.json` | 19 |
| `layout_editor.json` | 175 | `attachment.json` | 15 |
| `common.json` | 79 | `nav.json` | 1 |
| `countries.json` | 48 | | |
| `auth.json` | 33 | | |

`sirsoft-basic` (엔트리 21 + partial 2,638 = 2,659)

| 파일 | leaf | 파일 | leaf |
| --- | ---: | --- | ---: |
| `editor.json` | 722 | `home.json` | 30 |
| `shop.json` | 584 | `error.json` | 22 |
| `mypage.json` | 428 | `attachment.json` | 12 |
| `board.json` | 201 | `footer.json` | 12 |
| `user.json` | 131 | `nav.json` | 11 |
| `auth.json` | 126 | `timezones.json` | 7 |
| `policy.json` | 102 | `userinfo.json` | 5 |
| `layout_editor.json` | 86 | `upload.json` | 4 |
| `common.json` | 59 | `sirsoft-basic.json` | 3 |
| `countries.json` | 48 | `languages.json` | 2 |
| `search.json` | 43 | | |

---

## 4. manifest 계약 근거

두 팩 모두 아래 값으로 고정했다.

| 필드 | 값 | 근거 |
| --- | --- | --- |
| `namespace` | `g7` | 모든 G7 공식 번들의 공통 prefix |
| `vendor` | `sirsoft` | 대상 `template.json` 의 `vendor` 와 동일 |
| `scope` | `template` | `LanguagePackManifestValidator` 가 허용하는 4종 중 템플릿 |
| `target_identifier` | `sirsoft-admin_basic` / `sirsoft-basic` | `template.json` 의 `identifier` |
| `identifier` | `{namespace}-{scope}-{target}-{locale}` | `validateIdentifierNaming()` 공식 |
| `locale` | `zh-CN` | BCP-47 패턴 + registrar 디렉토리 스캔 패턴 `/^[a-z]{2,3}(-[A-Z]{2})?$/` 동시 통과 |
| `locale_name` | `Simplified Chinese` | 사양 |
| `locale_native_name` | `简体中文` | 사양 |
| `text_direction` | `ltr` | 사양 |
| `version` | `1.0.0` | 최초 릴리즈 |
| `license` | `MIT` | 대상 `template.json` 의 `license` 와 동일 |
| `g7_version` | `>=7.0.0` | 기존 zh-CN 팩 16종·대응 ja 팩과 동일. `template.json` 의 `>=7.0.9` 는 템플릿 자체의 코어 요구치이지 언어팩의 요구치가 아니다 |
| `requires.target_version` | `null` | 버전 제약 없음 (ja 팩과 동일) |
| `requires.depends_on_core_locale` | `true` | scope ≠ core → 동일 locale 코어 팩 활성 전제 (`resolveCoreLocaleBlockedReason()`) |
| `github_url` / `github_changelog_url` | `""` | 번들 배포 (기존 zh-CN 팩과 동일) |

---

## 5. 정합성 검사 결과

```
php tests/Translations/zh-CN-templates-parity-check.php --verbose --style
```

```
=== g7-template-sirsoft-admin_basic-zh-CN parity check ===
검사 파일 12개 (JSON 12), 대조 키 3718개
… 22개 항목 전부 OK (0)
RESULT: PASS — 위반 0건

=== g7-template-sirsoft-basic-zh-CN parity check ===
검사 파일 23개 (JSON 23), 대조 키 2659개
… 22개 항목 전부 OK (0)
RESULT: PASS — 위반 0건

검사 팩 2개 · 통과 2개 · 실패 0개 · 파일 35개 · 대조 키 6377개 · 총 위반 0건
RESULT: ALL PASS
```

### 검사 항목 (26개 요구 항목 → 22개 리포트 카테고리)

| # | 항목 | 결과 |
| --- | --- | --- |
| 1 | 파일 누락 (ko 원본 대비) | OK (0) |
| 2 | 초과 파일 (팩에만 있는 파일) | OK (0) |
| 3 | 구조 오류 (필수 디렉토리·엔트리 존재, `backend/` 부재) | OK (0) |
| 4 | JSON 구문 | OK (0) |
| 5 | PHP 배열 구조 (템플릿 팩은 PHP 0개 — 존재 자체를 위반으로 본다) | OK (0) |
| 6 | 키 누락 | OK (0) |
| 7 | 초과 키 | OK (0) |
| 8 | 키 순서 불일치 (ko 원본 기준) | OK (0) |
| 9 | 자료형 불일치 (string/array/int/bool/null) | OK (0) |
| 10 | placeholder 불일치 (`:name` · `{{x}}` · `{x}` · `%s` — 종류 + 등장 횟수) | OK (0) |
| 11 | HTML 태그 불일치 (태그명·속성 보존) | OK (0) |
| 12 | URL 불일치 (URL·경로 토큰 보존) | OK (0) |
| 13 | 빈 값 불일치 (원문이 빈 문자열일 때만 빈 문자열 허용) | OK (0) |
| 14 | 개행(`\n`) 개수 불일치 | OK (0) |
| 15 | 한글 잔존 | OK (0) |
| 16 | 일본어 가나 잔존 | OK (0) |
| 17 | 인코딩 오류 (UTF-8 유효성 · CRLF) | OK (0) |
| 18 | BOM 검출 | OK (0) |
| 19 | `$partial` 경로 오류 (실재 파일 여부 · 로케일 세그먼트 정규화) | OK (0) |
| 20 | manifest 계약 (scope · version · vendor/license 대조 · 네이밍 공식 · `target_version: null` · `depends_on_core_locale: true`) | OK (0) |
| 21 | 보안 규칙 (허용 확장자 · 심볼릭 링크 · 실행 코드 · 비밀값 패턴) | OK (0) |
| 22 | ja 팩 구조 차이 (파일 인벤토리 대조) | OK (0) |
| 23 | seed 계약 (`name`/`description` 2키) | §7 |
| 24 | CHANGELOG 존재·인코딩 | §6 |
| 25 | 표기 스타일 (CJK 인접 반각 구두점) | 경고 0건 |
| 26 | ja 키 순서 드리프트 | 정보성 경고 14건 (§8) |

**스타일 경고 (실패 아님) 총 14건** — 전부 "ja 팩과 키 순서가 다름(집합은 동일), zh 는 ko 순서를 따른다" 유형이다. admin_basic 3건(`admin` · `editor` · `layout_editor`), basic 11건(`attachment` · `auth` · `board` · `common` · `editor` · `error` · `mypage` · `policy` · `search` · `shop` · `user`).

---

## 6. JSON / 인코딩 / 보안

| 검사 | 명령 | 결과 |
| --- | --- | --- |
| JSON 파싱 | `json_decode` 전수 | 35/35 유효 |
| UTF-8 without BOM | `head -c3 \| od` 전수 | BOM 0건 |
| LF 개행 | `grep -qU $'\r'` 전수 | CR 0건 |
| 파일 끝 개행 | `tail -c1` 전수 | 39/39 LF 로 끝남 |
| 허용 확장자 | `.json` / `.md` 만 | 위반 0건 |
| 심볼릭 링크 | `is_link()` 전수 | 0건 |
| 실행 가능 코드 | PHP 파일 0개 | 해당 없음 |
| 외부 네트워크 호출·파일 쓰기·명령 실행 | 팩 내 코드 없음 | 해당 없음 |
| PHP 문법 (검사기·테스트) | `php -l` × 3 | 전부 통과 |
| `git diff --check` | 공백 오류 | 0건 |

---

## 7. seed 검증

두 팩 모두 `seed/manifest.json` 1개만 둔다. 템플릿은 권한·역할·메뉴·알림 정의를 선언하지 않으므로 다른 seed 파일이 없다 (ja 팩과 동일 계약).

| 팩 | `name` | `description` |
| --- | --- | --- |
| admin_basic | `Admin Basic` | `Gnuboard7 默认管理员模板` |
| basic | `Basic` | `Gnuboard7 默认用户模板` |

- `name` 은 `template.json` 의 `name.en` 과 동일한 고유명이므로 번역하지 않았다 (ja 팩도 동일).
- `description` 은 ko 원문(`그누보드7 기본 관리자 템플릿` / `그누보드7 기본 사용자 템플릿`)을 간체 중국어로 옮겼다.
- 키는 `name` · `description` 2개로 고정. PHPUnit 이 `array_keys()` 로 이를 단언한다.

---

## 8. ja 팩과의 차이

| # | 대상 | 차이 | 판단 |
| --- | --- | --- | --- |
| 1 | 두 팩 전체 | 키 **집합**은 ko 원본과 ja 팩이 완전히 동일 (누락 0 · 잉여 0) | 의도적 차이 상수를 선언할 필요가 없었다. 검사기의 `seed_extras` · `seed_missing` · `ja_only_partials` · `url_exempt` 는 모두 빈 배열이다 |
| 2 | admin_basic `admin`·`editor`·`layout_editor` / basic `attachment`·`auth`·`board`·`common`·`editor`·`error`·`mypage`·`policy`·`search`·`shop`·`user` | ja 가 ko 와 **키 순서**가 다름 (집합은 동일) | zh-CN 은 **ko 순서**를 따른다. `--style` 에서 정보성 경고 14건으로 표면화 |
| 3 | `countries.json` 의 `KR` | admin_basic 원본은 `한국`, basic 원본은 `대한민국` | 원본 차이를 그대로 반영해 각각 `韩国` · `大韩民国` |
| 4 | `seed/manifest.json` `description` | ja 는 `GNU Board 7 基本管理者テンプレート` / `Gnuboard7 基本ユーザーテンプレート` (표기 불일치) | zh-CN 은 두 팩 모두 `Gnuboard7` 로 통일 |

**ja 팩은 이번 작업에서 수정하지 않았다.**

---

## 9. 번역 판단표 (템플릿 도메인)

지정 용어집을 그대로 적용하고, 템플릿 화면에서 새로 등장한 표기를 아래로 고정했다.

| 한국어 | zh-CN | 판단 |
| --- | --- | --- |
| 레이아웃 | 布局 | 레이아웃 편집기 전반 |
| 위지윅 편집 | 所见即所得编辑 | |
| 컨테이너 | 容器 | |
| 플렉스 / 그리드 | Flex / Grid | 컴포넌트 식별자(`flex`/`grid`)와 1:1 대응이라 원문 유지 |
| 배지 | 徽章 | |
| 아코디언 | 折叠面板 | |
| 드롭다운 | 下拉菜单 | |
| 스켈레톤 화면 | 骨架屏 | |
| 블라인드(게시글) | 屏蔽 | |
| 비밀글 | 私密帖子 | |
| 비회원 | 非会员 | |
| 찜 목록 | 收藏列表 | 컴포넌트명 `wishlist` 데이터소스는 `心愿单` |
| 구매확정 | 确认收货 | |
| 재주문 | 再次购买 | |
| 마일리지 | 积分 | 이커머스 팩 표기 승계 |
| 예치금 | 预存款 | 이커머스 팩 표기 승계 |
| 무통장입금 / 가상계좌 | 银行汇款 / 虚拟账户 | 이커머스 팩 표기 승계 |
| 스케줄 | 计划任务 | |
| 행위자(로그) | 操作者 | |
| 프로바이더 | 提供商 | 본인인증 |
| 강제 시점 / 강제 위치 | 强制时点 / 强制位置 | 본인인증 정책 |
| 본인인증 | 实名认证 | 코어 팩 표기 승계 |
| 슬러그 | 别名 | 코어 팩 표기 승계 |
| 원(통화) | 韩元 | 코어 팩 표기 승계 |
| 한국어(언어 라벨) | 韩语 | `English` 는 원문 유지 |
| 등록 | 注册 / 添加 / 发表 | 문맥 구분 — 회원가입은 注册, 항목 추가는 添加, 글·댓글은 发表 |

### 보존 대상 (번역하지 않음)

- 컴포넌트 식별자 키 · 상태 enum (`pending` · `paid` · `shipped` · `sold_out` …)
- 라우트·이벤트 이름 예시: `api.auth.register` · `core.auth.after_register`
- 경로·파일명: `/storage/logs/` · `laravel.log` · `sitemap.xml` · `module.json` · `plugin.json` · `template.json`
- 설정 키·코드 조각: `opcache.enable=1` · `location ~* \.(js|css|json|png|jpg|jpeg|gif|ico|svg|woff2?)$` · `$ sudo php artisan core:update` · `socks5h://127.0.0.1:1080`
- 브랜드·기술명: GitHub · Composer · Laravel · Reverb · Redis · Memcached · Mailgun · AWS · MaxMind GeoLite2 · CKEditor · Naver · Kakao · Google · Twitter · Facebook · Slack · Artisan · Cron
- URL·이메일: `https://www.maxmind.com/en/geolite2/signup` · `minsup@sir.kr` · `api.mailgun.net`
- 서술용 중괄호 토큰은 값만 번역: `"GnuBoard7 {버전}"` → `"GnuBoard7 {版本}"`, `{변수명}` → `{变量名}`.
  둘 다 코드가 치환하는 placeholder 가 아니라 안내 문구다 (`app/Helpers/settings_helpers.php:142` 주석이 전자의 근거이며, ja 팩도 각각 `{バージョン}` · 동일 처리로 번역했다). placeholder 정규식 `/\{[A-Za-z0-9_.$]+\}/u` 에 걸리지 않으므로 검사기 판정에도 영향이 없다.

---

## 10. frontend build 필요 여부

**불필요.** 코드 근거:

- 언어팩 JSON 은 `app/Listeners/LanguagePack/MergeFrontendLanguage.php` 가 런타임에 디스크에서 `File::get()` 으로 읽어 병합한다. 번들에 인라인되지 않는다.
- 템플릿의 `lang/**` 는 번들 JS 진입점이 `import` 하지 않는다 — `templates/_bundled/{target}/` 의 자산 그래프에 `lang/` 참조가 없다.
- 두 팩 모두 `.json` / `.md` 만 포함하므로 빌드 산출물이 생기지 않는다.

따라서 `npm install` · `npm run build` 를 실행하지 않았고, 실행할 필요도 없다.

---

## 11. 검증 도구

| 파일 | 역할 |
| --- | --- |
| `tests/Translations/lib/zh-CN-template-parity-lib.php` | 템플릿 전용 공용 검사 라이브러리. `zh-CN-plugin-parity-lib.php` 를 `require_once` 해 순수 헬퍼(평탄화·placeholder·HTML·URL·한글/가나·인코딩·보안)를 재사용하고, 템플릿 자산 배치(backend 없음, `lang/ko.json` + `lang/partial/ko/`)에 맞춘 `zhcnTemplateParityCheck()` · `zhcnCollectFiles()` · `zhcnCheckTemplateManifest()` 를 추가한다 |
| `tests/Translations/zh-CN-templates-parity-check.php` | 진입점. 두 대상을 순회하며 팩별 리포트를 출력하고, 하나라도 위반이 있으면 exit 1 |
| `tests/Unit/Services/LanguagePack/BundledSimplifiedChineseTemplatePacksTest.php` | 데이터셋 기반 PHPUnit. 프로덕션 `LanguagePackManifestValidator` 를 실제로 호출한다. **DB 를 만들거나 바꾸지 않는다** — `RefreshDatabase` 를 쓰지 않고 파일 시스템만 읽는다 |

검사 로직을 라이브러리 1개로 모은 이유: 대상이 2종이라 진입점을 분리하면 규칙이 갈라질 수 있다. 진입점은 대상 식별자와 ja 대비 의도적 차이만 선언한다.

### PHPUnit 테스트 케이스

| 테스트 | 검증 |
| --- | --- |
| `test_manifest_passes_production_validator` | 프로덕션 validator 실호출 |
| `test_manifest_fields_match_specification` | scope · vendor · target · locale · version · `target_version: null` · `depends_on_core_locale: true` · 네이밍 공식 · 다국어 name/description |
| `test_target_template_exists_and_metadata_matches` | `template.json` 실재 · vendor/license 일치 |
| `test_content_files_exist_and_no_backend_directory` | 엔트리·partial·seed·CHANGELOG 존재, `backend/` 부재, seed 키 2개 |
| `test_frontend_keys_match_korean_origin` | 엔트리·partial 키 셋 **및 순서** 대칭 |
| `test_no_korean_characters_remain_in_translated_values` | 한글 잔존 0 |
| `test_no_japanese_kana_remain_in_translated_values` | 가나 잔존 0 (ja 팩 복사 사고 방지) |
| `test_partial_directives_resolve_to_existing_files` | `$partial` 실재 |
| `test_placeholders_match_korean_origin` | placeholder 종류 + 개수 |
| `test_pack_contains_only_allowed_files` | 확장자 화이트리스트 · 심볼릭 링크 |

---

## 12. 실행하지 못한 테스트와 이유

| 명령 | 상태 | 이유 |
| --- | --- | --- |
| `php artisan test --filter=BundledSimplifiedChineseTemplatePacksTest` | **미실행** | 로컬 PHP 7.4.22, `composer.json` 은 `^8.2` 요구. `vendor/` 가 비어 있어 `vendor/autoload.php` 가 없다. 의존성 설치는 이번 작업의 금지 사항이다 |
| `php artisan language-pack:install …` | **미실행** | 위와 동일 + 운영 DB 변경 금지 |
| `npm run build` | **미실행** | §10 근거로 불필요하며, build 실행도 금지 사항이다 |

**대체 검증** — PHPUnit 단언을 프레임워크 밖에서 그대로 재현하는 스크립트를 작성해 실행했고, 두 팩 34개 단언이 전부 PASS 했다.

- manifest 규칙: BCP-47 패턴 · registrar 스캔 패턴 · 네이밍 공식을 `LanguagePackManifestValidator` 의 정규식으로 재구현해 검증
- 나머지 9개 테스트: 동일 로직을 그대로 옮겨 실행
- 결과: `ALL PASS` (admin_basic 34/34 · basic 34/34)

프로덕션 validator 본체 호출만은 재현이 아니라 실제 실행이 필요하므로, **서버에서 아래 명령으로 확인해야 한다.**

```bash
php artisan test --filter=BundledSimplifiedChineseTemplatePacksTest
```

이 항목이 확인되기 전까지 "PHPUnit 통과"라고 보고하지 않는다.

---

## 13. 변경 파일

### 신규 (43개)

**언어팩 (39)**

```
lang-packs/_bundled/g7-template-sirsoft-admin_basic-zh-CN/
  language-pack.json · CHANGELOG.md · seed/manifest.json
  frontend/zh-CN.json
  frontend/partial/{admin,attachment,auth,common,countries,editor,errors,extensions,layout_editor,nav}.json

lang-packs/_bundled/g7-template-sirsoft-basic-zh-CN/
  language-pack.json · CHANGELOG.md · seed/manifest.json
  frontend/zh-CN.json
  frontend/partial/{attachment,auth,board,common,countries,editor,error,footer,home,languages,
                    layout_editor,mypage,nav,policy,search,shop,sirsoft-basic,timezones,
                    upload,user,userinfo}.json
```

**검증 (3)**

```
tests/Translations/lib/zh-CN-template-parity-lib.php
tests/Translations/zh-CN-templates-parity-check.php
tests/Unit/Services/LanguagePack/BundledSimplifiedChineseTemplatePacksTest.php
```

**보고서 (1)**

```
docs/reports/zh-CN-template-language-packs-report.md
```

### 수정 (4개)

| 파일 | 변경 |
| --- | --- |
| `README.md` | 번들 언어팩 표에 2행 추가 |
| `README.ko.md` | 번들 언어팩 표에 2행 추가 |
| `CHANGELOG.md` | `[Unreleased] > Added` 에 1행 추가 |
| `docs/extension/language-packs.md` | 제공 범위 문장 갱신 · 패키지 구성 표 2행 추가 · `### 템플릿 팩` 절 신설 · 동기화 의무 문단 갱신 (총 +129 / −4) |

### 수정하지 않은 것

`app/**` · `bootstrap/**` · `config/**` · `database/**` · `modules/**` · `plugins/**` · `templates/**` · `routes/**` · `resources/**` · 기존 언어팩 전체(ja/en/ko 포함) · `package.json` 및 lock · `composer.json` 및 `composer.lock` · `.env` 계열 · 운영 DB · 설치본 언어팩 디렉토리(`lang-packs/{identifier}/`) · `lang-packs/_pending/**` — **전부 무변경.**

---

## 14. 예상하지 못한 변경 / 감사 결과

```
$ git status --short
 M CHANGELOG.md
 M README.ko.md
 M README.md
 M docs/extension/language-packs.md
?? docs/reports/zh-CN-template-language-packs-report.md
?? lang-packs/_bundled/g7-template-sirsoft-admin_basic-zh-CN/
?? lang-packs/_bundled/g7-template-sirsoft-basic-zh-CN/
?? tests/Translations/lib/zh-CN-template-parity-lib.php
?? tests/Translations/zh-CN-templates-parity-check.php
?? tests/Unit/Services/LanguagePack/BundledSimplifiedChineseTemplatePacksTest.php

$ git diff --check
(출력 없음)
```

**예상하지 못한 변경 없음.** 허용 범위 밖 변경 0건. 삭제·이름변경 0건.

---

## 15. 기존 zh-CN 검사기 회귀

작업 후 기존 5종을 다시 실행했다.

| 검사기 | 결과 |
| --- | --- |
| `zh-CN-core-parity-check.php` | PASS |
| `zh-CN-board-parity-check.php` | PASS |
| `zh-CN-ecommerce-parity-check.php` | PASS |
| `zh-CN-page-parity-check.php` | PASS |
| `zh-CN-plugins-parity-check.php` | PASS |
| `zh-CN-templates-parity-check.php` (신규) | PASS |

---

## 16. 런타임 동작 근거

- **병합 경로**: `MergeFrontendLanguage` 가 팩의 `frontend/partial/*.json` 을 basename 으로 먼저 적재한 뒤, 루트 `zh-CN.json` 의 `$partial` 을 해석해 트리를 합친다. 팩의 `$partial` 값이 `partial/{name}.json` 로 정규화되어 있어야 하며, 두 팩 모두 그 형태다 (검사기 항목 19).
- **설치 차단**: scope ≠ core 이므로 `g7-core-zh-CN` 이 활성이 아니면 `core_locale_missing` 으로 차단된다. 대상 템플릿이 없거나 비활성이면 `target_not_installed` / `target_inactive` 로 차단된다.
- **슬롯**: 슬롯 키가 `(scope, target_identifier, locale)` 이므로 두 팩은 서로 다른 슬롯이며, 기존 ja 팩과도 다른 슬롯이다. 동시 설치·동시 활성이 가능하다.

---

## 17. 보안 점검

| 항목 | 결과 |
| --- | --- |
| PHP 파일 | 0개 — 실행 표면 없음 |
| 심볼릭 링크 | 0개 |
| 외부 네트워크 호출 코드 | 없음 |
| 파일 쓰기 / 명령 실행 코드 | 없음 |
| 비밀값·자격증명 패턴 | 검출 0건 (검사기 항목 21) |
| 확장자 화이트리스트(`.json`/`.md`) | 위반 0건 |
| `.env` · 시크릿 출력 | 하지 않음 |
| DB 접근 | 없음 — 검사기·테스트 모두 파일 시스템만 읽는다 |

---

## 18. 문서 반영

| 문서 | 반영 내용 |
| --- | --- |
| `README.md` | Bundled language packs 표에 `g7-template-sirsoft-admin_basic-zh-CN` · `g7-template-sirsoft-basic-zh-CN` 2행 추가 (ja 행 바로 아래, 알파벳 순 유지) |
| `README.ko.md` | 번들 언어팩 표에 대응 2행 추가 |
| `CHANGELOG.md` | `[Unreleased] > Added` 에 템플릿 2종 항목 추가. 관리자·사용자 화면에서 무엇이 중국어로 보이게 되는지와, 라우트 이름·상태 enum·설정 키·컴포넌트 식별자가 변경되지 않는다는 점을 명시 |
| `docs/extension/language-packs.md` | ① 제공 범위 문장을 "코어 1 + 모듈 3 + 플러그인 11 + 템플릿 2"로 갱신 ② 패키지 구성 표에 template 2행 추가 ③ `### 템플릿 팩 (g7-template-sirsoft-*-zh-CN, 2종)` 절 신설 — 대상 선정 근거, 입력→산출 매핑, backend 부재 계약, 도메인 용어집, 보존 대상, ja 차이, 검증 명령, 설치 순서 ④ 동기화 의무 문단에 템플릿 2종과 신규 검사기 추가 |

---

## 19. 설치 순서 (서버)

```bash
# 1) 코어 zh-CN 팩이 먼저 활성이어야 한다 (depends_on_core_locale: true)
php artisan language-pack:install g7-core-zh-CN --source=bundled

# 2) 대상 템플릿이 active 인지 확인 (아니면 target_inactive 로 차단)
php artisan template:list

# 3) 템플릿 팩 설치 (자동 활성) — 서로 의존하지 않으므로 순서는 자유
php artisan language-pack:install g7-template-sirsoft-admin_basic-zh-CN --source=bundled
php artisan language-pack:install g7-template-sirsoft-basic-zh-CN --source=bundled

# 4) 확인
php artisan language-pack:list --scope=template
```

---

## 20. 서버 검증 명령 (권장 순서)

```bash
# 1) 정합성 검사 (vendor 불필요)
php tests/Translations/zh-CN-templates-parity-check.php --verbose --style

# 2) 기존 zh-CN 검사기 회귀
php tests/Translations/zh-CN-core-parity-check.php
php tests/Translations/zh-CN-board-parity-check.php
php tests/Translations/zh-CN-ecommerce-parity-check.php
php tests/Translations/zh-CN-page-parity-check.php
php tests/Translations/zh-CN-plugins-parity-check.php --style

# 3) PHPUnit (프로덕션 validator 실호출)
php artisan test --filter=BundledSimplifiedChineseTemplatePacksTest

# 4) 언어팩 관련 기존 스위트 회귀
php artisan test --filter=BundledJapanesePacksTest
php artisan test --filter=BundledSimplifiedChinese

# 5) 정적 분석 (프로젝트 표준)
./vendor/bin/pint --test
./vendor/bin/phpstan analyse
```

---

## 21. 롤백

commit 하지 않았으므로 되돌리기는 파일 단위다.

```powershell
# 신규 팩·검사기·테스트 제거
Remove-Item -Recurse -Force "lang-packs\_bundled\g7-template-sirsoft-admin_basic-zh-CN"
Remove-Item -Recurse -Force "lang-packs\_bundled\g7-template-sirsoft-basic-zh-CN"
Remove-Item -Force "tests\Translations\lib\zh-CN-template-parity-lib.php"
Remove-Item -Force "tests\Translations\zh-CN-templates-parity-check.php"
Remove-Item -Force "tests\Unit\Services\LanguagePack\BundledSimplifiedChineseTemplatePacksTest.php"
Remove-Item -Force "docs\reports\zh-CN-template-language-packs-report.md"

# 문서 4종 원복
git checkout -- README.md README.ko.md CHANGELOG.md docs/extension/language-packs.md
```

설치 후 롤백이라면 언어팩을 제거하면 된다 — 코어·템플릿 코드를 건드리지 않았으므로 제거 즉시 ko/en 으로 되돌아간다.

```bash
php artisan language-pack:uninstall g7-template-sirsoft-basic-zh-CN
php artisan language-pack:uninstall g7-template-sirsoft-admin_basic-zh-CN
```

---

## 22. 위험 요소와 판단

| # | 위험 | 판단 |
| --- | --- | --- |
| 1 | 로컬에서 PHPUnit·artisan 을 실행하지 못했다 | 대체 검증(§12)으로 34개 단언을 재현했으나, 프로덕션 validator 본체 호출은 서버 확인이 필요하다. **이 항목을 통과로 보고하지 않는다** |
| 2 | `admin.json` 2,356 · `editor.json` 956 등 대형 파일의 번역 품질 | 키·순서·자료형·placeholder·HTML·URL·개행은 기계 검사로 100% 보장된다. 문장 자연스러움은 기계로 잡히지 않으므로 운영 화면에서의 육안 확인을 권장한다 |
| 3 | ja 팩 키 순서 드리프트 14건 | zh-CN 은 ko 원본을 따르므로 정상이다. ja 팩을 동기화하면 경고가 사라진다 (이번 작업 범위 밖) |
| 4 | 템플릿 버전 상승 시 키 드리프트 | `template.json` 버전이 오르면 `lang/` 키가 바뀔 수 있다. 팩의 `requires.target_version` 이 `null` 이라 설치는 계속되고 미번역만 조용히 폴백된다 → §18 의 동기화 의무 문단이 재발 방지 장치다 |

---

## 23. 후속 과제

1. **서버에서 PHPUnit 실행** — §20 의 3)·4). 이 확인 전에는 "PHPUnit 통과"로 취급하지 않는다.
2. **ja 팩 키 순서 정리** — §8 의 14건은 ja 팩이 ko 원본과 순서가 어긋난 결과다. ja 팩을 동기화하면 스타일 경고가 0 이 된다.
3. **ko/en 변경 시 동기화** — 두 템플릿의 `lang/**` 키를 바꿀 때마다 대응 zh-CN 팩도 갱신하고 `zh-CN-templates-parity-check.php` 로 확인해야 한다. 누락되면 중국어 화면에서 오류 없이 ko/en 폴백이 노출된다.
4. **학습용 템플릿 2종** — `gnuboard7-hello_{admin,user}_template` 는 운영 대상이 아니므로 이번에 제외했다. 학습 자료의 다국어 완결성을 위해 필요하다면 별도 판단이 필요하다.

권장 커밋 제목:

```
feat(lang-packs): 관리자·사용자 템플릿 중국어 간체(zh-CN) 언어팩 추가
```

---

## 24. 보고서 클립보드 복사

```powershell
Get-Content -Raw -Encoding UTF8 "docs\reports\zh-CN-template-language-packs-report.md" | Set-Clipboard
```
