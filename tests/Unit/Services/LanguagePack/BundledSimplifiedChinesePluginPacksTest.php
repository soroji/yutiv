<?php

namespace Tests\Unit\Services\LanguagePack;

use App\Services\LanguagePack\LanguagePackManifestValidator;
use App\Services\LanguagePack\LanguagePackPhpArrayValidator;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 공식 플러그인 중국어 간체 번들 언어팩 11종의 무결성 검증.
 *
 * `BundledSimplifiedChinesePackTest`(코어)·`...BoardPackTest`·`...EcommercePackTest`·
 * `...PagePackTest` 와 같은 계약을 plugin 스코프에 적용하되, 대상이 11개이므로
 * **데이터 프로바이더**로 묶는다. 팩마다 별도 테스트 클래스를 복사하면 규칙이 갈라지고,
 * 한 팩만 다르게 검사되는 사각이 생긴다.
 *
 * 데이터셋 이름에 팩 식별자를 넣어 실패 시 어느 팩인지 즉시 드러나게 한다
 * (`--filter` 로 개별 팩만 돌릴 수도 있다).
 *
 * `BundledJapanesePacksTest` 는 검사 대상을 상수 배열로 나열하므로 새 로케일 팩을 자동으로
 * 포함하지 않는다 — manifest 가 깨져도, PHP 가 보안 검사를 통과하지 못해도 오류 없이
 * 릴리즈까지 통과한다. 이 테스트가 그 사각을 플러그인 zh-CN 팩에 대해 닫는다.
 *
 * 검증 대상:
 *  1. manifest 가 production validator(LanguagePackManifestValidator)를 통과
 *  2. manifest 필드가 plugin 스코프 zh-CN 팩 사양과 일치 (target/의존성/네이밍 공식)
 *  3. 번들 팩이 탐색 가능하고 콘텐츠 파일이 실재
 *  4. backend PHP 가 설치 시 보안 검사(LanguagePackPhpArrayValidator)를 통과
 *  5. backend/frontend 키 셋이 플러그인 한국어 원본과 완전히 대칭 (순서 포함)
 *  6. placeholder 종류·개수가 원본과 일치
 *  7. seed 가 ja 팩과 키 대칭 (의도적 차이는 상수로 선언)
 *  8. 번역 값에 한국어·일본어 문자가 잔존하지 않음
 *  9. 언어팩 보안 규칙 (확장자·PHP 위치·심볼릭 링크)
 * 10. 대상 플러그인이 저장소에 실재하고 vendor/license 가 일치
 *
 * 번들 미배포 상태에서는 팩 단위로 자동 스킵 (CI 안전).
 */
class BundledSimplifiedChinesePluginPacksTest extends TestCase
{
    /** 패키지 로케일 */
    private const LOCALE = 'zh-CN';

    /**
     * 검사 대상 — 대상 플러그인 식별자 목록.
     *
     * `gnuboard7-hello_plugin` 은 학습용 샘플 확장이라 공식 언어팩 인벤토리에 없다
     * (ja 팩도 등재되어 있지 않다). 새 플러그인 zh-CN 팩을 추가하면 여기에도 넣어야 한다.
     *
     * @var array<int, string>
     */
    private const TARGETS = [
        'sirsoft-ckeditor5',
        'sirsoft-daum_postcode',
        'sirsoft-gdpr',
        'sirsoft-marketing',
        'sirsoft-message_bizppurio',
        'sirsoft-pay_kginicis',
        'sirsoft-pay_nhnkcp',
        'sirsoft-pay_nicepayments',
        'sirsoft-tosspayments',
        'sirsoft-verification_kginicis',
        'sirsoft-verification_nhnkcp',
    ];

    /**
     * ja 팩 대비 의도적 seed 차이 — 대상 ⇒ [파일 ⇒ 허용 dot-path].
     *
     * 현재는 없다. ko 원본이 바뀌었는데 ja 가 따라오지 못한 자리가 생기면 여기에 명시한다.
     *
     * @var array<string, array<string, array<int, string>>>
     */
    private const INTENTIONAL_SEED_EXTRAS = [];

    /**
     * @return array<string, array<int, string>>
     */
    public static function packProvider(): array
    {
        $out = [];
        foreach (self::TARGETS as $target) {
            $out[$target] = [$target];
        }

        return $out;
    }

    private function packageRoot(string $target): string
    {
        return base_path('lang-packs/_bundled/g7-plugin-'.$target.'-'.self::LOCALE);
    }

    private function jaPackageRoot(string $target): string
    {
        return base_path('lang-packs/_bundled/g7-plugin-'.$target.'-ja');
    }

    private function pluginRoot(string $target): string
    {
        return base_path('plugins/_bundled/'.$target);
    }

    /**
     * 번들이 배포되지 않았으면 해당 팩만 스킵합니다.
     */
    private function skipUnlessBundled(string $target): void
    {
        if (! File::isFile($this->packageRoot($target).'/language-pack.json')) {
            $this->markTestSkipped("g7-plugin-$target-".self::LOCALE.' 번들이 아직 배포되지 않음');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(string $target): array
    {
        $manifest = json_decode(File::get($this->packageRoot($target).'/language-pack.json'), true);
        $this->assertIsArray($manifest, "[$target] manifest 가 올바른 JSON 이어야 함");

        return $manifest;
    }

    /**
     * manifest 가 production validator 를 통과하는지 검증합니다.
     *
     * @dataProvider packProvider
     */
    public function test_manifest_passes_production_validator(string $target): void
    {
        $this->skipUnlessBundled($target);

        $validator = $this->app->make(LanguagePackManifestValidator::class);

        // 검증 실패 시 ValidationException 이 던져진다 — 통과 자체가 계약이다.
        $validator->validate($this->manifest($target), $this->packageRoot($target));

        $this->addToAssertionCount(1);
    }

    /**
     * manifest 필드가 plugin 스코프 zh-CN 팩 사양과 일치하는지 검증합니다.
     *
     * @dataProvider packProvider
     */
    public function test_manifest_fields_match_specification(string $target): void
    {
        $this->skipUnlessBundled($target);

        $manifest = $this->manifest($target);
        $expectedId = 'g7-plugin-'.$target.'-'.self::LOCALE;

        $this->assertSame($expectedId, $manifest['identifier']);
        $this->assertSame('g7', $manifest['namespace'], 'namespace=g7 (모든 G7 공식 번들의 공통 prefix)');
        $this->assertSame('plugin', $manifest['scope']);
        $this->assertSame($target, $manifest['target_identifier']);
        $this->assertSame(self::LOCALE, $manifest['locale']);
        $this->assertSame('Simplified Chinese', $manifest['locale_name']);
        $this->assertSame('简体中文', $manifest['locale_native_name']);
        $this->assertSame('ltr', $manifest['text_direction']);
        $this->assertSame('1.0.0', $manifest['version']);
        $this->assertNull($manifest['requires']['target_version'], 'target_version 은 기존 팩 계약대로 null');

        // scope != core 이므로 동일 locale 코어 팩 활성이 전제여야 한다
        // (LanguagePackService::resolveCoreLocaleBlockedReason 이 강제).
        $this->assertTrue(
            $manifest['requires']['depends_on_core_locale'],
            "[$target] plugin 스코프 팩은 동일 locale 코어 팩에 의존해야 한다"
        );

        // 네이밍 공식: {namespace}-{scope}-{target}-{locale}
        $this->assertSame(
            sprintf('%s-%s-%s-%s', $manifest['namespace'], $manifest['scope'], $manifest['target_identifier'], $manifest['locale']),
            $manifest['identifier']
        );

        foreach (['name', 'description'] as $field) {
            $this->assertIsArray($manifest[$field], "[$target] $field 는 다국어 객체");
            foreach (['ko', 'en', self::LOCALE] as $locale) {
                $this->assertArrayHasKey($locale, $manifest[$field], "[$target] $field 에 $locale 키 필요");
                $this->assertNotEmpty($manifest[$field][$locale], "[$target] $field.$locale 이 비어 있음");
            }
        }
    }

    /**
     * 대상 플러그인이 실재하고 vendor/license 가 일치하는지 검증합니다.
     *
     * 대상 확장이 없으면 설치 자체가 `target_not_installed` 로 차단되므로,
     * 식별자 오타를 배포 전에 잡는다.
     *
     * @dataProvider packProvider
     */
    public function test_target_plugin_exists_and_metadata_matches(string $target): void
    {
        $this->skipUnlessBundled($target);

        $pluginJson = $this->pluginRoot($target).'/plugin.json';
        $this->assertTrue(File::isFile($pluginJson), "[$target] 대상 플러그인 manifest 가 존재해야 함");

        $plugin = json_decode(File::get($pluginJson), true);
        $this->assertIsArray($plugin);
        $this->assertSame($target, $plugin['identifier'], "[$target] plugin.json 의 identifier 와 target 이 일치해야 함");

        $manifest = $this->manifest($target);
        $this->assertSame($plugin['vendor'], $manifest['vendor'], "[$target] vendor 가 plugin.json 과 일치해야 함");
        $this->assertSame($plugin['license'], $manifest['license'], "[$target] license 가 plugin.json 과 일치해야 함");
    }

    /**
     * 콘텐츠 파일이 실제로 존재하는지 검증합니다.
     *
     * @dataProvider packProvider
     */
    public function test_content_files_exist(string $target): void
    {
        $this->skipUnlessBundled($target);

        $root = $this->packageRoot($target);

        $this->assertTrue(
            File::isFile($root.'/frontend/'.self::LOCALE.'.json'),
            "[$target] frontend/".self::LOCALE.'.json 엔트리가 있어야 함'
        );
        $this->assertNotEmpty(File::glob($root.'/seed/*.json') ?: [], "[$target] seed 에 초기 데이터 번역이 있어야 함");
        $this->assertTrue(File::isFile($root.'/CHANGELOG.md'), "[$target] CHANGELOG.md 가 있어야 함");

        // ko 원본에 backend 가 있으면 팩에도 있어야 한다.
        if (File::isDirectory($this->pluginRoot($target).'/lang/ko')) {
            $this->assertNotEmpty(
                File::glob($root.'/backend/'.self::LOCALE.'/*.php') ?: [],
                "[$target] backend/".self::LOCALE.' 에 PHP 번역 파일이 있어야 함'
            );
        }
    }

    /**
     * backend PHP 파일이 설치 시 보안 검사를 통과하는지 검증합니다.
     *
     * 언어팩의 PHP 는 활성화되면 require 되므로, 번역 배열 외의 코드가 섞이면 설치가
     * 거부된다. 배포 전에 같은 검사를 걸어 릴리즈 후 발견을 막는다.
     *
     * @dataProvider packProvider
     */
    public function test_backend_php_files_are_literal_translation_arrays(string $target): void
    {
        $this->skipUnlessBundled($target);

        $files = File::glob($this->packageRoot($target).'/backend/'.self::LOCALE.'/*.php') ?: [];
        if ($files === []) {
            $this->addToAssertionCount(1); // backend 번역이 없는 팩 (daum_postcode)
            return;
        }

        foreach ($files as $path) {
            $violation = LanguagePackPhpArrayValidator::inspectFile($path);

            $this->assertNull(
                $violation,
                sprintf(
                    '[%s] %s 는 번역 배열만 담아야 합니다 (%s, line %d)',
                    $target,
                    basename($path),
                    $violation['reason'] ?? '',
                    $violation['line'] ?? 0
                )
            );
        }
    }

    /**
     * 번역 키 셋·순서가 플러그인 한국어 원본과 완전히 대칭인지 검증합니다.
     *
     * 키가 어긋나면 해당 화면만 조용히 기준 로케일로 폴백해 오류 없이 드러나지 않는다.
     *
     * @dataProvider packProvider
     */
    public function test_keys_match_korean_origin(string $target): void
    {
        $this->skipUnlessBundled($target);

        $koDir = $this->pluginRoot($target).'/lang/ko';
        $zhDir = $this->packageRoot($target).'/backend/'.self::LOCALE;

        $koFiles = File::isDirectory($koDir) ? array_map('basename', File::glob($koDir.'/*.php') ?: []) : [];
        $zhFiles = File::isDirectory($zhDir) ? array_map('basename', File::glob($zhDir.'/*.php') ?: []) : [];
        sort($koFiles);
        sort($zhFiles);

        $this->assertSame($koFiles, $zhFiles, "[$target] backend 파일 목록이 플러그인 ko lang 과 1:1 대응해야 함");

        foreach ($koFiles as $name) {
            $koKeys = $this->flattenKeys(require $koDir.'/'.$name);
            $zhKeys = $this->flattenKeys(require $zhDir.'/'.$name);

            $this->assertSame($koKeys, $zhKeys, "[$target][$name] 키 셋·순서가 원본과 일치해야 함");
        }

        $koEntry = $this->pluginRoot($target).'/resources/lang/ko.json';
        $zhEntry = $this->packageRoot($target).'/frontend/'.self::LOCALE.'.json';
        $this->assertTrue(File::isFile($koEntry), "[$target] ko.json 원본이 있어야 함");
        $this->assertTrue(File::isFile($zhEntry), "[$target] frontend 엔트리가 있어야 함");

        $this->assertSame(
            $this->flattenKeys(json_decode(File::get($koEntry), true)),
            $this->flattenKeys(json_decode(File::get($zhEntry), true)),
            "[$target] frontend 키 셋·순서가 원본과 일치해야 함"
        );
    }

    /**
     * 번역 값의 placeholder 집합이 한국어 원본과 정확히 일치하는지 검증합니다.
     *
     * 키 대칭 검사는 값을 보지 않는다. `:count` 를 빠뜨리거나 `{{version}}` 를
     * `{version}` 으로 바꿔 써도 예외가 나지 않고, 화면에 치환되지 않은 원문이나
     * 빈 자리가 그대로 노출된다. 종류뿐 아니라 개수까지 비교한다.
     *
     * @dataProvider packProvider
     */
    public function test_placeholders_match_korean_origin(string $target): void
    {
        $this->skipUnlessBundled($target);

        $mismatches = [];

        $koDir = $this->pluginRoot($target).'/lang/ko';
        $zhDir = $this->packageRoot($target).'/backend/'.self::LOCALE;
        foreach (File::isDirectory($koDir) ? array_map('basename', File::glob($koDir.'/*.php') ?: []) : [] as $name) {
            $ko = $this->flattenValues(require $koDir.'/'.$name);
            $zh = $this->flattenValues(require $zhDir.'/'.$name);
            foreach ($ko as $key => $value) {
                if ($this->placeholderCounts($value) !== $this->placeholderCounts($zh[$key] ?? '')) {
                    $mismatches[] = "$name :: $key";
                }
            }
        }

        $ko = $this->flattenValues(json_decode(File::get($this->pluginRoot($target).'/resources/lang/ko.json'), true));
        $zh = $this->flattenValues(json_decode(File::get($this->packageRoot($target).'/frontend/'.self::LOCALE.'.json'), true));
        foreach ($ko as $key => $value) {
            if ($this->placeholderCounts($value) !== $this->placeholderCounts($zh[$key] ?? '')) {
                $mismatches[] = "ko.json :: $key";
            }
        }

        $this->assertSame([], $mismatches, "[$target] placeholder 종류·개수가 원본과 다릅니다");
    }

    /**
     * seed 번역이 ja 팩과 키 대칭인지 검증합니다.
     *
     * seed 원본은 plugin.php / plugin.json 에 분산되어 기계적 1:1 대조 대상이 아니다.
     * 동일 원본에서 생성된 산출물이므로 ja 팩과 키 집합이 같아야 하며, 예외는
     * `INTENTIONAL_SEED_EXTRAS` 에 명시된 항목뿐이다.
     *
     * @dataProvider packProvider
     */
    public function test_seed_keys_match_japanese_pack(string $target): void
    {
        $this->skipUnlessBundled($target);

        $jaSeedDir = $this->jaPackageRoot($target).'/seed';
        $zhSeedDir = $this->packageRoot($target).'/seed';

        $jaSeeds = File::isDirectory($jaSeedDir) ? array_map('basename', File::glob($jaSeedDir.'/*.json') ?: []) : [];
        $zhSeeds = File::isDirectory($zhSeedDir) ? array_map('basename', File::glob($zhSeedDir.'/*.json') ?: []) : [];
        sort($jaSeeds);
        sort($zhSeeds);

        $this->assertSame($jaSeeds, $zhSeeds, "[$target] seed 파일 목록이 ja 팩과 1:1 대응해야 함");

        foreach ($jaSeeds as $name) {
            $jaKeys = $this->flattenKeys(json_decode(File::get($jaSeedDir.'/'.$name), true));
            $zhKeys = $this->flattenKeys(json_decode(File::get($zhSeedDir.'/'.$name), true));
            $allowed = self::INTENTIONAL_SEED_EXTRAS[$target][$name] ?? [];

            $this->assertSame([], array_values(array_diff($jaKeys, $zhKeys)), "[$target][seed/$name] ja 에 있으나 zh 에 없는 키");
            $this->assertSame(
                [],
                array_values(array_diff(array_diff($zhKeys, $jaKeys), $allowed)),
                "[$target][seed/$name] 선언되지 않은 초과 키"
            );
        }
    }

    /**
     * 번역 값에 한국어·일본어 문자가 남아 있지 않은지 검증합니다.
     *
     * 용어집에 없는 도메인 용어는 어간만 번역되어 원본 문자가 값에 그대로 남는다.
     * 예외도 오류도 나지 않고 키 대칭 검사도 값은 보지 않으므로, 그 로케일로 화면을
     * 열기 전까지 드러나지 않는다. ja 팩을 재번역한 흔적(가나)도 함께 잡는다.
     *
     * @dataProvider packProvider
     */
    public function test_no_korean_or_japanese_characters_remain(string $target): void
    {
        $this->skipUnlessBundled($target);

        $root = $this->packageRoot($target);
        $offenders = [];

        foreach (File::glob($root.'/backend/'.self::LOCALE.'/*.php') ?: [] as $path) {
            foreach ($this->flattenValues(require $path) as $key => $value) {
                if ($this->containsHangul($value) || $this->containsKana($value)) {
                    $offenders[] = basename($path)." :: $key";
                }
            }
        }

        $jsonFiles = array_merge(
            [$root.'/frontend/'.self::LOCALE.'.json'],
            File::glob($root.'/seed/*.json') ?: []
        );
        foreach ($jsonFiles as $path) {
            if (! File::isFile($path)) {
                continue;
            }
            $decoded = json_decode(File::get($path), true);
            $this->assertIsArray($decoded, "[$target] ".basename($path).' 가 올바른 JSON 이어야 함');

            foreach ($this->flattenValues($decoded) as $key => $value) {
                if ($this->containsHangul($value) || $this->containsKana($value)) {
                    $offenders[] = basename($path)." :: $key";
                }
            }
        }

        $this->assertSame([], $offenders, "[$target] 번역 값에 한국어·일본어 문자가 남아 있습니다");
    }

    /**
     * 언어팩 보안 규칙(확장자·PHP 위치·심볼릭 링크)을 검증합니다.
     *
     * @dataProvider packProvider
     */
    public function test_security_rules(string $target): void
    {
        $this->skipUnlessBundled($target);

        $root = $this->packageRoot($target);
        $violations = [];

        foreach (File::allFiles($root) as $file) {
            $rel = str_replace('\\', '/', $file->getRelativePathname());
            $ext = strtolower($file->getExtension());

            if (is_link($file->getPathname())) {
                $violations[] = "심볼릭 링크: $rel";
            }
            if (! in_array($ext, ['php', 'json', 'md'], true)) {
                $violations[] = "허용되지 않은 확장자: $rel";
            }
            if ($ext === 'php' && ! preg_match('#^backend/'.preg_quote(self::LOCALE, '#').'/[A-Za-z0-9_-]+\.php$#', $rel)) {
                $violations[] = "PHP 가 backend 번역 경로 밖에 있음: $rel";
            }
        }

        $this->assertSame([], $violations, "[$target] 언어팩 보안 규칙 위반");
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

        if (preg_match_all('/\{\{\s*[A-Za-z0-9_.$]+\s*\}\}/u', $value, $m)) {
            foreach ($m[0] as $t) {
                $bump('MUSTACHE:'.preg_replace('/\s+/', '', $t));
            }
        }
        $stripped = preg_replace('/\{\{\s*[A-Za-z0-9_.$]+\s*\}\}/u', '', $value);

        if (preg_match_all('/\{[A-Za-z0-9_.$]+\}/u', $stripped, $m)) {
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
     * 중첩 배열을 dot-path 키 목록으로 평탄화합니다 (순서 보존).
     *
     * @param  mixed  $node
     * @return array<int, string>
     */
    private function flattenKeys($node, string $prefix = ''): array
    {
        if (! is_array($node) || $node === []) {
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

    /**
     * 문자열에 한글(음절/자모)이 포함되어 있는지 판정합니다.
     */
    private function containsHangul(string $value): bool
    {
        return preg_match('/[\x{AC00}-\x{D7A3}\x{1100}-\x{11FF}\x{3130}-\x{318F}]/u', $value) === 1;
    }

    /**
     * 문자열에 히라가나/가타카나가 포함되어 있는지 판정합니다.
     */
    private function containsKana(string $value): bool
    {
        return preg_match('/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}]/u', $value) === 1;
    }
}
