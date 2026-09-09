<?php

use Illuminate\Support\Facades\Route;
use Plugins\Yutiv\SesMonitor\Http\Controllers\Admin\SesEventLogController;

/*
|--------------------------------------------------------------------------
| YUTIV SES Monitor — Admin API Routes
|--------------------------------------------------------------------------
|
| 프리픽스: /api/plugins/yutiv-ses_monitor (PluginRouteServiceProvider 자동 적용)
| 미들웨어: api (자동 적용)
|
| 공개 webhook 은 여기 없다. 요구 경로가 `/webhooks/aws/ses` 이고 이 로더는
| `api/plugins/{id}` 프리픽스를 강제하므로, 그 라우트는 SesMonitorServiceProvider
| 가 직접 등록한다.
|
| 권한은 전부 코어 `core.notification-logs.read` 를 재사용한다 — SES 이벤트는
| 발송 이력의 결과이므로 별도 권한 체계를 만들지 않는다.
|
*/

Route::middleware(['auth:sanctum', 'permission:admin,core.notification-logs.read'])
    ->prefix('admin/ses-events')
    ->group(function () {
        // 이벤트 타입별 집계 + 최근 이벤트 시각 (발송 이력 화면 요약 패널)
        Route::get('/summary', [SesEventLogController::class, 'summary'])
            ->name('admin.ses-events.summary');

        // 특정 발송 이력에 연결된 이벤트
        Route::get('/for-notification-log/{notificationLog}', [SesEventLogController::class, 'forNotificationLog'])
            ->whereNumber('notificationLog')
            ->name('admin.ses-events.for-notification-log');

        // 이벤트 목록 (linked / unlinked 필터 포함)
        Route::get('/', [SesEventLogController::class, 'index'])
            ->name('admin.ses-events.index');

        // 단건 상세 (원문은 설정 + include_raw 일 때만)
        Route::get('/{sesEvent}', [SesEventLogController::class, 'show'])
            ->whereNumber('sesEvent')
            ->name('admin.ses-events.show');
    });
