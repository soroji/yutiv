<?php

namespace Plugins\Yutiv\SesMonitor\Tests\Support;

/**
 * 로그 호출을 메모리에 받아 적는 테스트용 로거.
 *
 * ── 왜 Log::listen() 을 쓰지 않는가 ─────────────────────────────────────────
 * `Log::listen()` 은 `MessageLogged` 이벤트에 의존한다. 이 프로젝트의 기본 채널은
 * `stack` 이고, 채널 구성·이벤트 디스패처 상태에 따라 이벤트가 오지 않을 수 있어
 * 서버 실행에서 구조화 로그 단언이 재현성 없이 실패했다.
 *
 * 이 클래스는 `log` 바인딩 자체를 대체해 **호출을 직접** 받아 적는다. 이벤트를 거치지
 * 않으므로 채널 설정과 무관하게 결정적이다. 프로젝트의 `Log::spy()` 관례와 같은 방향
 * (Mockery 대신 단순 기록기를 쓰는 것은, 전체 호출을 덤프해 민감정보 부재까지
 *  한 번에 검사하기 위해서다).
 *
 * 실제 파일 기록은 하지 않는다 — 테스트가 로그 파일을 더럽히지 않는다.
 */
class RecordingLogSpy
{
    /** @var array<int, array{level: string, message: string, context: array}> */
    private array $records = [];

    /**
     * 기록된 전체 호출.
     *
     * @return array<int, array{level: string, message: string, context: array}>
     */
    public function records(): array
    {
        return $this->records;
    }

    /**
     * 기록된 메시지 이름 목록 (구조화 로그의 event 명).
     *
     * @return array<int, string>
     */
    public function messages(): array
    {
        return array_column($this->records, 'message');
    }

    /**
     * 특정 event 명의 첫 기록.
     *
     * @return array{level: string, message: string, context: array}|null
     */
    public function first(string $message): ?array
    {
        foreach ($this->records as $record) {
            if ($record['message'] === $message) {
                return $record;
            }
        }

        return null;
    }

    /**
     * 전체 기록을 한 문자열로 — 민감정보 부재 검사를 한 번에 하기 위한 덤프.
     */
    public function dump(): string
    {
        return (string) json_encode(
            $this->records,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
    }

    public function reset(): void
    {
        $this->records = [];
    }

    // ── 로거 표면 ───────────────────────────────────────────────────────────
    // LogManager 가 제공하는 것 중 코어·플러그인이 실제로 쓰는 것만 흉내 낸다.

    public function log($level, $message = '', array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => is_string($message) ? $message : (string) json_encode($message),
            'context' => $context,
        ];
    }

    public function emergency($message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert($message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical($message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error($message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning($message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice($message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info($message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug($message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /**
     * `Log::channel('x')->info(...)` / `Log::stack([...])` 같은 호출도 같은 기록기로 모은다.
     */
    public function channel($channel = null): self
    {
        return $this;
    }

    public function driver($driver = null): self
    {
        return $this;
    }

    public function stack(array $channels, $channel = null): self
    {
        return $this;
    }

    public function withContext(array $context = []): self
    {
        return $this;
    }

    public function shareContext(array $context = []): self
    {
        return $this;
    }

    /**
     * 흉내 내지 않은 메서드는 조용히 무시한다 — 로거 때문에 테스트가 깨지면 안 된다.
     *
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): void
    {
        // no-op
    }
}
