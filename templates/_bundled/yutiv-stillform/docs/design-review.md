# Still Form 실제 화면 수정 — 2026-10-09

로컬 수정 및 production dist 재빌드 완료. commit/push, 서버 템플릿 파일 변경, DB 레이아웃 업데이트는 수행하지 않았다. 기존 미추적 `tests/Integration/`는 보존했다. TeeWide 및 코어·상품·회원·주문·결제·인증·권한 코드는 수정하지 않았다.

## 실제 원인

https://yutiv.com/ 의 일반 Chrome User-Agent로 로드한 실제 G7 DOM과 computed style을 확인했다. HeadlessChrome 기본 UA는 SEO 화면으로 분기하므로 검증에 사용하지 않았다.

수정 전 1440px의 `.sf-hero`는 `display:grid`, `grid-template-columns:612px 612px`였다. CSS 누락이나 breakpoint 실패가 아니었다. 실제 자식은 다음과 같았다.

```html
<div class="sf-hero">
  <div class="sf-hero-copy">…</div>
  <div></div> <!-- stillform_store_banner: 주입 없어도 G7이 div 생성 -->
  <div><div class="sf-composition">…</div></div> <!-- media wrapper -->
</div>
```

빈 배너가 첫 행 오른쪽 셀을 차지해 미디어가 두 번째 행 왼쪽에 배치됐다. 실제 좌표는 문구 `(108,114)`, 빈 wrapper `(720,360)`, 미디어 `(108,606.5)`였다. 코어 `DynamicRenderer.tsx`의 extension_point 분기도 빈 div를 반환한다.

배너는 grid의 형제 영역으로 유지하고 `.sf-hero-grid`에는 문구와 `.sf-hero-media` wrapper 두 개만 넣었다. wrapper 제거를 추정하는 전역 선택자나 `display:contents`, 새 `!important`는 사용하지 않았다. 제목의 `overflow-wrap:anywhere`와 모바일 `.sf-root h1`의 같은 규칙을 제거하고 `word-break:keep-all`, `overflow-wrap:break-word`, 폭에 맞는 글자 크기·줄 높이를 적용했다. 기존 번역의 의미상 줄바꿈 `천천히 발견하는\n새로운 일상.`은 유지했다.

초기 모바일 측정에서는 닫힌 오른쪽 드로어의 이동 중 문서 폭이 581px까지 늘어났다. 안정된 재측정은 390px였다. 닫힌 드로어를 `hidden`으로 처리해 화면 밖 자식이 일시적인 가로 넘침에 참여하지 않게 했으며, 열기·ESC 닫기·포커스 복원을 확인했다.

1024px 추가 검증에서 `aspect-ratio:5/4`와 `min-height:500px`가 비주얼 너비를 625px로 늘려 문서 폭이 1112px가 되는 문제도 발견했다. 해당 미디어에 `width:100%`를 명시해 grid 셀 너비를 지키게 했다.

## 변경 파일

- `layouts/partials/home/_hero.json`: 배너와 두 열 분리, 미디어 wrapper 클래스.
- `layouts/partials/home/_brand_story.json`: 미디어 wrapper 클래스, CSS에서 모바일 문구 우선 배치.
- `layouts/home.json`, `layouts/partials/home/_no_products.json`: 빈 영역을 포함하는 이중 `py-16`을 전용 여백으로 변경. 기존 API/로딩/오류/0건 조건 유지.
- `layouts/_user_base.json`: 닫힌 드로어 표시, 짧은 푸터 소개 연결.
- `src/styles/stillform.css`: 열 비율, 콘텐츠 gutter, 타이포그래피, 스토리·CTA·푸터 비율.
- `src/components/composite/Header.tsx`: 헤더·본문 폭 일치, 검색 flex 항목의 최소 너비 해제.
- `lang/{ko,en,ja,zh-CN}.json`: 짧은 푸터 소개 4개 언어.
- `__tests__/layouts/stillform.test.tsx`: 실제 DynamicRenderer에서 빈 배너 wrapper가 두 열 밖에 있는지 회귀 확인.
- `dist/css/components.css`, `dist/js/components.iife.js`, `dist/build-manifest.json`: production 재빌드 및 source/dist 체크섬.
- `CHANGELOG.md`, 이 보고서 및 전후 캡처.

## 수정 전후 캡처 및 실측

| 폭 | 수정 전 실제 서버 | 수정 후 실제 G7 + 로컬 응답 대체 |
|---|---|---|
| PC 1440px | [수정 전](screenshots/before-1440.png) | [수정 후](screenshots/after-1440.png) |
| 모바일 390px | [수정 전](screenshots/before-390.png) | [수정 후](screenshots/after-390.png) |

수정 후는 서버 API와 서버 G7 엔진을 그대로 사용하면서 브라우저 요청에만 로컬 dist, 홈 컴포넌트 트리, 드로어 표시 속성, 푸터 문구를 대체했다. DB에 파셜을 등록하는 서버 과정은 실행하지 않았다. 따라서 **배포된 서버 수정본 검증은 미완료**이며, 픽스처 통과를 서버 검증으로 보고하지 않는다. 쿠키 안내는 실제 거부 버튼으로 닫고 캡처했다.

| 항목 | 수정 전 | 수정 후 |
|---|---|---|
| PC 콘텐츠 폭 | 1224px, x=108 | 1256px, x=92 |
| PC 히어로 | 문구 위 / 미디어 아래, 오른쪽 비어 있음 | 문구 526px / 미디어 658px, 간격 72px |
| 모바일 히어로 | 문구 302px + 이중 좌우 패딩 | 문구·비주얼 350px, 좌우 gutter 20px |
| 빈 상품 전체 영역 PC | 362.75px | 218.75px |
| 빈 상품 전체 영역 모바일 | 381.5px | 197.5px |
| 모바일 제목 | 42px, 단어 중간 줄바꿈 가능 | 33.93px, 단어 단위 줄바꿈 |

320·390·768·1024·1440px에서 문서 가로 넘침 0, 실제 G7 runtime error 0. 1440px 두 열, 390px 한 열과 제목·스토리 문구를 시각적으로 확인했다. 어두운 테마도 별도 캡처했다. 빈 상품 상태는 실제 공개 상품 API 응답을 사용했으며, 가짜 상품·가격·리뷰를 서버에 쓰지 않았다.

## 원본과 비교 및 남는 차이

[원본 서버](https://stillform.20ft.co.kr/)를 1440·390px 브라우저로 열고 [원본 저장소](https://github.com/20ft-bahamut/20feet-stillform-template)의 HeroBanner, BrandStorySection, EditorialBanner, StoreHeader/StoreFooter와 design-tokens를 비교했다.

| 영역 | 이번 반영 | 남는 차이 |
|---|---|---|
| 헤더 | 본문과 동일한 1320px 상한·gutter | 원본은 한 줄 로고/링크. YUTIV는 검색·언어·통화·주문조회와 두 줄 메뉴 유지 |
| 히어로 | 종이 배경, 여유 있는 열 간격, 약 44:56 비율과 5:4 큰 비주얼, 모바일 문구→미디어 | 원본은 약 41:59 비율, 사진, 굵은 제목과 테두리 보조 버튼. YUTIV는 가벼운 제목·밑줄 링크 |
| 본문 폭/간격 | 1320px 상한, PC gutter 32px·모바일 20px, 콘텐츠 중심 정렬 | 상품 없는 실제 서버이므로 원본의 많은 상품 섹션·전체 페이지 길이와 직접 같지 않음 |
| 스토리 | PC 왼쪽 세로 비주얼/오른쪽 문구, 모바일 문구 먼저 | 원본 사진·브랜드 인장 제외. CSS 조형은 사진의 질감·생활 맥락을 재현하지 못함 |
| 에디토리얼/CTA | 원본 하단 Explore의 좁고 중앙 정렬된 ivory CTA 비율 | 원본의 별도 사진+문구 에디토리얼은 없음. 이번 CTA는 그 섹션의 완전한 복제가 아님 |
| 상품 | 별도 로컬 픽스처에서 PC 4열·모바일 2열 확인 | 원본 인기상품의 큰 대표 카드+작은 카드 구성은 현재 균등 카드 배치와 다름 |
| 푸터 | 어두운 바탕, 짧은 소개, 정돈된 링크 그룹 | 원본의 사업자 정보 중심 푸터 대신 YUTIV 기존 정보·마이페이지 링크 유지. 실제 사업자 정보는 API 값 있을 때만 표시 |

원본 저장소는 MIT 라이선스이나 사진마다 별도의 출처·사용 권한 근거는 확인되지 않았다. 이번 작업은 원본 사진·로고·상품 데이터를 추가하지 않았다. **히어로·스토리의 사진을 CSS 조형으로 대체한 차이는 여전히 크다.** 기존 API 상품 사진은 기존 미디어 정책을 그대로 따른다. 추후 사용 범위가 확인된 브랜드 사진을 미디어 확장 지점에 넣을 수 있다.

## 검증

- `npm run build`: production IIFE/CSS/선언 파일 생성 성공.
- `npm run type-check`: 성공.
- `npm run check:static`: 170 layouts, 4개 언어 2677 keys, 기존 40 routes 포함 검사 및 source/dist SHA256 성공.
- `npm run test:run -- __tests__/layouts/stillform.test.tsx`: 10개 성공. 실제 DynamicRenderer의 링크·wrapper·쿠폰 표시·주문 성공 라우트 계약 확인.
- 실제 서버 Chromium: 원본/수정 전 캡처, 브라우저 한정 수정 응답의 5개 폭과 드로어 열기/닫기, dark mode 검증.
- `node tests/Preview/yutiv-stillform/build.cjs --umd tests/Preview/yutiv-commerce/.umd`, `browser-probe.cjs`, `check.cjs`: 기존 52개 픽스처 화면의 빌드·브라우저 실측·렌더 검사 성공. 431개 이미지 로드, 가로 넘침·미해석 번역/표현식·runtime/resource 오류 위반 0건. 상품 배치는 PC 4열/모바일 2열. 서버 상품·DB와 분리되어 있음.
- 미검증: 서버 반영 후 설치된 파일/DB 레이아웃·캐시 상태, 실상품 등록 상태의 서버 화면, 실제 로그인·주문·결제 E2E. Safari/iOS와 다른 브라우저 미검증.

## 서버 적용 절차 — 사용자가 실행

1. 현재 `templates/yutiv-stillform/`와 이 템플릿의 `template_layouts` 행을 백업하고, 서버에서 직접 바꾼 레이아웃이 있는지 확인한다. `overwrite`는 해당 템플릿의 DB 레이아웃을 번들 기준으로 갱신한다.
2. 로컬 수정본을 사용자가 commit/push하고 서버에서 해당 커밋을 가져온다. 이번 커밋에 production dist를 포함하면 서버 npm 재빌드는 필요 없다. 소스만 배포했다면 먼저 `php artisan template:build yutiv-stillform --production`을 실행한다.
3. 활성 디렉터리 파일과 DB 레이아웃을 함께 갱신한다.

```bash
php artisan template:update yutiv-stillform --source=bundled --force --layout-strategy=overwrite
php artisan template:cache-clear yutiv-stillform
```

현재 코어의 update 경로는 `_bundled/yutiv-stillform`에서 설치된 `templates/yutiv-stillform`로 파일을 복사하고, 활성 상태를 복원한 뒤 `refreshTemplateLayouts()`를 실행해 DB 레이아웃과 캐시 버전을 갱신한다. 버전을 올리지 않은 수정이므로 `--force`가 필요하다. GitHub 원본 소스로 덮어쓰지 않게 `--source=bundled`를 지정한다.

설치된 파일을 이미 다른 배포 방법으로 갱신했다면 다음 명령으로 **설치된 디렉터리의** JSON을 DB에 동기화할 수 있다. `_bundled`만 수정한 상태에서 이 명령만 실행하면 이전 설치 파일이 다시 읽힌다.

```bash
php artisan template:refresh-layout yutiv-stillform
php artisan template:cache-clear yutiv-stillform
```

4. 브라우저 캐시를 새로고침하고 1440·390px에서 확인한다. 홈 레이아웃 API에서 `.sf-hero-grid` 아래 문구/미디어 두 자식, 그 밖의 배너를 확인한다. 자산 응답에 새 grid CSS가 있는지, API·언어 자산 URL의 캐시 버전이 갱신됐는지 확인한다. 빈 상품 안내 높이, 드로어 닫힌 상태, 제목 단어 줄바꿈을 확인한다. 이 단계가 완료되어야 서버 배포 검증 완료로 볼 수 있다.
