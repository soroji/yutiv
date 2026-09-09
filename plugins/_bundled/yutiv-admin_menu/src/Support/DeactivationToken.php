<?php

namespace Plugins\Yutiv\AdminMenu\Support;

/**
 * 비활성화 허용 토큰 — `storage/app/yutiv-admin-menu/allow-deactivate`.
 *
 * 이 플러그인을 끄면 `yutiv:admin-menu` 명령이 등록 해제되어 `--rollback` 을 쓸 수
 * 없게 된다. 그래서 배치가 적용된 상태에서는 비활성화를 막고, **되돌린 뒤에만**
 * 비활성화를 허용한다. 그 "되돌렸다" 는 사실을 나르는 것이 이 토큰이다.
 *
 * ── 계약 ────────────────────────────────────────────────────────────────────
 *   발급(issue)   `--rollback` 이 복원과 **복원 후 검증까지 모두 성공**했을 때만.
 *   폐기(revoke)  `--apply` 가 성공했을 때 (배치가 다시 적용됐으므로 토큰은 무효),
 *                 그리고 롤백이 실패·부분 실패했을 때.
 *   소비(consume) `plugin:deactivate` 가 한 번 쓰고 **즉시 삭제**한다.
 *                 한 번 만든 파일로 이후 비활성화를 반복 허용하지 않는다 (일회용).
 *
 * ── 왜 Laravel 을 안 쓰는가 ─────────────────────────────────────────────────
 * 경로를 생성자로 받는 순수 클래스라, PHP 7.4 독립 하네스가 **이 프로덕션 코드 그대로**
 * 발급·소비·폐기 semantics 를 실행 검증할 수 있다 (`MenuLayoutPlan` 과 같은 이유).
 */
class DeactivationToken
{
    /** @var string */
    private $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /** 디렉토리 기준으로 만든다 (운영에서는 storage/app/yutiv-admin-menu). */
    public static function inDirectory(string $dir): self
    {
        // chr(92) = 역슬래시. Windows 경로 구분자를 정규화한다.
        return new self(rtrim(str_replace(chr(92), '/', $dir), '/').'/allow-deactivate');
    }

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * 토큰을 발급한다. 호출 측이 **복원 성공 + 검증 성공**을 확인한 뒤에만 부를 것.
     *
     * @param  array<string, mixed>  $meta  감사 흔적 (스냅샷명·복원 건수·시각)
     */
    public function issue(array $meta = []): bool
    {
        $dir = dirname($this->path);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return false;
        }

        $payload = array_merge([
            'issued_at' => date('c'),
            'issued_by' => 'yutiv:admin-menu --rollback',
            'note' => '일회용입니다. plugin:deactivate 가 한 번 쓰고 삭제합니다.',
        ], $meta);

        return @file_put_contents(
            $this->path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ) !== false;
    }

    /**
     * 토큰을 한 번 쓰고 즉시 삭제한다.
     *
     * @return array<string, mixed>|null 토큰이 없었으면 null. 있었으면 페이로드(파싱 실패 시 빈 배열).
     */
    public function consume(): ?array
    {
        if (! $this->exists()) {
            return null;
        }

        $raw = @file_get_contents($this->path);
        $payload = is_string($raw) ? json_decode($raw, true) : null;

        // 삭제가 먼저다 — 소비 후 남아 있으면 일회용 계약이 깨진다.
        @unlink($this->path);

        return is_array($payload) ? $payload : [];
    }

    /**
     * 토큰을 폐기한다 (있으면 삭제).
     *
     * @return bool 실제로 지웠으면 true
     */
    public function revoke(): bool
    {
        if (! $this->exists()) {
            return false;
        }

        return @unlink($this->path);
    }
}
