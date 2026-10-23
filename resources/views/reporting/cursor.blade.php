@if($paginator->hasPages())
<nav class="pagination" aria-label="Timeline history pages">
@if($paginator->previousPageUrl())<a href="{{ $paginator->previousPageUrl() }}" rel="prev">Newer events</a>@else<span aria-disabled="true">Newer events</span>@endif
<span>Recording time · newest first</span>
@if($paginator->nextPageUrl())<a href="{{ $paginator->nextPageUrl() }}" rel="next">Older events</a>@else<span aria-disabled="true">Older events</span>@endif
</nav>
@endif
