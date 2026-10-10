<?php

namespace Modules\Sirsoft\Ecommerce\Services\Translation;

use RuntimeException;

/** HTML tags and attributes NEVER leave the server and are copied byte-for-byte. */
class StructuredTextTranslator
{
    public function __construct(private TranslationProviderInterface $provider) {}

    public function translate(string $source, string $locale, bool $html, array $terms = []): string
    {
        $parts = $html ? preg_split('/(<!--[\s\S]*?-->|<(?:"[^"]*"|\'[^\']*\'|[^\'">])*>)/u', $source, -1, PREG_SPLIT_DELIM_CAPTURE) : [$source];
        $segments = [];
        $protected = [];
        $positions = [];
        $excluded = 0;
        foreach ($parts as $index => $part) {
            if ($html && str_starts_with($part, '<')) {
                if (preg_match('/^<\s*(script|style|code|pre)\b/i', $part)) {
                    $excluded++;
                }
                if (preg_match('/^<\s*\/\s*(script|style|code|pre)\b/i', $part)) {
                    $excluded = max(0, $excluded - 1);
                }

                continue;
            }
            if ($excluded || trim($part) === '') {
                continue;
            }
            $plain = $html ? html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $part;
            // Chunk text nodes without allowing the model to rebuild HTML.
            // Protect before splitting, so a brand/model at a chunk boundary stays intact.
            $chunk = $plain;
            $tokens = [];
            $pattern = '/'.implode('|', array_map(fn ($term) => preg_quote($term, '/'), array_filter($terms))).'/u';
            if ($terms && $pattern !== '//u') {
                $chunk = preg_replace_callback($pattern, function ($match) use (&$tokens) {
                    $token = '__KEEP_'.count($tokens).'__';
                    $tokens[$token] = $match[0];

                    return $token;
                }, $chunk);
            }
            // Preserve identifiers/model numbers/specifications/units and literal URLs.
            $chunk = preg_replace_callback('/__KEEP_\d+__|https?:\/\/[^\s<>]+|[A-Za-z0-9][A-Za-z0-9._,%+\/-]*(?:\s?(?:ml|mL|kg|mg|cm|mm|GB|MB|L|g|V|W|Hz))?/u', function ($match) use (&$tokens) {
                if (str_starts_with($match[0], '__KEEP_')) {
                    return $match[0];
                }
                $token = '__KEEP_'.count($tokens).'__';
                $tokens[$token] = $match[0];

                return $token;
            }, $chunk);
            $chunks = [];
            while ($chunk !== '') {
                $length = min(1500, mb_strlen($chunk));
                preg_match_all('/__KEEP_\d+__/', $chunk, $matches, PREG_OFFSET_CAPTURE);
                foreach ($matches[0] as [$placeholder, $offset]) {
                    $start = mb_strlen(substr($chunk, 0, $offset));
                    if ($start >= $length) {
                        break;
                    }
                    if ($start + strlen($placeholder) > $length) {
                        $length = $start;
                        break;
                    }
                }
                $chunks[] = mb_substr($chunk, 0, $length);
                $chunk = mb_substr($chunk, $length);
            }
            foreach ($chunks as $chunk) {
                $id = 't'.count($segments);
                $segments[$id] = $chunk;
                $protected[$id] = array_filter($tokens, fn ($key) => str_contains($chunk, $key), ARRAY_FILTER_USE_KEY);
                $positions[$id] = $index;
            }
        }
        if (! $segments) {
            return $source;
        }
        $translated = [];
        $deadline = microtime(true) + 150;
        foreach (array_chunk($segments, 8, true) as $batch) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('timeout');
            }
            $result = $this->provider->translate($batch, $locale);
            $keys = array_keys($result);
            $expected = array_keys($batch);
            sort($keys);
            sort($expected);
            if ($keys !== $expected) {
                throw new RuntimeException('invalid_response');
            }
            // Providers may return object keys in any order; rebuild in source order.
            foreach ($batch as $id => $_source) {
                $text = $result[$id];
                if (! is_string($text) || trim($text) === '' || mb_strlen($text) > 9000 || preg_match('/[<>]/u', $text)) {
                    throw new RuntimeException('invalid_response');
                }
                preg_match_all('/__KEEP_\d+__/', $text, $matches);
                $actual = $matches[0];
                $tokens = array_keys($protected[$id]);
                sort($actual);
                sort($tokens);
                if ($actual !== $tokens) {
                    throw new RuntimeException('invalid_response');
                }
                $text = strtr($text, $protected[$id]);
                $translated[$positions[$id]] = ($translated[$positions[$id]] ?? '').($html ? htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8') : $text);
            }
        }
        foreach ($translated as $index => $text) {
            $parts[$index] = $text;
        }

        return implode('', $parts);
    }
}
