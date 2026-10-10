#!/usr/bin/env python3
"""Design experiment: SQLite SQL vs an independent UTC-point oracle.

Run with Python 3.9+ and an installed IANA tz database. No dependencies.
This does not implement the Laravel package or validate MySQL/PHP execution.
The four persistence tables are fixed; owners below are an external input
relation, as the real query would start from an application's owner table.

Production PHP must enumerate DateTimeZone::getTransitions(). This prototype
scans UTC in 30-minute steps and bisects each detected offset transition to
microsecond precision. It is deliberately not an endpoint-offset comparison:
the long-window tests include two transitions with equal endpoint offsets.
The scanner assumes no two IANA transitions occur within a scan step; it is
not a replacement for a production transition API.
"""

from __future__ import annotations

import random
import sqlite3
from dataclasses import dataclass, field
from datetime import date, datetime, timedelta, timezone
from zoneinfo import ZoneInfo

UTC = timezone.utc
MINUTE_US = 60_000_000
DAY_US = 86_400_000_000


def utc(value: str) -> datetime:
    return datetime.fromisoformat(value.replace("Z", "+00:00")).astimezone(UTC)


def micros(value: timedelta) -> int:
    return value.days * DAY_US + value.seconds * 1_000_000 + value.microseconds


@dataclass
class Period:
    start: date | None = None
    end: date | None = None
    weekly: dict[int, list[tuple[int, int]]] = field(default_factory=dict)
    exceptions: dict[date, list[tuple[int, int]]] = field(default_factory=dict)

    def active(self, day: date) -> bool:
        return (self.start is None or self.start <= day) and (
            self.end is None or day <= self.end
        )


@dataclass
class Header:
    owner: tuple[str, str]
    tz: str
    periods: list[Period]
    kind: str = "opening"
    enabled: bool = True
    deleted: bool = False

    def __post_init__(self):
        # Fixture adapter: canonical integer IDs become strings; UUID/ULID
        # fixture strings already use their canonical representation.
        self.owner = (self.owner[0], str(self.owner[1]))


@dataclass(frozen=True)
class Window:
    tz: str
    day: str
    weekday: int
    previous: str
    previous_weekday: int
    start: int
    end: int

    def values(self) -> tuple:
        return (self.tz, self.day, self.weekday, self.previous,
                self.previous_weekday, self.start, self.end)


def offset(at: datetime, zone: ZoneInfo) -> timedelta:
    return at.astimezone(zone).utcoffset()


def transitions(a: datetime, b: datetime, zone: ZoneInfo) -> list[datetime]:
    result = []
    left = a
    while left < b:
        right = min(left + timedelta(minutes=30), b)
        before = offset(left, zone)
        if offset(right, zone) != before:
            lo, hi = left, right
            while micros(hi - lo) > 1:
                mid = lo + timedelta(microseconds=micros(hi - lo) // 2)
                if offset(mid, zone) == before:
                    lo = mid
                else:
                    hi = mid
            if hi < b:
                result.append(hi)
        left = right
    return result


def local_windows(a: datetime, b: datetime, timezones: list[str]) -> list[Window]:
    """Map real UTC segments to increasing wall time, split at offset/midnight.

    Never interpret an ambiguous/nonexistent local datetime as zoned input.
    A segment's right endpoint uses its LEFT offset, so spring jumps and fall
    repeats produce the correct missing/repeated wall-time pieces.
    """
    if a >= b:
        raise ValueError("query must be a nonempty UTC half-open interval")
    result = []
    for name in sorted(set(timezones)):
        zone = ZoneInfo(name)
        cuts = [a, *transitions(a, b, zone), b]
        for left, right in zip(cuts, cuts[1:]):
            delta = offset(left, zone)
            # Naive arithmetic here is projection using a known UTC offset,
            # never localization of an ambiguous wall-time input.
            wall_left = left.replace(tzinfo=None) + delta
            wall_right = right.replace(tzinfo=None) + delta
            cursor = wall_left
            while cursor < wall_right:
                midnight = datetime.combine(cursor.date(), datetime.min.time())
                stop = min(wall_right, midnight + timedelta(days=1))
                day = cursor.date()
                previous = day - timedelta(days=1)
                result.append(Window(name, day.isoformat(), day.isoweekday(),
                                     previous.isoformat(), previous.isoweekday(),
                                     micros(cursor - midnight), micros(stop - midnight)))
                cursor = stop
    assert all(0 <= w.start < w.end <= DAY_US for w in result)
    return result


SCHEMA = """
CREATE TABLE timetables (
 id INTEGER PRIMARY KEY, owner_type TEXT NOT NULL, owner_id TEXT NOT NULL,
 kind TEXT NOT NULL, timezone TEXT NOT NULL, enabled INTEGER NOT NULL,
 deleted_at TEXT NULL, UNIQUE (owner_type, owner_id, kind)
);
CREATE TABLE periods (
 id INTEGER PRIMARY KEY, timetable_id INTEGER NOT NULL REFERENCES timetables(id),
 valid_from TEXT NULL, valid_to TEXT NULL,
 CHECK (valid_from IS NULL OR valid_to IS NULL OR valid_from <= valid_to)
);
CREATE TABLE days (
 id INTEGER PRIMARY KEY, period_id INTEGER NOT NULL REFERENCES periods(id),
 weekday INTEGER NULL CHECK (weekday BETWEEN 1 AND 7), on_date TEXT NULL,
 CHECK ((weekday IS NULL) <> (on_date IS NULL)),
 UNIQUE (period_id, weekday), UNIQUE (period_id, on_date)
);
CREATE TABLE intervals (
 id INTEGER PRIMARY KEY, day_id INTEGER NOT NULL REFERENCES days(id),
 start_minute INTEGER NOT NULL CHECK (start_minute BETWEEN 0 AND 1439),
 end_minute INTEGER NOT NULL,
 CHECK (end_minute > start_minute AND end_minute <= start_minute + 1440)
);
CREATE INDEX timetable_owner ON timetables(owner_type, owner_id, kind, enabled);
CREATE INDEX period_header ON periods(timetable_id);
CREATE INDEX interval_day ON intervals(day_id);
"""

# ISO dates and integer weekdays/coordinates are supplied by the adapter.
# No DATE(), strftime(), timezone conversion or dialect-specific date arithmetic.
# SELECT ? UNION ALL is valid for derived relations in SQLite and MySQL 8.
PREDICATE = """
EXISTS (
 SELECT 1 FROM timetables t
 WHERE t.owner_type = o.owner_type AND t.owner_id = o.owner_id
   AND t.kind = ? AND t.enabled = 1 AND t.deleted_at IS NULL
   AND EXISTS (
    SELECT 1 FROM ({windows}) w
    WHERE w.tz = t.timezone AND EXISTS (
     SELECT 1 FROM periods p
     WHERE p.timetable_id = t.id
       AND (p.valid_from IS NULL OR p.valid_from <= w.day)
       AND (p.valid_to IS NULL OR p.valid_to >= w.day)
       AND (
        EXISTS (
         SELECT 1 FROM days d JOIN intervals i ON i.day_id = d.id
         WHERE d.period_id = p.id
           AND (d.on_date = w.day OR (
            d.on_date IS NULL AND d.weekday = w.weekday
            AND NOT EXISTS (SELECT 1 FROM days x
              WHERE x.period_id = p.id AND x.on_date = w.day)
           ))
           AND i.start_minute * 60000000 < w.to_us
           AND i.end_minute * 60000000 > w.from_us
        )
        OR (
         NOT EXISTS (SELECT 1 FROM days x
           WHERE x.period_id = p.id AND x.on_date = w.day)
         AND (p.valid_from IS NULL OR p.valid_from <= w.prev_day)
         AND (p.valid_to IS NULL OR p.valid_to >= w.prev_day)
         AND EXISTS (
          SELECT 1 FROM days d JOIN intervals i ON i.day_id = d.id
          WHERE d.period_id = p.id
            AND (d.on_date = w.prev_day OR (
             d.on_date IS NULL AND d.weekday = w.prev_weekday
             AND NOT EXISTS (SELECT 1 FROM days x
               WHERE x.period_id = p.id AND x.on_date = w.prev_day)
            ))
            AND i.end_minute * 60000000 > 86400000000 + w.from_us
         )
        )
       )
    )
   )
)
"""


def derived(rows: list[tuple], columns: list[str]) -> tuple[str, list]:
    if not rows:
        raise ValueError("derived relation must not be empty")
    first = "SELECT " + ", ".join("? AS " + name for name in columns)
    rest = " UNION ALL SELECT " + ", ".join("?" for _ in columns)
    return first + rest * (len(rows) - 1), [value for row in rows for value in row]


class Experiment:
    def __init__(self, headers: list[Header], owners: list[tuple[str, str]]):
        self.headers = headers
        self.owners = sorted({(owner_type, str(owner_id)) for owner_type, owner_id in owners})
        self.zones = sorted({header.tz for header in headers})
        self.db = sqlite3.connect(":memory:")
        self.db.executescript("PRAGMA foreign_keys=ON;" + SCHEMA)
        self.checks = {"point": 0, "overlap": 0, "count": 0, "pagination": 0}
        for header in headers:
            tid = self.db.execute(
                "INSERT INTO timetables(owner_type,owner_id,kind,timezone,enabled,deleted_at) "
                "VALUES(?,?,?,?,?,?)",
                (*header.owner, header.kind, header.tz, header.enabled,
                 "2026-01-01" if header.deleted else None)).lastrowid
            for period in header.periods:
                pid = self.db.execute(
                    "INSERT INTO periods(timetable_id,valid_from,valid_to) VALUES(?,?,?)",
                    (tid, period.start.isoformat() if period.start else None,
                     period.end.isoformat() if period.end else None)).lastrowid
                entries = [(weekday, None, spans) for weekday, spans in period.weekly.items()]
                entries += [(None, day, spans) for day, spans in period.exceptions.items()]
                for weekday, day, spans in entries:
                    did = self.db.execute(
                        "INSERT INTO days(period_id,weekday,on_date) VALUES(?,?,?)",
                        (pid, weekday, day.isoformat() if day else None)).lastrowid
                    self.db.executemany(
                        "INSERT INTO intervals(day_id,start_minute,end_minute) VALUES(?,?,?)",
                        [(did, start, end) for start, end in spans])

    def query(self, a: datetime, b: datetime, mode="rows", limit=5, skip=0,
              zones: list[str] | None = None):
        snapshot = self.zones if zones is None else zones
        if not set(self.zones).issubset(snapshot):
            raise ValueError("timezone snapshot omits a header timezone")
        windows = local_windows(a, b, snapshot)
        owner_sql, owner_args = derived(self.owners, ["owner_type", "owner_id"])
        window_sql, window_args = derived([w.values() for w in windows],
            ["tz", "day", "weekday", "prev_day", "prev_weekday", "from_us", "to_us"])
        select = "COUNT(*)" if mode == "count" else "o.owner_type, o.owner_id"
        sql = "SELECT " + select + " FROM (" + owner_sql + ") o WHERE "
        sql += PREDICATE.format(windows=window_sql)
        args = owner_args + ["opening"] + window_args
        if mode != "count":
            sql += " ORDER BY o.owner_type, o.owner_id"
        if mode == "page":
            sql += " LIMIT ? OFFSET ?"
            args += [limit, skip]
        result = self.db.execute(sql, args).fetchall()
        return result[0][0] if mode == "count" else result

    def check(self, a: datetime, b: datetime, *, point=False,
              expected: list[tuple[str, str]] | None = None, pages=False):
        reference = oracle(self.headers, self.owners, a, b)
        result = self.query(a, b)
        assert result == reference, (a, b, result, reference)
        if expected is not None:
            assert result == sorted(expected), (a, b, result, expected)
        self.checks["point" if point else "overlap"] += 1
        assert self.query(a, b, "count") == len(reference)
        self.checks["count"] += 1
        if pages:
            collected = []
            for skip in range(0, len(reference) + 3, 3):
                page = self.query(a, b, "page", 3, skip)
                assert page == reference[skip:skip + 3]
                collected.extend(page)
                self.checks["pagination"] += 1
            assert collected == reference


def active_at(header: Header, at: datetime) -> bool:
    """Independent direct membership oracle; no SQL or local-window reuse."""
    if not header.enabled or header.deleted or header.kind != "opening":
        return False
    local = at.astimezone(ZoneInfo(header.tz))
    day = local.date()
    # Test oracle sampling is exact only for source boundaries on UTC minute
    # boundaries. Assert the precondition instead of silently approximating.
    assert micros(local.utcoffset()) % MINUTE_US == 0
    wall_us = ((local.hour * 60 + local.minute) * MINUTE_US
               + local.second * 1_000_000 + local.microsecond)
    periods = [p for p in header.periods if p.active(day)]
    assert len(periods) <= 1, "fixture violates disjoint-period assumption"
    if not periods:
        return False
    p = periods[0]
    selected = p.exceptions.get(day, p.weekly.get(day.isoweekday(), []))
    if any(start * MINUTE_US <= wall_us < end * MINUTE_US for start, end in selected):
        return True
    if day in p.exceptions:
        return False
    yesterday = day - timedelta(days=1)
    if not p.active(yesterday):
        return False
    prior = p.exceptions.get(yesterday, p.weekly.get(yesterday.isoweekday(), []))
    return any(start * MINUTE_US <= wall_us + DAY_US < end * MINUTE_US
               for start, end in prior)


def oracle(headers, owners, a, b):
    """Exact for our minute-aligned sources/offset transitions, including us.

    On each real UTC minute schedule membership is constant. Evaluate a and
    every subsequent UTC minute strictly before b. This checks partial-minute
    endpoints precisely; it never constructs local query windows. Fixtures use
    IANA transitions on UTC minute boundaries and minute-aligned UTC offsets.
    """
    assert a < b
    next_minute = a.replace(second=0, microsecond=0) + timedelta(minutes=1)
    instants = [a]
    while next_minute < b:
        instants.append(next_minute)
        next_minute += timedelta(minutes=1)
    found = set()
    for header in headers:
        if header.owner not in found and any(active_at(header, at) for at in instants):
            found.add(header.owner)
    return sorted(set(owners) & found)


def single(period: Period, zone="UTC", enabled=True, deleted=False):
    return Experiment([Header(("Shop", 1), zone, [period], enabled=enabled,
                              deleted=deleted)], [("Shop", 1)])


def run():
    all_experiments = []

    def remember(exp):
        all_experiments.append(exp)
        return exp

    def point(exp, when, yes):
        at = utc(when)
        exp.check(at, at + timedelta(microseconds=1), point=True,
                  expected=[("Shop", "1")] if yes else [])

    def overlap(exp, a, b, yes):
        exp.check(utc(a), utc(b), expected=[("Shop", "1")] if yes else [])

    standard = remember(single(Period(weekly={1: [(540, 720), (840, 1080)]})))
    for time, yes in [("08:59:59.999999", False), ("09:00:00", True),
                      ("11:59:59.999999", True), ("12:00:00", False),
                      ("13:59:59.999999", False), ("14:00:00", True),
                      ("17:59:59.999999", True), ("18:00:00", False)]:
        point(standard, "2026-01-05T" + time + "Z", yes)
    overlap(standard, "2026-01-05T08:59:59.999999Z", "2026-01-05T09:00:00Z", False)
    overlap(standard, "2026-01-05T08:59:59.999999Z", "2026-01-05T09:00:00.000001Z", True)
    overlap(standard, "2026-01-05T12:00:00Z", "2026-01-05T14:00:00Z", False)
    overlap(standard, "2026-01-05T17:59:59.999999Z", "2026-01-05T18:00:00Z", True)
    future = remember(single(Period(exceptions={date(2040, 1, 2): [(540, 600)]})))
    point(future, "2040-01-02T08:59:59.999999Z", False)
    point(future, "2040-01-02T09:00:00Z", True)
    point(future, "2040-01-02T10:00:00Z", False)
    overlap(future, "2040-01-02T08:59:59.999999Z", "2040-01-02T09:00:00.000001Z", True)

    night = remember(single(Period(weekly={7: [(1320, 1560)]})))
    point(night, "2026-01-04T22:00:00Z", True)
    point(night, "2026-01-05T01:59:59.999999Z", True)
    point(night, "2026-01-05T02:00:00Z", False)
    closed = remember(single(Period(weekly={7: [(1320, 1560)], 1: [(600, 660)]},
                                  exceptions={date(2026, 1, 5): []})))
    point(closed, "2026-01-05T01:00:00Z", False)
    point(closed, "2026-01-05T10:30:00Z", False)
    replaced = remember(single(Period(weekly={7: [(1320, 1560)], 1: [(600, 660)]},
                            exceptions={date(2026, 1, 5): [(30, 45)]})))
    point(replaced, "2026-01-05T00:35:00Z", True)
    point(replaced, "2026-01-05T01:00:00Z", False)
    point(replaced, "2026-01-05T10:30:00Z", False)
    prior_exception = remember(single(Period(weekly={7: [(600, 660)]},
                              exceptions={date(2026, 1, 4): [(1380, 1500)]})))
    point(prior_exception, "2026-01-05T00:59:59.999999Z", True)
    point(prior_exception, "2026-01-05T01:00:00Z", False)

    old = Period(end=date(2026, 1, 4), weekly={7: [(1320, 1560)]})
    new = Period(start=date(2026, 1, 5), weekly={1: [(600, 660)]})
    change = remember(Experiment([Header(("Shop", 1), "UTC", [old, new])], [("Shop", 1)]))
    point(change, "2026-01-04T23:59:59.999999Z", True)
    point(change, "2026-01-05T00:00:00Z", False)
    point(change, "2026-01-05T10:00:00Z", True)
    gap = remember(single(Period(date(2026, 1, 4), date(2026, 1, 4), {7: [(1320, 1560)]})))
    point(gap, "2026-01-05T00:01:00Z", False)
    full = remember(single(Period(weekly={1: [(0, 1440)]})))
    point(full, "2026-01-05T00:00:00Z", True)
    point(full, "2026-01-05T23:59:59.999999Z", True)
    point(full, "2026-01-06T00:00:00Z", False)
    for enabled, deleted in [(False, False), (True, True)]:
        inactive = remember(single(Period(weekly={1: [(0, 1440)]}), enabled=enabled, deleted=deleted))
        point(inactive, "2026-01-05T12:00:00Z", False)

    rome = remember(single(Period(weekly={7: [(135, 165)]}), "Europe/Rome"))
    overlap(rome, "2026-03-29T00:00:00Z", "2026-03-29T03:00:00Z", False)
    for instant, yes in [("00:14:59.999999", False), ("00:15:00", True),
                         ("00:45:00", False), ("01:14:59.999999", False),
                         ("01:15:00", True), ("01:45:00", False)]:
        point(rome, "2026-10-25T" + instant + "Z", yes)
    overlap(rome, "2026-10-25T00:45:00Z", "2026-10-25T01:15:00Z", False)
    overlap(rome, "2026-10-25T00:20:00Z", "2026-10-25T00:21:00Z", True)
    overlap(rome, "2026-10-25T01:20:00Z", "2026-10-25T01:21:00Z", True)
    overlap(rome, "2026-10-25T01:14:59.999999Z", "2026-10-25T01:15:00Z", False)
    overlap(rome, "2026-10-25T01:14:59.999999Z", "2026-10-25T01:15:00.000001Z", True)
    overlap(rome, "2026-10-25T00:44:59.999999Z", "2026-10-25T00:45:00Z", True)
    spring_partial = remember(single(Period(weekly={7: [(105, 195)]}), "Europe/Rome"))
    point(spring_partial, "2026-03-29T00:59:59.999999Z", True)
    point(spring_partial, "2026-03-29T01:00:00Z", True)
    point(spring_partial, "2026-03-29T01:15:00Z", False)

    lord = remember(single(Period(weekly={7: [(100, 110)]}), "Australia/Lord_Howe"))
    point(lord, "2026-04-04T14:45:00Z", True)  # first 01:45, UTC+11
    point(lord, "2026-04-04T15:15:00Z", True)  # repeated 01:45, UTC+10:30
    lord_gap = remember(single(Period(weekly={7: [(130, 140)]}), "Australia/Lord_Howe"))
    overlap(lord_gap, "2026-10-03T14:00:00Z", "2026-10-03T17:00:00Z", False)
    apia = remember(single(Period(exceptions={date(2011, 12, 30): [(0, 1440)]}), "Pacific/Apia"))
    overlap(apia, "2011-12-29T10:00:00Z", "2011-12-31T10:00:00Z", False)
    apia_spill = remember(single(Period(exceptions={date(2011, 12, 29): [(1380, 1500)]}), "Pacific/Apia"))
    point(apia_spill, "2011-12-30T09:59:59.999999Z", True)
    point(apia_spill, "2011-12-30T10:00:00Z", False)

    # Explicit window projection assertions, independent expected endpoints.
    w = local_windows(utc("2026-03-29T00:30Z"), utc("2026-03-29T01:30Z"), ["Europe/Rome"])
    assert [(x.start // MINUTE_US, x.end // MINUTE_US) for x in w] == [(90, 120), (180, 210)]
    w = local_windows(utc("2026-10-25T00:30Z"), utc("2026-10-25T01:30Z"), ["Europe/Rome"])
    assert [(x.start // MINUTE_US, x.end // MINUTE_US) for x in w] == [(150, 180), (120, 150)]
    w = local_windows(utc("2011-12-30T09:30Z"), utc("2011-12-30T10:30Z"), ["Pacific/Apia"])
    assert [x.day for x in w] == ["2011-12-29", "2011-12-31"]
    long_a, long_b = utc("2026-01-01T00:00Z"), utc("2026-12-01T00:00Z")
    assert offset(long_a, ZoneInfo("Europe/Rome")) == offset(long_b, ZoneInfo("Europe/Rome"))
    assert len(transitions(long_a, long_b, ZoneInfo("Europe/Rome"))) == 2
    # Verify the oracle's transition-alignment premise against known UTC
    # transition instants, without deriving those instants from local_windows.
    for name, when in [
        ("Europe/Rome", "2026-03-29T01:00Z"),
        ("Europe/Rome", "2026-10-25T01:00Z"),
        ("America/New_York", "2026-03-08T07:00Z"),
        ("America/New_York", "2026-11-01T06:00Z"),
        ("Australia/Lord_Howe", "2026-04-04T15:00Z"),
        ("Australia/Lord_Howe", "2026-10-03T15:30Z"),
        ("Pacific/Apia", "2011-12-30T10:00Z"),
    ]:
        transition = utc(when)
        zone = ZoneInfo(name)
        assert transition.second == transition.microsecond == 0
        assert offset(transition - timedelta(microseconds=1), zone) != offset(transition, zone)

    rng = random.Random(813719)
    zones = ["UTC", "Europe/Rome", "America/New_York", "Australia/Lord_Howe", "Pacific/Apia"]
    headers = []
    owners = [(kind, str(n)) for kind in ["Shop", "Person"] for n in range(1, 13)]
    owners.append(("NoTimetable", "99"))
    for owner in owners[:-1]:
        weekly = {}
        for wd in range(1, 8):
            if rng.random() < .7:
                start = rng.randrange(1440)
                weekly[wd] = [(start, start + rng.randrange(1, 1441))]
        exceptions = {}
        for day in [date(2026, 1, 5), date(2026, 3, 29), date(2026, 10, 25), date(2011, 12, 30)]:
            if rng.random() < .7:
                exceptions[day] = [] if rng.random() < .5 else [(60, 180)]
        headers.append(Header(owner, rng.choice(zones), [Period(weekly=weekly, exceptions=exceptions)],
                              enabled=rng.random() > .15, deleted=rng.random() < .1))
    # Several matching intervals on one header must still give one owner row.
    # The full-day + night combination is valid even though its civil union
    # exceeds 24h: never merge it into an invalid [0,1560] persisted interval.
    for header in headers:
        if header.owner in [("Shop", "1"), ("Person", "1")]:
            header.enabled, header.deleted = True, False
            spans = [(0, 1440), (60, 180), (180, 300), (1320, 1560)]
            header.periods = [Period(weekly={i: spans for i in range(1, 8)})]
    uuid_owner = ("Shop", "d8e0cf68-cbb3-4fd0-a5ce-0de754991e15")
    ulid_owner = ("Person", "01ARZ3NDEKTSV4RRFFQ69G5FAV")
    owners += [uuid_owner, ulid_owner]
    headers += [Header(owner, "UTC", [Period(weekly={i: [(0, 1440)] for i in range(1, 8)})])
                for owner in [uuid_owner, ulid_owner]]
    headers.append(Header(("NoTimetable", "99"), "UTC",
                [Period(weekly={i: [(0, 1440)] for i in range(1, 8)})], kind="delivery"))
    mixed = remember(Experiment(headers, owners))
    anchors = [utc(value) for value in ["2026-01-04T22:00Z", "2026-03-29T00:00Z",
               "2026-10-25T00:00Z", "2026-04-04T14:00Z", "2026-10-03T14:00Z",
               "2011-12-30T09:00Z"]]
    for n in range(240):
        at = rng.choice(anchors) + timedelta(minutes=rng.randrange(-300, 301),
                                              seconds=rng.randrange(60), microseconds=rng.randrange(1_000_000))
        mixed.check(at, at + timedelta(microseconds=1), point=True, pages=n % 40 == 0)
        begin = rng.choice(anchors) + timedelta(minutes=rng.randrange(-300, 301))
        end = begin + timedelta(minutes=rng.randrange(1, 181))
        mixed.check(begin, end, pages=n % 40 == 0)
    # Subminute overlap straddles independently evaluated minute boundaries.
    for n in range(30):
        seam = rng.choice(anchors) + timedelta(minutes=rng.randrange(-90, 91))
        mixed.check(seam - timedelta(microseconds=rng.randrange(1, 1_000_000)),
                    seam + timedelta(microseconds=rng.randrange(1, 1_000_000)))
    for instant in anchors:
        rows = mixed.query(instant, instant + timedelta(microseconds=1))
        assert ("Shop", "1") in rows and ("Person", "1") in rows
        assert rows.count(("Shop", "1")) == 1 and ("NoTimetable", "99") not in rows
        assert uuid_owner in rows and ulid_owner in rows
    try:
        mixed.db.execute("INSERT INTO timetables(owner_type,owner_id,kind,timezone,enabled,deleted_at) "
                         "VALUES(?,?,?,?,?,?)", ("Shop", "1", "opening", "UTC", 0, "2026-01-01"))
    except sqlite3.IntegrityError:
        pass
    else:
        raise AssertionError("duplicate owner/kind header accepted, even though deleted")
    try:
        mixed.query(anchors[0], anchors[0] + timedelta(seconds=1), zones=["UTC"])
    except ValueError:
        pass
    else:
        raise AssertionError("incomplete timezone snapshot accepted")

    totals = {key: sum(exp.checks[key] for exp in all_experiments) for key in mixed.checks}
    print("PASS: independent SQL/oracle comparisons:", totals)
    print("PASS: 240 deterministic random point + 240 random overlap + 30 subminute overlap cases")
    print("PASS: DST windows, two transitions with equal endpoint offsets, skipped date, owner collision, timezone snapshot")
    print("PASS: unique header including deleted rows, string integer/UUID/ULID owner keys, overlapping/adjacent/night interval deduplication")
    print("LIMIT: SQLite executed; MySQL 8 and PHP runtime remain unverified.")
    print("LIMIT: oracle exact for these minute-aligned sources and checked transition fixtures; not a generic proof for arbitrary subminute source boundaries.")
    print("LIMIT: transition scanner is prototype-only; large derived relations must respect database statement/parameter limits (SQLite UNION ALL: 500 terms).")
    print("SCHEMA: period non-overlap, IANA timezone validity, date validity, interval sorting/deduplication need write-time validation.")
    print("SCHEMA: preserve overlapping/adjacent stored spans when union exceeds 24h; only merge effective resolver output.")
    print("SCHEMA: UNIQUE nullable weekday/on_date + XOR enforces one day per selector on SQLite/MySQL; empty exception is a row with zero intervals.")


if __name__ == "__main__":
    run()
