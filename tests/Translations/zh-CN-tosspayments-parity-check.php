<?php

/**
 * g7-plugin-sirsoft-tosspayments-zh-CN 언어팩 정합성 검사기 진입점 (standalone).
 *
 * 검사 로직은 `lib/zh-CN-plugin-parity-lib.php` 에 있다 — 플러그인 11종이 같은 팩
 * 레이아웃을 쓰므로 규칙이 갈라지지 않도록 한 곳에 모았다. 이 파일은 대상 플러그인과
 * ja 팩 대비 의도적 차이만 선언한다.
 *
 * 실행:
 *   php tests/Translations/zh-CN-tosspayments-parity-check.php [--verbose] [--style]
 *
 * 종료 코드: 0 = 통과, 1 = 위반 발견.
 */
declare(strict_types=1);

require_once __DIR__.'/lib/zh-CN-plugin-parity-lib.php';

/**
 * ja 팩 대비 의도적 차이 선언.
 *
 * seed 차이는 없다. ko 원본이 바뀌었는데 ja 가 따라오지 못한 자리가 생기면 여기에
 * dot-path 를 명시한다 — 목록에 없는 차이는 실패로 보고된다.
 *
 * `url_exempt` 는 URL 대조 예외다. `settings.webhook_url_hint` 의 ko 원문은
 * `https://내도메인` 이라는 **예시용 의사(擬似) URL** 로, 실제 링크가 아니라
 * "여기에 당신의 도메인을 적어라" 는 안내다. 한글을 남길 수 없으므로 중국어
 * (`https://您的域名`)로 옮겼고, 그 결과 URL 토큰이 달라진다. 실제 링크가 아니므로
 * 깨질 대상이 없다 — 이 한 곳만 면제하고 나머지 URL 은 계속 엄격히 대조한다.
 *
 * @var array<string, mixed>
 */
$intentional = [
    'seed_extras' => [],
    'seed_missing' => [],
    'ja_only_files' => [],
    'url_exempt' => [
        'frontend/zh-CN.json :: settings.webhook_url_hint',
    ],
];

exit(zhcnRunPluginEntrypoint('sirsoft-tosspayments', $intentional, $argv));
