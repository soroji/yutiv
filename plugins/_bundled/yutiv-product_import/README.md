# 엑셀 상품 일괄등록

본사 관리자 **상품 관리 → 엑셀 상품 일괄등록** (`/admin/plugins/yutiv-product_import/products`). 양식을 내려받고 상품·옵션 시트를 입력한 뒤 업로드합니다. 오류가 없을 때 등록을 확정하고 진행 상태와 결과 엑셀을 확인합니다. 새 상품은 **비공개·판매중지**로 등록되므로 기존 상품 수정 화면에서 내용과 이미지를 확인한 뒤 판매를 시작하세요. 기존 상품 업데이트·삭제는 지원하지 않습니다.

일반 회원과 개인 범위 관리자는 접근할 수 없습니다. 최고관리자 또는 범위 제한 없는 `yutiv-product_import.headquarters.create`와 `sirsoft-ecommerce.products.create` 관리자 권한이 모두 필요합니다. 서버가 업로드·확정·재시도·조회·다운로드와 상품 처리 시 권한을 확인합니다. 작업은 요청 관리자만 조회할 수 있고 최고관리자는 전체 작업을 조회할 수 있습니다.

## 양식과 입력 예시

다운로드 파일에는 **입력 안내 / 상품 / 옵션 / 카테고리 안내** 네 시트가 있습니다. 상품·옵션 시트에는 헤더만 있으며 예시는 안내 시트에만 있습니다. 헤더와 열 순서를 바꾸지 마세요. 코드는 셀 서식을 텍스트로 유지하세요. 앞자리 0이 손실될 수 있는 숫자 형식 식별자와 모든 입력 수식은 거부합니다.

상품 열:

| 열 | 입력 |
| --- | --- |
| 관리코드 | 영문·숫자·밑줄·하이픈 1~50자, 대소문자 구분 없이 중복 금지. `000123` |
| 상품명 | 현재 관리자 언어의 상품명. `예시 상품` |
| 카테고리 코드 | 다운로드 시점 실제 카테고리 ID, 여러 개는 `12\|34` (최대 5개). 첫 코드가 대표 |
| 판매가 | 양수, 쇼핑몰 기본통화와 기존 소수 자릿수 규칙. KRW 예: `12000` |
| 재고 | 0 이상 정수. 단일 상품 `5`, 옵션 상품은 옵션 재고 합계 |
| 상세설명 | 선택, 기존 수동 등록과 같은 HTMLPurifier 정제 정책 |
| 대표 이미지 URL | 선택, 승인된 공개 HTTPS 이미지 주소 1개 |
| 추가 이미지 URL | 선택, 공개 HTTPS 이미지 주소를 `\|`로 구분 |

카테고리 안내에는 코드·상위 경로 포함 이름·사용 가능 여부가 있습니다. 같은 이름이라도 ID와 경로로 구분하세요. 카테고리 또는 상위 카테고리가 비활성이면 사용할 수 없습니다.

옵션 열: **상품 관리코드 / 옵션 식별자 / 옵션명1 / 옵션값1 / 옵션명2 / 옵션값2 / 옵션명3 / 옵션값3 / 추가금액 / 재고**.

기존 상품 구조는 조합별 옵션과 옵션 그룹을 저장합니다. 최대 3개 그룹을 표현하고 실제 판매할 조합마다 한 행을 작성하세요. 같은 상품의 그룹 이름과 순서는 같아야 하고 식별자와 조합은 중복될 수 없습니다. 첫 옵션은 기본 옵션으로 추가금액 0이며 나머지는 판매가에 더할 금액입니다. 총 상품 재고는 옵션 재고 합계입니다. 옵션 없는 상품은 옵션 시트에 행을 추가하지 않습니다.

| 상품 관리코드 | 옵션 식별자 | 옵션명1 | 옵션값1 | 옵션명2 | 옵션값2 | 옵션명3 | 옵션값3 | 추가금액 | 재고 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 000123 | 0001 | 색상 | 베이지 | 크기 | M | | | 0 | 3 |
| 000123 | 0002 | 색상 | 검정 | 크기 | M | | | 1000 | 2 |

이 예시라면 상품 재고는 5입니다. 이미지 없는 상품도 등록할 수 있습니다. 예시 사진·상품·가격·리뷰를 서버에 시드하지 않습니다.

## 제한과 오류 처리

- 파일: `.xlsx`만, 10MiB 이하. 매크로·수식·외부 연결·손상 XML·외부 엔티티·압축 경로 이탈 거부. ZIP 항목 1000개, 압축 해제 전체 50MiB/개별 16MiB 이하. 입력 셀은 65535바이트 이하입니다.
- 상품: 헤더 다음 500행, 옵션: 헤더 다음 2000행, 상품당 옵션 100개. 뒤쪽 빈 행을 과도하게 추가하지 마세요. 지정 양식 외 입력 열은 지원하지 않습니다.
- 금액 절댓값은 DB의 DECIMAL(15,2) 범위인 9,999,999,999,999.99 이하, 상품/옵션 재고는 signed INT 범위인 2,147,483,647 이하입니다. 통화 소수 규칙도 함께 적용합니다.
- 이미지: 대표 포함 상품당 5개, 파일당 10MiB, 각 변 10000px, 2000만 픽셀 이하. 실제 JPEG/PNG/GIF/WebP만 허용하고 기존 업로드·리사이즈·저장소 정책도 적용합니다.
- HTTPS 443만, 인증 정보·프래그먼트·내부 호스트와 사설/예약/링크로컬/메타데이터 주소를 거부합니다. 모든 DNS 응답을 검사하고 연결 IP를 고정합니다. 리다이렉트마다 목적지를 다시 검사하며 최대 3회, 각 요청 연결 5초/전체 20초 제한입니다. IPv6는 보수적인 공개 범위만 지원합니다. 프록시 환경변수에 의존하지 않습니다.
- 미리보기는 URL/DNS만 검증하며 이미지를 다운로드하거나 상품을 생성하지 않습니다. 입력 오류는 시트·원본 행·열·수정 방법을 제공합니다. 오류가 하나라도 있으면 수정 후 재업로드해야 합니다.
- 확정 후 입력은 서버 저장본을 사용합니다. 카테고리·기존 코드·상품 규칙과 권한을 재검증합니다. 실행 중 실패 상품은 롤백하고 미디어 파일을 정리합니다. **실패 상품만 재시도**하면 성공 상품은 다시 생성하지 않습니다. 실패 코드 예약은 유지되어 다른 업로드로 우회 재등록할 수 없습니다.
- 결과는 상품 결과·옵션 결과·검증 오류 세 시트이며 원본 행을 보존합니다. 모든 내보내기 셀은 텍스트로 기록하여 `=`, `+`, `-`, `@`로 시작하는 입력도 수식 실행되지 않습니다.

## 구현과 추가 구조

기존 `StoreProductRequest` 규칙·교차 검증과 `ProductService`, `ProductImageService`, `SequenceService`, 모델 관계/이벤트, 재고 동기화, HTML 정제를 재사용합니다. 직접 SQL로 상품을 삽입하지 않습니다. 수동 등록의 선택 옵션 수정도 함께 반영하며 인증·주문 API·TeeWide·개인 스토어·리워드 소스는 변경하지 않습니다.

**옵션 시트에 해당 상품 행이 없으면 일반 상품**입니다. 가짜 기본 옵션 행은 필요 없습니다. ecommerce 1.2.1의 공통 저장 서비스가 `has_options=false`, 빈 옵션 그룹/값과 내부 판매 단위 1개를 저장합니다. 가격·재고는 상품 입력값을 사용하고 재저장 시 판매 단위 ID를 유지합니다. 고객은 기본 옵션을 선택할 필요가 없습니다. 선택 옵션의 그룹명·값·조합 편집과 기존 옵션 모드 변경 제한은 [수동 등록 안내](../../../modules/_bundled/sirsoft-ecommerce/docs/product-option-modes.md)를 참고하세요.

기존 `product_code`는 내부 채번 코드로 유지합니다. 관리코드는 기존 `sales_product_code`에 저장하지만 해당 필드에는 고유 제약이 없어 별도 `yutiv_product_import_identities`로 대소문자 무시 코드 예약을 보장합니다. 기존 상품의 내부 코드/판매상품코드/SKU와 삭제 상품까지 중복 검사합니다. 수동 등록 자체의 중복 정책을 바꾸지는 않습니다.

마이그레이션 하나가 `yutiv_product_import_runs`, `yutiv_product_import_rows`, `yutiv_product_import_identities` 세 테이블을 추가합니다. 작업·원본 입력·검증 데이터·상품별 상태·미디어 정리 기록을 저장합니다. 상품 단위 DB 트랜잭션과 행 잠금·고유 예약·성공 상태가 중복 확정/워커 재실행을 방어합니다. 지속적인 대기 행을 스케줄러가 다시 전송하고 실패 미디어 정리도 재시도합니다. 공통 일반 상품 저장 규칙을 사용하므로 `sirsoft-ecommerce >=1.2.1`을 요구합니다. 옵션 지원에는 추가 migration이 없습니다.

추가 PHP 라이브러리는 없습니다. PHP 8.2+와 zip/dom/curl/fileinfo 및 기존 이미지 처리 확장, ecommerce의 기존 HTMLPurifier 의존성이 필요합니다. XLSX는 계산 엔진 없이 제한된 OOXML 테이블만 읽고 씁니다. 프런트 개발 의존성은 Vite/Vitest이며 잠금 파일과 빌드 결과를 포함합니다. API 계약은 [docs/api](docs/api/README.md)를 참조하세요.

## 서버 반영 명령 (사용자가 실행)

서버에 변경 파일을 반영한 뒤 PHP 8.2+ 환경에서 실행합니다. 새 플러그인 설치 과정이 마이그레이션을 실행합니다. 관리자 권한 부여는 본사 역할에만 적용하세요.

```sh
php artisan module:update sirsoft-ecommerce --source=bundled --force --layout-strategy=overwrite
php artisan module:cache-clear sirsoft-ecommerce
php artisan plugin:install yutiv-product_import
php artisan plugin:activate yutiv-product_import
php artisan plugin:refresh-layout yutiv-product_import
php artisan plugin:cache-clear yutiv-product_import
php artisan route:clear
```

이미 설치된 환경에서 이 마이그레이션만 적용할 경우:

```sh
php artisan migrate --path=plugins/_bundled/yutiv-product_import/database/migrations --force
```

빌드는 포함되어 있습니다. 재빌드할 경우:

```sh
cd plugins/_bundled/yutiv-product_import
npm ci
npm run build
```

`.env`에 `YUTIV_PRODUCT_IMPORT_QUEUE_CONNECTION=database` 또는 기존 Redis 연결 이름을 지정하고 해당 `config/queue.php` 연결의 **retry_after를 600초 이상**으로 설정합니다. sync/null 드라이버는 거부합니다. 작업 timeout은 450초입니다. 웹 업로드 제한 `upload_max_filesize`는 10M 이상, `post_max_size`와 웹 서버 요청 제한은 multipart 오버헤드를 포함해 더 크게 설정하세요.

```sh
php artisan config:clear
php artisan queue:work database --queue=yutiv-product-import --timeout=450 --tries=3
php artisan schedule:run
```

워커는 프로세스 관리자로 유지하고 `schedule:run`은 기존 매분 cron을 사용합니다. Redis 사용 시 위 database를 연결 이름으로 바꾸세요. 워커 재시작은 `php artisan queue:restart`, 수동 미전송 복구/실패 파일 정리는 `php artisan yutiv:product-import-dispatch`입니다. 워커의 이미지 저장소 접근 권한과 DNS/HTTPS 접근을 확인하세요. 같은 서버의 스케줄러/워커를 사용합니다. 여러 호스트에 워커를 분산하면 임시 다운로드 경로도 공유해야 장애 호스트의 임시 파일을 정리할 수 있습니다.

## 검증과 남은 확인

PHPUnit은 실제 코어/ecommerce/import 마이그레이션과 상품 서비스를 **SQLite 메모리 테스트 DB**에서 사용합니다. 기존 SQLite 비호환의 무관한 게시판/로그 마이그레이션은 제외합니다. 테스트 이미지 파일은 Storage::fake에서 처리하고 DNS/전송은 통제한 대역으로 대체하며 개발 서버에 데이터를 생성하지 않습니다.

실행 전 `.env.testing`을 별도 테스트 DB 또는 SQLite `:memory:`로 구성하고 테스트 의존성을 설치하세요. 개발/운영 DB 자격증명을 복사하지 마세요.

```sh
php vendor/bin/phpunit plugins/_bundled/yutiv-product_import/tests
cd plugins/_bundled/yutiv-product_import
npm test
npm run build
```

로컬 기본 PHP 7.4 대신 저장소 밖의 PHP 8.4.26으로 검사했습니다. 단일/조합 상품·재고·금액·카테고리·중복·미리보기·확정 재검증·권한/소유권·재시도·이미지 롤백/정리·워크북 수식/손상/제한·SSRF/리다이렉트·결과 행/문자열·기존 수동 등록/공개 목록과 프런트 요청을 검사합니다.

**미검증:** 실제 브라우저 관리자 화면, 실제 Excel/LibreOffice 앱의 편집 호환성, 실제 인터넷 이미지 전송/TLS 및 운영 저장소, MySQL의 다중 프로세스 동시 실행, 운영 큐/스케줄러. 서버에서 우선 빈 양식 다운로드와 관리자 권한/일반 회원 거부를 확인하고, 데이터 생성 검증은 별도 테스트 DB에서 실행하세요. 작업/코드 예약 보존 기간과 자동 삭제는 첫 버전에 포함하지 않습니다.
