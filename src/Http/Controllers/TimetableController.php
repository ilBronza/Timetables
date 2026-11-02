<?php

namespace IlBronza\Timetables\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use IlBronza\Timetables\Domain\InvalidTimetable;
use IlBronza\Timetables\Editor\OwnerResolver;
use IlBronza\Timetables\Editor\TimetableEditor;
use IlBronza\Timetables\Editor\TimetableForm;
use IlBronza\Timetables\Exceptions\TimetableConflict;
use IlBronza\Timetables\Models\Timetable;
use IlBronza\Timetables\Services\TimetableWriter;
use IlBronza\Timetables\Support\Timezones;
use Illuminate\Validation\ValidationException;

class TimetableController extends Controller
{
    public function __construct(
        private readonly OwnerResolver $owners,
        private readonly TimetableForm $form,
        private readonly TimetableWriter $writer,
    ) {}

    public function index(string $owner, string $key)
    {
        $model = $this->owners->resolve($owner, $key, 'view');

        return view('timetables::editor.index', [
            'owner' => $model,
            'records' => $this->query($model)->orderBy('kind')->paginate(20),
            'canUpdate' => $this->owners->allows($model, 'update'),
        ]);
    }

    public function create(string $owner, string $key)
    {
        $model = $this->owners->resolve($owner, $key, 'update');

        return $this->editor($model, null, $this->form->defaults());
    }

    public function show(string $owner, string $key, string $timetable)
    {
        $model = $this->owners->resolve($owner, $key, 'view');
        $record = $this->snapshot($model, $timetable);

        return $this->editor($model, $record, $this->form->fromTimetable($record), readOnly: true);
    }

    public function edit(string $owner, string $key, string $timetable)
    {
        $model = $this->owners->resolve($owner, $key, 'update');
        $record = $this->snapshot($model, $timetable);
        abort_if($record->trashed(), 404);

        return $this->editor($model, $record, $this->form->fromTimetable($record));
    }

    public function store(Request $request, string $owner, string $key)
    {
        $model = $this->owners->resolve($owner, $key, 'update');
        $data = $this->form->validate($request, creating: true);

        return $this->save($request, $model, null, $data);
    }

    public function update(Request $request, string $owner, string $key, string $timetable)
    {
        $model = $this->owners->resolve($owner, $key, 'update');
        $record = $this->query($model)->findOrFail($timetable);
        $data = $this->form->validate($request, creating: false);

        return $this->save($request, $model, $record, $data);
    }

    public function destroy(Request $request, string $owner, string $key, string $timetable)
    {
        return $this->lifecycle($request, $owner, $key, $timetable, 'delete');
    }

    public function restore(Request $request, string $owner, string $key, string $timetable)
    {
        return $this->lifecycle($request, $owner, $key, $timetable, 'restore');
    }

    private function query(Model $owner): Builder
    {
        $class = Timetable::getProjectClassName();

        return (new $class)->setConnection($owner->getConnectionName())->newQuery()->withTrashed()
            ->where('owner_type', $owner->getMorphClass())->where('owner_id', (string) $owner->getKey());
    }

    private function snapshot(Model $owner, string $id): Timetable
    {
        // Avoid showing children from one revision with the token of another.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $record = $this->query($owner)->with('periods.days.intervals')->findOrFail($id);
            if ($this->query($owner)->whereKey($id)->where('revision', $record->revision)->exists()) {
                return $record;
            }
        }

        abort(409, __('timetables::editor.conflict'));
    }

    private function editor(Model $owner, ?Timetable $record, array $data, bool $readOnly = false, bool $conflict = false)
    {
        return view('timetables::editor.form', [
            'owner' => $owner, 'record' => $record, 'data' => $data,
            'readOnly' => $readOnly, 'conflict' => $conflict,
            'timezones' => Timezones::configured(),
            'canUpdate' => $this->owners->allows($owner, 'update'),
        ]);
    }

    private function save(Request $request, Model $owner, ?Timetable $record, array $data)
    {
        try {
            $record = $this->writer->replace($owner, $record?->kind ?? $data['kind'],
                $this->form->definition($data), isset($data['revision']) ? (int) $data['revision'] : null);
        } catch (InvalidTimetable) {
            throw ValidationException::withMessages(['periods' => __('timetables::editor.invalid_definition')]);
        } catch (TimetableConflict) {
            if ($request->expectsJson()) {
                return response()->json(['message' => __('timetables::editor.conflict')], 409);
            }

            // Keep both the user's input and the original revision on screen.
            return response($this->editor($owner, $record, $data, conflict: true)->render(), 409);
        }

        return redirect(TimetableEditor::url($owner, 'edit', $record))->with('timetables_status', __('timetables::editor.saved'));
    }

    private function lifecycle(Request $request, string $alias, string $key, string $id, string $action)
    {
        $owner = $this->owners->resolve($alias, $key, 'update');
        $record = $this->query($owner)->findOrFail($id);
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        try {
            $this->writer->$action($owner, $record->kind, (int) $data['revision']);
        } catch (TimetableConflict) {
            if ($request->expectsJson()) {
                return response()->json(['message' => __('timetables::editor.conflict')], 409);
            }

            return response()->view('timetables::editor.conflict', ['owner' => $owner], 409);
        }

        return redirect(TimetableEditor::url($owner))->with('timetables_status', __('timetables::editor.'.$action.'d'));
    }
}
