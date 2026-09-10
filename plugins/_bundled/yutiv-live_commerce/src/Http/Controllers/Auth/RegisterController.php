<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Controllers\Auth;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Plugins\Yutiv\LiveCommerce\Models\TeeWideUser;
use Plugins\Yutiv\LiveCommerce\Support\TeeWideAuth;
use Plugins\Yutiv\LiveCommerce\Support\TeeWidePresenter;

/**
 * TeeWide 회원가입.
 *
 * YUTIV 회원가입과 아무 것도 공유하지 않는다 — 다른 테이블(`teewide_users`),
 * 다른 guard(`teewide`), 다른 세션 쿠키(`teewide_session`).
 */
class RegisterController
{
    public function show(): View
    {
        return view('teewide::auth.register', [
            'portalUrl' => TeeWidePresenter::portalUrl(),
            'liveUrl' => TeeWidePresenter::liveUrl(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // 이메일은 검증 **전에** 정규화한다. 그래야 unique 검사와 실제 저장이
        // 같은 값을 본다 — 'A@b.com ' 과 'a@b.com' 이 다른 계정이 되면 안 된다.
        $request->merge([
            'email' => TeeWideUser::normalizeEmail($request->input('email')),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => [
                'required', 'string', 'email', 'max:191',
                Rule::unique('teewide_users', 'email'),
            ],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'terms' => ['accepted'],
        ], [
            'terms.accepted' => '이용약관에 동의해야 가입할 수 있습니다.',
        ]);

        $user = TeeWideUser::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            // 평문은 여기서 끝난다. 로그에도 DB 에도 남지 않는다.
            'password' => Hash::make($validated['password']),
            'status' => TeeWideUser::STATUS_ACTIVE,
        ]);

        TeeWideAuth::guard()->login($user);

        // 가입 전 세션 ID 를 그대로 들고 로그인 상태가 되면 세션 고정 공격에 노출된다.
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->route('teewide.account');
    }
}
