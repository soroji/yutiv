<?php

namespace Plugins\Yutiv\LiveCommerce\Http\Controllers\Auth;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Plugins\Yutiv\LiveCommerce\Models\TeeWideUser;
use Plugins\Yutiv\LiveCommerce\Support\TeeWideAuth;
use Plugins\Yutiv\LiveCommerce\Support\TeeWidePresenter;

/**
 * TeeWide 로그인·로그아웃.
 *
 * ── 계정 존재 여부를 알려 주지 않는다 ──────────────────────────────────────
 * "없는 이메일" 과 "비밀번호 틀림" 과 "정지된 계정" 을 구분해 답하면, 그 자체가
 * 회원 목록 열거 수단이 된다. 세 경우 모두 같은 문구로 답한다.
 */
class LoginController
{
    /** 실패 시 언제나 같은 문구 — 어떤 계정이 있는지 알려 주지 않는다. */
    private const GENERIC_FAILURE = '이메일 또는 비밀번호가 올바르지 않습니다.';

    public function show(): View
    {
        return view('teewide::auth.login', [
            'portalUrl' => TeeWidePresenter::portalUrl(),
            'liveUrl' => TeeWidePresenter::liveUrl(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge([
            'email' => TeeWideUser::normalizeEmail($request->input('email')),
        ]);

        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:191'],
            'password' => ['required', 'string'],
        ]);

        $remember = $request->boolean('remember');

        // ① 자격 증명 확인. 여기서 실패하면 계정이 없거나 비밀번호가 틀린 것이다.
        if (! TeeWideAuth::guard()->attempt($credentials, $remember)) {
            throw ValidationException::withMessages(['email' => self::GENERIC_FAILURE]);
        }

        // ② 상태 확인. 자격 증명이 맞아도 active 가 아니면 들여보내지 않는다.
        //    attempt() 가 이미 세션에 사용자를 넣었으므로 반드시 되돌린다.
        $user = TeeWideAuth::user();

        if ($user === null || ! $user->canAuthenticate()) {
            TeeWideAuth::guard()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages(['email' => self::GENERIC_FAILURE]);
        }

        // 로그인 직전 세션 ID 를 버린다 (세션 고정 공격 방어).
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(route('teewide.account'));
    }

    /**
     * 로그아웃 — POST 만 허용한다 (라우트에서 강제).
     *
     * ⚠ `teewide` guard 만 끊는다. YUTIV `web` guard 와 YUTIV 세션은 건드리지 않는다.
     *   여기서 `Auth::logout()`(기본 guard)을 부르면 다른 도메인의 로그인이 풀린다.
     */
    public function destroy(Request $request): RedirectResponse
    {
        TeeWideAuth::guard()->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('teewide.portal');
    }
}
