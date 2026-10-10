# AI 번역 설정 로딩 수정 (1.2.3)

작업 기준 HEAD `91e837cad35194a89901217c6c5fc8691fa7194b`. 작업 시작 시 Git 변경 없음. 서버 접근·유료 AI 요청·commit/push/deploy는 수행하지 않습니다.

## 확정 원인과 부팅 경로

1. `bootstrap/providers.php`에서 Core/Module 프로바이더가 등록됩니다. `ModuleServiceProvider::register()`의 실제 활성 모듈 디렉터리 발견 경로가 `EcommerceServiceProvider::register()`를 호출합니다.
2. `AbstractExtensionServiceProvider::register()`는 repository/storage/cache 바인딩만 등록합니다. 부모가 번역 설정을 삭제하는 것이 아닙니다. 기존 ecommerce register의 `mergeConfigFrom(..., 'sirsoft-ecommerce.translation')` 직후에는 번역 배열이 존재합니다.
3. 이후 `CoreServiceProvider::boot()` → `ModuleManager::loadModules()` → `loadModuleConfig()`가 실행됩니다. 모듈 `module.php::getConfig()`는 `sirsoft-ecommerce` 전체 키에 `config/ecommerce.php`를 지정합니다. `ModuleManager::loadModuleConfig()`의 `Config::set($key, $configData)`가 전체 배열을 교체하여 하위 translation만 사라집니다. 큐는 `queue.connections` 아래라 남습니다.
4. 그 뒤 Core의 `loadModuleSettingsToConfig()` → `ExtensionSettingsMirror::refreshAllModules()`는 관리자 저장 설정을 별도 `g7_settings.modules.*`에 채웁니다. 기존 currency/product/order/payment/cart/review/limits/dashboard 기본 config나 번역 연결 설정을 병합하는 경로가 아닙니다. 부모 boot의 migration/language 등록 역시 원인이 아닙니다.
5. 격리된 실제 모듈 설치 디렉터리에서 기존 register 키를 복원해 Console/HTTP 양쪽의 booting 직전 존재, 부팅 완료 후 소실을 재현했습니다. 캐시 파일 유무만으로 설명할 수 있는 오류가 아닙니다.

## 수정과 호환성

`EcommerceServiceProvider::register()`의 번역 설정 전용 최상위 키를 **`sirsoft-ecommerce-translation`**으로 변경하고 어댑터와 테스트의 모든 소비 위치를 동기화했습니다. 설정 파일은 기존 `config/translation.php`, API 키 항목은 **`key`**입니다. 환경변수 이름은 그대로입니다. 새 키를 `module.php::getConfig()`의 매 요청 require 경로에 추가하지 않습니다.

Laravel `mergeConfigFrom()`은 설정 캐시가 있으면 파일을 다시 읽지 않습니다. 실제 `config:cache`의 fresh application register에서 환경값을 읽어 새 최상위 배열을 캐시에 포함하고, 이후 새 PHP 프로세스에서는 그 값을 그대로 사용합니다. `boot()` 재병합이나 캐시 사용 시 env 직접 조회는 추가하지 않았습니다. 코어·ModuleManager·기존 기본 config와 큐 설정은 수정하지 않습니다.

configuration API의 기존 `configured` 필드는 유지하고 안전한 `status`만 추가합니다:

| status | 의미 |
| --- | --- |
| config_missing | 설정 배열 또는 driver 키가 누락됨 |
| disabled | 기본 비활성 설정이 정상 로드됨 |
| not_configured | 활성 드라이버를 요청했으나 지원되는 연결 조건/필수 정보가 불완전함 |
| ready | 호환 어댑터의 연결 설정 형식이 충족됨 (실제 AI 호출 성공을 의미하지 않음) |

endpoint/model/key나 상품 원문은 API/CLI/검사 출력으로 반환하지 않습니다. 관리자 화면도 상태별 안내를 구분하고 이전 API(status 없음)는 기존 미설정 안내로 호환합니다. 특정 provider/model/key를 선택하거나 생성하지 않았습니다.

공개 응답은 선택적 필드 추가이고 기존 소비 API/저장 동작이 바뀌지 않으므로 다른 확장의 최소 버전 제약은 유지합니다. Still Form/TeeWide/checkout/엑셀/상품 목록 fitColumns는 변경하지 않습니다. migration, 추가 의존성, 새 큐나 스케줄러는 없습니다.

## 변경 파일

- ecommerce: `src/Providers/EcommerceServiceProvider.php`, `src/Services/Translation/CompatibleTranslationProvider.php`, `src/Http/Controllers/Admin/CatalogTranslationController.php`, `src/Console/Commands/TranslationStatusCommand.php`, ??? ?? ?? Enum `src/Enums/TranslationConnectionStatus.php`.
- 안전한 상태 안내 ko/en `resources/lang/partial/*/admin/translation.json`, 기존 번역 기능 테스트, 신규 `tests/Feature/TranslationConfigBootTest.php`, `tests/fixtures/translation-boot.php`, `tests/catalog-translation.phpunit.xml`.
- 관리자: `CatalogTranslationPanel.tsx`, 관련 컴포넌트 테스트, template manifest/CHANGELOG, 빌드 dist.
- 모듈 manifest/composer/package 버전1.2.3, CHANGELOG, 공식 vendor-bundle zip/manifest. 의존성과 composer.lock은 그대로입니다. 관리자 template 버전1.0.10.
- 사용 문서 및 API 응답 문서.

## 검증

`TranslationConfigBootTest`는 프로바이더 단독 호출이나 설정 수동 주입이 아니라, 임시 base path의 실제 G7 bootstrap/providers, `ModuleServiceProvider` 자동 발견, `CoreServiceProvider` 및 `ModuleManager::loadModules()`를 사용합니다. SQLite 파일·환경 파일·storage·bootstrap cache는 전부 고유한 임시 디렉터리입니다. 테스트용 DB username만 fixture config에 넣어 Core의 실제 확장 로딩 가드를 통과시킵니다. 기존 DB·.env·.env.testing·캐시는 바꾸지 않습니다.

캐시 없는 Console/HTTP, 실제 config:cache, 각각 새 PHP 프로세스의 Console/HTTP 및 빈 큐에서 실제 `queue:work ecommerce-translation --once`를 검사합니다. 기본 disabled와 가짜 연결 환경값 양쪽에서 캐시 생성 후 반대 환경값을 전달해 캐시 우선 동작을 확인합니다. 기본 config의 모든 값 보존, 어댑터/서비스 해석, 큐 database/retry_after240, 안전한 상태 CLI도 검사합니다. HTTP는 실제 Http Kernel bootstrap(콘솔 판정 false)이며 실제 웹서버 요청이나 브라우저 검사는 아닙니다.

HTTP client stray 요청을 차단하고 실제 어댑터 호출 검사는 기존 fake HTTP만 사용합니다. 격리 PHP 전체 검사 49개/461 assertions 통과(신규 부팅3개 포함). 실제 MySQL/서버/PHP-FPM/유료 제공자/워커 장기 실행은 미검증입니다.

```bash
php vendor/phpunit/phpunit/phpunit --configuration modules/_bundled/sirsoft-ecommerce/tests/catalog-translation.phpunit.xml
# 관리자 템플릿 디렉터리
npm run test:run -- src/components/composite/__tests__/CatalogTranslationPanel.test.tsx src/components/composite/__tests__/catalogTranslation.test.ts
```

## 서버 재적용과 안전한 확인

이 수정은 **sirsoft-ecommerce 1.2.3 + sirsoft-admin_basic 1.0.10** 갱신입니다. Still Form 재적용은 필요 없습니다. 소스/산출물을 사용자가 반영한 뒤:

```bash
php artisan module:vendor-verify sirsoft-ecommerce
php artisan module:update sirsoft-ecommerce --source=bundled --vendor-mode=bundled --force --layout-strategy=overwrite --no-interaction
php artisan template:update sirsoft-admin_basic --source=bundled --force --layout-strategy=overwrite --no-interaction
php artisan config:clear
php artisan ecommerce:translation-status
php artisan config:cache
php artisan ecommerce:translation-status
```

마지막 명령은 각각 독립 PHP 프로세스입니다. CLI는 config_present, status, cache_loaded, queue_database, retry_after_240만 출력합니다. AI 설정 없는 현재 서버는 두 확인 모두 status=disabled, config_present=true가 정상이고 cache_loaded만 false→true로 바뀝니다. 전체 config나 key 값을 덤프하지 마세요.

HTTP 확인: 기존 관리자에서 `/admin/ecommerce/settings?tab=language_currency`를 열거나 인증된 GET `/api/modules/sirsoft-ecommerce/admin/catalog-translations/configuration`의 configured/status만 확인하세요. ready는 연결 형식 확인이므로 별도 유료 요청을 실행할 필요가 없습니다.

현재 번역 워커가 아직 없으므로 설치/시작은 AI 연결을 사용할 때 기존 안내대로 진행합니다. 이미 실행 중이라면 전용 번역 워커 프로세스만 재시작하세요. 기존 다른 작업 워커를 일괄 재시작할 필요는 없습니다. `.env` 연결 정보를 나중에 바꾸면 config:cache 재생성과 전용 워커 재시작이 필요합니다.

공식 패키징 명령(활성 모듈 디렉터리가 있는 빌드 환경): `php artisan module:vendor-bundle sirsoft-ecommerce --force --no-interaction`, 이후 `module:vendor-verify`. 로컬 활성 디렉터리가 없어 빈 임시 빌드 자리 디렉터리를 만들고 공식 CLI로 staging composer install을 실행한 뒤 빈 디렉터리를 제거했습니다. 해시를 수동 수정하거나 composer update를 실행하지 않았습니다.

?? ?? ??: ??? ?? ????21? ??(?? pool ? threads ???), ??? ?? ?? ?? TypeScript ?? ??, ??/??? ?? ??(??? dist ??), PHP Pint ??, ?? JSON ?? ? npm ??? ?? ?? ??, composer.lock ?? ??, ?? vendor ?? ??? ??. ?? ????? ?? ??? ?? ??? ?? ???? ?? ??? ??? ???? ????.
