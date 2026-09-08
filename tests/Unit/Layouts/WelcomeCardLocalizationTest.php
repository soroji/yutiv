<?php

namespace Tests\Unit\Layouts;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 홈 환영 카드(sirsoft-basic) 의 다국어 계약을 파일 단위로 고정합니다.
 *
 * 이 카드에는 화면에 보이는 문구 두 개가 로케일을 타지 않는 상태로 있었습니다:
 *  1. 배지 "Welcome" 이 번역키가 아니라 영어 리터럴이었다.
 *  2. 사이트 소개가 `?? '$t:...'` 로 폴백을 걸었지만, 서버가 빈 문자열을 내려주므로
 *     `??`(null 병합)는 폴백하지 않는다 — 번역이 없는 언어에서 빈 줄이 남는다.
 *
 * 둘 다 예외도 로그도 남기지 않고 그 언어 화면을 열어야만 보인다. 레이아웃 JSON 과
 * 4개 로케일 자원(템플릿 ko/en 원본 + ja/zh-CN 언어팩)은 서로 다른 파일이라 한쪽만
 * 고쳐도 아무 검사에 걸리지 않으므로, 여기서 함께 묶어 둔다.
 *
 * DB 를 쓰지 않는다 — 파일 시스템만 읽는다.
 *
 * @effects welcome_card_badge_is_translated
 * @effects welcome_card_description_falls_back_on_empty
 */
class WelcomeCardLocalizationTest extends TestCase
{
    /** 환영 카드 레이아웃 partial (저장소에 있는 원본 = _bundled) */
    private const CARD = 'templates/_bundled/sirsoft-basic/layouts/partials/home/_welcome_card.json';

    /** 배지 번역키 */
    private const BADGE_KEY = 'welcome_badge';

    /**
     * 4개 로케일의 home 번역 소스 경로.
     *
     * ko/en 은 템플릿에 내장되고, ja/zh-CN 은 번들 언어팩이 공급한다.
     *
     * @return array<string, string>
     */
    private function homeTranslationSources(): array
    {
        return [
            'ko' => base_path('templates/_bundled/sirsoft-basic/lang/partial/ko/home.json'),
            'en' => base_path('templates/_bundled/sirsoft-basic/lang/partial/en/home.json'),
            'ja' => base_path('lang-packs/_bundled/g7-template-sirsoft-basic-ja/frontend/partial/home.json'),
            'zh-CN' => base_path('lang-packs/_bundled/g7-template-sirsoft-basic-zh-CN/frontend/partial/home.json'),
        ];
    }

    /**
     * 환영 카드 레이아웃 원문을 반환합니다.
     */
    private function cardSource(): string
    {
        $path = base_path(self::CARD);
        $this->assertTrue(File::isFile($path), self::CARD.' 가 있어야 함');

        return File::get($path);
    }

    /**
     * 배지 문구가 영어로 하드코딩되어 있지 않아야 합니다.
     *
     * @effects welcome_card_badge_is_translated
     */
    public function test_badge_is_not_hardcoded_english(): void
    {
        $source = $this->cardSource();

        $this->assertStringNotContainsString(
            '"text": "Welcome"',
            $source,
            '배지 문구는 번역키여야 한다 — 영어 리터럴이면 어떤 언어 화면에서도 영어로 남는다'
        );
        $this->assertStringContainsString('"text": "$t:home.'.self::BADGE_KEY.'"', $source);
    }

    /**
     * 배지 번역키가 4개 로케일 소스에 모두 있어야 합니다.
     *
     * 하나라도 빠지면 그 언어 화면에 번역키 원문(`home.welcome_badge`)이 노출된다.
     *
     * @effects welcome_card_badge_is_translated
     */
    public function test_badge_key_exists_in_every_locale_source(): void
    {
        foreach ($this->homeTranslationSources() as $locale => $path) {
            $this->assertTrue(File::isFile($path), "[$locale] $path 가 있어야 함");

            $home = json_decode(File::get($path), true);
            $this->assertIsArray($home, "[$locale] home.json 이 올바른 JSON 이어야 함");

            $this->assertArrayHasKey(self::BADGE_KEY, $home, "[$locale] home.".self::BADGE_KEY.' 번역이 필요');
            $this->assertIsString($home[self::BADGE_KEY], "[$locale] 번역값은 문자열");
            $this->assertNotSame('', trim($home[self::BADGE_KEY]), "[$locale] 번역값이 비어 있음");
        }
    }

    /**
     * 각 로케일의 배지 번역이 그 언어로 실제 번역되어 있어야 합니다.
     *
     * 값이 존재하기만 하고 전부 영어면 키만 옮긴 셈이라 증상이 그대로다.
     *
     * @effects welcome_card_badge_is_translated
     */
    public function test_badge_translations_are_actually_localized(): void
    {
        $sources = $this->homeTranslationSources();
        $values = [];
        foreach ($sources as $locale => $path) {
            $values[$locale] = json_decode(File::get($path), true)[self::BADGE_KEY];
        }

        // ko: 한글 음절, ja: 가나, zh-CN: 한자 — 각 언어의 문자 체계가 실제로 쓰였는지 본다.
        $this->assertMatchesRegularExpression('/[\x{AC00}-\x{D7A3}]/u', $values['ko'], 'ko 번역에 한글이 없다');
        $this->assertMatchesRegularExpression('/[\x{3040}-\x{30FF}]/u', $values['ja'], 'ja 번역에 가나가 없다');
        $this->assertMatchesRegularExpression('/[\x{4E00}-\x{9FFF}]/u', $values['zh-CN'], 'zh-CN 번역에 한자가 없다');
        $this->assertSame('Welcome', $values['en']);

        // zh-CN 이 ja 값을 그대로 복사한 사고 방지 (같은 구조의 ja 팩이 먼저 존재한다).
        $this->assertNotSame($values['ja'], $values['zh-CN']);
        $this->assertDoesNotMatchRegularExpression('/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}]/u', $values['zh-CN']);
        $this->assertDoesNotMatchRegularExpression('/[\x{AC00}-\x{D7A3}]/u', $values['zh-CN']);
    }

    /**
     * 사이트 소개 폴백이 빈 문자열에도 동작하는 표현식이어야 합니다.
     *
     * 서버는 요청 로케일의 값이 없으면 빈 문자열을 내려준다(폴백하면 중국어 화면에
     * 한국어가 남는 증상이 재현되므로 일부러 폴백하지 않는다). `??` 는 null 에만
     * 폴백하므로 빈 문자열에서는 번역키가 쓰이지 않는다 — `||` 여야 한다.
     *
     * @effects welcome_card_description_falls_back_on_empty
     */
    public function test_description_uses_falsy_safe_fallback(): void
    {
        $source = $this->cardSource();

        $this->assertStringContainsString(
            "site_description || '\$t:home.welcome_description'",
            $source,
            '빈 문자열도 폴백되는 `||` 를 써야 한다'
        );
        $this->assertStringNotContainsString(
            "site_description ?? '\$t:home.welcome_description'",
            $source,
            '`??` 는 빈 문자열에 폴백하지 않아 번역 없는 언어에서 빈 줄이 남는다'
        );
    }

    /**
     * 폴백에 쓰이는 번역키가 4개 로케일에 모두 있어야 합니다.
     *
     * @effects welcome_card_description_falls_back_on_empty
     */
    public function test_description_fallback_key_exists_in_every_locale_source(): void
    {
        foreach ($this->homeTranslationSources() as $locale => $path) {
            $home = json_decode(File::get($path), true);

            $this->assertArrayHasKey('welcome_description', $home, "[$locale] home.welcome_description 번역이 필요");
            $this->assertNotSame('', trim((string) $home['welcome_description']), "[$locale] 폴백 문구가 비어 있음");
        }
    }

    /**
     * 4개 로케일 소스의 home 키 집합이 동일해야 합니다.
     *
     * 키가 어긋나면 그 화면만 조용히 기준 로케일로 폴백하거나 번역키가 그대로 노출된다.
     *
     * @effects welcome_card_badge_is_translated
     */
    public function test_home_translation_key_sets_are_identical(): void
    {
        $sources = $this->homeTranslationSources();
        $koKeys = $this->flattenKeys(json_decode(File::get($sources['ko']), true));

        foreach ($sources as $locale => $path) {
            if ($locale === 'ko') {
                continue;
            }
            $keys = $this->flattenKeys(json_decode(File::get($path), true));

            $this->assertSame([], array_values(array_diff($koKeys, $keys)), "[$locale] ko 에 있으나 없는 키");
            $this->assertSame([], array_values(array_diff($keys, $koKeys)), "[$locale] ko 에 없는 잉여 키");
        }
    }

    /**
     * 레이아웃과 번역 소스가 UTF-8 without BOM · LF 로 저장되어야 합니다.
     *
     * @effects welcome_card_badge_is_translated
     */
    public function test_touched_files_are_utf8_without_bom_and_lf(): void
    {
        $paths = array_values($this->homeTranslationSources());
        $paths[] = base_path(self::CARD);

        foreach ($paths as $path) {
            $raw = File::get($path);

            $this->assertNotSame("\xEF\xBB\xBF", substr($raw, 0, 3), basename($path).' 에 BOM 이 있음');
            $this->assertStringNotContainsString("\r", $raw, basename($path).' 에 CR 이 있음 (LF 여야 함)');
            $this->assertTrue(mb_check_encoding($raw, 'UTF-8'), basename($path).' 이 UTF-8 이 아님');
        }
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
}
