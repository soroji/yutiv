<?php

namespace Plugins\Yutiv\SesMonitor\Listeners;

use Illuminate\Mail\Events\MessageSending;
use Plugins\Yutiv\SesMonitor\Support\SesConfig;

/**
 * 모든 Laravel 메일에 `X-SES-CONFIGURATION-SET` 헤더를 1회만 붙인다.
 *
 * ── 왜 MessageSending 인가 ──────────────────────────────────────────────────
 * 이 프로젝트의 메일은 두 경로로 나간다.
 *   · `App\Mail\DbTemplateMail` — 코어·모듈의 모든 알림 메일
 *   · `SettingsService::sendTestMail()` 의 `Mail::raw()` — 테스트 메일
 * 두 경로 모두 결국 `Illuminate\Mail\Mailer` 를 지나며 `MessageSending` 을 발생시킨다.
 * 그래서 이 이벤트 하나에 붙이면 **테스트 메일을 포함한 모든 메일**이 커버되고,
 * Mailable 마다 헤더를 넣는 중복 코드가 생기지 않는다.
 *
 * ── 비메일 채널 ────────────────────────────────────────────────────────────
 * `MessageSending` 은 메일 전송기에서만 발생한다. database/fcm/SMS 등 다른 알림
 * 채널은 이 이벤트를 거치지 않으므로 영향이 없다.
 *
 * ── 중복 방지 ──────────────────────────────────────────────────────────────
 * 큐 재시도나 다른 확장이 이미 같은 헤더를 넣었을 수 있다. 존재하면 건드리지 않는다
 * (덮어쓰지도 않는다 — 더 구체적인 의도를 존중).
 */
class AttachSesConfigurationSet
{
    /** SES 가 읽는 헤더 이름. 대소문자는 무시되지만 AWS 문서 표기를 따른다. */
    public const HEADER = 'X-SES-CONFIGURATION-SET';

    public function handle(MessageSending $event): void
    {
        if (! SesConfig::headerEnabled()) {
            return;
        }

        $headers = $event->message->getHeaders();

        if ($headers->has(self::HEADER)) {
            return;
        }

        $headers->addTextHeader(self::HEADER, SesConfig::configurationSet());
    }
}
