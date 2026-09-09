# YUTIV 쇼핑몰 관리자 메뉴 재구성 보고서

- 작성일: 2026-09-09
- 저장소: `D:\work\yutiv` / branch `main` / HEAD `8a3d6b3b`
- 범위: **관리자 메뉴 정보구조 변경**. 기능 신규·복제 없음. 관리자 대시보드 재설계는 별도 2차 범위.
- 서버 작업·DB 직접 변경·커밋·푸시 **미수행**
- **최종 GO 아님** — 운영/스테이징 서버의 실제 Laravel 검증(§16)이 남아 있습니다

---

## 1. 관리자 템플릿 포함 여부와 역할 구분

**관리자 템플릿을 만들지 않았습니다.** `sirsoft-admin_basic` 도 수정하지 않았습니다.

| 계층 | 담당 | 이번 작업 |
| --- | --- | --- |
| **사용자 템플릿** `yutiv-commerce` | 고객이 보는 쇼핑몰 화면(홈·상품·장바구니·주문) | 무변경 |
| **관리자 템플릿** `sirsoft-admin_basic` | 관리자 화면의 레이아웃·컴포넌트·사이드바 렌더 | 무변경 |
| **모듈** `sirsoft-ecommerce` | 쇼핑몰 **기능** 전부 (상품·주문·쿠폰·리뷰·배송정책…) + 관리자 메뉴 **정의** | 무변경 |
| **신규 플러그인** `yutiv-admin_menu` | 메뉴 **배치**(계층·순서·이름)만 소유 | 신규 |

관리자 템플릿을 새로 만드는 조건(메뉴 배치로 불가능 / 대시보드 KPI 재설계 / 전용 레이아웃
필요)에 이번 요구는 해당하지 않았습니다. 메뉴 배치만으로 목표가 달성됩니다.

---

## 2. 조사한 메뉴 등록·동기화 구조

### 등록 경로

```
ModuleManager::syncDeclarativeArtifacts()          app/Extension/ModuleManager.php:2360
  └ createModuleMenus($module)                                              :2311
      ├ $menus = $module->getAdminMenus()                                   :2317
      ├ HookManager::applyFilters("module.{id}.admin_menus.translations")   :2321  ★ 유일한 사전 필터
      └ ExtensionMenuSyncHelper::syncMenuRecursive()                        :2329
  └ cleanupStaleModuleEntries($module)                                      :2367
      └ collectSlugsRecursive($module->getAdminMenus())                     :2742  ★ 필터 미적용 원본
```

이커머스 정의: `modules/_bundled/sirsoft-ecommerce/module.php:2531` — 부모 `sirsoft-ecommerce`
(order 40) 아래 자식 11개.

### A–E 확정 (추측 아님, 코드 근거)

| 질문 | 답 | 근거 |
| --- | --- | --- |
| **A.** DB 에서 `parent_id`/`order` 만 바꾸면 업데이트 후 보존되는가? | **`parent_id` 는 NO / `order` 는 조건부 YES** | `ExtensionMenuSyncHelper::syncMenu()` 의 `$updateData = ['parent_id' => $parentId]` 는 **무조건** 적용됩니다(:102). `order`/`name`/`icon`/`url` 만 `user_overrides` 검사를 거칩니다(:117~127). 클래스 docblock 도 "parent_id 는 항상 확장 정의값으로 업데이트됩니다" 라고 명시합니다(:21). |
| **B.** 재동기화가 원래 계층으로 되돌리는가? | **YES** | 위와 동일. `Menu::$trackableFields = ['name','icon','order','url']`(app/Models/Menu.php:37) 에 `parent_id` 가 없어 `user_overrides` 로도 보호할 수 없습니다. |
| **C.** 다른 소유 메뉴 아래 이커머스 메뉴를 둘 수 있는가? | **스키마상 YES, 실효성 NO** | `menus.parent_id` 에 소유권 제약이 없습니다(migration 2026_04_01_000013). 그러나 A 때문에 동기화가 되돌립니다. |
| **D.** URL·permission·ownership 을 유지한 채 깊이만 바꿀 수 있는가? | **YES** | `menus` 테이블에 `permission` 컬럼이 **없습니다.** 접근 제어는 `menu_permissions(menu_id, role_id/user_id)` 가 담당하므로 `parent_id`/`order` 변경과 무관합니다. `extension_type`/`extension_identifier` 도 건드리지 않습니다. |
| **E.** 부모를 숨기거나 삭제하면? | **삭제는 자식 동반 삭제, 숨김은 트리에서 제외** | `parent_id` FK 가 `cascadeOnDelete`(같은 migration) 입니다. 그래서 이번 배치는 부모를 **삭제하지 않고 재사용**합니다. |
| 추가 | `updateOrderWithHierarchy()` 는 쿼리빌더 `->update()` 라 모델 이벤트를 타지 않습니다 | `app/Repositories/MenuRepository.php:236~259` — 관리자 화면에서 메뉴를 옮겨도 `user_overrides` 가 기록되지 않습니다. |
| 추가 | stale 정리는 **필터를 거치지 않은** 원본 slug 집합 기준 | `ModuleManager.php:2742`. → 배치가 새 slug 를 만들면 다음 동기화에서 삭제됩니다. |

**결론**: DB·관리자 화면만으로는 업데이트에 안전한 재배치가 불가능합니다. 동기화에 넘어가는
**정의 자체**를 바꿔야 합니다.

---

## 3. 선택한 구현 방식과 다른 방식의 제외 이유

### 선택: `module.sirsoft-ecommerce.admin_menus.translations` 필터를 쓰는 전용 플러그인

`plugins/_bundled/yutiv-admin_menu` — 메뉴 배치만 소유하고 기능은 하나도 만들지 않습니다.

- 동기화 **직전** 필터에서 정의를 다시 그림 → 공식 동기화 경로가 우리 배치를 기록
  → 모듈 설치·업데이트·재활성화마다 **자동 재적용** (업데이트 안전)
- 모듈이 이미 선언한 slug 만 재배치 → stale 정리에 걸리지 않음
- `plugins/_bundled/` 는 코어 업데이트가 최상위 orphan 을 보존하는 자리
  (`CoreUpdateService::$preserveTopLevelOrphans`) → 코어 업데이트에도 안전
- 즉시 적용·복구를 위해 `yutiv:admin-menu` 명령을 함께 제공, **배치 정의 파일 하나를 공유**

### 우선순위대로 검토하고 제외한 방식

| 순위 | 방식 | 제외 이유 |
| --- | --- | --- |
| 1 | 코어의 관리자 메뉴 override 기능 | **`parent_id` 에 대한 override 기능이 없습니다.** `user_overrides` 는 `name/icon/order/url` 만 다루고 `parent_id` 는 무조건 덮어씁니다. 관리자 화면에서 옮겨도 다음 동기화에 되돌아갑니다. |
| 2 | 별도 확장이 메뉴 배치만 소유 | **채택** |
| 3 | 멱등 아티즌 명령 단독 | 명령만으로는 **업데이트 안전성이 없습니다** — 모듈이 재동기화될 때마다 계층이 되돌아가 사람이 매번 다시 실행해야 합니다. 그래서 명령은 필터의 **보조 수단**(즉시 적용·스냅샷·롤백)으로만 두었습니다. |
| 4 | 코어 수정 (`syncMenu` 가 `parent_id` 도 보존하도록) | 코어 계약 변경이라 다른 모든 확장의 동기화 동작에 영향이 갑니다. 2번으로 해결되므로 손대지 않았습니다. |
| — | `sirsoft-ecommerce` 원본 수정 | 공식 모듈 업데이트에서 덮어써집니다. 금지 항목이기도 합니다. |
| — | 관리자 템플릿 신규 제작 | 메뉴 배치만으로 목표 달성. 대시보드 재설계는 2차 범위. |

**훅 이름에 대한 솔직한 메모**: 이 필터의 최초 용도는 언어팩의 로케일 주입입니다
(`LanguagePackServiceProvider.php:197`). 이름이 `.translations` 인데 계층까지 바꾸는 것은
용도에서 한 걸음 벗어납니다. 다만 **동기화 직전에 메뉴 정의를 만질 수 있는 코어의 유일한
확장점**이고, 이 플러그인도 name 로케일(ja/zh-CN)을 함께 채웁니다. 리스너 priority 를 50 으로
두어 언어팩 주입 뒤에 실행되므로 언어팩 결과를 덮지 않습니다.

---

## 4. 최종 메뉴 트리

`php tests/Menu/yutiv-admin-menu-check.php --verbose` 실제 출력입니다.

```
── 변경 전 ──                              ── 변경 후 ──
대시보드          order=1                  대시보드          order=1
환경설정          order=2                  주문 관리         order=2   /admin/ecommerce/orders
알림 발송 이력    order=3                  상품 관리         order=3   /admin/ecommerce/products
본인인증 이력     order=4                  카테고리 관리     order=4   /admin/ecommerce/categories
활동 로그         order=5                  쿠폰 관리         order=5   /admin/ecommerce/promotion-coupons
메뉴 관리         order=6                  리뷰 관리         order=6   /admin/ecommerce/reviews
사용자 관리       order=7                  사용자 관리       order=7   /admin/users
권한 관리         order=8                  쇼핑몰 설정       order=8
모듈 관리         order=9                      환경설정          order=1
플러그인 관리     order=10                     브랜드 관리       order=2
템플릿 관리       order=11                     배송정책          order=3
스케쥴 관리       order=12                     상품정보제공고시  order=4
이커머스          order=40                     공통정보 관리     order=5
    환경설정          order=1                  마일리지 내역     order=6
    상품 관리         order=2              환경설정          order=9
    카테고리 관리     order=3              알림 발송 이력    order=10
    브랜드 관리       order=4              본인인증 이력     order=11
    상품정보제공고시  order=5              활동 로그         order=12
    공통정보 관리     order=6              메뉴 관리         order=13
    주문 관리         order=7              권한 관리         order=14
    쿠폰 관리         order=8              모듈 관리         order=15
    배송정책          order=9              플러그인 관리     order=16
    리뷰 관리         order=10             템플릿 관리       order=17
    마일리지 내역     order=11             스케쥴 관리       order=18
```

주문·상품·카테고리·쿠폰·리뷰는 **이커머스를 펼치지 않고 한 번에** 접근합니다.
기존 '이커머스' 부모는 중복 노출되지 않고 '쇼핑몰 설정' 으로 의미가 바뀌어 재사용됩니다.

### 요청 순서와 정확히 일치함

요청하신 최상위 순서를 그대로 구현했습니다. 하네스가 이 맵 전체를 한 건의 단언으로
검사합니다 (`적용 후: 최상위 순서 1~18 이 요청과 정확히 일치`).

| # | slug | 이름 | 출처 |
| --- | --- | --- | --- |
| 1 | `admin-dashboard` | 대시보드 | 코어 (순서 유지) |
| 2 | `sirsoft-ecommerce-orders` | 주문 관리 | 이커머스 (승격) |
| 3 | `sirsoft-ecommerce-products` | 상품 관리 | 이커머스 (승격) |
| 4 | `sirsoft-ecommerce-categories` | 카테고리 관리 | 이커머스 (승격) |
| 5 | `sirsoft-ecommerce-promotion-coupons` | 쿠폰 관리 | 이커머스 (승격) |
| 6 | `sirsoft-ecommerce-reviews` | 리뷰 관리 | 이커머스 (승격) |
| 7 | `admin-users` | 사용자 관리 | 코어 (**유일하게 앞으로 이동**) |
| 8 | `sirsoft-ecommerce` | 쇼핑몰 설정 | 이커머스 부모 재사용 |
| 9 | `admin-settings` | 환경설정 | 코어 |
| 10 | `admin-notification-logs` | 알림 발송 이력 | 코어 |
| 11 | `admin-identity-logs` | 본인인증 이력 | 코어 |
| 12 | `admin-activity-logs` | 활동 로그 | 코어 |
| 13 | `admin-menus` | 메뉴 관리 | 코어 |
| 14 | `admin-roles` | 권한 관리 | 코어 |
| 15 | `admin-modules` | 모듈 관리 | 코어 |
| 16 | `admin-plugins` | 플러그인 관리 | 코어 |
| 17 | `admin-templates` | 템플릿 관리 | 코어 |
| 18 | `admin-schedules` | 스케쥴 관리 | 코어 |

9번 이후 코어 메뉴 10개의 **상대 순서는 변경 전과 동일**합니다 (환경설정 → 알림 → 본인인증 →
활동 로그 → 메뉴 → 권한 → 모듈 → 플러그인 → 템플릿 → 스케쥴). 앞으로 옮긴 코어 메뉴는
`admin-users` 하나뿐입니다. 하네스가 이 불변식을 별도로 검사합니다
(`적용 후: 사용자 관리를 제외한 코어 메뉴의 상대 순서 유지`).

> 이전 초안에서 '쇼핑몰 설정' 을 7번, '사용자 관리' 를 13번에 두었던 배치는 **승인받지 못해
> 폐기**했습니다. 현재 구현에 그 배치는 남아 있지 않습니다.

### 코어 순서를 건드릴 수밖에 없는 이유

`menus.order` 는 **정수 컬럼**이고 코어가 1~12 를 빈틈없이 씁니다. 대시보드(1)와 환경설정(2)
사이에 넣을 소수 순서가 없습니다. 정렬은 `orderBy('order')` 단일 키라 동점의 순서도 보장되지
않습니다(`MenuRepository.php:106`). 그래서 자리를 비우는 것 외에 방법이 없습니다.

완화 장치:

- 대상 slug 를 `config/menu-layout.json` 의 `core_order.orders` **명시 맵**으로 한정합니다.
  맵에 없는 코어 메뉴는 건드리지 않습니다.
- 값이 **절대 목표값**(shift 가 아님)이라 몇 번을 실행해도 누적되지 않습니다.
- 모델 경유 저장이라 `order` 가 `user_overrides` 에 기록되어 코어 업데이트 동기화가
  덮어쓰지 않습니다 (§9).
- 원치 않으면 `core_order.enabled: false` 로 끌 수 있습니다 (그 경우 최상위 순서가 코어
  메뉴와 겹칩니다 — 하네스가 이 상태를 FAIL 로 잡습니다).

`slug`, `url`, `menu_permissions`, `extension_type`, `extension_identifier` 는 어느 경로에서도
읽고 쓰지 않습니다 (§6).

---

## 5. 수정·신규 파일 목록

**기존 파일 수정 0건.** 전부 신규입니다.

| 파일 | 역할 |
| --- | --- |
| `plugins/_bundled/yutiv-admin_menu/plugin.json` | 매니페스트 (identifier, ko/en/ja/zh-CN, 이커머스 의존성) |
| `plugins/_bundled/yutiv-admin_menu/composer.json` | PSR-4 (`Plugins\Yutiv\AdminMenu\`) |
| `plugins/_bundled/yutiv-admin_menu/plugin.php` | 플러그인 본체. 훅 리스너 선언, `getAdminMenus()` 는 빈 배열 |
| `plugins/_bundled/yutiv-admin_menu/config/menu-layout.json` | **배치 정의 단일 진실 원천** |
| `plugins/_bundled/yutiv-admin_menu/src/Support/MenuLayoutPlan.php` | 순수 계획기 (DB·Laravel 미사용) |
| `plugins/_bundled/yutiv-admin_menu/src/Support/DeactivationToken.php` | 일회용 비활성화 허용 토큰 (순수 클래스) |
| `plugins/_bundled/yutiv-admin_menu/src/Listeners/EcommerceAdminMenuListener.php` | 이커머스 동기화 직전 필터 (`module.…admin_menus.translations`) |
| `plugins/_bundled/yutiv-admin_menu/src/Listeners/CoreAdminMenuListener.php` | 코어 메뉴 재시드 필터 (`core.menus.config`) |
| `plugins/_bundled/yutiv-admin_menu/src/Console/Commands/AdminMenuCommand.php` | `yutiv:admin-menu` (`--check`/`--dry-run`/`--apply`/`--rollback`/`--status`) |
| `plugins/_bundled/yutiv-admin_menu/src/Providers/AdminMenuServiceProvider.php` | 명령 등록 |
| `plugins/_bundled/yutiv-admin_menu/{README,CHANGELOG}.md`, `LICENSE` | 문서 |
| `tests/Menu/yutiv-admin-menu-check.php` | **실행 가능한** 독립 검증 하네스 (PHP 7.4) |
| `tests/Feature/Menu/YutivAdminMenuLayoutTest.php` | PHPUnit 통합 테스트 13종 (로컬 실행 불가 — §13) |
| `docs/reports/yutiv-commerce-admin-menu-report.md` | 이 문서 |

`sirsoft-ecommerce` · `sirsoft-basic` · `sirsoft-admin_basic` · 코어 **무변경**.

---

## 6. 기존 URL / permission / ownership 보존 결과

검증기 실측입니다.

```
OK   재작성 정의: 모든 URL 이 원본과 동일
OK   재작성 정의: 모든 permission 키가 원본과 동일
OK   적용 후: URL 이 바뀐 메뉴 없음
OK   적용 후: permission 이 바뀐 메뉴 없음
OK   적용 후: extension ownership 이 바뀐 메뉴 없음
OK   적용 후: 이커머스 메뉴 12개가 여전히 모듈 소유
OK   slug 전역 유일
OK   적용 후: 부모-자식 순환 및 유령 부모 없음
OK   적용 후: 최상위 order 값 충돌 없음
```

`menus` 에 `permission` 컬럼이 없고 접근 제어가 `menu_permissions(menu_id)` 이므로,
`parent_id`/`order` 변경은 역할별 접근 제어에 구조적으로 영향을 주지 않습니다.
새 URL 로 메뉴를 복제하지 않았습니다.

---

## 7. 다국어 처리 결과

모듈 정의는 **ko/en 두 로케일만** 제공합니다(`module.php:2534~`). 최상위로 올라간 메뉴가
ja/zh-CN 에서 배열이나 키로 보이지 않도록, 배치 정의에서 4개 로케일을 채웠습니다.

| 대상 | ko | en | ja | zh-CN |
| --- | --- | --- | --- | --- |
| 주문 관리 | 주문 관리 | Orders | 注文管理 | 订单管理 |
| 상품 관리 | 상품 관리 | Products | 商品管理 | 商品管理 |
| 카테고리 관리 | 카테고리 관리 | Categories | カテゴリー管理 | 分类管理 |
| 쿠폰 관리 | 쿠폰 관리 | Coupons | クーポン管理 | 优惠券管理 |
| 리뷰 관리 | 리뷰 관리 | Reviews | レビュー管理 | 评价管理 |
| 쇼핑몰 설정 | 쇼핑몰 설정 | Store Settings | ショップ設定 | 商城设置 |
| 설정 하위 6종 | ✓ | ✓ | ✓ | ✓ |

- 코어의 다국어 계약(`name` = 로케일 배열)을 그대로 씁니다. React/PHP 화면 코드에 한국어를
  하드코딩하지 않았습니다 — 문구는 전부 배치 정의(JSON)에 있습니다.
- 기존 로케일을 지우지 않고 **덧씌우기(merge)** 라, 언어팩이 먼저 주입한 값도 보존됩니다.
- 검증: `OK 적용 후: 최상위·설정 메뉴가 ko/en/ja/zh-CN 4개 로케일 보유`,
  `OK 적용 후: name 이 로케일 배열 계약 준수`

---

## 8. 멱등성 결과

```
OK   dry-run 은 상태를 바꾸지 않음
OK   두 번째 적용은 변경 0건 (멱등)
OK   두 번 적용해도 결과 동일
OK   slug 전역 유일
OK   적용 후: 부모-자식 순환 및 유령 부모 없음
OK   적용 후: 최상위 order 값 충돌 없음
```

멱등성의 핵심은 **목표값 기준 계산**입니다. 계획기는 현재값에 더하지 않고 목표값과 비교해
차이만 담습니다. 코어 순서도 상대 이동(shift)이 아니라 `core_order.orders` 의 **절대 목표값**
이라 반복 실행해도 누적되지 않습니다. 중복 메뉴 생성·slug 중복·권한 손실은 구조적으로 불가능합니다
— 이 배치는 메뉴를 **만들지도 지우지도 않고** 기존 행의 필드만 바꿉니다.

---

## 9. 재동기화 내구성 결과 (5개 상황)

요청하신 다섯 상황을 하네스가 각각 시뮬레이션해 검증했습니다.

| # | 상황 | 재현 경로 | 보호 수단 | 결과 |
| --- | --- | --- | --- | --- |
| 1 | 코어 메뉴 동기화 | `ExtensionMenuSyncHelper::syncMenu()` 계약 재현 | `user_overrides['order']` | **유지** |
| 2 | 코어 업데이트 / 코어 메뉴 시드 재적용 | 재시드 = 행 삭제 후 재생성 | `core.menus.config` 필터 (`CoreAdminMenuListener`) | **유지** (단 보호 표시는 소실 → `--check` 가 감지, `--apply` 로 복구) |
| 3 | `sirsoft-ecommerce` 메뉴 재동기화 | 모듈 정의 전체 재기록 | `module.…admin_menus.translations` 필터 | **유지** |
| 4 | 플러그인 재활성화 | 훅 재등록 + 전체 재동기화 | 위 두 필터 | **유지** |
| 5 | 같은 명령 2회 실행 | `planApply` 재호출 | 절대값 계획 (누적 없음) | **변경 0건** |

하네스 실제 출력:

```
OK   모듈 재동기화(플러그인 활성): 승격 메뉴가 최상위 유지
OK   모듈 재동기화(플러그인 활성): 전체 배치 그대로
OK   플러그인 비활성 시 원래 계층으로 되돌아감 (설계된 폴백)
OK   코어 메뉴 재동기화(코어 업데이트) 후에도 순서 유지 — user_overrides 보호
OK   코어 재동기화 후 전체 배치 그대로
OK   코어 재시드(필터 적용): 사용자 관리 order=7
OK   코어 재시드(필터 적용): 대시보드 order=1
OK   코어 재시드(필터 적용): 나머지 코어가 9번 이후
OK   코어 재시드 후: 순서 drift 없음
OK   코어 재시드 후: 보호 표시 소실을 --check 가 감지
OK   코어 재시드 후 재적용하면 drift 0
OK   플러그인 재활성화 + 전체 재동기화 후에도 배치 그대로
OK   두 번째 적용은 변경 0건 (멱등)
```

### 경로별로 수단이 다른 이유

코어 메뉴가 재설정되는 경로는 두 개이고 성격이 다릅니다.

- **`CoreUpdateService::syncCoreMenus()`** (코어 업데이트) — 기존 행을 갱신합니다.
  `ExtensionMenuSyncHelper` 를 쓰므로 `order` 가 `user_overrides` 에 있으면 건너뜁니다.
  `--apply` 가 모델 경유 저장으로 마킹하고, 이미 값이 같아 변경이 없는 slug 에 대해서도
  **강제 마킹 패스**를 돌려 빠짐이 없게 합니다.
  이 경로는 `getCoreMenuDefinitions()` 가 `config('core.menus')` 를 **필터 없이** 읽으므로
  필터 리스너는 관여하지 않습니다 (`CoreUpdateService.php:2538` 확인).
- **`CoreAdminMenuSeeder`** — 코어 메뉴를 **삭제 후 재생성**합니다. 새 행이라
  `user_overrides` 가 비어 있어 위 보호가 통하지 않습니다. 대신 이 시더는
  `core.menus.config` 필터를 통과시키므로(`CoreAdminMenuSeeder.php:57`),
  `CoreAdminMenuListener` 가 정의 단계에서 목표 순서를 주입합니다.

재시드 직후는 **순서는 맞지만 보호 표시가 없는** 상태입니다. `MenuLayoutPlan::verify()` 가
이 상태를 `unprotected`("보호 표시 없음") drift 로 보고하고 `--check` 가 exit 1 로 끝냅니다.
`--apply` 를 한 번 더 돌리면 마킹이 복구됩니다 — 하네스 8-d 가 이 전 과정을 검증합니다.

> 재시드는 코어 메뉴 행을 지우므로 `menu_permissions` 도 cascade 로 사라집니다. 이건 시더
> 자체의 동작이며 이 플러그인과 무관하지만, 재시드 후에는 권한 재설정도 필요합니다.

### 요청하신 금지사항 준수

- **매 페이지 요청마다 DB update 없음** — 훅 리스너는 확장 동기화 시점에만 호출되며
  배열만 변형합니다. 프론트 요청 경로에는 메뉴 쓰기가 없습니다.
- **부팅 시 무조건 update 없음** — `AdminMenuServiceProvider` 는
  `$this->app->runningInConsole()` 일 때 명령을 등록할 뿐 DB 를 건드리지 않습니다.
- 메뉴 쓰기는 오직 `yutiv:admin-menu --apply` 와 `--rollback` 두 명령에서만 일어납니다.

---

## 10. 명령 계약 · 롤백 · 비활성화 계약

### 10-1. 명령 계약

| 모드 | DB 쓰기 | 스냅샷 쓰기 | 종료 코드 | 비고 |
| --- | --- | --- | --- | --- |
| `--check` | 없음 | 없음 | drift 있으면 **1** | 읽기 전용. CI·크론용 |
| `--dry-run` | 없음 | 없음 | 0 | 예상 변경만 출력 |
| `--apply` | 트랜잭션 1건 | 적용 전 1건 | 검증 실패 시 **1** | 적용 후 자체 재검증 → 실패 시 자동 롤백 |
| `--rollback` | 트랜잭션 1건 | 없음 | 0 | 스냅샷 복원 |
| `--status` | 없음 | 없음 | 0 | 현재 배치 요약 |

- 모드를 두 개 이상 주면 거부합니다. **기본 동작(무인자 실행)은 없습니다.**
- `--check` 는 `순서 / 상위 메뉴 / 메뉴 없음 / 보호 표시 없음` 네 가지 drift 를
  `slug · 항목 · 기대 · 현재` 표로 보여줍니다.
- `--apply` 는 스냅샷을 **트랜잭션 밖에서** 먼저 쓰고, 트랜잭션 안에서 모델 `save()` 로
  변경을 적용한 뒤 코어 slug 의 `order` 를 `user_overrides` 에 강제 마킹하고,
  마지막에 `verify()` 를 다시 돌립니다. drift 가 남으면 예외를 던져 **트랜잭션 전체를
  되돌립니다** — 부분 적용 상태로 끝나지 않습니다.
- 2회차 `--apply` 는 변경 0건입니다 (하네스 검증).

### 10-2. 롤백

```
OK   롤백 후 원래 계층·순서·이름 복원
```

`--apply` 는 적용 **전** 전체 메뉴 상태를 `storage/app/yutiv-admin-menu/snapshot-*.json`
에 남기고 `latest.txt` 가 최신을 가리킵니다. `--rollback` 은 그 스냅샷의
`parent_id` · `order` · 다국어 `name` · `icon` · `user_overrides` 만 되돌립니다.
`menu_permissions`, `url`, `extension_type`, `extension_identifier` 는 읽지도 쓰지도
않습니다. 하네스는 복원 후 상태가 적용 전과 **완전히 동일**함을 확인했습니다.

### 10-3. 비활성화 계약 (정정)

**비활성화만으로는 DB 배치가 자동 복원되지 않습니다.** 이 보고서의 이전 판은 §9/§17 에서
"플러그인을 비활성화하면 원래 계층으로 복귀" 라고 적었는데, 정확하지 않아 정정합니다.

정확한 사실은 다음과 같습니다.

- 비활성화는 훅 리스너를 떼어내므로 **앞으로 있을** 동기화에서 배치가 재적용되지 않습니다.
  하네스의 "플러그인 비활성 시 원래 계층으로 되돌아감" 항목은 **그 이후 모듈 재동기화가
  일어난 경우**를 시뮬레이션한 결과이지, 비활성화 자체의 효과가 아닙니다.
- 비활성화 시점의 DB 에 이미 기록된 `parent_id`/`order`/`name` 은 **그대로 남습니다.**
  이후 모듈·코어 동기화가 실제로 일어나야 원래 계층으로 덮어써집니다.
- 게다가 비활성화하면 `yutiv:admin-menu` 명령이 등록 해제되어 **`--rollback` 을 쓸 수
  없게 됩니다.**

그래서 순서를 고정합니다.

```bash
php artisan yutiv:admin-menu --rollback              # 1. 먼저 원복
php artisan plugin:deactivate yutiv-admin_menu       # 2. 그다음 비활성화
```

역순 실행을 막기 위해 `Plugin::deactivate()` 에 가드를 넣었습니다. 배치가 적용된 상태
(= `verify()` drift 0) 에서 `plugin:deactivate` 를 호출하면 `RuntimeException` 으로
**비활성화를 차단**하고 위 순서를 안내합니다.

### 10-4. 비활성화 허용 토큰 — 일회용 계약

`storage/app/yutiv-admin-menu/allow-deactivate` 는 "되돌린 상태" 를 나르는 **일회용 토큰**
입니다. 구현은 `src/Support/DeactivationToken.php` 이며, 경로를 생성자로 받는 순수 클래스라
PHP 7.4 하네스가 프로덕션 코드 그대로 실행 검증합니다.

| 시점 | 토큰 | 구현 |
| --- | --- | --- |
| `--apply` 성공 (변경 0건 성공 포함) | **폐기** | `AdminMenuCommand::revokeDeactivationToken()` |
| `--rollback` 복원 + 복원 후 검증 모두 성공 | **발급** | `$token->issue([...])` — `verifyRestored()` 통과 뒤에만 |
| `--rollback` 트랜잭션 실패 / 부분 복원 / 검증 실패 | **미발급 + 기존 토큰 폐기**, exit 1 | `AdminMenuCommand::failRollback()` |
| `plugin:deactivate` | **소비 후 즉시 삭제** | `Plugin::deactivate()` → `$token->consume()` |
| 가드 판정 실패 (fail-open) | 남은 토큰 폐기 | `Plugin::deactivate()` catch |

되돌릴 생각 없이 현 배치를 그대로 두고 자동 재적용만 멈추려면 토큰을 손으로 만듭니다
(이 경우에도 일회용입니다).

```bash
touch storage/app/yutiv-admin-menu/allow-deactivate
```

**부분 복원 판정**: 스냅샷에 있던 slug 가 지금 DB 에 없으면 완전 복원이 아니므로
토큰을 주지 않고 exit 1 로 끝냅니다. 복원 후 검증은 `parent_id` · `order` · `icon` 과
다국어 `name` 을 로케일별로 비교합니다.

### 10-5. fail-open 시 로깅

가드 판정 자체가 실패하는 경우(메뉴 테이블 부재 등)에는 비활성화를 통과시킵니다 — 가드
때문에 플러그인을 영영 끄지 못하는 상황이 더 나쁘기 때문입니다. 다만 조용히 넘어가지
않습니다.

```php
Log::critical('yutiv-admin_menu 비활성화 가드 fail-open — 판정 실패로 비활성화를 허용했습니다', [
    'error' => ..., 'exception' => ...,
    'db_menus_restored' => false,
    'warning' => 'DB 메뉴 배치는 복원되지 않았습니다. parent_id/order/name 은 적용된 상태 그대로 남아 있습니다.',
    'action_required' => '플러그인을 다시 활성화한 뒤 php artisan yutiv:admin-menu --rollback 을 실행하세요.',
]);
```

등급은 `warning` 이 아니라 **`critical`** 이고, `db_menus_restored: false` 를 명시해
**DB 메뉴가 자동 복원된 것처럼 보고하지 않습니다.** 실제로 이 경로는 메뉴를 단 한 건도
쓰지 않습니다.

---

## 11. 전체 테스트 결과

### 실행함 — `tests/Menu/yutiv-admin-menu-check.php` (PHP 7.4, vendor 불필요)

```
=== YUTIV 관리자 메뉴 배치 검증 ===
메뉴 행 24개 (코어 12 + 이커머스 12) · 계획된 변경 22건
RESULT: PASS — 통과 93건, 위반 0건
```

93개 항목: 플러그인 매니페스트·네임스페이스 계약(코어의 `directoryToNamespace` 규칙 재현),
변경 전 계층, 배치가 참조하는 slug 실재, 정의 누락 0, 재작성 정의의 slug/URL/permission 보존,
dry-run 무변경, 승격 5개의 parent/order, 쇼핑몰 설정 하위 6개,
**최상위 1~18 순서가 요청과 정확히 일치**, **사용자 관리를 제외한 코어 상대 순서 유지**,
최상위 slug·order 충돌 없음, slug 유일, 순환·유령 부모 없음, 멱등성 2종,
모듈 재동기화 유지 2종, 플러그인 비활성 폴백, **코어 업데이트 재동기화 유지 2종**,
**코어 재시드 5종(필터 주입 3 + drift 감지 1 + 재적용 복구 1)**, **플러그인 재활성화 1종**,
**drift 감지 2종**, 롤백 복원, 4개 로케일 2종, 모듈 소유권 유지,
**비활성화 허용 토큰 계약 20종**(토큰 semantics 7 실행 + 흐름 시뮬레이션 5 + 호출 순서·로그 등급 소스 단언 8).

### 검출력 확인 (red → green)

| 주입한 결함 | 결과 |
| --- | --- |
| `admin-users` 목표 순서를 7 → 13 으로 되돌림 | **FAIL 4건** — "최상위 순서 1~18 이 요청과 정확히 일치", "사용자 관리 order=7 — 실제 13", "최상위 order 값 충돌 없음 — 중복: 13", "코어 메뉴 재동기화 후에도 순서 유지" |
| `core_order.enabled: false` (코어 순서 고정 해제) | **FAIL 5건** — order 충돌 + "코어 재시드 후: 보호 표시 소실을 --check 가 감지 — 감지 0건" |
| 배치가 존재하지 않는 slug 참조 | **FAIL 5건** — "배치 정의가 참조하는 slug 가 모두 모듈 정의에 존재" 등 |
| 승격 목록에서 리뷰 관리 누락 | **FAIL 5건** — "모듈 정의의 모든 메뉴가 배치에서 다뤄짐(누락 0)" 등 |
| `DeactivationToken::consume()` 이 파일을 안 지우게(일회용 파괴) | **FAIL 3건** — "consume() 직후 파일이 삭제된다", "두 번째 consume() 는 null", "소비 후 재차 deactivate 는 다시 차단" |
| `--rollback` 이 검증 전에 토큰 발급 | **FAIL 1건** — "--rollback 은 복원 후 검증(verifyRestored) 뒤에 토큰을 발급한다" |
| `--apply` 성공 경로의 토큰 폐기 호출 제거 | **FAIL 1건** — "--apply 성공 직전에 토큰 폐기 호출이 있다" |
| 가드를 `is_file` 단순 확인으로 되돌리고 로그를 `warning` 으로 낮춤 | **FAIL 3건** — "consume 한 뒤 통과", "fail-open 이 critical 등급", "fail-open 경로가 남은 토큰을 폐기" |
| 원복 후 | **PASS — 통과 93건, 위반 0건** |

### 문법 검사

```
plugin.php                                      OK
src/Console/Commands/AdminMenuCommand.php       OK
src/Listeners/EcommerceAdminMenuListener.php    OK
src/Listeners/CoreAdminMenuListener.php         OK
src/Providers/AdminMenuServiceProvider.php      OK
src/Support/MenuLayoutPlan.php                  OK
src/Support/DeactivationToken.php               OK
tests/Feature/Menu/YutivAdminMenuLayoutTest.php OK
tests/Menu/yutiv-admin-menu-check.php           OK
```

### 작성했으나 실행 못 함 — `tests/Feature/Menu/YutivAdminMenuLayoutTest.php`

프로젝트 PHPUnit 관례(`Tests\TestCase` + `RefreshDatabase`)를 따르는 통합 테스트 **21종**
(기존 9종 + 최상위 순서 정확 일치, `--check` drift 감지, 코어 재동기화 후 순서 유지,
코어 재시드 정의 주입 + 토큰 일회용, 토큰 revoke, `--apply` 시 토큰 제거,
`--rollback` 성공 시 발급, `--rollback` 부분 실패 시 미발급, 적용 상태 비활성화 차단,
토큰 소비 후 비활성화 통과). 로컬에서 실행 불가 이유는 §13. 같은 불변식을 위 하네스가 실제로
검증하며, 두 파일은 **같은 계획기(`MenuLayoutPlan`)** 를 쓰므로 로직이 갈라지지 않습니다.

---

## 12. 실제 브라우저 측정 여부

**하지 않았습니다.** 관리자 화면을 띄우려면 Laravel 부팅이 필요한데 §13 의 이유로 불가능합니다.

대신 배치 결과를 트리로 렌더한 로컬 프리뷰를 만들었습니다 — **시뮬레이션이며 실제 관리자
렌더가 아닙니다.**

```
tests/Menu/output/menu-layout-preview.html
```

변경 전/후 트리와 ko/en/ja/zh-CN 이름을 나란히 담았습니다. 1440px·좁은 화면에서의 실제
사이드바 렌더 확인은 서버 적용 후 항목입니다.

---

## 13. 실행하지 못한 검사와 이유

로컬 PHP 는 **7.4.22**, `vendor/` 는 **비어 있고**(패키지 0개), `composer.json` 은 `php ^8.2`
를 요구합니다. 시스템에 PHP 8 바이너리가 없습니다(`C:\php` 7.4.22, `C:\php7.3` 7.3.33).
따라서 Laravel 부팅이 불가능합니다.

| 실행 못 한 것 | 이유 | 대체 |
| --- | --- | --- |
| `php artisan yutiv:admin-menu --check/--dry-run/--apply/--rollback` | Laravel 부팅 불가 | 계획·검증 로직을 하네스가 실행 검증 |
| `YutivAdminMenuLayoutTest` (PHPUnit, 13종) | 동일 | 같은 계획기로 하네스가 검증 |
| `plugin:install` / `plugin:activate` / `plugin:deactivate` 가드 | 동일 | 가드 로직은 `verify()` 결과에만 의존 — 하네스가 verify 를 검증 |
| 관리자 화면 렌더·사이드바 확인 | 동일 | 트리 프리뷰(시뮬레이션) |
| 실제 모듈 재설치/업데이트 후 메뉴 확인 | 동일 + 서버 작업 금지 | 동기화 규칙 재현 시뮬레이션 |
| 권한 없는 관리자의 메뉴 가시성 | `menu_permissions`/`role_menus` 조인과 인증 필요 | 배치가 권한 테이블을 건드리지 않음을 구조로 확인 |
| 모듈 비활성 시 메뉴 비노출 | 동일 | 소유권 무변경(12건 모듈 소유 유지) 확인 |
| 실제 DB 트랜잭션·동시성 | 동일 | — |

**요청 항목 중 "super admin 전체 메뉴 확인", "권한 없는 관리자 비노출" 은 서버에서만 확인
가능합니다.** 이 배치가 `menu_permissions` 를 전혀 건드리지 않는다는 것까지만 확인했습니다.

---

## 14. `git diff --check`

```
(위반 0건)
```

## 15. `git status --short`

```
?? docs/reports/yutiv-commerce-admin-menu-report.md
?? plugins/_bundled/yutiv-admin_menu/
?? tests/Feature/Menu/YutivAdminMenuLayoutTest.php
?? tests/Menu/
```

`M`/`D`/`R` 없음 — 기존 추적 파일을 하나도 수정하지 않았습니다.
`tests/Menu/output/` 은 프리뷰 산출물이라 `tests/Menu/.gitignore` 로 제외돼 있습니다.

---

## 16. 운영 적용 명령 (서버 검증 절차 포함)

```bash
cd /path/to/g7
git fetch origin && git checkout main && git pull --ff-only

# 0) 백업 — 메뉴는 DB 에 있다
mysqldump -u <USER> -p <DB> --single-transaction --quick --default-character-set=utf8mb4 \
  > ~/menu-backup-$(date +%Y%m%d-%H%M%S).sql

# 1) PHPUnit 먼저 (여기가 로컬에서 못 돌린 부분)
php artisan test --filter=YutivAdminMenuLayoutTest
php artisan test --filter=ModuleMenuSyncTest       # 기존 메뉴 동기화 회귀

# 2) 플러그인 설치·활성화 (빌드 불필요 — 프론트엔드 자산 없음)
php artisan plugin:install yutiv-admin_menu
php artisan plugin:activate yutiv-admin_menu

# 3) 적용 전 상태 확인 (둘 다 DB·스냅샷 무변경)
php artisan yutiv:admin-menu --check      # 이 시점에는 drift 있음 → exit 1 이 정상
php artisan yutiv:admin-menu --dry-run

# 4) 적용 (적용 전 스냅샷 자동 생성 + 적용 후 자체 재검증)
php artisan yutiv:admin-menu --apply

# 5) 캐시 정리 후 관리자 화면 확인
php artisan cache:clear
php artisan config:clear && php artisan config:cache

# 6) 검증
php artisan yutiv:admin-menu --check      # 이제 drift 0 → exit 0
php artisan yutiv:admin-menu --apply      # 2회차: 변경 0건 (멱등)
php artisan yutiv:admin-menu --status
php tests/Menu/yutiv-admin-menu-check.php
```

### 서버에서 반드시 눈으로 확인할 항목

| # | 확인 | 방법 |
| --- | --- | --- |
| 1 | 최상위 1~18 순서가 §4 표와 일치 | 관리자 좌측 메뉴 |
| 2 | 주문·상품·카테고리·쿠폰·리뷰가 펼침 없이 보임 | 관리자 좌측 메뉴 |
| 3 | '쇼핑몰 설정' 아래 6개 | 관리자 좌측 메뉴 |
| 4 | 각 메뉴 클릭 시 기존 URL 로 이동 | 클릭 |
| 5 | **슈퍼 관리자** 계정에서 전체 노출 | 로그인 |
| 6 | **권한 제한 계정**에서 기존과 동일하게 일부 미노출 | 로그인 |
| 7 | ko/en/ja/zh-CN 전환 시 이름 정상 | 언어 전환 |
| 8 | 이커머스 메뉴 재동기화 후 배치 유지 | `php artisan module:sync sirsoft-ecommerce` 후 `--check` |
| 9 | 코어 메뉴 재동기화 후 순서 유지 | 코어 업데이트 후 `--check` |
| 10 | 2회차 `--apply` 변경 0건 | 위 6) |
| 11 | `--rollback` 이 적용 전 상태로 복원 | 스테이징에서 확인 |
| 12 | 복원 후 재적용해도 최종 상태 동일 | 스테이징에서 확인 |

> **이 보고서는 최종 GO 가 아닙니다.** 위 12개 항목은 **운영/스테이징 서버에서 아직
> 실행되지 않았습니다.** 로컬은 PHP 7.4 + `vendor/` 비어 있음이라 Laravel 부팅 자체가
> 불가능하기 때문입니다(§13). 서버에서 위 절차를 실제로 수행하고 결과를 확인한 뒤에야
> 배포 GO 판정이 가능합니다.

---

## 17. 운영 롤백 명령

**순서가 고정되어 있습니다.** 비활성화를 먼저 하면 `yutiv:admin-menu` 명령이 등록 해제되어
`--rollback` 을 쓸 수 없게 됩니다.

```bash
# 되돌리기 — 최근 스냅샷으로 복원
php artisan yutiv:admin-menu --rollback
php artisan cache:clear

# 그다음 비활성화 (역순 실행은 deactivate() 가드가 차단한다)
php artisan plugin:deactivate yutiv-admin_menu
php artisan cache:clear

# 특정 스냅샷 지정
php artisan yutiv:admin-menu --rollback --snapshot=snapshot-20260909-101500.json

# 배치를 그대로 둔 채 자동 재적용만 멈추려면 가드를 해제
touch storage/app/yutiv-admin-menu/allow-deactivate
php artisan plugin:deactivate yutiv-admin_menu

# 완전 제거 (반드시 --rollback 이후)
php artisan plugin:uninstall yutiv-admin_menu

# 그래도 문제가 남으면 DB 복원
# mysql -u <USER> -p <DB> < ~/menu-backup-<STAMP>.sql
```

**비활성화만으로는 DB 배치가 자동 복원되지 않습니다.** 자세한 계약은 §10-3 을 보세요.

---

## 18. 권장 커밋 제목

```
feat(plugin): YUTIV 쇼핑몰 관리자 메뉴 배치 플러그인 추가
```

---

## 부록. 관리자 대시보드 재설계는 별도 범위

이번 작업은 **메뉴 정보구조**만 바꿉니다. 관리자 홈을 쇼핑몰 KPI(매출·주문 상태·재고 경고 등)
중심으로 바꾸는 일은 별도 2차 작업입니다. 그 작업은 새 데이터 집계와 화면이 필요하므로
관리자 템플릿 신규 제작 조건에 해당할 수 있으며, 시작 전에 어떤 지표를 이커머스 모듈의
**기존 API 로** 얻을 수 있는지부터 조사해야 합니다.
