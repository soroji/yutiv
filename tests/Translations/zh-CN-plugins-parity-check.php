<?php

/**
 * 플러그인 zh-CN 언어팩 11종 통합 정합성 검사기 (standalone).
 *
 * 플러그인별 진입점(`zh-CN-<short>-parity-check.php`)을 하나씩 돌려 개별 PASS/FAIL 과
 * 전체 위반 건수를 한 번에 보여준다. 하나라도 실패하면 non-zero 로 종료한다.
 *
 * 개별 진입점을 그대로 서브프로세스로 실행하므로 "여기서는 통과하는데 개별 실행에서는
 * 실패" 하는 괴리가 생기지 않는다 — 검사 대상·예외 선언은 각 진입점이 단일 출처다.
 *
 * 실행:
 *   php tests/Translations/zh-CN-plugins-parity-check.php [--verbose] [--style]
 *
 * 종료 코드: 0 = 전부 통과, 1 = 하나 이상 위반.
 */
declare(strict_types=1);

/**
 * 통합 실행 대상 — 진입점 short name ⇒ 대상 플러그인 식별자.
 *
 * 제외 대상은 여기에 넣지 않는다.
 *  - `gnuboard7-hello_plugin`: 학습용 샘플 확장(README "학습용 샘플 확장" 표)이라
 *    공식 언어팩 인벤토리에 없다. ja 팩도 인벤토리에 등재되어 있지 않다.
 *
 * @var array<string, string>
 */
$targets = [
    'ckeditor5' => 'sirsoft-ckeditor5',
    'daum-postcode' => 'sirsoft-daum_postcode',
    'gdpr' => 'sirsoft-gdpr',
    'marketing' => 'sirsoft-marketing',
    'message-bizppurio' => 'sirsoft-message_bizppurio',
    'pay-kginicis' => 'sirsoft-pay_kginicis',
    'pay-nhnkcp' => 'sirsoft-pay_nhnkcp',
    'pay-nicepayments' => 'sirsoft-pay_nicepayments',
    'tosspayments' => 'sirsoft-tosspayments',
    'verification-kginicis' => 'sirsoft-verification_kginicis',
    'verification-nhnkcp' => 'sirsoft-verification_nhnkcp',
];

$verbose = in_array('--verbose', $argv, true);
$styleMode = in_array('--style', $argv, true);
$passthru = array_values(array_filter([$verbose ? '--verbose' : null, $styleMode ? '--style' : null]));

$php = PHP_BINARY;
$failed = [];
$results = [];

echo "=== zh-CN 플러그인 언어팩 통합 정합성 검사 (".count($targets)."종) ===\n\n";

foreach ($targets as $short => $target) {
    $script = __DIR__.DIRECTORY_SEPARATOR."zh-CN-$short-parity-check.php";
    if (! is_file($script)) {
        $results[$short] = ['status' => 'MISSING', 'violations' => -1, 'output' => "진입점 없음: $script"];
        $failed[] = $short;

        continue;
    }

    $cmd = escapeshellarg($php).' '.escapeshellarg($script);
    foreach ($passthru as $flag) {
        $cmd .= ' '.escapeshellarg($flag);
    }

    $output = [];
    $exit = 0;
    exec($cmd.' 2>&1', $output, $exit);
    $text = implode("\n", $output);

    $violations = 0;
    if (preg_match('/RESULT: FAIL — 총 (\d+) 건/u', $text, $m)) {
        $violations = (int) $m[1];
    }

    $results[$short] = [
        'status' => $exit === 0 ? 'PASS' : 'FAIL',
        'violations' => $violations,
        'output' => $text,
    ];
    if ($exit !== 0) {
        $failed[] = $short;
    }

    printf("%-24s %-24s %s%s\n", $short, $target,
        $exit === 0 ? 'PASS' : 'FAIL',
        $exit === 0 ? '' : " ($violations 건)");
}

$totalViolations = 0;
foreach ($results as $r) {
    $totalViolations += max(0, $r['violations']);
}

if ($failed !== []) {
    echo "\n--- 실패한 팩 상세 ---\n";
    foreach ($failed as $short) {
        echo "\n### $short\n".$results[$short]['output']."\n";
    }
} elseif ($verbose || $styleMode) {
    echo "\n--- 개별 출력 ---\n";
    foreach ($results as $short => $r) {
        echo "\n### $short\n".$r['output']."\n";
    }
}

printf("\n검사 팩 %d개 · 통과 %d개 · 실패 %d개 · 총 위반 %d건\n",
    count($targets), count($targets) - count($failed), count($failed), $totalViolations);
echo $failed === [] ? "RESULT: ALL PASS\n" : 'RESULT: FAIL — '.implode(', ', $failed)."\n";

exit($failed === [] ? 0 : 1);
