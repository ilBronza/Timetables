<?php

namespace IlBronza\Timetables\Console;

use IlBronza\Timetables\Models\Timetable;
use IlBronza\Timetables\Support\Timezones;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ValidateConfigurationCommand extends Command
{
    protected $signature = 'timetables:validate {--connection=* : Database connections to inspect}';

    protected $description = 'Validate timetable timezone configuration against all stored headers, including deleted ones';

    public function handle(): int
    {
        try {
            $allowed = Timezones::configured();
            $connections = $this->option('connection') ?: config('timetables.validation_connections', []);
            $connections = $connections ?: [config('database.default')];
            if (! is_array($connections)) {
                throw new \InvalidArgumentException('validation_connections must be an array.');
            }
            $class = Timetable::getProjectClassName();
            $table = (new $class)->getTable();
            $invalid = false;
            foreach (array_unique($connections) as $connection) {
                $stored = DB::connection($connection)->table($table)->distinct()->pluck('timezone')->all();
                foreach (array_diff($stored, $allowed) as $timezone) {
                    $this->error('Connection ['.$connection.']: stored timezone ['.$timezone.'] is not configured.');
                    $invalid = true;
                }
            }
            if ($invalid) {
                return self::FAILURE;
            }
            $this->info('Timetable timezone configuration is valid on the inspected connections.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
