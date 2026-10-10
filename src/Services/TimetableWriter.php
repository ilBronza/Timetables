<?php

namespace IlBronza\Timetables\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use IlBronza\Timetables\Domain\CivilDate;
use IlBronza\Timetables\Domain\CivilInterval;
use IlBronza\Timetables\Domain\InvalidTimetable;
use IlBronza\Timetables\Exceptions\TimetableConflict;
use IlBronza\Timetables\Models\Timetable;
use IlBronza\Timetables\Support\Timezones;

/** All supported aggregate mutations pass through the owner's connection. */
class TimetableWriter
{
    public function replace(Model $owner, string $kind, array $definition, ?int $expectedRevision = null): Timetable
    {
        [$type, $key] = $this->identity($owner, $kind);
        $data = $this->validate($definition);
        try {
            return $owner->getConnection()->transaction(function () use ($owner, $type, $key, $kind, $data, $expectedRevision) {
                $this->lockOwner($owner);
                $header = $this->header($owner, $type, $key, $kind);
                if ($header) {
                    $this->checkRevision($header, $expectedRevision);
                    if ($header->trashed()) {
                        throw new TimetableConflict('Restore the deleted timetable before replacing it.');
                    }
                } elseif ($expectedRevision !== null) {
                    throw new TimetableConflict('The timetable no longer exists.');
                } else {
                    $class = Timetable::getProjectClassName();
                    $header = new $class;
                    $header->setConnection($owner->getConnectionName());
                    $header->fill(['owner_type' => $type, 'owner_id' => $key, 'kind' => $kind, 'revision' => 0]);
                }
                $header->fill(['timezone' => $data['timezone'], 'name' => $data['name'], 'enabled' => $data['enabled']]);
                $header->revision++;
                $this->save($header);
                $header->periods()->delete();
                foreach ($data['periods'] as $periodData) {
                    $period = $header->periods()->create([
                        'valid_from' => $periodData['valid_from'], 'valid_to' => $periodData['valid_to'], 'name' => $periodData['name'],
                    ]);
                    $this->created($period);
                    foreach ($periodData['weekly'] as $weekday => $intervals) {
                        $day = $period->days()->create(['weekday' => $weekday]);
                        $this->created($day);
                        $this->writeIntervals($day, $intervals);
                    }
                    foreach ($periodData['exceptions'] as $date => $intervals) {
                        $day = $period->days()->create(['on_date' => $date]);
                        $this->created($day);
                        $this->writeIntervals($day, $intervals);
                    }
                }
                return $header->load('periods.days.intervals');
            });
        } catch (QueryException $error) {
            if (str_contains(strtolower($error->getMessage()), 'unique') || ($error->errorInfo[1] ?? null) === 1062) {
                throw new TimetableConflict('The timetable was created concurrently.', 0, $error);
            }
            throw $error;
        }
    }

    public function setEnabled(Model $owner, string $kind, bool $enabled, int $expectedRevision): Timetable
    {
        return $this->mutate($owner, $kind, $expectedRevision, function (Timetable $header) use ($enabled) {
            if ($header->trashed()) {
                throw new TimetableConflict('Restore the deleted timetable before enabling or disabling it.');
            }
            $header->enabled = $enabled;
            $this->save($header);
        });
    }

    public function delete(Model $owner, string $kind, int $expectedRevision): Timetable
    {
        return $this->mutate($owner, $kind, $expectedRevision, function (Timetable $header) {
            if ($header->trashed()) {
                throw new TimetableConflict('The timetable is already deleted.');
            }
            $this->save($header);
            if (! $header->delete()) {
                throw new TimetableConflict('Timetable deletion was cancelled.');
            }
        });
    }

    public function restore(Model $owner, string $kind, int $expectedRevision): Timetable
    {
        return $this->mutate($owner, $kind, $expectedRevision, function (Timetable $header) {
            if (! $header->trashed()) {
                throw new TimetableConflict('The timetable is not deleted.');
            }
            if (! $header->restore()) {
                throw new TimetableConflict('Timetable restoration was cancelled.');
            }
        });
    }

    public function forceDelete(Model $owner, string $kind, int $expectedRevision): Timetable
    {
        return $this->mutate($owner, $kind, $expectedRevision, function (Timetable $header) {
            $this->save($header);
            if (! $header->forceDelete()) {
                throw new TimetableConflict('Timetable deletion was cancelled.');
            }
        });
    }

    private function mutate(Model $owner, string $kind, int $expectedRevision, callable $action): Timetable
    {
        [$type, $key] = $this->identity($owner, $kind);
        return $owner->getConnection()->transaction(function () use ($owner, $type, $key, $kind, $expectedRevision, $action) {
            $this->lockOwner($owner);
            $header = $this->header($owner, $type, $key, $kind);
            if (! $header) {
                throw new TimetableConflict('The timetable no longer exists.');
            }
            $this->checkRevision($header, $expectedRevision);
            $header->revision++;
            $action($header);
            return $header;
        });
    }

    private function identity(Model $owner, string $kind): array
    {
        if (! $owner->exists || (! is_int($owner->getKey()) && ! is_string($owner->getKey()))) {
            throw new InvalidTimetable('The owner must be a persisted model with a scalar key.');
        }
        $key = (string) $owner->getKey();
        if ($owner->getKeyType() === 'int' && preg_match('/^([+-]?)([0-9]+)$/D', $key, $match)) {
            $digits = ltrim($match[2], '0');
            $key = $digits === '' ? '0' : ($match[1] === '-' ? '-' : '').$digits;
        }
        $type = $owner->getMorphClass();
        if ($key === '' || $this->length($key) > 64 || $kind === '' || $this->length($kind) > 64 || $type === '' || $this->length($type) > 255) {
            throw new InvalidTimetable('Owner identifiers and kind must fit the timetable schema.');
        }
        return [$type, $key];
    }

    private function lockOwner(Model $owner): void
    {
        $owner->newQuery()->whereKey($owner->getKey())->lockForUpdate()->firstOrFail();
    }

    private function header(Model $owner, string $type, string $key, string $kind): ?Timetable
    {
        $class = Timetable::getProjectClassName();
        return (new $class)->setConnection($owner->getConnectionName())->newQuery()->withTrashed()
            ->where('owner_type', $type)->where('owner_id', $key)->where('kind', $kind)->lockForUpdate()->first();
    }

    private function checkRevision(Timetable $header, ?int $expectedRevision): void
    {
        if ($expectedRevision === null || $expectedRevision !== $header->revision) {
            throw new TimetableConflict('The expected timetable revision is missing or stale.');
        }
    }

    private function writeIntervals(Model $day, array $intervals): void
    {
        foreach ($intervals as [$start, $end]) {
            $interval = $day->intervals()->create(['start_minute' => $start, 'end_minute' => $end]);
            $this->created($interval);
        }
    }

    private function save(Model $model): void
    {
        if (! $model->save()) {
            throw new TimetableConflict('Timetable saving was cancelled.');
        }
    }

    private function created(Model $model): void
    {
        if (! $model->exists) {
            throw new TimetableConflict('Timetable child creation was cancelled.');
        }
    }

    private function validate(array $data): array
    {
        $this->keys($data, ['timezone', 'name', 'enabled', 'periods']);
        if (! isset($data['timezone']) || ! is_string($data['timezone'])) {
            throw new InvalidTimetable('The timetable timezone must be a configured IANA string.');
        }
        Timezones::assertAllowed($data['timezone']);
        $name = $this->name($data['name'] ?? null);
        $enabled = array_key_exists('enabled', $data) ? $data['enabled'] : true;
        if (! is_bool($enabled)) {
            throw new InvalidTimetable('enabled must be a boolean.');
        }
        if (! isset($data['periods']) || ! is_array($data['periods']) || ! array_is_list($data['periods'])) {
            throw new InvalidTimetable('periods must be a list.');
        }
        $periods = [];
        foreach ($data['periods'] as $period) {
            if (! is_array($period)) {
                throw new InvalidTimetable('Each period must be an object.');
            }
            $this->keys($period, ['valid_from', 'valid_to', 'name', 'weekly', 'exceptions']);
            $from = $this->date($period['valid_from'] ?? null);
            $to = $this->date($period['valid_to'] ?? null);
            if ($from !== null && $to !== null && $from > $to) {
                throw new InvalidTimetable('Period date bounds are reversed.');
            }
            $weekly = array_key_exists('weekly', $period) ? $period['weekly'] : [];
            $exceptions = array_key_exists('exceptions', $period) ? $period['exceptions'] : [];
            if (! is_array($weekly) || ! is_array($exceptions)) {
                throw new InvalidTimetable('weekly and exceptions must be date-indexed maps.');
            }
            foreach ($weekly as $weekday => $intervals) {
                if (! in_array($weekday, [1, 2, 3, 4, 5, 6, 7], true)) {
                    throw new InvalidTimetable('Weekly keys must be ISO weekdays 1 through 7.');
                }
                $weekly[$weekday] = $this->intervals($intervals);
            }
            foreach ($exceptions as $date => $intervals) {
                $this->date($date);
                if (($from !== null && $date < $from) || ($to !== null && $date > $to)) {
                    throw new InvalidTimetable('Exception dates must belong to their period.');
                }
                $exceptions[$date] = $this->intervals($intervals);
            }
            ksort($weekly);
            ksort($exceptions);
            $periods[] = ['valid_from' => $from, 'valid_to' => $to, 'name' => $this->name($period['name'] ?? null), 'weekly' => $weekly, 'exceptions' => $exceptions];
        }
        usort($periods, fn ($a, $b) => ($a['valid_from'] ?? '') <=> ($b['valid_from'] ?? ''));
        for ($i = 1; $i < count($periods); $i++) {
            if ($periods[$i - 1]['valid_to'] === null || $periods[$i]['valid_from'] === null || $periods[$i - 1]['valid_to'] >= $periods[$i]['valid_from']) {
                throw new InvalidTimetable('Timetable periods must not overlap.');
            }
        }
        return ['timezone' => $data['timezone'], 'name' => $name, 'enabled' => $enabled, 'periods' => $periods];
    }

    private function intervals(mixed $intervals): array
    {
        if (! is_array($intervals) || ! array_is_list($intervals)) {
            throw new InvalidTimetable('Intervals must be a list of integer minute pairs.');
        }
        $unique = [];
        foreach ($intervals as $interval) {
            if (! is_array($interval) || ! array_is_list($interval) || count($interval) !== 2 || ! is_int($interval[0]) || ! is_int($interval[1])) {
                throw new InvalidTimetable('Each interval must contain exactly two integer minutes.');
            }
            new CivilInterval($interval[0], $interval[1]);
            $unique[$interval[0].':'.$interval[1]] = $interval;
        }
        $result = array_values($unique);
        usort($result, fn ($a, $b) => ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]));
        $limit = config('timetables.max_intervals_per_day', 32);
        if (! is_int($limit) || $limit < 1) {
            throw new InvalidTimetable('max_intervals_per_day must be a positive integer.');
        }
        if (count($result) > $limit) {
            throw new InvalidTimetable('Too many intervals in one day.');
        }
        return $result;
    }

    private function date(mixed $date): ?string
    {
        if ($date === null) {
            return null;
        }
        if (! is_string($date)) {
            throw new InvalidTimetable('Dates must be YYYY-MM-DD strings.');
        }
        CivilDate::from($date);
        return $date;
    }

    private function name(mixed $name): ?string
    {
        if ($name !== null && (! is_string($name) || $this->length($name) > 255)) {
            throw new InvalidTimetable('Names must be null or strings of at most 255 characters.');
        }
        return $name;
    }

    private function keys(array $data, array $allowed): void
    {
        if (array_diff(array_keys($data), $allowed)) {
            throw new InvalidTimetable('Unknown timetable property.');
        }
    }

    private function length(string $value): int
    {
        $length = preg_match_all('/./us', $value);
        if ($length === false) {
            throw new InvalidTimetable('Timetable strings must be valid UTF-8.');
        }
        return $length;
    }
}
