<?php

namespace Plugins\Yutiv\SesMonitor\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * SNS `SubscribeURL` 호출 — HTTPS 구독을 실제로 확정하는 유일한 수단.
 *
 * ── 왜 호출이 필요한가 ──────────────────────────────────────────────────────
 * SNS HTTPS 구독은 **엔드포인트가 SubscriptionConfirmation 의 SubscribeURL 을 호출해야**
 * 확정된다. AWS 콘솔에서 클릭만으로 확정할 수 없다 (콘솔의 "Request confirmation" 은
 * 확인 메시지를 **재전송**할 뿐이다). 그래서 최초 구독 시에는 반드시
 * `SES_SNS_AUTO_CONFIRM=true` 로 잠깐 열어 이 호출을 한 번 성사시켜야 한다.
 *
 * ── 안전장치 ────────────────────────────────────────────────────────────────
 *   · 호출 전에 URL 을 **다시** 검증한다 — HTTPS + `sns.{허용리전}.amazonaws.com(.cn)`.
 *     서명 검증을 통과한 메시지라도, 호출 대상은 독립적으로 확인한다.
 *   · redirect 를 따라가지 않는다 (`withoutRedirecting`). 3xx 는 실패로 본다 —
 *     리다이렉트를 따라가면 검증한 호스트 밖으로 나갈 수 있다.
 *   · connect/read timeout 을 짧게 둔다. webhook 응답은 빨라야 한다.
 *   · 같은 MessageId 재전송은 캐시 선점으로 한 번만 호출한다 (SNS 는 at-least-once).
 *   · 선점을 보장할 수 없으면(캐시 장애·동시 요청) **호출하지 않는다** — fail-closed.
 *     호출 측이 500 을 돌려주고 SNS 재시도를 기다린다.
 *
 * ── 로깅 ────────────────────────────────────────────────────────────────────
 * 이 클래스는 로그를 남기지 않는다. Token 과 SubscribeURL 전체는 **구독 권한을 가진
 * 자격증명**이므로 어디에도 기록하지 않는다. 호출 측은 이 클래스가 돌려주는 결과
 * 코드만 로그에 쓴다.
 *
 * ── 문법 주의 ───────────────────────────────────────────────────────────────
 * SnsMessageValidator / SesEventParser 와 같은 이유로 **PHP 7.4 파서로도 읽히는 문법**만
 * 쓴다. tests/Ses 하네스가 이 파일을 require 해 `isAllowedSubscribeUrl()` 규칙을 실행
 * 검증한다 (Http/Cache 파사드는 그 경로에서 호출되지 않는다).
 */
class SubscriptionConfirmer
{
    /** 확인 성공 (실제로 호출함). */
    public const RESULT_CONFIRMED = 'confirmed';

    /** 같은 MessageId 를 이미 처리함 — 호출하지 않음. */
    public const RESULT_ALREADY_HANDLED = 'already_handled';

    /** SubscribeURL 이 허용된 SNS 호스트가 아님 — 호출하지 않음. */
    public const RESULT_URL_REJECTED = 'url_rejected';

    /** 호출했으나 실패 (비 2xx, redirect, timeout, 네트워크 오류). */
    public const RESULT_FAILED = 'failed';

    /** 멱등 선점을 보장할 수 없음 — **호출하지 않았다.** 호출 측이 500 으로 재시도를 유도한다. */
    public const RESULT_CLAIM_UNAVAILABLE = 'claim_unavailable';

    /** 이 요청이 멱등 키를 선점했다 — 이 요청만 호출할 수 있다. */
    public const CLAIM_CLAIMED = 'claimed';

    /** 이미 선점된 키가 있다 — 다른 요청이 처리했다. */
    public const CLAIM_DUPLICATE = 'duplicate';

    /** 선점 안전성을 보장할 수 없다 (캐시 장애). **호출하면 안 된다.** */
    public const CLAIM_UNAVAILABLE = 'unavailable';

    /** 캐시 키 접두. */
    private const CACHE_PREFIX = 'yutiv-ses-monitor:sns-confirm:';

    /**
     * 중복 판정 유지 기간(초). **유한해야 한다.**
     *
     * 영구 키를 쓰면 캐시가 살아 있는 한 같은 MessageId 로는 영영 재확인할 수 없다.
     * SNS 재전송 창을 덮으면서도, 구독을 다시 확정해야 할 때 하루를 기다리지 않을
     * 길이로 1시간을 쓴다.
     */
    private const CACHE_TTL = 3600;

    /** @var int */
    private $connectTimeout;

    /** @var int */
    private $timeout;

    public function __construct(int $connectTimeout = 2, int $timeout = 4)
    {
        $this->connectTimeout = $connectTimeout;
        $this->timeout = $timeout;
    }

    /**
     * SubscribeURL 을 호출해 구독을 확정한다.
     *
     * **호출 측이 서명·TopicArn·리전·Timestamp 검증을 모두 통과시킨 뒤에만** 부를 것.
     * 이 클래스는 그 검증을 반복하지 않는다 (URL 호스트 재검증만 한다).
     *
     * @param  string  $subscribeUrl  SNS 가 준 SubscribeURL
     * @param  string  $messageId  SNS MessageId (중복 판정 키)
     * @param  string  $region  허용 리전
     * @return array{result: string, status: int|null, claim: string}
     */
    public function confirm(string $subscribeUrl, string $messageId, string $region): array
    {
        if (! self::isAllowedSubscribeUrl($subscribeUrl, $region)) {
            return ['result' => self::RESULT_URL_REJECTED, 'status' => null, 'claim' => self::CLAIM_CLAIMED];
        }

        $cacheKey = self::CACHE_PREFIX.sha1($messageId);
        $claim = $this->claim($cacheKey);

        // 이미 선점된 키 — 다른 요청이 처리했으므로 다시 호출하지 않는다.
        if ($claim === self::CLAIM_DUPLICATE) {
            return ['result' => self::RESULT_ALREADY_HANDLED, 'status' => null, 'claim' => $claim];
        }

        // ★ fail-closed — 선점을 보장할 수 없으면 **호출하지 않는다.**
        //
        // 캐시 장애나 동시 요청에서 선점 없이 호출하면 같은 SubscribeURL 이 여러 번
        // 나가 "MessageId 당 1회" 계약이 깨진다. SNS 는 non-2xx 를 재시도하므로,
        // 여기서 멈추고 500 을 돌려주면 캐시가 회복된 뒤 재시도에서 정상 처리된다.
        // 잃는 것은 지연뿐이고, 열어 두면 잃는 것은 계약이다.
        if ($claim === self::CLAIM_UNAVAILABLE) {
            return ['result' => self::RESULT_CLAIM_UNAVAILABLE, 'status' => null, 'claim' => $claim];
        }

        try {
            $response = Http::withoutRedirecting()
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->get($subscribeUrl);

            if ($response->successful()) {
                // 성공했으면 표시를 TTL 동안 그대로 둔다 — 재전송이 와도 다시 부르지 않는다.
                return ['result' => self::RESULT_CONFIRMED, 'status' => $response->status(), 'claim' => $claim];
            }

            // 3xx 포함 — redirect 를 따라가지 않으므로 여기로 떨어진다.
            $this->release($cacheKey);

            return ['result' => self::RESULT_FAILED, 'status' => $response->status(), 'claim' => $claim];
        } catch (\Throwable $e) {
            // timeout·DNS·TLS 오류. 예외 메시지에 URL 이 들어갈 수 있어 삼킨다.
            $this->release($cacheKey);

            return ['result' => self::RESULT_FAILED, 'status' => null, 'claim' => $claim];
        }
    }

    /**
     * 멱등 키를 선점한다 — **fail-closed**.
     *
     * ── 세 가지 결과 ────────────────────────────────────────────────────
     *   CLAIMED     add() 성공. 이 요청만 SubscribeURL 을 호출할 수 있다.
     *   DUPLICATE   add() 실패 + get() 으로 기존 키 확인. 다른 요청이 이미 처리했다.
     *   UNAVAILABLE 그 밖의 모든 경우 — 선점을 보장할 수 없다. **호출 금지.**
     *
     * ── 왜 fail-closed 인가 ─────────────────────────────────────────────
     * Cache::add() 는 키가 이미 있을 때도, 쓰기 자체가 실패했을 때도 false 를 돌려준다.
     * 후자를 선점 성공처럼 다루면 캐시 장애·동시 요청에서 같은 SubscribeURL 이
     * 여러 번 나가 "MessageId 당 1회" 계약이 깨진다.
     *
     * SNS 는 non-2xx 를 재시도하므로, 확신이 없을 때 멈추고 500 을 돌려주는 쪽이
     * 안전하다. 캐시가 회복되면 재시도에서 정상 선점된다 — 잃는 것은 지연뿐이다.
     * 반대로 열어 두면 잃는 것은 계약이고, 그건 재시도로 되돌릴 수 없다.
     *
     * @return string CLAIM_CLAIMED | CLAIM_DUPLICATE | CLAIM_UNAVAILABLE
     */
    private function claim(string $cacheKey): string
    {
        try {
            if (Cache::add($cacheKey, true, self::CACHE_TTL) === true) {
                return self::CLAIM_CLAIMED;
            }
        } catch (\Throwable $e) {
            // add() 자체가 던졌다 — 선점 여부를 알 수 없다. 호출하지 않는다.
            return self::CLAIM_UNAVAILABLE;
        }

        // add() 가 false 였다. 기존 키가 실제로 있으면 중복, 없거나 확인 불가면 UNAVAILABLE.
        try {
            return Cache::get($cacheKey) !== null
                ? self::CLAIM_DUPLICATE
                : self::CLAIM_UNAVAILABLE;
        } catch (\Throwable $e) {
            // get() 도 던졌다 — 상태를 확인할 수 없으므로 호출하지 않는다.
            return self::CLAIM_UNAVAILABLE;
        }
    }

    /**
     * SubscribeURL 허용 여부 — HTTPS + AWS SNS 호스트 + 허용 리전.
     *
     * `SnsMessageValidator::isAllowedCertificateUrl()` 과 같은 호스트 규칙이지만
     * 경로 확장자(.pem) 조건은 없다. 두 URL 의 용도가 다르므로 규칙을 공유하지 않고
     * 각자 명시한다 — 한쪽을 완화해도 다른 쪽이 조용히 넓어지지 않게.
     */
    public static function isAllowedSubscribeUrl(string $url, string $region): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return false;
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'] : '';
        if ($scheme !== 'https') {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }

        $host = strtolower(isset($parts['host']) ? (string) $parts['host'] : '');
        if ($host === '') {
            return false;
        }

        if (preg_match('/^sns\.([a-z0-9-]+)\.amazonaws\.com(\.cn)?$/', $host, $m) !== 1) {
            return false;
        }

        return $m[1] === $region;
    }

    /**
     * 중복 표시를 해제한다 — 실패했으면 SNS 재시도로 다시 시도할 수 있어야 한다.
     */
    private function release(string $cacheKey): void
    {
        try {
            Cache::forget($cacheKey);
        } catch (\Throwable $e) {
            // 캐시 해제 실패가 응답을 좌우해선 안 된다.
        }
    }
}
