# 상품 입고·QR·매출정산 0.4.0

## 구조와 저장 경계

상품 `live_products` → 옵션 `live_options` → 개별 물품 `live_stock_units` → 이동 원장 `live_stock_movements` 관계를 유지한다.
상품·옵션 QR은 판매 품목 식별, 물품 UUID QR은 실물 식별이다. 입고·출고·반품·A/S는 기존 서비스를 호출한다.
YUTIV 쇼핑몰·개인 스토어·리워드 모델을 연결하지 않는다.

추가 migration `2026_10_20_000005_create_live_receipts_table.php`는 `live_receipts`와 물품 `(tenant_id, receipt_key)` 인덱스만 추가한다.
입고 헤더의 `items` JSON에 옵션 ID·표시명·수량을 확정 스냅샷으로 보존하고 같은 업체/receipt_key의 개별 물품을 연결한다.
입고번호는 서버 DB ID이며 URL `?batch=번호`로 재조회한다. 처리자, 상품, 위치, 메모, 입고 확정일시와 기록일시를 보존한다.
0.2.0 이전 입고에는 헤더를 추정·생성하지 않는다. 기존 물품 이력·receipt UUID 조회/라벨 주소는 유지한다.

`LiveReceiptService`는 기존 업체 행 잠금과 `LiveWorkflow::once()`를 사용한다. 상품·옵션·개별 입고·이동·배치·감사를 하나의 외부 트랜잭션으로 확정한다.
같은 키·같은 입력은 기존 배치 결과를 돌려주고, 같은 키·다른 입력은 409다. 실패하면 전부 롤백한다.
옵션별 내부 입고 키를 구분하되 물품에는 공통 receipt_key를 기록한다. 기존 입고 요청의 입력 해시는 변경하지 않는다.
한 배치 1~200개·최대 50옵션이다. 실제 입고일시는 서버 확정 시각이며 소급 입력은 지원하지 않는다.
확정 배치의 수정·일괄 취소도 제공하지 않는다. 기존 물품별 재고 조정/정정과 사유·감사를 사용하며 예약·출고 물품을 입고 취소로 되돌릴 수 없다.

## 상품관리 흐름

상품 목록·초안 → 상품 등록/추가 입고 → 옵션별 수량 → 입고 확정 → 해당 배치 내역 → 배치 물품 QR 선택/인쇄.
복사는 이름·코드·설명·옵션/가격 초안을 사용하며 재고·QR·주문·입고 이력·이미지 파일을 복제하지 않는다. 복사본 코드는 반드시 새 코드로 바꾼다.
상품 이미지는 입고 완료 후 같은 상품 수정 화면의 기존 업로드 기능을 사용한다.
옵션 조합 생성은 기존 표를 교체한다. 기존 옵션 ID/QR은 추가 입고 시 재사용하며 가격·할인·판매 설정을 같은 화면에서 별도로 저장할 수 있다.
판매 가능 예상량은 available + 이번 수량이며 reserved는 별도 표시한다.
입고 내역은 배치·기간·상품명/코드·옵션 ID·처리자 ID·입고 위치로 조회한다.
입고 권한은 inventory, 새 상품/복사는 catalog도 요구한다. QR은 labels, 매출정산은 settlement 권한이다.
owner는 전체, warehouse는 기존 상품 입고·QR, finance는 매출정산, sales는 상품 초안/옵션 관리만 가능하다.

QR 재출력은 기존 물품 UUID만 사용하며 재고/QR을 새로 만들지 않는다. 배치 번호를 함께 제출하면 서버에서도 다른 배치 물품을 거절한다.
기존 라벨 규격 50×30mm를 유지하며 최대 200개 초과 조회는 조용히 자르지 않고 422로 거절한다.
긴 문구는 최소 6pt까지 맞추고 여전히 넘치면 더 큰 용지 선택을 안내하며 인쇄를 막는다. 브라우저 인쇄 요청 감사는 실물 출력 완료가 아니다.

## 장부 기준과 저장 보고서

0.4.0에서 누적 물품/주문/옵션/이벤트 전체 상한에 따른 차단을 조건별 DB 집계와 20개 옵션 페이지네이션으로 대체했다. 전체 합계는 모든 페이지를 대상으로 한다. 업체 쓰기 행을 잠그지 않는 REPEATABLE READ 일관 읽기를 사용한다.

기간 발생 주문액·입금 변동·취소·반품·환불과 현재 미입금·현재 주문 상태·현재 잔여 상품금액을 구분한다. 기간 순매출/회계상 매출 인식은 제공하지 않는다. 재고 전이 검증·기초/기말 대사·예약·외부/내부 A/S 기준은 유지한다.

업체·기간·시간대·기준 버전·생성 시각·생성자 및 집계/상세를 불변 보고서로 저장한다. 다시 생성하면 별도 UUID 버전이다. 저장된 결과를 최대 100행씩 분할 재조회·재출력하므로 화면 한 페이지만 출력하지 않는다. 이후 주문·반품·상품명 변경을 기존 보고서에 적용하지 않는다.

정확한 계산식·혼합 주문의 상품 조건·동시성/잠금 범위·보존/출력 경계·신규 migration은 [0.4.0 장부 기준](LEDGER_040.md), 실제 성능/환경/검증 결과는 [0.4.0 검증 보고](VERIFICATION_040.md)를 따른다. [0.3.0 검증 기록](VERIFICATION_030.md)은 당시 제한을 포함한 과거 기록이다.

## 서버 반영 준비 (운영 서버에서 실행하지 않음)

업무 쓰기를 중지하고 DB·업로드·APP_KEY·이전 코드와 환경 설정을 백업한다.
이 플러그인의 PHP/뷰/레이아웃/언어/migration/manifest/dist/components.json을 함께 반영한다. 코어나 다른 확장 갱신은 필요하지 않다.

```sh
php artisan plugin:update yutiv-live_commerce --source=bundled --force
php artisan plugin:vendor-verify yutiv-live_commerce
php artisan route:cache
```

새 설치는 `plugin:install` → `plugin:activate`. 0.3.0은 초기 migration 전 공식 런타임 오토로드 등록을 보완했다.
0.2.0에서 업데이트하면 000005(입고 헤더), 000006(저장 보고서/조회 인덱스)이 추가된다. 0.3.0에서는 000006만 추가된다. 기존 상품·옵션·물품·주문·원장·QR을 변경하지 않는다. 업무 데이터가 있으면 rollback하지 않고 전진 복구한다.
코어 공개 API 변경 없음. 번들 의존 manifest 스캔에서 이 플러그인의 소비 확장이 없어 다른 버전 제약 상향은 불필요하다.
프런트 변경은 `php artisan plugin:build yutiv-live_commerce --production` 공식 빌드로 dist와 components.json을 갱신한다.

## 자동 실행 확인과 systemd 대안

기존 `getSchedules()`의 매분 `live:maintain --batch=100 --seconds=45` 등록은 유지한다.
명령은 DB/prefix별 MySQL GET_LOCK으로 중복 실행을 막고 예약 만료·인센티브 대사 커서를 보존한다.
서버 상태는 이 작업에서 조회하지 않았다. 배포 담당자가 먼저 아래를 확인해야 한다.

```sh
crontab -l
sudo crontab -l
sudo -u www-data crontab -l
sudo grep -R -n -E 'schedule:(run|work)|live:maintain' /etc/crontab /etc/cron.d /etc/systemd/system
systemctl list-timers --all
systemctl list-units --all --type=service | grep -E 'scheduler|schedule|live-maintain'
ps -ef | grep -E '[a]rtisan (schedule:work|schedule:run|live:maintain)'
```

기존 Laravel scheduler가 있으면 그 경로가 이미 live:maintain을 호출하므로 전용 timer를 중복 설치하지 않는다.
기존 직접 cron/서비스가 있어도 같은 작업을 추가하지 않는다. 담당자 확인 후 기존 실행 경로가 전혀 없을 때만 다음 대안을 선택한다.
프로젝트 경로·PHP 8.3.6 바이너리·서비스 계정은 실제 환경에 맞게 바꾸고 읽기/쓰기 권한을 확인한다.

`/etc/systemd/system/yutiv-live-maintain.service`:

```ini
[Unit]
Description=YUTIV live reservation expiry and incentive reconciliation
After=network.target
ConditionPathExists=/var/www/yutiv-g7/.env

[Service]
Type=oneshot
User=www-data
Group=www-data
WorkingDirectory=/var/www/yutiv-g7
ExecStart=/usr/bin/php /var/www/yutiv-g7/artisan live:maintain --batch=100 --seconds=45
TimeoutStartSec=90
UMask=0027
NoNewPrivileges=true
```

`/etc/systemd/system/yutiv-live-maintain.timer`:

```ini
[Unit]
Description=Run YUTIV live maintenance each minute

[Timer]
OnCalendar=*-*-* *:*:00
Persistent=true
AccuracySec=1s
Unit=yutiv-live-maintain.service

[Install]
WantedBy=timers.target
```

담당자 확인 후 설치/확인:

```sh
sudo systemd-analyze verify /etc/systemd/system/yutiv-live-maintain.service /etc/systemd/system/yutiv-live-maintain.timer
sudo systemctl daemon-reload
sudo systemctl enable --now yutiv-live-maintain.timer
systemctl list-timers yutiv-live-maintain.timer
systemctl status yutiv-live-maintain.service yutiv-live-maintain.timer
journalctl -u yutiv-live-maintain.service --since today
```

중지 절차는 `sudo systemctl disable --now yutiv-live-maintain.timer` 후 실행 중 서비스가 있는지 확인하고, 필요할 때만 `sudo systemctl stop yutiv-live-maintain.service`로 중지한다.
스케줄러 경로로 전환할 때도 먼저 timer 중지를 확인한다. 원장·커서를 삭제하지 않는다. 운영 서버에는 어떤 명령도 실행하지 않았다.
