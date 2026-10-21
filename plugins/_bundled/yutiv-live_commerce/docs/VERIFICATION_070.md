# 0.7.0 검증 결과

## 환경과 범위

- 기준 main / HEAD `09389ffbea5691593dd536ecff7c46ec1352fb63`, 시작 작업 트리 깨끗함. 플러그인 내부만 변경. commit/push/운영 접속/배포 없음.
- 운영 자격정보 없는 격리 복사본 `C:/Temp/live-accounting060-test`, `C:/Temp/live-accounting060-browser`를 재사용했다. DB 읽기/쓰기 모두 합성 DB, 전용 MySQL 포트 33319다. 브라우저는 로컬 HTTP 18974와 `.test` 호스트만 사용했다.
- 실제 PHP **8.4.26 / MySQL 8.4.11**, 코어 7.0.9. 대상 **PHP 8.3.6 / MySQL 8.0.46**과 다르며 대상 버전에서 직접 실행한 결과가 아니다.

## 검증 결과

최종 Feature 전체 **236 tests / 70,359 assertions** 통과. 실행 시간 8분 04.037초, 최대 메모리 144MiB. (이는 로컬 회귀 테스트 실행 수치이며 서비스 부하 벤치마크가 아니다.)

- 최종 방송 집중 검증: **6 tests / 2,085 assertions**, 28.503초 / 76MiB. 총 건수 상한을 인위적으로 2로 내려도 페이지 20행과 다음 탐색이 유지되고 ‘이상’ 표시가 나오는 사례 포함.
- JavaScript: **13 tests / 5 files** 통과. 정수 요율, 3인 균등 배분의 0.01% 잔여, 기존 최대 잔여 방식의 원 배분, 오전/오후 이름을 검증했다. 브라우저에서는 변경한 예정시간을 별도 판매시간 기본값으로 연결하는 동작도 확인했다.
- PHP 문법: 플러그인 **218파일**, 오류 0. 신규 방송 클래스·입력 검증 코드의 Pint 적용 및 git diff --check 통과.
- 설치 Smoke: **2 tests / 29 assertions**, 27.273초 / 66MiB, 공식 `composer test-smoke` 통과.
- 공식 `plugin:build --production`: JS **49.52KB**, CSS **22.06KB**. manifest 5종 0.7.0과 dist 동기화. 공식 update, route:cache, vendor:verify 통과.

방송 검증에는 등록 쇼호스트가 Jessi 한 명인 신규 폼의 빈 선택, 없음/한 명/여러 명, 중복·타 업체 차단, 100% 지분, 소수 요율/지분 및 잘못된 합계, 별도 시간과 날짜 검증, 23건 목록의 안정적 정렬/페이지, 기존 URL 및 권한이 포함된다. 동시 방송별 상품/판매 링크 귀속, 기간 전/내/후 고객 노출, 방송상품 숨김과 원본 판매 설정 보존, 기존 주문 스냅샷 불변 및 종료 후 입금 처리와 재고 대사도 검증했다.

## 실제 Chrome

빌드된 설치본에서 1440/1920/390px로 조회→신규 폼→참여자 선택→등록→수정→재조회를 실행했다. 수동 방송명 보존, 빠른 채널 전환, 단독 100% 및 두 명 50/50 저장·재조회, 100,000원 기준 예상 8,580원, 옵션 추가/방송 노출 중지, 별도 판매시간 재조회와 목록 조건 복원을 확인했다. JavaScript pageerror 0. 상품관리·회계관리·주문·출고도 세 해상도에서 HTTP 200 및 문서 가로 넘침 없음을 확인했다.

| 화면 | 변경 전 | 변경 후 |
|---|---|---|
| PC 1440 | [기존 큰 등록 폼](verification/broadcast070/screens/before-1440.png) | [목록 중심](verification/broadcast070/screens/list-saved-1440.png), [방송 상세](verification/broadcast070/screens/detail-1440.png) |
| PC 1920 | [기존 화면](verification/broadcast070/screens/before-1920.png) | [기본 정보](verification/broadcast070/screens/detail-top-1920.png), [참여자](verification/broadcast070/screens/participants-1920.png) |
| 모바일 390 | [기존 화면](verification/broadcast070/screens/before-390.png) | [기본 정보](verification/broadcast070/screens/detail-top-390.png), [두 명 참여자](verification/broadcast070/screens/participants-390.png) |

## 공식 업데이트와 원장 보존

0.6.0→0.7.0 공식 업데이트 전후에 **LiveEntity 44테이블의 전체 행 수와 SHA256**이 일치했다. 비교 대상에는 주문 2,602건/상세 10,405행, 물품 4,365개/재고 이동 25,374건, 지출 10,008건/자금 기록 16건, 기존 저장 보고서 25개/상세 316,450행이 포함된다. 해시는 기존 전체 컬럼을 대상으로 계산했으며 이후 브라우저 검증용 신규 방송/진행자를 만들기 전에 비교했다. 예전 장부를 수정하거나 원장 데이터를 변환하지 않는다.

**신규 migration 없음.** 방송/JSON 참여자/판매상품 순서·활성 컬럼은 기존 것을 사용한다. 판매자가 수수료·노출을 수정해도 이미 생성된 주문의 가격·귀속·수수료 스냅샷은 유지한다. 유효하지 않은 현재 정책으로 새 주문을 만들지 못하도록 검증만 보강했다.

배포 대상은 `plugins/_bundled/yutiv-live_commerce/` 전체이며 공식 빌드·manifest·dist·문서·테스트가 포함된다. 실제 배포하지 않았다. [흐름·계산 기준·원인·복구](BROADCAST_070.md), [API 계약](api/broadcasts.md), [원본 검증 자료](verification/broadcast070/)를 함께 검토할 수 있다.
