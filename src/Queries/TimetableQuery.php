<?php

namespace IlBronza\Timetables\Queries;

use DateTimeInterface;
use IlBronza\Timetables\Domain\Instant;
use IlBronza\Timetables\Domain\InvalidTimetable;
use IlBronza\Timetables\Domain\LocalWindowFactory;
use IlBronza\Timetables\Domain\QueryTooBroad;
use IlBronza\Timetables\Models\Timetable;
use IlBronza\Timetables\Models\TimetableDay;
use IlBronza\Timetables\Models\TimetableInterval;
use IlBronza\Timetables\Models\TimetablePeriod;
use IlBronza\Timetables\Support\Timezones;
use Illuminate\Database\Eloquent\Builder;

final class TimetableQuery
{
    public function __construct(private readonly LocalWindowFactory $windows) {}

    public function activeAt(Builder $query, DateTimeInterface|string $instant, string $kind): Builder
    {
        $from = Instant::parse($instant);

        return $this->overlaps($query, $from, $from->modify('+1 microsecond'), $kind);
    }

    public function overlaps(Builder $query, DateTimeInterface|string $from, DateTimeInterface|string $to, string $kind): Builder
    {
        $from = Instant::parse($from);
        $to = Instant::parse($to);
        if ($from >= $to) {
            throw new InvalidTimetable('A search interval must have a strictly later end.');
        }
        $kindLength = preg_match_all('/./us', $kind);
        if ($kindLength === false || $kindLength < 1 || $kindLength > 64) {
            throw new InvalidTimetable('A timetable kind must contain between 1 and 64 characters.');
        }

        $maxDays = $this->limit('max_days', 31);
        if (Instant::toMicros($to) - Instant::toMicros($from) > $maxDays * 86_400_000_000) {
            throw new QueryTooBroad('The search exceeds timetables.query.max_days.');
        }
        $windows = $this->windows->between($from, $to, Timezones::configured(), $this->limit('max_windows', 64));
        $connection = $query->getConnection();
        $driver = $connection->getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql'], true)) {
            throw new InvalidTimetable('Timetable queries support SQLite and MySQL.');
        }
        $grammar = $connection->getQueryGrammar();
        $tables = [];
        foreach ([Timetable::class, TimetablePeriod::class, TimetableDay::class, TimetableInterval::class] as $base) {
            $class = $base::getProjectClassName();
            $tables[] = $grammar->wrapTable((new $class)->getTable());
        }
        [$headers, $periods, $days, $intervals] = $tables;

        // Eloquent does not update its model table when the caller aliases FROM.
        $ownerFrom = $query->getQuery()->from;
        if (! is_string($ownerFrom)) {
            throw new InvalidTimetable('Timetable scopes require a named owner table, optionally aliased.');
        }
        $ownerTable = preg_split('/\s+as\s+/i', $ownerFrom);
        $ownerTable = trim(end($ownerTable));
        $key = $grammar->wrap($ownerTable.'.'.$query->getModel()->getKeyName());
        $key = $driver === 'mysql' ? 'BINARY CAST('.$key.' AS CHAR)' : 'CAST('.$key.' AS TEXT)';

        $selects = [];
        $bindings = [$query->getModel()->getMorphClass(), $kind];
        foreach ($windows as $window) {
            $selects[] = $selects === []
                ? 'SELECT ? AS tz, ? AS day, ? AS weekday, ? AS prev_day, ? AS prev_weekday, ? AS from_us, ? AS to_us'
                : 'SELECT ?, ?, ?, ?, ?, ?, ?';
            $previous = $window->date->midnightUtc()->modify('-1 day');
            array_push($bindings, $window->timezone, $window->date->value, $window->date->weekday(),
                $previous->format('Y-m-d'), (int) $previous->format('N'), $window->startMicros, $window->endMicros);
        }
        if (count($bindings) + count($query->getBindings()) > $this->limit('max_bindings', 900)) {
            throw new QueryTooBroad('The search exceeds timetables.query.max_bindings.');
        }
        $windowSql = implode(' UNION ALL ', $selects);

        // The two day selectors deliberately use EXISTS, including empty overrides.
        $sql = <<<SQL
EXISTS (
 SELECT 1 FROM {$headers} tt_h
 WHERE tt_h.owner_type = ? AND tt_h.owner_id = {$key}
 AND tt_h.kind = ? AND tt_h.enabled = 1 AND tt_h.deleted_at IS NULL
 AND EXISTS (
  SELECT 1 FROM ({$windowSql}) tt_w
  WHERE tt_w.tz = tt_h.timezone AND EXISTS (
   SELECT 1 FROM {$periods} tt_p
   WHERE tt_p.timetable_id = tt_h.id
   AND (tt_p.valid_from IS NULL OR tt_p.valid_from <= tt_w.day)
   AND (tt_p.valid_to IS NULL OR tt_p.valid_to >= tt_w.day)
   AND (
    EXISTS (
     SELECT 1 FROM {$days} tt_d JOIN {$intervals} tt_i ON tt_i.day_id = tt_d.id
     WHERE tt_d.period_id = tt_p.id
     AND (tt_d.on_date = tt_w.day OR (
      tt_d.on_date IS NULL AND tt_d.weekday = tt_w.weekday
      AND NOT EXISTS (SELECT 1 FROM {$days} tt_x WHERE tt_x.period_id = tt_p.id AND tt_x.on_date = tt_w.day)
     ))
     AND tt_i.start_minute * 60000000 < tt_w.to_us
     AND tt_i.end_minute * 60000000 > tt_w.from_us
    ) OR (
     tt_w.day > '0001-01-01'
     AND
     NOT EXISTS (SELECT 1 FROM {$days} tt_x WHERE tt_x.period_id = tt_p.id AND tt_x.on_date = tt_w.day)
     AND (tt_p.valid_from IS NULL OR tt_p.valid_from <= tt_w.prev_day)
     AND (tt_p.valid_to IS NULL OR tt_p.valid_to >= tt_w.prev_day)
     AND EXISTS (
      SELECT 1 FROM {$days} tt_d JOIN {$intervals} tt_i ON tt_i.day_id = tt_d.id
      WHERE tt_d.period_id = tt_p.id
      AND (tt_d.on_date = tt_w.prev_day OR (
       tt_d.on_date IS NULL AND tt_d.weekday = tt_w.prev_weekday
       AND NOT EXISTS (SELECT 1 FROM {$days} tt_x WHERE tt_x.period_id = tt_p.id AND tt_x.on_date = tt_w.prev_day)
      ))
      AND tt_i.end_minute * 60000000 > 86400000000 + tt_w.from_us
     )
    )
   )
  )
 )
)
SQL;

        return $query->whereRaw($sql, $bindings);
    }

    private function limit(string $key, int $default): int
    {
        $limit = config('timetables.query.'.$key, $default);
        if (! is_int($limit) || $limit < 1) {
            throw new InvalidTimetable('Timetable query limits must be positive integers.');
        }

        return $limit;
    }
}
