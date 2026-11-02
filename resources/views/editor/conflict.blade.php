@extends(config('timetables.editor.layout'))

@section('content')
<div class="uk-alert-danger" role="alert" uk-alert>@lang('timetables::editor.conflict')</div>
<a class="uk-button uk-button-default" href="{{ \IlBronza\Timetables\Editor\TimetableEditor::url($owner) }}">@lang('timetables::editor.reload')</a>
@endsection
