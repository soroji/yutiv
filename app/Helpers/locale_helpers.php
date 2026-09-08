<?php

/**
 * 다국어 라벨 해석 헬퍼.
 *
 * 두 가지 도메인을 단일 시그니처로 처리한다:
 *   - settings JSON (사용자 편집 가능): `value: ['ko'=>..., 'en'=>...]` + `fallbackKey: 'sirsoft-ecommerce::settings.countries.KR.name'`
 *   - registry payload (시스템 정의): `nameKey: 'notification.channels.mail.name'`
 *
 * @since 7.0.0-beta.4
 */

if (! function_exists('localized_label')) {
    /**
     * 다국어 라벨을 활성 locale 기준으로 해석합니다.
     *
     * 우선순위: nameKey __() > value[locale] > fallbackKey __() > value[fallback_locale] > value 첫 키
     *
     * @param  array<string, string>|null  $value        ['ko' => ..., 'en' => ...] 형태 (settings JSON)
     * @param  string|null                 $nameKey      lang key 직접 선언 (registry payload)
     * @param  string|null                 $fallbackKey  $value 의 활성 locale 키 부재 시 __() 호출 키
     * @param  string|null                 $locale       명시 locale (기본: app()->getLocale())
     */
    function localized_label(
        ?array $value = null,
        ?string $nameKey = null,
        ?string $fallbackKey = null,
        ?string $locale = null,
    ): string {
        // Laravel app 미초기화 환경 (PHPUnit\Framework\TestCase 직접 상속 등) 도 안전하게 처리.
        // 명시 인자 → app locale → fallback_locale → 'ko'
        $hasLaravelApp = function (): bool {
            try {
                return function_exists('app') && app() instanceof \Illuminate\Contracts\Foundation\Application;
            } catch (\Throwable) {
                return false;
            }
        };

        if ($locale === null) {
            if ($hasLaravelApp()) {
                try {
                    $locale = app()->getLocale();
                } catch (\Throwable) {
                    // 무시
                }
                if ($locale === null) {
                    try {
                        $locale = config('app.fallback_locale', 'ko');
                    } catch (\Throwable) {
                        $locale = 'ko';
                    }
                }
            } else {
                $locale = 'ko';
            }
        }

        $tryTranslate = static function (string $key) use ($hasLaravelApp, $locale): ?string {
            if (! $hasLaravelApp()) {
                return null;
            }
            try {
                $translated = __($key, [], $locale);

                return is_string($translated) ? $translated : null;
            } catch (\Throwable) {
                return null;
            }
        };

        // 1. nameKey 우선 (registry payload — 데이터에 다국어 JSON 없음)
        if ($nameKey !== null && $nameKey !== '') {
            $translated = $tryTranslate($nameKey);
            if ($translated !== null && $translated !== $nameKey) {
                return $translated;
            }
        }

        // 2. value 의 활성 locale 키 (settings JSON)
        if ($value !== null && isset($value[$locale]) && $value[$locale] !== '') {
            return $value[$locale];
        }

        // 3. fallbackKey __()
        if ($fallbackKey !== null && $fallbackKey !== '') {
            $translated = $tryTranslate($fallbackKey);
            if ($translated !== null && $translated !== $fallbackKey) {
                return $translated;
            }
        }

        // 4. value 의 fallback_locale → 첫 키
        if ($value !== null) {
            $fallback = 'ko';
            if ($hasLaravelApp()) {
                try {
                    $fallback = config('app.fallback_locale', 'ko');
                } catch (\Throwable) {
                    // 'ko' 유지
                }
            }
            if (isset($value[$fallback]) && $value[$fallback] !== '') {
                return $value[$fallback];
            }
            $first = reset($value);

            return is_string($first) ? $first : '';
        }

        return '';
    }
}

if (! function_exists('localize_catalog_field')) {
    /**
     * Settings 카탈로그 다국어 JSON 필드에 활성 언어팩의 모든 활성 locale 키를 채워줍니다.
     *
     * 단일 string 반환이 아니라 **다국어 배열 자체** 를 반환 — settings 응답에 다국어 JSON 으로
     * 그대로 노출되어야 하므로. (운영자가 admin UI 에서 모든 locale 편집 가능해야 함)
     *
     * 운영자 편집값(비어있지 않은 값) 은 보존, 부재한 locale 만 lang pack 에서 자동 채움.
     *
     * 사용 예시:
     * ```php
     * // EcommerceSettingsService::getBuiltinPaymentMethods() 안에서
     * $cachedName = localize_catalog_field(
     *     $method['_cached_name'] ?? ['ko' => $id, 'en' => $id],
     *     "sirsoft-ecommerce::settings.payment_methods.{$id}.name",
     * );
     * ```
     *
     * @param  array<string, string>  $field    ['ko' => '...', 'en' => '...'] 다국어 JSON
     * @param  string                  $langKey  완전한 lang key (네임스페이스 prefix 포함)
     * @return array<string, string>            보강된 다국어 JSON
     *
     * @since 7.0.0-beta.4
     */
    function localize_catalog_field(array $field, string $langKey): array
    {
        $locales = config('app.translatable_locales', config('app.supported_locales', ['ko', 'en']));
        if (! is_array($locales) || empty($locales)) {
            $locales = ['ko', 'en'];
        }

        foreach ($locales as $locale) {
            // 운영자 편집값 보존 — 키가 존재하고 비어있지 않으면 skip
            if (isset($field[$locale]) && $field[$locale] !== '') {
                continue;
            }
            try {
                $translated = __($langKey, [], $locale);
                if (is_string($translated) && $translated !== $langKey) {
                    $field[$locale] = $translated;
                }
            } catch (\Throwable) {
                // Laravel app 미초기화 환경 — skip
            }
        }

        return $field;
    }
}

if (! function_exists('localized_payload')) {
    /**
     * Registry payload 단축 helper — entry 의 {field} (다국어 JSON) 와 {field}_key (lang key) 를 자동 처리.
     *
     * @param  array<string, mixed>  $entry  ['name' => [...], 'name_key' => '...']
     * @param  string                $field  처리할 필드명 (기본: 'name')
     * @param  string|null           $locale
     */
    function localized_payload(array $entry, string $field = 'name', ?string $locale = null): string
    {
        $value = $entry[$field] ?? null;
        $nameKey = $entry["{$field}_key"] ?? null;

        return localized_label(
            value: is_array($value) ? $value : null,
            nameKey: is_string($nameKey) ? $nameKey : null,
            locale: $locale,
        );
    }
}

if (! function_exists('localized_setting_base_locale')) {
    /**
     * 다국어 미지정 레거시 설정 문자열이 귀속되는 기준 로케일을 반환합니다.
     *
     * `general.site_description` 처럼 원래 단일 string 이던 설정이 로케일 맵으로 확장될 때,
     * 기존 값이 "어느 언어로 쓰였는지" 판정할 근거는 사이트의 기준 로케일뿐이다.
     * `localized_label()` 의 폴백 체인과 같은 축(`app.fallback_locale`)을 쓴다 —
     * 두 곳이 갈라지면 마이그레이션된 값이 해석 단계에서 다시 사라진다.
     *
     * @return string 기준 로케일 (기본 'ko')
     */
    function localized_setting_base_locale(): string
    {
        try {
            $locale = config('app.fallback_locale', 'ko');
        } catch (\Throwable) {
            return 'ko';
        }

        return is_string($locale) && $locale !== '' ? $locale : 'ko';
    }
}

if (! function_exists('localized_setting_map')) {
    /**
     * 설정값을 관리자 편집용 로케일 맵으로 정규화합니다.
     *
     * - 레거시 string  → `[기준 로케일 => 값]` **한 칸만** 채운다.
     *   모든 로케일에 복제하면 한국어 원문이 중국어 화면에 그대로 남아, 이 기능이 고치려는
     *   증상이 데이터에 그대로 굳어진다. 나머지 로케일은 비워 두고 표시 단계의 폴백에 맡긴다.
     * - 로케일 맵      → string 값만 남겨 그대로 통과 (운영자 입력 보존).
     * - 그 외          → 빈 배열.
     *
     * 반환 맵에 없는 로케일 키를 임의로 만들어 채우지 않는다 — 빈 문자열을 저장해 두면
     * "번역이 없다"와 "번역이 빈 값이다"를 구분할 수 없다.
     *
     * @param  mixed  $value  설정값 (string / 로케일 맵 / null)
     * @return array<string, string> 로케일 맵
     */
    function localized_setting_map(mixed $value): array
    {
        if (is_string($value)) {
            return $value === '' ? [] : [localized_setting_base_locale() => $value];
        }

        if (! is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $locale => $text) {
            if (is_string($locale) && is_string($text)) {
                $map[$locale] = $text;
            }
        }

        return $map;
    }
}

if (! function_exists('localized_setting_value')) {
    /**
     * 설정값을 현재(또는 지정) 로케일 문자열로 해석합니다.
     *
     * 배열이 그대로 화면 경로에 흘러 `"Array"` 로 렌더되거나 `(string)` 캐스팅에서
     * TypeError 가 나는 것을 차단하는 단일 지점이다.
     *
     * 두 가지 정책을 지원한다:
     *
     * - `$strict = false` (기본)
     *   요청 로케일 → `app.fallback_locale` → 첫 비어 있지 않은 값 순으로 폴백한다.
     *   "어느 언어 화면에서도 무언가는 보여야 하는" 값에 쓴다. 이는 코어가 이미
     *   `SettingsServiceProvider::localizeSettingValue()` · `LocalizesSeoValues::resolveLocalizedValue()`
     *   에서 쓰는 관용과 같은 순서다. 현재 이 모드를 쓰는 코어 설정 필드는 없다.
     *
     * - `$strict = true` (예: `general.site_description`)
     *   요청 로케일 값이 비어 있으면 **폴백하지 않고 빈 문자열**을 돌려준다.
     *   중국어 화면에 한국어 설명이 남는 것이 바로 고치려는 증상이므로, 여기서 폴백하면
     *   증상이 그대로 재현된다. 빈 문자열을 받은 레이아웃이 `$t:` 번역키로 넘어가야 한다.
     *   레거시 string 은 기준 로케일 값으로 간주하므로, 기준 로케일 화면에서만 노출된다.
     *
     * @param  mixed  $value  설정값 (string / 로케일 맵 / scalar / null)
     * @param  bool  $strict  요청 로케일 값이 없을 때 폴백을 금지할지 여부
     * @param  string|null  $locale  명시 로케일 (기본: 활성 로케일)
     * @return string 해석된 문자열 (없으면 빈 문자열)
     */
    function localized_setting_value(mixed $value, bool $strict = false, ?string $locale = null): string
    {
        if ($locale === null) {
            try {
                $locale = app()->getLocale();
            } catch (\Throwable) {
                $locale = null;
            }
            if (! is_string($locale) || $locale === '') {
                $locale = localized_setting_base_locale();
            }
        }

        // 레거시 string 은 기준 로케일 값으로 간주한다 (localized_setting_map 과 동일 규칙).
        $map = localized_setting_map($value);

        if ($map === []) {
            // 로케일 맵도 string 도 아닌 scalar 는 화면 표기용으로만 문자열화한다.
            return is_scalar($value) ? (string) $value : '';
        }

        if (isset($map[$locale]) && $map[$locale] !== '') {
            return $map[$locale];
        }

        if ($strict) {
            return '';
        }

        $fallback = localized_setting_base_locale();
        if (isset($map[$fallback]) && $map[$fallback] !== '') {
            return $map[$fallback];
        }

        foreach ($map as $candidate) {
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '';
    }
}
