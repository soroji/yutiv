<?php

namespace Tests\Unit\Services\LanguagePack;

use App\Services\LanguagePack\LanguagePackManifestValidator;
use App\Services\LanguagePack\LanguagePackPhpArrayValidator;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 공식 중국어 간체 번들 언어팩(g7-core-zh-CN)의 무결성 검증.
 *
 * `BundledJapanesePacksTest` 와 같은 계약을 zh-CN 코어 팩에 적용한다. ja 테스트는
 * 식별자 12종을 상수로 나열하므로 새 로케일 팩은 어떤 검사도 받지 못한 채 릴리즈될 수
 * 있다 — manifest 가 깨져도, PHP 파일이 보안 검사를 통과하지 못해도 오류가 나지 않고
 * 설치 시점에야 드러난다. 이 테스트가 그 사각을 zh-CN 에 대해 닫는다.
 *
 * 검증 대상:
 *  1. manifest 가 production validator(LanguagePackManifestValidator)를 통과
 *  2. manifest 필드가 zh-CN 코어 팩 사양과 일치 (locale/scope/네이밍 공식 등)
 *  3. backend/frontend/seed 파일이 실제로 존재
 *  4. backend PHP 가 설치 시 보안 검사(LanguagePackPhpArrayValidator)를 통과
 *  5. backend PHP 키 셋이 한국어 원본(lang/ko)과 완전히 대칭
 *  6. 번역 값에 한국어(원본 로케일) 문자가 잔존하지 않음
 *  7. frontend 의 `$partial` 디렉티브가 실재 파일을 가리킴
 *
 * 번들 미배포 상태에서는 자동 스킵 (CI 안전).
 */
class BundledSimplifiedChinesePackTest extends TestCase
{
    /** 검증 대상 패키지 식별자 */
    private const IDENTIFIER = 'g7-core-zh-CN';

    /** 패키지 로케일 */
    private const LOCALE = 'zh-CN';

    /**
     * 패키지 루트 절대경로를 반환합니다.
     */
    private function packageRoot(): string
    {
        return base_path('lang-packs/_bundled/'.self::IDENTIFIER);
    }

    /**
     * 번들이 배포되지 않았으면 테스트를 스킵합니다.
     */
    private function skipUnlessBundled(): void
    {
        if (! File::isFile($this->packageRoot().'/language-pack.json')) {
            $this->markTestSkipped(self::IDENTIFIER.' 번들이 아직 배포되지 않음');
        }
    }

    /**
     * manifest 를 배열로 읽습니다.
     *
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        $manifest = json_decode(File::get($this->packageRoot().'/language-pack.json'), true);
        $this->assertIsArray($manifest, 'manifest 가 올바른 JSON 이어야 함');

        return $manifest;
    }

    /**
     * manifest 가 production validator 를 통과하는지 검증합니다.
     *
     * @return void
     */
    public function test_manifest_passes_production_validator(): void
    {
        $this->skipUnlessBundled();

        $validator = $this->app->make(LanguagePackManifestValidator::class);

        // 검증 실패 시 ValidationException 이 던져진다 — 통과 자체가 계약이다.
        $validator->validate($this->manifest(), $this->packageRoot());

        $this->addToAssertionCount(1);
    }

    /**
     * manifest 필드가 zh-CN 코어 팩 사양과 일치하는지 검증합니다.
     *
     * @return void
     */
    public function test_manifest_fields_match_specification(): void
    {
        $this->skipUnlessBundled();

        $manifest = $this->manifest();

        $this->assertSame(self::IDENTIFIER, $manifest['identifier']);
        $this->assertSame('g7', $manifest['namespace'], 'namespace=g7 (모든 G7 공식 번들의 공통 prefix)');
        $this->assertNotEmpty($manifest['vendor'], 'vendor 는 제작자 식별자');
        $this->assertSame('core', $manifest['scope']);
        $this->assertNull($manifest['target_identifier'], 'scope=core 는 target_identifier 가 null');
        $this->assertSame(self::LOCALE, $manifest['locale']);
        $this->assertSame('Simplified Chinese', $manifest['locale_name']);
        $this->assertSame('简体中文', $manifest['locale_native_name']);
        $this->assertSame('ltr', $manifest['text_direction']);
        $this->assertFalse(
            $manifest['requires']['depends_on_core_locale'],
            '코어 팩은 다른 코어 로케일에 의존하지 않는다'
        );

        // 네이밍 공식: {namespace}-core-{locale}
        $this->assertSame(
            sprintf('%s-core-%s', $manifest['namespace'], $manifest['locale']),
            $manifest['identifier']
        );

        // name/description 은 다국어 객체 — 최소 ko/en + 자체 로케일
        foreach (['name', 'description'] as $field) {
            $this->assertIsArray($manifest[$field], "$field 는 다국어 객체");
            foreach (['ko', 'en', self::LOCALE] as $locale) {
                $this->assertArrayHasKey($locale, $manifest[$field], "$field 에 $locale 키 필요");
                $this->assertNotEmpty($manifest[$field][$locale], "$field.$locale 이 비어 있음");
            }
        }
    }

    /**
     * backend/frontend/seed 콘텐츠 파일이 실제로 존재하는지 검증합니다.
     *
     * 매니페스트에 `contents` 필드가 없으므로 디스크 스캔이 SSoT 다.
     *
     * @return void
     */
    public function test_content_files_exist(): void
    {
        $this->skipUnlessBundled();

        $root = $this->packageRoot();

        $backend = File::glob($root.'/backend/'.self::LOCALE.'/*.php') ?: [];
        $this->assertNotEmpty($backend, 'backend/'.self::LOCALE.' 에 PHP 번역 파일이 있어야 함');

        $this->assertTrue(
            File::isFile($root.'/frontend/'.self::LOCALE.'.json'),
            'frontend/'.self::LOCALE.'.json 엔트리가 있어야 함'
        );

        $seed = File::glob($root.'/seed/*.json') ?: [];
        $this->assertNotEmpty($seed, 'seed 에 초기 데이터 번역이 있어야 함');

        $this->assertTrue(File::isFile($root.'/CHANGELOG.md'), 'CHANGELOG.md 가 있어야 함');
    }

    /**
     * backend PHP 파일이 설치 시 보안 검사를 통과하는지 검증합니다.
     *
     * 언어팩의 PHP 는 활성화 시 require 되므로, 번역 배열 외의 코드가 섞이면
     * 설치가 거부된다. 배포 전에 같은 검사를 걸어 릴리즈 후 발견을 막는다.
     *
     * @return void
     */
    public function test_backend_php_files_are_literal_translation_arrays(): void
    {
        $this->skipUnlessBundled();

        $files = File::glob($this->packageRoot().'/backend/'.self::LOCALE.'/*.php') ?: [];
        $this->assertNotEmpty($files);

        foreach ($files as $path) {
            $violation = LanguagePackPhpArrayValidator::inspectFile($path);

            $this->assertNull(
                $violation,
                sprintf(
                    '%s 는 번역 배열만 담아야 합니다 (%s, line %d)',
                    basename($path),
                    $violation['reason'] ?? '',
                    $violation['line'] ?? 0
                )
            );
        }
    }

    /**
     * backend PHP 키 셋이 한국어 원본과 완전히 대칭인지 검증합니다.
     *
     * 키가 어긋나면 해당 화면만 조용히 기준 로케일로 폴백해 오류 없이 드러나지 않는다.
     *
     * @return void
     */
    public function test_backend_keys_match_korean_origin(): void
    {
        $this->skipUnlessBundled();

        $koDir = base_path('lang/ko');
        $zhDir = $this->packageRoot().'/backend/'.self::LOCALE;

        $koFiles = array_map('basename', File::glob($koDir.'/*.php') ?: []);
        $zhFiles = array_map('basename', File::glob($zhDir.'/*.php') ?: []);
        sort($koFiles);
        sort($zhFiles);

        $this->assertSame($koFiles, $zhFiles, 'backend 파일 목록이 lang/ko 와 1:1 대응해야 함');

        foreach ($koFiles as $name) {
            $koKeys = $this->flattenKeys(require $koDir.'/'.$name);
            $zhKeys = $this->flattenKeys(require $zhDir.'/'.$name);

            $this->assertSame(
                [],
                array_values(array_diff($koKeys, $zhKeys)),
                "[$name] 한국어 원본에 있으나 번역에 없는 키"
            );
            $this->assertSame(
                [],
                array_values(array_diff($zhKeys, $koKeys)),
                "[$name] 번역에만 있는 잉여 키"
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
     * @return void
     */
    public function test_no_korean_characters_remain_in_translated_values(): void
    {
        $this->skipUnlessBundled();

        $root = $this->packageRoot();
        $offenders = [];

        foreach (File::glob($root.'/backend/'.self::LOCALE.'/*.php') ?: [] as $path) {
            foreach ($this->flattenValues(require $path) as $key => $value) {
                if ($this->containsHangul($value)) {
                    $offenders[] = basename($path)." :: $key";
                }
            }
        }

        foreach ($this->contentJsonFiles() as $path) {
            $decoded = json_decode(File::get($path), true);
            $this->assertIsArray($decoded, basename($path).' 가 올바른 JSON 이어야 함');

            foreach ($this->flattenValues($decoded) as $key => $value) {
                if ($this->containsHangul($value)) {
                    $offenders[] = basename($path)." :: $key";
                }
            }
        }

        $this->assertSame([], $offenders, '번역 값에 한국어 문자가 남아 있습니다');
    }

    /**
     * frontend 엔트리의 `$partial` 디렉티브가 실재 파일을 가리키는지 검증합니다.
     *
     * @return void
     */
    public function test_partial_directives_resolve_to_existing_files(): void
    {
        $this->skipUnlessBundled();

        $frontendDir = $this->packageRoot().'/frontend';
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
     * 팩 안의 모든 콘텐츠 JSON 경로를 반환합니다 (frontend + seed).
     *
     * @return array<int, string>
     */
    private function contentJsonFiles(): array
    {
        $root = $this->packageRoot();

        return array_merge(
            File::glob($root.'/frontend/*.json') ?: [],
            File::glob($root.'/frontend/partial/*.json') ?: [],
            File::glob($root.'/seed/*.json') ?: []
        );
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

    /**
     * 문자열에 한글(음절/자모)이 포함되어 있는지 판정합니다.
     */
    private function containsHangul(string $value): bool
    {
        return preg_match('/[\x{AC00}-\x{D7A3}\x{1100}-\x{11FF}\x{3130}-\x{318F}]/u', $value) === 1;
    }
}
