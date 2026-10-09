# 1.2.1 번들 배포 복구

## 원인과 산출물

상품 옵션 수정에서 composer.json 버전을 1.2.1로 변경했지만 이전 vendor-bundle.json/zip을 유지하여 composer_json_sha256 검증이 실패했다. composer.lock은 변경되지 않았다. 해시를 직접 수정하지 않고 공식 명령으로 ZIP과 메타데이터를 함께 재생성했다. 외부 패키지는 기존 ezyang/htmlpurifier v4.19.0 하나다.

```sh
php artisan module:vendor-bundle sirsoft-ecommerce --force
php artisan module:vendor-verify sirsoft-ecommerce
php artisan module:vendor-bundle sirsoft-ecommerce --check
```

오류 안내의 `vendor-bundle:build` 대신 현재 등록된 명령인 `module:vendor-bundle`을 사용한다. 빌더에는 설치 디렉터리가 필요하며, 입력 파일은 `_bundled`의 composer.json/lock이 우선이다. 로컬에서는 기존 설치 디렉터리가 없어 임시 디렉터리로 빌드를 수행하고 검사에 필요한 composer 파일만 복사했다. 검사 후 임시 디렉터리를 제거했다. 설치·DB 변경은 수행하지 않았다.

Git에 포함할 산출물은 `vendor-bundle.json`과 `vendor-bundle.zip`이다. 현재 composer.json 및 lock의 Git 저장 내용과 로컬 내용은 동일하며 줄바꿈 차이도 없다. composer update나 의존성 버전 변경은 하지 않았다.

## 소스 선택 확인

### 활성 모듈 강제 업데이트 판정 수정

c1d2d691의 CLI 사전 판정은 `getBundledModules()`를 사용했으나 이 목록은 활성 모듈과 pending 모듈을 제외하는 설치 후보 목록이다. 따라서 활성 sirsoft-ecommerce에서는 실제 번들이 있어도 소스 없음 오류로 중단됐다. 기존 CLI 테스트가 이 목록을 인위적으로 채워 문제를 놓쳤다.

CLI와 Manager는 이제 실제 `_bundled` 디렉터리의 module.json을 읽어 판정한다. 번들이 없거나 JSON·식별자·버전이 잘못되면 번들 전용 오류로 중단하며 GitHub로 우회하지 않는다. vendor ZIP 무결성 검증은 기존 설치 준비 경로에서 계속 수행한다.

실제 명령 진입점에 `--source=bundled --vendor-mode=bundled --force --layout-strategy=overwrite --no-interaction`을 전달해 설치 1.2.0 → 번들 1.2.1 및 설치 1.2.1 → 동일 버전 강제 업데이트를 검증했다. 설치 후보 캐시는 빈 상태로 두고 실제 Manager의 pending 복사·무결성 검증·압축 해제까지 수행한 뒤 파일 교체 직전에 의도적으로 중단한다. 이 중단 때문에 테스트 명령의 종료 코드는 1이며, 정상 준비 경로 도달은 별도로 검사한다. 번들 없음과 잘못된 manifest는 준비 단계에 진입하지 않는 것을 검사한다. 서버 설치 완료를 검증한 것은 아니다.

기존 CLI 사전 안내는 GitHub 우선 업데이트 조회 결과를 출력했지만, ModuleManager의 sourceOverride는 실제 설치 준비 단계에서 bundled 경로를 선택했다. 따라서 해당 안내만으로 GitHub 파일을 설치했다고 볼 수 없다. 이번 수정은 CLI 표시와 Manager 사전 조회 모두 강제 bundled 선택을 따르게 한다.

회귀 테스트는 실제 ModuleManager → 번들 경로 → pending 복사 → VendorResolver → 무결성 검증 → ZIP 압축 해제를 실행한다. GitHub 조회·다운로드 호출을 금지하고, 파일 적용 직전 단계의 번들 원본 및 vendor 파일을 확인한 후 중단한다. 활성 파일 교체, migration, 실제 모듈 설치 완료는 이 테스트 범위 밖이다.

`yutiv-product_import`는 PHP와 ext-* 요구사항만 있어 외부 Composer 패키지가 없다. 기존 설치 로직의 의존성 판정에 따라 bundled 모드에서도 vendor ZIP을 요구하지 않는다. 실제 pending 복사 및 해당 판정을 테스트했다. `plugin:vendor-bundle` 및 `plugin:vendor-verify`의 건너뜀은 정상이며 플러그인 전체 설치 완료를 의미하지 않는다.

## 서버에서 이어서 실행

수정 파일을 사용자가 commit/push하고 서버에서 갱신한 다음 실행한다. 서버 Composer 의존성을 새로 업데이트할 필요는 없다.

```sh
php artisan module:vendor-verify sirsoft-ecommerce
php artisan module:update sirsoft-ecommerce --source=bundled --vendor-mode=bundled --force --layout-strategy=overwrite --no-interaction
php artisan module:list
php artisan module:cache-clear sirsoft-ecommerce
php artisan plugin:install yutiv-product_import --vendor-mode=bundled --no-interaction
php artisan plugin:activate yutiv-product_import
php artisan route:clear
php artisan queue:restart
```

모듈 업데이트 성공 및 1.2.1 설치를 확인한 후 플러그인 설치를 진행한다. 모듈/플러그인의 migration은 기존 설치·업데이트 절차가 실행한다. 레이아웃 overwrite는 기존 명령과 동일하므로 서버의 별도 관리자 레이아웃 수정이 있다면 먼저 백업한다. 플러그인 설치는 아직 수행하지 않았다는 보고를 기준으로 하며, 이미 설치된 경우 무조건 force 재설치하지 않는다.

엑셀 등록 워커는 기존 프로세스 관리 설정을 따르고 다음 명령을 사용한다.

```sh
php artisan queue:work database --queue=yutiv-product-import --timeout=450 --tries=3
```

PHP 확장, 큐 retry_after, 권한과 양식 사용법은 [엑셀 플러그인 안내](../../../../plugins/_bundled/yutiv-product_import/README.md)를 따른다. 이번 수정에는 프런트엔드 소스 변경이 없어 기존 dist를 그대로 배포한다. 서버 접속·설치·migration·활성화·워커 실행은 로컬 검증에서 수행하지 않았다.
