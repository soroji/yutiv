@extends('teewide::layouts.operations',['title'=>$sellerBrand.' — 상품관리'])
@section('navigation')
@foreach($menu as $key=>$item)<a href="/seller/{{ $key }}" @if($key==='products') aria-current="page" @endif>{{ $item[0] }}</a>@endforeach
@endsection
@section('content')
<div class="live-products-workspace">
@if($errors->any())
<div role="alert">@foreach($errors->all() as $message)<p>{{ $message }}</p>@endforeach</div>
@endif
@yield('workspace')
</div>
@endsection
