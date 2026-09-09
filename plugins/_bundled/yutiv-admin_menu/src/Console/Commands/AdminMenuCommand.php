<?php

namespace Plugins\Yutiv\AdminMenu\Console\Commands;

use App\Models\Menu;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Plugins\Yutiv\AdminMenu\Plugin;

/**
 * YUTIV 쇼핑몰 관리자 메뉴 배치를 검사/미리보기/적용/복원한다.
 *
 * 훅 리스너는 **동기화가 일어날 때** 배치를 적용한다. 이 명령은 모듈·코어를 재설치하지
 * 않고 **지금 바로** 같은 배치를 DB 에 반영하고, 되돌릴 수단을 제공한다. 둘은
 * `config/menu-layout.json` 하나를 공유하므로 결과가 어긋나지 않는다.
 *
 * ── 모드별 계약 ─────────────────────────────────────────────────────────────
 *   --check     읽기 전용. 목표 순서와 현재 DB 를 비교해 drift 가 있으면 **exit 1**.
 *               DB 도 스냅샷도 건드리지 않는다.
 *   --dry-run   바뀔 내용만 출력. DB·스냅샷 무변경.
 *   --apply     스냅샷 생성 → 트랜잭션 안에서 적용 → **적용 후 자체 검증** →
 *               검증 실패 시 트랜잭션 롤백(자동 원복). 두 번째 실행은 변경 0건.
 *   --rollback  적용 직전 스냅샷의 parent_id / order / 다국어 name / icon 을 정확히 복원.
 *               menu_permissions 와 extension ownership 은 손대지 않는다.
 *
 * 이 명령은 **요청 처리 경로에서 실행되지 않는다.** 콘솔 전용으로 등록되며
 * (AdminMenuServiceProvider), 부팅이나 프론트 요청에서 DB 를 쓰지 않는다.
 */
class AdminMenuCommand extends Command
{
    /** @var string */
    protected $signature = 'yutiv:admin-menu
        {--check : 목표 배치와 현재 DB 를 비교한다 (읽기 전용, drift 있으면 exit 1)}
        {--dry-run : 변경 예정 내용만 출력한다 (DB·스냅샷 무변경)}
        {--apply : 배치를 적용한다 (스냅샷 → 트랜잭션 → 자체 검증 → 실패 시 자동 롤백)}
        {--rollback : 스냅샷으로 되돌린다}
        {--snapshot= : --rollback 시 사용할 스냅샷 파일명 (미지정 시 최신)}
        {--status : 현재 배치 상태를 요약 출력한다}';

    /** @var string */
    protected $description = 'YUTIV 쇼핑몰 관리자 메뉴 배치를 검사/미리보기/적용/복원합니다';

    /** 스냅샷 디렉토리 (storage/app 기준) */
    private const SNAPSHOT_DIR = 'yutiv-admin-menu';

    public function handle(): int
    {
        $modes = array_filter([
            'check' => (bool) $this->option('check'),
            'dry-run' => (bool) $this->option('dry-run'),
            'apply' => (bool) $this->option('apply'),
            'rollback' => (bool) $this->option('rollback'),
            'status' => (bool) $this->option('status'),
        ]);

        if (count($modes) > 1) {
            $this->error('모드는 하나만 지정하세요: '.implode(', ', array_keys($modes)));

            return self::INVALID;
        }
        if ($modes === []) {
            $this->warn('--check / --dry-run / --apply / --rollback / --status 중 하나를 지정하세요.');
            $this->line('  안전을 위해 기본 동작은 없습니다. 먼저 --check 또는 --dry-run 으로 확인하세요.');

            return self::INVALID;
        }

        $mode = array_key_first($modes);

        if ($mode === 'rollback') {
            return $this->rollback();
        }
        if ($mode === 'status') {
            return $this->showStatus();
        }
        if ($mode === 'check') {
            return $this->check();
        }

        return $this->applyOrPreview($mode === 'apply');
    }

    // ── 읽기 전용 검사 ───────────────────────────────────────────────────────

    /**
     * 목표 배치와 현재 DB 를 비교한다. 아무것도 바꾸지 않는다.
     *
     * @return int drift 가 있으면 1
     */
    private function check(): int
    {
        $plan = Plugin::layoutPlan();
        $rows = Plugin::currentMenuRows();

        if ($rows === []) {
            $this->error('메뉴가 하나도 없습니다. 코어 메뉴 시드와 이커머스 모듈 설치 상태를 확인하세요.');

            return self::FAILURE;
        }

        $drift = $plan->verify($rows);

        if ($drift === []) {
            $this->info('배치 일치 — drift 0건.');
            $this->renderTopLevel($rows);

            return self::SUCCESS;
        }

        $this->warn('배치 drift '.count($drift).'건:');
        $table = [];
        foreach ($drift as $d) {
            $table[] = [
                $d['slug'],
                $this->driftLabel($d['kind']),
                $this->stringify($d['expected']),
                $this->stringify($d['actual']),
            ];
        }
        $this->table(['slug', '항목', '기대', '현재'], $table);
        $this->newLine();
        $this->line('  적용하려면: php artisan yutiv:admin-menu --apply');

        return self::FAILURE;
    }

    /** drift 종류를 사람이 읽는 말로. */
    private function driftLabel(string $kind): string
    {
        $labels = [
            'order' => '순서',
            'parent' => '상위 메뉴',
            'missing' => '메뉴 없음',
            'unprotected' => '보호 표시 없음',
        ];

        return $labels[$kind] ?? $kind;
    }

    // ── 미리보기 / 적용 ──────────────────────────────────────────────────────

    /**
     * @param  bool  $apply  true 면 실제 적용, false 면 미리보기
     */
    private function applyOrPreview(bool $apply): int
    {
        $plan = Plugin::layoutPlan();
        $rows = Plugin::currentMenuRows();

        if ($rows === []) {
            $this->error('메뉴가 하나도 없습니다. 코어 메뉴 시드와 이커머스 모듈 설치 상태를 확인하세요.');

            return self::FAILURE;
        }

        $result = $plan->planApply($rows);
        foreach ($result['warnings'] as $w) {
            $this->warn('  경고: '.$w);
        }

        if ($result['changes'] === []) {
            // 변경이 없어도 보호 표시(user_overrides) 가 빠졌을 수 있다 — 검사로 확인한다.
            $drift = $plan->verify($rows);
            if ($drift === [] || ! $apply) {
                $this->info('변경할 내용이 없습니다 — 이미 YUTIV 배치 상태입니다.');

                // 배치가 적용된 상태이므로 남아 있는 비활성화 허용 토큰은 무효다.
                if ($apply) {
                    $this->revokeDeactivationToken();
                }

                return self::SUCCESS;
            }
        }

        $this->renderChanges($result['changes']);

        if (! $apply) {
            $this->newLine();
            $this->info('dry-run 이므로 DB 와 스냅샷을 변경하지 않았습니다. 적용하려면 --apply 를 쓰세요.');

            return self::SUCCESS;
        }

        // 스냅샷은 트랜잭션 밖에서 남긴다 — 적용이 실패해도 되돌릴 근거가 남아야 한다.
        $snapshotName = $this->writeSnapshot($rows);
        $this->line('  스냅샷 저장: '.self::SNAPSHOT_DIR.'/'.$snapshotName);

        $applied = 0;
        $verifyDrift = [];

        try {
            DB::transaction(function () use ($result, $plan, &$applied, &$verifyDrift) {
                foreach ($result['changes'] as $slug => $change) {
                    $menu = Menu::where('slug', $slug)->first();
                    if (! $menu) {
                        continue;
                    }
                    foreach ($change['after'] as $field => $value) {
                        $menu->{$field} = $value;
                    }
                    // 모델 경유 저장 — HasUserOverrides 가 order/name/icon 을 user_overrides 에
                    // 기록해 이후 코어·모듈 동기화가 덮어쓰지 않게 한다.
                    $menu->save();
                    $applied++;
                }

                // 코어 메뉴의 order 보호 표시가 빠져 있으면 여기서 채운다.
                // (코어 메뉴 재시드 직후처럼 순서는 맞지만 마킹만 없는 상태)
                foreach (array_keys($plan->coreOrders()) as $slug) {
                    $menu = Menu::where('slug', $slug)->first();
                    if (! $menu) {
                        continue;
                    }
                    $overrides = is_array($menu->user_overrides) ? $menu->user_overrides : [];
                    if (! in_array('order', $overrides, true)) {
                        $overrides[] = 'order';
                        $menu->user_overrides = $overrides;
                        $menu->save();
                    }
                }

                // ★ 적용 후 자체 검증 — 실패하면 예외를 던져 트랜잭션을 통째로 되돌린다.
                $verifyDrift = $plan->verify(Plugin::currentMenuRows());
                if ($verifyDrift !== []) {
                    throw new \RuntimeException('적용 후 검증 실패 — drift '.count($verifyDrift).'건');
                }
            });
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('적용 실패 — 트랜잭션을 되돌렸습니다. DB 는 적용 전 상태입니다.');
            $this->line('  사유: '.$e->getMessage());
            foreach (array_slice($verifyDrift, 0, 8) as $d) {
                $this->line(sprintf('    - %s / %s / 기대 %s / 현재 %s',
                    $d['slug'], $this->driftLabel($d['kind']), $this->stringify($d['expected']), $this->stringify($d['actual'])));
            }
            $this->line('  스냅샷은 남아 있습니다: '.self::SNAPSHOT_DIR.'/'.$snapshotName);

            return self::FAILURE;
        }

        // ★ 적용에 성공했으므로 비활성화 허용 토큰은 더 이상 유효하지 않다 — 반드시 제거한다.
        //   (토큰은 "되돌린 상태" 를 나르는 것이고, 지금은 다시 적용된 상태다.)
        $this->revokeDeactivationToken();

        $this->newLine();
        $this->info("적용 완료 — 메뉴 {$applied}건 변경, 적용 후 검증 통과.");
        $this->renderTopLevel(Plugin::currentMenuRows());
        $this->newLine();
        $this->line('  되돌리려면: php artisan yutiv:admin-menu --rollback');
        $this->line('  ※ 플러그인 비활성화 전에 반드시 --rollback 을 먼저 실행하세요 —');
        $this->line('    비활성화하면 이 명령 자체가 사라져 되돌릴 수단이 없어집니다.');
        $this->line('  화면 반영: php artisan cache:clear');

        return self::SUCCESS;
    }

    // ── 롤백 ────────────────────────────────────────────────────────────────

    private function rollback(): int
    {
        $disk = Storage::disk('local');
        $name = $this->option('snapshot');

        if (! $name) {
            if (! $disk->exists(self::SNAPSHOT_DIR.'/latest.txt')) {
                $this->error('스냅샷이 없습니다. --apply 를 한 적이 없거나 스냅샷이 삭제됐습니다.');

                return self::FAILURE;
            }
            $name = trim((string) $disk->get(self::SNAPSHOT_DIR.'/latest.txt'));
        }

        $path = self::SNAPSHOT_DIR.'/'.$name;
        if (! $disk->exists($path)) {
            $this->error('스냅샷 파일을 찾을 수 없습니다: '.$path);

            return self::FAILURE;
        }

        $payload = json_decode((string) $disk->get($path), true);
        if (! is_array($payload) || ! isset($payload['menus'])) {
            $this->error('스냅샷 형식이 올바르지 않습니다: '.$path);

            return self::FAILURE;
        }

        $restored = 0;
        $missing = [];

        try {
            DB::transaction(function () use ($payload, &$restored, &$missing) {
            foreach ($payload['menus'] as $row) {
                $menu = Menu::where('slug', $row['slug'])->first();
                if (! $menu) {
                    $missing[] = $row['slug'];

                    continue;
                }
                // 되돌리는 것은 이 플러그인이 바꾼 네 가지뿐이다.
                // menu_permissions / role_menus / extension_type / extension_identifier / url 은
                // 애초에 건드리지 않았으므로 복원 대상도 아니다.
                $menu->parent_id = $row['parent_id'];
                $menu->order = $row['order'];
                $menu->name = $row['name'];
                $menu->icon = $row['icon'];
                if (array_key_exists('user_overrides', $row)) {
                    $menu->user_overrides = $row['user_overrides'];
                }
                $menu->save();
                $restored++;
            }
            });
        } catch (\Throwable $e) {
            // 트랜잭션이 통째로 되돌아갔다 — 복원되지 않았으므로 토큰을 주지 않는다.
            $this->error('복원 실패 — 트랜잭션을 되돌렸습니다. DB 는 복원 시도 전 상태입니다.');
            $this->line('  사유: '.$e->getMessage());
            $this->failRollback('복원 트랜잭션 실패');

            return self::FAILURE;
        }

        // ★ 부분 실패 판정 — 스냅샷에 있던 slug 가 지금 DB 에 없으면 완전 복원이 아니다.
        if ($missing !== []) {
            $this->error('부분 복원 — 스냅샷의 메뉴 '.count($missing).'건을 DB 에서 찾지 못했습니다.');
            foreach (array_slice($missing, 0, 8) as $slug) {
                $this->line('    - '.$slug);
            }
            $this->failRollback('부분 복원 (누락 '.count($missing).'건)');

            return self::FAILURE;
        }

        // ★ 복원 후 검증 — 스냅샷 값과 현재 DB 가 필드 단위로 일치하는지 다시 읽어 확인한다.
        $mismatch = $this->verifyRestored($payload['menus']);
        if ($mismatch !== []) {
            $this->error('복원 후 검증 실패 — 스냅샷과 다른 메뉴 '.count($mismatch).'건.');
            foreach (array_slice($mismatch, 0, 8) as $m) {
                $this->line(sprintf('    - %s / %s / 기대 %s / 현재 %s',
                    $m['slug'], $m['field'], $this->stringify($m['expected']), $this->stringify($m['actual'])));
            }
            $this->failRollback('복원 후 검증 실패 ('.count($mismatch).'건)');

            return self::FAILURE;
        }

        $this->info("복원 완료 — 스냅샷 {$name} 기준으로 메뉴 {$restored}건 되돌렸고, 복원 후 검증도 통과했습니다.");

        // ★ 복원과 검증이 **모두** 성공한 지금에만 비활성화 허용 토큰을 발급한다.
        $token = Plugin::deactivationToken();
        $issued = $token->issue([
            'snapshot' => $name,
            'restored' => $restored,
        ]);

        $this->newLine();
        if ($issued) {
            $this->line('  비활성화 허용 토큰 발급: '.$token->path());
            $this->line('  ※ 일회용입니다 — plugin:deactivate 가 한 번 쓰고 삭제합니다.');
        } else {
            $this->warn('  비활성화 허용 토큰을 쓰지 못했습니다 (권한·디스크 확인). 비활성화가 차단될 수 있습니다.');
        }

        $this->warn('  플러그인이 활성 상태이면 다음 모듈·코어 동기화에서 배치가 다시 적용됩니다.');
        $this->line('  자동 재적용을 멈추려면 이어서 비활성화하세요:');
        $this->line('    php artisan plugin:deactivate yutiv-admin_menu');

        return self::SUCCESS;
    }

    /**
     * 복원 후 검증 — 스냅샷 값과 현재 DB 를 필드 단위로 비교한다.
     *
     * @param  array<int, array<string, mixed>>  $snapshotMenus
     * @return array<int, array{slug: string, field: string, expected: mixed, actual: mixed}>
     */
    private function verifyRestored(array $snapshotMenus): array
    {
        $current = [];
        foreach (Plugin::currentMenuRows() as $row) {
            $current[$row['slug']] = $row;
        }

        $mismatch = [];
        foreach ($snapshotMenus as $row) {
            $slug = $row['slug'];
            if (! isset($current[$slug])) {
                $mismatch[] = ['slug' => $slug, 'field' => '메뉴 없음', 'expected' => '존재', 'actual' => '없음'];

                continue;
            }
            $now = $current[$slug];

            foreach (['parent_id', 'order', 'icon'] as $field) {
                if ((string) ($now[$field] ?? '') !== (string) ($row[$field] ?? '')) {
                    $mismatch[] = ['slug' => $slug, 'field' => $field, 'expected' => $row[$field] ?? null, 'actual' => $now[$field] ?? null];
                }
            }

            // 다국어 name 은 로케일별로 비교한다 (배열 순서 차이를 오탐하지 않게).
            $expectedName = is_array($row['name'] ?? null) ? $row['name'] : [];
            $actualName = is_array($now['name'] ?? null) ? $now['name'] : [];
            foreach ($expectedName as $locale => $value) {
                if (($actualName[$locale] ?? null) !== $value) {
                    $mismatch[] = ['slug' => $slug, 'field' => "name.{$locale}", 'expected' => $value, 'actual' => $actualName[$locale] ?? null];
                }
            }
        }

        return $mismatch;
    }

    /**
     * 롤백 실패·부분 실패 처리 — 토큰을 **발급하지 않고**, 남아 있던 토큰도 폐기한다.
     *
     * 되돌리지 못한 상태에서 비활성화를 허용하면 되돌릴 수단(이 명령)이 사라진다.
     */
    private function failRollback(string $reason): void
    {
        $this->newLine();
        $this->warn('  비활성화 허용 토큰을 발급하지 않았습니다 — 사유: '.$reason);
        $this->line('  DB 메뉴는 복원되지 않았거나 일부만 복원됐습니다. 비활성화는 계속 차단됩니다.');

        if ($this->revokeDeactivationToken()) {
            $this->line('  기존에 남아 있던 허용 토큰도 폐기했습니다.');
        }
    }

    /**
     * 비활성화 허용 토큰을 폐기한다.
     *
     * @return bool 실제로 지웠으면 true
     */
    private function revokeDeactivationToken(): bool
    {
        try {
            return Plugin::deactivationToken()->revoke();
        } catch (\Throwable $e) {
            $this->warn('  허용 토큰 폐기 실패: '.$e->getMessage());

            return false;
        }
    }

    // ── 상태 ────────────────────────────────────────────────────────────────

    private function showStatus(): int
    {
        $plan = Plugin::layoutPlan();
        $rows = Plugin::currentMenuRows();
        $drift = $plan->verify($rows);

        $this->line('대상 모듈: '.$plan->targetModule());
        $this->newLine();
        $this->renderTopLevel($rows);
        $this->newLine();

        if ($drift === []) {
            $this->info('배치 적용 상태입니다 (drift 0건).');
        } else {
            $this->warn('drift '.count($drift).'건 — php artisan yutiv:admin-menu --check 로 확인하세요.');
        }

        return self::SUCCESS;
    }

    // ── 출력 헬퍼 ───────────────────────────────────────────────────────────

    /** 최상위 메뉴를 순서대로 표로 출력한다. */
    private function renderTopLevel(array $rows): void
    {
        $top = array_values(array_filter($rows, static function ($r) {
            return $r['parent_id'] === null;
        }));
        usort($top, static function ($a, $b) {
            return $a['order'] <=> $b['order'];
        });

        $table = [];
        foreach ($top as $r) {
            $name = is_array($r['name']) ? ($r['name']['ko'] ?? reset($r['name'])) : $r['name'];
            $table[] = [$r['order'], $name, $r['slug'], (string) $r['url']];
        }
        $this->table(['order', '이름(ko)', 'slug', 'URL'], $table);
    }

    /** 변경 목록을 표로 출력한다. */
    private function renderChanges(array $changes): void
    {
        $rows = [];
        foreach ($changes as $slug => $c) {
            foreach ($c['after'] as $field => $to) {
                $rows[] = [$slug, $field, $this->stringify($c['before'][$field] ?? null), $this->stringify($to)];
            }
        }
        $this->table(['slug', '필드', '현재', '변경 후'], $rows);
    }

    /**
     * @param  mixed  $value
     */
    private function stringify($value): string
    {
        if ($value === null) {
            return '(최상위)';
        }
        if (is_array($value)) {
            return isset($value['ko']) ? (string) $value['ko'] : json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }

    /**
     * 적용 전 상태를 스냅샷으로 남긴다.
     *
     * @return string 저장한 파일명
     */
    private function writeSnapshot(array $rows): string
    {
        $name = 'snapshot-'.date('Ymd-His').'.json';
        $payload = [
            'created_at' => date('c'),
            'note' => 'yutiv:admin-menu --apply 직전의 메뉴 상태. --rollback 이 이 값으로 되돌린다.',
            'menus' => $rows,
        ];
        Storage::disk('local')->put(
            self::SNAPSHOT_DIR.'/'.$name,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        Storage::disk('local')->put(self::SNAPSHOT_DIR.'/latest.txt', $name);

        return $name;
    }
}
