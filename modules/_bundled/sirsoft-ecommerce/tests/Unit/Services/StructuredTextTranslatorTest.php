<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Modules\Sirsoft\Ecommerce\Services\Translation\StructuredTextTranslator;
use Modules\Sirsoft\Ecommerce\Services\Translation\TranslationProviderInterface;
use PHPUnit\Framework\TestCase;

class StructuredTextTranslatorTest extends TestCase
{
    private function provider(?callable $handler = null): TranslationProviderInterface
    {
        return new class($handler) implements TranslationProviderInterface
        {
            public array $calls = [];

            public function __construct(private $handler) {}

            public function configured(): bool
            {
                return true;
            }

            public function translate(array $segments, string $locale): array
            {
                $this->calls[] = [$segments, $locale];

                return $this->handler ? ($this->handler)($segments, $locale) : array_map(fn ($text) => str_replace('한국어', 'Translated', $text), $segments);
            }
        };
    }

    public function test_long_html_keeps_tags_attributes_links_images_and_excluded_nodes(): void
    {
        $provider = $this->provider();
        $html = '<div title="a > b"><p>'.str_repeat('한국어 ', 800).'</p><ul><li>한국어 250ml AB-123 YUTIV</li></ul><table><tr><td>한국어</td></tr></table><a href="https://example.com/a?q=1&b=2">한국어</a><img src="/image/001.png" alt="한국어"><code>한국어</code></div>';
        $result = (new StructuredTextTranslator($provider))->translate($html, 'ja', true, ['YUTIV']);
        preg_match_all('/<(?:"[^"]*"|\'[^\']*\'|[^\'">])*>/', $html, $before);
        preg_match_all('/<(?:"[^"]*"|\'[^\']*\'|[^\'">])*>/', $result, $after);
        $this->assertSame($before[0], $after[0]);
        $this->assertStringContainsString('250ml AB-123 YUTIV', $result);
        $this->assertStringContainsString('<code>한국어</code>', $result);
        $this->assertStringContainsString('Translated', $result);
        foreach ($provider->calls as [$segments, $locale]) {
            $this->assertSame('ja', $locale);
            foreach ($segments as $text) {
                $this->assertStringNotContainsString('<img', $text);
                $this->assertStringNotContainsString('https://example.com/a', $text);
            }
        }
    }

    public function test_missing_extra_wrong_type_or_markup_results_are_rejected(): void
    {
        foreach ([fn ($s) => [], fn ($s) => [...$s, 'unknown' => 'bad'], fn ($s) => array_map(fn () => ['bad'], $s), fn ($s) => array_map(fn () => '<img src=x>', $s)] as $handler) {
            try {
                (new StructuredTextTranslator($this->provider($handler)))->translate('한국어', 'en', false);
                $this->fail('Invalid result accepted');
            } catch (\RuntimeException $exception) {
                $this->assertSame('invalid_response', $exception->getMessage());
            }
        }
    }

    public function test_protected_term_removal_or_duplication_is_rejected(): void
    {
        foreach ([fn ($s) => array_map(fn ($text) => str_replace('__KEEP_0__', '', $text), $s), fn ($s) => array_map(fn ($text) => $text.' __KEEP_0__', $s)] as $handler) {
            $failed = false;
            try {
                (new StructuredTextTranslator($this->provider($handler)))->translate('한국어 YUTIV 250ml', 'en', false, ['YUTIV']);
            } catch (\RuntimeException $exception) {
                $failed = true;
                $this->assertSame('invalid_response', $exception->getMessage());
            }
            $this->assertTrue($failed);
        }
    }

    public function test_blank_source_does_not_call_provider(): void
    {
        $provider = $this->provider();
        $this->assertSame('<p> </p>', (new StructuredTextTranslator($provider))->translate('<p> </p>', 'en', true));
        $this->assertSame([], $provider->calls);
    }

    public function test_chunk_boundaries_preserve_terms_and_response_key_order_cannot_reorder_text(): void
    {
        $source = str_repeat('가', 1497).'고유브랜드 20,000원 250ml '.str_repeat('나', 1600).' AB-123';
        $provider = $this->provider(fn ($segments) => array_reverse($segments, true));
        $this->assertSame($source, (new StructuredTextTranslator($provider))->translate($source, 'en', false, ['고유브랜드']));
    }
}
