<div class="uk-flex uk-flex-middle uk-flex-wrap uk-margin-small-top" style="gap: 1rem" data-interval data-index="{{ $intervalIndex }}">
    <label>@lang('timetables::editor.start')<input class="uk-input" type="time" name="{{ $prefix }}[{{ $intervalIndex }}][start]" value="{{ $interval['start'] }}" step="60" required></label>
    <label>@lang('timetables::editor.end')<input class="uk-input" type="time" name="{{ $prefix }}[{{ $intervalIndex }}][end]" value="{{ $interval['end'] }}" step="60" required></label>
    <label>
        <input type="hidden" name="{{ $prefix }}[{{ $intervalIndex }}][next_day]" value="0">
        <input class="uk-checkbox" type="checkbox" name="{{ $prefix }}[{{ $intervalIndex }}][next_day]" value="1" @checked($interval['next_day'])> @lang('timetables::editor.next_day')
    </label>
    @if(! $readOnly)<button class="uk-button uk-button-text uk-text-danger" type="button" data-timetable-remove="interval">@lang('timetables::editor.remove_interval')</button>@endif
</div>
