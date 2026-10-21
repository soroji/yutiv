@if($paginator->hasPages())
<nav class="product-pages" aria-label="목록 페이지">
@if($paginator->onFirstPage())<span aria-disabled="true">이전</span>@else<a href="{{ $paginator->previousPageUrl() }}" rel="prev">이전</a>@endif
@for($number=max(1,$paginator->currentPage()-2);$number<=min($paginator->lastPage(),$paginator->currentPage()+2);$number++)
@if($number===$paginator->currentPage())<strong aria-current="page">{{ $number }}</strong>@else<a href="{{ $paginator->url($number) }}">{{ $number }}</a>@endif
@endfor
@if($paginator->hasMorePages())<a href="{{ $paginator->nextPageUrl() }}" rel="next">다음</a>@else<span aria-disabled="true">다음</span>@endif
</nav>
@endif
