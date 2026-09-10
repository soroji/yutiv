@extends('teewide::layouts.teewide', [
    'title' => '로그인 — TeeWide',
    'area' => 'auth',
    'footerNote' => 'TeeWide 계정은 yutiv.com 계정과 별개입니다.',
])

@section('header')
    @include('teewide::partials.portal-header', ['teeWideUser' => null])
@endsection

@section('content')
    <section class="tw-auth" aria-labelledby="tw-login-title">
        <div class="tw-wrap">
            <div class="tw-auth__card">
                <h1 class="tw-auth__title" id="tw-login-title">로그인</h1>
                <p class="tw-auth__lead">TeeWide 계정으로 로그인합니다.</p>

                @if ($errors->any())
                    {{-- 오류는 색만으로 알리지 않는다. role=alert 로 스크린리더에도 알린다. --}}
                    <p class="tw-alert" role="alert">{{ $errors->first() }}</p>
                @endif

                <form class="tw-form" method="POST" action="{{ route('teewide.login.store') }}" novalidate>
                    @csrf

                    <div class="tw-field">
                        <label class="tw-label" for="tw-email">
                            이메일<span class="tw-label__req" aria-hidden="true">*</span>
                        </label>
                        <input
                            class="tw-input"
                            type="email"
                            id="tw-email"
                            name="email"
                            value="{{ old('email') }}"
                            autocomplete="email"
                            required
                            @error('email') aria-invalid="true" aria-describedby="tw-email-error" @enderror
                        >
                        @error('email')
                            <span class="tw-error" id="tw-email-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="tw-field">
                        <label class="tw-label" for="tw-password">
                            비밀번호<span class="tw-label__req" aria-hidden="true">*</span>
                        </label>
                        {{-- 비밀번호는 old() 로 되살리지 않는다. --}}
                        <input
                            class="tw-input"
                            type="password"
                            id="tw-password"
                            name="password"
                            autocomplete="current-password"
                            required
                            @error('password') aria-invalid="true" aria-describedby="tw-password-error" @enderror
                        >
                        @error('password')
                            <span class="tw-error" id="tw-password-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <label class="tw-check" for="tw-remember">
                        <input type="checkbox" id="tw-remember" name="remember" value="1" @checked(old('remember'))>
                        <span>로그인 상태 유지</span>
                    </label>

                    <button type="submit" class="tw-btn tw-btn--primary">로그인</button>
                </form>

                <p class="tw-auth__foot">
                    계정이 없으신가요? <a href="{{ route('teewide.register') }}">회원가입</a>
                </p>
            </div>
        </div>
    </section>
@endsection
