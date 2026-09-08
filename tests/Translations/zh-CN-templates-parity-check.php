<?php

/**
 * 템플릿 zh-CN 언어팩 2종 정합성 검사기 (standalone).
 *
 * 대상
 * ----
 *   - `g7-template-sirsoft-admin_basic-zh-CN` (관리자 템플릿)
 *   - `g7-template-sirsoft-basic-zh-CN`       (사용자 템플릿)
 *
 * 두 팩을 각각 검사해 개별 PASS/FAIL 을 출력하고, 하나라도 위반이 있으면 exit 1 로 끝난다.
 * 검사 로직은 `lib/zh-CN-template-parity-lib.php` 에 있고, 그 라이브러리는 플러그인 팩
 * 검사기의 순수 헬퍼를 재사용한다 — 규칙을 복사하지 않기 위해서다.
 *
 * Laravel 부팅도 Composer autoload 도 필요하지 않아 vendor/ 가 없는 환경에서도 실행된다.
 *
 * 실행:
 *   php tests/Translations/zh-CN-templates-parity-check.php [--verbose] [--style]
 *
 * 종료 코드: 0 = 전부 통과, 1 = 하나 이상 위반.
 */
declare(strict_types=1);

require_once __DIR__.'/lib/zh-CN-template-parity-lib.php';

/**
 * 검사 대상 — 대상 템플릿 식별자 ⇒ ja 팩 대비 의도적 차이 선언.
 *
 * 두 템플릿 모두 ko 원본과 ja 팩의 **키 집합·파일 구성이 완전히 일치**한다.
 * 다른 것은 일부 partial 의 **키 순서**뿐이며(ja 가 ko 대비 재정렬됨), zh 는 ko 순서를
 * 따르므로 실패가 아니라 `--style` 정보성 경고로만 표면화한다.
 *
 * 학습용 샘플 템플릿(`gnuboard7-hello_admin_template`, `gnuboard7-hello_user_template`)은
 * 운영 서버의 설치·활성 템플릿이 아니고 공식 언어팩 인벤토리에도 없어 대상에서 제외했다.
 *
 * @var array<string, array<string, mixed>>
 */
$targets = [
    'sirsoft-admin_basic' => [
        'seed_extras' => [],
        'seed_missing' => [],
        'ja_only_partials' => [],
        'url_exempt' => [],
    ],
    'sirsoft-basic' => [
        'seed_extras' => [],
        'seed_missing' => [],
        'ja_only_partials' => [],
        'url_exempt' => [],
    ],
];

$root = dirname(__DIR__, 2);
$verbose = in_array('--verbose', $argv, true);
$styleMode = in_array('--style', $argv, true);

$failed = [];
$totalViolations = 0;
$totalFiles = 0;
$totalKeys = 0;

echo "=== zh-CN 템플릿 언어팩 정합성 검사 (".count($targets)."종) ===\n\n";

foreach ($targets as $target => $intentional) {
    $report = zhcnTemplateParityCheck($root, $target, $intentional, $styleMode);

    $packId = "g7-template-$target-zh-CN";
    echo "=== $packId parity check ===\n";
    printf("검사 파일 %d개 (JSON %d), 대조 키 %d개\n\n",
        $report->stats['files'], $report->stats['json_files'], $report->stats['keys']);

    foreach (zhcnPluginCategories() as $key => $label) {
        $list = $report->violations[$key] ?? [];
        $count = count($list);
        printf("%-20s %s (%d)\n", $label, $count === 0 ? 'OK' : 'FAIL', $count);
        if ($count === 0) {
            continue;
        }
        $shown = $verbose ? $list : array_slice($list, 0, 20);
        foreach ($shown as $msg) {
            echo "    - $msg\n";
        }
        if (! $verbose && $count > 20) {
            printf("    ... 외 %d건 (--verbose 로 전체 출력)\n", $count - 20);
        }
    }

    if ($styleMode) {
        echo "\n--- 표기 스타일 경고 (실패 아님) ---\n";
        if ($report->styleWarnings === []) {
            echo "없음\n";
        } else {
            foreach ($report->styleWarnings as $w) {
                echo "    ~ $w\n";
            }
            printf("    총 %d건\n", count($report->styleWarnings));
        }
    }

    $violations = $report->total();
    $totalViolations += $violations;
    $totalFiles += $report->stats['files'];
    $totalKeys += $report->stats['keys'];
    if ($violations > 0) {
        $failed[] = $target;
    }

    echo "\nRESULT: ".($violations === 0 ? 'PASS — 위반 0건' : "FAIL — 총 $violations 건")."\n\n";
}

printf("검사 팩 %d개 · 통과 %d개 · 실패 %d개 · 파일 %d개 · 대조 키 %d개 · 총 위반 %d건\n",
    count($targets), count($targets) - count($failed), count($failed), $totalFiles, $totalKeys, $totalViolations);
echo $failed === [] ? "RESULT: ALL PASS\n" : 'RESULT: FAIL — '.implode(', ', $failed)."\n";

exit($failed === [] ? 0 : 1);
