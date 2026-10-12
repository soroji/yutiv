# TeeWide 메인 배경 출처와 배포

- 확인일: 2026-10-12
- 참고 페이지: https://golfif.store/index.php
- 적용 원본: https://golfif.store/images/golfif_pc.png
- 근거: 페이지 `.pc-page` CSS의 `background`와 실제 브라우저 이미지 Network 응답(HTTP 200).
- 저장 위치: `resources/assets/golfif-pc.png` (플러그인 루트 기준)
- SHA-256: `7a8c7751ce6dc97305e387a34afc73b554c7783fbced3dce1ba74959624bc5c7`
- 크기: 1672×941, 2,208,050 bytes. 원본 bytes를 그대로 보존했으며 이미지의 Golfif 로고·문구를 편집하지 않았다.
- 사용자의 원본 복제 요청에 따라 적용했다. 외부 사이트의 나머지 이미지·아이콘·주문 기능은 복제하지 않았다.

참고 사이트는 모바일에서 `images/golfif_mobile_bg.png`라는 별도 이미지를 사용한다.
TeeWide는 요청에 따라 PC·모바일 모두 같은 `golfif-pc.png`를 사용하고 CSS 위치·오버레이만 조정한다.
저장소의 기존 `back.png`, `background.jpg`는 사용하거나 수정하지 않았다.

## 자산 로딩

기존 TeeWideHostGate는 TeeWide 호스트의 코어 `/api/plugins/assets/...` 요청도 차단한다.
인증·호스트 게이트·라우팅을 변경하지 않기 위해 설치된 플러그인의 PNG를 메인 전용
Blade에서 읽어 CSS data URI로 포함한다. 외부 핫링크·스크린샷 배경·이미지 변환은 사용하지 않는다.
브라우저가 디코딩한 PNG bytes의 해시도 원본과 일치하는지 검증한다.
대신 메인 HTML에는 약 2.94MB의 base64 데이터가 포함되며, 독립 이미지 HTTP 캐시는 사용하지 않는다.

배포 대상은 `yutiv-live_commerce` 0.1.4의 메인 Blade/partial, 이 PNG, manifest/Composer 버전이다.
활성 설치본 갱신은 기존 공식 `plugin:update` 절차를 사용한다. 파일 누락을 감추는 대체 이미지는 없다.
TS/JS/CSS 번들 소스, 외부 의존성, migration 변경이 없으므로 npm 빌드는 필요하지 않다.
`broadcast-preview`와 기존 `main-styles`는 라이브 홈에서 계속 사용하므로 보존한다.
메인만 `golfLanding` 분기를 사용하고 로그인·가입·계정 화면의 기존 공통 헤더는 유지한다.
