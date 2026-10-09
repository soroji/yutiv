# 무통장입금 계좌 설정 위치

소스 확인일: 2026-10-10. 기존 계좌 관리 화면을 사용한다. 별도 테이블·설정 저장소·관리자 화면은 추가하지 않는다.

## 관리자 사용 순서

1. **쇼핑몰 설정 → 환경설정**을 연다. URL은 `/admin/ecommerce/settings`이다. 코어의 `/admin/settings`와는 다른 화면이다.
2. **주문설정** 탭을 선택한다.
3. **무통장 계좌번호 설정**에서 **계좌 추가**를 누른다.
4. 은행·계좌번호·예금주를 입력하고 **사용**을 켠다. 기존 서버 정책상 등록된 계좌가 있다면 최소 하나는 **기본**과 **사용**이 함께 켜져 있어야 한다.
5. 하단 **저장**을 누른다. **은행 관리**는 은행 코드/이름 목록 관리이며, 실제 입금 계좌 추가와는 다르다.

조회 권한은 `sirsoft-ecommerce.settings.read`, 저장 권한은 `sirsoft-ecommerce.settings.update`이다. 수정 권한이 없으면 API의 `abilities.can_update`가 false이고 화면은 읽기 전용이다. 로그인·관리자 검사와 API 권한 middleware는 별도로 유지된다.

## 연결 근거

- `sirsoft-ecommerce/module.php::getAdminMenus()`의 `sirsoft-ecommerce-settings`: URL `/admin/ecommerce/settings`, 조회 권한 선언.
- `yutiv-admin_menu/config/menu-layout.json`: 기존 `sirsoft-ecommerce` 부모를 **쇼핑몰 설정**으로 이름 변경하고 환경설정을 첫 번째 하위 메뉴로 유지한다. 메뉴 삭제·권한 변경은 하지 않는다.
- `MenuLayoutPlan::rewriteDefinition()`: 기존 메뉴 노드를 복사해 이름·계층·순서만 재배치하고 URL·권한을 보존한다.
- `sirsoft-ecommerce/resources/routes/admin.json`: `admin_ecommerce_settings` 레이아웃으로 연결한다.
- `_tab_order_settings.json`: 주문설정 탭에서 `bank_accounts_card`를 표시하고 PC `_bank_accounts_table.json`, 모바일 `_bank_accounts_cards.json`을 사용한다.

## 저장 및 공개 응답

운영 저장 위치는 `storage/app/modules/sirsoft-ecommerce/settings/order_settings.json`의 `bank_accounts`다. 코드상 구조는 `bank_code`, `account_number`, `account_holder`, `is_active`, `is_default`이며, 은행 이름은 같은 파일의 `banks` 목록에서 코드로 연결한다.

관리자 API:

- GET `/api/modules/sirsoft-ecommerce/admin/settings`: 전체 설정·수정 가능 여부 조회.
- GET `/api/modules/sirsoft-ecommerce/admin/settings/order_settings`: 주문설정 조회.
- PUT `/api/modules/sirsoft-ecommerce/admin/settings`: 기존 저장 버튼이 호출하는 저장 API. `StoreEcommerceSettingsRequest` → `EcommerceSettingsController::store()` → `EcommerceSettingsService::saveSettings()`를 사용한다.
- PUT `/api/modules/sirsoft-ecommerce/admin/settings/banks`: 은행 목록만 저장하는 별도 기존 API. 입금 계좌 저장 API로 혼동하지 않는다.

계좌 필수값은 기존 FormRequest에서 검증하고, 화면은 계좌별 필드 오류와 기본/사용 오류를 표시한다. 서버는 활성 여부뿐 아니라 등록된 계좌 행의 필수값을 검증하는 기존 정책을 유지한다.

공개 GET `/api/modules/sirsoft-ecommerce/settings/payment`는 같은 서비스의 `getPublicPaymentSettings()`를 호출하고 `bank_name`을 보강한다. checkout은 반환된 `bank_accounts` 중 활성 계좌를 선택 목록에 표시한다. 저장은 서비스의 settings 캐시를 비우고 설정 config 미러와 이미 해석된 관련 캐시를 갱신한다. 테스트는 저장 전에 캐시를 읽고 저장 후 같은 서비스·공개 컨트롤러 및 새 서비스에서 변경을 확인하며 별도 수동 cache clear를 하지 않는다. 이미 열려 있는 checkout은 설정을 다시 조회하도록 새로고침해야 한다.

## 이번 확인 결과 및 한계

- 로컬 메뉴 계획 검증: 93개 검사 통과, 누락·URL 변경·권한 변경 없음.
- 계좌 경로·실제 설정 서비스 저장·공개 컨트롤러 응답·캐시 무효화·불완전 계좌 검증: 3개 테스트, 19개 assertion 통과. 파일은 테스트 전용 임시 storage에 쓰고 정리했다. 서버나 운영 설정을 변경하지 않았다.
- 기존 checkout 로컬 테스트도 함께 확인한다. 서버 계좌를 등록해 확인한 결과가 아니다.
- 기존 모듈 통합 테스트는 SQLite에서 `board_posts` migration의 중복 기본키 오류로 준비 단계에 실패했다. 관련 없는 migration을 수정하지 않았다.
- 실제 서버 메뉴 DB·접속 계정의 권한·브라우저 화면은 확인하지 못했다. 따라서 사용자가 메뉴를 찾지 못한 이유를 서버 권한이나 캐시 문제로 단정하지 않는다. 소스상 연결은 정상이다.

서버에서 메뉴 자체가 없다면 먼저 다음 **조회 명령**으로 배치 상태를 확인한다. 이번 작업에서는 서버에서 실행하지 않았다.

```sh
php artisan yutiv:admin-menu --status
php artisan yutiv:admin-menu --check
```

계좌 설정 경로 코드·프런트엔드·Composer 의존성에는 수정이 없어 추가 migration, vendor 번들 재생성, dist 재빌드는 필요하지 않다. 이번에 추가된 산출물은 검증 테스트와 이 안내 문서다. 앞선 checkout 변경은 보존했다.
