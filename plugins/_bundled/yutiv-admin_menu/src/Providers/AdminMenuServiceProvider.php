<?php

namespace Plugins\Yutiv\AdminMenu\Providers;

use App\Extension\BasePluginServiceProvider;
use Plugins\Yutiv\AdminMenu\Console\Commands\AdminMenuCommand;

/**
 * 이 플러그인의 서비스 프로바이더.
 *
 * 하는 일은 아티즌 명령 등록 하나뿐이다. 메뉴 배치 자체는 훅 리스너
 * (`EcommerceAdminMenuListener`) 가 모듈 동기화 시점에 처리하므로 여기서 부팅 중에
 * DB 를 건드리지 않는다 — 부팅 경로에서 쓰기를 하면 마이그레이션 전이나 CLI 설치
 * 중에도 실행되어 예측 불가능해진다.
 */
class AdminMenuServiceProvider extends BasePluginServiceProvider
{
    /** @var string */
    protected string $pluginIdentifier = 'yutiv-admin_menu';

    /**
     * 부팅 — 콘솔에서만 명령을 등록한다.
     */
    public function boot(): void
    {
        parent::boot();

        if ($this->app->runningInConsole()) {
            $this->commands([
                AdminMenuCommand::class,
            ]);
        }
    }
}
