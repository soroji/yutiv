<?php

/**
 * g7-module-sirsoft-board-zh-CN 언어팩 ↔ 게시판 모듈 한국어 원본 정합성 검사기 (standalone).
 *
 * 용도
 * ----
 * `lang-packs/_bundled/g7-module-sirsoft-board-zh-CN/` 의 번역 산출물이 게시판 모듈의
 * 한국어 원본(`modules/_bundled/sirsoft-board/src/lang/ko/`,
 * `modules/_bundled/sirsoft-board/resources/lang/ko.json`,
 * `.../resources/lang/partial/ko/`)과 구조적으로 1:1 대응하는지 검사한다.
 * Laravel 부팅도 Composer autoload 도 필요하지 않아 vendor/ 가 없는 환경에서도
 * `php tests/Translations/zh-CN-board-parity-check.php` 로 단독 실행된다.
 *
 * 코어 팩 검사기(`zh-CN-core-parity-check.php`)와 같은 계약을 모듈 스코프에 적용한다.
 * 차이는 원본 경로(모듈 트리)와 seed 대조 기준(ja 팩)뿐이다.
 *
 * 검사 항목
 * --------
 *  1. 파일 인벤토리    — 원본 대비 파일 1:1 대응 (누락/초과)
 *  2. 키 누락 / 초과   — dot-path 집합 비교
 *  3. 키 순서          — 동일 깊이에서의 키 나열 순서 일치
 *  4. 자료형           — array/string/int/bool/null 일치
 *  5. placeholder      — :attr, {var}, {{var}}, %s/%d 집합 일치
 *  6. HTML 태그        — 태그명 + 속성 집합 일치
 *  7. URL              — http(s):// 토큰 집합 일치
 *  8. 빈 값            — 원본이 빈 문자열이면 번역도 빈 문자열
 *  9. 개행             — 개행 개수 일치
 * 10. 한글 잔존        — 번역 값에 남은 Hangul (미번역 검출)
 * 11. 일본어 잔존      — 번역 값에 섞인 히라가나/가타카나 (ja 팩 오염 검출)
 * 12. UTF-8 / BOM / CRLF
 * 13. $partial         — 디렉티브 경로가 실제 파일을 가리키는지
 *
 * 종료 코드: 0 = 전부 통과, 1 = 위반 발견.
 *
 * 옵션
 * ----
 *   --verbose   위반 상세를 전부 출력 (기본은 항목당 최대 20건)
 *   --style     CJK 옆 ASCII 문장부호 등 표기 스타일 경고도 함께 출력 (실패로 치지 않음)
 */
declare(strict_types=1);

// PHP 8 문자열 헬퍼 폴리필 — 프로젝트 런타임은 PHP 8.2 이지만, 이 검사기는
// vendor/ 없이 단독 실행되므로 구버전 CLI(7.x)에서도 그대로 돌아가야 한다.
if (! function_exists('str_starts_with')) {
    /**
     * @return bool
     */
    function str_starts_with(string $haystack, string $needle)
    {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (! function_exists('str_contains')) {
    /**
     * @return bool
     */
    function str_contains(string $haystack, string $needle)
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

$root = dirname(__DIR__, 2);
$packRoot = $root.'/lang-packs/_bundled/g7-module-sirsoft-board-zh-CN';
$jaPackRoot = $root.'/lang-packs/_bundled/g7-module-sirsoft-board-ja';
$moduleRoot = $root.'/modules/_bundled/sirsoft-board';
$locale = 'zh-CN';

/**
 * seed 에서 ja 팩에는 없으나 zh-CN 에는 의도적으로 포함한 키.
 *
 * ja 팩이 게시판 모듈의 ko 원본보다 오래되어 `dashboard` 권한 카테고리(module.php
 * ::getPermissions())가 빠져 있다. 이 팩은 ja 재번역이 아니라 ko 원본을 기준으로
 * 만들었으므로 해당 키를 포함한다 — ja 팩은 별도 동기화 대상이며 이 검사기가
 * 그 차이를 "초과 키" 로 잘못 보고하지 않도록 여기 명시한다.
 *
 * @var array<string, array<int, string>> seed 파일명 ⇒ 허용된 추가 dot-path 목록
 */
$intentionalSeedExtras = [
    'permissions.json' => [
        'sirsoft-board.dashboard.name',
        'sirsoft-board.dashboard.description',
        'sirsoft-board.dashboard.view.name',
        'sirsoft-board.dashboard.view.description',
    ],
];

$verbose = in_array('--verbose', $argv, true);
$styleMode = in_array('--style', $argv, true);

/** @var array<string, array<int, string>> 검사 항목 ⇒ 위반 메시지 목록 */
$violations = [];
/** @var array<int, string> 스타일 경고 (실패 아님) */
$styleWarnings = [];
$stats = ['files' => 0, 'keys' => 0, 'json_files' => 0, 'php_files' => 0];

/**
 * 위반을 기록한다.
 */
function fail(string $category, string $message): void
{
    global $violations;
    $violations[$category][] = $message;
}

/**
 * 중첩 배열을 dot-path ⇒ 값 평탄 맵으로 변환한다.
 *
 * @param  mixed  $node
 * @return array<string, mixed>
 */
function flatten($node, string $prefix = ''): array
{
    if (! is_array($node)) {
        return [$prefix => $node];
    }

    if ($node === []) {
        return [$prefix => []];
    }

    $out = [];
    foreach ($node as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
        $out += flatten($value, $path);
    }

    return $out;
}

/**
 * 동일 깊이의 키 나열 순서를 "부모경로 => [키...]" 목록으로 수집한다.
 *
 * @param  mixed  $node
 * @return array<string, array<int, string>>
 */
function keyOrder($node, string $prefix = ''): array
{
    if (! is_array($node)) {
        return [];
    }

    $out = [$prefix => array_map('strval', array_keys($node))];

    foreach ($node as $key => $value) {
        if (is_array($value)) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $out += keyOrder($value, $path);
        }
    }

    return $out;
}

/**
 * 문자열에서 placeholder 토큰 집합을 추출한다 (정렬된 고유 목록).
 *
 * @return array<int, string>
 */
function placeholders(string $s): array
{
    $found = [];

    // {{var}} 를 먼저 소비해 {var} 규칙과 겹치지 않게 한다.
    if (preg_match_all('/\{\{\s*[A-Za-z0-9_.]+\s*\}\}/u', $s, $m)) {
        foreach ($m[0] as $t) {
            $found[] = 'MUSTACHE:'.preg_replace('/\s+/', '', $t);
        }
    }
    $stripped = preg_replace('/\{\{\s*[A-Za-z0-9_.]+\s*\}\}/u', '', $s);

    if (preg_match_all('/\{[A-Za-z0-9_.]+\}/u', $stripped, $m)) {
        foreach ($m[0] as $t) {
            $found[] = 'BRACE:'.$t;
        }
    }

    // Laravel :placeholder — `v:version` 처럼 영숫자에 붙은 형태도 실제로 치환되므로
    // 앞 문자로 거르지 않고, `::x` 중복 계수만 피한다.
    if (preg_match_all('/(?<!:):([A-Za-z_][A-Za-z0-9_]*)/u', $stripped, $m)) {
        foreach ($m[1] as $t) {
            $found[] = 'COLON::'.$t;
        }
    }

    if (preg_match_all('/%[0-9]*\.?[0-9]*[sdufx]/u', $stripped, $m)) {
        foreach ($m[0] as $t) {
            $found[] = 'PRINTF:'.$t;
        }
    }

    $found = array_values(array_unique($found));
    sort($found);

    return $found;
}

/**
 * 문자열에서 HTML 태그(태그명 + 속성명 집합)를 추출한다.
 *
 * @return array<int, string>
 */
function htmlTags(string $s): array
{
    $found = [];

    if (preg_match_all('/<\/?([a-zA-Z][a-zA-Z0-9]*)((?:\s+[^<>]*?)?)\/?>/u', $s, $m, PREG_SET_ORDER)) {
        foreach ($m as $set) {
            $tag = strtolower($set[1]);
            $attrs = [];
            if (preg_match_all('/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=/u', $set[2] ?? '', $am)) {
                $attrs = array_map('strtolower', $am[1]);
                sort($attrs);
            }
            $found[] = $tag.($attrs ? '['.implode(',', $attrs).']' : '');
        }
    }

    sort($found);

    return $found;
}

/**
 * 문자열에서 URL 토큰을 추출한다.
 *
 * 종결 문장부호는 URL 의 일부가 아니다 — 중국어 전각 괄호·구두점도 경계로 취급한다.
 *
 * @return array<int, string>
 */
function urls(string $s): array
{
    $found = [];
    $boundary = '\s"\'<>()\[\]\x{FF08}\x{FF09}\x{3002}\x{FF0C}\x{3001}\x{FF1B}\x{FF1A}\x{300C}\x{300D}\x{201C}\x{201D}\x{FF01}\x{FF1F}';
    if (preg_match_all('#https?://[^'.$boundary.']+#u', $s, $m)) {
        $found = array_map(static fn (string $u): string => rtrim($u, '.,;:!?'), $m[0]);
    }
    sort($found);

    return $found;
}

/**
 * 값의 자료형 라벨.
 *
 * @param  mixed  $v
 */
function typeOf($v): string
{
    if (is_array($v)) {
        return 'array';
    }
    if (is_bool($v)) {
        return 'bool';
    }
    if (is_int($v)) {
        return 'int';
    }
    if (is_float($v)) {
        return 'float';
    }
    if ($v === null) {
        return 'null';
    }

    return 'string';
}

/**
 * 문자열에 한글(음절/자모)이 포함되어 있는지 판정한다.
 */
function hasHangul(string $v): bool
{
    return preg_match('/[\x{AC00}-\x{D7A3}\x{1100}-\x{11FF}\x{3130}-\x{318F}]/u', $v) === 1;
}

/**
 * 문자열에 일본어 가나가 포함되어 있는지 판정한다.
 */
function hasKana(string $v): bool
{
    return preg_match('/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}]/u', $v) === 1;
}

/**
 * 파일이 BOM 없이 시작하며 UTF-8 로 유효하고 LF 줄바꿈인지 검사한다.
 */
function checkEncoding(string $path, string $label): void
{
    $raw = file_get_contents($path);

    if ($raw === false) {
        fail('encoding', "$label: 파일을 읽을 수 없습니다");

        return;
    }

    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        fail('bom', "$label: UTF-8 BOM 이 존재합니다");
    }

    if (! mb_check_encoding($raw, 'UTF-8')) {
        fail('encoding', "$label: 유효한 UTF-8 이 아닙니다");
    }

    if (str_contains($raw, "\r\n")) {
        fail('encoding', "$label: CRLF 줄바꿈이 포함되어 있습니다 (LF 여야 함)");
    }
}

/**
 * 원본 트리와 번역 트리를 전 항목 대조한다.
 *
 * @param  mixed  $ko
 * @param  mixed  $zh
 */
function compareTree($ko, $zh, string $label): void
{
    global $stats, $styleWarnings;

    $koFlat = flatten($ko);
    $zhFlat = flatten($zh);

    $stats['keys'] += count($koFlat);

    foreach (array_diff_key($koFlat, $zhFlat) as $path => $_) {
        fail('missing_keys', "$label :: $path");
    }

    foreach (array_diff_key($zhFlat, $koFlat) as $path => $_) {
        fail('extra_keys', "$label :: $path");
    }

    $koOrder = keyOrder($ko);
    $zhOrder = keyOrder($zh);
    foreach ($koOrder as $parent => $keys) {
        if (! array_key_exists($parent, $zhOrder)) {
            continue;
        }
        if ($keys !== $zhOrder[$parent]) {
            $where = $parent === '' ? '(root)' : $parent;
            fail('key_order', "$label :: $where — 원본[".implode(',', $keys).'] 번역['.implode(',', $zhOrder[$parent]).']');
        }
    }

    foreach ($koFlat as $path => $koVal) {
        if (! array_key_exists($path, $zhFlat)) {
            continue;
        }
        $zhVal = $zhFlat[$path];

        if (typeOf($koVal) !== typeOf($zhVal)) {
            fail('value_type', "$label :: $path — 원본=".typeOf($koVal).' 번역='.typeOf($zhVal));

            continue;
        }

        if (! is_string($koVal)) {
            continue;
        }

        if (trim($koVal) === '' && trim($zhVal) !== '') {
            fail('empty_value', "$label :: $path — 원본은 빈 값이나 번역에 값이 있습니다");
        }
        if (trim($koVal) !== '' && trim($zhVal) === '') {
            fail('empty_value', "$label :: $path — 번역 값이 비어 있습니다");
        }

        $koPh = placeholders($koVal);
        $zhPh = placeholders($zhVal);
        if ($koPh !== $zhPh) {
            fail('placeholder', "$label :: $path — 원본[".implode(' ', $koPh).'] 번역['.implode(' ', $zhPh).']');
        }

        $koTags = htmlTags($koVal);
        $zhTags = htmlTags($zhVal);
        if ($koTags !== $zhTags) {
            fail('html', "$label :: $path — 원본[".implode(' ', $koTags).'] 번역['.implode(' ', $zhTags).']');
        }

        $koUrls = urls($koVal);
        $zhUrls = urls($zhVal);
        if ($koUrls !== $zhUrls) {
            fail('url', "$label :: $path — 원본[".implode(' ', $koUrls).'] 번역['.implode(' ', $zhUrls).']');
        }

        if (hasHangul($zhVal)) {
            fail('hangul_residue', "$label :: $path — \"".mb_substr($zhVal, 0, 60).'"');
        }

        if (hasKana($zhVal)) {
            fail('kana_residue', "$label :: $path — \"".mb_substr($zhVal, 0, 60).'"');
        }

        if (substr_count($koVal, "\n") !== substr_count($zhVal, "\n")) {
            fail('newline', "$label :: $path — 원본 개행 ".substr_count($koVal, "\n").'개, 번역 '.substr_count($zhVal, "\n").'개');
        }

        if (preg_match('/[\x{4E00}-\x{9FFF}][,;!?]|[,;!?][\x{4E00}-\x{9FFF}]/u', $zhVal)) {
            $styleWarnings[] = "$label :: $path — CJK 인접 ASCII 문장부호";
        }
    }
}

// ── 1. 백엔드 PHP 대조 ────────────────────────────────────────────────
$koDir = $moduleRoot.'/src/lang/ko';
$zhDir = $packRoot.'/backend/'.$locale;

$koNames = array_map('basename', glob($koDir.'/*.php') ?: []);
$zhNames = array_map('basename', glob($zhDir.'/*.php') ?: []);
sort($koNames);
sort($zhNames);

foreach (array_diff($koNames, $zhNames) as $missing) {
    fail('missing_files', "backend/$locale/$missing");
}
foreach (array_diff($zhNames, $koNames) as $extra) {
    fail('extra_files', "backend/$locale/$extra");
}

foreach ($koNames as $name) {
    if (! in_array($name, $zhNames, true)) {
        continue;
    }

    $zhPath = $zhDir.'/'.$name;
    checkEncoding($zhPath, "backend/$locale/$name");

    $koData = require $koDir.'/'.$name;
    $zhData = require $zhPath;

    if (! is_array($koData) || ! is_array($zhData)) {
        fail('structure', "backend/$locale/$name: return 값이 배열이 아닙니다");

        continue;
    }

    compareTree($koData, $zhData, "backend/$locale/$name");
    $stats['files']++;
    $stats['php_files']++;
}

// ── 2. 프론트엔드 JSON 대조 ───────────────────────────────────────────
// 원본의 `$partial` 경로는 "partial/ko/x.json", 팩은 "partial/x.json" 으로
// 정규화된다 (ja 팩과 동일 규칙). 경로 값 자체는 번역 대상이 아니므로
// 비교 전에 원본 경로를 팩 규칙으로 변환해 대조한다.
/**
 * @param  mixed  $node
 * @return mixed
 */
function normalizePartialPaths($node, string $locale)
{
    if (! is_array($node)) {
        return $node;
    }

    $out = [];
    foreach ($node as $k => $v) {
        if ($k === '$partial' && is_string($v)) {
            $out[$k] = str_replace('partial/'.$locale.'/', 'partial/', $v);

            continue;
        }
        $out[$k] = normalizePartialPaths($v, $locale);
    }

    return $out;
}

/**
 * 디렉토리 아래 모든 *.json 을 상대경로 목록으로 수집한다 (재귀).
 *
 * @return array<int, string>
 */
function collectJson(string $dir): array
{
    if (! is_dir($dir)) {
        return [];
    }

    $out = [];
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $f) {
        if ($f->isDir() || strtolower($f->getExtension()) !== 'json') {
            continue;
        }
        $rel = substr($f->getPathname(), strlen($dir) + 1);
        $out[] = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
    }
    sort($out);

    return $out;
}

// 2-1. 엔트리 파일
$koEntry = $moduleRoot.'/resources/lang/ko.json';
$zhEntry = $packRoot.'/frontend/'.$locale.'.json';

if (! is_file($koEntry)) {
    fail('structure', "원본 누락: $koEntry");
} elseif (! is_file($zhEntry)) {
    fail('missing_files', "frontend/$locale.json");
} else {
    checkEncoding($zhEntry, "frontend/$locale.json");
    $koData = json_decode((string) file_get_contents($koEntry), true);
    $zhData = json_decode((string) file_get_contents($zhEntry), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        fail('json_syntax', "frontend/$locale.json: ".json_last_error_msg());
    } else {
        compareTree(normalizePartialPaths($koData, 'ko'), $zhData, "frontend/$locale.json");
        $stats['files']++;
        $stats['json_files']++;
    }
}

// 2-2. partial (재귀 — admin/ 서브디렉토리 포함)
$koPartialDir = $moduleRoot.'/resources/lang/partial/ko';
$zhPartialDir = $packRoot.'/frontend/partial';

$koPartials = collectJson($koPartialDir);
$zhPartials = collectJson($zhPartialDir);

foreach (array_diff($koPartials, $zhPartials) as $missing) {
    fail('missing_files', "frontend/partial/$missing");
}
foreach (array_diff($zhPartials, $koPartials) as $extra) {
    fail('extra_files', "frontend/partial/$extra");
}

foreach ($koPartials as $rel) {
    if (! in_array($rel, $zhPartials, true)) {
        continue;
    }

    $zhPath = $zhPartialDir.'/'.$rel;
    checkEncoding($zhPath, "frontend/partial/$rel");

    $koData = json_decode((string) file_get_contents($koPartialDir.'/'.$rel), true);
    $zhData = json_decode((string) file_get_contents($zhPath), true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        fail('json_syntax', "frontend/partial/$rel: ".json_last_error_msg());

        continue;
    }

    compareTree(normalizePartialPaths($koData, 'ko'), $zhData, "frontend/partial/$rel");
    $stats['files']++;
    $stats['json_files']++;
}

// ── 3. $partial 경로 실재 확인 ────────────────────────────────────────
if (is_file($zhEntry)) {
    $data = json_decode((string) file_get_contents($zhEntry), true);
    $walk = function ($node) use (&$walk, $packRoot) {
        if (! is_array($node)) {
            return;
        }
        foreach ($node as $k => $v) {
            if ($k === '$partial' && is_string($v)) {
                if (! is_file($packRoot.'/frontend/'.$v)) {
                    fail('partial_path', "frontend/$v 가 존재하지 않습니다");
                }

                continue;
            }
            $walk($v);
        }
    };
    $walk($data);
}

// ── 4. seed JSON — ja 팩과 키 대조 + 구문/인코딩/잔존 문자 ────────────
// seed 원본은 module.php / 시더에 분산되어 있어 기계적 1:1 대조 대상이 아니다.
// 동일 모듈 원본에서 생성된 산출물이므로 ja 팩과 키 집합이 같아야 하며,
// 예외는 $intentionalSeedExtras 에 명시된 항목뿐이다.
$jaSeedDir = $jaPackRoot.'/seed';
$zhSeedDir = $packRoot.'/seed';

$jaSeeds = array_map('basename', glob($jaSeedDir.'/*.json') ?: []);
$zhSeeds = array_map('basename', glob($zhSeedDir.'/*.json') ?: []);
sort($jaSeeds);
sort($zhSeeds);

foreach (array_diff($jaSeeds, $zhSeeds) as $missing) {
    fail('missing_files', "seed/$missing");
}
foreach (array_diff($zhSeeds, $jaSeeds) as $extra) {
    fail('extra_files', "seed/$extra");
}

foreach ($jaSeeds as $name) {
    if (! in_array($name, $zhSeeds, true)) {
        continue;
    }

    $zhPath = $zhSeedDir.'/'.$name;
    checkEncoding($zhPath, "seed/$name");

    $jaData = json_decode((string) file_get_contents($jaSeedDir.'/'.$name), true);
    $zhData = json_decode((string) file_get_contents($zhPath), true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        fail('json_syntax', "seed/$name: ".json_last_error_msg());

        continue;
    }

    $jaFlat = flatten($jaData);
    $zhFlat = flatten($zhData);
    $allowedExtras = $intentionalSeedExtras[$name] ?? [];

    foreach (array_diff_key($jaFlat, $zhFlat) as $path => $_) {
        fail('missing_keys', "seed/$name :: $path");
    }
    foreach (array_diff_key($zhFlat, $jaFlat) as $path => $_) {
        if (in_array($path, $allowedExtras, true)) {
            continue;
        }
        fail('extra_keys', "seed/$name :: $path");
    }

    foreach ($zhFlat as $path => $val) {
        if (! is_string($val)) {
            continue;
        }
        if (hasHangul($val)) {
            fail('hangul_residue', "seed/$name :: $path — \"".mb_substr($val, 0, 60).'"');
        }
        if (hasKana($val)) {
            fail('kana_residue', "seed/$name :: $path — \"".mb_substr($val, 0, 60).'"');
        }
        if (! array_key_exists($path, $jaFlat) || ! is_string($jaFlat[$path])) {
            continue;
        }
        $jaPh = placeholders($jaFlat[$path]);
        $zhPh = placeholders($val);
        if ($jaPh !== $zhPh) {
            fail('placeholder', "seed/$name :: $path — ja[".implode(' ', $jaPh).'] zh['.implode(' ', $zhPh).']');
        }
        $jaTags = htmlTags($jaFlat[$path]);
        $zhTags = htmlTags($val);
        if ($jaTags !== $zhTags) {
            fail('html', "seed/$name :: $path — ja[".implode(' ', $jaTags).'] zh['.implode(' ', $zhTags).']');
        }
    }

    $stats['files']++;
    $stats['json_files']++;
}

// ── 5. manifest / CHANGELOG 인코딩 ────────────────────────────────────
foreach (['language-pack.json', 'CHANGELOG.md'] as $meta) {
    $path = $packRoot.'/'.$meta;
    if (! is_file($path)) {
        fail('missing_files', $meta);

        continue;
    }
    checkEncoding($path, $meta);
    $stats['files']++;
}

// ── 결과 출력 ─────────────────────────────────────────────────────────
$categories = [
    'missing_files' => '파일 누락',
    'extra_files' => '초과 파일',
    'structure' => '구조 오류',
    'json_syntax' => 'JSON 구문',
    'missing_keys' => '키 누락',
    'extra_keys' => '초과 키',
    'key_order' => '키 순서 불일치',
    'value_type' => '자료형 불일치',
    'placeholder' => 'placeholder 불일치',
    'html' => 'HTML 태그 불일치',
    'url' => 'URL 불일치',
    'empty_value' => '빈 값 불일치',
    'newline' => '개행 개수 불일치',
    'hangul_residue' => '한글 잔존',
    'kana_residue' => '일본어 가나 잔존',
    'encoding' => '인코딩 오류',
    'bom' => 'BOM 검출',
    'partial_path' => '$partial 경로 오류',
];

echo "=== g7-module-sirsoft-board-zh-CN parity check ===\n";
echo sprintf(
    "검사 파일 %d개 (PHP %d / JSON %d), 대조 키 %d개\n\n",
    $stats['files'],
    $stats['php_files'],
    $stats['json_files'],
    $stats['keys']
);

$total = 0;
foreach ($categories as $key => $label) {
    $items = $violations[$key] ?? [];
    $count = count($items);
    $total += $count;
    printf("%-22s %s\n", $label, $count === 0 ? 'OK (0)' : "FAIL ($count)");
    if ($count > 0) {
        $show = $verbose ? $items : array_slice($items, 0, 20);
        foreach ($show as $item) {
            echo "    - $item\n";
        }
        if (! $verbose && $count > 20) {
            echo '    ... 외 '.($count - 20)."건 (--verbose 로 전체 출력)\n";
        }
    }
}

if ($styleMode) {
    echo "\n--- 표기 스타일 경고 (실패 아님) ---\n";
    if ($styleWarnings === []) {
        echo "없음\n";
    } else {
        foreach (array_slice($styleWarnings, 0, $verbose ? PHP_INT_MAX : 30) as $w) {
            echo "    ~ $w\n";
        }
        echo '    총 '.count($styleWarnings)."건\n";
    }
}

echo "\n".($total === 0 ? "RESULT: PASS — 위반 0건\n" : "RESULT: FAIL — 총 $total 건\n");

exit($total === 0 ? 0 : 1);
