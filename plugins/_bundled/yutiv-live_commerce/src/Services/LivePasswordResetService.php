<?php

namespace Plugins\Yutiv\LiveCommerce\Services;

use Illuminate\Support\Facades\Mail;
use Plugins\Yutiv\LiveCommerce\Enums\LiveEntity as E;
use Plugins\Yutiv\LiveCommerce\Mail\LivePasswordResetMail;
use Plugins\Yutiv\LiveCommerce\Models\LiveTenant;
use Plugins\Yutiv\LiveCommerce\Models\TeeWideUser;

class LivePasswordResetService extends LiveWorkflow
{
    public function requestReset(int $tenant, string $email): string
    {
        if (! config('yutiv_live_commerce.account_mail_enabled', false) || ! config('mail.from.address')
            || in_array(config('mail.default'), ['log', 'array'], true)) {
            return '비밀번호 재설정 메일이 준비되지 않았습니다. 고객센터에 문의해 주세요.';
        }
        $user = TeeWideUser::where('tenant_id', $tenant)->where('email', $email)->where('status', 'active')->first();
        if ($user) {
            $token = bin2hex(random_bytes(32));
            $reset = $this->records->transaction(function () use ($tenant, $user, $token) {
                LiveTenant::whereKey($tenant)->lockForUpdate()->firstOrFail();
                TeeWideUser::where('tenant_id', $tenant)->lockForUpdate()->findOrFail($user->id);
                $this->records->query(E::Reset, $tenant)->where('user_id', $user->id)->whereNull('used_at')->update(['used_at' => now()]);

                return $this->records->create(E::Reset, $tenant, ['user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(30)]);
            });
            $model = LiveTenant::findOrFail($tenant);
            $url = app(LiveDomainService::class)->url($model).'/password/reset/'.$token;
            try {
                Mail::to($user->email)->send(new LivePasswordResetMail($model->name, $url));
            } catch (\Throwable $e) {
                $reset->used_at = now();
                $reset->save();

                // 메일 예외 원문과 토큰은 고객 응답/감사 기록으로 전달하지 않습니다.
                return '메일 요청을 처리하지 못했습니다. 잠시 후 다시 시도해 주세요.';
            }
        }

        return '입력한 계정이 확인되면 재설정 메일을 안내합니다. 수신함을 확인해 주세요.';
    }

    public function reset(int $tenant, string $token, string $password): void
    {
        $this->records->transaction(function () use ($tenant, $token, $password) {
            LiveTenant::whereKey($tenant)->lockForUpdate()->firstOrFail();
            $reset = $this->records->query(E::Reset, $tenant)->where('token_hash', hash('sha256', $token))->lockForUpdate()->firstOrFail();
            abort_if($reset->used_at || $reset->expires_at <= now(), 422, __('yutiv-live_commerce::live.errors.e_7d6bb70770'));
            $user = TeeWideUser::where('tenant_id', $tenant)->lockForUpdate()->findOrFail($reset->user_id);
            app(LiveAccountService::class)->changePassword($user, $password);
            $reset->used_at = now();
            $reset->save();
        });
    }
}
