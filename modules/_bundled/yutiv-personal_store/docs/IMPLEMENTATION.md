# 개인 스토어 구현·운영 계약

0.1.1 최종 배포 점검과 systemd 서비스/timer 예시는 [DEPLOYMENT.md](DEPLOYMENT.md)를 따른다. 앞선 보고서의 "무시된 ecommerce composer.lock 변경" 설명은 정정한다: 해당 lock은 추적 파일이다. 최종 공식 갱신은 content-hash와 Composer 빈 배열 표기만 변경했고 외부 패키지 ezyang/htmlpurifier v4.19.0은 유지했다. TeeWide는 외부 의존성/lock/vendor bundle이 없어 공식 검증 결과는 skip이다.

최종 위험별 검증: 가입·스냅샷·상태·유지보수·레이아웃 17 tests/105 assertions, 실제 관리자 취소+환불 저장 1/5, legacy/0원/순서 변경 배송 7/31, 외부 HTTP를 fake한 실제 PG callback 1/11, TeeWide 가입 2/114 통과. PG callback 테스트에는 개인 스토어 원장까지의 통합 assertion은 없으며 PG→completePayment→동기 리스너 연결은 소스와 별도 리워드 상태 테스트로 확인했다. 실제 PG 호출은 하지 않았다. 같은 버전 PHP 8.3.6/MySQL 8.0 실행과 systemd unit 검증은 미실행이다.

공식 격리 install/update/activate 후 새 프로세스에서 installed provider 경로, ecommerce 1.3.1/personal_store 0.1.1 active, Still Form 1.0.1의 공개·상세·운영자 라우트와 레이아웃 병합을 확인했다. 수동 provider/임시 라우트 등록을 쓰지 않았다. 공개/운영자 SPA HTML과 G7Config 내장 JSON, 공개 API 및 404에 합성 구매자 이름/전화/이메일/원본 주문번호 marker가 없음을 검사했다. 구매자 전용 기존 주문 API는 구매자에게 주문 정보를 제공하는 별도 경계이며 운영자 API와 혼동하지 않는다.

## 로컬 검증 기록

운영 환경과 분리한 저장소 사본, PHP 8.4.26, MySQL 8.4.11, 전용 임시 DB 및 `it_` 접두사에서 검증했다. 실제 회원·주문·결제나 외부 결제 호출은 사용하지 않았다.

- 신규 모듈: 25 tests / 271 assertions, 통과. 별도 PHP 프로세스의 중복 outbox 처리와 반대 방향 동시 이동을 포함한다.
- 기존 계산·생성·옵션·취소·카트 회귀: 385 tests / 972 assertions, 통과.
- 추가 임시주문·주문·구매자 마일리지 및 신규 라인 회귀: 69 tests / 137 assertions, 통과. 앞선 테스트와 일부 중복하므로 합계를 고유 테스트 수로 해석하지 않는다.
- TeeWide 화면·인증 검증: 46 tests / 2420 assertions, 통과. 임시 브라우저 렌더 fixture를 포함한다.
- 신규 migration: 격리 MySQL에서 17개 테이블 down/up/down/up, 주문 라인 컬럼 down/up/up 통과. 운영 rollback을 권장하는 근거가 아니다.
- 실제 Chromium PC 1440px / 모바일 390px: 공개 목록·상세·운영자·본사 8개 화면과 TeeWide 메인 4개 화면에서 가로 넘침 없음. 모바일 상품 카트 추가 HTTP 201 및 유입 증명 전송 확인. 본사 외부 폰트/아이콘 CSS는 네트워크 차단되어 해당 자산의 시각 결과는 미검증이다.
- 공식 격리 설치·활성화·라우트/레이아웃 병합·scheduler 등록, Still Form production build 및 ecommerce vendor bundle 생성 확인. 신규 모듈에는 자체 프런트엔드 package.json이 없어 module:build는 적용 대상이 아니다.

최종 재검증 중 같은 격리 DB를 사용하는 두 실행이 겹쳐 초기화 충돌이 발생했다. 두 실행을 종료한 후 직렬로 재실행해 위 통과 결과를 확인했다. 전체 프로젝트 테스트, 실제 PG 결제, 브라우저 전체 가입/체크아웃 완료, 운영 서버 설정·배포 상태는 이 검증 범위에 포함하지 않는다.

## 경계와 화면

이 모듈은 YUTIV User/Sanctum만 사용한다. TeeWide의 guard, 회원, tenant, channel, 세션, 도메인과 연결하지 않는다. 사용자 제공 서버 결과의 활성 템플릿은 `yutiv-stillform`이다. 이번 검증에 설치하는 템플릿/모듈/DB는 로컬 격리 사본이며 실제 서버 활성 상태의 증거가 아니다.

- 공개 `/store/{slug}` → `user_store` → 활성 사용자 템플릿 `_user_base`의 content 슬롯.
- 공개 `/store/{slug}/products/{product_code}` → `user_detail` → Still Form `shop/show` 상속. 기존 `core.layout.filter_parent_data` 훅이 이 자식의 명시적 marker에만 상품 data source endpoint를 대체한다. 같은 ID를 상속 자식에 추가하지 않으며 일반 상세는 바꾸지 않는다.
- 일반 `/store-office` → `user_operator`. 본인 주문 마이페이지와 분리한다.
- 본사 `/admin/personal-stores` → `admin_headquarters`, 전용 Admin permission. 일반 운영자는 Admin을 부여받지 않는다.
- 기존 `_global.shopBase`의 `route_path/no_route` 계산을 그대로 사용해 카트·체크아웃·주문완료로 이동한다. 비스토어 쇼핑 경로는 유입 헤더를 보내지 않는다.
- 등록 layout extension은 기존 `auth/register`와 `register_extension_fields` 앵커에만 적용한다. 호스트 registerForm 초기화 이후 init action에서 증명을 조회한다. FormRequest에서 용도/만료/서명/서버 참조/계보를 검증하고 User INSERT와 같은 트랜잭션에서 다시 검증해 회원 귀속을 생성한다. 전체 registration_data를 신뢰하지 않는다.

신규 모듈에는 커스텀 React/TS/CSS 번들이 없다. 기존 Still Form basic 컴포넌트·상세 구매 UI·components.json·dist를 사용한다. 따라서 `module:build`의 package.json 기반 프론트엔드 빌드는 적용 대상이 아니다. JSON/언어 partial/컴포넌트 참조를 별도로 검증한다. vendor-bundle 명령은 외부 Composer 패키지가 없어 공식적으로 skip한다. 필요한 플랫폼 확장은 `ext-bcmath`다.

## 저장 및 정책

회원 1명당 스토어 1개(unique user_id). 부모 없는 개설은 본사 승인 대기이며, 가입 때 검증된 회원 귀속이 있으면 그 스토어 아래에 개설한다. 방문은 기존 회원 귀속과 카트 라인을 바꾸지 않는다. 이동은 신청/본사 승인 경로이며 운영자 API에는 직접 이동 동작이 없다. 이동 후 새 부모→자식 배분율은 0으로 초기화하고 이력을 남긴다.

카테고리는 현재 하위 카테고리를 포함하며 새 상품에도 적용한다. 개별 포함은 OR, 개별 제외는 최우선이다. 상품 쿼리에는 EXISTS/IN을 사용해 다중 카테고리 상품을 중복 반환하지 않는다. 목록·상세·담기·최종 주문에서 판매 자격을 확인한다. 원본 주문의 통계는 snapshot 행을 한 번만 합산하고 closure JOIN의 중복 배수를 만들지 않는다. 하위 판매 화면은 집계 금액만 표시한다. 표시한 매출은 취소 전 할인 후 기준액이며 순매출이나 전체 주문 청구액으로 표현하지 않는다.

17개 테이블: revisions, stores, closure, allocations, memberships, applications, history, product_rates, products, categories, attributions, line_snapshots, beneficiaries, wallets, ledger, line_states, outbox (`personal_stores` 외에는 `personal_store_` 접두사). 회원/slug/원본 UUID/event_key/원장 멱등 키 unique, closure 조상·후손 PK, 선택 store/product PK, FK restrict delete. MySQL/MariaDB의 배분율 CHECK는 0~10000을 강제한다. 모든 FK/인덱스는 Laravel DB prefix를 적용한다. 긴 복합 인덱스 이름은 명시적으로 줄였다.

스토어 계층은 깊이 제한이 없다. 일렬 N개 closure 저장량은 자기 관계 포함 N(N+1)/2행이다. 하위 S개 이동 시 외부 조상 A개 관계를 삭제하고 새 조상 B개와 S개 관계를 삽입하므로 O(S(A+B)) 쓰기다. 개설·이동·배분율·상품 정책 변경·주문 스냅샷은 singleton revision row FOR UPDATE로 직렬화한다. 읽는 도중 계보와 비율이 섞이지 않는다. 대규모 계층에서 이 mutex와 closure 저장량은 실제 비용이며 수평 쓰기 처리량을 제한한다. 업무 깊이 제한으로 우회하지 않는다.

## ecommerce 1.3.1 최소 확장

`CalculationItem`/`ItemCalculation`, Cart/OrderOption에 nullable 원본 UUID/유입 참조를 추가한다. 계산 키는 원본 라인 UUID, 재계산 때 실제 order_option_id를 우선하며 상품 옵션 ID를 출력 필드로 유지한다. 기존 UUID 없는 단일 옵션 입력은 기존 키 계약을 유지한다. 카트 UUID와 주문 UUID는 다르다. 임시 주문 생성마다 새 UUID를 동결하므로 동일 카트의 여러 미결제 주문이 충돌하지 않는다. 분할 복제는 원본 UUID를 보존하며 다른 원본과 병합하지 않는다. 생성 옵션/배송/스냅샷 연결은 원본 line_uuid 조회로 연결한다. legacy NULL 직접 호출에만 순서 fallback이 남으며 실제 신규 주문은 UUID를 생성한다.

| 훅 | 인자·시점 |
|---|---|
| order.created_in_transaction | Order, TempOrder, OrderCalculationResult, array<int, OrderOption>. 옵션·결제·배송·0원 결제 처리 뒤, 주문 생성 DB 트랜잭션 커밋 전 |
| order_option.changed_in_transaction | 원본 OrderOption, 새 OrderStatusEnum, 결과(original/split/merged_into 등). 상태 변경 DB 트랜잭션 커밋 전 |
| order.cancellation_completed_in_transaction | Order, OrderCancel, OrderRefund. 취소 최종 상태 저장 뒤, 커밋 전 |
| cart.filter_direct_attribution | nullable 기존 참조, 입력 item 배열, ProductOption. 바로구매 transient Cart 생성 시 |

모든 훅 이름에는 `sirsoft-ecommerce.`가 붙는다. 동기 Listener(`sync: true`)의 예외는 전파돼 호출자 트랜잭션을 롤백한다. `order.filter_create_data`나 커밋 밖 `after_create`로 스냅샷을 만들지 않는다. 기존 공개 필드·메서드 시그니처는 유지한다. 소비 확장 전체 manifest를 조사했으며 신규 계약을 사용하는 모듈만 >=1.3.1 의존성이 필요하다.

## 리워드와 복구

주문 통화와 동결 기본 통화 모두 KRW일 때만 리워드 생성. 배송비를 제외하고 상품+옵션 소계에서 상품/주문/코드/등급 할인을 차감하며 구매자 포인트 사용은 차감하지 않는다. float 배분 없이 BCMath 중간 곱과 정수 P를 사용한다. pool=floor(base×HQ bps/10000), 각 단계 전달액=floor(incoming×child bps/10000), 잔여는 해당 노드가 보유한다. 최종 판매 스토어는 마지막 전달액을 보유한다. 10,000원×20%, 경로 50%→20%→50%의 결과는 1000/800/100/100P다.

snapshot에는 생성 옵션 ID, 상품/옵션/수량/단가/할인 배분/기준액, 통화, 전체 계보/율/revision, 계산 버전과 각 수혜액을 넣는다. 구매자 이름·연락처·배송지·추가옵션 자유 텍스트는 넣지 않는다. 원본 수정/삭제 모델 이벤트를 거부한다. 사후 변경 정책으로 재계산하지 않는다. 원장과 available/debt는 한 트랜잭션에서 쓰며 잠금은 outbox→라인 상태→store ID 오름차순 wallet이다. 주문 상태 캡처는 order→option ID 오름차순이다.

결제 paid_at 확인 전에는 지급하지 않는다. 결제 후 구매확정 전은 대기다. 부분 확정·취소는 원본 수량을 분모로 원래 각 수혜액의 누적 floor 목표를 계산한다. 균일 가격인 원본 라인의 수량 분할에 대응하며 마지막 전량 회수는 원래 지급액과 정확히 일치한다. 이미 확정된 취소 수량만 회수한다. 미완료 수동 환불과 취소 참조 없는 금액 전용 환불은 검토 상태로 보류한다. 실제 PG 취소와 취소 이력은 하나의 경제 사실로 취급하며 이력 숫자를 별도로 이중 회수하지 않는다. 원본과 매핑할 근거가 없는 금액 전용 환불의 자동 회수는 지원하지 않는다.

처리 원장: earn, recover, shortfall, offset. 부족액은 debt, 미래 earn 시 offset을 별도 기록해 발생액과 가용 증액을 구분한다. 구매자 UserMileageService/adminEarn을 재사용하지 않고 별도 지갑·원장을 사용한다. 쇼핑 사용·출금·현금 지급 API는 없다.

주문과 snapshot/created outbox는 같은 커밋에 포함된다. 상태 훅 이후 프로세스 중단은 현재 paid_at/원본 UUID별 확정·취소 수량으로 상태 outbox를 재등록해 복구한다. 중복 event_key와 원장 목표 키 unique, 상태 누적 max, 최신 검토 이벤트 순서, DB transaction retry=3으로 중복·역순·동시 처리에 수렴한다. 검토 상태 이후 도착한 오래된 확정 이벤트는 지급하지 않는다. 과거 NULL 원본 주문을 현재 계보로 소급 생성하지 않는다.

상태 캡처는 order→option→line state 순서로 잠근다. 마지막 상태 지문과 capture_version을 같은 트랜잭션에 저장해 A→검토→A도 새 전환으로 기록한다. 같은 현재 상태의 재시도는 같은 키를 사용한다. 대사는 생성 outbox 누락, 최신 캡처 outbox 누락, 미처리 건수, 검토 건수와 원장/잔액 불일치를 출력한다. `--enqueue`는 불변 snapshot에서 생성 이벤트를 재등록하고 현재 경제 상태를 다시 캡처한다. 이 검사는 전체 snapshot을 조회하므로 대규모 데이터의 주기를 조정해야 한다.

## 배포 준비와 복구 (실행하지 않은 서버 절차)

1. 유지보수 시간에 DB 전체·코드·설정·확장 활성 디렉터리·원본 스냅샷/원장을 일관된 시점으로 백업한다. 서버 PHP/BCMath/MySQL/MariaDB 버전, 실제 활성 Still Form과 ecommerce 버전을 확인한다.
2. 변경 `_bundled`와 공식 생성 vendor bundle을 배포한다. `php artisan module:update sirsoft-ecommerce --force --vendor-mode=bundled`로 1.3.1 코드와 nullable 컬럼 migration을 먼저 적용한다. 새 파일 등록 및 upgrade step은 공식 updater가 수행한다.
3. `php artisan module:install yutiv-personal_store --vendor-mode=bundled`, `php artisan module:activate yutiv-personal_store` 순서로 모듈·테이블·권한·메뉴를 설치/활성화한다. 없는 이전 버전 의존성을 강제 우회하지 않는다.
4. TeeWide는 별도 `plugin:update yutiv-live_commerce --force`로 0.1.1 Blade만 적용한다. 기존 disabled 스위치를 바꾸지 않는다. 로그인/account/live 경로를 smoke 검사한다. 기존 라우트 캐시 문제는 이 작업에서 수정하지 않았다.
5. `extension:update-autoload`, `template:cache-clear` 및 기존 배포 절차의 config/route cache 재생성을 적용하고 서버 scheduler를 연결한다. `/store-office`, `/admin/personal-stores`, 공개 상세·카트·가입 경로를 확인한다. 사이트 운영 DB에서 이번 테스트 명령이나 migrate:fresh를 실행하지 않는다.
6. `php artisan yutiv-personal_store:reconcile` 검사, `...:reconcile --enqueue` 누락 상태 재등록, `...:outbox --limit=500` 처리. closure 복구는 검사·백업 뒤 `--repair-closure`를 명시한다. cycle/누락 부모는 복구를 중단한다. 재전송은 원장 멱등 키를 삭제하지 않는다.

전용 Laravel queue worker는 요구하지 않는다. scheduler가 DB outbox 처리 명령을 매분, 대사/재등록 명령을 5분마다 실행한다. `retry_after`는 적용되지 않는다. DB 트랜잭션 내부 실패는 rollback 후 다음 실행에서 재시도한다. 운영자가 명령 timeout을 정할 때 깊은 라인의 수혜자 수·배치 규모·DB lock wait를 실측하고 강제 종료로 커밋 중간을 끊지 않도록 여유를 둔다. 대사는 전체 기존 snapshot 주문을 스캔하므로 대량 데이터의 주기/배치 비용을 모니터링해야 한다. 원장/잔액 검사는 동시 처리 중 읽기 불일치가 없도록 wallet 잠금을 사용한다.

DB 변경 뒤 이전 코드만 되돌리면 NULL 아닌 신규 라인/스냅샷과 확장 계약이 어긋난다. 리워드 데이터가 생긴 뒤 테이블/컬럼을 파괴하는 migration rollback은 권장하지 않는다. 장애 시 신규 유입을 중단하고 compatible 1.3.1 ecommerce와 schema/원장/outbox를 보존해 수정본을 적용한다. 백업 복구는 DB와 코드·설정·활성 디렉터리를 같은 시점으로 복원하며 그 이후 주문/원장 손실 여부를 대사한다.
