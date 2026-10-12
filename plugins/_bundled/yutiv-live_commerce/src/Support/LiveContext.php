<?php

namespace Plugins\Yutiv\LiveCommerce\Support;

use Illuminate\Http\Request;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity;
use Plugins\Yutiv\LiveCommerce\Enums\StaffRole;
use Plugins\Yutiv\LiveCommerce\Models\LiveRecord;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;
use Plugins\Yutiv\LiveCommerce\Models\TeeWideUser;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** 서버 등록 호스트와 공개 채널만 업체 컨텍스트를 결정합니다. */
final class LiveContext
{
    public static function tenant(?Request $request = null): ?LiveTenant
    {
        $request ??= request();
        if ($request->attributes->has('live.tenant')) {
            return $request->attributes->get('live.tenant');
        }
        $host = TeeWideHost::canonical($request->getHost());
        try {
            $domain = LiveRecord::entity(LiveEntity::Domain)->newQuery()->where('host', $host)->first();
            if ($domain !== null) {
                abort_unless($domain->active, 404);
                $tenant = LiveTenant::query()->public()->find($domain->tenant_id);
                abort_unless($tenant, 404);
            } elseif (TeeWideHost::role($host, TeeWideConfig::rootHost(), TeeWideConfig::liveHost()) !== TeeWideHost::ROLE_FOREIGN) {
                $slug = $request->route()?->getName() === 'teewide.live.tenant' ? $request->route('tenant') : null;
                $tenant = TeeWidePresenter::findPublicTenant(is_string($slug) ? $slug : (string) config(TeeWideConfig::KEY.'.brand_tenant', ''));
            } else {
                $tenant = null;
            }
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable) {
            // 설치 이전/DB 장애는 외부 도메인을 허용하지 않습니다.
            $tenant = null;
        }
        $request->attributes->set('live.tenant', $tenant);

        return $tenant;
    }

    public static function requireTenant(): LiveTenant
    {
        return self::tenant() ?? abort(404);
    }

    public static function user(): TeeWideUser
    {
        $user = TeeWideAuth::user();
        $tenant = self::requireTenant();
        abort_unless($user && $user->tenant_id === $tenant->id && $user->canAuthenticate(), 403);

        return $user;
    }

    public static function authorize(string $ability): TeeWideUser
    {
        $user = self::user();
        abort_if($user->must_change_password, 403, __('yutiv-live_commerce::live.errors.e_78e13218b7'));
        $staff = LiveRecord::entity(LiveEntity::Staff)->newQuery()->where('tenant_id', $user->tenant_id)->where('user_id', $user->id)->where('active', true)->first();
        abort_unless($staff && StaffRole::tryFrom($staff->role)?->allows($ability), 403);

        return $user;
    }
}
