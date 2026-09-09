<?php

namespace Plugins\Yutiv\SesMonitor\Http\Controllers\Admin;

use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Plugins\Yutiv\SesMonitor\Models\SesEventLog;
use Plugins\Yutiv\SesMonitor\Support\SesConfig;

/**
 * 관리자용 SES 이벤트 조회 API.
 *
 * ── 권한 ────────────────────────────────────────────────────────────────────
 * 라우트에서 `permission:admin,core.notification-logs.read` 로 게이트한다.
 * SES 이벤트는 발송 이력의 결과이므로 같은 권한을 요구하는 것이 맞고, 새 권한을
 * 만들면 역할 설정이 이원화된다.
 *
 * ── 개인정보 ────────────────────────────────────────────────────────────────
 * 목록 응답의 수신자는 **항상 마스킹**한다(`a***e@example.com`).
 * `raw_payload` 는 목록에 절대 싣지 않으며, 상세 조회에서도
 * `SES_EVENT_RAW_PAYLOAD_ENABLED=true` + 명시적 `include_raw=1` 일 때만 나간다.
 */
class SesEventLogController extends AdminBaseController
{
    /** 목록 응답에 허용하는 이벤트 필터. */
    private const SORTABLE = ['occurred_at', 'created_at', 'event_type'];

    /**
     * SES 이벤트 목록.
     *
     * 필터: `event_type`, `linked`(linked|unlinked), `ses_message_id`, `from`, `to`
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'event_type' => ['nullable', 'string', 'max:30'],
            'linked' => ['nullable', 'string', 'in:linked,unlinked'],
            'ses_message_id' => ['nullable', 'string', 'max:200'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort_by' => ['nullable', 'string', 'in:'.implode(',', self::SORTABLE)],
            'sort_order' => ['nullable', 'string', 'in:asc,desc'],
        ]);

        $query = SesEventLog::query()
            ->leftJoin('ses_message_links', 'ses_message_links.ses_message_id', '=', 'ses_event_logs.ses_message_id')
            ->leftJoin('notification_logs', 'notification_logs.id', '=', 'ses_message_links.notification_log_id')
            ->select([
                'ses_event_logs.*',
                'ses_message_links.notification_log_id as linked_notification_log_id',
                'notification_logs.subject as linked_subject',
                'notification_logs.notification_type as linked_notification_type',
                'notification_logs.sent_at as linked_sent_at',
            ]);

        if (! empty($validated['event_type'])) {
            // allowlist 안의 값만 통과 — 임의 문자열로 인덱스를 낭비하지 않는다.
            if (in_array($validated['event_type'], SesConfig::allowedEventTypes(), true)) {
                $query->where('ses_event_logs.event_type', $validated['event_type']);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if (($validated['linked'] ?? null) === 'linked') {
            $query->whereNotNull('ses_message_links.notification_log_id');
        } elseif (($validated['linked'] ?? null) === 'unlinked') {
            $query->whereNull('ses_message_links.notification_log_id');
        }

        if (! empty($validated['ses_message_id'])) {
            $query->where('ses_event_logs.ses_message_id', $validated['ses_message_id']);
        }

        if (! empty($validated['from'])) {
            $query->where('ses_event_logs.occurred_at', '>=', $validated['from']);
        }

        if (! empty($validated['to'])) {
            $query->where('ses_event_logs.occurred_at', '<=', $validated['to']);
        }

        $sortBy = $validated['sort_by'] ?? 'occurred_at';
        $sortOrder = $validated['sort_order'] ?? 'desc';

        $paginator = $query
            ->orderBy('ses_event_logs.'.$sortBy, $sortOrder)
            ->paginate($validated['per_page'] ?? 20);

        return $this->success('OK', [
            'items' => array_map([$this, 'presentRow'], $paginator->items()),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * 이벤트 타입별 집계 + 최근 이벤트 시각.
     *
     * 발송 이력 화면에 얹는 요약 패널이 이 응답을 쓴다.
     */
    public function summary(Request $request): JsonResponse
    {
        $counts = SesEventLog::query()
            ->select('event_type', DB::raw('COUNT(*) as total'))
            ->groupBy('event_type')
            ->pluck('total', 'event_type')
            ->all();

        $byType = [];
        foreach (SesConfig::allowedEventTypes() as $type) {
            $byType[$type] = (int) ($counts[$type] ?? 0);
        }

        $unlinked = SesEventLog::query()
            ->leftJoin('ses_message_links', 'ses_message_links.ses_message_id', '=', 'ses_event_logs.ses_message_id')
            ->whereNull('ses_message_links.notification_log_id')
            ->count();

        $latest = SesEventLog::query()->max('occurred_at');

        return $this->success('OK', [
            'enabled' => SesConfig::webhookEnabled(),
            'endpoint' => '/'.SesConfig::endpointPath(),
            'counts' => $byType,
            'total' => array_sum($byType),
            'unlinked' => $unlinked,
            'latest_event_at' => $latest,
        ]);
    }

    /**
     * 특정 발송 이력에 연결된 SES 이벤트.
     *
     * 발송 이력 상세에서 "이 메일이 실제로 어떻게 됐는지" 를 보여주기 위한 것으로,
     * `notification_logs` 는 조회만 하고 수정하지 않는다.
     */
    public function forNotificationLog(Request $request, int $notificationLog): JsonResponse
    {
        $events = SesEventLog::query()
            ->join('ses_message_links', 'ses_message_links.ses_message_id', '=', 'ses_event_logs.ses_message_id')
            ->where('ses_message_links.notification_log_id', $notificationLog)
            ->orderBy('ses_event_logs.occurred_at')
            ->select('ses_event_logs.*')
            ->get();

        return $this->success('OK', [
            'notification_log_id' => $notificationLog,
            'events' => $events->map(fn (SesEventLog $e) => $this->presentRow($e))->all(),
        ]);
    }

    /**
     * 단건 상세. 원문은 설정 + 명시 요청이 모두 있을 때만 포함한다.
     */
    public function show(Request $request, SesEventLog $sesEvent): JsonResponse
    {
        $row = $this->presentRow($sesEvent);

        $wantsRaw = $request->boolean('include_raw');

        if ($wantsRaw && SesConfig::rawPayloadEnabled()) {
            $row['raw_payload'] = $sesEvent->raw_payload;
        } elseif ($wantsRaw) {
            $row['raw_payload'] = null;
            $row['raw_payload_note'] = 'SES_EVENT_RAW_PAYLOAD_ENABLED 가 꺼져 있어 원문을 저장하지 않았습니다.';
        }

        return $this->success('OK', $row);
    }

    /**
     * 응답 한 줄. **원문 payload 와 평문 수신자는 절대 포함하지 않는다.**
     *
     * @return array<string, mixed>
     */
    private function presentRow(SesEventLog $event): array
    {
        return [
            'id' => $event->id,
            'event_type' => $event->event_type,
            'ses_message_id' => $event->ses_message_id,
            'source' => $event->source,
            'recipients_masked' => $event->maskedRecipients(),
            'recipient_count' => is_array($event->recipients) ? count($event->recipients) : 0,
            'occurred_at' => optional($event->occurred_at)->toDateTimeString(),
            // 수신 시각과 지연 — "이벤트가 언제 일어났고 우리가 언제 받았는가" 를
            // 화면에서 바로 볼 수 있게 한다. Notification 에 과거 상한이 없으므로
            // 지연 도착 여부는 시간으로 판단해야 한다.
            'received_at' => optional($event->created_at)->toDateTimeString(),
            'delay_seconds' => $event->delaySeconds(),
            'bounce_type' => $event->bounce_type,
            'bounce_subtype' => $event->bounce_subtype,
            'complaint_feedback_type' => $event->complaint_feedback_type,
            'diagnostic_code' => $event->diagnostic_code,
            'notification_log_id' => $event->getAttribute('linked_notification_log_id'),
            'linked' => $event->getAttribute('linked_notification_log_id') !== null,
            'linked_subject' => $event->getAttribute('linked_subject'),
            'linked_notification_type' => $event->getAttribute('linked_notification_type'),
            'created_at' => optional($event->created_at)->toDateTimeString(),
        ];
    }
}
