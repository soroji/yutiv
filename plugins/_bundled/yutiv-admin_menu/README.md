# YUTIV 쇼핑몰 관리자 메뉴 배치

쇼핑몰 운영 메뉴를 관리자 **최상위**로 올려 한 번에 접근하게 만드는 플러그인입니다.

**기능을 만들지 않습니다.** 상품·주문·카테고리·쿠폰·리뷰 화면은 모두
`sirsoft-ecommerce` 모듈이 제공하며, 이 플러그인은 그 화면을 복제하거나 새 URL 로
감싸지 않습니다. 모듈이 이미 선언한 메뉴의 **계층(parent) · 순서(order) · 이름(name)**
만 다시 그립니다. slug·URL·`menu_permissions`·확장 소유권(extension_type /
extension_identifier)은 건드리지 않습니다.

## 배치 결과

```
변경 전                              변경 후
────────────────────────           ────────────────────────
1 대시보드                          1 대시보드
2 환경설정                          2 주문 관리          ← 한 번에
3 사용자 관리                        3 상품 관리
…                                  4 카테고리 관리
N 이커머스        ← 매번 펼쳐야 함    5 쿠폰 관리
  ├ 환경설정                        6 리뷰 관리
  ├ 상품 관리                       7 사용자 관리        ← 코어 메뉴 중 이것만 앞으로
  ├ 카테고리 관리                    8 쇼핑몰 설정        ← 기존 이커머스 부모 재사용
  ├ 브랜드 관리                        ├ 환경설정
  ├ 상품정보제공고시                    ├ 브랜드 관리
  ├ 공통정보 관리                      ├ 배송정책
  ├ 주문 관리                          ├ 상품정보제공고시
  ├ 쿠폰 관리                          ├ 공통정보 관리
  ├ 배송정책                           └ 마일리지 내역
  ├ 리뷰 관리                        9 환경설정
  └ 마일리지 내역                    10 알림 로그 … 18 스케쥴 관리
```

최종 최상위 순서는 정확히 다음과 같습니다.

| # | slug | 이름 |
|---|------|------|
| 1 | `admin-dashboard` | 대시보드 |
| 2 | `sirsoft-ecommerce-orders` | 주문 관리 |
| 3 | `sirsoft-ecommerce-products` | 상품 관리 |
| 4 | `sirsoft-ecommerce-categories` | 카테고리 관리 |
| 5 | `sirsoft-ecommerce-promotion-coupons` | 쿠폰 관리 |
| 6 | `sirsoft-ecommerce-reviews` | 리뷰 관리 |
| 7 | `admin-users` | 사용자 관리 |
| 8 | `sirsoft-ecommerce` | 쇼핑몰 설정 |
| 9 | `admin-settings` | 환경설정 |
| 10 | `admin-notification-logs` | 알림 로그 |
| 11 | `admin-identity-logs` | 본인인증 로그 |
| 12 | `admin-activity-logs` | 활동 로그 |
| 13 | `admin-menus` | 메뉴 관리 |
| 14 | `admin-roles` | 역할 관리 |
| 15 | `admin-modules` | 모듈 관리 |
| 16 | `admin-plugins` | 플러그인 관리 |
| 17 | `admin-templates` | 템플릿 관리 |
| 18 | `admin-schedules` | 스케쥴 관리 |

코어 메뉴 중 앞으로 옮기는 것은 **`admin-users` 하나뿐**이고, 나머지 코어 메뉴는
기존 상대 순서를 그대로 유지한 채 9번 이후로 내려갑니다.

## 왜 이 방식인가

`ExtensionMenuSyncHelper::syncMenu()` 는 `parent_id` 를 **항상** 확장 정의값으로
덮어씁니다. `Menu::$trackableFields` 에 `parent_id` 가 없어 `user_overrides` 로도
보호되지 않습니다. 즉 **DB 나 관리자 '메뉴 관리' 화면에서 계층만 바꿔 두면, 모듈을
업데이트하거나 재활성화하는 순간 원래대로 돌아갑니다.**

반면 `ModuleManager::createModuleMenus()` 는 동기화 **직전에**
`module.{identifier}.admin_menus.translations` 필터를 통과시킵니다. 이 플러그인은
그 필터에 붙어 정의 자체를 다시 그리므로, 공식 동기화 경로가 우리 배치를 그대로
기록합니다 — 모듈이 몇 번 재동기화돼도 유지됩니다.

`ModuleManager::cleanupStaleModuleEntries()` 가 **필터 이전** 원본 정의의 slug 집합으로
stale 메뉴를 지우기 때문에, 이 플러그인은 **새 slug 를 만들지 않습니다.** 새 부모가
필요한 자리는 기존 `sirsoft-ecommerce` 부모를 '쇼핑몰 설정' 으로 재사용합니다.

### 코어 메뉴 순서의 내구성

코어 메뉴 순서는 성격이 다른 두 경로로 재설정될 수 있어서, 각각 다른 수단으로 막습니다.

| 경로 | 동작 | 보호 수단 |
|------|------|-----------|
| `CoreUpdateService::syncCoreMenus()` (코어 업데이트) | 기존 행을 `ExtensionMenuSyncHelper` 로 갱신 | `--apply` 가 모델 경유 저장으로 `user_overrides` 에 `order` 를 마킹 → 동기화가 건너뜀 |
| `CoreAdminMenuSeeder` (코어 메뉴 재시드) | 코어 메뉴를 **삭제 후 재생성** | `core.menus.config` 필터에 붙은 `CoreAdminMenuListener` 가 정의 단계에서 목표 순서를 주입 |
| `sirsoft-ecommerce` 메뉴 재동기화 | 모듈 메뉴 전체 재기록 | `module.sirsoft-ecommerce.admin_menus.translations` 필터의 `EcommerceAdminMenuListener` |
| 플러그인 재활성화 | 훅 재등록 후 동기화 | 위 두 필터가 다시 붙으므로 동일 결과 (멱등) |
| 같은 명령 2회 실행 | — | 2회차 변경 0건 (멱등) |

재시드 경로는 행 자체가 새로 만들어지므로 순서는 맞지만 `user_overrides` 마킹이
사라집니다. 이 상태를 `--check` 가 **"보호 표시 없음"** drift 로 보고하고,
`--apply` 를 한 번 더 실행하면 마킹이 복구됩니다.

> 이 플러그인은 **요청 처리 중에 DB 를 쓰지 않습니다.** 서비스 프로바이더는 부팅 시
> 콘솔에서만 명령을 등록하고 DB 를 건드리지 않으며, 훅 리스너는 확장 동기화 시점의
> 정의 배열만 변형합니다. 메뉴 쓰기는 오직 `yutiv:admin-menu --apply` / `--rollback`
> 두 명령에서만 일어납니다.

## 설치 · 적용

```bash
php artisan plugin:install yutiv-admin_menu
php artisan plugin:activate yutiv-admin_menu

php artisan yutiv:admin-menu --check      # 현재 DB 가 목표와 다른지만 확인 (읽기 전용)
php artisan yutiv:admin-menu --dry-run    # 바뀔 내용 확인 (DB·스냅샷 모두 무변경)
php artisan yutiv:admin-menu --apply      # 적용 (스냅샷 → 트랜잭션 → 자체 검증)
php artisan cache:clear
```

`--apply` 는 모듈을 재설치하지 않고 **지금 바로** 반영합니다. 플러그인이 활성 상태라면
이후 모듈 동기화 때 필터가 같은 배치를 다시 적용하므로 두 경로의 결과가 같습니다.

### 명령 계약

| 모드 | DB 쓰기 | 스냅샷 쓰기 | 종료 코드 |
|------|---------|-------------|-----------|
| `--check` | 없음 | 없음 | drift 있으면 **1**, 없으면 0 |
| `--dry-run` | 없음 | 없음 | 0 |
| `--apply` | 트랜잭션 1건 | 적용 전 1건 | 검증 실패 시 롤백 후 1 |
| `--rollback` | 트랜잭션 1건 | 없음 | 0 |
| `--status` | 없음 | 없음 | 0 |

- `--check` 는 목표 순서와 DB 를 비교해 `순서 / 상위 메뉴 / 메뉴 없음 / 보호 표시 없음`
  네 가지 drift 를 표로 보여주고, 하나라도 있으면 **exit 1** 로 끝납니다 (CI·크론용).
- `--apply` 는 적용 직후 스스로 `verify()` 를 다시 돌리고, drift 가 남아 있으면
  예외를 던져 **트랜잭션 전체를 되돌립니다.** 부분 적용 상태로 끝나지 않습니다.
- 모드를 두 개 이상 주면 거부합니다. 기본 동작(무인자 실행)은 없습니다.

## 되돌리기와 비활성화

**비활성화만으로는 DB 배치가 복원되지 않습니다.** 비활성화는 "앞으로의 동기화에서
배치를 다시 적용하지 않는다"는 뜻일 뿐이고, 이미 DB 에 기록된 `parent_id`/`order`/
`name` 은 그대로 남습니다. 게다가 비활성화하면 `yutiv:admin-menu` 명령이 등록 해제되어
`--rollback` 을 쓸 수 없게 됩니다.

그래서 순서가 고정되어 있습니다.

```bash
php artisan yutiv:admin-menu --rollback              # 1. 먼저 원복
php artisan plugin:deactivate yutiv-admin_menu       # 2. 그다음 비활성화
```

역순으로 실행하는 것을 막기 위해, 배치가 적용된 상태에서 `plugin:deactivate` 를
호출하면 플러그인의 `deactivate()` 가 **비활성화를 차단**하고 위 순서를 안내합니다.

### 비활성화 허용 토큰 (일회용)

`storage/app/yutiv-admin-menu/allow-deactivate` 가 가드를 통과시키는 토큰입니다.
**일회용**이라 `plugin:deactivate` 가 한 번 쓰고 즉시 삭제합니다 — 한 번 만든 파일로
이후 비활성화를 반복 허용하지 않습니다.

| 시점 | 토큰 |
| --- | --- |
| `--rollback` 이 복원 + **복원 후 검증**까지 성공 | **발급** |
| `--rollback` 이 실패하거나 부분 복원으로 끝남 | 발급하지 않고, 남아 있던 토큰도 **폐기** |
| `--apply` 성공 (변경 0건으로 성공한 경우 포함) | **폐기** — 배치가 다시 적용됐으므로 무효 |
| `plugin:deactivate` | **소비 후 즉시 삭제** |
| 가드 판정 실패로 fail-open | 남은 토큰 폐기 + `critical` 로그 |

되돌릴 생각 없이 현 배치를 그대로 두고 자동 재적용만 멈추려면 토큰을 손으로 만듭니다.
이 경우에도 일회용이라 다음 비활성화에는 다시 만들어야 합니다.

```bash
touch storage/app/yutiv-admin-menu/allow-deactivate
```

가드가 판정 자체에 실패하면(메뉴 테이블 부재 등) 비활성화를 막지 않습니다 — 가드 때문에
플러그인을 영영 끄지 못하는 상황이 더 나쁘기 때문입니다. 다만 그때는 `Log::critical` 로
`db_menus_restored: false` 와 함께 남기며, **DB 메뉴가 복원된 것처럼 보고하지 않습니다.**

`--rollback` 은 스냅샷의 `parent_id`, `order`, 다국어 `name`, `icon`,
`user_overrides` 만 되돌립니다. `menu_permissions`, `url`, 확장 소유권은 읽지도
쓰지도 않습니다. 복원 뒤에는 **스냅샷 값과 현재 DB 를 필드 단위로 다시 읽어 검증**하고,
누락(부분 복원)이나 불일치가 있으면 토큰을 발급하지 않고 exit 1 로 끝냅니다.

```bash
php artisan yutiv:admin-menu --rollback --snapshot=snapshot-20260909-101500.json
```

스냅샷은 `storage/app/yutiv-admin-menu/` 에 쌓이고 `latest.txt` 가 최신 파일명을 가리킵니다.

## 배치 정의 수정

`config/menu-layout.json` 하나가 단일 진실 원천입니다. 훅 리스너와 아티즌 명령이
**같은 파일**을 읽으므로 둘이 어긋날 수 없습니다.

정의를 고친 뒤에는 반드시 검증기를 돌리세요.

```bash
php tests/Menu/yutiv-admin-menu-check.php --verbose --preview
```

이 검증기는 PHP 7.4 로도 돌아가며(vendor 불필요) 이 플러그인의 실제 계획기를 그대로
불러 최상위 1~18 순서·멱등성·순환·slug 유일성·URL/권한 보존·모듈 재동기화·코어 업데이트·
코어 재시드·플러그인 재활성화 후 유지를 실행 검증합니다.

서버(PHP 8.2 + vendor)에서는 PHPUnit 통합 테스트도 함께 돌리세요.

```bash
php artisan test --filter=YutivAdminMenuLayoutTest
```

## 알려진 한계

- 코어 메뉴 순서는 `config/menu-layout.json` 의 `core_order.orders` 에 **절대값 맵**으로
  적혀 있습니다 (`admin-users: 7`, 나머지 9~18). 코어가 최상위 메뉴를 추가하면 그 메뉴는
  맵에 없으므로 코어 기본 순서를 그대로 쓰고, 목표 순서와 충돌할 수 있습니다.
  그때는 맵에 항목을 추가한 뒤 `--apply` 를 다시 실행하세요. 맵 전체를 끄려면
  `core_order.enabled` 를 `false` 로 두면 되지만, 그러면 최상위 순서가 코어 메뉴와 겹칩니다.
- `CoreAdminMenuSeeder` 는 코어 메뉴를 삭제 후 재생성하므로 `menu_permissions` 가
  cascade 로 함께 사라집니다. 이건 시더 자체의 동작이고 이 플러그인과 무관하지만,
  재시드 후에는 권한 재설정과 `--apply` 재실행이 모두 필요합니다.
- 관리자 대시보드 화면 자체(쇼핑몰 KPI 등)는 이 플러그인의 범위가 아닙니다.
