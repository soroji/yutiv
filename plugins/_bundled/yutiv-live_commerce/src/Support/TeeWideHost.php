<?php

namespace Plugins\Yutiv\LiveCommerce\Support;

/**
 * Host 정규화와 역할 판정.
 *
 * ── 왜 별도 클래스인가 ──────────────────────────────────────────────────────
 * "이 요청이 어느 사이트인가" 는 라우트·미들웨어·세션 세 곳에서 똑같이 판단해야 한다.
 * 세 곳이 각자 문자열을 비교하면 한 곳만 정규화를 빠뜨려도 격리가 뚫린다. 판정을
 * 여기 한 곳에 모으고, 아래 함정을 모두 이 안에서 처리한다.
 *
 *   · 포트 포함        `teewide.com:8443`  → `teewide.com`
 *   · 대문자           `TeeWide.COM`       → `teewide.com`
 *   · 후행 점(FQDN)    `teewide.com.`      → `teewide.com`
 *   · 좌우 공백        ` teewide.com `     → `teewide.com`
 *   · IPv6 대괄호      `[::1]:8000`        → `[::1]`
 *
 * ── 문법 주의 ───────────────────────────────────────────────────────────────
 * PHP 7.4 파서로도 읽히는 문법만 쓴다. 로컬 검증 환경에 PHP 8 이 없어
 * `tests/TeeWide` 독립 하네스가 이 파일을 그대로 require 해 정규화 규칙을 실행 검증한다
 * (기존 yutiv-ses_monitor 의 SnsMessageValidator 와 같은 이유).
 */
class TeeWideHost
{
    /** 계정 포털 (teewide.com). */
    const ROLE_ROOT = 'root';

    /** 라이브 판매 (live.teewide.com). */
    const ROLE_LIVE = 'live';

    /** TeeWide 가 아닌 호스트 (yutiv.com 포함). */
    const ROLE_FOREIGN = 'foreign';

    /**
     * Host 헤더를 비교 가능한 canonical 형태로 만든다.
     *
     * 판정 불가능한 값(빈 문자열 등)은 빈 문자열로 돌려준다 — 호출 측이 "알 수 없는
     * 호스트" 로 다루도록 하기 위해서다. 여기서 임의로 기본값을 채우면 위조 Host 가
     * 조용히 통과한다.
     *
     * @param  string|null  $host  원본 Host 헤더 또는 $request->getHost()
     */
    public static function canonical($host): string
    {
        if (! is_string($host)) {
            return '';
        }

        $value = trim($host);

        if ($value === '') {
            return '';
        }

        // IPv6 리터럴은 대괄호 안을 그대로 두고 포트만 떼야 한다.
        if (strpos($value, '[') === 0) {
            $close = strpos($value, ']');
            if ($close !== false) {
                $value = substr($value, 0, $close + 1);
            }
        } else {
            // 포트 제거 — 마지막 콜론 뒤가 숫자일 때만 자른다.
            $colon = strrpos($value, ':');
            if ($colon !== false && ctype_digit(substr($value, $colon + 1))) {
                $value = substr($value, 0, $colon);
            }
        }

        // 후행 점(FQDN 절대 표기) 제거 후 소문자화.
        $value = rtrim($value, '.');

        return strtolower($value);
    }

    /**
     * canonical host 두 개가 같은 사이트인가.
     */
    public static function matches($host, string $expected): bool
    {
        $canonical = self::canonical($host);
        $target = self::canonical($expected);

        if ($canonical === '' || $target === '') {
            return false;
        }

        return $canonical === $target;
    }

    /**
     * 이 요청이 TeeWide 의 어느 영역인가.
     *
     * 설정된 호스트와 **정확히** 일치할 때만 TeeWide 로 본다. 부분 일치나 접미사 비교를
     * 쓰지 않는다 — `evil-teewide.com` 이나 `teewide.com.attacker.net` 이 통과하면 안 된다.
     */
    public static function role($host, string $rootHost, string $liveHost): string
    {
        if (self::matches($host, $rootHost)) {
            return self::ROLE_ROOT;
        }

        if (self::matches($host, $liveHost)) {
            return self::ROLE_LIVE;
        }

        return self::ROLE_FOREIGN;
    }

    /**
     * TeeWide 영역인가 (root 또는 live).
     */
    public static function isTeeWide($host, string $rootHost, string $liveHost): bool
    {
        return self::role($host, $rootHost, $liveHost) !== self::ROLE_FOREIGN;
    }
}
