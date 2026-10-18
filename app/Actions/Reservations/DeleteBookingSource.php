<?php

namespace App\Actions\Reservations;

use App\Models\BookingSource;
use App\Models\User;
use App\Services\Reservations\BookingSourceUsage;
use Illuminate\Support\Facades\DB;

class DeleteBookingSource
{
    public function __construct(private BookingSourceUsage $usage) {}

    /**
     * Delete a source that no reservation uses and that is not a system row.
     *
     * Returns false, without writing or logging, for a system row or a used source.
     */
    public function __invoke(User $actor, BookingSource $source): bool
    {
        return DB::transaction(function () use ($actor, $source): bool {
            $source = BookingSource::query()->lockForUpdate()->findOrFail($source->id);

            if ($source->is_system || $this->usage->isUsed($source)) {
                return false;
            }

            $source->delete();

            activity()
                ->causedBy($actor)
                ->event('booking_source.deleted')
                ->withProperties(['property_id' => $source->property_id, 'name' => $source->name, 'type' => $source->type->value])
                ->log('Booking source deleted');

            return true;
        });
    }
}
