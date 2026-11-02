@extends(config('timetables.editor.layout'))

@section('content')
@php
    $editor = \IlBronza\Timetables\Editor\TimetableEditor::class;
    $action = $editor::url($owner, $record ? 'update' : 'store', $record);
    if (! $readOnly && ! $conflict && old('_timetables_form') === $action) {
        $data = session()->getOldInput();
    }
    $data = app(\IlBronza\Timetables\Editor\TimetableForm::class)->forDisplay($data);
@endphp
<div class="uk-container uk-container-large">
    <nav class="uk-margin-bottom" aria-label="{{ __('timetables::editor.navigation') }}">
        <a href="{{ $editor::url($owner) }}">← @lang('timetables::editor.back')</a>
        @if($readOnly && $canUpdate && ! $record->trashed())
            <a class="uk-button uk-button-primary uk-margin-left" href="{{ $editor::url($owner, 'edit', $record) }}">@lang('timetables::editor.edit')</a>
        @endif
    </nav>
    <h1 class="uk-h3">{{ __('timetables::editor.'.($readOnly ? 'show' : ($record ? 'edit' : 'create'))) }} · {{ method_exists($owner, 'getName') ? $owner->getName() : ($owner->name ?? $owner->getKey()) }}</h1>
    @if(session('timetables_status'))
        <div class="uk-alert-success" role="status" uk-alert>{{ session('timetables_status') }}</div>
    @endif
    @if($conflict)
        <div class="uk-alert-danger" role="alert" uk-alert>
            <p>@lang('timetables::editor.conflict') @lang('timetables::editor.input_preserved')</p>
            <a href="{{ $editor::url($owner, $record && ! $record->trashed() ? 'edit' : 'index', $record && ! $record->trashed() ? $record : null) }}">@lang('timetables::editor.reload')</a>
        </div>
    @endif
    @if($errors->any())
        <div class="uk-alert-danger" role="alert" uk-alert>
            <p>@lang('timetables::editor.correct_errors')</p>
            <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif
    <form class="uk-form-stacked" method="POST" action="{{ $action }}" data-timetable-editor>
        @csrf
        @if($record) @method('PUT') @endif
        <input type="hidden" name="_timetables_form" value="{{ $action }}">
        @if($record)<input type="hidden" name="revision" value="{{ $data['revision'] ?? '' }}">@endif
        <fieldset class="uk-fieldset" @disabled($readOnly)>
            <div class="uk-card uk-card-default uk-card-body uk-margin-bottom">
                <div class="uk-grid-small uk-child-width-1-2@m" uk-grid>
                    <label class="uk-display-block">@lang('timetables::editor.kind')
                        @if($record)
                            <input class="uk-input" type="text" value="{{ $record->kind }}" readonly>
                        @else
                            <input class="uk-input" type="text" name="kind" maxlength="64" value="{{ $data['kind'] ?? '' }}" required placeholder="opening / working / training">
                        @endif
                    </label>
                    <label class="uk-display-block">@lang('timetables::editor.name')
                        <input class="uk-input" type="text" name="name" maxlength="255" value="{{ $data['name'] ?? '' }}">
                    </label>
                    <label class="uk-display-block">@lang('timetables::editor.timezone')
                        <select class="uk-select" name="timezone" required>
                            @if(($data['timezone'] ?? '') !== '' && ! in_array($data['timezone'], $timezones, true))
                                <option value="{{ $data['timezone'] }}" selected>{{ $data['timezone'] }} · @lang('timetables::editor.timezone_unavailable')</option>
                            @endif
                            @foreach($timezones as $timezone)
                                <option value="{{ $timezone }}" @selected(($data['timezone'] ?? '') === $timezone)>{{ $timezone }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="uk-display-block uk-margin-top">
                        <input type="hidden" name="enabled" value="0">
                        <input class="uk-checkbox" type="checkbox" name="enabled" value="1" @checked($data['enabled'] ?? false)> @lang('timetables::editor.enabled')
                    </label>
                </div>
            </div>
            <p class="uk-text-meta">@lang('timetables::editor.instructions')</p>
            <input type="hidden" name="periods" value="">
            <div data-period-list>
                @foreach(($data['periods'] ?? []) as $periodIndex => $period)
                    @include('timetables::editor._period')
                @endforeach
            </div>
            @if(! $readOnly)
                <button class="uk-button uk-button-default uk-margin-bottom" type="button" data-timetable-add="period">+ @lang('timetables::editor.add_period')</button>
            @endif
        </fieldset>
        @if(! $readOnly)
            <div class="uk-margin-top uk-flex uk-flex-wrap uk-flex-middle" style="gap: 1rem">
                <button class="uk-button uk-button-primary" type="submit" @disabled($conflict)>@lang('timetables::editor.save')</button>
                <a href="{{ $editor::url($owner) }}">@lang('timetables::editor.cancel')</a>
            </div>
            <noscript><p>@lang('timetables::editor.javascript')</p></noscript>
            <input type="hidden" name="complete" value="1">
        @endif
        <template data-timetable-template="period">
            @include('timetables::editor._period', ['periodIndex' => '__P__', 'period' => app(\IlBronza\Timetables\Editor\TimetableForm::class)->emptyPeriod()])
        </template>
        <template data-timetable-template="exception">
            @include('timetables::editor._exception', ['periodIndex' => '__P__', 'exceptionIndex' => '__E__', 'exception' => ['date' => '', 'intervals' => []]])
        </template>
        <template data-timetable-template="interval">
            @include('timetables::editor._interval', ['prefix' => '__PREFIX__', 'intervalIndex' => '__I__', 'interval' => ['start' => '', 'end' => '', 'next_day' => false]])
        </template>
    </form>
</div>
@include('timetables::editor._script')
@endsection
