<div class="uk-padding-small uk-background-muted uk-margin-small" data-exception data-day data-index="{{ $exceptionIndex }}">
    <div class="uk-flex uk-flex-middle uk-flex-wrap" style="gap: 1rem">
        <label>@lang('timetables::editor.exception_date')<input class="uk-input" type="date" name="periods[{{ $periodIndex }}][exceptions][{{ $exceptionIndex }}][date]" value="{{ $exception['date'] }}" required></label>
        @if(! $readOnly)
            <button class="uk-button uk-button-text" type="button" data-timetable-add="interval" data-prefix="periods[{{ $periodIndex }}][exceptions][{{ $exceptionIndex }}][intervals]">+ @lang('timetables::editor.add_interval')</button>
            <button class="uk-button uk-button-text uk-text-danger" type="button" data-timetable-remove="exception">@lang('timetables::editor.remove_exception')</button>
        @endif
    </div>
    <input type="hidden" name="periods[{{ $periodIndex }}][exceptions][{{ $exceptionIndex }}][intervals]" value="">
    <div data-interval-list>
        @foreach(($exception['intervals'] ?? []) as $intervalIndex => $interval)
            @include('timetables::editor._interval', ['prefix' => 'periods['.$periodIndex.'][exceptions]['.$exceptionIndex.'][intervals]'])
        @endforeach
    </div>
    <small data-empty-intervals @if(! empty($exception['intervals'])) hidden @endif>@lang('timetables::editor.closed_date')</small>
</div>
