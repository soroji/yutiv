# c3585c0 서버 화면 검토

2026-10-09 검토. 작업 시작 시 Git 변경은 없었고 로컬 HEAD는 배포 커밋 `c3585c090704ed6f4a87a8e582d1ce2353fca7f9`와 같았다. 이번 수정은 로컬에만 있으며 commit·push·서버 적용은 수행하지 않았다. 이전 검토 기록과 화면은 보존했다.

## 실제 서버 확인

일반 Chrome으로 https://yutiv.com 을 1440px와 390px에서 열었다. 앱 내 브라우저가 제공되지 않아 기존 로컬 Chromium/Playwright를 사용했다. 별도 격리 서버나 픽스처 환경은 구축하지 않았다.

서버가 로드한 템플릿 CSS/JS는 모두 HTTP 200이며 시작 시 로컬 dist와 바이트 단위로 일치했다. URL의 버전 값은 `v=1791503906`이었다. 캐시 불일치가 원인이 아니다.

| 자산 | 서버 SHA-256 |
|---|---|
| css/components.css | `5b9d54b8b90298515d21dfdc5b933f2c969072f60dcc786a2fe7fe14d381b48a` |
| js/components.iife.js | `88c10c6599fac4df519279228b438bf1b2f492893b485d1b7d8ebdbeb878384f` |

PC 히어로는 실제 DOM에서 526.22px/657.78px의 2열이었다. 모바일은 좌우 20px 여백 안에 350px의 1열이었다. 390px 화면의 문서 폭도 390px이며 제목은 keep-all이 적용되어 단어 중간 줄바꿈이나 잘림을 발견하지 못했다. 이전 히어로 wrapper 수정은 배포본에서 정상 동작했다.

홈과 이번에 확인한 공개 메뉴 이동에서 Console 오류·경고, 런타임 오류, 실패 요청, HTTP 400 이상 응답, CSP 위반은 모두 0건이었다. 이 관찰 범위를 넘어 오류가 없다고 보장하지 않는다. CSP 설정은 변경하지 않았다.

## 발견한 문제와 수정

| 문제 | 확인한 원인 | 수정 |
|---|---|---|
| 모바일 메뉴에서 브랜드 이야기·쇼핑몰 공지·배송 안내 진입 누락 | `_user_base.json`의 `mobile_nav_drawer` 쇼핑 그룹에 해당 버튼이 없었다. PC 헤더/푸터 및 경로 자체는 존재했다. | 기존 번역과 `/story`, `/notice`, `/shipping` 경로를 사용하는 버튼을 추가했다. 기존 방식으로 전역 메뉴 상태를 닫고 이동한다. |
| 모바일 푸터 마지막 그룹 오른쪽에 큰 빈 공간 | 브랜드가 전체 열을 차지한 뒤 링크 그룹 3개가 2열 grid에 배치되어 마지막 마이페이지 그룹만 왼쪽 열에 남았다. | 홀수 개 링크 그룹일 때 마지막 그룹이 전체 폭을 사용하고 내부 링크를 2열로 배치한다. 44px 클릭 영역을 유지한다. |

모바일 푸터 높이는 718px에서 622px로 줄었다. 마지막 그룹은 167px 폭/224px 높이에서 350px 폭/128px 높이로 바뀌었다. PC 푸터 높이 353.5px와 PC 화면은 동일하다. 상품이 없는 영역은 이전 배포본의 간결한 안내를 유지했다. 가짜 상품·가격·리뷰는 추가하지 않았다.

## 수정 파일

- `layouts/_user_base.json`: 모바일 메뉴 버튼 3개.
- `src/styles/stillform.css`: 모바일 푸터 마지막 그룹 배치.
- `dist/css/components.css`, `dist/build-manifest.json`: production 재빌드 결과.
- `CHANGELOG.md`: 변경 기록.
- 이 보고서와 `screenshots/post-deploy/`: 화면 및 검증 근거.

JS도 재빌드했지만 출력은 동일해서 JS dist 파일의 변경은 없다. 수정 CSS SHA-256은 `1b8ce9d62542dec6f089fc697a3ae835b895df469fa6eb147b28bdb37154ece9`이다. API·인증·주문·결제·동적 쇼핑 경로와 TeeWide·개인 스토어 리워드는 수정하지 않았다.

## 화면 비교와 검증

**before는 실제 서버 응답**, **after는 실제 서버 페이지에서 템플릿 CSS/JS 및 해당 레이아웃 응답만 로컬 수정본으로 덮어쓴 브라우저 화면**이다. after는 서버 배포 결과가 아니다. 서버 데이터와 확장 슬롯은 유지했다.

| 화면 | 서버 수정 전 | 로컬 응답 적용 후 |
|---|---|---|
| PC 1440px | [before](screenshots/post-deploy/before-1440.png) | [after](screenshots/post-deploy/after-1440.png) |
| 모바일 390px | [before](screenshots/post-deploy/before-390.png) | [after](screenshots/post-deploy/after-390.png) |
| 모바일 메뉴 | [before](screenshots/post-deploy/menu-before-390.png) | [after](screenshots/post-deploy/menu-after-390.png) |

- `npm run build`: 성공.
- `npm run check:static`: 성공. 경로·번역·JSON 및 source/dist 일치 검증.
- `npm run test:run -- __tests__/layouts/stillform.test.tsx src/components/composite/__tests__/Stillform.test.tsx`: 57개 통과.
- 실제 서버 1440/390px 및 로컬 응답 적용 1440/390/320/768px에서 가로 넘침 없음.
- 추가 메뉴의 실제 SPA 경로 이동과 메뉴 닫힘, ESC 닫힘 및 토글로 포커스 복귀 확인.
- 두 모드 모두 관찰한 Console/Network/CSP 오류 없음.
- [최초 서버 감사](screenshots/post-deploy/server-audit.json)의 로컬 일치 값은 **수정 시작 전** dist와 비교한 결과다. [최종 검증](screenshots/post-deploy/verification.json)은 모드별 측정값을 포함한다.

새 수정의 서버 반영 후 화면은 미검증이다. 로그인 후 주문·결제 전체 흐름 및 실제 상품이 채워진 서버 화면은 이번에 실행하지 않았다.

## 참고 디자인과 남은 차이

https://stillform.20ft.co.kr/ 도 1440/390px에서 직접 확인했다. 원본 저장소의 디자인 코드와 기존 구현 차이도 함께 검토했다.

| 구분 | 차이 및 필요한 조치 |
|---|---|
| 구현 오류·누락 | 이번 모바일 메뉴 누락과 푸터 빈 열을 수정했다. 배포된 히어로 2열/1열 및 한글 줄바꿈은 정상이다. |
| 콘텐츠 부족 | 원본의 사진 대신 CSS 꽃병·원 조형을 사용한다. 실제 상품이 없어 카테고리·신상품·인기 상품 사진과 카드가 없다. 권한 확인된 브랜드/편집 사진, 실제 상품·분류·상품 이미지 및 사업자 정보를 준비해야 한다. 간격 수정만으로 원본과 같아지지 않는다. |
| 기존 구현의 의도적 변경 | YUTIV는 검색·언어·통화·주문/계정 기능을 가진 2단 헤더, 원본보다 가벼운 제목, 약 44:56 히어로 비율, 계정 링크 중심 푸터 및 중앙 CTA를 사용한다. 원본은 간결한 1단 헤더, 강한 제목, 사진 중심 편집 섹션과 사업자 중심 푸터다. 기존 상품 grid도 원본의 대형 인기 상품 카드 구성과 다르다. |

원본 코드의 MIT 라이선스와 사진 개별 사용 권한은 별개다. 사진의 사용 권한은 확인되지 않아 가져오지 않았다. 참고 사이트 사진이 담긴 비교 캡처는 저장소에 추가하지 않았다.

## 사용자가 서버에 반영할 절차

사용자가 변경을 검토해 commit/push하고 서버에서 해당 커밋을 받은 뒤 프로젝트 루트에서 실행한다. dist가 포함되므로 서버 npm 재빌드는 필요하지 않다.

```sh
php artisan template:update yutiv-stillform --source=bundled --force --layout-strategy=overwrite
php artisan template:cache-clear yutiv-stillform
```

`overwrite`는 설치된 템플릿 파일과 DB 레이아웃을 번들 수정본으로 동기화한다. 사용자 편집 DB 레이아웃이 있으면 먼저 백업하고 차이를 검토한다. 파일만 수동으로 복사하는 경우 `template:refresh-layout yutiv-stillform`은 `_bundled`가 아니라 **설치된 템플릿**의 레이아웃을 읽으므로 설치 파일부터 갱신해야 한다.

적용 후 브라우저에서 로드한 CSS의 새 해시, 모바일 메뉴 3개 및 2열 마지막 푸터 그룹을 확인하고 1440/390px 화면과 Console/Network를 다시 확인한다. JS 해시는 그대로인 것이 정상이다.
