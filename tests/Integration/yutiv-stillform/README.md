# YUTIV Still Form: 실제 G7 통합 검증 / 격리 화면 확인

## 선택적 PSR-4 경로 수정 기록

`plugins/_bundled/sirsoft-daum_postcode/composer.json`은 동일 네임스페이스에 `src/`, `./`를 선언하지만 실제 번들에는 `src/`가 없다. `plugin.php`에 `Plugins\Sirsoft\DaumPostcode\Plugin` 클래스가 있고, 프런트엔드 소스/산출물은 `resources/js`와 `dist/js/plugin.iife.js`에 있다. 따라서 서버에서 발견한 유일한 누락 `plugins/sirsoft-daum_postcode/src/`는 이 구조와 일치한다. 기존 가드가 선택적 PSR-4 후보에도 필수 경로용 `realpath()` 검사를 적용한 것이 실패 원인이다.

- `guards.php`: `sfPsr4Directory()`로 선택적인 미존재 디렉터리를 등록에서 제외한다. 경로를 구성 요소별로 검사하며 실제 상위 경로와 링크를 따라가되, 작업공간을 벗어나는 순간 차단한다. `..`를 먼저 정규화하여 링크 이탈을 숨기지 않는다. 끊어진 링크도 건너뛰지 않는다. 존재하는 PSR-4 경로는 디렉터리여야 한다.
- `sfInside()`는 여전히 존재하는 격리 경로만 허용한다. `sfRequiredFile()`은 파일 여부도 검사한다. 환경 파일, composer.json, autoload.files, module.php/plugin.php 등 필수 진입 파일은 누락 시 실패한다. 선택적인 확장 vendor autoload도 상위 경로 격리 검사를 통과해야 한다.
- 진단은 `missing-required-path: plugins/...`와 `outside-path: plugins/...`를 구분한다. 끊어진 링크는 `broken-symbolic-link`, 종류 불일치는 `not-file`/`not-directory`다. 절대 작업공간 경로/링크 대상은 출력하지 않으며 제어 문자는 치환한다. 작업공간 기준으로 표현할 수 없는 절대 입력은 `[outside-workspace]`로 표시한다. `check.php`와 `router.php` 모두 가드의 안전한 코드만 표시한다.
- `path-check.php`: 실제 우편번호 manifest/루트 진입 파일 구조, 선택적 src, 필수 파일 누락, 외부 `../`, 기존 파일을 디렉터리로 사용하는 경우, 내부/외부/끊어진 링크를 검사한다. 임시 fixture는 이 테스트 디렉터리 안에 만들고 finally에서 링크를 따라가지 않고 정리한다. 실제 플러그인은 읽기만 한다.

로컬 결과: 수정 전 새 경로 회귀 검사의 실패를 재현했다. 수정 후 일반 경로 **14개 통과**, 기존 가드 **82개 통과**, PHP 파일 **7개 문법 검사 통과**. Windows의 심볼릭 링크 생성 권한이 없어 링크 검사 **8개는 미실행**이며 `path-check.php`는 전체 성공을 출력하지 않고 **종료 코드 2(INCOMPLETE)**를 반환한다. 실제 G7 부팅은 로컬 PHP 7.4/vendor 부재로 미실행이다. 서버 접근은 하지 않았다.

수정 도구를 기존 격리 작업공간에 전송한 뒤 서버에서 재실행한다. 경로 검사가 완료되면 `22 path cases, including 8 symbolic-link cases`가 출력되어야 한다:

```bash
(
  set -eu
  cd /tmp/yutiv-ses-test.1XBbpE/repo
  export APP_ENV=testing
  export STILLFORM_TEST_ROOT=/tmp/yutiv-ses-test.1XBbpE/repo
  php tests/Integration/yutiv-stillform/guard-check.php
  php tests/Integration/yutiv-stillform/path-check.php
  php tests/Integration/yutiv-stillform/factory-check.php
  php tests/Integration/yutiv-stillform/check.php
)
```

플러그인 소스/composer.json을 변경하거나 빈 src 디렉터리를 생성하지 않는다. 아래 DB 가드 수정 기록 및 최초 실행 기록은 이전 단계의 결과다.

## DB 가드 수정 기록

`composer.lock`의 Laravel은 **v12.62.0**, 소스 커밋은 `f7e61eb1e0e06a38996802b769bce9127aec227c`다. 로컬 vendor가 없어 해당 커밋의 공식 소스를 확인했다:

- [ConnectionFactory.php](https://github.com/laravel/framework/blob/f7e61eb1e0e06a38996802b769bce9127aec227c/src/Illuminate/Database/Connectors/ConnectionFactory.php): `getReadWriteConfig()`는 후보 중 하나를 선택하고, `mergeReadWriteConfig()`는 공통 설정에 해당 side를 `array_merge`한 뒤 read/write 키를 제거한다. `createReadWriteConnection()`은 병합된 write 설정으로 연결 객체를 만든다. host 배열도 PDO 생성 시 선택/재시도 대상이다.
- [Connection.php](https://github.com/laravel/framework/blob/f7e61eb1e0e06a38996802b769bce9127aec227c/src/Illuminate/Database/Connection.php): `getConfig()`는 그 연결 객체에 저장된 평탄화된 설정이다. 원본 read/write 중첩 배열이 아니다.
- [DatabaseManager.php](https://github.com/laravel/framework/blob/f7e61eb1e0e06a38996802b769bce9127aec227c/src/Illuminate/Database/DatabaseManager.php): factory 호출 전에 `ConfigurationUrlParser`로 URL 설정을 해석한다. 따라서 이 도구는 URL을 사전에 차단한다.
- 저장소 `config/database.php`의 mysql은 read/write 각각 database/host/username을 가진다. read의 빈 환경값은 write 환경값으로 fallback한다. **원본이 올바르더라도 기존 bootstrap의 `sfDatabaseConfig($connection->getConfig())`는 제거된 read 키를 다시 요구하여 `database-config-read`로 실패한다.** DB 환경변수를 추가하는 것으로 해결되지 않는 이유다. 공통 설정 상속형도 기존 가드가 잘못 거부했다.

수정 후 원본 설정은 `sfDatabaseConfig()`가 모든 read/write 후보를 공통 설정과 동일한 얕은 병합 규칙으로 해석하여 검사한다. 평탄화된 연결 객체 설정에는 별도의 `sfResolvedDatabaseConfig()`를 적용한다. 숫자 키 후보 목록은 연속된 배열이어야 하며 모든 후보/host를 검사한다. 빈 side 배열은 공통 설정을 상속하지만 null/문자열/누락된 side/혼합 키/중첩 side는 중단한다. 단일 공통 연결도 지원한다. URL은 null/빈 문자열만 허용하며, 명시된 다른 DB/드라이버는 병합 전에도 거부한다. 설정값은 변경하지 않고 실제 read/write PDO의 `SELECT DATABASE()` 검사도 유지한다.

회귀 검사는 수정 전 상속형에서 종료 코드 1로 실패함을 재현한 뒤, 수정 후 **82개 통과**했다. 모두 합성 데이터이며 비밀번호/환경 파일을 사용하지 않는다. `factory-check.php`는 설치된 Laravel factory로 5개 설정 형태를 만들고 평탄화 및 PDO 지연 생성을 확인한다. 이 검사는 DB/환경 파일 없이 실행되지만 로컬 PHP 7.4/vendor 부재로 여기서는 미실행이다.

수정된 도구 디렉터리를 아래 기존 전송 절차로 테스트 작업공간에 복사한 후, 서버에서 다음만 먼저 재실행한다:

```bash
(
  set -eu
  cd /tmp/yutiv-ses-test.1XBbpE/repo
  export APP_ENV=testing
  export STILLFORM_TEST_ROOT=/tmp/yutiv-ses-test.1XBbpE/repo
  php tests/Integration/yutiv-stillform/guard-check.php
  php tests/Integration/yutiv-stillform/factory-check.php
  php tests/Integration/yutiv-stillform/check.php
)
```

이번 수정에서는 서버 접근·운영 코드/config/.env 변경·commit/push를 하지 않았다. 아래 17개 검사 결과는 최초 작성 당시 기록이며 현재 결과는 위 82개다.

대상은 기존 `/tmp/yutiv-ses-test.1XBbpE/repo`, MySQL `yutiv_g7_testing`이다. 운영 접근·배포·설치·활성화·마이그레이션·시더·회원/주문/결제 생성 명령은 없다. 도구는 설치된 `modules/*`, `plugins/*`, `templates/yutiv-stillform`을 읽으며 `_bundled`로 대체하지 않는다. 테스트 파일을 운영 `public/`에 복사하지 않는다.

## 검증 수준과 이번 로컬 결과

- 시작 상태: `main`, `06bf2d9f330fc4ae69715094c3b69185a97221f5`, 작업 트리 깨끗함.
- 로컬 PHP 7.4.22, `vendor/autoload.php` 없음. 새 PHP 파일 문법 검사 및 `guard-check.php` 17개 검사 통과. `check.php`는 PHP 버전 가드에서 종료 코드 1로 중단하고 성공 문구를 출력하지 않음.
- 소스 점검: 레이아웃 JSON 170개 중 DB 등록 대상 46개, partial 124개. manifest의 JS/CSS 파일과 `YutivStillform` 심볼 존재 확인. 이것은 설치된 서버 DB의 등록 결과가 아니다. 신규 파일 공백 검사도 통과했다.
- 서버에서 기존 PHPUnit 2 tests / 11 assertions와 사업자정보 API가 통과했다는 결과는 사용자 제공 결과다. **이번 도구의 서버 실행 결과는 아직 없다.** 활성 템플릿/등록 레이아웃/HTTP 자산/실제 브라우저는 아래 명령 실행 후 판정한다.
- `tests/Preview/yutiv-stillform`은 실제 번들을 쓰지만 합성 데이터와 G7 상태 모형을 사용하는 픽스처 프리뷰다. 해당 결과를 실제 DB/API/인증/구매 검증으로 간주하지 않는다. 이미 통과한 전체 프런트엔드 검사는 반복하지 않았다.

## 도구 역할

| 파일 | 역할 |
|---|---|
| `guards.php` | DB 설정, 경로 격리, 공개 응답 스키마, 미리보기 HTTP 메서드 검사 |
| `guard-check.php` | 정상/오류 DB, URL 우회, 필드 누락·추가·잘못된 타입, 경로 이탈, 변경 요청 차단 회귀 검사. DB/네트워크 사용 없음 |
| `bootstrap.php` | CLI와 웹 공용 부팅: 환경·DB 검증 → 설치된 확장 PSR-4/files/진입 파일 로드 → HTTP Kernel |
| `check.php` | 실제 라우트/응답, 활성 템플릿, 레이아웃, 자산의 HTTP 내용 일치, 홈 HTML, 상품 목록 검증 |
| `router.php` | 127.0.0.1:8765 전용 임시 프런트 컨트롤러. 모든 요청에 같은 환경/DB 검사 적용 |

`check.php`는 모든 검사를 끝내야 PASS를 출력한다. 오류 시 실패 단계와 도구 자체의 고정 오류 코드만 출력하고 종료 코드는 1이다. 예외 내용/SQL/환경설정/인증정보/사업자정보/응답 본문은 출력하지 않는다. 사업자 공개 필드는 정확히 `company`, `representative`, `business_number`, `mail_order_number`, `address`, `phone`, `email`, `hosting`, `verification_url`이며 각각 `string|null`이어야 한다.

DB 접속 전에 유효 설정의 mysql read/write 데이터베이스명을 확인한다. 애플리케이션 프로바이더 등록 전 실제 `getReadPdo()`와 `getPdo()` 각각에서 `SELECT DATABASE()`를 실행하며, 프로바이더 등록 후·부팅 후·CLI HTTP 검사 후에도 확인한다. 사용하지 않는 DB 연결은 검증 프로세스 설정에서 제외한다. 쓰기 연결 검사는 조회 SQL로 수행한다. 환경 파일을 수정하거나 테스트 DB를 초기화하지 않는다.

`.env`와 `.env.testing`은 기존 검증된 복사본으로 내용이 같아야 한다. 프로세스 APP_ENV와 파일 APP_ENV 모두 testing이어야 하며 상속된 DB 변수가 파일과 충돌하면 중단한다. 외부 캐시 경로, config/route 캐시, 외부 Vite 개발 서버 표시 파일 `public/hot`은 거부한다. 캐시 파일을 자동 삭제하지 않는다. 앱의 testing 보호 조건은 유지한다.

## 소스로 확인한 실제 연결

- `public/index.php`는 `bootstrap/cache/autoload-extensions.php`를 읽는다. `CoreServiceProvider::registerExtensionAutoload()`도 같은 캐시를 읽는다. `ExtensionManager::updateComposerAutoload()`는 testing에서 생성하지 않는다. 따라서 캐시가 없는 이 작업공간에서 **단순 `artisan serve`만으로 확장 클래스가 로드된다고 보장할 수 없다.**
- 공용 bootstrap은 검증 프로세스 안에서만 설치된 확장의 composer PSR-4와 autoload files, module.php/plugin.php, 존재하는 vendor/autoload.php를 등록한다. 캐시를 생성하지 않는다. Request를 HTTP Kernel bootstrap **전에** 컨테이너에 등록한다.
- `CoreServiceProvider::boot()`는 일반 CLI에서 템플릿 로딩을 건너뛴다. 이 도구는 프로세스의 `APP_RUNNING_IN_CONSOLE=false`로 HTTP 부팅 분기를 사용한다. APP_ENV는 계속 testing이다. 웹 요청도 동일 bootstrap을 실행한다.
- `TemplateManager::getActiveTemplate('user')`와 DB의 유일한 active user 템플릿을 모두 확인한다. `ValidatesLayoutFiles` 규칙대로 `meta.is_partial=true` 파일은 DB 등록 검사에서 제외한다. 나머지 설치된 JSON의 `layout_name`이 `TemplateLayout`에 있어야 한다. 등록 여부 검사이며 편집된 DB 콘텐츠 전체와 소스의 동일성 검사는 아니다.
- `template.json`: `dist/css/components.css`, `dist/js/components.iife.js`. `resources/views/app.blade.php`는 `AssetUrl::templateAsset(..., 'css/components.css')` / `'js/components.iife.js'`를 사용한다. `TemplateService::getAssetFilePath()`가 `templates/{identifier}/dist/`를 붙인다. **API 인자에 `dist/`를 다시 붙이지 않는다.** suffix/query 두 모드는 AssetUrl에 위임한다.
- `vite.config.ts`의 IIFE 이름은 정확히 `YutivStillform`. `ComponentRegistry::getGlobalVariableName()`의 하이픈 분리·단어 첫 글자 대문자 변환 결과와 일치한다. CLI는 심볼과 HTTP 파일 내용만 확인한다. 실제 `window.YutivStillform` 실행은 브라우저 확인 항목이다.
- `routes.json`, `src/support/storefront.ts::shopBase()`는 `sirsoft-ecommerce.basic_info.route_path`(기본 `shop`)와 `no_route`를 따른다. 상품 목록은 `{base}/products`, 상세는 `{base}/products/:product_code`, 카트는 `{base}/cart`. `no_route=true`면 base는 빈 문자열이다. 상세 조회는 product_code를 쓰고 장바구니 변경은 상품 숫자 ID를 쓴다. 설정값을 로그로 출력하지 말고 화면의 상품/카트 링크로 이동한다.
- 실제 데이터 경로: 목록 GET `/api/modules/sirsoft-ecommerce/products`, 상세 GET `/api/modules/sirsoft-ecommerce/products/{product_code}`, 카트 POST `/api/modules/sirsoft-ecommerce/cart/query`. 마지막은 routes의 `CartController::index()` → `CartService::getCartWithCalculation()` 조회/계산 경로이므로 미리보기에서 예외적으로 허용한다. 추가/수정/삭제/로그인 제출 등 다른 POST/PUT/PATCH/DELETE는 차단한다.
- `resources/js/core/TemplateApp.ts::resolveRouteExpressions()`는 경로 표현식을 평가한 뒤 중복 `/`를 합친다. 따라서 no_route 분기의 `//products`도 `/products`로 정규화된다. 이 동작을 템플릿의 하드코딩된 `/shop` 링크로 대체하지 않는다.

## 시작 → 접속 → 종료 명령

먼저 로컬 PowerShell에서 **새 도구 디렉터리만** 기존 테스트 작업공간으로 보낸다. `USER@HOST`는 실제 SSH 접속 대상으로 치환한다. 운영 경로나 운영 환경 파일을 복사하지 않는다.

```powershell
Set-Location D:\work\yutiv
$SshDestination = 'USER@HOST'
ssh $SshDestination 'mkdir -p /tmp/yutiv-ses-test.1XBbpE/repo/tests/Integration'
if ($LASTEXITCODE -ne 0) { throw 'Test tool directory creation failed' }
scp -r tests/Integration/yutiv-stillform "${SshDestination}:/tmp/yutiv-ses-test.1XBbpE/repo/tests/Integration/"
if ($LASTEXITCODE -ne 0) { throw 'Test tool transfer failed' }
ssh $SshDestination
```

서버 SSH 터미널에서 다음을 한 묶음으로 실행한다. 설치/활성화가 끝난 기존 테스트 작업공간과 PHP 8.2+가 전제다. 포트 8765가 사용 중이면 다른 프로세스를 종료하지 말고 이 미리보기를 중단한다.

```bash
(
  set -eu
  cd /tmp/yutiv-ses-test.1XBbpE/repo
  export APP_ENV=testing
  export STILLFORM_TEST_ROOT=/tmp/yutiv-ses-test.1XBbpE/repo
  php tests/Integration/yutiv-stillform/guard-check.php
  php tests/Integration/yutiv-stillform/check.php
  # 위 검사 중 하나라도 실패하면 서버를 시작하지 않는다.
  # 요청 URL/오류 상세가 터미널에 기록되지 않도록 내장 서버 stderr를 버린다.
  exec php -d display_errors=0 -d log_errors=0 -S 127.0.0.1:8765 \
    -t public tests/Integration/yutiv-stillform/router.php 2>/dev/null
)
```

다른 로컬 터미널에서 SSH 터널을 유지한다.

```powershell
ssh -N -o ExitOnForwardFailure=yes -L 127.0.0.1:8765:127.0.0.1:8765 USER@HOST
```

시크릿/새 브라우저 프로필로 `http://127.0.0.1:8765/` 접속. Host 검사 때문에 `localhost` 대신 정확히 이 주소를 사용한다. DevTools Network에서 문서/API/JS/CSS의 `X-Stillform-Preview: testing-db-guarded`를 확인한다. 사업자정보 응답 본문·환경설정·인증정보를 콘솔이나 보고서에 복사하지 않는다.

종료는 **서버 미리보기 터미널에서 Ctrl+C**, **로컬 SSH 터널 터미널에서 Ctrl+C**. 백그라운드 서비스/PID 파일을 만들지 않으며 기존 서버 프로세스를 kill하지 않는다. 브라우저 탭도 닫는다.

## 브라우저 확인표

| 화면 | 확인 항목 |
|---|---|
| 홈 `/` | 실제 active template가 Still Form인지, JS/CSS 200, 헤더/푸터, 모바일 메뉴 키보드·포커스·닫기, 가로 넘침. 상품 0건이면 API 성공 후 빈 상태 표시, 실패 상태와 혼동하지 않음 |
| 상품 목록 | 홈의 Shop/상품 링크 사용. 동적 prefix/no_route가 반영되는지, 실제 API 상태와 빈 목록 안내·로딩 종료 확인 |
| 상품 상세 | 실제 공개 상품이 있을 때 목록 카드로 이동, URL은 product_code, 이미지/가격/옵션 표시. 상품이 없으면 정상 상세 검증은 **미실행**으로 기록; 임의 상세 경로의 404는 별도 오류 처리 검사일 뿐 |
| 카트 | 헤더 링크로 이동, 새 프로필의 빈 카트와 POST `/cart/query` 상태 확인. fallback만 표시된 화면을 API 성공으로 오인하지 않음. 담기/삭제/수량 변경/주문 실행 금지 |
| 로그인 `/login` | 입력·라벨·반응형·비밀번호 표시 등 화면만 확인. 제출은 차단되며 로그인 성공/세션 유지 검증으로 보고하지 않음 |

Console에서 값 대신 연결 여부만 확인할 수 있다:

```js
Boolean(window.G7Core && window.YutivStillform &&
  document.querySelector('[data-template-id="yutiv-stillform"]'))
```

360/390/768/1440px, 라이트/다크에서 필요한 화면을 확인한다. API/자산 실패, 컴포넌트 등록 오류, 미해석 `{{...}}`, 메뉴 포커스 이탈은 별도로 기록한다. 상품 수가 0이면 구매 흐름 통과로 보고하지 않는다. 상품이 있어도 이번 미리보기는 구매 검증을 하지 않는다.

## 제한과 중단 시 최소 조치

이 미리보기는 실제 G7 HTTP 응답을 사용하지만 익명·읽기 중심이다. 프로세스 캐시/세션은 array, 메일은 array, 큐/브로드캐스트는 null로 제한하고 인증 쿠키/Authorization을 받지 않는다. 응답 후 terminate 훅을 실행하지 않는다. CSP로 외부 connect/frame 및 외부 form 제출을 제한한다. 따라서 회원 세션, 외부 주소 검색/본인인증/PG, WebSocket, 주문 흐름을 검증하는 환경이 아니다. G7의 일반 부팅/조회 훅과 테스트 저장소의 내부 캐시·뷰 작업은 발생할 수 있다.

- `php-8.2-required`: 서버의 PHP 8.3 바이너리로 실행한다. 로컬 PHP 업그레이드는 이번 작업에 포함하지 않는다.
- `isolated-workspace`, `env-copy-mismatch`, 환경/DB 오류: 지정된 기존 격리 경로와 검증된 테스트 파일/테스트 DB를 다시 확인한다. 운영 파일을 가져오거나 APP_ENV를 바꾸지 않는다.
- `cached-bootstrap`, `external-vite-dev-server`: 해당 테스트 작업공간에 과거 config/route 캐시 또는 Vite hot 표시가 남아 있으면 중단한다. 파일 내용을 출력하거나 도구가 임의 삭제하지 않는다. 테스트 작업공간 소유자가 테스트 전용 파일인지 확인한 뒤 그 파일만 별도로 보관해야 한다.
- `registered-layouts`, 활성 템플릿/자산/라우트 검사 실패: 사용자 제공 설치 결과와 현재 작업공간이 다르거나 설치된 파일/DB 등록이 불완전하다. 이 도구는 재설치/활성화를 수행하지 않는다. 필요한 수정은 **테스트 작업공간의 누락된 설치 산출물/등록을 복구하는 것**이며 운영 수정이 아니다.
- HTTP 503 또는 단계만 출력되는 예외: 성공으로 간주하지 않는다. 전체 예외·SQL을 공유하지 말고 먼저 PHP 버전, 설치된 composer 의존성, 지정 작업공간, 파일 권한, 위 가드 조건을 확인한다. 서버 실행 전에는 이 절차가 실제 성공한다고 단정할 수 없다.

코어 공개 API/번들 확장을 수정하지 않았으므로 버전 제약·CHANGELOG 동기화 대상은 없다. TeeWide 및 기존 YUTIV 템플릿/플러그인은 변경하지 않는다.
