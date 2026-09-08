<?php

/**
 * 플러그인 zh-CN 언어팩 ↔ 한국어 원본 정합성 검사 공용 라이브러리 (standalone).
 *
 * 용도
 * ----
 * 번들 플러그인 11종의 zh-CN 언어팩은 파일 레이아웃이 동일하다
 * (`backend/{locale}/*.php` ← `lang/ko/*.php`, `frontend/{locale}.json` ← `resources/lang/ko.json`,
 * `seed/*.json` ← ja 팩). 플러그인마다 같은 검사기를 복사하면 규칙이 갈라지므로,
 * 검사 로직은 이 파일 하나에 두고 플러그인별 진입점은 얇게 유지한다.
 *
 * 코어·모듈 팩 검사기(`zh-CN-core|board|ecommerce|page-parity-check.php`)와 동일한 계약을
 * plugin 스코프에 적용한다. Laravel 부팅도 Composer autoload 도 필요하지 않아 vendor/ 가
 * 없는 환경에서도 단독 실행된다.
 *
 * 검사 항목
 * --------
 *  1. 파일 인벤토리    — ko 원본 대비 파일 1:1 대응 (누락/초과)
 *  2. 키 누락 / 초과   — dot-path 집합 비교
 *  3. 키 순서          — 동일 깊이에서의 키 나열 순서 일치
 *  4. 자료형           — array/string/int/bool/null 일치
 *  5. placeholder      — :attr, {var}, {{var}}, %s/%d 의 종류·개수 일치
 *  6. HTML 태그        — 태그명 + 속성 집합 일치
 *  7. URL              — http(s):// 토큰 집합 일치
 *  8. 빈 값            — 원본이 빈 문자열이면 번역도 빈 문자열
 *  9. 개행             — 개행 개수 일치
 * 10. 한글 잔존        — 번역 값에 남은 Hangul (미번역 검출)
 * 11. 일본어 잔존      — 번역 값에 섞인 히라가나/가타카나 (ja 팩 오염 검출)
 * 12. UTF-8 / BOM / CRLF
 * 13. $partial         — 디렉티브 경로가 실제 파일을 가리키는지
 * 14. seed             — ja 팩과 키 집합·순서 대조 (의도적 차이는 상수로 명시)
 * 15. manifest         — 필수 필드 · BCP-47 · 네이밍 공식 · target ↔ plugin.json identifier
 * 16. 보안             — 확장자 화이트리스트 · PHP 위치 · 심볼릭 링크 · 금지 함수
 *
 * @see zh-CN-plugins-parity-check.php 전체 플러그인 통합 실행
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
if (! function_exists('str_ends_with')) {
    /**
     * @return bool
     */
    function str_ends_with(string $haystack, string $needle)
    {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}

/**
 * 검사 항목 키 ⇒ 출력 라벨.
 *
 * @return array<string, string>
 */
function zhcnPluginCategories(): array
{
    return [
        'missing_files' => '파일 누락',
        'extra_files' => '초과 파일',
        'structure' => '구조 오류',
        'json_syntax' => 'JSON 구문',
        'php_structure' => 'PHP 배열 구조',
        'missing_keys' => '키 누락',
        'extra_keys' => '초과 키',
        'key_order' => '키 순서 불일치',
        'type' => '자료형 불일치',
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
        'manifest' => 'manifest 계약',
        'security' => '보안 규칙',
        'ja_structure' => 'ja 팩 구조 차이',
    ];
}

/**
 * 중첩 배열을 dot-path ⇒ 값 평탄 맵으로 변환한다.
 *
 * @param  mixed  $node
 * @return array<string, mixed>
 */
function zhcnFlatten($node, string $prefix = ''): array
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
        $out += zhcnFlatten($value, $path);
    }

    return $out;
}

/**
 * 동일 깊이의 키 나열 순서를 "부모경로 => [키...]" 목록으로 수집한다.
 *
 * @param  mixed  $node
 * @return array<string, array<int, string>>
 */
function zhcnKeyOrder($node, string $prefix = ''): array
{
    if (! is_array($node)) {
        return [];
    }

    $out = [$prefix => array_map('strval', array_keys($node))];

    foreach ($node as $key => $value) {
        if (is_array($value)) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $out += zhcnKeyOrder($value, $path);
        }
    }

    return $out;
}

/**
 * 문자열에서 placeholder 를 "토큰 ⇒ 등장 횟수" 맵으로 추출한다.
 *
 * 종류뿐 아니라 개수까지 비교해야 `:count` 를 한 번 빠뜨린 번역을 잡을 수 있다.
 *
 * @return array<string, int>
 */
function zhcnPlaceholders(string $s): array
{
    $counts = [];
    $bump = static function (string $token) use (&$counts): void {
        $counts[$token] = ($counts[$token] ?? 0) + 1;
    };

    // {{var}} 를 먼저 소비해 {var} 규칙과 겹치지 않게 한다.
    if (preg_match_all('/\{\{\s*[A-Za-z0-9_.$]+\s*\}\}/u', $s, $m)) {
        foreach ($m[0] as $t) {
            $bump('MUSTACHE:'.preg_replace('/\s+/', '', $t));
        }
    }
    $stripped = preg_replace('/\{\{\s*[A-Za-z0-9_.$]+\s*\}\}/u', '', $s);

    if (preg_match_all('/\{[A-Za-z0-9_.$]+\}/u', $stripped, $m)) {
        foreach ($m[0] as $t) {
            $bump('BRACE:'.$t);
        }
    }

    // Laravel :placeholder — `v:version` 처럼 영숫자에 붙은 형태도 실제로 치환되므로
    // 앞 문자로 거르지 않고, `::x` 중복 계수만 피한다.
    if (preg_match_all('/(?<!:):([A-Za-z_][A-Za-z0-9_]*)/u', $stripped, $m)) {
        foreach ($m[1] as $t) {
            $bump('COLON:'.$t);
        }
    }

    if (preg_match_all('/%[0-9]*\.?[0-9]*[sdufx]/u', $stripped, $m)) {
        foreach ($m[0] as $t) {
            $bump('PRINTF:'.$t);
        }
    }

    ksort($counts);

    return $counts;
}

/**
 * placeholder 맵을 사람이 읽을 문자열로 만든다.
 *
 * @param  array<string, int>  $counts
 */
function zhcnPlaceholderLabel(array $counts): string
{
    $parts = [];
    foreach ($counts as $token => $n) {
        $parts[] = $n > 1 ? "$token x$n" : $token;
    }

    return implode(' ', $parts);
}

/**
 * 문자열에서 HTML 태그(태그명 + 속성명 집합)를 추출한다.
 *
 * @return array<int, string>
 */
function zhcnHtmlTags(string $s): array
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
function zhcnUrls(string $s): array
{
    $found = [];
    $boundary = '\s"\'<>()\[\]\x{FF08}\x{FF09}\x{3002}\x{FF0C}\x{3001}\x{FF1B}\x{FF1A}\x{300C}\x{300D}\x{201C}\x{201D}\x{FF01}\x{FF1F}';
    if (preg_match_all('#https?://[^'.$boundary.']+#u', $s, $m)) {
        $found = array_map(static function (string $u): string {
            return rtrim($u, '.,;:!?');
        }, $m[0]);
    }
    sort($found);

    return $found;
}

/**
 * 값의 자료형 라벨.
 *
 * @param  mixed  $v
 */
function zhcnTypeOf($v): string
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
function zhcnHasHangul(string $v): bool
{
    return preg_match('/[\x{AC00}-\x{D7A3}\x{1100}-\x{11FF}\x{3130}-\x{318F}]/u', $v) === 1;
}

/**
 * 문자열에 히라가나/가타카나가 포함되어 있는지 판정한다.
 */
function zhcnHasKana(string $v): bool
{
    return preg_match('/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}]/u', $v) === 1;
}

/**
 * 검사 상태 컨테이너.
 *
 * 전역 변수를 쓰면 통합 실행(플러그인 11종을 한 프로세스에서 순회)에서 결과가 섞인다.
 * 플러그인마다 새 인스턴스를 만들어 격리한다.
 */
final class ZhCnParityReport
{
    /** @var array<string, array<int, string>> 검사 항목 ⇒ 위반 메시지 */
    public array $violations = [];

    /** @var array<int, string> 표기 스타일 경고 (실패 아님) */
    public array $styleWarnings = [];

    /** @var array<string, int> */
    public array $stats = ['files' => 0, 'keys' => 0, 'php_files' => 0, 'json_files' => 0];

    public function fail(string $category, string $message): void
    {
        $this->violations[$category][] = $message;
    }

    public function total(): int
    {
        $n = 0;
        foreach ($this->violations as $list) {
            $n += count($list);
        }

        return $n;
    }
}

/**
 * 파일 인코딩(UTF-8 / BOM / CRLF)을 검사한다.
 */
function zhcnCheckEncoding(ZhCnParityReport $r, string $path, string $label): void
{
    $raw = (string) file_get_contents($path);

    if (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
        $r->fail('bom', "$label: BOM 이 있습니다");
    }
    if (strpos($raw, "\r\n") !== false) {
        $r->fail('encoding', "$label: CRLF 개행이 있습니다");
    }
    if (! mb_check_encoding($raw, 'UTF-8')) {
        $r->fail('encoding', "$label: UTF-8 이 아닙니다");
    }
}

/**
 * ko 원본 트리와 zh 번역 트리를 값 단위로 대조한다.
 *
 * @param  mixed  $ko
 * @param  mixed  $zh
 */
function zhcnCompareTree(ZhCnParityReport $r, $ko, $zh, string $label, bool $styleMode, array $urlExempt = []): void
{
    $koFlat = zhcnFlatten($ko);
    $zhFlat = zhcnFlatten($zh);

    foreach (array_diff_key($koFlat, $zhFlat) as $path => $_) {
        $r->fail('missing_keys', "$label :: $path");
    }
    foreach (array_diff_key($zhFlat, $koFlat) as $path => $_) {
        $r->fail('extra_keys', "$label :: $path");
    }

    $koOrder = zhcnKeyOrder($ko);
    $zhOrder = zhcnKeyOrder($zh);
    foreach ($koOrder as $parent => $keys) {
        if (! array_key_exists($parent, $zhOrder)) {
            continue;
        }
        if ($keys !== $zhOrder[$parent]) {
            $where = $parent === '' ? '(root)' : $parent;
            $r->fail('key_order', "$label :: $where");
        }
    }

    foreach ($koFlat as $path => $koValue) {
        if (! array_key_exists($path, $zhFlat)) {
            continue;
        }
        $zhValue = $zhFlat[$path];
        $r->stats['keys']++;

        if (zhcnTypeOf($koValue) !== zhcnTypeOf($zhValue)) {
            $r->fail('type', "$label :: $path — ko=".zhcnTypeOf($koValue).' zh='.zhcnTypeOf($zhValue));

            continue;
        }
        if (! is_string($koValue)) {
            continue;
        }

        if ($koValue === '' && $zhValue !== '') {
            $r->fail('empty_value', "$label :: $path — 원본이 빈 문자열인데 번역에 값이 있음");
        }
        if ($koValue !== '' && $zhValue === '') {
            $r->fail('empty_value', "$label :: $path — 번역이 비어 있음");
        }

        $koPh = zhcnPlaceholders($koValue);
        $zhPh = zhcnPlaceholders($zhValue);
        if ($koPh !== $zhPh) {
            $r->fail('placeholder', "$label :: $path — ko[".zhcnPlaceholderLabel($koPh).'] zh['.zhcnPlaceholderLabel($zhPh).']');
        }

        $koTags = zhcnHtmlTags($koValue);
        $zhTags = zhcnHtmlTags($zhValue);
        if ($koTags !== $zhTags) {
            $r->fail('html', "$label :: $path — ko[".implode(' ', $koTags).'] zh['.implode(' ', $zhTags).']');
        }

        $koUrls = zhcnUrls($koValue);
        $zhUrls = zhcnUrls($zhValue);
        if ($koUrls !== $zhUrls && ! in_array("$label :: $path", $urlExempt, true)) {
            $r->fail('url', "$label :: $path — ko[".implode(' ', $koUrls).'] zh['.implode(' ', $zhUrls).']');
        }

        if (substr_count($koValue, "\n") !== substr_count($zhValue, "\n")) {
            $r->fail('newline', "$label :: $path — 개행 수 ko=".substr_count($koValue, "\n").' zh='.substr_count($zhValue, "\n"));
        }

        if (zhcnHasHangul($zhValue)) {
            $r->fail('hangul_residue', "$label :: $path — \"".mb_substr($zhValue, 0, 60).'"');
        }
        if (zhcnHasKana($zhValue)) {
            $r->fail('kana_residue', "$label :: $path — \"".mb_substr($zhValue, 0, 60).'"');
        }

        // 마침표(.)는 제외한다 — `处理中...` 같은 말줄임표는 기존 zh-CN 팩이 공통으로
        // 쓰는 정상 표기라 포함하면 신호가 잡음에 묻힌다 (코어·모듈 검사기와 동일 규칙).
        if ($styleMode && preg_match('/[\x{4E00}-\x{9FFF}][,;!?]|[,;!?][\x{4E00}-\x{9FFF}]/u', $zhValue)) {
            $r->styleWarnings[] = "$label :: $path — CJK 인접 ASCII 문장부호";
        }
    }
}

/**
 * 팩 하나를 검사하고 결과 리포트를 돌려준다.
 *
 * @param  array<string, mixed>  $intentional  ja 대비 의도적 차이 선언
 *                                             - seed_extras: array<string, array<int,string>> seed 파일 ⇒ 허용 추가 dot-path
 *                                             - seed_missing: array<string, array<int,string>> seed 파일 ⇒ 의도적으로 뺀 dot-path
 *                                             - ja_only_files: array<int,string> ja 에만 있는 상대경로 (frontend/backend)
 *                                             - url_exempt: array<int,string> URL 대조를 면제할 "라벨 :: dot-path"
 */
function zhcnPluginParityCheck(string $root, string $target, array $intentional = [], bool $styleMode = false): ZhCnParityReport
{
    $r = new ZhCnParityReport;
    $locale = 'zh-CN';
    $urlExempt = $intentional['url_exempt'] ?? [];
    $packId = "g7-plugin-$target-$locale";
    $packRoot = "$root/lang-packs/_bundled/$packId";
    $jaPackRoot = "$root/lang-packs/_bundled/g7-plugin-$target-ja";
    $pluginRoot = "$root/plugins/_bundled/$target";

    if (! is_dir($packRoot)) {
        $r->fail('structure', "팩 디렉토리가 없습니다: lang-packs/_bundled/$packId");

        return $r;
    }

    // ── 1. backend PHP — ko 원본 대조 ─────────────────────────────────
    $koBackendDir = "$pluginRoot/lang/ko";
    $zhBackendDir = "$packRoot/backend/$locale";
    $koBackend = is_dir($koBackendDir) ? array_map('basename', glob("$koBackendDir/*.php") ?: []) : [];
    $zhBackend = is_dir($zhBackendDir) ? array_map('basename', glob("$zhBackendDir/*.php") ?: []) : [];
    sort($koBackend);
    sort($zhBackend);

    foreach (array_diff($koBackend, $zhBackend) as $missing) {
        $r->fail('missing_files', "backend/$locale/$missing");
    }
    foreach (array_diff($zhBackend, $koBackend) as $extra) {
        $r->fail('extra_files', "backend/$locale/$extra");
    }

    foreach (array_intersect($koBackend, $zhBackend) as $name) {
        $zhPath = "$zhBackendDir/$name";
        zhcnCheckEncoding($r, $zhPath, "backend/$locale/$name");

        $raw = (string) file_get_contents($zhPath);
        if (! preg_match('/^<\?php\s+(declare\(strict_types=1\);\s+)?return\s*\[/u', $raw)) {
            $r->fail('php_structure', "backend/$locale/$name: `<?php return [` 형태의 순수 배열 반환이 아닙니다");
        }

        $koData = require "$koBackendDir/$name";
        $zhData = require $zhPath;
        if (! is_array($zhData)) {
            $r->fail('php_structure', "backend/$locale/$name: 배열을 반환하지 않습니다");

            continue;
        }

        zhcnCompareTree($r, $koData, $zhData, "backend/$locale/$name", $styleMode, $urlExempt);
        $r->stats['files']++;
        $r->stats['php_files']++;
    }

    // ── 2. frontend JSON — ko 원본 대조 ───────────────────────────────
    $koEntry = "$pluginRoot/resources/lang/ko.json";
    $zhEntry = "$packRoot/frontend/$locale.json";

    if (is_file($koEntry) && ! is_file($zhEntry)) {
        $r->fail('missing_files', "frontend/$locale.json");
    } elseif (! is_file($koEntry) && is_file($zhEntry)) {
        $r->fail('extra_files', "frontend/$locale.json");
    } elseif (is_file($koEntry)) {
        zhcnCheckEncoding($r, $zhEntry, "frontend/$locale.json");

        $koData = json_decode((string) file_get_contents($koEntry), true);
        $zhData = json_decode((string) file_get_contents($zhEntry), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $r->fail('json_syntax', "frontend/$locale.json: ".json_last_error_msg());
        } else {
            zhcnCompareTree($r, zhcnNormalizePartialPaths($koData), $zhData, "frontend/$locale.json", $styleMode, $urlExempt);
            $r->stats['files']++;
            $r->stats['json_files']++;
        }
    }

    // partial 디렉토리 (현재 플러그인들은 사용하지 않지만 계약상 지원한다)
    $koPartialDir = "$pluginRoot/resources/lang/partial/ko";
    $zhPartialDir = "$packRoot/frontend/partial";
    $koPartials = zhcnCollectJson($koPartialDir);
    $zhPartials = zhcnCollectJson($zhPartialDir);
    foreach (array_diff($koPartials, $zhPartials) as $missing) {
        $r->fail('missing_files', "frontend/partial/$missing");
    }
    foreach (array_diff($zhPartials, $koPartials) as $extra) {
        $r->fail('extra_files', "frontend/partial/$extra");
    }
    foreach (array_intersect($koPartials, $zhPartials) as $rel) {
        $zhPath = "$zhPartialDir/$rel";
        zhcnCheckEncoding($r, $zhPath, "frontend/partial/$rel");
        $koData = json_decode((string) file_get_contents("$koPartialDir/$rel"), true);
        $zhData = json_decode((string) file_get_contents($zhPath), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $r->fail('json_syntax', "frontend/partial/$rel: ".json_last_error_msg());

            continue;
        }
        zhcnCompareTree($r, zhcnNormalizePartialPaths($koData), $zhData, "frontend/partial/$rel", $styleMode, $urlExempt);
        $r->stats['files']++;
        $r->stats['json_files']++;
    }

    // ── 3. $partial 경로 실재 확인 ────────────────────────────────────
    if (is_file($zhEntry)) {
        $data = json_decode((string) file_get_contents($zhEntry), true);
        $walk = function ($node) use (&$walk, $packRoot, $r): void {
            if (! is_array($node)) {
                return;
            }
            foreach ($node as $key => $value) {
                if ($key === '$partial' && is_string($value)) {
                    if (! is_file("$packRoot/frontend/$value")) {
                        $r->fail('partial_path', "$value 가 존재하지 않습니다");
                    }

                    continue;
                }
                $walk($value);
            }
        };
        $walk(is_array($data) ? $data : []);
    }

    // ── 4. seed JSON — ja 팩 대조 ─────────────────────────────────────
    // seed 원본은 plugin.php / plugin.json 에 분산되어 기계적 1:1 대조 대상이 아니다.
    // 동일 원본에서 생성된 산출물이므로 ja 팩과 키 집합이 같아야 하며,
    // 예외는 $intentional 에 명시된 항목뿐이다.
    $jaSeedDir = "$jaPackRoot/seed";
    $zhSeedDir = "$packRoot/seed";
    $jaSeeds = is_dir($jaSeedDir) ? array_map('basename', glob("$jaSeedDir/*.json") ?: []) : [];
    $zhSeeds = is_dir($zhSeedDir) ? array_map('basename', glob("$zhSeedDir/*.json") ?: []) : [];
    sort($jaSeeds);
    sort($zhSeeds);

    foreach (array_diff($jaSeeds, $zhSeeds) as $missing) {
        $r->fail('missing_files', "seed/$missing");
    }
    foreach (array_diff($zhSeeds, $jaSeeds) as $extra) {
        $r->fail('extra_files', "seed/$extra");
    }

    foreach (array_intersect($jaSeeds, $zhSeeds) as $name) {
        $zhPath = "$zhSeedDir/$name";
        zhcnCheckEncoding($r, $zhPath, "seed/$name");

        $jaData = json_decode((string) file_get_contents("$jaSeedDir/$name"), true);
        $zhData = json_decode((string) file_get_contents($zhPath), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $r->fail('json_syntax', "seed/$name: ".json_last_error_msg());

            continue;
        }

        $jaFlat = zhcnFlatten($jaData);
        $zhFlat = zhcnFlatten($zhData);
        $allowedExtras = $intentional['seed_extras'][$name] ?? [];
        $allowedMissing = $intentional['seed_missing'][$name] ?? [];

        foreach (array_diff_key($jaFlat, $zhFlat) as $path => $_) {
            if (in_array($path, $allowedMissing, true)) {
                continue;
            }
            $r->fail('missing_keys', "seed/$name :: $path");
        }
        foreach (array_diff_key($zhFlat, $jaFlat) as $path => $_) {
            if (in_array($path, $allowedExtras, true)) {
                continue;
            }
            $r->fail('extra_keys', "seed/$name :: $path");
        }

        $jaOrder = array_keys($jaFlat);
        $zhOrder = array_keys($zhFlat);
        if ($allowedExtras === [] && $allowedMissing === [] && $jaOrder !== $zhOrder) {
            $r->fail('key_order', "seed/$name — ja 팩과 키 순서가 다릅니다");
        }

        foreach ($zhFlat as $path => $val) {
            if (! is_string($val)) {
                continue;
            }
            if (zhcnHasHangul($val)) {
                $r->fail('hangul_residue', "seed/$name :: $path — \"".mb_substr($val, 0, 60).'"');
            }
            if (zhcnHasKana($val)) {
                $r->fail('kana_residue', "seed/$name :: $path — \"".mb_substr($val, 0, 60).'"');
            }
            if (! array_key_exists($path, $jaFlat) || ! is_string($jaFlat[$path])) {
                continue;
            }
            $jaPh = zhcnPlaceholders($jaFlat[$path]);
            $zhPh = zhcnPlaceholders($val);
            if ($jaPh !== $zhPh) {
                $r->fail('placeholder', "seed/$name :: $path — ja[".zhcnPlaceholderLabel($jaPh).'] zh['.zhcnPlaceholderLabel($zhPh).']');
            }
            $jaTags = zhcnHtmlTags($jaFlat[$path]);
            $zhTags = zhcnHtmlTags($val);
            if ($jaTags !== $zhTags) {
                $r->fail('html', "seed/$name :: $path — ja[".implode(' ', $jaTags).'] zh['.implode(' ', $zhTags).']');
            }
        }

        $r->stats['files']++;
        $r->stats['json_files']++;
    }

    // ── 4-b. ja 팩과의 구조 대조 (드리프트 가시화) ────────────────────
    // 이 팩의 정합 기준은 어디까지나 ko 원본이다. 다만 ja 팩과의 파일 구성 차이는
    // "ja 가 낡았는가 / zh 가 빠뜨렸는가" 를 사람이 판단할 수 있도록 표면화한다.
    $jaOnly = $intentional['ja_only_files'] ?? [];
    $jaBackend = is_dir("$jaPackRoot/backend/ja") ? array_map('basename', glob("$jaPackRoot/backend/ja/*.php") ?: []) : [];
    sort($jaBackend);
    foreach (array_diff($jaBackend, $zhBackend) as $onlyJa) {
        if (in_array("backend/$onlyJa", $jaOnly, true)) {
            continue;
        }
        $r->fail('ja_structure', "ja 팩에만 있는 backend 파일: $onlyJa (ko 원본 확인 필요)");
    }
    foreach (array_diff($zhBackend, $jaBackend) as $onlyZh) {
        $r->fail('ja_structure', "zh 팩에만 있는 backend 파일: $onlyZh (ja 팩이 낡았는지 확인 필요)");
    }

    // ── 4-c. ja 팩과의 키 순서 드리프트 (정보성 — 실패 아님) ──────────
    if ($styleMode) {
        foreach (array_intersect($koBackend, $jaBackend) as $name) {
            $koKeys = array_keys(zhcnFlatten(require "$koBackendDir/$name"));
            $jaKeys = array_keys(zhcnFlatten(require "$jaPackRoot/backend/ja/$name"));
            if ($koKeys !== $jaKeys && array_diff($koKeys, $jaKeys) === [] && array_diff($jaKeys, $koKeys) === []) {
                $r->styleWarnings[] = "backend/$locale/$name — ja 팩과 키 순서가 다름 (키 집합은 동일). zh 는 ko 순서를 따른다";
            }
        }
        if (is_file($koEntry) && is_file("$jaPackRoot/frontend/ja.json")) {
            $koKeys = array_keys(zhcnFlatten(json_decode((string) file_get_contents($koEntry), true)));
            $jaKeys = array_keys(zhcnFlatten(json_decode((string) file_get_contents("$jaPackRoot/frontend/ja.json"), true)));
            $jaOnlyKeys = array_values(array_diff($jaKeys, $koKeys));
            $koOnlyKeys = array_values(array_diff($koKeys, $jaKeys));
            if ($jaOnlyKeys !== []) {
                $r->styleWarnings[] = "frontend/$locale.json — ja 팩에만 있는 키 ".count($jaOnlyKeys).'건 (ko 원본에서 제거됨): '
                    .implode(', ', array_slice($jaOnlyKeys, 0, 3)).(count($jaOnlyKeys) > 3 ? ' …' : '');
            }
            if ($koOnlyKeys !== []) {
                $r->styleWarnings[] = "frontend/$locale.json — ko 원본에만 있는 키 ".count($koOnlyKeys).'건 (ja 팩이 낡음): '
                    .implode(', ', array_slice($koOnlyKeys, 0, 3)).(count($koOnlyKeys) > 3 ? ' …' : '');
            }
            if ($jaOnlyKeys === [] && $koOnlyKeys === [] && $koKeys !== $jaKeys) {
                $r->styleWarnings[] = "frontend/$locale.json — ja 팩과 키 순서가 다름 (키 집합은 동일). zh 는 ko 순서를 따른다";
            }
        }
    }

    // ── 5. manifest 계약 ──────────────────────────────────────────────
    zhcnCheckManifest($r, $packRoot, $pluginRoot, $packId, $target, $locale);

    // ── 6. 보안 규칙 ──────────────────────────────────────────────────
    zhcnCheckSecurity($r, $packRoot, $locale);

    // ── 7. CHANGELOG 인코딩 ───────────────────────────────────────────
    if (is_file("$packRoot/CHANGELOG.md")) {
        zhcnCheckEncoding($r, "$packRoot/CHANGELOG.md", 'CHANGELOG.md');
    } else {
        $r->fail('missing_files', 'CHANGELOG.md');
    }

    return $r;
}

/**
 * 원본의 `$partial` 경로(`partial/ko/x.json`)를 팩 규칙(`partial/x.json`)으로 정규화한다.
 *
 * @param  mixed  $node
 * @return mixed
 */
function zhcnNormalizePartialPaths($node)
{
    if (! is_array($node)) {
        return $node;
    }

    $out = [];
    foreach ($node as $key => $value) {
        if ($key === '$partial' && is_string($value)) {
            $out[$key] = preg_replace('#^partial/ko/#', 'partial/', $value);

            continue;
        }
        $out[$key] = zhcnNormalizePartialPaths($value);
    }

    return $out;
}

/**
 * 디렉토리 아래 모든 *.json 을 정렬된 상대경로 목록으로 수집한다 (재귀).
 *
 * @return array<int, string>
 */
function zhcnCollectJson(string $dir): array
{
    if (! is_dir($dir)) {
        return [];
    }

    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (! $file->isFile() || strtolower($file->getExtension()) !== 'json') {
            continue;
        }
        $out[] = strtr(substr($file->getPathname(), strlen($dir) + 1), DIRECTORY_SEPARATOR, '/');
    }
    sort($out);

    return $out;
}

/**
 * manifest 필수 필드·BCP-47·네이밍 공식·target 일치를 검사한다.
 */
function zhcnCheckManifest(ZhCnParityReport $r, string $packRoot, string $pluginRoot, string $packId, string $target, string $locale): void
{
    $path = "$packRoot/language-pack.json";
    if (! is_file($path)) {
        $r->fail('missing_files', 'language-pack.json');

        return;
    }
    zhcnCheckEncoding($r, $path, 'language-pack.json');

    $m = json_decode((string) file_get_contents($path), true);
    if (! is_array($m)) {
        $r->fail('json_syntax', 'language-pack.json: '.json_last_error_msg());

        return;
    }

    $required = [
        'identifier', 'namespace', 'vendor', 'name', 'description', 'version', 'license',
        'scope', 'target_identifier', 'locale', 'locale_name', 'locale_native_name',
        'text_direction', 'g7_version', 'requires',
    ];
    foreach ($required as $field) {
        if (! array_key_exists($field, $m)) {
            $r->fail('manifest', "필수 필드 누락: $field");
        }
    }

    if (($m['identifier'] ?? null) !== $packId) {
        $r->fail('manifest', "identifier 불일치: ".var_export($m['identifier'] ?? null, true));
    }
    if (($m['scope'] ?? null) !== 'plugin') {
        $r->fail('manifest', 'scope 는 plugin 이어야 합니다');
    }
    if (($m['target_identifier'] ?? null) !== $target) {
        $r->fail('manifest', 'target_identifier 가 플러그인 식별자와 다릅니다');
    }
    if (($m['locale'] ?? null) !== $locale) {
        $r->fail('manifest', "locale 은 $locale 이어야 합니다");
    }
    if (($m['locale_name'] ?? null) !== 'Simplified Chinese') {
        $r->fail('manifest', 'locale_name 은 Simplified Chinese 이어야 합니다');
    }
    if (($m['locale_native_name'] ?? null) !== '简体中文') {
        $r->fail('manifest', 'locale_native_name 은 简体中文 이어야 합니다');
    }
    if (($m['text_direction'] ?? null) !== 'ltr') {
        $r->fail('manifest', 'text_direction 은 ltr 이어야 합니다');
    }
    if (($m['requires']['depends_on_core_locale'] ?? null) !== true) {
        $r->fail('manifest', 'requires.depends_on_core_locale 는 true 여야 합니다');
    }
    // `?? ` 는 null 을 "없음" 으로 취급하므로 존재 여부와 값을 따로 본다.
    if (! is_array($m['requires'] ?? null)
        || ! array_key_exists('target_version', $m['requires'])
        || $m['requires']['target_version'] !== null) {
        $r->fail('manifest', 'requires.target_version 은 null 이어야 합니다 (기존 팩 계약)');
    }
    if (! preg_match('/^\d+\.\d+\.\d+$/', (string) ($m['version'] ?? ''))) {
        $r->fail('manifest', 'version 이 SemVer 가 아닙니다');
    }

    // BCP-47 (LanguagePackManifestValidator 와 동일 패턴)
    if (! preg_match('/^[a-z]{2,3}(-[A-Z][a-z]{3})?(-[A-Z]{2}|-[0-9]{3})?(-[a-zA-Z0-9]{5,8})?$/', (string) ($m['locale'] ?? ''))) {
        $r->fail('manifest', 'locale 이 BCP-47 패턴을 통과하지 못합니다');
    }
    // LanguagePackBundledRegistrar 의 locale 디렉토리 스캔 패턴
    if (! preg_match('/^[a-z]{2,3}(-[A-Z]{2})?$/', (string) ($m['locale'] ?? ''))) {
        $r->fail('manifest', 'locale 이 번들 registrar 의 디렉토리 스캔 패턴을 통과하지 못합니다');
    }
    // 네이밍 공식: {namespace}-{scope}-{target}-{locale}
    $expected = ($m['namespace'] ?? '').'-'.($m['scope'] ?? '').'-'.($m['target_identifier'] ?? '').'-'.($m['locale'] ?? '');
    if ($expected !== ($m['identifier'] ?? null)) {
        $r->fail('manifest', "네이밍 공식 불일치: 기대 $expected");
    }

    foreach (['name', 'description'] as $field) {
        if (! is_array($m[$field] ?? null)) {
            $r->fail('manifest', "$field 는 다국어 객체여야 합니다");

            continue;
        }
        foreach (['ko', 'en', $locale] as $l) {
            if (empty($m[$field][$l])) {
                $r->fail('manifest', "$field.$l 이 비어 있습니다");
            }
        }
    }

    // target ↔ plugin.json identifier
    $pluginJson = "$pluginRoot/plugin.json";
    if (! is_file($pluginJson)) {
        $r->fail('manifest', "대상 플러그인 manifest 가 없습니다: plugins/_bundled/$target/plugin.json");

        return;
    }
    $p = json_decode((string) file_get_contents($pluginJson), true);
    if (($p['identifier'] ?? null) !== $target) {
        $r->fail('manifest', 'plugin.json 의 identifier 와 target_identifier 가 다릅니다');
    }
    if (($m['vendor'] ?? null) !== ($p['vendor'] ?? null)) {
        $r->fail('manifest', 'vendor 가 plugin.json 과 다릅니다');
    }
    if (($m['license'] ?? null) !== ($p['license'] ?? null)) {
        $r->fail('manifest', 'license 가 plugin.json 과 다릅니다');
    }
}

/**
 * 언어팩 보안 규칙(확장자·PHP 위치·심볼릭 링크·금지 함수·비밀정보)을 검사한다.
 */
function zhcnCheckSecurity(ZhCnParityReport $r, string $packRoot, string $locale): void
{
    $allowedExt = ['php', 'json', 'md'];
    $phpLocation = '#^backend/(?:'.preg_quote($locale, '#').'/)?[A-Za-z0-9_-]+\.php$#';
    $banned = [
        'eval', 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen',
        'include', 'include_once', 'require', 'require_once',
        'file_put_contents', 'fopen', 'unlink', 'curl_init', 'file_get_contents',
        'getenv', 'putenv', 'call_user_func', 'call_user_func_array', 'create_function',
    ];
    // 실제 자격증명만 잡는다. `test_secret_key` 같은 **키 이름**은 결제 플러그인 설정 화면의
    // 정상적인 라벨이므로 이름만으로 실패시키면 오탐이 된다 — 긴 리터럴 값이 붙은 경우,
    // 개인키 블록, AWS 액세스 키 형식만 위반으로 본다.
    $secretPattern = '/((?:api[_-]?key|secret|passwd|password|token)\s*["\']?\s*[:=]\s*["\'][A-Za-z0-9_\-\/+]{16,}["\']'
        .'|BEGIN [A-Z ]*PRIVATE KEY'
        .'|AKIA[0-9A-Z]{16})/i';

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($packRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $pathname = $file->getPathname();
        if (is_link($pathname)) {
            $r->fail('security', '심볼릭 링크: '.$file->getFilename());

            continue;
        }
        if (! $file->isFile()) {
            continue;
        }
        $rel = strtr(substr($pathname, strlen($packRoot) + 1), DIRECTORY_SEPARATOR, '/');
        $ext = strtolower($file->getExtension());

        if (! in_array($ext, $allowedExt, true)) {
            $r->fail('security', "허용되지 않은 확장자: $rel");
        }
        if ($file->getSize() > 512 * 1024) {
            $r->fail('security', "파일이 512KB 를 초과합니다: $rel");
        }

        $raw = (string) file_get_contents($pathname);
        if (strpos($raw, "\0") !== false) {
            $r->fail('security', "바이너리 파일: $rel");
        }
        if (preg_match($secretPattern, $raw)) {
            $r->fail('security', "비밀정보로 보이는 문자열: $rel");
        }

        if ($ext !== 'php') {
            continue;
        }
        if (! preg_match($phpLocation, $rel)) {
            $r->fail('security', "PHP 파일이 backend 번역 경로 밖에 있습니다: $rel");
        }
        foreach ($banned as $fn) {
            if (preg_match('/\b'.preg_quote($fn, '/').'\s*\(/i', $raw)) {
                $r->fail('security', "금지 함수 호출: $rel — $fn()");
            }
        }
        // 토큰 화이트리스트 — 프로덕션 LanguagePackPhpArrayValidator 의 근사 검증
        $allowedTokens = [T_OPEN_TAG, T_RETURN, T_ARRAY, T_CONSTANT_ENCAPSED_STRING, T_DOUBLE_ARROW,
            T_LNUMBER, T_DNUMBER, T_WHITESPACE, T_STRING, T_DECLARE, T_COMMENT, T_DOC_COMMENT];
        $allowedIdent = ['true', 'false', 'null', 'strict_types'];
        foreach (token_get_all($raw) as $t) {
            if (is_array($t)) {
                if (! in_array($t[0], $allowedTokens, true)) {
                    $r->fail('security', "$rel: 허용되지 않은 토큰 ".token_name($t[0]));
                } elseif ($t[0] === T_STRING && ! in_array(strtolower($t[1]), $allowedIdent, true)) {
                    $r->fail('security', "$rel: 허용되지 않은 식별자 ".$t[1]);
                }
            } elseif (! in_array($t, ['[', ']', '(', ')', ',', ';', '=', '<', '>'], true)) {
                $r->fail('security', "$rel: 허용되지 않은 문자 ".$t);
            }
        }
    }
}

/**
 * 검사 결과를 출력하고 위반 건수를 반환한다.
 */
function zhcnPrintReport(string $target, ZhCnParityReport $r, bool $verbose, bool $styleMode): int
{
    $packId = "g7-plugin-$target-zh-CN";
    echo "=== $packId parity check ===\n";
    printf("검사 파일 %d개 (PHP %d / JSON %d), 대조 키 %d개\n\n",
        $r->stats['files'], $r->stats['php_files'], $r->stats['json_files'], $r->stats['keys']);

    foreach (zhcnPluginCategories() as $key => $label) {
        $list = $r->violations[$key] ?? [];
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
        if ($r->styleWarnings === []) {
            echo "없음\n";
        } else {
            foreach ($r->styleWarnings as $w) {
                echo "    ~ $w\n";
            }
            printf("    총 %d건\n", count($r->styleWarnings));
        }
    }

    $total = $r->total();
    echo "\nRESULT: ".($total === 0 ? 'PASS — 위반 0건' : "FAIL — 총 $total 건")."\n";

    return $total;
}

/**
 * 플러그인별 진입점이 공통으로 쓰는 실행 헬퍼.
 *
 * @param  array<string, mixed>  $intentional
 */
function zhcnRunPluginEntrypoint(string $target, array $intentional, array $argv): int
{
    $root = dirname(__DIR__, 3);
    $verbose = in_array('--verbose', $argv, true);
    $styleMode = in_array('--style', $argv, true);

    $report = zhcnPluginParityCheck($root, $target, $intentional, $styleMode);

    return zhcnPrintReport($target, $report, $verbose, $styleMode) === 0 ? 0 : 1;
}
