@extends(request()->boolean('embedded') || request()->boolean('ibFetcher') ? 'timetables::editor.fragment' : config('timetables.editor.layout'))

@section('content')
@php($editor = \IlBronza\Timetables\Editor\TimetableEditor::class)
<section class="uk-card uk-card-default uk-card-body">
    <div class="uk-flex uk-flex-between uk-flex-middle uk-flex-wrap uk-margin-bottom">
        <h1 class="uk-h3 uk-margin-remove">@lang('timetables::editor.title') · {{ method_exists($owner, 'getName') ? $owner->getName() : ($owner->name ?? $owner->getKey()) }}</h1>
        @if($canUpdate)
            <a class="uk-button uk-button-primary" href="{{ $editor::url($owner, 'create') }}">@lang('timetables::editor.create')</a>
        @endif
    </div>
    @if(session('timetables_status'))
        <div class="uk-alert-success" role="status" uk-alert>{{ session('timetables_status') }}</div>
    @endif
    @if($errors->any())
        <div class="uk-alert-danger" role="alert" uk-alert>{{ $errors->first() }}</div>
    @endif
    <div class="uk-overflow-auto">
        <table class="uk-table uk-table-divider uk-table-middle">
            <thead><tr>
                <th>@lang('timetables::editor.kind')</th><th>@lang('timetables::editor.name')</th>
                <th>@lang('timetables::editor.timezone')</th><th>@lang('timetables::editor.status')</th><th>@lang('timetables::editor.actions')</th>
            </tr></thead>
            <tbody>
            @forelse($records as $record)
                <tr>
                    <td><a href="{{ $editor::url($owner, 'show', $record) }}">{{ $record->kind }}</a></td>
                    <td>{{ $record->name }}</td><td>{{ $record->timezone }}</td>
                    <td><span class="uk-label {{ $record->trashed() ? 'uk-label-danger' : ($record->enabled ? 'uk-label-success' : 'uk-label-warning') }}">{{ __('timetables::editor.'.($record->trashed() ? 'deleted' : ($record->enabled ? 'enabled' : 'disabled'))) }}</span></td>
                    <td>
                        @if($canUpdate)
                            @if(! $record->trashed())
                                <a class="uk-button uk-button-default uk-button-small" href="{{ $editor::url($owner, 'edit', $record) }}">@lang('timetables::editor.edit')</a>
                            @endif
                            <form class="uk-inline" method="POST" action="{{ $editor::url($owner, $record->trashed() ? 'restore' : 'destroy', $record) }}" @if(! $record->trashed()) data-timetable-confirm="{{ __('timetables::editor.confirm_delete') }}" @endif>
                                @csrf
                                @if(! $record->trashed()) @method('DELETE') @endif
                                <input type="hidden" name="revision" value="{{ $record->revision }}">
                                <button class="uk-button uk-button-small {{ $record->trashed() ? 'uk-button-default' : 'uk-button-danger' }}" type="submit">{{ __('timetables::editor.'.($record->trashed() ? 'restore' : 'delete')) }}</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5">@lang('timetables::editor.empty')</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $records->appends(request()->only('embedded', 'ibFetcher'))->links('timetables::editor.pagination') }}
</section>
@include('timetables::editor._script')
@endsection
