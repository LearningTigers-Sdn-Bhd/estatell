<?php

namespace App\Actions\Platform;

use App\Enums\ContactEnquiryStatus;
use App\Models\ContactEnquiry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UpdateContactEnquiryStatus
{
    /**
     * Move an enquiry to a new status. Returns false when the enquiry is already there.
     */
    public function __invoke(User $admin, ContactEnquiry $enquiry, ContactEnquiryStatus $status): bool
    {
        return DB::transaction(function () use ($admin, $enquiry, $status): bool {
            $enquiry = ContactEnquiry::query()->lockForUpdate()->findOrFail($enquiry->id);
            $previousStatus = $enquiry->status;

            if (! $previousStatus->canTransitionTo($status)) {
                return false;
            }

            $enquiry->update(['status' => $status]);

            activity()
                ->performedOn($enquiry)
                ->causedBy($admin)
                ->event('contact-enquiry.status-changed')
                ->withProperties(['from' => $previousStatus->value, 'to' => $status->value])
                ->log('Contact enquiry marked '.$status->value);

            return true;
        });
    }
}
