<?php

namespace Tests\Unit\Services\LanguagePack;

use App\Services\LanguagePack\LanguagePackManifestValidator;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 공식 템플릿 중국어 간체 번들 언어팩(g7-template-sirsoft-*-zh-CN)의 무결성 검증.
 *
 * `BundledSimplifiedChinesePagePackTest`(module 스코프), `BundledSimplifiedChinesePluginPacksTest`
 * (plugin 스코프)와 같은 계약을 template 스코프에 적용한다. `BundledJapanesePacksTest` 는 검사
 * 대상을 상수 배열로 나열하므로 새 로케일 팩을 자동으로 포함하지 않는다 — manifest 가 깨져도
 * 오류 없이 릴리즈까지 통과한다. 이 테스트가 그 사각을 템플릿 zh-CN 팩 2종에 대해 닫는다.
 *
 * 템플릿은 모듈·플러그인과 자산 배치가 다르다:
 *  - 번역 원본이 `templates/_bundled/{target}/lang/ko.json` + `lang/partial/ko/*.json` 에만 있다
 *  - backend PHP 번역이 없다 (프런트엔드 전용)
 * 따라서 backend 관련 검사 대신 "backend 디렉터리가 없어야 한다"를 계약으로 고정한다.
 *
 * 검증 대상:
 *  1. manifest 가 production validator(LanguagePackManifestValidator)를 통과
 *  2. manifest 필드가 template 스코프 zh-CN 팩 사양과 일치 (target/의존성/네이밍 공식)
 *  3. 대상 템플릿(template.json)이 저장소에 실재하고 vendor/license 가 일치
 *  4. frontend/seed 파일이 실제로 존재하고 backend 디렉터리는 없음
 *  5. frontend JSON 키 셋·키 순서가 템플릿 한국어 원본과 완전히 대칭
 *  6. 번역 값에 한국어(원본 로케일) 문자가 잔존하지 않음
 *  7. 번역 값에 일본어 가나가 섞이지 않음 (ja 팩 복사 방지)
 *  8. frontend 의 `$partial` 디렉티브가 실재 파일을 가리킴
 *  9. placeholder 종류·개수가 한국어 원본과 정확히 일치
 * 10. 팩 안에 허용 확장자(.json/.md) 외 파일이나 심볼릭 링크가 없음
 *
 * 이 테스트는 파일 시스템만 읽는다 — DB 를 만들거나 바꾸지 않는다.
 * 번들 미배포 상태에서는 자동 스킵 (CI 안전).
 */
class BundledSimplifiedChineseTemplatePacksTest extends TestCase
{
    /** 패키지 로케일 */
    private const LOCALE = 'zh-CN';

    /**
     * 검사 대상 템플릿 팩 목록.
     *
     * `gnuboard7-hello_admin_template` / `gnuboard7-hello_user_template` 은 운영 서버에서
     * 설치·활성 템플릿이 아니며 학습용 샘플이므로 zh-CN 팩을 만들지 않았고, 여기서도 제외한다.
     *
     * @return array<string, array{0: string, 1: string}> [identifier, target]
     */
    public static function templatePackProvider(): array
    {
        return [
            'admin_basic' => ['g7-template-sirsoft-admin_basic-zh-CN', 'sirsoft-admin_basic'],
            'basic' => ['g7-template-sirsoft-basic-zh-CN', 'sirsoft-basic'],
        ];
    }

    /**
     * 패키지 루트 절대경로를 반환합니다.
     */
    private function packageRoot(string $identifier): string
    {
        return base_path('lang-packs/_bundled/'.$identifier);
    }

    /**
     * 대상 템플릿 루트 절대경로를 반환합니다.
     */
    private function templateRoot(string $target): string
    {
        return base_path('templates/_bundled/'.$target);
    }

    /**
     * 번들이 배포되지 않았으면 테스트를 스킵합니다.
     */
    private function skipUnlessBundled(string $identifier): void
    {
        if (! File::isFile($this->packageRoot($identifier).'/language-pack.json')) {
            $this->markTestSkipped($identifier.' 번들이 아직 배포되지 않음');
        }
    }

    /**
     * manifest 를 배열로 읽습니다.
     *
     * @return array<string, mixed>
     */
    private function manifest(string $identifier): array
    {
        $manifest = json_decode(File::get($this->packageRoot($identifier).'/language-pack.json'), true);
        $this->assertIsArray($manifest, 'manifest 가 올바른 JSON 이어야 함');

        return $manifest;
    }

    /**
     * manifest 가 production validator 를 통과하는지 검증합니다.
     *
     * @dataProvider templatePackProvider
     */
    public function test_manifest_passes_production_validator(string $identifier, string $target): void
    {
        $this->skipUnlessBundled($identifier);

        $validator = $this->app->make(LanguagePackManifestValidator::class);

        // 검증 실패 시 ValidationException 이 던져진다 — 통과 자체가 계약이다.
        $validator->validate($this->manifest($identifier), $this->packageRoot($identifier));

        $this->addToAssertionCount(1);
    }

    /**
     * manifest 필드가 template 스코프 zh-CN 팩 사양과 일치하는지 검증합니다.
     *
     * @dataProvider templatePackProvider
     */
    public function test_manifest_fields_match_specification(string $identifier, string $target): void
    {
        $this->skipUnlessBundled($identifier);

        $manifest = $this->manifest($identifier);

        $this->assertSame($identifier, $manifest['identifier']);
        $this->assertSame('g7', $manifest['namespace'], 'namespace=g7 (모든 G7 공식 번들의 공통 prefix)');
        $this->assertSame('sirsoft', $manifest['vendor'], 'vendor 는 대상 템플릿의 vendor 와 같아야 함');
        $this->assertSame('template', $manifest['scope']);
        $this->assertSame($target, $manifest['target_identifier'], 'target 은 실제 템플릿 식별자와 일치해야 함');
        $this->assertSame(self::LOCALE, $manifest['locale']);
        $this->assertSame('Simplified Chinese', $manifest['locale_name']);
        $this->assertSame('简体中文', $manifest['locale_native_name']);
        $this->assertSame('ltr', $manifest['text_direction']);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', (string) $manifest['version'], 'version 은 SemVer');

        // manifest version 과 CHANGELOG 최신 항목이 같아야 한다.
        //
        // 처음에는 '1.0.0' 을 그대로 단언했지만, 그 상수는 팩을 한 번이라도 갱신하면 반드시
        // 틀린다 — 지켜야 하는 계약은 "번들을 고쳤으면 버전과 이력을 함께 올린다" 이다.
        // `language-pack:update` 의 비강제 경로가 버전 비교로 갱신 여부를 판단하므로,
        // 내용만 바뀌고 버전이 그대로면 운영 설치본이 조용히 낡은 채 남는다.
        $changelog = File::get($this->packageRoot($identifier).'/CHANGELOG.md');
        $this->assertSame(
            1,
            preg_match('/^##\s*\[(\d+\.\d+\.\d+)\]/m', $changelog, $cm),
            'CHANGELOG.md 에 최신 버전 항목(## [x.y.z])이 있어야 함'
        );
        $this->assertSame($cm[1], $manifest['version'], 'manifest version 과 CHANGELOG 최신 항목이 일치해야 함');

        // target_version 은 "제약 없음"을 뜻하는 null 이어야 한다.
        // `??` 는 null 을 '없음'으로 취급하므로 array_key_exists 로 확인한다.
        $this->assertIsArray($manifest['requires'] ?? null, 'requires 블록이 필요');
        $this->assertArrayHasKey('target_version', $manifest['requires']);
        $this->assertNull($manifest['requires']['target_version'], 'target_version 은 null (버전 제약 없음)');

        // scope != core 이므로 동일 locale 코어 팩 활성이 전제여야 한다
        // (LanguagePackService::resolveCoreLocaleBlockedReason 이 강제).
        $this->assertTrue(
            $manifest['requires']['depends_on_core_locale'],
            'template 스코프 팩은 동일 locale 코어 팩에 의존해야 한다'
        );

        // 네이밍 공식: {namespace}-{scope}-{target}-{locale}
        $this->assertSame(
            sprintf('%s-%s-%s-%s', $manifest['namespace'], $manifest['scope'], $manifest['target_identifier'], $manifest['locale']),
            $manifest['identifier']
        );

        foreach (['name', 'description'] as $field) {
            $this->assertIsArray($manifest[$field], "$field 는 다국어 객체");
            foreach (['ko', 'en', self::LOCALE] as $locale) {
                $this->assertArrayHasKey($locale, $manifest[$field], "$field 에 $locale 키 필요");
                $this->assertNotEmpty($manifest[$field][$locale], "$field.$locale 이 비어 있음");
            }
        }
    }

    /**
     * 대상 템플릿이 저장소에 실재하고 vendor/license 가 팩과 일치하는지 검증합니다.
     *
     * 대상 확장이 없으면 설치 자체가 `target_not_installed` 로 차단되므로,
     * 식별자 오타를 배포 전에 잡는다.
     *
     * @dataProvider templatePackProvider
     */
    public function test_target_template_exists_and_metadata_matches(string $identifier, string $target): void
    {
        $this->skipUnlessBundled($identifier);

        $templateJson = $this->templateRoot($target).'/template.json';
        $this->assertTrue(File::isFile($templateJson), '대상 템플릿 manifest 가 존재해야 함');

        $template = json_decode(File::get($templateJson), true);
        $this->assertIsArray($template);
        $this->assertSame($target, $template['identifier'], 'template.json 의 identifier 와 target 이 일치해야 함');

        $manifest = $this->manifest($identifier);
        $this->assertSame($template['vendor'], $manifest['vendor'], 'vendor 가 대상 템플릿과 일치해야 함');
        $this->assertSame($template['license'], $manifest['license'], 'license 가 대상 템플릿과 일치해야 함');
    }

    /**
     * frontend/seed 콘텐츠 파일이 존재하고 backend 디렉터리는 없는지 검증합니다.
     *
     * 템플릿은 backend PHP 번역을 갖지 않는다. backend 디렉터리가 생기면 설치 시
     * PHP 가 require 되므로, 애초에 없어야 한다는 계약을 여기서 고정한다.
     *
     * @dataProvider templatePackProvider
     */
    public function test_content_files_exist_and_no_backend_directory(string $identifier, string $target): void
    {
        $this->skipUnlessBundled($identifier);

        $root = $this->packageRoot($identifier);

        $this->assertTrue(
            File::isFile($root.'/frontend/'.self::LOCALE.'.json'),
            'frontend/'.self::LOCALE.'.json 엔트리가 있어야 함'
        );
        $this->assertNotEmpty(
            File::glob($root.'/frontend/partial/*.json') ?: [],
            'frontend/partial 에 부분 번역이 있어야 함'
        );
        $this->assertTrue(File::isFile($root.'/seed/manifest.json'), 'seed/manifest.json 이 있어야 함');
        $this->assertTrue(File::isFile($root.'/CHANGELOG.md'), 'CHANGELOG.md 가 있어야 함');

        $this->assertFalse(
            File::isDirectory($root.'/backend'),
            '템플릿 팩에는 backend PHP 번역이 없어야 함'
        );

        $seed = json_decode(File::get($root.'/seed/manifest.json'), true);
        $this->assertSame(['name', 'description'], array_keys($seed), 'seed manifest 는 name/description 만 가진다');
    }

    /**
     * frontend JSON 키 셋과 키 순서가 템플릿 한국어 원본과 대칭인지 검증합니다.
     *
     * 키가 어긋나면 해당 화면만 조용히 기준 로케일로 폴백해 오류 없이 드러나지 않는다.
     * 원본의 `$partial` 경로는 `partial/ko/x.json`, 팩은 `partial/x.json` 으로 정규화되므로
     * 키 이름만 비교한다 (값 비교는 placeholder 검사에서 별도로 한다).
     *
     * @dataProvider templatePackProvider
     */
    public function test_frontend_keys_match_korean_origin(string $identifier, string $target): void
    {
        $this->skipUnlessBundled($identifier);

        $koEntry = $this->templateRoot($target).'/lang/ko.json';
        $zhEntry = $this->packageRoot($identifier).'/frontend/'.self::LOCALE.'.json';

        $this->assertTrue(File::isFile($koEntry), '템플릿 한국어 엔트리가 있어야 함');
        $this->assertTrue(File::isFile($zhEntry));

        $this->assertSame(
            $this->flattenKeys(json_decode(File::get($koEntry), true)),
            $this->flattenKeys(json_decode(File::get($zhEntry), true)),
            '엔트리 파일 키 셋·순서가 일치해야 함'
        );

        $koPartialDir = $this->templateRoot($target).'/lang/partial/ko';
        $zhPartialDir = $this->packageRoot($identifier).'/frontend/partial';

        $koPartials = $this->collectRelativeJson($koPartialDir);
        $zhPartials = $this->collectRelativeJson($zhPartialDir);

        $this->assertSame($koPartials, $zhPartials, 'partial 파일 목록이 1:1 대응해야 함');

        foreach ($koPartials as $rel) {
            $this->assertSame(
                $this->flattenKeys(json_decode(File::get($koPartialDir.'/'.$rel), true)),
                $this->flattenKeys(json_decode(File::get($zhPartialDir.'/'.$rel), true)),
                "[partial/$rel] 키 셋·순서가 한국어 원본과 일치해야 함"
            );
        }
    }

    /**
     * 번역 값에 한국어(원본 로케일) 문자가 남아 있지 않은지 검증합니다.
     *
     * 용어집에 없는 도메인 용어는 어간만 번역되어 원본 문자가 값에 그대로 남는다.
     * 예외도 오류도 나지 않고 키 대칭 검사도 값은 보지 않으므로, 그 로케일로 화면을
     * 열기 전까지 드러나지 않는다.
     *
     * @dataProvider templatePackProvider
     */
    public function test_no_korean_characters_remain_in_translated_values(string $identifier, string $target): void
    {
        $this->skipUnlessBundled($identifier);

        $offenders = [];

        foreach ($this->contentJsonFiles($identifier) as $path) {
            $decoded = json_decode(File::get($path), true);
            $this->assertIsArray($decoded, basename($path).' 가 올바른 JSON 이어야 함');

            foreach ($this->flattenValues($decoded) as $key => $value) {
                if (preg_match('/[\x{AC00}-\x{D7A3}\x{1100}-\x{11FF}\x{3130}-\x{318F}]/u', $value) === 1) {
                    $offenders[] = basename($path)." :: $key";
                }
            }
        }

        $this->assertSame([], $offenders, '번역 값에 한국어 문자가 남아 있습니다');
    }

    /**
     * 번역 값에 일본어 가나가 섞여 있지 않은지 검증합니다.
     *
     * 같은 구조의 ja 팩이 먼저 존재하므로 파일 단위 복사 사고가 실제로 가능하다.
     * 가나가 한 글자라도 남으면 중국어 화면에 일본어가 그대로 노출된다.
     *
     * @dataProvider templatePackProvider
     */
    public function test_no_japanese_kana_remain_in_translated_values(string $identifier, string $target): void
    {
        $this->skipUnlessBundled($identifier);

        $offenders = [];

        foreach ($this->contentJsonFiles($identifier) as $path) {
            $decoded = json_decode(File::get($path), true);
            $this->assertIsArray($decoded);

            foreach ($this->flattenValues($decoded) as $key => $value) {
                if (preg_match('/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}]/u', $value) === 1) {
                    $offenders[] = basename($path)." :: $key";
                }
            }
        }

        $this->assertSame([], $offenders, '번역 값에 일본어 가나가 남아 있습니다');
    }

    /**
     * frontend 엔트리의 `$partial` 디렉티브가 실재 파일을 가리키는지 검증합니다.
     *
     * @dataProvider templatePackProvider
     */
    public function test_partial_directives_resolve_to_existing_files(string $identifier, string $target): void
    {
        $this->skipUnlessBundled($identifier);

        $frontendDir = $this->packageRoot($identifier).'/frontend';
        $entry = $frontendDir.'/'.self::LOCALE.'.json';

        $this->assertTrue(File::isFile($entry));

        $data = json_decode(File::get($entry), true);
        $this->assertIsArray($data);

        $missing = [];
        $walk = function ($node) use (&$walk, $frontendDir, &$missing): void {
            if (! is_array($node)) {
                return;
            }
            foreach ($node as $key => $value) {
                if ($key === '$partial' && is_string($value)) {
                    if (! File::isFile($frontendDir.'/'.$value)) {
                        $missing[] = $value;
                    }

                    continue;
                }
                $walk($value);
            }
        };
        $walk($data);

        $this->assertSame([], $missing, '$partial 이 가리키는 파일이 없습니다');
    }

    /**
     * 번역 값의 placeholder 집합이 한국어 원본과 정확히 일치하는지 검증합니다.
     *
     * 키 대칭 검사는 값을 보지 않는다. `{{count}}` 를 `{count}` 로 바꿔 써도 예외가
     * 나지 않고, 화면에 치환되지 않은 원문이나 빈 자리가 그대로 노출된다.
     * 종류뿐 아니라 개수까지 비교한다.
     *
     * @dataProvider templatePackProvider
     */
    public function test_placeholders_match_korean_origin(string $identifier, string $target): void
    {
        $this->skipUnlessBundled($identifier);

        $mismatches = [];

        $pairs = [
            self::LOCALE.'.json' => [
                $this->templateRoot($target).'/lang/ko.json',
                $this->packageRoot($identifier).'/frontend/'.self::LOCALE.'.json',
            ],
        ];

        $koPartialDir = $this->templateRoot($target).'/lang/partial/ko';
        foreach ($this->collectRelativeJson($koPartialDir) as $rel) {
            $pairs['partial/'.$rel] = [
                $koPartialDir.'/'.$rel,
                $this->packageRoot($identifier).'/frontend/partial/'.$rel,
            ];
        }

        foreach ($pairs as $label => [$koPath, $zhPath]) {
            $ko = $this->flattenValues(json_decode(File::get($koPath), true));
            $zh = $this->flattenValues(json_decode(File::get($zhPath), true));

            foreach ($ko as $key => $value) {
                // `$partial` 은 경로 정규화(partial/ko/x.json → partial/x.json) 대상이라 제외.
                if (str_ends_with($key, '$partial')) {
                    continue;
                }
                if ($this->placeholderCounts($value) !== $this->placeholderCounts($zh[$key] ?? '')) {
                    $mismatches[] = "$label :: $key";
                }
            }
        }

        $this->assertSame([], $mismatches, 'placeholder 종류·개수가 원본과 다릅니다');
    }

    /**
     * 팩 안에 허용 확장자 외 파일이나 심볼릭 링크가 없는지 검증합니다.
     *
     * 설치 파이프라인은 `.php/.json/.md` 만 허용하고 심볼릭 링크를 거부한다.
     * 배포 전에 같은 계약을 걸어 릴리즈 후 설치 실패를 막는다.
     *
     * @dataProvider templatePackProvider
     */
    public function test_pack_contains_only_allowed_files(string $identifier, string $target): void
    {
        $this->skipUnlessBundled($identifier);

        $root = $this->packageRoot($identifier);
        $violations = [];

        foreach (File::allFiles($root) as $file) {
            $rel = str_replace('\\', '/', $file->getRelativePathname());

            if (is_link($file->getPathname())) {
                $violations[] = "symlink: $rel";

                continue;
            }
            if (! in_array(strtolower($file->getExtension()), ['json', 'md'], true)) {
                $violations[] = "확장자 불허: $rel";
            }
        }

        $this->assertSame([], $violations, '팩에 허용되지 않은 파일이 있습니다');
    }

    /**
     * 문자열의 placeholder 를 종류별 등장 횟수 맵으로 반환합니다.
     *
     * @return array<string, int>
     */
    private function placeholderCounts(string $value): array
    {
        $counts = [];

        $bump = function (string $token) use (&$counts): void {
            $counts[$token] = ($counts[$token] ?? 0) + 1;
        };

        if (preg_match_all('/\{\{\s*[A-Za-z0-9_.]+\s*\}\}/u', $value, $m)) {
            foreach ($m[0] as $t) {
                $bump('MUSTACHE:'.preg_replace('/\s+/', '', $t));
            }
        }
        $stripped = preg_replace('/\{\{\s*[A-Za-z0-9_.]+\s*\}\}/u', '', $value);

        if (preg_match_all('/\{[A-Za-z0-9_.]+\}/u', $stripped, $m)) {
            foreach ($m[0] as $t) {
                $bump('BRACE:'.$t);
            }
        }
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
     * 디렉토리 아래 모든 *.json 을 정렬된 상대경로 목록으로 수집합니다 (재귀).
     *
     * @return array<int, string>
     */
    private function collectRelativeJson(string $dir): array
    {
        if (! File::isDirectory($dir)) {
            return [];
        }

        $out = [];
        foreach (File::allFiles($dir) as $file) {
            if (strtolower($file->getExtension()) !== 'json') {
                continue;
            }
            $out[] = str_replace('\\', '/', $file->getRelativePathname());
        }
        sort($out);

        return $out;
    }

    /**
     * 팩 안의 모든 콘텐츠 JSON 경로를 반환합니다 (frontend + seed).
     *
     * @return array<int, string>
     */
    private function contentJsonFiles(string $identifier): array
    {
        $root = $this->packageRoot($identifier);

        $frontend = File::isDirectory($root.'/frontend')
            ? array_map(
                static fn ($f) => $f->getPathname(),
                array_filter(File::allFiles($root.'/frontend'), static fn ($f) => strtolower($f->getExtension()) === 'json')
            )
            : [];

        return array_merge(array_values($frontend), File::glob($root.'/seed/*.json') ?: []);
    }

    /**
     * 중첩 배열을 dot-path 키 목록으로 평탄화합니다.
     *
     * @param  mixed  $node
     * @return array<int, string>
     */
    private function flattenKeys($node, string $prefix = ''): array
    {
        if (! is_array($node)) {
            return [$prefix];
        }

        $keys = [];
        foreach ($node as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $keys = array_merge($keys, $this->flattenKeys($value, $path));
        }

        return $keys;
    }

    /**
     * 중첩 배열을 dot-path ⇒ 문자열 값 맵으로 평탄화합니다 (문자열 값만).
     *
     * @param  mixed  $node
     * @return array<string, string>
     */
    private function flattenValues($node, string $prefix = ''): array
    {
        if (! is_array($node)) {
            return is_string($node) ? [$prefix => $node] : [];
        }

        $out = [];
        foreach ($node as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $out += $this->flattenValues($value, $path);
        }

        return $out;
    }
}
