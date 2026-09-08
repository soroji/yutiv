<?php

/**
 * g7-plugin-sirsoft-pay_nicepayments-zh-CN 언어팩 정합성 검사기 진입점 (standalone).
 *
 * 검사 로직은 `lib/zh-CN-plugin-parity-lib.php` 에 있다 — 플러그인 11종이 같은 팩
 * 레이아웃을 쓰므로 규칙이 갈라지지 않도록 한 곳에 모았다. 이 파일은 대상 플러그인과
 * ja 팩 대비 의도적 차이만 선언한다.
 *
 * 실행:
 *   php tests/Translations/zh-CN-pay-nicepayments-parity-check.php [--verbose] [--style]
 *
 * 종료 코드: 0 = 통과, 1 = 위반 발견.
 */
declare(strict_types=1);

require_once __DIR__.'/lib/zh-CN-plugin-parity-lib.php';

/**
 * ja 팩 대비 의도적 차이 선언.
 *
 * 현재 이 팩에는 seed 차이가 없다. ko 원본이 바뀌었는데 ja 가 따라오지 못한 자리가
 * 생기면 여기에 dot-path 를 명시한다 — 목록에 없는 차이는 실패로 보고된다.
 *
 * @var array<string, mixed>
 */
$intentional = [
    'seed_extras' => [],
    'seed_missing' => [],
    'ja_only_files' => [],
];

exit(zhcnRunPluginEntrypoint('sirsoft-pay_nicepayments', $intentional, $argv));
