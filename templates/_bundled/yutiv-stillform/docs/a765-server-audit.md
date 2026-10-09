# a7654a5 실제 서버: 모바일 잘림·CSP 집중 확인

2026-10-09. 작업 시작 Git은 깨끗했고 HEAD는 `a7654a5bd0dde9c3fdb1014429eabb59cf3f6a27`이었다. 실제 https://yutiv.com 응답만 사용했다. 로컬 응답 덮어쓰기, 코드 수정, dist 재빌드, commit/push/서버 적용은 하지 않았다. 변경은 이 보고서와 검증 근거 파일뿐이다.

## 확인 조건

앱 내 브라우저가 연결되지 않아 기존 로컬 Chrome 154.0.8037.98로 확인했다. 확장 프로그램이나 로그인 세션이 없는 새 컨텍스트를 사용했다. PC 1440×1000, 모바일 393×852, 확대 100%(`visualViewport.scale=1`), 가로 스크롤 시작 위치(`scrollX=0`), 네트워크 캐시 비활성화 조건이다. 일반 Chrome UA 컨텍스트의 첫 로드와 새로고침을 확인한 뒤, iPhone UA·터치·DPR 3의 별도 새 모바일 컨텍스트에서도 Console 기록을 비우고 새로고침했다. DevTools `Audits.issueAdded`, Console, pageerror, 실패 요청, `securitypolicyviolation`을 수집했다.

첨부 화면은 로그인 상태지만 이번 검증은 비로그인 상태다. 실제 iPhone 하드웨어 및 사용자의 브라우저 프로필/확장은 확인하지 않았다.

## 모바일 오른쪽 잘림: 재현되지 않음

첫 로드·새로고침 모두 `documentElement.clientWidth=393`, `scrollWidth=393`, `innerWidth=393`, `scrollX=0`, 확대 배율 1이다. 모든 표시 요소의 bounding rect를 검사했으며 화면 경계를 0.5px 이상 넘는 요소는 없었다. 모바일 에뮬레이션에서 세로 스크롤 0/1500/1624px에서도 같은 결과다.

| 실제 요소 | 왼쪽 x | 오른쪽 x | 폭 | 적용 CSS |
|---|---:|---:|---:|---|
| `#mobile_header` | 0 | 393 | 393 | flex, border-box |
| `#mobile_header_right` | 201 | 377 | 176 | flex, gap-1 |
| `#main_content` | 0 | 393 | 393 | width:100%, border-box, 좌우 padding 20px |
| `.sf-hero-copy` | 20 | 373 | 353 | min-width:0 |
| `.sf-hero-media` | 20 | 373 | 353 | min-width:0, 모바일 1열 grid |
| `.sf-empty` 상품 준비 안내 | 20 | 373 | 353 | 정상 컨테이너 폭 |
| `.sf-story-band` | 20 | 373 | 353 | 353px 1열 grid |
| `.sf-editorial` 하단 안내 | 20 | 373 | 353 | max-width:960px, border-box |
| 마지막 `.sf-footer-group` | 20 | 373 | 353 | 내부 168.5px/168.5px 2열 |

html/body/루트/본문/히어로/하단 안내는 측정한 `overflow-x:visible` 상태다. 잘림을 숨기는 CSS를 추가하지 않았다. [393×852 뷰포트 캡처](screenshots/a765-audit/viewport-393x852.png), [모바일 전체 화면](screenshots/a765-audit/server-393.png)에서 오른쪽 여백과 이미지 끝이 보인다.

첨부 화면의 잘림은 이번 조건에서 재현되지 않아 CSS 원인을 특정할 수 없다. 캡처 문제라고도 단정하지 않는다. 지속 재현 시 동일 화면에서 clientWidth/scrollWidth/innerWidth/scrollX/visualViewport.width/scale 및 경계를 넘는 요소의 좌표, 로그인 여부가 필요하다.

## CSP eval: 재현되지 않음

새 컨텍스트 첫 로드·새로고침 및 기록을 비운 별도 모바일 새로고침에서 CSP eval 관련 DevTools Issue, Console 오류, 런타임 오류, securitypolicyviolation은 0건이다. 실패 요청 및 HTTP 400 이상 응답도 일반 PC/모바일 감사에서 0건이다. 홈 문서 응답에는 CSP/CSP-Report-Only 헤더가 없었고 CSP meta도 없었다. 이 관찰은 다른 문서·iframe·로그인 상태의 정책이나 사용자 브라우저 확장에 대한 결론이 아니다.

실제로 잡힌 Issues는 플러그인 bundle의 DocumentCookie 성능 알림(0-based line 0, column 3797), PC 입력 요소의 id/name 누락 GenericIssue였다. eval 차단과 다른 이슈이므로 이를 CSP 원인으로 취급하지 않았다.

로컬 템플릿 source와 G7 코어를 검색했으며 코어 표현식 평가기는 `SafeExpressionEvaluator`를 사용하는 현재 구현이다. `new Function`에 관한 주석·과거 CHANGELOG·테스트 문자열이 있다는 사실만으로 서버에서 eval이 실행되었다고 판단하지 않았다. 차단된 URL/호출 위치가 수집되지 않아 출처를 템플릿·코어·외부 스크립트·확장 중 하나로 분류할 수 없다. 사용자 기능 장애도 이번 관찰에서 확인되지 않았다. unsafe-eval 추가나 CSP 변경은 하지 않았다.

사용자 브라우저에서 계속 발생한다면 **Issues의 CSP 항목을 펼친 Affected resources의 스크립트 URL·행/열**, Console의 오류 및 호출 스택, 새 비로그인/확장 없는 컨텍스트에서의 재현 여부를 제공해야 출처를 특정할 수 있다. 로그인 계정 메뉴·주문·인증 기능은 이번에 검증하지 않았다.

## 배포 반영 확인

서버 템플릿 CSS/JS는 HTTP 200이며 로컬 dist SHA-256과 일치한다. 두 자산 URL의 버전은 `v=1791505188`이다.

- CSS: `1b8ce9d62542dec6f089fc697a3ae835b895df469fa6eb147b28bdb37154ece9`
- JS: `88c10c6599fac4df519279228b438bf1b2f492893b485d1b7d8ebdbeb878384f`

모바일 메뉴에 브랜드 이야기·쇼핑몰 공지·배송 및 교환·반품이 표시된다. [서버 메뉴 캡처](screenshots/a765-audit/menu-393.png). 모바일 푸터 마지막 그룹은 전체 353px 폭 내부 2열, 높이 128px이며 푸터 전체 높이는 622px다. PC 히어로는 526.22/657.78px의 2열, 콘텐츠 1256px 폭, 푸터 353.5px 높이로 이전 검토와 같다. [실제 PC 화면](screenshots/a765-audit/server-1440.png).

추가 코드 수정 원인이 확인되지 않았으므로 서버에 적용할 신규 코드나 명령은 없다. TeeWide/API/인증/주문/리워드는 변경하지 않았다. 수정 전후 비교 대신 현재 배포본의 실제 화면만 기록했다.

## 측정 근거

- [첫 로드/새로고침 감사](screenshots/a765-audit/audit.json): 자산 해시, Issues, Console/Network/CSP, 전체 경계 검사.
- [주요 요소 좌표/CSS](screenshots/a765-audit/regions.json).
- [모바일 에뮬레이션 재확인](screenshots/a765-audit/mobile-emulation.json): 기록 비운 새로고침과 세로 위치별 경계 검사.
