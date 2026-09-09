<?php

namespace Plugins\Yutiv\SesMonitor;

use App\Enums\ExtensionOwnerType;
use App\Extension\AbstractPlugin;
use App\Extension\Helpers\ExtensionMenuSyncHelper;
use Plugins\Yutiv\SesMonitor\Listeners\LinkNotificationLogListener;
use Plugins\Yutiv\SesMonitor\Support\SesConfig;

/**
 * YUTIV Amazon SES 이벤트 모니터.
 *
 * ── 이 플러그인이 지키는 사실 구분 ──────────────────────────────────────────
 * `notification_logs` 는 **애플리케이션이 발송을 시도했다** 는 기록이다.
 * SES 이벤트는 **AWS 가 그 메일을 실제로 어떻게 처리했는지** 알려준 결과다.
 * 두 사실은 출처도 시점도 다르므로 이 플러그인은
 *   · `notification_logs` 를 **읽기만** 하고,
 *   · `status` 컬럼의 `sent` 를 SES `delivery` 로 바꾸거나 덮어쓰지 않으며,
 *   · SES 결과는 전부 append-only `ses_event_logs` 에 따로 쌓는다.
 * 연결은 플러그인 소유 `ses_message_links` 를 통해서만 이뤄진다.
 *
 * ── 안전한 기본값 ──────────────────────────────────────────────────────────
 * `SES_SNS_TOPIC_ARN` 이 없으면 공개 endpoint 를 등록하지 않고,
 * `SES_CONFIGURATION_SET` 이 없으면 메일 헤더를 붙이지 않는다.
 * 즉 환경값을 넣기 전까지 이 플러그인은 아무 것도 하지 않는다.
 */
class Plugin extends AbstractPlugin
{
    /**
     * 훅 리스너 — 발송 이력이 기록될 때 SES 메시지 ID 매핑을 남긴다.
     *
     * @return array<class-string>
     */
    public function getHookListeners(): array
    {
        return [
            LinkNotificationLogListener::class,
        ];
    }

    /**
     * 관리자 메뉴.
     *
     * 권한은 코어의 `core.notification-logs.read` 를 그대로 쓴다 — SES 이벤트는
     * 발송 이력의 결과이므로 별도 권한 체계를 새로 만들지 않는다.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAdminMenus(): array
    {
        return [
            [
                'name' => [
                    'ko' => 'SES 이벤트',
                    'en' => 'SES Events',
                    'ja' => 'SES イベント',
                    'zh-CN' => 'SES 事件',
                ],
                'slug' => 'yutiv-ses_monitor-events',
                'url' => '/admin/plugins/yutiv-ses_monitor/events',
                'icon' => 'fas fa-envelope-circle-check',
                'order' => 60,
            ],
        ];
    }

    /**
     * 이 플러그인은 자체 권한을 선언하지 않는다.
     *
     * 요구사항대로 기존 `core.notification-logs.read` 권한 모델을 존중한다.
     * 새 권한을 만들면 역할 설정이 이원화되어 "발송 이력은 못 보는데 SES 결과는
     * 보이는" 상태가 생길 수 있다.
     *
     * @return array<string, mixed>
     */
    public function getPermissions(): array
    {
        return [];
    }

    /**
     * 보존기간 정리 스케줄.
     *
     * 매일 새벽에 90일(기본)이 지난 SES 이벤트를 삭제한다.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSchedules(): array
    {
        return [
            [
                'command' => 'yutiv:ses-prune',
                'schedule' => 'daily',
                'description' => 'SES 이벤트 로그 보존기간(기본 90일) 경과분 삭제',
            ],
        ];
    }

    /**
     * 활성화 — 관리자 메뉴를 등록한다.
     *
     * 모듈과 달리 플러그인은 코어가 `getAdminMenus()` 를 자동 처리하지 않으므로
     * 여기서 동기화 헬퍼를 직접 호출한다 (sirsoft-gdpr 와 동일 패턴).
     */
    public function activate(): bool
    {
        $helper = app(ExtensionMenuSyncHelper::class);

        foreach ($this->getAdminMenus() as $menuData) {
            $helper->syncMenuRecursive(
                $menuData,
                ExtensionOwnerType::Plugin,
                $this->getIdentifier(),
            );
        }

        return parent::activate();
    }

    /**
     * 현재 설정 요약 (status 명령·관리자 화면용).
     *
     * **비밀값을 담지 않는다.** TopicArn 은 계정 ID 를 포함하므로 마스킹한다.
     *
     * @return array<string, mixed>
     */
    public static function statusSummary(): array
    {
        return [
            'endpoint' => '/'.SesConfig::endpointPath(),
            'webhook_enabled' => SesConfig::webhookEnabled(),
            'header_enabled' => SesConfig::headerEnabled(),
            'configuration_set' => SesConfig::configurationSet(),
            'region' => SesConfig::region(),
            'topic_arn' => self::maskArn(SesConfig::topicArn()),
            'auto_confirm' => SesConfig::autoConfirm(),
            'control_max_age_seconds' => SesConfig::controlMaxAgeSeconds(),
            'max_future_skew_seconds' => SesConfig::maxFutureSkewSeconds(),
            'legacy_max_age_env_present' => SesConfig::legacyMaxAgeEnvPresent(),
            'raw_payload_enabled' => SesConfig::rawPayloadEnabled(),
            'retention_days' => SesConfig::retentionDays(),
        ];
    }

    /**
     * `arn:aws:sns:ap-northeast-2:123456789012:yutiv-ses-events`
     * → `arn:aws:sns:ap-northeast-2:****:yutiv-ses-events`
     */
    public static function maskArn(string $arn): string
    {
        if ($arn === '') {
            return '';
        }

        $parts = explode(':', $arn);

        if (count($parts) < 6) {
            return '****';
        }

        $parts[4] = '****';

        return implode(':', $parts);
    }
}
