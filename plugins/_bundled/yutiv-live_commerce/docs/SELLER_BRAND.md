# 고객 화면 판매자 브랜드 (0.1.4)

## 저장과 조회

기존 `live_tenants.name`을 브랜드 이름으로 재사용합니다. 신규 컬럼·migration은 없습니다.
공개 판매자는 기존 `status=active` 검증을 유지합니다. 포털·가입·로그인·계정·라이브 홈은
`TEEWIDE_BRAND_TENANT`(기본 기존 판매자 slug `golfif`)로 지정한 DB 판매자의 이름을 표시합니다.
이 설정은 도메인 이름에서 추측하지 않으며 URL query는 사용하지 않습니다.
개별 채널은 `LiveController::channel`이 검증한 공개 판매자 결과를 요청 attribute로 전달합니다.
`SellerBrand::compose`는 이 결과를 모든 `teewide::*` Blade에 전달합니다. 캐시는 해당 요청 attribute에만 저장하며 판매자 간 공유 캐시는 없습니다.
비공개·미설정·조회 실패 시 다른 판매자로 대체하지 않고 중립적인 “라이브”만 표시합니다.
Blade `{{ }}` escaping을 사용하며 메타 설명과 제목도 같은 규칙입니다.

## 현재 Golfif 설정

운영 DB는 이번 작업에서 수정하지 않았습니다. 기존 `golfif` 행의 이름은 실제 서버에서 확인해야 합니다.
소유자가 연결된 판매자는 해당 계정으로 로그인하여 **내 계정 → 내 판매 채널 → 고객에게 표시할 브랜드 이름**에
`Golfif`를 입력하고 저장합니다. 이름은 필수·최대 100자이며 HTML 및 제어 문자를 허용하지 않습니다.
다른 회원의 판매자는 서버 FormRequest가 403으로 차단합니다. 이 폼은 소유자 연결·판매자 개설 권한을 새로 부여하지 않습니다.

소유자가 없는 기존 행은 권한 있는 운영자가 별도의 승인된 DB 관리 절차로 `slug=golfif`를 확인한 뒤
`name=Golfif`만 설정해야 합니다. 예를 들어 기존 운영 관리 콘솔에서 다음처럼 검증할 수 있습니다
(이번 작업에서 실행하지 않았으며 서비스 DB 변경은 별도 작업입니다).

```php
$data = validator(['name' => 'Golfif'], ['name' => ['required', 'string', 'max:100', 'regex:/^[^<>\x00-\x1F\x7F]+$/u']])->validate();
$tenant = \Plugins\Yutiv\LiveCommerce\Models\LiveTenant::where('slug', 'golfif')->firstOrFail();
// 현재 행과 소유자/상태를 확인한 다음 name만 변경합니다.
$tenant->update($data);
```

초기 채널 Seeder 재실행은 운영자의 기존 이름·상태를 덮어쓸 수 있으므로 브랜드 설정 용도로 사용하지 않습니다.
현재 인스턴스 설정은 `TEEWIDE_BRAND_TENANT=golfif`입니다. 다른 판매자는 그 판매자의 slug를 설정하고
해당 행의 name을 사용합니다. 판매자별 호스트 매핑 테이블은 현재 구현에 없습니다.
여러 판매자의 전용 포털을 한 인스턴스에서 동시에 제공하는 기능은 이번 변경에 포함하지 않습니다.
공유 라이브 호스트의 기존 `/{tenant}` 공개 채널은 각 판매자의 브랜드를 표시합니다.

## 설정 저장 요청

- POST `/account/brand/{tenant}`, named route `teewide.account.brand`, 기존 root_host에만 등록
- 기존 teewide guard 로그인·CSRF·소유 판매자 검증 필수
- 입력: `name` 문자열. 성공: 계정 화면 리다이렉트 및 저장 안내
- 입력 오류: 세션 오류와 리다이렉트, 권한 오류: 403. DB ID 입력은 사용하지 않습니다.
- 저장은 `AccountController::updateBrand → SellerBrandService → SellerBrandRepositoryInterface` 경로입니다.
  저장소는 해당 모델 연결의 트랜잭션에서 판매자 행을 잠그고 소유권을 다시 확인합니다.

## 도메인 교체

기존 `TEEWIDE_ROOT_HOST`, `TEEWIDE_LIVE_HOST`, `TEEWIDE_SESSION_DOMAIN`, `TEEWIDE_SESSION_SECURE`를
새로운 승인된 도메인 구성에 맞춥니다. 설정 캐시 갱신과 공식 `RouteCacheHelper::rebuild()` 경로의 라우트 캐시
갱신이 필요합니다. 브랜드 판매자 slug와 이름은 도메인과 독립적입니다.
이 작업에서는 DNS·Nginx·TLS·쿠키·호스트 검증 규칙을 변경하지 않았습니다.
링크는 기존 named route와 `TeeWidePresenter::portalUrl/liveUrl` 설정 기반 생성을 유지합니다.

## 메일과 배포

현재 TeeWide 가입/로그인 구현에는 인증 메일 발송, 비밀번호 재설정 라우트 및 고객 계정 메일 템플릿이 없습니다.
발신 주소/인증 도메인이나 다른 YUTIV 메일은 수정하지 않았습니다. 운영의 별도 외부 발송 연동은 미확인입니다.
약관 사업자 정보도 변경하지 않았습니다. 새 메일 기능을 추가하지 않습니다.

갱신 대상은 `yutiv-live_commerce` 0.1.4의 설정·PHP·Blade·manifest/Composer입니다.
기존 원본 골프 PNG와 디자인을 보존합니다. JS/TS 번들 변경이 없어 프론트 빌드는 필요하지 않습니다.
공식 플러그인 update 및 라우트 캐시 갱신 절차를 사용하며 실제 서버 반영은 별도 작업입니다.

## 변경 파일

아래 경로는 `plugins/_bundled/yutiv-live_commerce/` 기준입니다.

- `CHANGELOG.md`, `composer.json`, `plugin.json`, `config/live-commerce.php`
- `resources/views/portal/index.blade.php`
- `resources/views/auth/login.blade.php`, `resources/views/auth/register.blade.php`
- `resources/views/account/index.blade.php`, `resources/views/layouts/teewide.blade.php`
- `resources/views/live/home.blade.php`, `resources/views/live/channel.blade.php`
- `resources/views/partials/portal-header.blade.php`, `resources/views/partials/portal-golf-styles.blade.php`, `resources/views/partials/broadcast-preview.blade.php`
- `src/Http/Controllers/AccountController.php`, `src/Http/Controllers/LiveController.php`
- `src/Providers/LiveCommerceServiceProvider.php`
- `tests/PluginTestCase.php`, `tests/Feature/TeeWideDomainRoutingTest.php`
- 신규: `docs/SELLER_BRAND.md`, `resources/views/errors/request.blade.php`
- 신규: `src/Http/Requests/UpdateBrandRequest.php`, `src/Support/SellerBrand.php`
- 신규: `tests/Feature/SellerBrandTest.php`, `tests/scenarios/seller-brand.yaml`
- 신규: `src/Contracts/SellerBrandRepositoryInterface.php`, `src/Repositories/SellerBrandRepository.php`, `src/Services/SellerBrandService.php`
- `docs/MAIN_BACKGROUND.md` (배포 버전 갱신, 원본 이미지·출처 유지)
