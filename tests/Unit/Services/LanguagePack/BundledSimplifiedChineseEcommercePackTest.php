<?php

namespace Tests\Unit\Services\LanguagePack;

use App\Services\LanguagePack\LanguagePackManifestValidator;
use App\Services\LanguagePack\LanguagePackPhpArrayValidator;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 공식 이커머스 모듈 중국어 간체 번들 언어팩(g7-module-sirsoft-ecommerce-zh-CN)의 무결성 검증.
 *
 * `BundledSimplifiedChinesePackTest`(코어 zh-CN)와 같은 계약을 module 스코프에 적용한다.
 * `BundledJapanesePacksTest` 는 검사 대상을 상수 배열로 나열하므로 새 로케일 팩을 자동으로
 * 포함하지 않는다 — manifest 가 깨져도, PHP 가 보안 검사를 통과하지 못해도 오류 없이
 * 릴리즈까지 통과한다. 이 테스트가 그 사각을 이커머스 zh-CN 팩에 대해 닫는다.
 *
 * 검증 대상:
 *  1. manifest 가 production validator(LanguagePackManifestValidator)를 통과
 *  2. manifest 필드가 module 스코프 zh-CN 팩 사양과 일치 (target/의존성/네이밍 공식)
 *  3. backend/frontend/seed 파일이 실제로 존재
 *  4. backend PHP 가 설치 시 보안 검사(LanguagePackPhpArrayValidator)를 통과
 *  5. backend PHP 키 셋이 모듈 한국어 원본(src/lang/ko)과 완전히 대칭
 *  6. frontend JSON 키 셋이 모듈 한국어 원본(resources/lang/partial/ko)과 완전히 대칭
 *  7. 번역 값에 한국어(원본 로케일) 문자가 잔존하지 않음
 *  8. frontend 의 `$partial` 디렉티브가 실재 파일을 가리킴
 *  9. 대상 모듈(sirsoft-ecommerce)이 저장소에 실재
 *
 * 번들 미배포 상태에서는 자동 스킵 (CI 안전).
 */
class BundledSimplifiedChineseEcommercePackTest extends TestCase
{
    /** 검증 대상 패키지 식별자 */
    private const IDENTIFIER = 'g7-module-sirsoft-ecommerce-zh-CN';

    /** 대상 모듈 식별자 */
    private const TARGET = 'sirsoft-ecommerce';

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
     * 대상 모듈 루트 절대경로를 반환합니다.
     */
    private function moduleRoot(): string
    {
        return base_path('modules/_bundled/'.self::TARGET);
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
     * manifest 필드가 module 스코프 zh-CN 팩 사양과 일치하는지 검증합니다.
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
        $this->assertSame('module', $manifest['scope']);
        $this->assertSame(self::TARGET, $manifest['target_identifier'], 'target 은 실제 모듈 식별자와 일치해야 함');
        $this->assertSame(self::LOCALE, $manifest['locale']);
        $this->assertSame('Simplified Chinese', $manifest['locale_name']);
        $this->assertSame('简体中文', $manifest['locale_native_name']);
        $this->assertSame('ltr', $manifest['text_direction']);

        // scope != core 이므로 동일 locale 코어 팩 활성이 전제여야 한다
        // (LanguagePackService::resolveCoreLocaleBlockedReason 이 강제).
        $this->assertTrue(
            $manifest['requires']['depends_on_core_locale'],
            'module 스코프 팩은 동일 locale 코어 팩에 의존해야 한다'
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
     * 대상 모듈이 저장소에 실재하는지 검증합니다.
     *
     * 대상 확장이 없으면 설치 자체가 `target_not_installed` 로 차단되므로,
     * 식별자 오타를 배포 전에 잡는다.
     *
     * @return void
     */
    public function test_target_module_exists(): void
    {
        $this->skipUnlessBundled();

        $moduleJson = $this->moduleRoot().'/module.json';
        $this->assertTrue(File::isFile($moduleJson), '대상 모듈 manifest 가 존재해야 함');

        $module = json_decode(File::get($moduleJson), true);
        $this->assertIsArray($module);
        $this->assertSame(self::TARGET, $module['identifier'], 'module.json 의 identifier 와 target 이 일치해야 함');
    }

    /**
     * backend/frontend/seed 콘텐츠 파일이 실제로 존재하는지 검증합니다.
     *
     * @return void
     */
    public function test_content_files_exist(): void
    {
        $this->skipUnlessBundled();

        $root = $this->packageRoot();

        $this->assertNotEmpty(
            File::glob($root.'/backend/'.self::LOCALE.'/*.php') ?: [],
            'backend/'.self::LOCALE.' 에 PHP 번역 파일이 있어야 함'
        );

        $this->assertTrue(
            File::isFile($root.'/frontend/'.self::LOCALE.'.json'),
            'frontend/'.self::LOCALE.'.json 엔트리가 있어야 함'
        );

        $this->assertNotEmpty(File::glob($root.'/seed/*.json') ?: [], 'seed 에 초기 데이터 번역이 있어야 함');
        $this->assertTrue(File::isFile($root.'/CHANGELOG.md'), 'CHANGELOG.md 가 있어야 함');
    }

    /**
     * backend PHP 파일이 설치 시 보안 검사를 통과하는지 검증합니다.
     *
     * 언어팩의 PHP 는 활성화되면 require 되므로, 번역 배열 외의 코드가 섞이면 설치가
     * 거부된다. 배포 전에 같은 검사를 걸어 릴리즈 후 발견을 막는다.
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
     * backend PHP 키 셋이 모듈 한국어 원본과 완전히 대칭인지 검증합니다.
     *
     * 키가 어긋나면 해당 화면만 조용히 기준 로케일로 폴백해 오류 없이 드러나지 않는다.
     *
     * @return void
     */
    public function test_backend_keys_match_korean_origin(): void
    {
        $this->skipUnlessBundled();

        $koDir = $this->moduleRoot().'/src/lang/ko';
        $zhDir = $this->packageRoot().'/backend/'.self::LOCALE;

        $koFiles = array_map('basename', File::glob($koDir.'/*.php') ?: []);
        $zhFiles = array_map('basename', File::glob($zhDir.'/*.php') ?: []);
        sort($koFiles);
        sort($zhFiles);

        $this->assertSame($koFiles, $zhFiles, 'backend 파일 목록이 모듈 ko lang 과 1:1 대응해야 함');

        foreach ($koFiles as $name) {
            $koKeys = $this->flattenKeys(require $koDir.'/'.$name);
            $zhKeys = $this->flattenKeys(require $zhDir.'/'.$name);

            $this->assertSame([], array_values(array_diff($koKeys, $zhKeys)), "[$name] 원본에 있으나 번역에 없는 키");
            $this->assertSame([], array_values(array_diff($zhKeys, $koKeys)), "[$name] 번역에만 있는 잉여 키");
        }
    }

    /**
     * frontend JSON 키 셋이 모듈 한국어 원본과 완전히 대칭인지 검증합니다.
     *
     * 원본의 `$partial` 경로는 `partial/ko/x.json`, 팩은 `partial/x.json` 으로 정규화되므로
     * 경로 값은 비교 전에 팩 규칙으로 변환한다 (ja 팩과 동일 규칙).
     *
     * @return void
     */
    public function test_frontend_keys_match_korean_origin(): void
    {
        $this->skipUnlessBundled();

        $koEntry = $this->moduleRoot().'/resources/lang/ko.json';
        $zhEntry = $this->packageRoot().'/frontend/'.self::LOCALE.'.json';

        $this->assertTrue(File::isFile($koEntry));
        $this->assertTrue(File::isFile($zhEntry));

        $koKeys = $this->flattenKeys(json_decode(File::get($koEntry), true));
        $zhKeys = $this->flattenKeys(json_decode(File::get($zhEntry), true));
        $this->assertSame($koKeys, $zhKeys, '엔트리 파일 키 셋이 일치해야 함');

        $koPartialDir = $this->moduleRoot().'/resources/lang/partial/ko';
        $zhPartialDir = $this->packageRoot().'/frontend/partial';

        $koPartials = $this->collectRelativeJson($koPartialDir);
        $zhPartials = $this->collectRelativeJson($zhPartialDir);

        $this->assertSame($koPartials, $zhPartials, 'partial 파일 목록이 1:1 대응해야 함 (admin/ 서브디렉토리 포함)');

        foreach ($koPartials as $rel) {
            $koKeys = $this->flattenKeys(json_decode(File::get($koPartialDir.'/'.$rel), true));
            $zhKeys = $this->flattenKeys(json_decode(File::get($zhPartialDir.'/'.$rel), true));

            $this->assertSame([], array_values(array_diff($koKeys, $zhKeys)), "[partial/$rel] 원본에 있으나 번역에 없는 키");
            $this->assertSame([], array_values(array_diff($zhKeys, $koKeys)), "[partial/$rel] 번역에만 있는 잉여 키");
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
     * 통화 코드·HTTP 메서드 등 기술 식별자가 번역되지 않았는지 검증합니다.
     *
     * 이커머스 팩에서만 발생하는 사고다. `KRW`/`CNY` 같은 통화 코드나 `GET`/`POST`
     * 는 라벨처럼 보이지만 결제·정산·외부 API 호출이 값 그대로 사용한다. 번역하면
     * 예외 없이 잘못된 통화로 청구되거나 배송비 계산 API 호출이 실패한다.
     * 키 대칭 검사도 한글 잔존 검사도 이 사고를 잡지 못하므로 값을 직접 고정한다.
     *
     * @return void
     */
    public function test_technical_identifiers_are_not_translated(): void
    {
        $this->skipUnlessBundled();

        $enums = require $this->packageRoot().'/backend/'.self::LOCALE.'/enums.php';
        $this->assertSame(
            ['GET' => 'GET', 'POST' => 'POST'],
            $enums['shipping_api_http_method'],
            'HTTP 메서드는 외부 API 호출에 그대로 쓰이므로 번역 대상이 아니다'
        );

        $settings = require $this->packageRoot().'/backend/'.self::LOCALE.'/settings.php';
        foreach (['KRW', 'USD', 'JPY', 'CNY', 'EUR'] as $code) {
            $this->assertArrayHasKey($code, $settings['currencies'], "통화 키 $code 가 보존되어야 함");
            $this->assertStringContainsString(
                $code,
                $settings['currencies'][$code]['name'],
                "통화 표시명에 ISO 4217 코드 $code 가 남아 있어야 함"
            );
        }
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
    private function contentJsonFiles(): array
    {
        $root = $this->packageRoot();

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

    /**
     * 문자열에 한글(음절/자모)이 포함되어 있는지 판정합니다.
     */
    private function containsHangul(string $value): bool
    {
        return preg_match('/[\x{AC00}-\x{D7A3}\x{1100}-\x{11FF}\x{3130}-\x{318F}]/u', $value) === 1;
    }
}
