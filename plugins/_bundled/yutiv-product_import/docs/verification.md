# 로컬 검증 기록 — 2026-10-09

시작/완료 HEAD: `a7654a5bd0dde9c3fdb1014429eabb59cf3f6a27`. 엑셀 최초 작업 시작 시 추적 파일 변경 없음. 옵션 수정 착수 시 작업 중이던 엑셀 플러그인·기존 사진·Still Form 감사 문서/스크린샷을 보존했습니다. 후속 수정은 sirsoft-ecommerce와 import 플러그인에 한정됩니다. commit/push/서버 반영 없음.

| 실행 | 결과 |
| --- | --- |
| PHP 8.4.26 PHPUnit (SQLite `:memory:`) | 42 tests, 210 assertions 성공 |
| Vitest | ecommerce 변경 범위 107 tests, import 요청 처리 3 tests 성공 |
| `npm run build` | ecommerce Vite 5.4.21 / import Vite 6.4.4 성공, 두 dist JS 포함 |
| PHP `-l` | 30 PHP 파일 성공 |
| Pint `--test` | 성공 |
| JSON/manifest 빌드 자산 확인 | 변경 범위 13 JSON 파일과 두 dist JS 자산 성공 |
| `api:docgen --scope=plugin:yutiv-product_import --dry-run` | 7개 엔드포인트 수집 |

PHPUnit은 실제 상품 저장 서비스·모델·관계·마이그레이션을 사용합니다. 옵션 합산 재고/가격, 비공개·판매중지, HTML 정제, 상품 미생성 미리보기, 금액/재고/카테고리/DB 범위 오류, 파일/기존 상품/예약 중복, 확정 재검증 및 브라우저 가격 무시, 워커 반복/실패 재시도, 이미지 실패 롤백·파일 정리·장애 기록 복구, 관리자 권한/소유권/권한 회수, 확장자·손상·수식·ZIP/셀/행/용량 제한, SSRF·DNS 고정·리다이렉트·숫자/16진수 호스트 우회 차단, 결과 원본 행/문자열, 실제 카테고리 경로와 빈 양식, 수동 상품등록 API와 공개 목록, 메뉴 재동기화와 관리자 언어 변경을 확인했습니다.

이미지 테스트는 실제 ProductImageService/Storage 정책을 사용하되 임시 Storage::fake 디스크와 통제된 이미지 전송을 사용합니다. 실제 인터넷 요청 또는 개발 서버 DB 쓰기를 하지 않았습니다. API 문서는 저장소의 공식 인벤토리·FormRequest 추출·스캐폴더로 생성한 후 코드 계약을 보강했습니다. API 예시는 서버 실측 응답으로 보고하지 않습니다.

미실행: 실제 관리자 브라우저 렌더링, Excel/LibreOffice 앱 편집 호환성, MySQL 동시 프로세스/잠금 검증, 실제 HTTPS/TLS 다운로드·운영 저장소 및 큐/스케줄러. 전체 기존 테스트 스위트 대신 변경 범위와 상품등록/공개 목록 회귀 검사를 실행했습니다. 설치/활성화와 배포는 실행하지 않았습니다.

주요 파일:

- `plugin.php`, `plugin.json`, `composer.json`, `config/import.php`: 플러그인·권한·메뉴·스케줄·의존성.
- `database/migrations/2026_10_09_000001_create_product_import_tables.php`: 작업/상품별 상태/고유 코드 예약.
- `src/Models`, `src/Enums`, `src/Services`: 상태·검증본 보관·확정/재시도·실제 상품 단위 저장/정리.
- `src/Support/Workbook.php`, `ImageFetcher.php`, `ImportAccess.php`: 제한된 XLSX·안전한 이미지 반입·본사 권한.
- `src/Http`, `src/routes/api.php`: 요청 검증·관리 API·소유권 검증·다운로드.
- `src/Jobs`, `src/Console`, `src/Providers`, `src/Listeners`: 비동기 처리·복구·설정·메뉴 유지.
- `resources/layouts/admin/product_import.json`, `resources/routes.json`, `resources/js/index.ts`, `dist/js/plugin.iife.js`: 관리자 화면과 빌드.
- `tests/Feature`, `tests/Unit`, `tests/frontend`: 변경 범위 검증.
- `README.md`, `CHANGELOG.md`, `docs/api`: 사용자 양식·운영 절차·API 계약.

서버 명령과 필요한 큐 재시도 시간 설정은 [사용법](../README.md#서버-반영-명령-사용자가-실행)에 있습니다. 기본 PHP 7.4는 사용하지 않았습니다. 로컬 PHP는 저장소 밖 `D:/work/yutiv-tools/php84/php.exe`를 사용했습니다. 테스트용 `.env.testing`은 별도 SQLite 메모리 DB로 구성했습니다.

## 옵션 후속 검사

일반 상품 생성/조회/수정 API·반복 저장의 단일 판매 단위 ID/가격/재고 유지, 목록 일괄 가격·재고 동기화, 기존 옵션의 ID/코드/가격/재고 유지와 모드 제거 거부, CartService의 옵션 미지정 연결, StockService 차감·복원, 실제 Order/OrderOption 참조 유지 및 참조 판매 단위 교체 API 422, 일반/옵션 혼합 import를 검사했습니다. 전체 checkout·결제 실행은 미검증입니다.

실제 MultilingualInput 렌더링에서 한글 composition 이벤트·같은 DOM과 포커스 유지·쉼표 값·KO/EN 전환을 확인했습니다. 서버 브라우저 연결은 No browser is available로 실패하여 서버의 입력 불능, overlay/disabled/runtime 조건과 실제 OS IME는 미검증입니다. 소스의 쉼표-외부모달-입력초기화 경로를 선택 옵션 화면에서 교체했습니다. [원인·사용법·저장 규칙](../../../../modules/_bundled/sirsoft-ecommerce/docs/product-option-modes.md)을 참고하세요.
