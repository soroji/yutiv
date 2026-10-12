@extends('teewide::layouts.operations', ['title' => $sellerBrand.' — 비밀번호'])
@section('content')
<section class="live-panel"><h1>{{ $changing ? '비밀번호 변경' : ($token ? '비밀번호 재설정' : '비밀번호 찾기') }}</h1>
<p class="live-help">{{ $changing ? '임시 비밀번호로 로그인한 경우 새 비밀번호를 설정해야 업무를 시작할 수 있습니다.' : '업체에 등록한 계정의 비밀번호를 재설정합니다.' }}</p>
<form data-live-form method="POST" action="/customer/tasks/{{ $changing ? 'password' : ($token ? 'reset' : 'forgot') }}">@csrf
@if ($token)<input type="hidden" name="token" value="{{ $token }}">@endif
@if (!$changing && !$token)<div class="live-field"><label for="email">이메일</label><input id="email" type="email" name="email" required autocomplete="email"></div>
@else
@if ($changing)<div class="live-field"><label for="current-password">현재/임시 비밀번호</label><input id="current-password" type="password" name="current_password" required autocomplete="current-password"></div>@endif
<div class="live-field"><label for="new-password">새 비밀번호</label><input id="new-password" type="password" name="password" minlength="8" maxlength="128" required autocomplete="new-password"></div>
<div class="live-field"><label for="password-confirmation">새 비밀번호 확인</label><input id="password-confirmation" type="password" name="password_confirmation" required autocomplete="new-password"></div>
@endif
<button type="submit" class="live-button">{{ $changing ? '비밀번호 변경' : ($token ? '새 비밀번호 저장' : '재설정 메일 요청') }}</button><p class="live-status" data-live-message role="status"></p>
</form><a href="/login">로그인으로</a>@if ($changing)<a href="/seller">판매자 업무로</a>@endif</section>
@endsection
