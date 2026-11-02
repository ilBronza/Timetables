<section class="uk-card uk-card-default uk-card-body uk-margin-bottom" data-period data-index="{{ $periodIndex }}">
    <div class="uk-flex uk-flex-between uk-flex-middle uk-margin-bottom">
        <h2 class="uk-h4 uk-margin-remove">@lang('timetables::editor.period')</h2>
        @if(! $readOnly)<button class="uk-button uk-button-text uk-text-danger" type="button" data-timetable-remove="period">@lang('timetables::editor.remove_period')</button>@endif
    </div>
    <div class="uk-grid-small uk-child-width-1-3@m" uk-grid>
        <label>@lang('timetables::editor.period_name')<input class="uk-input" type="text" name="periods[{{ $periodIndex }}][name]" maxlength="255" value="{{ $period['name'] ?? '' }}"></label>
        <label>@lang('timetables::editor.valid_from')<input class="uk-input" type="date" name="periods[{{ $periodIndex }}][valid_from]" value="{{ $period['valid_from'] ?? '' }}"></label>
        <label>@lang('timetables::editor.valid_to')<input class="uk-input" type="date" name="periods[{{ $periodIndex }}][valid_to]" value="{{ $period['valid_to'] ?? '' }}"></label>
    </div>
    <p class="uk-text-meta">@lang('timetables::editor.period_help')</p>
    <h3 class="uk-h5">@lang('timetables::editor.weekly')</h3>
    @foreach(range(1, 7) as $weekday)
        <div class="uk-margin-small uk-padding-small uk-background-muted" data-day>
            <div class="uk-flex uk-flex-between uk-flex-middle uk-flex-wrap">
                <strong>{{ __('timetables::editor.weekdays.'.$weekday) }}</strong>
                @if(! $readOnly)<button class="uk-button uk-button-text" type="button" data-timetable-add="interval" data-prefix="periods[{{ $periodIndex }}][weekly][{{ $weekday }}]">+ @lang('timetables::editor.add_interval')</button>@endif
            </div>
            <input type="hidden" name="periods[{{ $periodIndex }}][weekly][{{ $weekday }}]" value="">
            <div data-interval-list>
                @foreach(($period['weekly'][$weekday] ?? []) as $intervalIndex => $interval)
                    @include('timetables::editor._interval', ['prefix' => 'periods['.$periodIndex.'][weekly]['.$weekday.']'])
                @endforeach
            </div>
            <small data-empty-intervals @if(! empty($period['weekly'][$weekday])) hidden @endif>@lang('timetables::editor.inactive_day')</small>
        </div>
    @endforeach
    <div class="uk-flex uk-flex-between uk-flex-middle uk-margin-top">
        <h3 class="uk-h5 uk-margin-remove">@lang('timetables::editor.exceptions')</h3>
        @if(! $readOnly)<button class="uk-button uk-button-text" type="button" data-timetable-add="exception">+ @lang('timetables::editor.add_exception')</button>@endif
    </div>
    <p class="uk-text-meta">@lang('timetables::editor.exception_help')</p>
    <input type="hidden" name="periods[{{ $periodIndex }}][exceptions]" value="">
    <div data-exception-list>
        @foreach(($period['exceptions'] ?? []) as $exceptionIndex => $exception)
            @include('timetables::editor._exception')
        @endforeach
    </div>
</section>
