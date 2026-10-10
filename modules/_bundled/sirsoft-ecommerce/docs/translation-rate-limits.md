# AI 번역 요청 제한 수정

## 원인

기준 HEAD는 `7419f5f414154fc8a3570e147da2b9a406cca06b`이며 작업 시작 시 Git 변경은 없었습니다.

잠긴 Laravel의 `ThrottleRequests::handle()` 숫자형 제한은 `$prefix.$this->resolveRequestSignature($request)`를 키로 사용합니다. 인증 사용자 signature는 `sha1(user ID)`이며 URL·HTTP 메서드·제한 횟수를 포함하지 않습니다. 기존 번역 store/retry의 `throttle:5,1`은 prefix가 없어 코어 `/api/admin/auth/user` 등 숫자형 제한 API와 같은 카운터를 사용합니다. 다른 관리자 API 요청 5회만으로 첫 번역 요청이 차단될 수 있습니다. 서버 사용자가 번역을 5번 클릭했다는 증거는 없습니다.

회귀 검사는 실제 코어 관리자 라우트/미들웨어를 사용하고 프로필 렌더링 action만 대체합니다(번역 전용 SQLite fixture에 관련 프로필 테이블이 없기 때문). 다른 API 요청 후 잠긴 숫자형 미들웨어의 429를 재현하고, 수정한 실제 번역 POST 라우트는 정상 진입하는지 확인합니다.

## 구현

- `EcommerceServiceProvider::boot()`에 `ecommerce-catalog-translation` named limiter를 등록합니다. 설정/라우트 캐시 사용 여부와 무관한 정상 프로바이더 부팅 경로입니다.
- `Limit::perMinute(5)->by('sirsoft-ecommerce:catalog-translation:admin:'.user ID)`를 생성/재시도 모두 사용합니다. Laravel은 limiter 이름과 key를 함께 해시합니다. 상품/카테고리/작업 ID로 우회할 수 없습니다.
- configuration/show/cancel은 해당 limiter를 적용하지 않습니다. 기존 인증·관리자·항목 권한·소유권 검사와 다른 API 제한은 유지합니다.
- 429는 ResponseHelper 오류 봉투의 `errors.code=catalog_translation_rate_limited`, `errors.retry_after`와 원래 제한 헤더를 제공합니다. 전체 설정/키/상품 원문을 반환하지 않습니다.
- 프런트는 코드와 HTTP 429가 모두 일치할 때만 Retry-After 대기를 적용합니다. 생성/재시도 버튼은 대기 중 비활성화되며 타이머는 안내만 갱신합니다. 유료 요청을 자동 재시도하지 않습니다. 입력·검토 결과와 동일 payload의 request_id는 보존합니다.
- `running.current`가 진행 중 반복 클릭을 막습니다. 버튼은 type=button이고, 설정 조회/상태 폴링은 GET입니다. 리렌더만으로 생성 POST를 호출하지 않습니다.
- 제공자 HTTP 429는 워커가 항목 실패 `provider_rate_limited`로 기록합니다. YUTIV 미들웨어 429와 분리하며 자동 유료 재전송은 없습니다.

## 검증 경계와 명령

PHP 검사는 격리 SQLite와 실제 G7 모듈 부팅/라우트/미들웨어를 사용합니다. Bus fake 및 Http fake/stray-request 차단으로 유료 AI와 외부 네트워크 호출을 막습니다. 캐시 생성 전후 새 PHP 프로세스의 Console/HTTP/워커 부팅 검사에 named limiter 등록 확인을 추가했습니다.

```bash
php vendor/phpunit/phpunit/phpunit --configuration modules/_bundled/sirsoft-ecommerce/tests/catalog-translation.phpunit.xml
# 관리자 템플릿 디렉터리
npm run test:run -- src/components/composite/__tests__/CatalogTranslationPanel.test.tsx src/components/composite/__tests__/catalogTranslation.test.ts
npm run build
# ecommerce 모듈 디렉터리, PHP 실행 경로는 필요하면 G7_TEST_PHP 지정
npm run test:run -- resources/js/__tests__/layouts/catalogTranslationRendering.test.tsx
# 프로젝트 루트
php artisan module:vendor-bundle sirsoft-ecommerce --force --no-interaction
php artisan module:vendor-verify sirsoft-ecommerce
```

프런트 검사는 React DOM 이벤트와 fake 타이머 및 mock 응답을 사용하는 테스트입니다. 실제 서버 화면/네트워크/AI 제공자 연결 성공 검증으로 보고하지 않습니다. 서버 DB·환경 파일·상품·주문은 변경하지 않았습니다.

최종 실행 결과:

- 격리 PHP 52개 / 511 assertions 통과. 실제 미들웨어 카운터 충돌 재현, 생성/재시도 공통 예산, 관리자 분리, 조회/취소 제외, 61초 경과 후 허용, 제공자 429 항목 실패, config:cache 전후 새 Console/HTTP/worker 프로세스의 limiter 등록을 포함합니다.
- 패널/보호 헬퍼 25개 통과. 클릭/submit/리렌더 중복 방지, GET 폴링, Retry-After 우선 적용, 대기 후 수동 요청만 허용, request_id/입력/검토 보존을 확인했습니다.
- 실제 폼 JSON/manifest/빌드 IIFE/언어 partial/렌더러 경로 18개 통과. 실제 빌드된 패널의 한국어 429 안내와 버튼 비활성화를 포함합니다. 단독 컴포넌트 검사와 구분합니다.
- 관리자 공식 빌드 통과(IIFE 741.59 kB). 운영 소스 TypeScript 오류 0개, Pint 및 git diff --check 통과. 전체 테스트 파일 TypeScript 검사는 이번에 반복하지 않았습니다(직전 작업에서 기존 테스트 파일 오류 확인).
- 공식 vendor 번들 생성/무결성 검사 통과(425.0 KB, 1 package). 새 의존성이나 잠금 파일 변경은 없습니다.
- 실제 브라우저 화면 및 서버 API 재검증, 실제 AI 제공자 호출 성공 여부는 미검증입니다. 요청 카운터를 삭제하거나 한도를 올리지 않았습니다.

## 배포 대상

ecommerce **1.2.5**와 관리자 템플릿 **1.0.12**를 함께 갱신합니다. composer 버전 변경에 맞춰 공식 vendor 번들을 재생성하며 의존성과 composer.lock은 유지합니다. 기존 manifest 등록/컴포넌트 props 계약은 바뀌지 않아 components.json 변경은 필요 없습니다. 새 migration/스케줄러/키 설정은 없습니다.

의존 플러그인 7개(결제 4개, admin_menu, product_import, storefront_support)를 manifest 기준으로 검토했습니다. 번역 limiter/항목 오류를 소비하지 않으며 기존 PHP 서비스 시그니처·동작에 영향이 없어 최소 버전 제약은 유지합니다.

```bash
php artisan module:vendor-verify sirsoft-ecommerce
php artisan module:update sirsoft-ecommerce --source=bundled --vendor-mode=bundled --force --layout-strategy=overwrite --no-interaction
php artisan template:update sirsoft-admin_basic --source=bundled --force --layout-strategy=overwrite --no-interaction
php artisan config:cache
php artisan ecommerce:translation-status
```

라우트 캐시를 사용 중인 서버는 업데이트 후 `php artisan route:cache`로 재생성하세요. 전체 캐시 삭제나 기존 제한 키 삭제는 필요 없습니다. 기존 번역 전용 워커 프로세스만 운영 중인 프로세스 관리자에서 재시작하여 제공자 오류 구분 코드를 로드합니다. 다른 워커 전체를 재시작하는 명령은 필요 없습니다. 수동 워커 시작 명령은 기존과 같습니다.

```bash
php artisan queue:work ecommerce-translation --queue=ecommerce-translation --timeout=180 --sleep=2
```

commit/push/서버 적용은 사용자가 수행합니다.
