<?php

namespace IlBronza\Timetables\Services;

use Illuminate\Database\Eloquent\Model;
use IlBronza\Timetables\Models\Timetable;
use IlBronza\Timetables\Exceptions\TimetableConflict;

/** Use this service when deleting owners concurrently with timetable writes. */
class TimetableOwnerLifecycle
{
    public function deleteOwner(Model $owner, bool $force = false): bool
    {
        try {
            return $owner->getConnection()->transaction(function () use ($owner, $force) {
                $this->lockOwner($owner, $force);
                // The trait's deleting event performs cleanup for hard deletion,
                // within the same transaction and after the owner lock.
                $deleted = $force && method_exists($owner, 'forceDelete') ? $owner->forceDelete() : $owner->delete();
                if (! $deleted) {
                    throw new OwnerDeletionCancelled;
                }
                return true;
            });
        } catch (OwnerDeletionCancelled) {
            return false;
        }
    }

    public function cleanup(Model $owner): void
    {
        $owner->getConnection()->transaction(function () use ($owner) {
            $this->lockOwner($owner, true, false);
            $class = Timetable::getProjectClassName();
            $headers = (new $class)->setConnection($owner->getConnectionName())->newQuery()->withTrashed()
                ->where('owner_type', $owner->getMorphClass())->where('owner_id', (string) $owner->getKey())
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($headers as $header) {
                if (! $header->forceDelete()) {
                    throw new TimetableConflict('Timetable cleanup was cancelled.');
                }
            }
        });
    }

    private function lockOwner(Model $owner, bool $includeTrashed, bool $mustExist = true): void
    {
        $query = $owner->newQuery()->whereKey($owner->getKey());
        if ($includeTrashed && method_exists($owner, 'trashed')) {
            $query->withTrashed();
        }
        $query->lockForUpdate();
        $mustExist ? $query->firstOrFail() : $query->first();
    }
}

/** Internal transaction rollback signal when an Eloquent listener vetoes deletion. */
final class OwnerDeletionCancelled extends \RuntimeException
{
}
