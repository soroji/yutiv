<?php

namespace Plugins\Yutiv\SesMonitor\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Plugins\Yutiv\SesMonitor\Models\SesEventLog;
use Plugins\Yutiv\SesMonitor\Models\SesMessageLink;
use Plugins\Yutiv\SesMonitor\Plugin;
use Plugins\Yutiv\SesMonitor\Support\SesConfig;

/**
 * `yutiv:ses-status` — SES 모니터 상태를 읽기 전용으로 출력한다.
 *
 * health check·운영 점검용. **DB 를 쓰지 않는다**(조회만) 그리고
 * **비밀값과 개인정보 전체를 출력하지 않는다**:
 *   · TopicArn 은 계정 ID 를 마스킹한다.
 *   · 수신자 주소·제목·원문 payload 는 출력하지 않는다.
 *   · SMTP 비밀번호, AWS 키 같은 값은 애초에 읽지 않는다.
 */
class SesMonitorStatusCommand extends Command
{
    /** @var string */
    protected $signature = 'yutiv:ses-status {--json : 기계 판독용 JSON 으로 출력한다}';

    /** @var string */
    protected $description = 'YUTIV SES 이벤트 모니터의 설정·수집 상태를 출력합니다 (읽기 전용)';

    public function handle(): int
    {
        $summary = Plugin::statusSummary();
        $tableReady = Schema::hasTable('ses_event_logs');

        $counts = [];
        $total = 0;
        $latest = null;
        $unlinked = 0;
        $linked = 0;

        if ($tableReady) {
            foreach (SesConfig::allowedEventTypes() as $type) {
                $counts[$type] = SesEventLog::where('event_type', $type)->count();
                $total += $counts[$type];
            }

            $latest = SesEventLog::max('occurred_at');

            if (Schema::hasTable('ses_message_links')) {
                $linked = SesMessageLink::whereNotNull('notification_log_id')->count();
                $unlinked = SesEventLog::query()
                    ->leftJoin('ses_message_links', 'ses_message_links.ses_message_id', '=', 'ses_event_logs.ses_message_id')
                    ->whereNull('ses_message_links.notification_log_id')
                    ->count();
            }
        }

        $payload = [
            'endpoint' => $summary['endpoint'],
            'webhook_enabled' => $summary['webhook_enabled'],
            'header_enabled' => $summary['header_enabled'],
            'configuration_set' => $summary['configuration_set'],
            'region' => $summary['region'],
            'topic_arn' => $summary['topic_arn'],
            'auto_confirm' => $summary['auto_confirm'],
            'control_max_age_seconds' => $summary['control_max_age_seconds'],
            'max_future_skew_seconds' => $summary['max_future_skew_seconds'],
            'notification_max_age_seconds' => null, // Notification 에는 과거 상한이 없다
            'legacy_max_age_env_present' => $summary['legacy_max_age_env_present'],
            'raw_payload_enabled' => $summary['raw_payload_enabled'],
            'retention_days' => $summary['retention_days'],
            'table_ready' => $tableReady,
            'event_total' => $total,
            'event_counts' => $counts,
            'latest_event_at' => $latest,
            'linked_messages' => $linked,
            'unlinked_events' => $unlinked,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('YUTIV SES 이벤트 모니터 상태');
        $this->newLine();

        $this->table(['항목', '값'], [
            ['endpoint', $payload['endpoint']],
            ['webhook 활성', $this->yn($payload['webhook_enabled'])],
            ['헤더 삽입 활성', $this->yn($payload['header_enabled'])],
            ['configuration set', $payload['configuration_set'] !== '' ? $payload['configuration_set'] : '(미설정)'],
            ['region', $payload['region']],
            ['topic arn (마스킹)', $payload['topic_arn'] !== '' ? $payload['topic_arn'] : '(미설정)'],
            ['SubscribeURL 자동 호출(auto-confirm)', $payload['auto_confirm'] ? '켜짐 — 최초 구독 후 즉시 꺼야 함' : '꺼짐 (권장 상태)'],
            ['Timestamp — 미래 허용 오차(초, 전 타입)', (string) $payload['max_future_skew_seconds']],
            ['Timestamp — 제어 메시지 과거 상한(초)', (string) $payload['control_max_age_seconds']],
            ['Timestamp — Notification 과거 상한', '없음 (지연 도착 이벤트를 버리지 않음)'],
            ['원문 payload 저장', $this->yn($payload['raw_payload_enabled'])],
            ['보존 기간(일)', (string) $payload['retention_days']],
            ['테이블 준비', $this->yn($payload['table_ready'])],
        ]);

        if (! $tableReady) {
            $this->warn('ses_event_logs 테이블이 없습니다. 마이그레이션을 먼저 실행하세요.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('최근 이벤트 시각: '.($latest ?? '(없음)'));
        $this->line('총 이벤트: '.$total.'건 · 연결됨 '.$linked.'건 · 미연결 '.$unlinked.'건');
        $this->newLine();

        $rows = [];
        foreach ($counts as $type => $count) {
            $rows[] = [$type, (string) $count];
        }
        $this->table(['이벤트 타입', '건수'], $rows);

        if (! $payload['webhook_enabled']) {
            $this->warn('SES_SNS_TOPIC_ARN 이 비어 있어 webhook endpoint 가 등록되지 않았습니다.');
        }

        if (! $payload['header_enabled']) {
            $this->warn('SES_CONFIGURATION_SET 이 비어 있어 발송 메일에 헤더를 붙이지 않습니다.');
        }

        // 폐기된 설정이 .env 에 남아 있으면 "설정했는데 왜 안 먹지" 로 오해한다.
        if ($summary['legacy_max_age_env_present']) {
            $this->warn('SES_SNS_MAX_AGE_SECONDS 가 .env 에 남아 있습니다 — 이 값은 더 이상 사용되지 않습니다.');
            $this->warn('SES_SNS_MAX_FUTURE_SKEW_SECONDS / SES_SNS_CONTROL_MAX_AGE_SECONDS 로 대체됐습니다. 항목을 제거하세요.');
        }

        // auto-confirm 은 켜 둔 채 잊기 쉬운 스위치다. 켜져 있으면 매번 눈에 띄게 경고한다.
        if ($payload['auto_confirm']) {
            $this->warn('SES_SNS_AUTO_CONFIRM=true 입니다 — 검증을 통과한 SubscriptionConfirmation 의');
            $this->warn('SubscribeURL 을 자동 호출합니다. 최초 구독을 확정했으면 즉시 false 로 되돌리고');
            $this->warn('php artisan config:cache 를 실행하세요.');
        } elseif ($total === 0 && $payload['webhook_enabled']) {
            $this->warn('수신된 이벤트가 0건입니다. SNS 구독이 PendingConfirmation 상태일 수 있습니다.');
            $this->warn('README 의 "최초 구독 절차" 를 따라 한 번만 auto-confirm 을 켜서 확정하세요.');
        }

        return self::SUCCESS;
    }

    private function yn(bool $value): string
    {
        return $value ? '예' : '아니오';
    }
}
