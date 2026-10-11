# PHP 8.3.6 / MySQL 8.0.46 개인 스토어 격리 검증

이 도구는 커밋 `2182a90a98a2d123f0262bf2623ae1ead6b73f5e`의 기존 테스트를 새 사본에서 실행합니다. 서비스 업데이트, 서비스 DB migration, 확장 설치·활성화, 워커·타이머 실행을 하지 않습니다. 서버에서 아직 실행하지 않았습니다.

## 실행 전 최소 준비

1. Linux의 일반 검증 사용자, Python 3.9 이상, `/usr/bin/php8.3`(8.3.6), `git`, `bubblewrap`(`bwrap`), `socat`, `iproute2`(`ip`)가 필요합니다. 사용자 namespace와 network namespace 생성이 허용돼야 합니다. 도구는 root 실행을 거부하며, namespace가 불가능하면 보안 수준을 낮춰 실행하지 않습니다.
2. PHP에 BCMath, PDO MySQL 및 PHPUnit/Laravel용 확장이 있어야 합니다. 도구는 서비스 php.ini를 복사하지 않고 확장 파일로 독립 ini를 만듭니다. 부족한 확장이 있으면 중단합니다.
3. `/var/www/yutiv-g7`의 HEAD는 `50918f16d7ad033ab86c84e598fd3bf2aab168d0`, Git 작업 트리는 깨끗해야 합니다. 검증 커밋은 이미 fetch된 상태여야 합니다. fetch/checkout/pull은 도구가 실행하지 않습니다.
4. 서비스 `composer.lock`과 검증 커밋의 루트 lock이 같아야 합니다. 서비스 vendor에 PHPUnit 개발 의존성이 필요합니다. lock 불일치 또는 PHPUnit 부재 시 중단하며 Composer 설치로 자동 우회하지 않습니다. 관리자/개발자가 동일 lock 기반 개발 의존성 vendor를 별도로 준비하고 서비스 vendor 재사용 방식을 재검토해야 합니다.
5. 아래 절차로 준비된 **전용 DB, 전용 계정, 그 비밀번호**, 서버 MySQL Unix socket의 실제 경로가 필요합니다. DB 이름은 `yutiv_isolated_` 뒤에 영소문자/숫자 8~40자입니다. 서비스 DB `yutiv_g7`은 사용할 수 없습니다.
6. 검증 사용자에게 서비스 저장소·vendor 읽기와 MySQL socket 연결 권한이 필요합니다. 서비스 권한·그룹·설정은 도구가 변경하지 않습니다. 새 사본과 vendor를 담을 `/tmp` 디스크 공간을 준비합니다.

**테스트 DB의 기존 테이블과 데이터는 `migrate:fresh`로 삭제될 수 있습니다.** 전용 DB에 보존할 데이터가 있는지 먼저 직접 확인하십시오. 도구는 DB 이름 재입력으로 초기화 허용을 받으며, 빈 DB라고 추측하지 않습니다. 같은 계정/DB를 다른 검증 실행과 공유하지 마십시오. `Tests\TestCase`는 그 테스트 DB의 오래된 연결을 종료할 수 있습니다.

## 별도 DB 준비 — DBA 작업

도구는 MySQL 사용자/DB/권한을 만들거나 변경하지 않습니다. DBA가 아래와 같은 새 DB와 계정을 준비합니다. 실제 회원·주문을 복사하거나 서비스 DB를 복제하지 않습니다.

예시 이름은 `yutiv_isolated_20261011a`, 계정은 `yutiv_validation`입니다. 다른 실행에는 다른 DB와 계정을 권장합니다.

```sql
CREATE DATABASE `yutiv_isolated_20261011a`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'yutiv_validation'@'localhost' IDENTIFIED BY '<DBA가 안전하게 입력할 새 비밀번호>';
GRANT ALL PRIVILEGES ON `yutiv\_isolated\_20261011a`.*
  TO 'yutiv_validation'@'localhost';
SHOW GRANTS FOR 'yutiv_validation'@'localhost';
```

이 SQL은 준비 예시이며 자동 실행하지 않습니다. 비밀번호를 셸 명령 인자나 공유 로그에 넣지 말고 DBA의 보안 입력 절차를 사용합니다. 위 계정은 Unix socket 연결을 사용하므로 `localhost` 계정입니다. `GRANT OPTION`, 전역 권한, 다른 DB 권한, role/mandatory role을 부여하지 않습니다. DB 권한의 `_`는 wildcard가 되지 않도록 **이스케이프**합니다.

도구는 실제 연결의 `CURRENT_USER()`, `SHOW GRANTS`, `@@GLOBAL.mandatory_roles`를 검사합니다. 전역 `USAGE`와 정확히 이 DB에 대한 `ALL PRIVILEGES`만 인정합니다. wildcard, 서비스/다른 DB 권한, 역할, 위임 권한 또는 확인 불가능한 형태는 중단합니다. MySQL `partial_revokes` 등의 설정으로 SHOW GRANTS 표현이 달라져도 추측해서 허용하지 않습니다. DBA가 계정과 권한 표현을 확인해야 합니다.

이 권한 검사는 서비스 DB를 `USE`, 조회, 복제하는 방식으로 시험하지 않습니다. **직접 권한·역할 검사로 서비스 DB 접근 권한이 없음을 확인한 뒤** 테스트를 허용합니다. 검사 쿼리 결과나 인증정보는 터미널에 출력하지 않습니다.

## 정확한 실행 명령

이 디렉터리의 `run.py`, `sandbox.py`, `guard.php`, `README.md`를 서비스 밖 `/tmp/yutiv-validation-tools`에 독립 파일로 전달합니다. 이 도구는 검증할 커밋에 아직 포함되지 않은 별도 산출물입니다. 기존 불완전 경로 `/tmp/yutiv-ses-test.1XBbpE/repo`는 사용·수정·삭제하지 않습니다.

MySQL socket 경로는 DBA가 확인한 실제 값으로 지정합니다. 예를 들어 `/run/mysqld/mysqld.sock`이라면, 일반 검증 사용자로:

```sh
python3 /tmp/yutiv-validation-tools/run.py \
  --service /var/www/yutiv-g7 \
  --php /usr/bin/php8.3 \
  --mysql-socket /run/mysqld/mysqld.sock \
  --database yutiv_isolated_20261011a \
  --username yutiv_validation
```

DB 이름을 재입력하고 전용 계정 비밀번호를 화면 표시 없이 입력합니다. 비밀번호 인자/환경 변수 입력 옵션은 없습니다. 전체 실행을 로그에 기록하는 `set -x`, 키 입력 기록기, 비밀번호를 포함하는 명령은 사용하지 않습니다.

## 격리와 사전 검사

- `git archive`로 지정 커밋의 **새** `/tmp/yutiv-validation-*/repo`를 만듭니다. 서비스 작업 트리를 checkout하거나 수정하지 않습니다. 새 경로가 서비스 및 금지 경로와 겹치면 중단합니다.
- vendor를 `copyfile`로 독립 복사합니다. 원본과 사본의 심볼릭 링크·하드 링크·특수 파일을 거부합니다. 서비스 `.env`, APP_KEY, 결제·메일·AI 자격정보와 설치된 확장·캐시는 복사하지 않습니다.
- storage, bootstrap/cache, 테스트 캐시, 로그, 임시 파일과 설치 경로는 새 사본에만 있습니다. `.env.testing`은 새 키와 테스트 설정으로 생성하고 `.env`는 독립 존재 검사 sentinel만 생성합니다. 비밀번호는 환경파일에 쓰지 않습니다.
- bwrap 파일시스템에 서비스 경로를 마운트하지 않습니다. 시스템 실행 파일/라이브러리는 읽기 전용, 쓰기 가능한 프로젝트 파일은 새 작업공간으로 한정합니다. namespace 내부 `/tmp`, `/dev` 등은 별도 임시 마운트입니다.
- 새 network namespace에는 loopback과 전용 MySQL relay만 있습니다. 외부 route가 없어 실제 PG·메일·AI·HTTP 통신은 차단됩니다. host network namespace와 같거나 외부 route가 존재하면 중단합니다. MySQL Unix socket만 relay에 전달하고 서버 워커·타이머는 실행하지 않습니다.
- 새 ini의 `auto_prepend_file`은 부모 PHPUnit과 기존 동시성 테스트의 `PHP_BINARY` 자식에도 적용됩니다. 첫 단계에서 독립 PHP 두 번을 부팅해 동일 가드를 검증합니다. `php -n`으로 우회하는 실행은 지원하지 않습니다.
- 사본의 `bootstrap/app.php`만 검증 shim으로 감싸고 원본 내용은 `app.validation-source.php`에 보존합니다. RegisterProviders 전에 실제 testing 환경·URL/socket override·설정 캐시·읽기/쓰기 설정과 **실제 PDO의 SELECT DATABASE()**를 검사합니다. 사전 검사 전 migration은 실행하지 않습니다.
- 사용하지 않는 다른 DB 연결 설정은 검증 사본에서 제거합니다. 기본 연결은 mysql 하나이며, MySQL connector에서도 읽기/쓰기 PDO를 재검사합니다. 직접 PDO를 사용하는 잠금 테스트는 확인된 설정·동일 제한 계정·namespace relay를 사용합니다.
- PHPRC 및 DB 환경은 새로 구성해 전달합니다. 서비스 셸의 DB_URL·캐시 경로·메일/AI 자격정보를 상속하지 않습니다. 비밀번호는 TTY→부모 메모리→bwrap stdin→격리 프로세스 환경으로만 전달하며 argv에 넣지 않습니다. 동일 OS 사용자에게 프로세스 환경을 읽을 권한이 있는 Linux 특성상 검증 계정도 분리하는 것이 좋습니다.

업무 코드와 테스트 원본은 변경하지 않습니다. 검증 shim·환경·런타임 파일 변경은 새 사본 안에만 존재합니다. 격리 안에서 실행되는 테스트가 manifest, 캐시, 확장 파일을 변경하더라도 서비스에는 해당 경로가 보이지 않습니다.

## 실행 테스트와 로그

다음 묶음을 **순차** 실행합니다. 개인 스토어 동시성 opt-in은 반드시 켜며 skip/risky/warning은 실패로 취급합니다.

1. 부모/새 PHP 프로세스 사전 검사
2. 개인 스토어 `tests` 전체 — 실제 다중 프로세스 MySQL 동시성 포함
3. `RepeatedLineCalculationTest`
4. `RepeatedLineProcessingTest` — UUID 배송 매핑
5. `RepeatedOrderLineTest` — 분할/라인 식별
6. `OrderProcessingServiceTest` — 실제 주문 생성 파이프라인
7. `OrderCancellationServiceTest` — 취소/환불/기존 주문 회귀

개인 스토어 `ModuleTestCase`는 ecommerce `ModuleTestCase`를 상속합니다. `tests/bootstrap.php`가 bundled 확장·테스트 namespace를 로드하고, ecommerce 테스트 베이스가 provider·라우트·모듈 인스턴스와 시더를 준비합니다. `Tests\TestCase::setUpTraits()`는 migration 경로를 등록하며 RefreshDatabase에서 migrate:fresh가 실행됩니다. 테스트 부트스트랩은 config cache 삭제, `.env` 복원, 임시 upgrade 파일 정리도 수행하므로 프로젝트 전체를 복사본으로 격리합니다.

이는 **기존 테스트의 등록 방식**을 그대로 검증하는 도구입니다. 공식 설치·활성화나 운영 공개 URL 검증을 대신하지 않으며, 이번 실행은 그런 설치 명령을 포함하지 않습니다.

터미널은 단계·통과 여부·종료 코드·로그 위치만 출력합니다. 상세 stdout/stderr와 JUnit, PHP/application 로그는 작업공간에 저장합니다. 출력은 비밀번호 마스킹 후 기록하며 종료 시 로그/XML도 다시 마스킹합니다. 실패 시 원문 DB 예외/권한 SQL을 터미널에 표시하지 않습니다. 작업공간은 사용자 전용 0700 권한이며 비밀번호 파일은 생성하지 않습니다. 결과 공유 전 로그를 직접 검토하십시오.

실패한 사본과 로그는 보존됩니다. 자동 재시도·자동 정리·기존 디렉터리 삭제는 없습니다. 오류를 고친 뒤 새 전용 DB/새 실행으로 확인합니다. 정상 완료 코드 0, 검증 실패 0 이외, PHP 가드 실패 78, 사용자 Ctrl-C 130입니다. 도구 준비 실패는 1입니다.

검증 전후 서비스 HEAD와 `git status --porcelain=v1 --untracked-files=all`을 비교해 `service-git-before-after.json`에 기록합니다. 불일치는 전체 실패입니다. **이 결과는 Git으로 확인되지 않는 ignored 파일까지 무변경임을 증명하지 않습니다.**

## 도구 자체 검증과 한계

```sh
python3 -B -m unittest discover -s tools/personal-store-validation -p selftest.py -v
php8.3 -l tools/personal-store-validation/guard.php
```

로컬 도구 자체 테스트는 경로 이탈, 링크·아카이브, 비밀번호 마스킹과 권한 fail-closed 계약을 검사합니다. 서버에서 PHP 8.3.6·MySQL 8.0.46·bwrap namespace·Unix socket relay·전체 PHPUnit 실행이 통과하기 전에는 대상 환경 검증 완료로 보고하지 않습니다. 대상 버전이 다르면 도구가 중단하며 PHP 8.4/MySQL 8.4 결과로 대체하지 않습니다.
