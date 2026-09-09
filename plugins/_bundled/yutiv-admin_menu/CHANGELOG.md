# Changelog

이 플러그인의 모든 주요 변경사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,
[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.

## [1.0.0] - 2026-09-09

### Added

- 관리자 최상위 메뉴를 쇼핑몰 운영 중심 순서로 재배치합니다.
  `1 대시보드 / 2 주문 관리 / 3 상품 관리 / 4 카테고리 관리 / 5 쿠폰 관리 / 6 리뷰 관리 /
  7 사용자 관리 / 8 쇼핑몰 설정 / 9~18 나머지 코어 메뉴(기존 상대 순서 유지)`.
  저빈도 설정 6종(환경설정·브랜드·배송정책·상품정보제공고시·공통정보·마일리지)은 기존
  '이커머스' 부모를 재사용한 **'쇼핑몰 설정'** 아래로 모읍니다. 코어 메뉴 중 앞으로 옮기는
  것은 `admin-users` 하나뿐입니다.
  기능은 전부 `sirsoft-ecommerce` 모듈 것이며 이 플러그인은 어떤 화면·라우트·권한도 만들지
  않습니다 — 계층·순서·이름만 바꿉니다. slug·URL·`menu_permissions`·확장 소유권은 그대로입니다.
- `module.sirsoft-ecommerce.admin_menus.translations` 필터 리스너
  (`EcommerceAdminMenuListener`). 모듈 동기화 **직전에** 메뉴 정의를 다시 그려, 모듈
  설치·업데이트·재활성화 때마다 배치가 자동 재적용됩니다.
- `core.menus.config` 필터 리스너 (`CoreAdminMenuListener`). `CoreAdminMenuSeeder` 가
  코어 메뉴를 삭제 후 재생성할 때 목표 순서를 정의 단계에서 주입합니다.
- `php artisan yutiv:admin-menu` 명령
  (`--check` / `--dry-run` / `--apply` / `--rollback` / `--status`).
  - `--check` 는 읽기 전용이며 drift 가 있으면 **exit 1** 로 끝납니다.
  - `--dry-run` 은 DB 도 스냅샷도 쓰지 않습니다.
  - `--apply` 는 스냅샷 → 트랜잭션 → **적용 후 자체 재검증**을 수행하고, 검증에 실패하면
    트랜잭션을 자동으로 되돌립니다.
  - `--rollback` 은 `parent_id`·`order`·다국어 `name`·`icon`·`user_overrides` 만 복원하며
    `menu_permissions` 와 소유권은 건드리지 않습니다. 복원 뒤 스냅샷과 DB 를 필드 단위로
    다시 읽어 검증하고, 누락(부분 복원)·불일치·트랜잭션 실패면 exit 1 로 끝냅니다.
- 배치가 적용된 상태에서의 **비활성화 가드**. `plugin:deactivate` 를 먼저 실행하면 되돌릴
  명령 자체가 사라지므로 차단하고 올바른 순서를 안내합니다.
- **일회용 비활성화 허용 토큰** (`storage/app/yutiv-admin-menu/allow-deactivate`,
  `Support/DeactivationToken`).
  - `--rollback` 이 복원과 복원 후 검증을 **모두** 통과했을 때만 발급합니다.
  - `--rollback` 이 실패하거나 부분 복원으로 끝나면 발급하지 않고 기존 토큰도 폐기합니다.
  - `--apply` 가 성공하면(변경 0건 성공 포함) 폐기합니다 — 배치가 다시 적용됐으므로 무효입니다.
  - `plugin:deactivate` 가 한 번 소비하고 **즉시 삭제**합니다. 한 번 만든 파일로 이후
    비활성화를 반복 허용하지 않습니다.
- 가드 fail-open 로그를 `warning` → **`critical`** 로 올리고, `db_menus_restored: false`
  와 복구 안내를 함께 남깁니다. **DB 메뉴가 자동 복원된 것처럼 보고하지 않습니다.**
- 최상위·설정 메뉴 이름의 ko / en / ja / zh-CN 4개 로케일. 모듈 정의는 ko/en 만 제공하므로
  나머지를 배치 정의에서 채웁니다.

### Notes

- `ExtensionMenuSyncHelper::syncMenu()` 는 `parent_id` 를 **항상** 확장 정의값으로 덮어씁니다
  (`Menu::$trackableFields` 에 `parent_id` 가 없어 `user_overrides` 로도 보호되지 않음).
  그래서 DB 나 관리자 화면에서 계층만 바꾸는 방식은 업데이트에 살아남지 못합니다 —
  정의 자체를 필터로 다시 그리는 이 방식만이 업데이트에 안전합니다.
- `ModuleManager::cleanupStaleModuleEntries()` 는 **필터를 거치지 않은** 원본 정의의 slug
  집합으로 stale 메뉴를 지웁니다. 그래서 이 플러그인은 새 slug 를 만들지 않습니다.
- 코어 메뉴 순서 내구성은 경로별로 수단이 다릅니다. 코어 **업데이트**
  (`CoreUpdateService::syncCoreMenus()`)는 `user_overrides['order']` 마킹으로 보존되고,
  코어 **재시드**(`CoreAdminMenuSeeder`)는 `core.menus.config` 필터로 덮습니다. 재시드 후에는
  순서는 맞지만 마킹이 사라지므로 `--check` 가 "보호 표시 없음" drift 를 보고하며,
  `--apply` 를 한 번 더 실행하면 복구됩니다.
- **비활성화만으로는 DB 배치가 복원되지 않습니다.** 원복이 필요하면 반드시
  `yutiv:admin-menu --rollback` → `plugin:deactivate yutiv-admin_menu` 순서로 실행하세요.
- 요청 처리 중에는 메뉴를 쓰지 않습니다. 부팅 시 무조건 update 하거나 프론트 요청에서 메뉴를
  기록하는 경로는 없습니다 — 쓰기는 `--apply` 와 `--rollback` 두 명령에서만 일어납니다.
