# 배포 전 점검과 재현 절차 (서버 적용 미실행)

대상 서버 PHP 8.3.6 / MySQL 8.0 계열은 로컬에 동일 실행 환경이 없어 실행 미검증이다. 로컬 PHP 8.4.26 / MySQL 8.4.11 결과로 대체하지 않는다. 새 코드에는 PHP 8.4의 property hook, asymmetric visibility, array_find/array_any/array_all, BcMath\Number를 사용하지 않는다. MySQL CHECK의 실제 강제는 8.0.16 이상이 필요하므로 정확한 서버 patch 버전을 확인한다. FK/nullable 컬럼/191자 고유 키/짧은 복합 인덱스/FOR UPDATE/GET_LOCK은 MySQL 8.0 문법을 사용한다.

## 적용 대상

- sirsoft-ecommerce 1.3.1: module.json/composer.json/CHANGELOG, 라인 nullable migration `2026_10_11_000001`, Upgrade_1_3_0 및 Upgrade_1_3_1, 공식 vendor-bundle.json/zip. 기존 추적 composer.lock의 ezyang/htmlpurifier v4.19.0을 유지하며 외부 의존성 추가 없음.
- yutiv-personal_store 0.1.2: ecommerce >=1.3.1, G7 >=7.0.9, PHP ^8.2, ext-bcmath 필수. 기존 `2026_10_11_000001` 및 `000002`를 유지한다. 0.1.1 → 0.1.2 화면 보완에는 신규 migration이 없다. 운영자 상품코드 검색·카테고리 계층 라벨·공개 부모 검색·본인 스토어 이미지 업로드 API와 user_operator 레이아웃을 갱신한다.
- yutiv-live_commerce 0.1.2: 메인/방송 준비 Blade와 전용 CSS·모바일 메뉴 갱신. 외부 Composer 패키지/lock/vendor bundle 추가 없음. 인증·tenant·라우트 변경 없음.
- yutiv-stillform 1.0.2: StoreOffice 컴포넌트/등록 정보/언어/전용 CSS 및 빌드된 dist를 함께 갱신한다. 개인 스토어 0.1.2의 user_operator가 이 컴포넌트를 사용하므로 활성 Still Form 1.0.2와 짝으로 적용해야 한다. 이전 템플릿 자산으로는 새 운영 화면이 렌더링되지 않는다. 다른 사용자 템플릿용 운영 컴포넌트는 이번 변경에 포함하지 않는다. 공개 상품·카트·결제 레이아웃은 유지한다.

### 화면 보완 검증

Still Form 디렉터리에서 `npm run type-check`, `npm run build`, `npm run check:static`,
`npm run test:run -- src/components/composite/StoreOffice.test.tsx`를 실행한다.
번들 PHP 테스트는 서비스 DB에 연결하지 않는 별도 테스트 환경에서
`OperatorSelectionTest`, `HttpBoundaryTest`, `TeeWideMainDesignTest`를 실행한다.
브라우저 검증은 공식 설치·활성화된 로컬 복사본의 실제 G7 렌더러와 빌드 자산을 사용한다.
미개설/대기/운영/빈 목록/긴 상품명/422/503 상태는 합성 응답으로 재현하며,
실제 API의 소유 범위·개인정보·업로드 저장은 PHP HTTP 테스트로 별도 검증한다.
기존 서버 격리 검증 도구는 보존하지만 이 화면 보완의 배포 필수 조건이 아니다.

## 설치·업데이트

아래 명령은 실행 전 동일 시점 DB/코드/설정/활성 확장 디렉터리 백업을 요구한다. 서비스 사용자/경로/PHP binary는 실제 서버 값으로 치환한다. 운영 DB에서 테스트/migrate:fresh는 실행하지 않는다.

```sh
cd /srv/yutiv
/usr/bin/php8.3 -r 'exit(extension_loaded("bcmath") ? 0 : 1);'
/usr/bin/php8.3 artisan module:vendor-verify sirsoft-ecommerce
/usr/bin/php8.3 artisan plugin:vendor-verify yutiv-live_commerce
/usr/bin/php8.3 artisan module:update sirsoft-ecommerce --source=bundled --force --vendor-mode=bundled
# 최초 설치
/usr/bin/php8.3 artisan module:install yutiv-personal_store --vendor-mode=bundled
/usr/bin/php8.3 artisan module:activate yutiv-personal_store
# 이미 0.1.0이 설치된 경우 install 대신 다음 명령
# /usr/bin/php8.3 artisan module:update yutiv-personal_store --source=bundled --force --vendor-mode=bundled
/usr/bin/php8.3 artisan plugin:update yutiv-live_commerce --source=bundled --force --vendor-mode=bundled
/usr/bin/php8.3 artisan extension:update-autoload
/usr/bin/php8.3 artisan template:cache-clear yutiv-stillform
/usr/bin/php8.3 artisan module:cache-clear yutiv-personal_store
/usr/bin/php8.3 artisan yutiv-personal_store:reconcile
```

Module::install/activate는 BCMath 부재 시 migration/활성화를 거부한다. 기존 배포의 config/route cache와 PHP 장기 실행 프로세스 재시작 절차도 적용한다. 서버 적용 후 공개/운영자/본사 URL, 일반 가입과 TeeWide 가입, 체크아웃을 별도 smoke 확인한다. 일반 Laravel schedule:run 실행 여부는 미확인이다.

## 권장: 전용 systemd timers

모듈의 getSchedules()와 routes/console.php에는 outbox 매분(500개), reconcile --enqueue 5분 주기가 등록된다. 이 프로젝트 전체 scheduler 설치 여부를 전제로 삼지 않도록 **아래 전용 timer 방식 하나를 권장**한다. 일반 schedule:run을 병행하면 같은 명령이 중복 호출되므로 이 모듈의 실행 주체를 하나로 관리한다. GET_LOCK이 겹침을 방어하지만 중복 스케줄을 운영 정책으로 삼지 않는다.

`/etc/systemd/system/yutiv-store-outbox.service`:

```ini
[Unit]
Description=YUTIV personal store outbox
After=network.target
[Service]
Type=oneshot
User=www-data
WorkingDirectory=/srv/yutiv
ExecStart=/usr/bin/php8.3 artisan yutiv-personal_store:outbox --limit=500 --max-seconds=50
TimeoutStartSec=120
KillMode=control-group
```

`yutiv-store-outbox.timer`:

```ini
[Unit]
Description=YUTIV personal store minute timer
[Timer]
OnCalendar=*-*-* *:*:00
Persistent=true
Unit=yutiv-store-outbox.service
[Install]
WantedBy=timers.target
```

`yutiv-store-reconcile.service`:

```ini
[Unit]
Description=YUTIV personal store reconciliation
After=network.target
[Service]
Type=oneshot
User=www-data
WorkingDirectory=/srv/yutiv
ExecStart=/usr/bin/php8.3 artisan yutiv-personal_store:reconcile --enqueue
TimeoutStartSec=300
KillMode=control-group
```

`yutiv-store-reconcile.timer`:

```ini
[Unit]
Description=YUTIV personal store five minute timer
[Timer]
OnCalendar=*-*-* *:0/5:55
Persistent=true
Unit=yutiv-store-reconcile.service
[Install]
WantedBy=timers.target
```

```sh
systemd-analyze verify /etc/systemd/system/yutiv-store-{outbox,reconcile}.{service,timer}
systemctl daemon-reload
systemctl enable --now yutiv-store-outbox.timer yutiv-store-reconcile.timer
systemctl list-timers 'yutiv-store-*'
journalctl -u yutiv-store-outbox.service -u yutiv-store-reconcile.service
```

위 unit와 명령은 문서 준비만 했으며 서버에서 검증/적용하지 않았다. 서비스에 인증정보를 넣지 않고 기존 application 환경을 읽는다.

두 명령은 DB명+prefix별 공통 writer MySQL 세션 GET_LOCK(timeout 0)을 획득한다. 겹친 실행은 즉시 skip; 연결 종료 시 해제. 전용 대사 timer는 5분마다 55초에 실행해 기본 outbox 50초 배치와 시작 시각을 분리한다. 한 이벤트가 오래 걸리면 여전히 skip할 수 있으므로 연속 skip과 마지막 성공 대사 시각을 감시해야 한다. outbox limit은 1..10000, 기본 500; max-seconds는 1..3600, 기본 50이며 **항목 사이의 소프트 시간 제한**이다. 한 이벤트의 트랜잭션 시간이 이를 넘을 수 있으므로 hard limit은 위 systemd 120초다. 각 이벤트 commit 전 실패/강제 종료는 rollback되어 다음 실행에 재처리하며 commit 후 processed_at도 같은 transaction에 남는다. 실패 이벤트를 임의 삭제하지 않는다.

대사는 전체 snapshot/원장을 확인하므로 작업량에 비례한다. 정기 closure 검사는 읽기 일관성과 revision 검증을 사용하고 전역 revision FOR UPDATE를 잡지 않는다. 명시적 --repair-closure만 전체 쓰기 잠금을 잡으며 정기 timer에는 넣지 않는다. 대사 wallet 잠금은 지갑 하나씩 짧은 집계 트랜잭션이며 외부 통신 없음. 300초 종료를 반복하면 완료되지 않는 대사를 완료로 보고하지 말고 backlog, lock wait, 처리시간/수혜자 수를 측정해 시간·주기를 조정한다. queue worker/retry_after는 적용 대상이 아니다.

## 복구

```sh
# 필요할 때 신규 실행을 중지한 뒤 상태 점검
systemctl stop yutiv-store-outbox.timer yutiv-store-reconcile.timer
/usr/bin/php8.3 artisan yutiv-personal_store:reconcile
/usr/bin/php8.3 artisan yutiv-personal_store:reconcile --enqueue
/usr/bin/php8.3 artisan yutiv-personal_store:outbox --limit=500 --max-seconds=50
# closure 불일치는 백업/원인 확인 후 유지보수 시간에만 명시적 복구
# /usr/bin/php8.3 artisan yutiv-personal_store:reconcile --repair-closure
```

금액 전용 환불은 원본 연결이 해결될 때까지 review_required로 지급·상계를 보류하며 운영자 행 상태, 본사 검토 건수, 대사 review_lines에 표시한다. 검토 상태나 원장을 직접 덮어쓰는 복구는 하지 않는다. 0.1.1에서 소비된 증명의 proof_hash를 지우면 재사용 방어가 사라진다. 원장/스냅샷이 있는 상태에서 migration down 또는 구코드만 rollback은 권장하지 않는다. 1.3.1-compatible ecommerce/schema를 보존한 수정본 적용 또는 동일 시점 백업의 코드·DB·설정 복원을 사용한다.
