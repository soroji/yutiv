@foreach(\Plugins\Yutiv\LiveCommerce\Support\LiveSellerNavigation::menu($role) as $key=>$item)
<a href="/seller/{{ $key }}" @if(explode('/',$key)[0]===\Plugins\Yutiv\LiveCommerce\Support\LiveSellerNavigation::active(request()->path())) aria-current="page" @endif>{{ $item[0] }}</a>
@endforeach
