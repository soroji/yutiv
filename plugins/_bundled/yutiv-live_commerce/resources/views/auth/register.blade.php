@extends('teewide::layouts.teewide', [
    'title' => '회원가입 — TeeWide',
    'area' => 'auth',
    'footerNote' => 'TeeWide 계정은 yutiv.com 계정과 별개입니다.',
])

@section('header')
    @include('teewide::partials.portal-header', ['teeWideUser' => null])
@endsection

@section('content')
    <section class="tw-auth" aria-labelledby="tw-register-title">
        <div class="tw-wrap">
            <div class="tw-auth__card">
                <h1 class="tw-auth__title" id="tw-register-title">회원가입</h1>
                <p class="tw-auth__lead">TeeWide 계정을 만듭니다. 기존 yutiv.com 계정과는 별개입니다.</p>

                @if ($errors->any())
                    <p class="tw-alert" role="alert">입력한 내용을 다시 확인해 주세요.</p>
                @endif

                <form class="tw-form" method="POST" action="{{ route('teewide.register.store') }}" novalidate>
                    @csrf

                    <div class="tw-field">
                        <label class="tw-label" for="tw-name">
                            이름<span class="tw-label__req" aria-hidden="true">*</span>
                        </label>
                        <input
                            class="tw-input"
                            type="text"
                            id="tw-name"
                            name="name"
                            value="{{ old('name') }}"
                            maxlength="100"
                            autocomplete="name"
                            required
                            @error('name') aria-invalid="true" aria-describedby="tw-name-error" @enderror
                        >
                        @error('name')
                            <span class="tw-error" id="tw-name-error">{{ $message }}</span>
                        @enderror
                    </div>

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
                            maxlength="191"
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
                        {{-- 비밀번호는 old() 로 되살리지 않는다 — 화면·로그에 평문을 남기지 않는다. --}}
                        <input
                            class="tw-input"
                            type="password"
                            id="tw-password"
                            name="password"
                            autocomplete="new-password"
                            required
                            @error('password')
                                aria-invalid="true"
                                aria-describedby="tw-password-help tw-password-error"
                            @else
                                aria-describedby="tw-password-help"
                            @enderror
                        >
                        <span class="tw-help" id="tw-password-help">8자 이상 입력해 주세요.</span>
                        @error('password')
                            <span class="tw-error" id="tw-password-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="tw-field">
                        <label class="tw-label" for="tw-password-confirm">
                            비밀번호 확인<span class="tw-label__req" aria-hidden="true">*</span>
                        </label>
                        <input
                            class="tw-input"
                            type="password"
                            id="tw-password-confirm"
                            name="password_confirmation"
                            autocomplete="new-password"
                            required
                        >
                    </div>

                    <div class="tw-field">
                        <label class="tw-check" for="tw-terms">
                            <input
                                type="checkbox"
                                id="tw-terms"
                                name="terms"
                                value="1"
                                @checked(old('terms'))
                                @error('terms') aria-invalid="true" aria-describedby="tw-terms-error" @enderror
                            >
                            <span>이용약관에 동의합니다. <span class="tw-label__req" aria-hidden="true">*</span></span>
                        </label>
                        @error('terms')
                            <span class="tw-error" id="tw-terms-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <button type="submit" class="tw-btn tw-btn--primary">가입하기</button>
                </form>

                <p class="tw-auth__foot">
                    이미 계정이 있으신가요? <a href="{{ route('teewide.login') }}">로그인</a>
                </p>
            </div>
        </div>
    </section>
@endsection
