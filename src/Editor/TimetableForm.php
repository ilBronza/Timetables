<?php

namespace IlBronza\Timetables\Editor;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use IlBronza\Timetables\Domain\CivilInterval;
use IlBronza\Timetables\Domain\InvalidTimetable;
use IlBronza\Timetables\Models\Timetable;
use IlBronza\Timetables\Support\Timezones;

/** Translation between civil clock form controls and the writer's minute pairs. */
class TimetableForm
{
    public function validate(Request $request, bool $creating): array
    {
        $interval = 'periods.*.weekly.*.*';
        $exception = 'periods.*.exceptions.*';
        $rules = [
            // Last successful form control: detect PHP max_input_vars truncation.
            'complete' => ['required', 'in:1'],
            'kind' => $creating ? ['required', 'string', 'max:64'] : ['prohibited'],
            'revision' => $creating ? ['prohibited'] : ['required', 'integer', 'min:1'],
            'name' => ['nullable', 'string', 'max:255'],
            'timezone' => ['required', 'string', Rule::in(Timezones::configured())],
            'enabled' => ['required', 'boolean'],
            'periods' => ['present', 'nullable', 'array', 'max:'.config('timetables.editor.max_periods', 24)],
            'periods.*' => ['array:name,valid_from,valid_to,weekly,exceptions'],
            'periods.*.name' => ['nullable', 'string', 'max:255'],
            'periods.*.valid_from' => ['nullable', 'date_format:Y-m-d'],
            'periods.*.valid_to' => ['nullable', 'date_format:Y-m-d'],
            'periods.*.weekly' => ['present', 'array:1,2,3,4,5,6,7'],
            'periods.*.weekly.*' => ['nullable', 'array', 'max:'.config('timetables.max_intervals_per_day', 32)],
            'periods.*.exceptions' => ['present', 'nullable', 'array', 'max:'.config('timetables.editor.max_exceptions', 366)],
            $exception => ['array:date,intervals'],
            $exception.'.date' => ['required', 'date_format:Y-m-d'],
            $exception.'.intervals' => ['present', 'nullable', 'array', 'max:'.config('timetables.max_intervals_per_day', 32)],
        ];

        foreach ([$interval, $exception.'.intervals.*'] as $path) {
            $rules[$path] = ['array:start,end,next_day'];
            $rules[$path.'.start'] = ['required', 'date_format:H:i'];
            $rules[$path.'.end'] = ['required', 'date_format:H:i'];
            $rules[$path.'.next_day'] = ['required', 'boolean'];
        }

        $data = Validator::make($request->all(), $rules, [
            'complete.required' => __('timetables::editor.incomplete'),
        ], [
            'kind' => __('timetables::editor.kind'),
            'name' => __('timetables::editor.name'),
            'timezone' => __('timetables::editor.timezone'),
            'periods.*.valid_from' => __('timetables::editor.valid_from'),
            'periods.*.valid_to' => __('timetables::editor.valid_to'),
            $interval.'.start' => __('timetables::editor.start'),
            $interval.'.end' => __('timetables::editor.end'),
            $exception.'.date' => __('timetables::editor.exception_date'),
            $exception.'.intervals.*.start' => __('timetables::editor.start'),
            $exception.'.intervals.*.end' => __('timetables::editor.end'),
        ])->validate();
        // Semantic validation uses the same civil-interval invariant as the writer.
        $this->definition($data);

        return $data;
    }

    public function definition(array $data): array
    {
        $periods = [];
        foreach ($data['periods'] ?? [] as $index => $period) {
            $path = 'periods.'.$index;
            $from = $period['valid_from'] ?? null;
            $to = $period['valid_to'] ?? null;
            if ($from !== null && $to !== null && $from > $to) {
                $this->invalid($path.'.valid_to', 'reversed_period');
            }
            foreach ($periods as $previous) {
                if (($to === null || $previous['valid_from'] === null || $to >= $previous['valid_from'])
                    && ($from === null || $previous['valid_to'] === null || $from <= $previous['valid_to'])) {
                    $this->invalid($path.'.valid_from', 'overlapping_periods');
                }
            }
            $weekly = $exceptions = [];
            foreach ($period['weekly'] as $weekday => $intervals) {
                $weekly[$weekday] = $this->intervals($intervals ?? [], $path.'.weekly.'.$weekday);
            }
            foreach ($period['exceptions'] ?? [] as $i => $exception) {
                $date = $exception['date'];
                if (array_key_exists($date, $exceptions)) {
                    $this->invalid($path.'.exceptions.'.$i.'.date', 'duplicate_exception');
                }
                if (($from !== null && $date < $from) || ($to !== null && $date > $to)) {
                    $this->invalid($path.'.exceptions.'.$i.'.date', 'exception_outside_period');
                }
                $exceptions[$date] = $this->intervals($exception['intervals'] ?? [], $path.'.exceptions.'.$i.'.intervals');
            }
            $periods[] = ['name' => $period['name'] ?? null, 'valid_from' => $from, 'valid_to' => $to,
                'weekly' => $weekly, 'exceptions' => $exceptions];
        }

        return ['name' => $data['name'] ?? null, 'timezone' => $data['timezone'],
            'enabled' => (bool) $data['enabled'], 'periods' => $periods];
    }

    public function defaults(): array
    {
        $zones = Timezones::configured();

        return ['kind' => '', 'name' => '', 'enabled' => true,
            'timezone' => in_array(config('app.timezone'), $zones, true) ? config('app.timezone') : ($zones[0] ?? ''),
            'periods' => [$this->emptyPeriod()]];
    }

    public function emptyPeriod(): array
    {
        return ['name' => '', 'valid_from' => null, 'valid_to' => null, 'weekly' => [], 'exceptions' => []];
    }

    /** Invalid shapes in flashed input must still render a validation page. */
    public function forDisplay(array $data): array
    {
        $text = static fn ($value) => is_scalar($value) ? (string) $value : '';
        $rows = static fn ($value) => is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
        $intervals = static fn ($value) => array_map(static fn ($row) => [
            'start' => $text($row['start'] ?? ''), 'end' => $text($row['end'] ?? ''),
            'next_day' => in_array($row['next_day'] ?? false, [true, 1, '1'], true),
        ], $rows($value));
        $data['periods'] = array_map(function ($period) use ($text, $rows, $intervals) {
            $weekly = [];
            foreach (range(1, 7) as $day) {
                $weekly[$day] = $intervals(is_array($period['weekly'] ?? null) ? ($period['weekly'][$day] ?? []) : []);
            }

            return ['name' => $text($period['name'] ?? ''), 'valid_from' => $text($period['valid_from'] ?? ''),
                'valid_to' => $text($period['valid_to'] ?? ''), 'weekly' => $weekly,
                'exceptions' => array_map(static fn ($row) => [
                    'date' => $text($row['date'] ?? ''), 'intervals' => $intervals($row['intervals'] ?? []),
                ], $rows($period['exceptions'] ?? []))];
        }, $rows($data['periods'] ?? []));
        foreach (['kind', 'name', 'timezone', 'revision'] as $field) {
            $data[$field] = $text($data[$field] ?? '');
        }
        $data['enabled'] = in_array($data['enabled'] ?? false, [true, 1, '1'], true);

        return $data;
    }

    public function fromTimetable(Timetable $timetable): array
    {
        $periods = [];
        foreach ($timetable->periods as $period) {
            $weekly = $exceptions = [];
            foreach ($period->days as $day) {
                $intervals = $day->intervals->map(fn ($interval) => [
                    'start' => $this->clock($interval->start_minute),
                    'end' => $this->clock($interval->end_minute),
                    'next_day' => $interval->end_minute >= 1440,
                ])->all();
                if ($day->on_date !== null) {
                    $exceptions[] = ['date' => $day->on_date, 'intervals' => $intervals];
                } else {
                    $weekly[$day->weekday] = $intervals;
                }
            }
            $periods[] = ['name' => $period->name, 'valid_from' => $period->valid_from,
                'valid_to' => $period->valid_to, 'weekly' => $weekly, 'exceptions' => $exceptions];
        }

        return ['kind' => $timetable->kind, 'name' => $timetable->name, 'enabled' => $timetable->enabled,
            'timezone' => $timetable->timezone, 'revision' => $timetable->revision, 'periods' => $periods];
    }

    private function clock(int $minute): string
    {
        $minute %= 1440;

        return sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
    }

    private function intervals(array $intervals, string $path): array
    {
        $result = [];
        foreach ($intervals as $index => $interval) {
            $start = $this->minute($interval['start']);
            $end = $this->minute($interval['end']) + ($interval['next_day'] ? 1440 : 0);
            try {
                new CivilInterval($start, $end);
            } catch (InvalidTimetable) {
                $this->invalid($path.'.'.$index.'.end', 'invalid_interval');
            }
            $result[] = [$start, $end];
        }

        return $result;
    }

    private function minute(string $clock): int
    {
        return (int) substr($clock, 0, 2) * 60 + (int) substr($clock, 3, 2);
    }

    private function invalid(string $path, string $message): never
    {
        throw ValidationException::withMessages([$path => __('timetables::editor.'.$message)]);
    }
}
