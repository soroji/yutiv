# 상품 목록 숫자 표시 보완

`fitColumns`와 상품명 두 줄 배치, Select/ActionMenu portal, 우측 관리 열 고정을 유지한다. 정가·판매가·재고는 14px monospace, 한 줄, 오른쪽 정렬로 표시하며 줄바꿈·말줄임·잘림을 사용하지 않는다.

## 폭 배분과 제약

CompactNumberInput은 표시 문자열의 실제 폭을 측정하고 글꼴/입력값 변화에 다시 측정한다. 첫 측정에는 글자당 8.5px의 보수적인 기준을 사용한다. 숫자 버튼의 테두리 여유 4px와 셀 좌우 padding 최소 8px를 더해 해당 열의 최소 폭으로 전달한다. 열의 여유 폭과 상품명 열의 여유 폭을 먼저 조정한다. 모든 최소 폭의 합보다 컨테이너가 좁으면 최소 폭을 비례 축소하지 않고 표 내부 스크롤을 허용한다. 기본 DataGrid에는 적용하지 않는다.

모델의 금액 저장 용량은 unsigned decimal(15,2), 즉 정수부 13자리+소수부 2자리다. 재고 저장 용량은 signed integer이고 음수를 허용하지 않는 정상 입력의 상한은 2147483647이다. 기존 입력 검증·가격 포맷·통화 규칙을 변경하지 않는다. 읽기 전용 다통화 환산값도 통화 기호·구분자를 포함한 문자열 전체를 한 줄로 측정한다. 표시 문자열이 더 길거나 글꼴의 실제 폭이 더 크면 측정 결과에 따라 최소 폭도 더 커질 수 있다.

최대 표시 예시 `9999999999999.99` / `2147483647`에서는 금액 열 각각 148px, 재고 열 97px가 필요하다. 현재 전체 열 구성의 계산상 최소 표 폭은 **1221px**다. 일반적인 정수 금액 10000, 재고 100에서는 기존 최소 표 폭 **992px**를 유지한다. 1366px 화면에서 사이드바 288px와 나머지 여백 80px를 가정하면 가용 폭은 998px이며 최대 자릿수를 동시에 표시하려면 223px 부족하다. 이 경우 모든 열의 단일 화면 표시와 숫자 전체 표시를 동시에 보장할 수 없으므로 내부 스크롤이 필요하다. 이 사이드바/여백 수치는 테스트 가정이며 실제 서버 측정이 아니다.

240px 숫자 편집란은 document.body portal과 높은 겹침 순서를 유지하고, 화면 가장자리를 기준으로 좌표를 보정한다. ESC/Enter, 포커스 복원, 원래 change 이벤트와 min/max/step/disabled 처리를 유지한다.

## 검증

- Select, DataGrid, MultilingualInput, ProductListDensity, CompactNumberInput, fitColumns 6개 파일: 163개 테스트 통과.
- 1920/1440/1366px와 사이드바 288/64px 조합의 폭 배분, 최대 자릿수의 최소 폭 보호, 편집 중 숫자 길이 증가, 240px 편집란의 우하단 좌표 보정 검증. 폭/좌표 테스트는 모킹이며 실제 렌더링 검증이 아니다.
- 브라우저 연결 시 `No browser is available`, 가용 브라우저 목록은 빈 배열이었다. 실제 페이지의 clientWidth/scrollWidth, sticky 겹침, 스크린샷은 미검증이다.
- 기존 다국어 tabs 모드의 중첩 button 경고가 테스트에 출력된다. 이번 목록의 list 모드에는 해당 구조가 없으며 별도 기존 문제다.

빌드와 테스트 제외 소스 TypeScript 검사는 통과했고 `module:vendor-verify sirsoft-ecommerce`는 OK (0.4 MB, 1 packages)다. 전체 테스트 TypeScript 검사는 기존 테스트 오류 때문에 이번 통과 범위에 포함하지 않는다.

## 반영 대상

이번 추가 보완은 `sirsoft-admin_basic`의 DataGrid, CompactNumberInput, fitColumns와 테스트 및 dist, 이커머스 상품 목록의 읽기 전용 환산값 표시 변경이다. 앞선 미반영 fitColumns 상품 레이아웃 변경까지 함께 반영하려면 관리자 템플릿과 이커머스 모듈을 모두 갱신한다. 새 의존성, composer 변경, migration은 없다. 기존 API·가격·재고·권한·주문 처리는 변경하지 않는다.

```sh
php artisan module:vendor-verify sirsoft-ecommerce
php artisan template:update sirsoft-admin_basic --source=bundled --force --layout-strategy=overwrite --no-interaction
php artisan module:update sirsoft-ecommerce --source=bundled --vendor-mode=bundled --force --layout-strategy=overwrite --no-interaction
php artisan template:cache-clear sirsoft-admin_basic
php artisan module:cache-clear sirsoft-ecommerce
```

이번에는 읽기 전용 다통화 환산값 표시도 변경했으므로 관리자 템플릿과 모듈을 모두 갱신한다. overwrite 전에 서버에서 별도로 수정한 해당 확장 레이아웃을 백업한다. commit/push/서버 명령은 이 작업에서 실행하지 않는다.
