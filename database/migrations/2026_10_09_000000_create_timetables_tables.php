<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function tables(): array
    {
        return [
            config('timetables.models.timetable.table', 'timetables__timetables'),
            config('timetables.models.period.table', 'timetables__periods'),
            config('timetables.models.day.table', 'timetables__days'),
            config('timetables.models.interval.table', 'timetables__intervals'),
        ];
    }

    public function up(): void
    {
        $db = DB::connection($this->getConnection());
        $driver = $db->getDriverName();
        if (! in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Timetables supports MySQL and SQLite only.');
        }
        if ($driver === 'mysql') {
            $version = (string) $db->selectOne('SELECT VERSION() AS version')->version;
            if (stripos($version, 'MariaDB') !== false || ! preg_match('/^[0-9]+\.[0-9]+\.[0-9]+/', $version, $parts) || version_compare($parts[0], '8.0.17', '<')) {
                throw new RuntimeException('Timetables requires MySQL 8.0.17 or newer for enforced CHECK constraints and binary NO PAD collation.');
            }
        }
        $grammar = $db->getQueryGrammar();
        [$headers, $periods, $days, $intervals] = array_map($grammar->wrapTable(...), $this->tables());
        $binary = $driver === 'mysql' ? ' COLLATE utf8mb4_0900_bin' : ' COLLATE BINARY';
        $length = $driver === 'mysql' ? 'CHAR_LENGTH' : 'length';
        $revisionType = $driver === 'mysql' ? 'BIGINT UNSIGNED' : 'INTEGER';
        $suffix = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        $integer = $driver === 'sqlite' ? " AND typeof(weekday) = 'integer'" : '';
        $intervalInteger = $driver === 'sqlite' ? " AND typeof(start_minute) = 'integer' AND typeof(end_minute) = 'integer'" : '';

        $db->statement("CREATE TABLE {$headers} (
            id CHAR(36) NOT NULL PRIMARY KEY,
            owner_type VARCHAR(255){$binary} NOT NULL,
            owner_id VARCHAR(64){$binary} NOT NULL,
            kind VARCHAR(64){$binary} NOT NULL,
            name VARCHAR(255) NULL,
            timezone VARCHAR(255) NOT NULL,
            enabled BOOLEAN NOT NULL DEFAULT 1,
            revision {$revisionType} NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            deleted_at DATETIME NULL,
            UNIQUE (owner_type, owner_id, kind),
            CHECK ({$length}(owner_type) BETWEEN 1 AND 255),
            CHECK ({$length}(owner_id) BETWEEN 1 AND 64),
            CHECK ({$length}(kind) BETWEEN 1 AND 64),
            CHECK (revision >= 1),
            CHECK (enabled IN (0, 1))
        ){$suffix}");
        $db->statement("CREATE TABLE {$periods} (
            id CHAR(36) NOT NULL PRIMARY KEY,
            timetable_id CHAR(36) NOT NULL,
            valid_from DATE NULL,
            valid_to DATE NULL,
            name VARCHAR(255) NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            FOREIGN KEY (timetable_id) REFERENCES {$headers} (id) ON DELETE CASCADE,
            CHECK (valid_from IS NULL OR valid_to IS NULL OR valid_from <= valid_to)
        ){$suffix}");
        $db->statement("CREATE TABLE {$days} (
            id CHAR(36) NOT NULL PRIMARY KEY,
            period_id CHAR(36) NOT NULL,
            weekday INTEGER NULL,
            on_date DATE NULL,
            label VARCHAR(255) NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            FOREIGN KEY (period_id) REFERENCES {$periods} (id) ON DELETE CASCADE,
            UNIQUE (period_id, weekday),
            UNIQUE (period_id, on_date),
            CHECK ((weekday IS NOT NULL AND on_date IS NULL AND weekday BETWEEN 1 AND 7{$integer}) OR (weekday IS NULL AND on_date IS NOT NULL))
        ){$suffix}");
        $db->statement("CREATE TABLE {$intervals} (
            id CHAR(36) NOT NULL PRIMARY KEY,
            day_id CHAR(36) NOT NULL,
            start_minute INTEGER NOT NULL,
            end_minute INTEGER NOT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            FOREIGN KEY (day_id) REFERENCES {$days} (id) ON DELETE CASCADE,
            CHECK (start_minute BETWEEN 0 AND 1439 AND end_minute > start_minute AND end_minute <= start_minute + 1440{$intervalInteger})
        ){$suffix}");
        $names = $this->tables();
        foreach ([[1, ['timetable_id', 'valid_from', 'valid_to']], [3, ['day_id', 'start_minute']]] as [$table, $columns]) {
            $index = $grammar->wrap('tt_'.substr(hash('sha256', $db->getName().'|'.$db->getTablePrefix().$names[$table]), 0, 12).'_lookup');
            $quotedTable = $grammar->wrapTable($names[$table]);
            $db->statement('CREATE INDEX '.$index.' ON '.$quotedTable.' ('.implode(', ', array_map($grammar->wrap(...), $columns)).')');
        }
    }

    public function down(): void
    {
        $db = DB::connection($this->getConnection());
        foreach (array_reverse($this->tables()) as $table) {
            $db->statement('DROP TABLE IF EXISTS '.$db->getQueryGrammar()->wrapTable($table));
        }
    }
};
