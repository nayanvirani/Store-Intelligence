@if ($paginator->hasPages())
    <nav class="ad-actions" aria-label="Pages">
        @if ($paginator->onFirstPage())<span class="ad-btn small" aria-disabled="true" style="opacity:.5">Previous</span>@else<a class="ad-btn small" href="{{ $paginator->previousPageUrl() }}">Previous</a>@endif
        <span class="ad-muted">Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</span>
        @if ($paginator->hasMorePages())<a class="ad-btn small" href="{{ $paginator->nextPageUrl() }}">Next</a>@else<span class="ad-btn small" aria-disabled="true" style="opacity:.5">Next</span>@endif
    </nav>
@endif
