# 선택 옵션과 일반 상품

상품 등록(`/admin/ecommerce/products/create`)에서는 **선택 옵션 없음**이 기본입니다. 일반 상품은 판매가와 재고를 상품 입력란에서 입력합니다. 옵션명·값을 입력할 필요가 없습니다. 기존 **상품추가옵션**은 독립된 기능이며 이번 변경 대상이 아닙니다.

**선택 옵션 사용**을 누르면 그룹별로 옵션명과 옵션값을 입력합니다. 예: `색상` / `검정, 흰색`, 다른 그룹은 `사이즈` / `S, M, L`. 다른 언어의 값도 같은 순서로 입력하세요. 최대 3개 그룹이며 조합 생성 후 기존 방식대로 각 조합의 가격·재고를 편집합니다. “이 그룹 삭제”는 그 입력 그룹을 제거하며, 이미 생성된 조합은 재생성을 확인하기 전까지 유지됩니다.

기존 옵션 상품을 열면 저장된 그룹을 복원합니다. 조합 재생성은 기존 확인 절차를 사용하고 같은 조합의 ID·코드·가격·재고를 보존합니다. 기존 선택 옵션을 “없음”으로 바꾸거나 모두 제거하여 저장하는 요청은 서버에서 차단합니다. 주문이 참조하는 판매 단위를 삭제하고 새 ID로 교체할 수도 없습니다. 옵션 상품을 일반 상품으로 자동 변환하는 기능은 제공하지 않습니다.

## 공통 저장 규칙

- 일반 상품: `has_options=false`, `option_groups=[]`. 서버가 기존 `ProductOption` 모델로 단일 판매 단위를 만들며 `option_values=[]`, 상품 가격·재고와 동일하게 저장합니다. 재저장은 같은 레코드를 갱신하고 SKU/ID/코드는 유지합니다.
- 선택 옵션 상품: `has_options=true`, 실제 조합별 기존 옵션 저장 규칙을 사용합니다. 조합 최소 1개가 필요하고 상품 재고는 활성 조합 재고 합계입니다.
- ProductService의 생성·수정·목록 일괄 가격/재고 편집은 일반 상품의 판매 단위를 함께 동기화합니다. 주문 재고 차감·복원은 기존 StockService 경로를 사용하고 상품 재고도 동기화합니다.
- 엑셀 옵션 시트에 해당 관리코드 행이 없으면 일반 상품으로 처리합니다. 미리보기는 빈 옵션 배열만 검증하며 판매 단위를 만들지 않습니다. 확정 후 공통 ProductService가 생성합니다.
- 단일 판매 단위가 없는 기존 일반 상품은 수정 시 생성합니다. `has_options=false`인데 여러 판매 단위가 있는 불일치 데이터는 자동 삭제하지 않고 검토 오류를 반환합니다.

## 원인과 검증

이전 FormRequest는 모든 상품에 options 최소 1개를 요구했고, 옵션 필수 안내도 모드와 관계없이 표시했습니다. 일반 상품 재고 입력은 PC·모바일 모두 disabled=true였습니다. 옵션값의 기존 MultilingualTagInput은 쉼표 입력 시 첫 값만 외부 다국어 모달로 전달하고 입력을 비웠습니다. 해당 선택 옵션 입력만 IME를 지원하는 기존 MultilingualInput으로 바꾸고 raw 다국어 문자열을 유지하여 조합값으로 변환합니다. 공통 템플릿 컴포넌트는 수정하지 않았습니다.

실제 컴포넌트 렌더링 테스트에서 한글 composition 입력, 포커스 유지, 쉼표 입력, KO/EN 전환을 검사했습니다. 실제 브라우저 연결은 “No browser is available”로 실패하여 서버 화면의 오버레이·포커스·런타임 오류와 실제 OS 한글 입력기는 미검증입니다.

SQLite :memory:에서 실제 저장 서비스·마이그레이션·모델을 사용한 생성/조회/수정/재저장, 단위 중복 방지, 옵션 편집 식별자·가격·재고 보존, 장바구니의 자동 단위 연결, 주문 참조 보존, 재고 차감·복원, 일반/옵션 혼합 엑셀 등록을 확인합니다. 주문 테스트는 실제 Order/OrderOption 참조와 StockService를 사용하며 전체 checkout·결제 흐름 실행 검증은 아닙니다. MySQL 동시 잠금과 운영 큐는 미검증입니다.

```sh
# 별도 테스트 DB 설정 후 실행; 개발/운영 DB 자격증명 사용 금지
php vendor/bin/phpunit plugins/_bundled/yutiv-product_import/tests
cd modules/_bundled/sirsoft-ecommerce
npm ci
npm run test -- --run resources/js/__tests__/handlers/optionModes.test.tsx resources/js/__tests__/handlers/optionHandlers.generateOptions.test.ts resources/js/__tests__/handlers/productOptionHandlers.test.ts resources/js/__tests__/handlers/updateOptionField.test.ts resources/js/__tests__/layouts/productOptionsAdditionalToggle.test.tsx
npm run build
```

## 서버 반영

모듈의 변경 소스·JSON·언어 파일·dist를 전달한 후 사용자가 프로젝트 루트에서 실행합니다. 모듈 버전은 1.2.1이며 옵션 변경 자체에는 DB migration이나 새 PHP 의존성이 없습니다. 아래 overwrite는 설치된 ecommerce 레이아웃과 DB 레이아웃을 번들 수정본으로 갱신하므로 서버에서 직접 편집한 레이아웃이 있다면 먼저 백업하세요.

```sh
php artisan module:update sirsoft-ecommerce --source=bundled --force --layout-strategy=overwrite
php artisan module:cache-clear sirsoft-ecommerce
php artisan route:clear
php artisan queue:restart
```

엑셀 플러그인 설치·migration·권한·워커·스케줄러 명령과 파일/이미지 제한은 [엑셀 등록 안내](../../../../plugins/_bundled/yutiv-product_import/README.md)에 있습니다. commit·push·서버 반영은 수행하지 않았습니다.
