<?php

namespace Modules\Yutiv\PersonalStore;

use App\Extension\AbstractModule;
use Modules\Sirsoft\Ecommerce\Http\Middleware\ResolveShippingCountry;

class Module extends AbstractModule
{
    public function install(): bool
    {
        return extension_loaded('bcmath') ? parent::install() : $this->failWith(__('yutiv-personal_store::messages.bcmath_required'));
    }

    public function activate(): bool
    {
        return extension_loaded('bcmath') ? parent::activate() : $this->failWith(__('yutiv-personal_store::messages.bcmath_required'));
    }

    public function getAdminMenus(): array
    {
        return [['name' => ['ko' => '개인 스토어 승인·리워드', 'en' => 'Store approvals and rewards'], 'slug' => 'yutiv-personal-store-headquarters', 'url' => '/admin/personal-stores', 'icon' => 'fas fa-store', 'order' => 45, 'permission' => 'yutiv-personal_store.headquarters.manage']];
    }

    public function getPermissions(): array
    {
        return ['name' => ['ko' => '개인 스토어', 'en' => 'Personal store'], 'description' => ['ko' => '개인 스토어 권한', 'en' => 'Personal store permissions'], 'categories' => [
            ['identifier' => 'operator', 'name' => ['ko' => '운영자', 'en' => 'Operator'], 'description' => ['ko' => '본인 스토어 운영', 'en' => 'Manage own store'], 'permissions' => [['action' => 'manage', 'name' => ['ko' => '본인 스토어 운영', 'en' => 'Manage own store'], 'description' => ['ko' => '본인 스토어만 관리', 'en' => 'Own store only'], 'type' => 'user', 'roles' => ['user']]]],
            ['identifier' => 'headquarters', 'name' => ['ko' => '본사', 'en' => 'Headquarters'], 'description' => ['ko' => '승인·정책 관리', 'en' => 'Approvals and policy'], 'permissions' => [['action' => 'manage', 'name' => ['ko' => '승인·정책 관리', 'en' => 'Approvals and policy'], 'description' => ['ko' => '본사 정책 관리', 'en' => 'Headquarters policy'], 'type' => 'admin', 'roles' => ['admin']]]],
        ]];
    }

    public function getSchedules(): array
    {
        return [
            ['command' => 'yutiv-personal_store:outbox --limit=500', 'schedule' => 'everyMinute', 'description' => '스토어 리워드 outbox 처리'],
            ['command' => 'yutiv-personal_store:reconcile --enqueue', 'schedule' => 'everyFiveMinutes', 'description' => '누락 상태 이벤트 재등록·정합성 검사'],
        ];
    }

    public function getMiddleware(): array
    {
        return [['class' => ResolveShippingCountry::class, 'groups' => ['api'], 'targets' => ['api.modules.yutiv-personal_store.stores.products*']]];
    }

    public function getHookListeners(): array
    {
        return [Listeners\StoreOrderListener::class, Listeners\RegistrationListener::class, Listeners\StoreLayoutListener::class];
    }
}
