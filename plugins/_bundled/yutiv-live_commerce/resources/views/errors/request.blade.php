@extends('teewide::layouts.teewide', ['title' => $sellerBrand.' — 안내', 'area' => 'portal'])
@section('header')
    @include('teewide::partials.portal-header')
@endsection
@section('content')
    <section class="tw-section tw-wrap">
        <h1>{{ $status === 404 ? '페이지를 찾을 수 없습니다' : ($status === 403 ? '접근 권한이 없습니다' : '요청을 처리할 수 없습니다') }}</h1>
        <p>주소와 접근 권한을 확인하거나 잠시 후 다시 시도해 주세요.</p>
        <a class="tw-btn tw-btn--ghost" href="{{ $portalUrl }}">홈으로</a>
    </section>
@endsection
