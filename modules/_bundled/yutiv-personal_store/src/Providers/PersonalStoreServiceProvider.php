<?php

namespace Modules\Yutiv\PersonalStore\Providers;

use App\Extension\BaseModuleServiceProvider;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Yutiv\PersonalStore\Console\Commands\ProcessOutboxCommand;
use Modules\Yutiv\PersonalStore\Console\Commands\ReconcileCommand;
use Modules\Yutiv\PersonalStore\Repositories\Contracts\StoreRepositoryInterface;
use Modules\Yutiv\PersonalStore\Repositories\StoreRepository;
use Modules\Yutiv\PersonalStore\Services\AttributionProof;

class PersonalStoreServiceProvider extends BaseModuleServiceProvider
{
    protected string $moduleIdentifier = 'yutiv-personal_store';

    protected array $repositories = [StoreRepositoryInterface::class => StoreRepository::class];

    public function boot(): void
    {
        parent::boot();
        // Eloquent saved는 동기 실행됩니다. 실제 취소 트랜잭션에서 환불 검토 상태를 즉시 캡처합니다.
        OrderRefund::saved(function ($refund) {
            app(StoreRepositoryInterface::class)->captureOrderState($refund->order_id);
        });
        // AuthService의 회원 INSERT와 동일 트랜잭션입니다. after_register 커밋 이후 간격에 의존하지 않습니다.
        User::created(function ($user) {
            if (request()->routeIs('api.auth.register')) {
                $reference = app(AttributionProof::class)->verify(request()->input('store_membership_proof'), 'membership');
                if ($reference !== null) {
                    if ($user->getConnection() !== DB::connection() || DB::transactionLevel() < 1) {
                        throw new \LogicException(__('yutiv-personal_store::messages.invalid_proof'));
                    }
                    app(StoreRepositoryInterface::class)->registerMembership($user->id, $reference, hash('sha256', request()->input('store_membership_proof')));
                }
            }
        });
        if ($this->app->runningInConsole()) {
            $this->commands([ProcessOutboxCommand::class, ReconcileCommand::class]);
        }
    }
}
