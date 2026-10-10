<?php

use App\Extension\Traits\ResolvesLanguageFragments;

// Read-only language fixture using the same resolver as TemplateService.
require dirname(__DIR__, 5).'/vendor/autoload.php';
$resolver = new class
{
    use ResolvesLanguageFragments;

    public function load(string $locale): array
    {
        if (! in_array($locale, ['ko', 'en'], true)) {
            throw new RuntimeException('Unsupported test locale');
        }
        $directory = dirname(__DIR__, 2).'/resources/lang';

        return $this->resolveLanguageFragments(json_decode(file_get_contents($directory.'/'.$locale.'.json'), true, 512, JSON_THROW_ON_ERROR), $directory);
    }
};
echo json_encode($resolver->load($argv[1] ?? 'ko'), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
