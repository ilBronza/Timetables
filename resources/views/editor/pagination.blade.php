@if($paginator->hasPages())
<nav aria-label="{{ __('timetables::editor.navigation') }}">
    <ul class="uk-pagination">
        <li @class(['uk-disabled' => $paginator->onFirstPage()])>
            @if($paginator->onFirstPage())<span>@lang('timetables::editor.previous')</span>
            @else<a href="{{ $paginator->previousPageUrl() }}" rel="prev">@lang('timetables::editor.previous')</a>@endif
        </li>
        @foreach($elements as $element)
            @if(is_string($element))
                <li class="uk-disabled"><span>{{ $element }}</span></li>
            @else
                @foreach($element as $page => $url)
                    @if($page === $paginator->currentPage())<li class="uk-active"><span aria-current="page">{{ $page }}</span></li>
                    @else<li><a href="{{ $url }}">{{ $page }}</a></li>@endif
                @endforeach
            @endif
        @endforeach
        <li @class(['uk-disabled' => ! $paginator->hasMorePages()])>
            @if($paginator->hasMorePages())<a href="{{ $paginator->nextPageUrl() }}" rel="next">@lang('timetables::editor.next')</a>
            @else<span>@lang('timetables::editor.next')</span>@endif
        </li>
    </ul>
</nav>
@endif
