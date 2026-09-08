<?php

/**
 * 템플릿 zh-CN 언어팩 ↔ 한국어 원본 정합성 검사 라이브러리 (standalone).
 *
 * 용도
 * ----
 * 템플릿 팩은 플러그인 팩과 **원본 경로만** 다르다.
 *   - 플러그인: `plugins/_bundled/{id}/lang/ko/*.php` + `resources/lang/ko.json`
 *   - 템플릿  : `templates/_bundled/{id}/lang/ko.json` + `lang/partial/ko/*.json` (backend PHP 없음)
 *
 * 검사 규칙 자체(평탄화·키 순서·placeholder·HTML·URL·잔존 문자·인코딩·보안)는 동일하므로
 * `zh-CN-plugin-parity-lib.php` 의 순수 헬퍼를 그대로 재사용하고, 이 파일은 **템플릿 레이아웃
 * 전용 진입 함수와 manifest 계약**만 추가한다. 규칙을 복사하면 갈라진다.
 *
 * Laravel 부팅도 Composer autoload 도 필요하지 않아 vendor/ 가 없는 환경에서도 단독 실행된다.
 *
 * @see zh-CN-plugin-parity-lib.php 공용 헬퍼 (flatten/keyOrder/placeholders/htmlTags/urls/보안 등)
 * @see zh-CN-templates-parity-check.php 실행 진입점
 */
declare(strict_types=1);

require_once __DIR__.'/zh-CN-plugin-parity-lib.php';

/**
 * 템플릿 팩 하나를 검사하고 결과 리포트를 돌려준다.
 *
 * @param  array<string, mixed>  $intentional  ja 대비 의도적 차이 선언
 *                                             - seed_extras / seed_missing: seed 파일 ⇒ dot-path 목록
 *                                             - ja_only_partials: ja 에만 있는 partial 파일명
 *                                             - url_exempt: URL 대조를 면제할 "라벨 :: dot-path"
 */
function zhcnTemplateParityCheck(string $root, string $target, array $intentional = [], bool $styleMode = false): ZhCnParityReport
{
    $r = new ZhCnParityReport;
    $locale = 'zh-CN';
    $packId = "g7-template-$target-$locale";
    $packRoot = "$root/lang-packs/_bundled/$packId";
    $jaPackRoot = "$root/lang-packs/_bundled/g7-template-$target-ja";
    $templateRoot = "$root/templates/_bundled/$target";
    $urlExempt = $intentional['url_exempt'] ?? [];

    if (! is_dir($packRoot)) {
        $r->fail('structure', "팩 디렉토리가 없습니다: lang-packs/_bundled/$packId");

        return $r;
    }
    if (! is_dir($templateRoot)) {
        $r->fail('structure', "대상 템플릿이 없습니다: templates/_bundled/$target");

        return $r;
    }

    // ── 1. 템플릿에는 backend PHP 번역이 없다 — 팩에도 있으면 안 된다 ──
    $zhBackend = is_dir("$packRoot/backend") ? zhcnCollectFiles("$packRoot/backend") : [];
    foreach ($zhBackend as $rel) {
        $r->fail('extra_files', "backend/$rel — 템플릿 원본에는 backend 번역이 없습니다");
    }

    // ── 2. frontend 엔트리 — ko 원본 대조 ─────────────────────────────
    $koEntry = "$templateRoot/lang/ko.json";
    $zhEntry = "$packRoot/frontend/$locale.json";

    if (! is_file($koEntry)) {
        $r->fail('structure', "ko 원본 엔트리가 없습니다: templates/_bundled/$target/lang/ko.json");
    } elseif (! is_file($zhEntry)) {
        $r->fail('missing_files', "frontend/$locale.json");
    } else {
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

    // ── 3. frontend partial — ko 원본 대조 ────────────────────────────
    $koPartialDir = "$templateRoot/lang/partial/ko";
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

    // ── 4. $partial 경로 실재 확인 ────────────────────────────────────
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

    // ── 5. seed JSON — ja 팩 대조 ─────────────────────────────────────
    // seed 원본은 template.json 의 name/description 이라 기계적 1:1 대조 대상이 아니다.
    // 동일 원본에서 생성된 산출물이므로 ja 팩과 키 집합이 같아야 한다.
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
        if ($allowedExtras === [] && $allowedMissing === [] && array_keys($jaFlat) !== array_keys($zhFlat)) {
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
        }

        $r->stats['files']++;
        $r->stats['json_files']++;
    }

    // ── 6. ja 팩과의 구조 대조 (드리프트 가시화) ──────────────────────
    // 이 팩의 정합 기준은 어디까지나 ko 원본이다. 다만 ja 팩과의 파일 구성 차이는
    // "ja 가 낡았는가 / zh 가 빠뜨렸는가" 를 사람이 판단할 수 있도록 표면화한다.
    $jaOnly = $intentional['ja_only_partials'] ?? [];
    $jaPartials = zhcnCollectJson("$jaPackRoot/frontend/partial");
    foreach (array_diff($jaPartials, $zhPartials) as $onlyJa) {
        if (in_array($onlyJa, $jaOnly, true)) {
            continue;
        }
        $r->fail('ja_structure', "ja 팩에만 있는 partial: $onlyJa (ko 원본 확인 필요)");
    }
    foreach (array_diff($zhPartials, $jaPartials) as $onlyZh) {
        $r->fail('ja_structure', "zh 팩에만 있는 partial: $onlyZh (ja 팩이 낡았는지 확인 필요)");
    }

    // ── 6-b. ja 팩과의 키 순서 드리프트 (정보성 — 실패 아님) ──────────
    if ($styleMode) {
        $pairs = ["frontend/$locale.json" => [$koEntry, "$jaPackRoot/frontend/ja.json"]];
        foreach ($koPartials as $rel) {
            $pairs["frontend/partial/$rel"] = ["$koPartialDir/$rel", "$jaPackRoot/frontend/partial/$rel"];
        }
        foreach ($pairs as $label => [$koPath, $jaPath]) {
            if (! is_file($koPath) || ! is_file($jaPath)) {
                continue;
            }
            $koKeys = array_keys(zhcnFlatten(json_decode((string) file_get_contents($koPath), true)));
            $jaKeys = array_keys(zhcnFlatten(json_decode((string) file_get_contents($jaPath), true)));
            $jaOnlyKeys = array_values(array_diff($jaKeys, $koKeys));
            $koOnlyKeys = array_values(array_diff($koKeys, $jaKeys));
            if ($jaOnlyKeys !== []) {
                $r->styleWarnings[] = "$label — ja 팩에만 있는 키 ".count($jaOnlyKeys).'건 (ko 원본에서 제거됨): '
                    .implode(', ', array_slice($jaOnlyKeys, 0, 3)).(count($jaOnlyKeys) > 3 ? ' …' : '');
            }
            if ($koOnlyKeys !== []) {
                $r->styleWarnings[] = "$label — ko 원본에만 있는 키 ".count($koOnlyKeys).'건 (ja 팩이 낡음): '
                    .implode(', ', array_slice($koOnlyKeys, 0, 3)).(count($koOnlyKeys) > 3 ? ' …' : '');
            }
            if ($jaOnlyKeys === [] && $koOnlyKeys === [] && $koKeys !== $jaKeys) {
                $r->styleWarnings[] = "$label — ja 팩과 키 순서가 다름 (키 집합은 동일). zh 는 ko 순서를 따른다";
            }
        }
    }

    // ── 7. manifest 계약 ──────────────────────────────────────────────
    zhcnCheckTemplateManifest($r, $packRoot, $templateRoot, $packId, $target, $locale);

    // ── 8. 보안 규칙 (플러그인 팩과 동일 계약) ────────────────────────
    zhcnCheckSecurity($r, $packRoot, $locale);

    // ── 9. CHANGELOG 인코딩 ───────────────────────────────────────────
    if (is_file("$packRoot/CHANGELOG.md")) {
        zhcnCheckEncoding($r, "$packRoot/CHANGELOG.md", 'CHANGELOG.md');
    } else {
        $r->fail('missing_files', 'CHANGELOG.md');
    }

    return $r;
}

/**
 * 디렉토리 아래 모든 파일을 정렬된 상대경로 목록으로 수집한다 (확장자 무관, 재귀).
 *
 * @return array<int, string>
 */
function zhcnCollectFiles(string $dir): array
{
    if (! is_dir($dir)) {
        return [];
    }

    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (! $file->isFile()) {
            continue;
        }
        $out[] = strtr(substr($file->getPathname(), strlen($dir) + 1), DIRECTORY_SEPARATOR, '/');
    }
    sort($out);

    return $out;
}

/**
 * 템플릿 팩 manifest 의 필수 필드·BCP-47·네이밍 공식·target 일치를 검사한다.
 */
function zhcnCheckTemplateManifest(ZhCnParityReport $r, string $packRoot, string $templateRoot, string $packId, string $target, string $locale): void
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
        $r->fail('manifest', 'identifier 불일치: '.var_export($m['identifier'] ?? null, true));
    }
    if (($m['namespace'] ?? null) !== 'g7') {
        $r->fail('manifest', 'namespace 는 g7 이어야 합니다');
    }
    if (($m['scope'] ?? null) !== 'template') {
        $r->fail('manifest', 'scope 는 template 이어야 합니다');
    }
    if (($m['target_identifier'] ?? null) !== $target) {
        $r->fail('manifest', 'target_identifier 가 템플릿 식별자와 다릅니다');
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
    if (($m['version'] ?? null) !== '1.0.0') {
        $r->fail('manifest', 'version 은 1.0.0 이어야 합니다 (신규 팩)');
    }
    if (! preg_match('/^\d+\.\d+\.\d+$/', (string) ($m['version'] ?? ''))) {
        $r->fail('manifest', 'version 이 SemVer 가 아닙니다');
    }
    if (($m['requires']['depends_on_core_locale'] ?? null) !== true) {
        $r->fail('manifest', 'requires.depends_on_core_locale 는 true 여야 합니다');
    }
    // `??` 는 null 을 "없음" 으로 취급하므로 존재 여부와 값을 따로 본다.
    if (! is_array($m['requires'] ?? null)
        || ! array_key_exists('target_version', $m['requires'])
        || $m['requires']['target_version'] !== null) {
        $r->fail('manifest', 'requires.target_version 은 null 이어야 합니다 (기존 팩 계약)');
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

    // target ↔ template.json identifier / vendor / license
    $templateJson = "$templateRoot/template.json";
    if (! is_file($templateJson)) {
        $r->fail('manifest', "대상 템플릿 manifest 가 없습니다: templates/_bundled/$target/template.json");

        return;
    }
    $t = json_decode((string) file_get_contents($templateJson), true);
    if (($t['identifier'] ?? null) !== $target) {
        $r->fail('manifest', 'template.json 의 identifier 와 target_identifier 가 다릅니다');
    }
    if (($m['vendor'] ?? null) !== ($t['vendor'] ?? null)) {
        $r->fail('manifest', 'vendor 가 template.json 과 다릅니다');
    }
    if (($m['license'] ?? null) !== ($t['license'] ?? null)) {
        $r->fail('manifest', 'license 가 template.json 과 다릅니다');
    }
}
