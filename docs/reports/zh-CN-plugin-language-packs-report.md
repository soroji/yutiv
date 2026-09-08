# 번들 플러그인 중국어 간체(zh-CN) 언어팩 구현 보고서

- 대상: `plugins/_bundled` 번들 플러그인 12종 조사 → **11종 구현 / 1종 제외**
- 기준 커밋: `a667a401916f52324b3f2a3cee85f934024f5979`
- 작성일: 2026-09-08

---

## 1. 작업 전 점검 결과

| 항목 | 결과 |
|---|---|
| `git branch --show-current` | `main` |
| `git rev-parse HEAD` | `a667a401916f52324b3f2a3cee85f934024f5979` — **지정 커밋과 일치** |
| `git status --short` | 출력 없음 — **clean** |
| `git remote -v` | `origin` → `https://github.com/soroji/yutiv.git`, `upstream` → `https://github.com/gnuboard/g7.git` |

작업 시작 조건을 모두 충족하여 진행했다.

### 1-1. "활성 여부"의 정적 판정 가능성

저장소에는 플러그인 활성 상태를 정적으로 판정할 수단이 **없다**.

- `config/plugin.php` 가 존재하지 않는다 (모듈은 `config/module.php` 가 있으나 플러그인은 없음).
- 활성 여부는 `plugins` 테이블의 런타임 상태이며 `PluginManager` 가 DB 를 읽는다.
- 설치 시 기본 활성 목록을 담은 시더·manifest 도 없다.

따라서 **번들 ≠ 활성**이며, 선정 기준은 "번들로 동봉되고 **공식 언어팩 인벤토리(README)에 등재**된 플러그인"으로 삼았다. 사용자가 알려준 운영 화면의 활성 4종(CKEditor 5 / Daum 우편번호 / GDPR / 마케팅 동의)은 모두 이 기준에 포함된다.

---

## 2. 플러그인 전체 조사 매트릭스

`be` = backend PHP 파일 수(`lang/ko/*.php`), `fe` = frontend 파일 수(`resources/lang/ko.json`),
`seed` = 대응 ja 팩의 seed 파일 수, `koLeaf` = ko 원본 리프 키 수(backend+frontend).

| # | 실제 plugin identifier | 한국어 이름 | 버전 | 번들 | be | fe | seed | koLeaf | 기존 ja 팩 identifier | ja 파일 | zh-CN 필요 | 근거 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 1 | `sirsoft-ckeditor5` | CKEditor 5 WYSIWYG 에디터 | 1.0.2 | O | 1 | 1 | 2 | 86 | `g7-plugin-sirsoft-ckeditor5-ja` | 6 | **포함** | 관리자 설정·업로드 이미지 관리 화면 문구 존재 |
| 2 | `sirsoft-daum_postcode` | Daum 우편번호 | 1.0.2 | O | 0 | 1 | 1 | 32 | `g7-plugin-sirsoft-daum_postcode-ja` | 3 | **포함** | 사용자 주소 검색 위젯·관리자 설정 문구 존재 |
| 3 | `sirsoft-gdpr` | GDPR (일반 데이터 보호 규정) | 1.0.3 | O | 1 | 1 | 3 | 452 | `g7-plugin-sirsoft-gdpr-ja` | 7 | **포함** | 쿠키 배너(사용자)·동의 이력(관리자) 문구 존재 |
| 4 | `sirsoft-marketing` | 마케팅 동의 | 1.0.3 | O | 2 | 1 | 1 | 84 | `g7-plugin-sirsoft-marketing-ja` | 6 | **포함** | 회원가입·마이페이지 동의 항목 문구 존재 |
| 5 | `sirsoft-message_bizppurio` | 비즈뿌리오 메시지 발송 | 1.0.0 | O | 3 | 1 | 3 | 393 | `g7-plugin-sirsoft-message_bizppurio-ja` | 9 | **포함** | 발송 설정·알림톡 템플릿 관리 화면 문구 존재 |
| 6 | `sirsoft-pay_kginicis` | KG 이니시스 | 1.1.2 | O | 4 | 1 | 1 | 339 | `g7-plugin-sirsoft-pay_kginicis-ja` | 8 | **포함** | 결제 설정·결제 조회·에스크로 화면 문구 존재 |
| 7 | `sirsoft-pay_nhnkcp` | NHN KCP | 1.0.3 | O | 4 | 1 | 1 | 161 | `g7-plugin-sirsoft-pay_nhnkcp-ja` | 8 | **포함** | 동일 |
| 8 | `sirsoft-pay_nicepayments` | 나이스페이먼츠 | 1.0.2 | O | 4 | 1 | 1 | 174 | `g7-plugin-sirsoft-pay_nicepayments-ja` | 8 | **포함** | 동일 |
| 9 | `sirsoft-tosspayments` | 토스페이먼츠 | 1.0.2 | O | 3 | 1 | 1 | 110 | `g7-plugin-sirsoft-tosspayments-ja` | 7 | **포함** | 동일 |
| 10 | `sirsoft-verification_kginicis` | KG이니시스 본인인증 | 1.0.4 | O | 2 | 1 | 1 | 97 | `g7-plugin-sirsoft-verification_kginicis-ja` | 6 | **포함** | 본인인증 모달(사용자)·설정(관리자) 문구 존재 |
| 11 | `sirsoft-verification_nhnkcp` | NHN KCP 휴대폰 본인확인 | 1.0.1 | O | 2 | 1 | 1 | 110 | `g7-plugin-sirsoft-verification_nhnkcp-ja` | 6 | **포함** | 동일 |
| 12 | `gnuboard7-hello_plugin` | Hello 플러그인 | 0.1.1 | O | 0 | 1 | 1 | 7 | `g7-plugin-gnuboard7-hello_plugin-ja` | 3 | **제외** | README 「학습용 샘플 확장」 표에 속한 데모. ja 팩도 공식 언어팩 인벤토리에 **미등재**. 번역 자산은 있으나 운영 화면 문구가 아님 |

모든 대상의 `vendor` = `sirsoft`, `license` = `MIT` (제외된 12번은 `gnuboard7` / `MIT`).
`g7_version` 은 플러그인마다 `>=7.0.0` ~ `>=7.0.8` 로 다르다(§4 참조).

---

## 3. 생성한 zh-CN 팩

11종 전부 `version: 1.0.0`, `scope: plugin`, `namespace: g7`, `vendor: sirsoft`, `license: MIT`,
`locale: zh-CN` / `Simplified Chinese` / `简体中文` / `ltr`,
`g7_version: ">=7.0.0"`, `requires.target_version: null`, `requires.depends_on_core_locale: true`.

| # | zh-CN identifier | target | 총 파일 | 번역 파일 | 리프 키 |
|---|---|---|---|---|---|
| 1 | `g7-plugin-sirsoft-ckeditor5-zh-CN` | `sirsoft-ckeditor5` | 6 | 4 | 96 |
| 2 | `g7-plugin-sirsoft-daum_postcode-zh-CN` | `sirsoft-daum_postcode` | 4 | 2 | 34 |
| 3 | `g7-plugin-sirsoft-gdpr-zh-CN` | `sirsoft-gdpr` | 7 | 5 | 464 |
| 4 | `g7-plugin-sirsoft-marketing-zh-CN` | `sirsoft-marketing` | 6 | 4 | 86 |
| 5 | `g7-plugin-sirsoft-message_bizppurio-zh-CN` | `sirsoft-message_bizppurio` | 9 | 7 | 409 |
| 6 | `g7-plugin-sirsoft-pay_kginicis-zh-CN` | `sirsoft-pay_kginicis` | 8 | 6 | 341 |
| 7 | `g7-plugin-sirsoft-pay_nhnkcp-zh-CN` | `sirsoft-pay_nhnkcp` | 8 | 6 | 163 |
| 8 | `g7-plugin-sirsoft-pay_nicepayments-zh-CN` | `sirsoft-pay_nicepayments` | 8 | 6 | 176 |
| 9 | `g7-plugin-sirsoft-tosspayments-zh-CN` | `sirsoft-tosspayments` | 7 | 5 | 112 |
| 10 | `g7-plugin-sirsoft-verification_kginicis-zh-CN` | `sirsoft-verification_kginicis` | 6 | 4 | 99 |
| 11 | `g7-plugin-sirsoft-verification_nhnkcp-zh-CN` | `sirsoft-verification_nhnkcp` | 6 | 4 | 112 |
| | **합계** | | **75** | **53** | **2,092** |

파일 내역: backend PHP 26 + frontend JSON 11 + seed JSON 16 + `language-pack.json` 11 + `CHANGELOG.md` 11.

---

## 4. manifest 계약 근거

프로덕션 `LanguagePackManifestValidator` 와 기존 ja 플러그인 팩 11종을 조사해 결정했다.

| 필드 | 값 | 근거 |
|---|---|---|
| `namespace` | `g7` | 모든 공식 번들 팩 공통 |
| `vendor` | `sirsoft` | 각 `plugin.json`·ja 팩과 일치 (검사기가 대조) |
| `scope` | `plugin` | ja 플러그인 팩 11종 전부 `plugin` |
| `target_identifier` | 실제 plugin identifier | `plugin.json` 의 `identifier` 와 일치 (검사기·PHPUnit 이 대조) |
| `license` | `MIT` | 각 `plugin.json`·ja 팩과 일치 |
| `g7_version` | `>=7.0.0` | ja 팩은 `>=7.0.0`(6종)과 `>=7.0.0-beta.4`(5종)가 혼재. **beta 표기는 레거시**이므로 기존 zh-CN 팩 4종(core/board/ecommerce/page)과 동일하게 `>=7.0.0` 으로 통일 |
| `target_version` | `null` | ja 팩 11종 전부 `null` |
| `depends_on_core_locale` | `true` | ja 팩 11종 전부 `true`. scope≠core 이므로 `LanguagePackService::resolveCoreLocaleBlockedReason()` 이 코어 zh-CN 팩 활성을 강제 |

네이밍 공식 `{namespace}-{scope}-{target}-{locale}`, BCP-47 패턴, `LanguagePackBundledRegistrar` 의
locale 디렉토리 스캔 패턴(`/^[a-z]{2,3}(-[A-Z]{2})?$/`)을 11종 전부 통과했다.

---

## 5. 정합성 검사 결과

### 5-1. 통합 실행

```
php tests/Translations/zh-CN-plugins-parity-check.php --style
```

```
ckeditor5                sirsoft-ckeditor5             PASS
daum-postcode            sirsoft-daum_postcode         PASS
gdpr                     sirsoft-gdpr                  PASS
marketing                sirsoft-marketing             PASS
message-bizppurio        sirsoft-message_bizppurio     PASS
pay-kginicis             sirsoft-pay_kginicis          PASS
pay-nhnkcp               sirsoft-pay_nhnkcp            PASS
pay-nicepayments         sirsoft-pay_nicepayments      PASS
tosspayments             sirsoft-tosspayments          PASS
verification-kginicis    sirsoft-verification_kginicis PASS
verification-nhnkcp      sirsoft-verification_nhnkcp   PASS

검사 팩 11개 · 통과 11개 · 실패 0개 · 총 위반 0건
RESULT: ALL PASS   (exit 0)
```

### 5-2. 검사 항목별 결과 (11팩 합산)

| 항목 | 결과 |
|---|---|
| 파일 누락 / 초과 파일 | 0 / 0 |
| 구조 오류 | 0 |
| JSON 구문 | 0 (JSON 38개 전부 파싱 성공) |
| PHP 배열 구조 | 0 (PHP 26개 전부 `<?php return [ … ];`) |
| ko 대비 키 누락 / 초과 키 | 0 / 0 |
| 키 순서 불일치 | 0 |
| 자료형 불일치 | 0 |
| **placeholder 불일치 (종류·개수)** | 0 |
| HTML 태그 불일치 | 0 |
| URL 불일치 | 0 (예외 1건 — §8-5) |
| 빈 값 불일치 | 0 |
| 개행 개수 불일치 | 0 |
| 한글 잔존 | **0** |
| 일본어 가나 잔존 | **0** |
| 인코딩 오류 / BOM | 0 / 0 |
| `$partial` 경로 오류 | 0 (플러그인 원본에 partial 없음) |
| manifest 계약 | 0 |
| 보안 규칙 | 0 |
| ja 팩 구조 차이 | 0 (의도적 차이는 §8 에 선언) |

기존 zh-CN 팩 4종(core/board/ecommerce/page) 검사기도 **전부 PASS** — 회귀 없음.

### 5-3. 표기 스타일 경고 (실패 아님, 13건)

| 유형 | 건수 | 내용 |
|---|---|---|
| ja 팩과 키 순서 다름 | 11 | `messages.php` 6 · `frontend/zh-CN.json` 3 · `result_codes.php` 1 · `payment_methods.php` 1 — zh 는 ko 순서를 따른다 |
| ja 팩에만 있는 키 | 1 | `pay_kginicis` 의 `admin.cash_receipt_*` 14키 (§8-1) |
| CJK 인접 ASCII 문장부호 | 1 | `message_bizppurio` 의 `request_comment_placeholder` — ko 원문이 `#{name}=…, #{order_number}=…` 형태의 코드 예시라 ASCII 쉼표를 유지 |

---

## 6. PHP / JSON / 인코딩 / 보안

```
files=75 (php=26 json=38 md=11)
bom=0 crlf=0 non-utf8=0 binary=0 large(>512KB)=0
bad-ext=0 bad-php-location=0 symlink=0 banned-calls=0 secret-like=0
bad-tokens=0 non-string-leaf=0
php leaves=625 (전부 string)
```

| 규칙 | 결과 |
|---|---|
| 허용 확장자 외 파일 | 0 (실제: `php` 26, `json` 38, `md` 11) |
| 심볼릭 링크 | 0 |
| PHP 위치 `#^backend/zh-CN/[A-Za-z0-9_-]+\.php$#` | 26/26 통과 |
| PHP 정적 배열 반환만 허용 | 통과 (토큰 화이트리스트 검사) |
| `eval`/`exec`/`shell_exec`/`system`/`passthru`/`proc_open`/`popen` | 0 |
| `include`/`require` 및 동적 코드 로딩(`call_user_func`, `create_function`) | 0 |
| 네트워크 접근(`curl_init`, `file_get_contents`) | 0 |
| 파일 쓰기(`file_put_contents`, `fopen`, `unlink`) | 0 |
| `getenv`/`putenv` 등 환경변수 접근 | 0 |
| `.env` 포함 | 0 |
| API key / password / token / SMTP 자격증명 | 0 |
| 실제 사용자 이메일·운영 비밀정보 | 0 |
| 바이너리·대용량 파일 | 0 |
| `php -l` | 26/26 통과 |

> 참고: 결제·인증 플러그인의 `test_secret_key`·`live_api_key` 같은 문자열은 **설정 화면의 라벨 키**이지 값이 아니다. 검사기의 비밀정보 패턴은 "키 이름 + 16자 이상 리터럴 값" 형태만 위반으로 보므로 오탐하지 않는다.

---

## 7. seed 검증

| 대상 | seed 파일 | 원본 |
|---|---|---|
| ckeditor5 | `manifest`, `permissions` | `plugin.json`, `plugin.php::getPermissions()` (4항목) |
| daum_postcode | `manifest` | `plugin.json` |
| gdpr | `manifest`, `permissions`, `roles` | `plugin.json`, `getPermissions()` (4항목), `getRoles()` (1종) |
| marketing | `manifest` | `plugin.json` |
| message_bizppurio | `manifest`, `permissions`, `notifications` | `plugin.json`, `getPermissions()` (4항목), `getNotificationDefinitions()` (`bizppurio_balance_low`) |
| pay_* (3종), tosspayments, verification_* (2종) | `manifest` | `plugin.json` |

ja 팩과 **키 집합·순서 모두 일치**(위반 0). seed 예외 선언(`seed_extras`/`seed_missing`)은 11종 전부 비어 있다.

---

## 8. ja 팩과의 의도적 차이

| # | 대상 | 차이 | 조사 | 처리 |
|---|---|---|---|---|
| 1 | `sirsoft-pay_kginicis` | ja `frontend/ja.json` 에 `admin.cash_receipt_*` **14키**가 있으나 ko 원본에 없음 | KG 전용 현금영수증 패널이 이커머스 모듈의 공용 프로바이더 축으로 이관·제거됨. `EnsureAdminOrderDetailPaymentQueryLayoutListenerTest` 가 `kginicis_cash_receipt_panel` 노드 수 0 을 단언하고, 리스너의 `admin.cash_receipt_title` 참조는 **낡은 패널을 걷어내기 위한 시그니처 탐지**일 뿐 렌더링 키가 아님 | zh-CN 에서 **제외**. `--style` 정보성 경고로 표면화 |
| 2 | `sirsoft-daum_postcode` | ja 팩에 `CHANGELOG.md` 없음 (3파일) | 다른 ja 팩 10종은 모두 보유 — ja 쪽 누락 | zh-CN 은 **포함**(4파일). 허용 확장자이며 릴리즈 이력 추적에 필요 |
| 3 | ckeditor5 · pay_kginicis(×2) · pay_nhnkcp · pay_nicepayments · tosspayments · message_bizppurio · verification_kginicis(×2) · verification_nhnkcp | ja 가 ko 대비 **키 순서**가 다름 (집합은 동일, 11곳) | ko 원본이 단일 출처 | zh-CN 은 **ko 순서**를 따름. `--style` 정보성 경고 |
| 4 | `sirsoft-pay_nicepayments` | ko `payment_methods.php` 의 한글 브랜드명에 zero-width space(`U+200B`) 삽입 (`'네이' . "\u{200B}" . '버페이'`) | ZWSP 는 **한글 문자열을 끊기 위한 장치**다. zh-CN 은 라틴 브랜드명(`Naver Pay`)을 쓰므로 그 목적이 사라진다. ja 팩도 동일하게 ZWSP 없이 라틴 표기를 썼다 | zh-CN 도 **넣지 않음** |
| 5 | `sirsoft-tosspayments` | `settings.webhook_url_hint` 의 ko 원문에 `https://내도메인` 이라는 **의사(擬似) URL** | 실제 링크가 아니라 "여기에 당신의 도메인을 적어라"는 안내. 한글을 남길 수 없어 `https://您的域名` 으로 옮기면 URL 토큰이 달라진다 | 해당 **1개 경로만** `url_exempt` 로 면제하고 나머지 URL 은 계속 엄격 대조 |

**ja 팩 자체는 이번 작업에서 수정하지 않았다.**

---

## 9. 번역 판단표

| 한국어 원문 | 선택한 중국어 | 대안 | 선택 이유 | 기존 zh-CN 팩과의 일관성 |
|---|---|---|---|---|
| 알림톡 | `Alimtalk` | `Kakao 通知消息`, `提醒톡` 음역 | KakaoTalk 의 비즈니스 메시지 **서비스 브랜드명**(공식 영문 표기 AlimTalk). 의역하면 일반명사와 구분이 사라지고, enum 라벨로 쓰기에 길다 | 신규 (충돌 없음) |
| 에스크로 | `担保交易` | `第三方担保`, `托管交易` | 중국 전자상거래에서 통용되는 표기. 결제 3종에서 동일 적용 | 신규, 3팩 동일 |
| 본인확인 / 본인인증 | 둘 다 `实名认证` | 확인=`身份确认` / 인증=`实名认证` 로 분리 | ko 원본이 같은 기능을 두 표기로 부른다(플러그인마다 다름). 하나로 통일해야 화면이 일관된다 | **일치** (이커머스 팩 `实名认证`) |
| 무통장(입금) | `银行汇款` | `线下转账` | 이커머스 팩에서 확정한 표기 승계 | **일치** |
| 가상계좌 / 계좌이체 | `虚拟账户` / `账户转账` | — | 이커머스 팩과 동일 | **일치** |
| 공급가액 / 부가세 | `未税金额` / `增值税` | `供货价` / `VAT` | 이커머스 팩에서 확정 | **일치** |
| 현금영수증 (소득공제용/지출증빙용) | `现金收据`（`用于个人所得税抵扣`／`用于支出凭证`） | 중국 `发票` 로 치환 | 한국 고유 제도 — 중국 제도와 동일시하지 않는다 | **일치** (이커머스 팩) |
| 도로명 주소 / 지번 주소 | `街道名地址` / `地番地址` | `路名地址` / `地号地址` | 한국 주소 체계 고유 개념. 중국 주소 기능처럼 오역하지 않도록 원 개념을 그대로 옮김 | 신규 |
| 쿠키 | `Cookie` | `曲奇` | 기술 용어이므로 원문 유지 | 신규 |
| 개인정보처리방침 | `隐私政策` | `个人信息处理方针` | 중국 웹 표준 표기 | 신규 |
| 필수 동의 / 선택 동의 | `必需` / `可选` | `必须同意` / `可选同意` | GDPR 카테고리 **배지**라 짧아야 한다. 문장 문맥에서는 `必须同意`/`可选` 로 풀어 씀 | 신규 |
| 마케팅 동의 vs 서비스 필수 알림 | `营销同意` vs `必需 Cookie`·`服务必需` | — | 사용자 요구대로 구분 유지. 마케팅 동의는 `（可选）` 접두를 원문대로 보존 | 신규 |
| GDPR 법률 문구 (Art.6/Art.13) | 원문 의미 그대로 + `GDPR Art.6` 유지 | 중국 개인정보보호법(PIPL) 용어로 치환 | **EU 규정**의 의미를 임의로 중국 법률로 바꾸지 않는다 | 신규 |
| 채널 (마케팅 동의 항목) | `渠道` | `频道` | 이커머스 알림 채널과 동일 표기 | **일치** |
| 검수 (알림톡 템플릿) | `审核` | `检查` | 카카오 템플릿 심사 절차 | 신규 |
| 휴면 (템플릿 상태) | `休眠` | `停用` | `stopped`(`已停止`)와 구분해야 함 | 신규 |
| 상점관리자 / 가맹점 어드민 | `商户管理后台` | `店铺管理员` | PG 콘솔을 가리키므로 "관리자(사람)"와 구분 | 신규 |
| 원승인 (KCP/이니시스) | `原授权` | `初始批准` | 결제 승인 원거래를 가리킴 | 신규 |
| 페이코 / 애플페이 / 삼성페이 등 | `PAYCO` / `Apple Pay` / `Samsung Pay` | 음역 | 브랜드명 — 번역 금지 규칙 적용 | 신규 |
| 한국어 / English (라벨) | `韩语` / `英语` | 자기 언어 표기 유지 | 한글을 남길 수 없고 혼합 표기는 일관성이 깨진다 | **일치** (페이지·이커머스 팩) |
| `PAYCO (페이코)` | `PAYCO` | `PAYCO（PAYCO）` | 괄호 안이 같은 브랜드의 한글 음역이라 중국어에서는 중복 | 신규 |

---

## 10. frontend build 필요 여부

**불필요.** 코드 근거:

1. 언어팩 frontend JSON 은 `app/Listeners/LanguagePack/MergeFrontendLanguage.php` 가 **런타임에 디스크에서 읽어** 병합한다(`File::get()`, `frontend/partial/*.json` 직접 로드 + 루트 `{locale}.json` 의 `$partial` 해석).
2. `plugin.json` 에 `assets.js` 를 선언한 플러그인(예: `sirsoft-ckeditor5` — `entry: resources/js/index.ts`, `output: dist/js/plugin.iife.js`)도 그 진입점이 `resources/lang/**` 를 import 하지 않는다. 언어 자원은 빌드 그래프 밖이다.
3. 이번 작업은 `plugins/` 트리를 전혀 건드리지 않았으므로 기존 `dist/` 산출물이 바뀔 이유가 없다.

`npm install` / `npm run build` 는 실행하지 않았다.

---

## 11. 검증 도구

| 파일 | 성격 | 실행 |
|---|---|---|
| `tests/Translations/lib/zh-CN-plugin-parity-lib.php` | 공용 검사 라이브러리 (vendor·Laravel 불필요, PHP 7.x 폴리필 포함) | — |
| `tests/Translations/zh-CN-<short>-parity-check.php` × 11 | 플러그인별 진입점 (대상·의도적 차이만 선언) | **전부 실행 — 각 PASS** |
| `tests/Translations/zh-CN-plugins-parity-check.php` | 통합 진입점 (개별 PASS/FAIL + 전체 위반 건수, 실패 시 exit 1) | **실행 — ALL PASS (exit 0)** |
| `tests/Unit/Services/LanguagePack/BundledSimplifiedChinesePluginPacksTest.php` | PHPUnit 데이터셋 테스트 (팩 식별자별 독립 실패 식별) | **로컬 실행 불가** — §12 |

플러그인 11종이 같은 팩 레이아웃을 쓰므로 검사 로직을 한 파일에 모았다. 진입점을 복사했다면
규칙이 갈라져 한 팩만 다르게 검사되는 사각이 생긴다. 통합 진입점은 개별 진입점을 서브프로세스로
실행하므로 "통합에서는 통과, 개별에서는 실패" 하는 괴리가 없다.

PHPUnit 테스트 검증 항목: 프로덕션 manifest validator 실호출 · 프로덕션 PHP array validator 실호출 ·
번들 팩 탐색 · identifier/locale/scope/target/version · vendor·license ↔ `plugin.json` ·
파일 구조 · ko 대비 키 셋·순서 parity · placeholder 종류·개수 · seed ↔ ja 대칭 ·
한글·가나 잔존 0 · 보안 규칙 · `depends_on_core_locale` 설치 의존성 계약.

---

## 12. 실행하지 못한 테스트와 이유

| 항목 | 사유 | 서버에서 실행할 명령 |
|---|---|---|
| PHPUnit `BundledSimplifiedChinesePluginPacksTest` | 로컬 PHP **7.4.22** 이며 `composer.json` 은 `^8.2` 요구. `vendor/` 디렉토리는 존재하나 **비어 있음**(`autoload.php` 없음) → Laravel 부팅 불가 | `php artisan test --filter=BundledSimplifiedChinesePluginPacksTest` |
| 프로덕션 `LanguagePackManifestValidator` 실호출 | 위와 동일 (규칙만 검사기로 재현) | 위 PHPUnit 에 포함 |
| 프로덕션 `LanguagePackPhpArrayValidator` 실호출 | 위와 동일. 해당 클래스는 PHP 8 의 `catch (ParseError)` 를 사용해 7.4 에서 로드 불가 (토큰 화이트리스트로 근사) | 위 PHPUnit 에 포함 |
| 실제 설치·활성화 후 화면 확인 | DB 변경 금지 · 서버 접속 금지 범위 | §14 설치 명령 후 관리자·사용자 화면 확인 |

로컬에서 가능한 정적 검사(파서·인코딩·키·순서·placeholder·manifest·보안 규칙 재현)는 전부
통과했으나, **위 4건은 성공으로 간주하지 않는다.**

---

## 13. 변경 파일

### 신규 (89개 파일)

- `lang-packs/_bundled/g7-plugin-sirsoft-{ckeditor5,daum_postcode,gdpr,marketing,message_bizppurio,pay_kginicis,pay_nhnkcp,pay_nicepayments,tosspayments,verification_kginicis,verification_nhnkcp}-zh-CN/` — **75개 파일**
- `tests/Translations/lib/zh-CN-plugin-parity-lib.php` — 1
- `tests/Translations/zh-CN-<short>-parity-check.php` — 11
- `tests/Translations/zh-CN-plugins-parity-check.php` — 1
- `tests/Unit/Services/LanguagePack/BundledSimplifiedChinesePluginPacksTest.php` — 1
- `docs/reports/zh-CN-plugin-language-packs-report.md` (이 문서) — 1

### 수정 (4개 파일, 허용 범위 내)

| 파일 | 변경 |
|---|---|
| `README.md` | 번들 언어팩 인벤토리에 zh-CN 11행 추가 (삭제 0) |
| `README.ko.md` | 동일 (삭제 0) |
| `CHANGELOG.md` | `[Unreleased] > Added` 에 1행 추가 |
| `docs/extension/language-packs.md` | 패키지 구성 표에 11행 + 「플러그인 팩」 절 신설 + 동기화 의무 절 갱신 |

### 삭제 / 이름 변경

**0건.**

### 변경하지 않은 것

코어, 모듈, **플러그인(대상 11종 포함)**, 템플릿, 기존 언어팩(ja 12종 포함), 기존 zh-CN 팩 4종과
그 검사기·보고서, DB 스키마·마이그레이션, 라우트, 권한 키, 설정 키,
`package.json`/`composer.json`/lock 파일, `config/app.php`, `.env`.

---

## 14. 예상하지 못한 변경 / 감사 결과

**없음.**

| 감사 항목 | 결과 |
|---|---|
| `git diff --check` | 통과 (공백 오류·충돌 마커 0) |
| 삭제 파일 | 0 |
| 이름 변경 | 0 |
| 허용 범위 밖 변경 | 0 |
| package/composer/lock 변경 | 0 |
| 의존성 설치·업데이트 | 미실행 |
| frontend build | 미실행 |
| 비밀정보 | 0 |
| 바이너리·대용량 파일 | 0 |
| UTF-8 without BOM / LF | 75/75 |
| 기존 zh-CN 팩 4종 검사기 회귀 | 전부 PASS |

문서 diff 의 삭제 5줄은 모두 제자리 재작성(문장 갱신)이며, 기존 코어·게시판·이커머스·페이지 팩
설명은 그대로 보존했다.

### `git status --short`

```
 M CHANGELOG.md
 M README.ko.md
 M README.md
 M docs/extension/language-packs.md
?? docs/reports/zh-CN-plugin-language-packs-report.md
?? lang-packs/_bundled/g7-plugin-sirsoft-ckeditor5-zh-CN/
?? lang-packs/_bundled/g7-plugin-sirsoft-daum_postcode-zh-CN/
?? lang-packs/_bundled/g7-plugin-sirsoft-gdpr-zh-CN/
?? lang-packs/_bundled/g7-plugin-sirsoft-marketing-zh-CN/
?? lang-packs/_bundled/g7-plugin-sirsoft-message_bizppurio-zh-CN/
?? lang-packs/_bundled/g7-plugin-sirsoft-pay_kginicis-zh-CN/
?? lang-packs/_bundled/g7-plugin-sirsoft-pay_nhnkcp-zh-CN/
?? lang-packs/_bundled/g7-plugin-sirsoft-pay_nicepayments-zh-CN/
?? lang-packs/_bundled/g7-plugin-sirsoft-tosspayments-zh-CN/
?? lang-packs/_bundled/g7-plugin-sirsoft-verification_kginicis-zh-CN/
?? lang-packs/_bundled/g7-plugin-sirsoft-verification_nhnkcp-zh-CN/
?? tests/Translations/lib/
?? tests/Translations/zh-CN-ckeditor5-parity-check.php
?? tests/Translations/zh-CN-daum-postcode-parity-check.php
?? tests/Translations/zh-CN-gdpr-parity-check.php
?? tests/Translations/zh-CN-marketing-parity-check.php
?? tests/Translations/zh-CN-message-bizppurio-parity-check.php
?? tests/Translations/zh-CN-pay-kginicis-parity-check.php
?? tests/Translations/zh-CN-pay-nhnkcp-parity-check.php
?? tests/Translations/zh-CN-pay-nicepayments-parity-check.php
?? tests/Translations/zh-CN-plugins-parity-check.php
?? tests/Translations/zh-CN-tosspayments-parity-check.php
?? tests/Translations/zh-CN-verification-kginicis-parity-check.php
?? tests/Translations/zh-CN-verification-nhnkcp-parity-check.php
?? tests/Unit/Services/LanguagePack/BundledSimplifiedChinesePluginPacksTest.php
```

### `git diff --stat`

```
 CHANGELOG.md                     |   1 +
 README.ko.md                     |  11 ++++
 README.md                        |  11 ++++
 docs/extension/language-packs.md | 136 +++++++++++++++++++++++++++++++++++++--
 4 files changed, 154 insertions(+), 5 deletions(-)
```

### GitHub Desktop 커밋 가능 여부

가능하다. 위 목록 전부(추적 4 + 미추적 30 엔트리 / 실제 89개 파일)를 스테이징하면 된다.
`lang-packs/{identifier}/`(설치본)와 `_pending/`은 gitignore 대상이라 포함되지 않았다.

### 권장 commit 제목

```
feat(lang-packs): 번들 플러그인 11종 중국어 간체(zh-CN) 언어팩 추가
```

---

## 15. 서버 검증·백업·설치·롤백

### 검증

```bash
# 통합 (11종 개별 PASS/FAIL, 하나라도 실패하면 exit 1)
php tests/Translations/zh-CN-plugins-parity-check.php --style

# 개별
php tests/Translations/zh-CN-gdpr-parity-check.php --verbose --style

# 기존 zh-CN 팩 회귀
php tests/Translations/zh-CN-core-parity-check.php
php tests/Translations/zh-CN-board-parity-check.php
php tests/Translations/zh-CN-ecommerce-parity-check.php
php tests/Translations/zh-CN-page-parity-check.php

# PHPUnit
php artisan test --filter=BundledSimplifiedChinesePluginPacksTest
```

### 백업 (설치 전)

```bash
mysqldump -u <user> -p <db> language_packs > backup_language_packs_$(date +%F).sql
mysqldump -u <user> -p <db> permissions roles notifications > backup_seed_targets_$(date +%F).sql
tar czf backup_langpacks_$(date +%F).tgz lang-packs/
```

### 설치 순서

플러그인 팩끼리는 서로 의존하지 않으므로 3단계 내부 순서는 자유다. **코어 zh-CN 팩이 먼저** 활성이어야 한다.

```bash
# 1) 전제: 코어 zh-CN 팩 활성
php artisan language-pack:list --scope=core
php artisan language-pack:install g7-core-zh-CN --source=bundled     # 미설치인 경우만

# 2) 전제: 대상 플러그인이 active (아니면 target_inactive 로 차단)
php artisan plugin:list

# 3) 플러그인 팩 설치 (자동 활성)
for p in ckeditor5 daum_postcode gdpr marketing message_bizppurio \
         pay_kginicis pay_nhnkcp pay_nicepayments tosspayments \
         verification_kginicis verification_nhnkcp; do
  php artisan language-pack:install "g7-plugin-sirsoft-$p-zh-CN" --source=bundled
done

# 4) 확인
php artisan language-pack:list --scope=plugin

# 콘텐츠 수정 후 재반영 (frontend build 불필요)
php artisan language-pack:update g7-plugin-sirsoft-gdpr-zh-CN --force
```

### 롤백

```bash
# 개별 팩 비활성화 (번역만 기준 로케일로 되돌아감 — 데이터 손실 없음)
php artisan language-pack:disable g7-plugin-sirsoft-gdpr-zh-CN

# 완전 제거
php artisan language-pack:uninstall g7-plugin-sirsoft-gdpr-zh-CN

# 전체 롤백 (설치 전 상태로)
for p in ckeditor5 daum_postcode gdpr marketing message_bizppurio \
         pay_kginicis pay_nhnkcp pay_nicepayments tosspayments \
         verification_kginicis verification_nhnkcp; do
  php artisan language-pack:uninstall "g7-plugin-sirsoft-$p-zh-CN"
done

# seed 가 주입된 permissions/roles/notifications 의 zh-CN 행까지 되돌려야 하면 백업 복원
mysql -u <user> -p <db> < backup_seed_targets_<date>.sql

# 코드 롤백 (커밋 전이면 신규 파일 삭제 + 문서 4종 checkout)
git checkout -- README.md README.ko.md CHANGELOG.md docs/extension/language-packs.md
rm -rf lang-packs/_bundled/g7-plugin-sirsoft-*-zh-CN
rm -rf tests/Translations/lib tests/Translations/zh-CN-{ckeditor5,daum-postcode,gdpr,marketing,message-bizppurio,pay-kginicis,pay-nhnkcp,pay-nicepayments,tosspayments,verification-kginicis,verification-nhnkcp,plugins}-parity-check.php
rm -f tests/Unit/Services/LanguagePack/BundledSimplifiedChinesePluginPacksTest.php
```

> 언어팩 비활성화는 번역 레이어만 걷어낼 뿐 운영자가 수정한 라벨을 지우지 않는다
> (코어가 sub-key 단위로 보존). 다만 seed 로 주입된 zh-CN 표시명은 DB 행이므로,
> 원상 복구가 필요하면 위 백업을 복원한다.

---

## 16. 후속 과제

1. **ja 팩 정리** — §8 의 5건 중 4건은 ja 팩이 ko 원본보다 낡거나(키 순서·`pay_kginicis` 잔여 키) 파일이 빠진(`daum_postcode` CHANGELOG) 결과다. ja 팩을 동기화하면 검사기의 `url_exempt` 를 제외한 정보성 경고가 사라진다.
2. **템플릿 zh-CN 팩** — `g7-template-sirsoft-{basic,admin_basic}` 는 아직 ja 팩만 있다.
3. **ko/en 변경 시 동기화** — 플러그인의 다국어 키를 바꿀 때마다 대응 zh-CN 팩도 갱신하고 `zh-CN-plugins-parity-check.php` 로 확인해야 한다. 누락되면 중국어 화면에서 오류 없이 ko/en 폴백이 노출된다.

---

## 17. 보고서 클립보드 복사

```powershell
Get-Content -Raw -Encoding UTF8 "docs\reports\zh-CN-plugin-language-packs-report.md" | Set-Clipboard
```
